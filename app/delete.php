<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metodo non consentito');
}
csrf_check();

$short = (string)($_POST['short'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{5,12}$/', $short)) {
    http_response_code(400);
    exit('short non valido');
}

// un sito in corso si ferma prima dalla sua pagina: il crawler scrive ancora nella cartella
$k = db()->prepare('SELECT kind, status FROM snapshots WHERE short=?');
$k->execute([$short]);
$kr = $k->fetch() ?: [];
$k->closeCursor();
if (($kr['kind'] ?? '') === 'site' && ($kr['status'] ?? '') === 'running') {
    $_SESSION['flash'] = "Il sito $short è ancora in download: fermalo dalla pagina Siti, poi eliminalo.";
    header('Location: /snapper/sites.php');
    exit;
}

$root = DATA_DIR . '/' . $short;
$arch = ARCH_DIR . '/' . $short;

/* Ordine deliberato: prima il disco, poi il database. Cancellando prima le
 * righe, un fallimento su disco lascerebbe cartelle orfane che l'applicazione
 * non elenca più — spazio occupato e invisibile. Così invece un fallimento
 * lascia lo snapshot ancora in elenco, visibile e ri-eliminabile. */
if (is_link($arch) || file_exists($arch)) {
    @unlink($arch);
}
if (is_dir($root)) {
    // Se la guardia rifiuta il percorso NON proseguiamo in silenzio: saltare la
    // rimozione e cancellare comunque le righe lascerebbe una cartella orfana
    // invisibile — proprio il guasto che quest'ordine vuole evitare.
    $safe = path_within_data($root);
    if ($safe === false) {
        audit("DELETE rifiutata ip=" . client_ip() . " short=$short (percorso fuori da " . DATA_DIR . ")");
        $_SESSION['flash'] = "Eliminazione di $short annullata: percorso non riconosciuto. Nulla è stato modificato.";
        header('Location: /snapper/index.php');
        exit;
    }
    rrmdir($safe);
    if (is_dir($safe)) {
        audit("DELETE fallita ip=" . client_ip() . " short=$short (rimozione su disco incompleta)");
        $_SESSION['flash'] = "Eliminazione di $short non riuscita: file ancora presenti su disco. Snapshot mantenuto in elenco.";
        header('Location: /snapper/index.php');
        exit;
    }
}

$pdo = db();
$pdo->beginTransaction();
$pdo->prepare('DELETE FROM snapshots WHERE short=?')->execute([$short]);
$pdo->prepare('DELETE FROM snapshots_fts WHERE short=?')->execute([$short]);
// il watch resta: si gestisce da watches.php. Sgancia solo il riferimento.
$pdo->prepare('UPDATE watches SET last_short=NULL WHERE last_short=?')->execute([$short]);
// Wikipedia: le revisioni archiviate in questa prova escono dal dossier; una
// voce senza più revisioni né acquisizioni in corso sparisce dall'elenco.
$pdo->prepare('DELETE FROM wiki_revisions WHERE short=?')->execute([$short]);
$pdo->prepare('DELETE FROM wiki_jobs WHERE short=?')->execute([$short]);
$pdo->prepare('DELETE FROM site_pages WHERE short=?')->execute([$short]);
$pdo->prepare('DELETE FROM site_pages_fts WHERE short=?')->execute([$short]);
$pdo->prepare('DELETE FROM site_jobs WHERE short=?')->execute([$short]);
$pdo->exec('DELETE FROM wiki_pages WHERE id NOT IN (SELECT wiki_page FROM wiki_revisions)
                                    AND id NOT IN (SELECT wiki_page FROM wiki_jobs)');
$pdo->commit();

audit("DELETE ip=" . client_ip() . " short=$short");
$_SESSION['flash'] = "Snapshot $short eliminato.";
header('Location: /snapper/index.php');

/* ------------------------------------------------------------------ */
function rrmdir(string $d): void
{
    if (!is_dir($d)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($d);
}
