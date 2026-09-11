<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /snapper/index.php');
    exit;
}
csrf_check();

$url   = trim((string)($_POST['url'] ?? ''));
$title = trim((string)($_POST['title'] ?? ''));
$title = mb_substr($title, 0, 300);
$watch = isset($_POST['watch']);
$every = max(1, min(720, (int)($_POST['every_hours'] ?? 24)));

[$ok, $reason, $host] = validate_public_url($url);
if (!$ok) {
    audit('SAVE reject ip=' . client_ip() . " url=" . substr($url, 0, 200) . " :: $reason");
    $_SESSION['flash'] = "URL rifiutato: $reason";
    header('Location: /snapper/index.php');
    exit;
}

$short = safe_short(7);
$pdo = db();
$pdo->prepare('INSERT INTO snapshots(short, url, title, status) VALUES(?,?,?,?)')
    ->execute([$short, $url, ($title !== '' ? $title : null), 'pending']);

if ($watch) {
    $ex = $pdo->prepare('SELECT id FROM watches WHERE url=?');
    $ex->execute([$url]);
    if ($row = $ex->fetch()) {
        $pdo->prepare('UPDATE watches SET every_hours=?, last_short=?, last_run=CURRENT_TIMESTAMP, enabled=1 WHERE id=?')
            ->execute([$every, $short, $row['id']]);
    } else {
        $pdo->prepare('INSERT INTO watches(url, title, every_hours, last_short, last_run)
                       VALUES(?,?,?,?,CURRENT_TIMESTAMP)')
            ->execute([$url, ($title !== '' ? $title : null), $every, $short]);
    }
}

// Avvia subito solo se siamo sotto la soglia di concorrenza; altrimenti resta "pending"
$running = (int)$pdo->query("SELECT COUNT(*) c FROM snapshots WHERE status='running'")
    ->fetch()['c'];
if ($running < MAX_CONCURRENCY) {
    spawn_worker($short, $url);
    $_SESSION['flash'] = "Archiviazione avviata ($short).";
} else {
    $_SESSION['flash'] = "In coda ($short): worker occupati, partirà a breve.";
}

audit("SAVE ok ip=" . client_ip() . " short=$short host=$host watch=" . ($watch ? '1' : '0'));
header('Location: /snapper/index.php');

/* ------------------------------------------------------------------ */
function spawn_worker(string $short, string $url): void
{
    db()->prepare("UPDATE snapshots SET status='running' WHERE short=? AND status='pending'")
        ->execute([$short]);
    $cmd = 'PATH=/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin nohup '
        . escapeshellarg(WORKER) . ' '
        . escapeshellarg($short) . ' ' . escapeshellarg($url)
        . ' >> ' . escapeshellarg(DATA_DIR . '/worker.log') . ' 2>&1 &';
    shell_exec($cmd);
}
