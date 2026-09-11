<?php
declare(strict_types=1);

/* Avvia i worker per gli snapshot 'pending' fino a MAX_CONCURRENCY.
 * Invocato da worker.sh a fine cattura e da cron (cron-snapper.sh).  Solo CLI. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/config.php';

$lock = fopen(QUEUE_LOCK, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // un altro drain è già in corso
}

$pdo = db();
$spawned = 0;
while (true) {
    $running = (int)$pdo->query("SELECT COUNT(*) c FROM snapshots WHERE status='running'")->fetch()['c'];
    if ($running >= MAX_CONCURRENCY) break;

    $row = $pdo->query("SELECT short, url FROM snapshots WHERE status='pending' ORDER BY ts ASC LIMIT 1")->fetch();
    if (!$row) break;

    $upd = $pdo->prepare("UPDATE snapshots SET status='running' WHERE short=? AND status='pending'");
    $upd->execute([$row['short']]);
    if ($upd->rowCount() === 0) continue;

    $cmd = 'PATH=/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin nohup '
        . escapeshellarg(WORKER) . ' '
        . escapeshellarg((string)$row['short']) . ' ' . escapeshellarg((string)$row['url'])
        . ' >> ' . escapeshellarg(DATA_DIR . '/worker.log') . ' 2>&1 &';
    shell_exec($cmd);
    $spawned++;
    usleep(200000);
}

flock($lock, LOCK_UN);
fclose($lock);
echo "drain: avviati $spawned\n";
