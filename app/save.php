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

$pdo = db();
[$short, $started] = enqueue_capture($url, $title);

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

$_SESSION['flash'] = $started
    ? "Archiviazione avviata ($short)."
    : "In coda ($short): worker occupati, partirà a breve.";

audit("SAVE ok ip=" . client_ip() . " short=$short host=$host watch=" . ($watch ? '1' : '0'));
header('Location: /snapper/index.php');
