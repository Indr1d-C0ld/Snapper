<?php
declare(strict_types=1);

/* =========================================================================
 * Snapper – libreria: anti-SSRF, throttling login, TOTP, view helpers
 * =======================================================================*/

/* ---- Anti-SSRF ------------------------------------------------------- */

function ip_is_public(string $ip): bool
{
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

/**
 * Valida un URL come "pubblicamente raggiungibile e sicuro da archiviare".
 * @return array{0:bool,1:string,2:string}  [ok, motivo, host]
 */
function validate_public_url(string $url): array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2000) {
        return [false, 'URL vuoto o troppo lungo', ''];
    }
    $u = parse_url($url);
    if ($u === false || empty($u['scheme']) || empty($u['host'])) {
        return [false, 'URL malformato', ''];
    }
    if (!in_array(strtolower($u['scheme']), ['http', 'https'], true)) {
        return [false, 'Sono ammessi solo http/https', ''];
    }
    if (isset($u['user']) || isset($u['pass'])) {
        return [false, 'Credenziali nell\'URL non ammesse', ''];
    }
    $host = trim($u['host'], '[]');

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        if (!preg_match('/^[a-z0-9.-]+$/i', $host) || strlen($host) > 253) {
            return [false, 'Hostname non valido', $host];
        }
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ip']))   $ips[] = $r['ip'];
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
        foreach (@gethostbynamel($host) ?: [] as $ip) {
            $ips[] = $ip;
        }
    }
    if (!$ips) {
        return [false, 'DNS non risolvibile', $host];
    }
    foreach (array_unique($ips) as $ip) {
        if (!ip_is_public($ip)) {
            return [false, "Risolve a indirizzo non pubblico ($ip)", $host];
        }
    }
    return [true, '', $host];
}

/* ---- Coda di cattura --------------------------------------------- */

/* PATH con cui viene avviato il worker: volutamente ristretto, ma deve
 * includere /usr/local/bin, dove vive il software non pacchettizzato da apt
 * (es. `ots`). Definito qui una volta sola: prima era ripetuto in quattro file
 * e una correzione andava replicata a mano ovunque. */
const WORKER_PATH = '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin';

/**
 * Avvia il worker per uno snapshot 'pending', se siamo sotto MAX_CONCURRENCY.
 * PRESUPPONE che il chiamante detenga già il lock della coda: non acquisirlo
 * qui è ciò che permette a drain.php di ciclare tenendolo per tutto il giro.
 * @return bool worker effettivamente avviato
 */
function spawn_worker_locked(string $short, string $url): bool
{
    $pdo = db();
    $running = (int)$pdo->query("SELECT COUNT(*) c FROM snapshots WHERE status='running'")->fetch()['c'];
    if ($running >= MAX_CONCURRENCY) {
        return false;
    }
    // La transizione pending->running è anche la guardia contro un doppio avvio
    // dello stesso short: se un altro processo ci ha preceduto, rowCount è 0.
    $upd = $pdo->prepare("UPDATE snapshots SET status='running' WHERE short=? AND status='pending'");
    $upd->execute([$short]);
    if ($upd->rowCount() === 0) {
        return false;
    }
    $cmd = 'PATH=' . WORKER_PATH . ' nohup '
        . escapeshellarg(WORKER) . ' ' . escapeshellarg($short) . ' ' . escapeshellarg($url)
        . ' >> ' . escapeshellarg(DATA_DIR . '/worker.log') . ' 2>&1 &';
    shell_exec($cmd);
    return true;
}

/**
 * Esegue $fn tenendo il lock della coda. Non usarlo se il lock è già detenuto
 * dallo stesso processo: flock su un secondo descrittore si bloccherebbe da sé.
 * Se il lock non è ottenibile in mezzo secondo rinuncia ed esegue $fallback:
 * lasciare lo snapshot in coda è sempre preferibile ad appendere la richiesta
 * web, tanto drain.php lo raccoglie al giro successivo.
 */
function with_queue_lock(callable $fn, callable $fallback)
{
    $fh = @fopen(QUEUE_LOCK, 'c');
    if ($fh === false) {
        return $fallback();
    }
    try {
        for ($i = 0; $i < 10; $i++) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                try {
                    return $fn();
                } finally {
                    flock($fh, LOCK_UN);
                }
            }
            usleep(50000);
        }
        return $fallback();
    } finally {
        fclose($fh);
    }
}

/**
 * Inserisce uno snapshot 'pending' e prova ad avviarlo subito, rispettando
 * MAX_CONCURRENCY; altrimenti resta in coda (lo raccoglie drain.php/cron).
 * @return array{0:string,1:bool}  [short, avviato_subito]
 */
function enqueue_capture(string $url, ?string $title, ?string $parentShort = null): array
{
    $pdo   = db();
    $short = safe_short(7);
    $title = ($title !== null && trim($title) !== '') ? trim($title) : null;

    $pdo->prepare('INSERT INTO snapshots(short, url, title, status, parent_short) VALUES(?,?,?,?,?)')
        ->execute([$short, $url, $title, 'pending', $parentShort]);

    // Conteggio e avvio devono essere atomici fra i vari punti d'ingresso,
    // altrimenti due richieste simultanee leggono lo stesso conteggio e
    // superano entrambe il limite di worker.
    $started = with_queue_lock(
        fn() => spawn_worker_locked($short, $url),
        fn() => false
    );
    return [$short, $started];
}

