<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

/* =========================================================================
 * Snapper – API per servizi (bot Telegram, integrazioni)
 *
 * Tre barriere indipendenti:
 *   1. solo da loopback (qui sotto, e `Require local` nella conf Apache):
 *      dall'esterno questo endpoint non esiste;
 *   2. token Bearer, conservato solo come impronta SHA-256, revocabile;
 *   3. ambiti per token: 'capture' per archiviare, 'read' per leggere.
 *
 * Il token va nell'header `Authorization: Bearer …` (o `X-Snapper-Token`),
 * MAI nella query string: finirebbe nei log di accesso di Apache.
 * Nessuna sessione e nessun cookie.
 *
 *   POST ?a=capture  {url, title?}               archivia          [capture]
 *   POST ?a=watch    {url, every_hours, title?}  osserva un URL    [capture]
 *   GET  ?a=status   &short=…                    stato di una prova    [read]
 *   GET  ?a=recent   &limit=…                    ultime prove          [read]
 *   GET  ?a=search   &q=…&limit=…                ricerca full-text     [read]
 *   GET  ?a=events   &since=…                    catture concluse      [read]
 *   GET  ?a=health                               stato del sistema     [read]
 * =======================================================================*/

const API_LOOPBACK_ONLY   = true;   // false solo se serve un client remoto
const API_CAPTURES_PER_H  = 60;     // limite di catture per token
const API_MAX_BODY        = 8192;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_fail(string $msg, int $code): never
{
    out(['ok' => false, 'error' => $msg], $code);
}

function bearer_token(): ?string
{
    if (!empty($_SERVER['HTTP_X_SNAPPER_TOKEN'])) {
        $t = trim((string)$_SERVER['HTTP_X_SNAPPER_TOKEN']);
        return preg_match('/^[A-Za-z0-9_]{20,200}$/', $t) ? $t : null;
    }
    // Sotto mod_php l'header Authorization non arriva sempre in $_SERVER:
    // getallheaders() è la fonte affidabile, le altre due coprono FPM/CGI.
    $cands = [
        (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
        (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
    ];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp((string)$k, 'Authorization') === 0) {
                $cands[] = (string)$v;
            }
        }
    }
    foreach ($cands as $c) {
        if (preg_match('/^\s*Bearer\s+([A-Za-z0-9_]{20,200})\s*$/', $c, $m)) {
            return $m[1];
        }
    }
    return null;
}

function json_body(): array
{
    $raw = (string)file_get_contents('php://input', false, null, 0, API_MAX_BODY + 1);
    if (strlen($raw) > API_MAX_BODY) {
        api_fail('corpo della richiesta troppo grande', 413);
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        api_fail('corpo JSON non valido', 400);
    }
    return $j;
}

function need(array $scopes, string $s): void
{
    if (!in_array($s, $scopes, true)) {
        api_fail("questo token non ha l'ambito '$s'", 403);
    }
}

function snapshot_view(array $r): array
{
    return [
        'short'        => $r['short'],
        'url'          => $r['url'],
        'final_url'    => $r['final_url'] ?? null,
        'title'        => $r['title'] ?? null,
        'status'       => $r['status'],
        'status_msg'   => $r['status_msg'] ?? null,
        'http_status'  => isset($r['http_status']) ? (int)$r['http_status'] : null,
        'size_bytes'   => (int)($r['size_bytes'] ?? 0),
        'sha256'       => $r['sha256'] ?? null,
        'ots_status'   => $r['ots_status'] ?? 'none',
        'diff_pct'     => isset($r['diff_pct']) ? (float)$r['diff_pct'] : null,
        'parent_short' => $r['parent_short'] ?? null,
        'source'       => $r['source'] ?? null,
        'capture_ms'   => isset($r['capture_ms']) ? (int)$r['capture_ms'] : null,
        'created_at'   => $r['ts'] ?? null,
        'done_at'      => $r['done_at'] ?? null,
        'permalink'    => '/archives/' . rawurlencode((string)$r['short']) . '/',
    ];
}

function clamp_int($v, int $lo, int $hi, int $def): int
{
    $n = filter_var($v, FILTER_VALIDATE_INT);
    return $n === false ? $def : max($lo, min($hi, (int)$n));
}

/* ---- barriera 1: solo loopback -------------------------------------- */
$ip = client_ip();
if (API_LOOPBACK_ONLY && !in_array($ip, ['127.0.0.1', '::1'], true)) {
    // 404 e non 403: dall'esterno l'endpoint non deve nemmeno risultare esistente.
    api_fail('non trovato', 404);
}

/* ---- barriera 2: token ---------------------------------------------- */
if (isset($_GET['token']) || isset($_GET['access_token'])) {
    api_fail("token nella query string non ammesso: usa l'header Authorization", 400);
}
$tok = bearer_token();
if ($tok === null) {
    usleep(250000);
    api_fail('token mancante', 401);
}
$pdo = db();
$st = $pdo->prepare('SELECT * FROM api_tokens WHERE token_hash=? AND revoked IS NULL');
$st->execute([hash('sha256', $tok)]);
$T = $st->fetch();
if (!$T) {
    audit("API rifiutata ip=$ip (token non valido o revocato)");
    usleep(500000);
    api_fail('token non valido o revocato', 401);
}
$scopes = array_map('trim', explode(',', (string)$T['scopes']));
$pdo->prepare('UPDATE api_tokens SET last_used=CURRENT_TIMESTAMP, uses=uses+1 WHERE id=?')
    ->execute([$T['id']]);
