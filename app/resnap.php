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
$src = $pdo->prepare('SELECT url, title, parent_short, kind FROM snapshots WHERE short=?');
require_once __DIR__ . '/crawllib.php';
$src->execute([$short]);
$row = $src->fetch();
if (!$row) {
    http_response_code(404);
    exit('snapshot inesistente');
}

if (in_array($row['kind'] ?? 'page', ['wiki', 'wikiexport'], true)) {
    $w = $pdo->prepare('SELECT wiki_page FROM wiki_jobs WHERE short=?');
    $w->execute([$short]);
    $wp = (int)($w->fetch()['wiki_page'] ?? 0);
    $_SESSION['flash'] = 'Le prove di Wikipedia non si ri-catturano: le nuove revisioni si acquisiscono dal dossier della voce.';
    header('Location: /snapper/wiki.php' . ($wp ? "?page=$wp" : ''));
    exit;
}

[$ok, $reason, $host] = validate_public_url((string)$row['url']);
if ($ok && ($row['kind'] ?? 'page') === 'site') {
    // un sito si riscarica con le stesse opzioni, come nuova versione della catena
    $j = $pdo->prepare('SELECT options FROM site_jobs WHERE short=?');
    $j->execute([$short]);
    $opt = crawl_options(json_decode((string)($j->fetch()['options'] ?? '{}'), true) ?: []);
    [$new, $started] = site_enqueue($pdo, (string)$row['url'], $opt, 'web', $row['parent_short'] ?: $short);
    audit("RESNAP site ip=" . client_ip() . " from=$short new=$new");
    $_SESSION['flash'] = ($started ? 'Nuovo download del sito avviato' : 'Nuovo download del sito in coda') . " ($new), con le stesse opzioni.";
    header('Location: /snapper/sites.php');
    exit;
}
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
