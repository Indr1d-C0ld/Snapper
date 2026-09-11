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
$to    = ((string)($_POST['to'] ?? '1')) === '1' ? 1 : 0;
$back  = (string)($_POST['back'] ?? 'index.php');

if (preg_match('/^[A-Za-z0-9]{5,12}$/', $short)) {
    db()->prepare('UPDATE snapshots SET pinned=? WHERE short=?')->execute([$to, $short]);
}

// consenti solo redirect interni relativi
if (!preg_match('#^index\.php(\?[\w=&%.\-]*)?$#', $back)) {
    $back = 'index.php';
}
header('Location: /snapper/' . $back);