$source = 'api:' . $T['name'];

/* ---- azioni ---------------------------------------------------------- */
$a      = (string)($_GET['a'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$write  = ['capture', 'watch'];
if (in_array($a, $write, true) && $method !== 'POST') {
    api_fail('questa azione richiede POST', 405);
}
if (!in_array($a, $write, true) && $method !== 'GET') {
    api_fail('questa azione richiede GET', 405);
}

switch ($a) {

case 'capture':
case 'watch': {
    need($scopes, 'capture');
    $in    = json_body();
    $url   = trim((string)($in['url'] ?? ''));
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 300);

    [$ok, $reason, $host] = validate_public_url($url);
    if (!$ok) {
        audit("API $a rifiutata token={$T['name']} url=" . substr($url, 0, 200) . " :: $reason");
        api_fail("URL rifiutato: $reason", 422);
    }

    // Limite per token, contato direttamente sulle catture: nessuno stato extra.
    $c = $pdo->prepare("SELECT count(*) n FROM snapshots WHERE source=? AND ts > datetime('now','-1 hour')");
    $c->execute([$source]);
    if ((int)$c->fetch()['n'] >= API_CAPTURES_PER_H) {
        api_fail('troppe catture nell\'ultima ora per questo token', 429);
    }

    // Lo stesso link inoltrato due volte in pochi minuti non deve produrre due
    // prove identiche: si restituisce quella già in corso o appena fatta.
    if ($a === 'capture') {
        $d = $pdo->prepare("SELECT * FROM snapshots WHERE url=? AND status IN ('pending','running','ready')
                            AND ts > datetime('now','-10 minutes') ORDER BY ts DESC LIMIT 1");
        $d->execute([$url]);
        if ($dup = $d->fetch()) {
            out(['ok' => true, 'duplicate' => true] + snapshot_view($dup));
        }
    }

    $watchId = null;
    $every   = null;
    $parent  = null;
    $w       = false;
    if ($a === 'watch') {
        $every = clamp_int($in['every_hours'] ?? 24, 1, 720, 24);
        $ex = $pdo->prepare('SELECT id, last_short FROM watches WHERE url=?');
        $ex->execute([$url]);
        $w = $ex->fetch();
        // Se l'URL è già osservato, la nuova cattura si aggancia alla stessa
        // catena di versioni: così il worker la confronta con la precedente.
        if ($w && $w['last_short']) {
            $parent = chain_parent((string)$w['last_short']);
        }
    }
    [$short, $started] = enqueue_capture($url, $title, $parent, $source);

    if ($a === 'watch') {
        if ($w) {
            $watchId = (int)$w['id'];
            $pdo->prepare('UPDATE watches SET every_hours=?, last_short=?, last_run=CURRENT_TIMESTAMP, enabled=1 WHERE id=?')
                ->execute([$every, $short, $watchId]);
        } else {
            $pdo->prepare('INSERT INTO watches(url, title, every_hours, last_short, last_run) VALUES(?,?,?,?,CURRENT_TIMESTAMP)')
                ->execute([$url, ($title !== '' ? $title : null), $every, $short]);
            $watchId = (int)$pdo->lastInsertId();
        }
    }

    audit("API $a ok token={$T['name']} short=$short host=$host" . ($every ? " every=$every" : ''));
    $r = $pdo->prepare('SELECT * FROM snapshots WHERE short=?');
    $r->execute([$short]);
    $resp = ['ok' => true, 'duplicate' => false, 'started' => $started] + snapshot_view($r->fetch());
    if ($a === 'watch') {
        $resp['watch_id'] = $watchId;
        $resp['every_hours'] = $every;
    }
    out($resp, 201);
}

case 'status': {
    need($scopes, 'read');
    $short = (string)($_GET['short'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{5,12}$/', $short)) {
        api_fail('short non valido', 400);
    }
    $r = $pdo->prepare('SELECT * FROM snapshots WHERE short=?');
    $r->execute([$short]);
    $row = $r->fetch();
    if (!$row) {
        api_fail('prova inesistente', 404);
    }
    out(['ok' => true] + snapshot_view($row));
}

case 'recent': {
    need($scopes, 'read');
    $lim = clamp_int($_GET['limit'] ?? 10, 1, 50, 10);
    $r = $pdo->prepare('SELECT * FROM snapshots ORDER BY ts DESC LIMIT ?');
    $r->bindValue(1, $lim, PDO::PARAM_INT);
    $r->execute();
    out(['ok' => true, 'items' => array_map('snapshot_view', $r->fetchAll())]);
}

case 'search': {
    need($scopes, 'read');
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '' || mb_strlen($q) > 200) {
        api_fail('parametro q mancante o troppo lungo', 400);
    }
    $lim = clamp_int($_GET['limit'] ?? 5, 1, 25, 5);
    try {
        // Ordinamento per pertinenza (bm25) ed estratto dal corpo, con i
        // termini racchiusi fra « » così il client può evidenziarli.
        $r = $pdo->prepare("SELECT s.*, snippet(snapshots_fts, 3, '«', '»', '…', 14) AS excerpt
                            FROM snapshots_fts f JOIN snapshots s ON s.short = f.short
                            WHERE snapshots_fts MATCH ? ORDER BY bm25(snapshots_fts) LIMIT ?");
        $r->bindValue(1, $q);
        $r->bindValue(2, $lim, PDO::PARAM_INT);
        $r->execute();
        $rows = $r->fetchAll();
    } catch (Throwable) {
        api_fail('sintassi di ricerca non valida (operatori AND, OR, NOT, "frasi", prefissi*)', 400);
    }
    out(['ok' => true, 'query' => $q, 'items' => array_map(
        fn($x) => snapshot_view($x) + ['excerpt' => $x['excerpt']],
        $rows
    )]);
}

case 'events': {
    need($scopes, 'read');
    /* Catture concluse (ready o error) dopo il cursore "done_at|short".
     * Il cursore è una coppia perché done_at ha la risoluzione del secondo:
     * due catture concluse nello stesso secondo non devono perdersene una.
     * Senza `since` si ottiene solo il cursore attuale, così un client appena
     * avviato non rielabora tutto lo storico. */
    $since = (string)($_GET['since'] ?? '');
    if ($since === '') {
        $m = $pdo->query("SELECT done_at, short FROM snapshots WHERE done_at IS NOT NULL
                          ORDER BY done_at DESC, short DESC LIMIT 1")->fetch();
        $cur = $m ? $m['done_at'] . '|' . $m['short'] : gmdate('Y-m-d H:i:s') . '|';
        out(['ok' => true, 'items' => [], 'cursor' => $cur]);
    }
    if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([A-Za-z0-9]{0,12})$/', $since, $m)) {
        api_fail('cursore non valido', 400);
    }
    $r = $pdo->prepare("SELECT * FROM snapshots WHERE done_at IS NOT NULL
                        AND (done_at > ? OR (done_at = ? AND short > ?))
                        ORDER BY done_at, short LIMIT 50");
    $r->execute([$m[1], $m[1], $m[2]]);
    $rows = $r->fetchAll();
    $cur = $rows ? end($rows)['done_at'] . '|' . end($rows)['short'] : $since;
    out(['ok' => true, 'items' => array_map('snapshot_view', $rows), 'cursor' => $cur]);
}

case 'health': {
    need($scopes, 'read');
    $q = fn(string $sql) => $pdo->query($sql)->fetch();
    $counts = [];
    foreach ($pdo->query('SELECT status, count(*) n FROM snapshots GROUP BY status') as $row) {
        $counts[$row['status']] = (int)$row['n'];
    }
    $ots = [];
    foreach ($pdo->query('SELECT ots_status, count(*) n FROM snapshots GROUP BY ots_status') as $row) {
        $ots[(string)$row['ots_status']] = (int)$row['n'];
    }
    // Da dove arrivano le catture: è la misura con cui valutare se il bot
    // ha reso Snapper più usato.
    $sources = [];
    foreach ($pdo->query("SELECT CASE WHEN source LIKE 'api:%' THEN 'api' ELSE COALESCE(source,'web') END AS s,
                          count(*) n FROM snapshots WHERE ts > datetime('now','-14 days') GROUP BY s") as $row) {
        $sources[$row['s']] = (int)$row['n'];
    }
    $lastBackup = null;
    $bl = @file(DATA_DIR . '/backup.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    for ($i = count($bl) - 1; $i >= 0; $i--) {
        if (preg_match('/^\[([0-9-]+ [0-9:]+)\] (backup ok.*|BACKUP FALLITO.*)$/', $bl[$i], $mm)) {
            $lastBackup = ['at' => $mm[1], 'ok' => str_starts_with($mm[2], 'backup ok'), 'line' => $mm[2]];
            break;
        }
    }
    $lastErr = $q("SELECT short, url, status_msg, done_at FROM snapshots WHERE status='error'
                   ORDER BY done_at DESC LIMIT 1") ?: null;
    out([
        'ok'          => true,
        'queue'       => ['pending' => $counts['pending'] ?? 0, 'running' => $counts['running'] ?? 0],
        'archive'     => ['ready' => $counts['ready'] ?? 0, 'error' => $counts['error'] ?? 0],
        'ots'         => $ots,
        'sources_14d' => $sources,
        'last_backup' => $lastBackup,
        'last_capture'=> $q('SELECT max(done_at) t FROM snapshots')['t'] ?? null,
        'last_error'  => $lastErr,
        'disk'        => ['free' => @disk_free_space(DATA_DIR) ?: null, 'total' => @disk_total_space(DATA_DIR) ?: null],
        'watches'     => (int)$q('SELECT count(*) n FROM watches WHERE enabled=1')['n'],
    ]);
}

default:
    api_fail("azione sconosciuta: usa capture, watch, status, recent, search, events o health", 400);
}
