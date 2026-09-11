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

$root = DATA_DIR . '/' . $short;
$arch = ARCH_DIR . '/' . $short;

$pdo = db();
$pdo->beginTransaction();
$pdo->prepare('DELETE FROM snapshots WHERE short=?')->execute([$short]);
$pdo->prepare('DELETE FROM snapshots_fts WHERE short=?')->execute([$short]);
// il watch resta: si gestisce da watches.php. Sgancia solo il riferimento.
$pdo->prepare('UPDATE watches SET last_short=NULL WHERE last_short=?')->execute([$short]);
$pdo->commit();

if (is_link($arch) || file_exists($arch)) {
    @unlink($arch);
}
if (is_dir($root) && str_starts_with(realpath($root) ?: '', DATA_DIR . '/')) {
    rrmdir($root);
}

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