/** capostipite della catena di versioni per uno short dato (o lo short stesso) */
function chain_parent(string $short): string
{
    $st = db()->prepare('SELECT COALESCE(parent_short, short) p FROM snapshots WHERE short=?');
    $st->execute([$short]);
    $row = $st->fetch();
    return $row ? (string)$row['p'] : $short;
}

/* ---- Throttling login (per IP, su file) ---------------------------- */

function _rl_file(string $ip): string
{
    if (!is_dir(RL_DIR)) { @mkdir(RL_DIR, 0750, true); }
    return RL_DIR . '/' . hash('sha256', $ip) . '.json';
}

/** @return array{0:bool,1:int}  [consentito, secondi_di_attesa] */
function login_gate(string $ip): array
{
    $f = _rl_file($ip);
    if (!is_file($f)) return [true, 0];
    $r = json_decode((string)@file_get_contents($f), true) ?: [];
    $until = (int)($r['until'] ?? 0);
    $wait  = $until - time();
    return $wait > 0 ? [false, $wait] : [true, 0];
}

function login_fail(string $ip): void
{
    $f = _rl_file($ip);
    $r = is_file($f) ? (json_decode((string)@file_get_contents($f), true) ?: []) : [];
    $fails = (int)($r['fails'] ?? 0) + 1;
    // primi 4 tentativi liberi, poi backoff: 15s,30s,60s,... max 15 min
    $lock = $fails <= 4 ? 0 : min(900, 15 * (2 ** ($fails - 5)));
    @file_put_contents($f, json_encode([
        'fails' => $fails,
        'until' => time() + $lock,
        'last'  => time(),
    ]), LOCK_EX);
}

function login_ok(string $ip): void
{
    @unlink(_rl_file($ip));
}

/* ---- TOTP (RFC 6238, SHA1, 6 cifre, step 30s) --------------------- */

function totp_verify(?string $base32Secret, string $code, int $window = 1): bool
{
    $base32Secret = (string)$base32Secret;
    $code = preg_replace('/\D/', '', $code);
    if ($base32Secret === '' || strlen($code) !== 6) return false;

    $key = _base32_decode($base32Secret);
    if ($key === '') return false;

    $t = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $counter = pack('N*', 0) . pack('N*', $t + $i);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $off  = ord($hash[19]) & 0xf;
        $bin  = ((ord($hash[$off]) & 0x7f) << 24)
              | ((ord($hash[$off + 1]) & 0xff) << 16)
              | ((ord($hash[$off + 2]) & 0xff) << 8)
              | (ord($hash[$off + 3]) & 0xff);
        if (str_pad((string)($bin % 1000000), 6, '0', STR_PAD_LEFT) === $code) {
            return true;
        }
    }
    return false;
}

function _base32_decode(string $b32): string
{
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin(strpos($map, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

/* ---- Presentazione ------------------------------------------------- */

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function host_of(?string $url): string
{
    $host = parse_url((string)$url, PHP_URL_HOST) ?: (string)$url;
    return preg_replace('/^www\./', '', strtolower($host));
}

function human_size(int $b): string
{
    if ($b <= 0) return '—';
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($b, 1024));
    $i = max(0, min($i, count($u) - 1));
    return round($b / (1024 ** $i), $i ? 1 : 0) . ' ' . $u[$i];
}

/** ts SQLite (UTC) -> "10 set 2026, 14:32" in ora locale italiana */
function ts_local(?string $ts): string
{
    if (!$ts) return '';
    static $m = [1 => 'gen', 'feb', 'mar', 'apr', 'mag', 'giu',
                 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
    try {
        $tz = date_default_timezone_get();
        $d = new DateTime($ts . ' UTC');
        $d->setTimezone(new DateTimeZone($tz && $tz !== 'UTC' ? $tz : 'Europe/Rome'));
        return $d->format('d') . ' ' . $m[(int)$d->format('n')] . ' ' . $d->format('Y, H:i');
    } catch (Throwable) {
        return $ts;
    }
}

function status_stamp(string $status): string
{
    return match ($status) {
        'ready'   => '<span class="stamp dev">Sviluppato</span>',
        'running' => '<span class="stamp run">In sviluppo</span>',
        'pending' => '<span class="stamp wait">In coda</span>',
        'error'   => '<span class="stamp err">Velato</span>',
        default   => '<span class="stamp">' . h($status) . '</span>',
    };
}

/* ---- Layout condiviso (tema "Camera Oscura / Provino") ----------- */

function layout_head(string $title): void
{
    $t = h($title);
    echo <<<HTML
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>$t</title>
<link rel="stylesheet" href="/snapper/assets/snapper.css">
</head>
<body>
HTML;
}

function layout_masthead(string $active = ''): void
{
    $tab = fn(string $key, string $href, string $label) =>
        '<a class="tab' . ($active === $key ? ' tab-on' : '') . '" href="' . $href . '">' . $label . '</a>';
    $nav = $tab('sheet', '/snapper/index.php', 'Provino')
         . $tab('watch', '/snapper/watches.php', 'Watch')
         . '<a class="tab" href="/snapper/logout.php">Esci</a>';
    echo <<<HTML
<header class="masthead">
  <div class="brand">
    <h1>Snapper</h1>
    <span class="tag">Archivio di prove &middot; contact sheet</span>
  </div>
  <div class="mast-nav">$nav</div>
</header>
HTML;
}

function layout_foot(): void
{
    echo "\n</body>\n</html>\n";
}
