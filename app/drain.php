<?php
declare(strict_types=1);

/* Avvia i worker per gli snapshot 'pending' fino a MAX_CONCURRENCY.
 * Invocato da worker.sh a fine cattura e da cron (cron-snapper.sh).  Solo CLI. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';

$lock = fopen(QUEUE_LOCK, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // un altro drain è già in corso
}

$pdo = db();
$spawned = 0;
// Teniamo il lock per tutto il giro: spawn_worker_locked() lo presuppone e non
// tenta di riacquisirlo (lo farebbe su un secondo descrittore, bloccandosi).
while (true) {
    $row = $pdo->query("SELECT short, url FROM snapshots WHERE status='pending' ORDER BY ts ASC LIMIT 1")->fetch();
    if (!$row) break;

    if (!spawn_worker_locked((string)$row['short'], (string)$row['url'])) {
        break;   // limite di concorrenza raggiunto, o preso da qualcun altro
    }
    $spawned++;
    usleep(200000);
}

flock($lock, LOCK_UN);
fclose($lock);
echo "drain: avviati $spawned\n";
