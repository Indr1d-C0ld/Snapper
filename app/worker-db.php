<?php
declare(strict_types=1);

/* Helper DB per worker.sh – SOLO da CLI, scrive con prepared statement.
 * Uso:  php worker-db.php <op> <short>   (payload JSON su stdin)
 *   op = meta   -> {final_url,http_status,content_type}
 *   op = ready  -> {title,size_bytes,sha256,capture_ms,ots_status,body_file}
 *   op = error  -> {msg}
 *   op = get    -> stampa JSON della riga (per leggere il titolo manuale)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/config.php';

$op    = $argv[1] ?? '';
$short = $argv[2] ?? '';
if (!preg_match('/^[A-Za-z0-9]{5,12}$/', $short)) {
    fwrite(STDERR, "short non valido\n");
    exit(2);
}
$in = json_decode((string)stream_get_contents(STDIN), true) ?: [];
$pdo = db();

switch ($op) {
    case 'get':
        $s = $pdo->prepare('SELECT * FROM snapshots WHERE short=?');
        $s->execute([$short]);
        echo json_encode($s->fetch() ?: []);
        break;

    case 'meta':
        $pdo->prepare('UPDATE snapshots SET final_url=?, http_status=?, content_type=? WHERE short=?')
            ->execute([
                $in['final_url']    ?? null,
                isset($in['http_status']) ? (int)$in['http_status'] : null,
                $in['content_type'] ?? null,
                $short,
            ]);
        break;

    case 'ready':
        $body = '';
        if (!empty($in['body_file']) && is_file($in['body_file'])) {
            $body = (string)file_get_contents($in['body_file'], false, null, 0, 5_242_880);
        }
        $pdo->prepare(
            'UPDATE snapshots SET status=\'ready\', status_msg=NULL,
             title=COALESCE(NULLIF(?,\'\'), title),
             size_bytes=?, sha256=?, capture_ms=?, ots_status=?, diff_pct=? WHERE short=?'
        )->execute([
            $in['title']      ?? '',
            (int)($in['size_bytes'] ?? 0),
            $in['sha256']     ?? null,
            isset($in['capture_ms']) ? (int)$in['capture_ms'] : null,
            $in['ots_status']  ?? 'none',
            isset($in['diff_pct']) && $in['diff_pct'] !== '' ? (float)$in['diff_pct'] : null,
            $short,
        ]);

        $t = $pdo->prepare('SELECT COALESCE(title,\'\') t, url FROM snapshots WHERE short=?');
        $t->execute([$short]);
        $r = $t->fetch() ?: ['t' => '', 'url' => ''];

        $pdo->prepare('DELETE FROM snapshots_fts WHERE short=?')->execute([$short]);
        $pdo->prepare('INSERT INTO snapshots_fts(short,title,url,body) VALUES(?,?,?,?)')
            ->execute([$short, $r['t'], $r['url'], $body]);
        break;

    case 'error':
        $pdo->prepare('UPDATE snapshots SET status=\'error\', status_msg=? WHERE short=?')
            ->execute([mb_substr((string)($in['msg'] ?? 'errore'), 0, 500), $short]);
        break;

    default:
        fwrite(STDERR, "op sconosciuta: $op\n");
        exit(2);
}
