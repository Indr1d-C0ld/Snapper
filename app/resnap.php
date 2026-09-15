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
[$new, $started] = enqueue_capture((string)$row['url'], $row['title'], $parent);
$_SESSION['flash'] = $started
    ? "Nuova versione in sviluppo ($new)."
    : "Nuova versione in coda ($new).";

audit("RESNAP ip=" . client_ip() . " from=$short new=$new");
header('Location: /snapper/index.php');
