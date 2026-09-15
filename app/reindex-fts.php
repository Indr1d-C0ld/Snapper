<?php
declare(strict_types=1);

/* Ricostruisce l'indice full-text dai `text.txt` già presenti su disco.
 *
 * Serve quando l'indice è disallineato dagli artefatti: una cattura riuscita
 * ma con corpo non indicizzato resta invisibile alla ricerca, pur avendo tutti
 * i file al loro posto. Ri-catturare funzionerebbe ma creerebbe una versione
 * nuova e inutile: questo ricostruisce e basta.
 *
 * Uso:
 *   sudo -u www-data php reindex-fts.php            elenca cosa farebbe
 *   sudo -u www-data php reindex-fts.php --apply    ricostruisce davvero
 *   sudo -u www-data php reindex-fts.php --apply <short> [<short>…]
 *
 * SOLO da CLI: tocca l'indice del database e non ha controllo di sessione.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/config.php';

$args  = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$only  = array_values(array_filter($args, fn($a) => $a !== '--apply'));

$pdo = db();
$sql = "SELECT short, COALESCE(title,'') AS title, url FROM snapshots WHERE status='ready'";
if ($only) {
    $sql .= ' AND short IN (' . implode(',', array_fill(0, count($only), '?')) . ')';
}
$st = $pdo->prepare($sql . ' ORDER BY ts DESC');
$st->execute($only);
$rows = $st->fetchAll();

$fixed = $ok = $skipped = 0;
foreach ($rows as $r) {
    $short = (string)$r['short'];

    $cur = $pdo->prepare('SELECT length(body) AS n FROM snapshots_fts WHERE short=?');
    $cur->execute([$short]);
    $have = (int)($cur->fetch()['n'] ?? 0);

    $txt  = path_within_data(DATA_DIR . '/' . $short . '/text.txt');
    $size = ($txt !== false && is_file($txt)) ? (int)filesize($txt) : 0;

    if ($size === 0) {
        printf("  %-9s  nessun text.txt su disco, salto\n", $short);
        $skipped++;
        continue;
    }
    if ($have > 0) {
        $ok++;
        continue;               // già indicizzato, non lo tocchiamo
    }

    printf("  %-9s  indice vuoto, testo su disco: %s  %s\n",
        $short, number_format($size) . ' B', $apply ? '→ ricostruito' : '(da ricostruire)');

    if ($apply) {
        $body = (string)file_get_contents($txt, false, null, 0, 5_242_880);
        $pdo->prepare('DELETE FROM snapshots_fts WHERE short=?')->execute([$short]);
        $pdo->prepare('INSERT INTO snapshots_fts(short,title,url,body) VALUES(?,?,?,?)')
            ->execute([$short, $r['title'], $r['url'], $body]);
    }
    $fixed++;
}

echo "\n";
printf("%d già a posto, %d da ricostruire, %d senza testo su disco\n", $ok, $fixed, $skipped);
if ($fixed > 0 && !$apply) {
    echo "Nessuna modifica applicata. Rilancia con --apply per ricostruire.\n";
}
