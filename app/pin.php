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

if (preg_match('/^[A-Za-z0-9]{5,12}$/', $short)) {
    db()->prepare('UPDATE snapshots SET pinned=? WHERE short=?')->execute([$to, $short]);
}

/* Ricostruiamo la destinazione dai singoli parametri invece di filtrare la
 * stringa già assemblata: filtrare l'URL intero significava rifiutare qualunque
 * ricerca con uno spazio (codificato `+`) o con le virgolette — cioè il caso
 * normale — riportando l'utente all'elenco completo e facendogli perdere la
 * ricerca in corso. Così l'apertura verso redirect esterni resta chiusa per
 * costruzione: l'URL lo componiamo noi. */
$params = [];
$q = trim((string)($_POST['q'] ?? ''));
if ($q !== '') {
    $params['q'] = mb_substr($q, 0, 200);
}
if (($_POST['view'] ?? '') === 'ledger') {
    $params['view'] = 'ledger';
}
$page = (int)($_POST['page'] ?? 1);
if ($page > 1) {
    $params['page'] = $page;
}
header('Location: /snapper/index.php' . ($params ? '?' . http_build_query($params) : ''));
