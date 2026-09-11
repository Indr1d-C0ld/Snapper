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

$short = (string)($_POST['short'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{5,12}$/', $short)) {
    http_response_code(400);
    exit('short non valido');
}

$pdo = db();
$src = $pdo->prepare('SELECT url, title, parent_short FROM snapshots WHERE short=?');
$src->execute([$short]);
$row = $src->fetch();
if (!$row) {
    http_response_code(404);
    exit('snapshot inesistente');
}

[$ok, $reason, $host] = validate_public_url((string)$row['url']);
if (!$ok) {
    $_SESSION['flash'] = "Ri-cattura annullata: $reason";
    header('Location: /snapper/index.php');
    exit;
}

$parent = $row['parent_short'] ?: $short;   // capostipite della catena di versioni
$new = safe_short(7);
$pdo->prepare('INSERT INTO snapshots(short, url, title, status, parent_short)
               VALUES(?,?,?,?,?)')
    ->execute([$new, $row['url'], $row['title'], 'pending', $parent]);

$running = (int)$pdo->query("SELECT COUNT(*) c FROM snapshots WHERE status='running'")->fetch()['c'];
if ($running < MAX_CONCURRENCY) {
    $pdo->prepare("UPDATE snapshots SET status='running' WHERE short=?")->execute([$new]);
    $cmd = 'PATH=/usr/bin:/bin:/usr/sbin:/sbin nohup '
        . escapeshellarg(WORKER) . ' ' . escapeshellarg($new) . ' ' . escapeshellarg((string)$row['url'])
        . ' >> ' . escapeshellarg(DATA_DIR . '/worker.log') . ' 2>&1 &';
    shell_exec($cmd);
    $_SESSION['flash'] = "Nuova versione in sviluppo ($new).";
} else {
    $_SESSION['flash'] = "Nuova versione in coda ($new).";
}

audit("RESNAP ip=" . client_ip() . " from=$short new=$new");
header('Location: /snapper/index.php');
