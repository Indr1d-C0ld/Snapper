<?php
declare(strict_types=1);

/* Gestione dei token dell'API di Snapper.  SOLO da CLI.
 *
 *   sudo -u www-data php /var/www/html/snapper/api-token.php create <nome> [--scopes=capture,read]
 *   sudo -u www-data php /var/www/html/snapper/api-token.php list
 *   sudo -u www-data php /var/www/html/snapper/api-token.php revoke <nome>
 *   sudo -u www-data php /var/www/html/snapper/api-token.php rotate <nome>
 *
 * Il token viene mostrato UNA volta sola, alla creazione: nel database resta
 * soltanto la sua impronta SHA-256. Se va perso, si ruota. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$dbPath = getenv('SNAPPER_DB') ?: '/srv/snapshots/snapper.db';
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA busy_timeout=5000');

function usage(): never
{
    fwrite(STDERR, "uso: api-token.php create <nome> [--scopes=capture,read] | list | revoke <nome> | rotate <nome>\n");
    exit(2);
}

function new_token(): string
{
    return 'snp_' . bin2hex(random_bytes(32));
}

$cmd  = $argv[1] ?? '';
$name = $argv[2] ?? '';
$scopes = 'capture,read';
foreach (array_slice($argv, 3) as $opt) {
    if (preg_match('/^--scopes=([a-z,]+)$/', $opt, $m)) {
        $list = array_unique(array_filter(explode(',', $m[1])));
        if (!$list || array_diff($list, ['capture', 'read'])) {
            fwrite(STDERR, "ambiti ammessi: capture, read\n");
            exit(2);
        }
        sort($list);
        $scopes = implode(',', $list);
    } else {
        usage();
    }
}
if (in_array($cmd, ['create', 'revoke', 'rotate'], true)
    && !preg_match('/^[a-z0-9][a-z0-9_-]{1,40}$/', $name)) {
    fwrite(STDERR, "nome non valido: minuscole, cifre, - e _ (2-41 caratteri)\n");
    exit(2);
}

switch ($cmd) {
case 'create':
    $ex = $pdo->prepare('SELECT revoked FROM api_tokens WHERE name=?');
    $ex->execute([$name]);
    if ($ex->fetch()) {
        fwrite(STDERR, "esiste già un token '$name': usa rotate per sostituirlo\n");
        exit(1);
    }
    $t = new_token();
    $pdo->prepare('INSERT INTO api_tokens(name, token_hash, scopes) VALUES(?,?,?)')
        ->execute([$name, hash('sha256', $t), $scopes]);
    echo $t, "\n";
    fwrite(STDERR, "token '$name' creato (ambiti: $scopes). Non verrà mostrato di nuovo.\n");
    break;

case 'rotate':
    // Sostituisce l'impronta: il vecchio token smette subito di funzionare,
    // nome, ambiti e storico d'uso restano.
    $t = new_token();
    $st = $pdo->prepare('UPDATE api_tokens SET token_hash=?, revoked=NULL, created=CURRENT_TIMESTAMP WHERE name=?');
    $st->execute([hash('sha256', $t), $name]);
    if ($st->rowCount() === 0) {
        fwrite(STDERR, "nessun token '$name'\n");
        exit(1);
    }
    echo $t, "\n";
    fwrite(STDERR, "token '$name' ruotato: il precedente non è più valido.\n");
    break;

case 'revoke':
    $st = $pdo->prepare('UPDATE api_tokens SET revoked=CURRENT_TIMESTAMP WHERE name=? AND revoked IS NULL');
    $st->execute([$name]);
    if ($st->rowCount() === 0) {
        fwrite(STDERR, "nessun token attivo '$name'\n");
        exit(1);
    }
    echo "token '$name' revocato\n";
    break;

case 'list':
    $rows = $pdo->query('SELECT name, scopes, created, last_used, uses, revoked FROM api_tokens ORDER BY name')->fetchAll();
    if (!$rows) {
        echo "nessun token\n";
        break;
    }
    printf("%-20s %-14s %-19s %-19s %6s  %s\n", 'NOME', 'AMBITI', 'CREATO', 'ULTIMO USO', 'USI', 'STATO');
    foreach ($rows as $r) {
        printf("%-20s %-14s %-19s %-19s %6d  %s\n",
            $r['name'], $r['scopes'], $r['created'], $r['last_used'] ?? '-', $r['uses'],
            $r['revoked'] ? "revocato {$r['revoked']}" : 'attivo');
    }
    break;

default:
    usage();
}
