<?php
declare(strict_types=1);

/* =========================================================================
 * Snapper – configurazione + primitive condivise (sicurezza dell'accesso)
 *
 * MODELLO. Copia questo file in `config.php` (stessa cartella) e adatta i
 * valori. `config.php` NON va versionato: e' gia' in .gitignore.
 * I segreti (utente/hash/TOTP) vanno in /etc/snapper/auth.php, fuori dal
 * document root — vedi deploy/auth.php.sample.
 * =======================================================================*/

const DB_PATH        = '/srv/snapshots/snapper.db';
const DATA_DIR       = '/srv/snapshots';
const ARCH_DIR       = '/var/www/html/archives';
const WORKER         = '/var/www/html/snapper/worker.sh';
const AUTH_LOG       = '/srv/snapshots/auth.log';
const RL_DIR         = '/srv/snapshots/ratelimit';
const QUEUE_LOCK     = '/srv/snapshots/.queue.lock';
const MAX_CONCURRENCY = 2;          // worker simultanei
const SESSION_IDLE    = 3600;       // 1 h di inattività
const SESSION_ABS     = 43200;      // 12 h di durata massima

/* ---- Segreti: preferisci un file FUORI dal document root -----------------
 * Crea /etc/snapper/auth.php con:
 *   <?php return [
 *     'user'        => 'admin',
 *     'hash'        => '$2y$12$....',        // password_hash(..., PASSWORD_BCRYPT)
 *     'totp_secret' => null,                 // base32 (RFC 4648) per abilitare la 2FA
 *   ];
 */
(function (): void {
    $candidates = ['/etc/snapper/auth.php', '/srv/snapshots/auth.php'];
    $auth = null;
    foreach ($candidates as $f) {
        if (is_readable($f)) { $auth = require $f; break; }
    }
    if (!is_array($auth)) {
        // Fallback se manca /etc/snapper/auth.php: genera l'hash con
        //   php -r 'echo password_hash("LA-TUA-PASSWORD", PASSWORD_BCRYPT), PHP_EOL;'
        $auth = [
            'user'        => 'admin',
            'hash'        => '',   // <-- incolla qui l'hash bcrypt
            'totp_secret' => null, // base32 per abilitare la 2FA
        ];
    }
    define('AUTH_USER',        (string)($auth['user'] ?? 'admin'));
    define('AUTH_PASS_HASH',   (string)($auth['hash'] ?? ''));
    define('AUTH_TOTP_SECRET', $auth['totp_secret'] ?? null);
})();

/* ---- Database ----------------------------------------------------------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

/* ---- Sessione blindata ------------------------------------------------- */
function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $https = (($_SERVER['HTTPS'] ?? '') === 'on')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_name('snapsid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/snapper/',   // il cookie NON raggiunge /archives/
        'httponly' => true,
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    $now = time();
    if (($_SESSION['ok'] ?? false) === true) {
        $born = (int)($_SESSION['born'] ?? $now);
        $seen = (int)($_SESSION['seen'] ?? $now);
        if (($now - $seen) > SESSION_IDLE || ($now - $born) > SESSION_ABS) {
            $_SESSION = [];
            session_destroy();
            header('Location: /snapper/login.php?expired=1');
            exit;
        }
    }
    $_SESSION['seen'] = $now;
}

function require_login(): void
{
    boot_session();
    if (($_SESSION['ok'] ?? false) !== true) {
        header('Location: /snapper/login.php');
        exit;
    }
}

/* ---- CSRF ------------------------------------------------------------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(403);
        exit('CSRF token non valido');
    }
}

/* ---- Utilità -------------------------------------------------------- */
function safe_short(int $len = 7): string
{
    $a = '2345679BCDFGHJKLMNPQRSTVWXYZbcdfghjkmnpqrstvwxyz';
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= $a[random_int(0, strlen($a) - 1)];
    }
    return $s;
}

/**
 * Verifica che un percorso stia davvero dentro DATA_DIR e ne restituisce la
 * forma canonica (false se è fuori o non esiste).
 *
 * Canonicalizza ENTRAMBI i lati. DATA_DIR può benissimo essere un symlink (è
 * frequente: la cartella dati vive su un volume diverso), e in quel caso
 * confrontare realpath() del file con la costante grezza non combacia mai.
 * Un confronto sbagliato in un controllo di sicurezza è peggio dell'assenza
 * del controllo, perché fallisce in silenzio: è esattamente così che l'indice
 * full-text è rimasto vuoto e che la cancellazione su disco veniva saltata.
 */
function path_within_data(string $path): string|false
{
    static $base = null;
    if ($base === null) {
        $base = realpath(DATA_DIR) ?: DATA_DIR;
    }
    $real = realpath($path);
    return ($real !== false && str_starts_with($real, $base . '/')) ? $real : false;
}

function client_ip(): string
{
    // Apache serve direttamente: REMOTE_ADDR è affidabile.
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function audit(string $line): void
{
    @file_put_contents(
        AUTH_LOG,
        sprintf("[%s] %s\n", date('c'), $line),
        FILE_APPEND | LOCK_EX
    );
}
