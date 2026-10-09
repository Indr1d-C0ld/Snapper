<?php
declare(strict_types=1);

/* =========================================================================
 * Snapper – acquisizione di revisioni di Wikipedia.  SOLO da CLI.
 *
 * Avviato da worker.sh quando la prova è di tipo 'wiki' (dopo i controlli
 * anti-SSRF), come www-data:   php wiki-worker.php <short>
 *
 * Per ogni revisione richiesta salva:
 *   rev/<revid>/wikitext.txt   il testo sorgente esatto, verificato con lo sha1
 *                              pubblicato da Wikipedia
 *   rev/<revid>/index.html     la revisione resa, navigabile senza rete
 *   rev/<revid>/page.css       stili propri della revisione (TemplateStyles e
 *                              stili inline, che la sandbox degli archivi non ammette)
 *   rev/<revid>/meta.json      metadati della revisione
 *   rev/<revid>/parse.json     struttura resa da Wikipedia: sezioni, categorie,
 *                              link esterni, template (per i confronti)
 * più assets/ (stili e immagini condivisi), text.txt, SHA256SUMS(.ots),
 * index.html e bundle.zip.
 *
 * Nessuna risorsa esterna resta collegata: immagini scaricate, link interni
 * verso altre voci archiviate oppure resi inerti, link esterni resi inerti.
 * =======================================================================*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
ini_set('memory_limit', '1536M');
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/wikilib.php';

const WK_IMG_MAX_BYTES   = 20_000_000;    // per immagine
const WK_IMG_TOTAL_BYTES = 400_000_000;   // per acquisizione
const WK_IMG_MAX_COUNT   = 4000;

$short = (string)($argv[1] ?? '');
if (!preg_match('/^[A-Za-z0-9]{5,12}$/', $short)) {
    fwrite(STDERR, "short non valido\n");
    exit(2);
}

function wlog(string $m): void
{
    global $short;
    // stesso orario di worker.sh (ora locale), nello stesso registro
    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
    fwrite(STDERR, sprintf("[%s] WIKI %s :: %s\n", $now->format('Y-m-d H:i:s'), $short, $m));
}

function wk_finish_error(string $msg): never
{
    global $short;
    wlog("ERROR $msg");
    $pdo = db();
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // una prova fallita non deve lasciare revisioni nel dossier della voce
    $pdo->prepare('DELETE FROM wiki_revisions WHERE short=?')->execute([$short]);
    $pdo->prepare("UPDATE snapshots SET status='error', status_msg=?, done_at=CURRENT_TIMESTAMP WHERE short=?")
        ->execute([mb_substr($msg, 0, 500), $short]);
    wk_drain();
    exit(1);
}

function wk_drain(): void
{
    $php = PHP_BINARY ?: '/usr/bin/php';
    @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/drain.php') . ' >/dev/null 2>&1');
}

/* ---- CSS: nessun riferimento esterno sopravvive ----------------------- */
function wk_css(string $css): string
{
    $css = preg_replace('/@import\b[^;]*;?/i', '', $css);
    $css = preg_replace('/(-webkit-)?image-set\([^)]*\)/i', 'none', $css);
    $css = preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/is',
        fn($m) => str_starts_with(ltrim($m[2]), 'data:') ? $m[0] : 'none', $css);
    return str_ireplace(['expression(', '-moz-binding', 'behavior:'], ['x(', 'x', 'x:'], $css);
}

/** stile inline -> dichiarazioni con !important, per conservarne la precedenza */
function wk_decls(string $style): string
{
    $out = [];
    foreach (explode(';', wk_css($style)) as $d) {
        $d = trim($d);
        if ($d === '' || !str_contains($d, ':')) {
            continue;
        }
        $out[] = preg_match('/!\s*important\s*$/i', $d) ? $d : "$d !important";
    }
    return implode(';', $out);
}

/* ---- Immagini --------------------------------------------------------- */
$IMG = ['map' => [], 'bytes' => 0, 'count' => 0, 'skipped' => 0];

function wk_media(string $src, string $lang, string $root): ?string
{
    global $IMG;
    $src = html_entity_decode(trim($src), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (str_starts_with($src, '//')) {
        $src = 'https:' . $src;
    } elseif (str_starts_with($src, '/')) {
        $src = "https://$lang.wikipedia.org" . $src;
    }
    $host = strtolower((string)parse_url($src, PHP_URL_HOST));
    if (!str_starts_with($src, 'https://')
        || !(in_array($host, WIKI_MEDIA_HOSTS, true) || $host === "$lang.wikipedia.org")) {
        return null;
    }
    $key = strtok($src, '?');
    if (array_key_exists($key, $IMG['map'])) {
        return $IMG['map'][$key];
    }
    $IMG['map'][$key] = null;
    if ($IMG['count'] >= WK_IMG_MAX_COUNT || $IMG['bytes'] >= WK_IMG_TOTAL_BYTES) {
        $IMG['skipped']++;
        return null;
    }
    try {
        [$code, $body, $ctype] = wiki_http_get($src, WK_IMG_MAX_BYTES, 60);
    } catch (Throwable) {
        $IMG['skipped']++;
        return null;
    }
    if ($code !== 200 || !str_starts_with(strtolower($ctype), 'image/') || $body === '') {
        $IMG['skipped']++;
        return null;
    }
    $ext = strtolower(pathinfo((string)parse_url($key, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (!preg_match('/^(png|jpe?g|gif|svg|webp|avif|ico)$/', $ext)) {
        $ext = match (true) {
            str_contains($ctype, 'png')  => 'png',
            str_contains($ctype, 'jpeg') => 'jpg',
            str_contains($ctype, 'gif')  => 'gif',
            str_contains($ctype, 'svg')  => 'svg',
            str_contains($ctype, 'webp') => 'webp',
            default                      => 'img',
        };
    }
    $name = substr(sha1($key), 0, 24) . '.' . $ext;
    file_put_contents("$root/assets/img/$name", $body);
    $IMG['bytes'] += strlen($body);
    $IMG['count']++;
    return $IMG['map'][$key] = $name;
}

/* ---- Link ------------------------------------------------------------- */
$LINK_CACHE = [];

/** voce già archiviata altrove in Snapper? -> percorso della sua revisione più recente */
function wk_archived_target(string $lang, string $title): ?string
{
    global $LINK_CACHE;
    $k = "$lang|$title";
    if (!array_key_exists($k, $LINK_CACHE)) {
        $s = db()->prepare("SELECT r.short, r.revid FROM wiki_revisions r
                            JOIN wiki_pages p ON p.id = r.wiki_page
                            JOIN snapshots s ON s.short = r.short AND s.status = 'ready'
                            WHERE p.lang = ? AND p.title = ? ORDER BY r.ts DESC LIMIT 1");
        $s->execute([$lang, $title]);
        $r = $s->fetch();
        $s->closeCursor();
        $LINK_CACHE[$k] = $r ? '/archives/' . rawurlencode($r['short']) . '/rev/' . (int)$r['revid'] . '/' : null;
    }
    return $LINK_CACHE[$k];
}

function wk_inert(Dom\Element $a, string $abs, string $cls, string $label): void
{
    $a->removeAttribute('href');
    $a->removeAttribute('target');
    $a->setAttribute('data-href', $abs);
    $a->setAttribute('title', $label . ' · ' . $abs);
    $a->classList->add($cls);
}

function wk_link(Dom\Element $a, string $lang, string $selfTitle): void
{
    $href = trim(html_entity_decode((string)$a->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || str_starts_with($href, '#')) {
        return;
    }
    $abs = $href;
    if (str_starts_with($abs, '//')) {
        $abs = 'https:' . $abs;
    } elseif (str_starts_with($abs, '/')) {
        $abs = "https://$lang.wikipedia.org" . $abs;
    }
    if (!preg_match('#^https?://#i', $abs)) {
        wk_inert($a, $href, 'snp-ext', 'Collegamento non seguibile');
        return;
    }
    $host = strtolower((string)parse_url($abs, PHP_URL_HOST));
    if ($host === "$lang.wikipedia.org" || $host === "$lang.m.wikipedia.org") {
        $p = wiki_parse_url($abs);
        $frag = parse_url($abs, PHP_URL_FRAGMENT);
        $redlink = str_contains($abs, 'redlink=1');
        if ($p && $p['title'] !== null && !$redlink && $p['oldid'] === null && $p['diff'] === null) {
            if ($p['title'] === $selfTitle && $frag) {
                $a->setAttribute('href', '#' . $frag);
                return;
            }
            $t = wk_archived_target($lang, $p['title']);
            if ($t !== null) {
                $a->setAttribute('href', $t . ($frag ? '#' . rawurlencode($frag) : ''));
                $a->classList->add('snp-in');
                $a->setAttribute('title', 'Voce archiviata in Snapper: ' . $p['title']);
                return;
            }
            wk_inert($a, $abs, 'snp-na', 'Voce non archiviata: ' . $p['title']);
            return;
        }
        wk_inert($a, $abs, 'snp-na', $redlink ? 'Voce inesistente' : 'Pagina di Wikipedia non archiviata');
        return;
    }
    wk_inert($a, $abs, 'snp-ext', 'Collegamento esterno');
}

/**
 * Rende autosufficiente l'HTML di una revisione.
 * @return array{0:string,1:string,2:string}  [html del contenuto, css della revisione, testo puro]
 */
function wk_transform(string $html, string $lang, string $selfTitle, string $root): array
{
    $doc = Dom\HTMLDocument::createFromString(
        '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="snp-root">' . $html . '</div></body></html>',
        LIBXML_NOERROR, 'UTF-8');
    $r = $doc->getElementById('snp-root');
    $css = [];

    foreach (iterator_to_array($r->querySelectorAll('style')) as $s) {
        $css[] = wk_css($s->textContent);
        $s->remove();
    }
    foreach (iterator_to_array($r->querySelectorAll('script,noscript,link,meta,base,iframe,frame,object,embed,applet')) as $x) {
        $x->remove();
    }
    $classes = [];
    foreach (iterator_to_array($r->querySelectorAll('*')) as $el) {
        foreach (iterator_to_array($el->attributes) as $at) {
            $n = strtolower($at->name);
            if (str_starts_with($n, 'on') || in_array($n, ['srcset', 'formaction', 'action', 'poster', 'background', 'ping'], true)) {
                $el->removeAttribute($at->name);
            }
        }
        if ($el->hasAttribute('style')) {
            $d = wk_decls((string)$el->getAttribute('style'));
            $el->removeAttribute('style');
            if ($d !== '') {
                $cls = 'snp-s' . substr(sha1($d), 0, 10);
                $classes[$cls] = $d;
                $el->classList->add($cls);
            }
        }
    }
    foreach (iterator_to_array($r->querySelectorAll('img')) as $img) {
        $local = wk_media((string)$img->getAttribute('src'), $lang, $root);
        if ($local !== null) {
            $img->setAttribute('src', '../../assets/img/' . $local);
        } else {
            $img->removeAttribute('src');
            $img->classList->add('snp-noimg');
        }
        foreach (['data-src', 'data-srcset', 'resource'] as $at) {
            $img->removeAttribute($at);
        }
    }
    foreach (iterator_to_array($r->querySelectorAll('source,video,audio,track,input')) as $m) {
        $m->removeAttribute('src');
    }
    foreach (iterator_to_array($r->querySelectorAll('a[href],area[href]')) as $a) {
        wk_link($a, $lang, $selfTitle);
    }
    foreach (iterator_to_array($r->querySelectorAll('form')) as $f) {
        $f->removeAttribute('action');
    }

    foreach ($classes as $cls => $d) {
        $css[] = ".$cls{" . $d . '}';
    }
    $text = trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/\n{3,}/', "\n\n", (string)$r->textContent)));
    return [$r->innerHTML, implode("\n", $css) . "\n", $text];
}

/* ---- Pagine generate -------------------------------------------------- */

const WK_OWN_CSS = <<<'CSS'
/* Snapper – intestazione delle revisioni archiviate e indice della prova */
:root{--snp-bg:#17150f;--snp-paper:#f2ecdd;--snp-muted:#9a927f;--snp-line:#3b3427;--snp-red:#c8402f;--snp-ok:#7a9a6d;--snp-amber:#d79a3f}
body.snp-body{margin:0;background:#fff;color:#202122}
.snp-bar{background:var(--snp-bg);color:var(--snp-paper);padding:1.1rem clamp(1rem,4vw,2.5rem) 1rem;font:14px/1.5 ui-sans-serif,-apple-system,"Segoe UI",Roboto,sans-serif;border-bottom:3px solid var(--snp-red)}
.snp-bar a{color:#e6dbc2}
.snp-kicker{font:700 10.5px/1 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.2em;text-transform:uppercase;color:var(--snp-red);margin-bottom:.45rem}
.snp-kicker .snp-code{text-transform:none;letter-spacing:.04em}
.snp-h{font-size:1.35rem;line-height:1.2;margin:0 0 .7rem;font-weight:700}
.snp-meta{display:grid;grid-template-columns:max-content 1fr;gap:.25rem .9rem;margin:0;font:12.5px/1.45 ui-monospace,Menlo,Consolas,monospace;max-width:70rem}
.snp-meta dt{color:var(--snp-muted);text-transform:uppercase;letter-spacing:.08em;font-size:10.5px;padding-top:.15em}
.snp-meta dd{margin:0;overflow-wrap:anywhere}
.snp-ok{color:var(--snp-ok);font-weight:700}.snp-bad{color:var(--snp-red);font-weight:700}
.snp-note{margin:.8rem 0 0;font-size:12.5px;color:#d8cdb2;max-width:75ch;border-left:2px solid var(--snp-amber);padding-left:.6rem}
.snp-nav{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.85rem}
.snp-nav a,.snp-nav span{font:600 11.5px/1 ui-sans-serif,-apple-system,sans-serif;text-transform:uppercase;letter-spacing:.06em;border:1px solid var(--snp-line);padding:.45rem .65rem;text-decoration:none;color:var(--snp-paper)}
.snp-nav span{color:var(--snp-muted)}
.snp-nav a:hover{background:#2a251b}
.snp-content{max-width:76rem;margin:0 auto;padding:1rem clamp(1rem,4vw,2.5rem) 4rem;background:#fff}
.snp-content #firstHeading{font-family:'Linux Libertine','Georgia','Times',serif;font-size:1.8em;font-weight:normal;border-bottom:1px solid #a2a9b1;margin:0 0 .25em;padding:0}
a.snp-na,a.snp-ext{color:inherit;text-decoration:underline dotted #a2a9b1;cursor:help}
a.snp-ext::after{content:"↗";font-size:.75em;color:#72777d;margin-left:.1em}
a.snp-in{color:#0645ad}
img.snp-noimg{background:repeating-linear-gradient(45deg,#eaecf0 0 6px,#f8f9fa 6px 12px);min-width:24px;min-height:24px}
/* indice della prova */
.snp-index{background:var(--snp-bg);color:var(--snp-paper);min-height:100vh;padding:2rem clamp(1rem,4vw,3rem);font:14.5px/1.55 ui-sans-serif,-apple-system,"Segoe UI",Roboto,sans-serif}
.snp-card{max-width:72rem;margin:0 auto;background:var(--snp-paper);color:#1c1a15;border:1px solid #c8bd9f;padding:1.4rem clamp(1rem,3vw,1.8rem) 2rem}
.snp-card .snp-kicker{margin-bottom:.4rem}
.snp-card h1{font-size:1.3rem;margin:.1rem 0 1rem}
.snp-card a{color:#7a5a12}
.snp-card .snp-meta dt{color:#6f6857}
.snp-card .snp-ok{color:#3f6b33}.snp-card .snp-bad{color:#a52a1d}
.snp-scroll{overflow-x:auto;margin:1.2rem 0}
.snp-tbl{border-collapse:collapse;width:100%;font-size:13px;min-width:46rem}
.snp-tbl th,.snp-tbl td{border-bottom:1px solid #c8bd9f;padding:.45rem .5rem;text-align:left;vertical-align:top}
.snp-tbl th{font:700 10.5px/1.2 ui-monospace,Menlo,monospace;letter-spacing:.1em;text-transform:uppercase;color:#6f6857}
.snp-tbl td.n{font-family:ui-monospace,Menlo,monospace;white-space:nowrap;font-variant-numeric:tabular-nums}
.snp-verify{font:12.5px/1.55 ui-monospace,Menlo,monospace;background:#e6dbc2;border:1px solid #c8bd9f;padding:.7rem .9rem;overflow-x:auto;white-space:pre}
.snp-chips{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1rem}
.snp-chips a{border:1px solid #c8bd9f;padding:.45rem .7rem;text-decoration:none;font:600 11.5px/1 ui-sans-serif,sans-serif;text-transform:uppercase;letter-spacing:.06em;color:#1c1a15}
CSS;

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function wk_rev_page(array $rv, array $ctx, ?array $prev, ?array $next, string $content): string
{
    $lang = $ctx['lang'];
    $sha1Line = $rv['sha1'] === null
        ? '<span class="snp-bad">non pubblicato da Wikipedia (oscurato)</span>'
        : ($rv['sha1_ok']
            ? '<span class="snp-ok">✓ coincide</span> con lo sha1 pubblicato da Wikipedia · <code>' . e($rv['sha1']) . '</code>'
            : '<span class="snp-bad">✗ NON coincide</span> · Wikipedia: <code>' . e($rv['sha1']) . '</code> · archiviato: <code>' . e($rv['sha1_calc']) . '</code>');
    $tags = $rv['tags'] ? e(implode(', ', $rv['tags'])) : '—';
    $user = $rv['user'] === null ? '<i>nascosto</i>' : e($rv['user']) . ($rv['anon'] ? ' (IP)' : '');
    $comment = $rv['comment'] === null ? '<i>nascosto</i>' : ($rv['comment'] === '' ? '—' : e($rv['comment']));
    $nav = ($prev ? '<a href="../' . $prev['revid'] . '/">‹ precedente</a>' : '<span>‹ precedente</span>')
         . '<a href="../../">indice della prova</a>'
         . ($next ? '<a href="../' . $next['revid'] . '/">successiva ›</a>' : '<span>successiva ›</span>')
         . '<a href="wikitext.txt">wikitesto</a><a href="meta.json">metadati</a>';
    $ttl = e($ctx['title']);
    return '<!doctype html><html lang="' . e($lang) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . "<title>$ttl · revisione {$rv['revid']} · Snapper</title>"
        . '<link rel="stylesheet" href="../../assets/wiki.css"><link rel="stylesheet" href="page.css">'
        . '<link rel="stylesheet" href="../../assets/snapper-wiki.css"></head>'
        . '<body class="mediawiki ltr sitedir-ltr mw-hide-empty-elt ns-0 skin-vector action-view snp-body">'
        . '<header class="snp-bar">'
        . '<div class="snp-kicker">Snapper · prova <span class="snp-code">' . e($ctx['short']) . '</span> · revisione ' . $rv['pos'] . ' di ' . $ctx['n'] . '</div>'
        . "<h1 class=\"snp-h\">$ttl</h1><dl class=\"snp-meta\">"
        . '<dt>Revisione</dt><dd>' . $rv['revid'] . ' · <a href="' . e(wiki_oldid_url($lang, $rv['revid'])) . '" rel="noopener noreferrer">' . e(wiki_oldid_url($lang, $rv['revid'])) . '</a></dd>'
        . '<dt>Data</dt><dd>' . e(ts_local($rv['ts'])) . ' (' . e($rv['ts']) . ' UTC)</dd>'
        . "<dt>Autore</dt><dd>$user</dd><dt>Commento</dt><dd>$comment</dd>"
        . '<dt>Dimensione</dt><dd>' . number_format($rv['size'], 0, ',', '.') . ' byte' . ($rv['minor'] ? ' · modifica minore' : '') . "</dd>"
        . "<dt>Etichette</dt><dd>$tags</dd>"
        . "<dt>Wikitesto sha1</dt><dd>$sha1Line</dd>"
        . '<dt>SHA-256</dt><dd><code>' . e($rv['sha256']) . '</code></dd></dl>'
        . '<p class="snp-note">Resa ricostruita da Wikipedia il ' . e(ts_local($ctx['acquired'])) . ' con i template e le immagini di quel momento: '
        . 'l\'aspetto può differire da com\'era il ' . e(ts_local($rv['ts'])) . '. Il testo sorgente esatto è in <a href="wikitext.txt">wikitext.txt</a>. '
        . 'I collegamenti verso pagine non archiviate sono disattivati: l\'indirizzo resta leggibile passandoci sopra.</p>'
        . "<nav class=\"snp-nav\">$nav</nav></header>"
        . '<main id="content" class="mw-body snp-content"><h1 id="firstHeading" class="firstHeading mw-first-heading">' . $ttl . '</h1>'
        . '<div id="bodyContent" class="vector-body"><div id="mw-content-text" class="mw-body-content">' . $content . '</div></div></main>'
        . '</body></html>';
}

/* ===================================================================== */

$t0 = (int)(microtime(true) * 1000);
$pdo = db();
$s = $pdo->prepare('SELECT * FROM snapshots WHERE short=?');
$s->execute([$short]);
$snap = $s->fetch();
// Ogni lettura va chiusa subito: un cursore lasciato aperto tiene un blocco di
// lettura su SQLite per tutta l'acquisizione, e nessun altro processo (nemmeno
// la registrazione dell'esito) riesce più a scrivere.
$s->closeCursor();
if (!$snap || ($snap['kind'] ?? '') !== 'wiki') {
    fwrite(STDERR, "prova $short inesistente o non di tipo wiki\n");
    exit(2);
}
$s = $pdo->prepare('SELECT j.revids, p.* FROM wiki_jobs j JOIN wiki_pages p ON p.id = j.wiki_page WHERE j.short=?');
$s->execute([$short]);
$job = $s->fetch();
$s->closeCursor();
unset($s);
if (!$job) {
    wk_finish_error('acquisizione senza elenco di revisioni');
}
$lang  = (string)$job['lang'];
$title = (string)$job['title'];
$wanted = array_values(array_unique(array_map('intval', json_decode((string)$job['revids'], true) ?: [])));
if (!$wanted || count($wanted) > WIKI_MAX_REVS || !wiki_valid_lang($lang)) {
    wk_finish_error('elenco di revisioni non valido');
}

$root = DATA_DIR . '/' . $short;
umask(022);
foreach (["$root/rev", "$root/assets/img"] as $d) {
    if (!is_dir($d) && !mkdir($d, 0755, true)) {
        wk_finish_error("impossibile creare $d");
    }
}
$pdo->prepare("UPDATE snapshots SET final_url=?, http_status=200, content_type='application/json (API MediaWiki)' WHERE short=?")
    ->execute([wiki_article_url($lang, $title), $short]);
wlog("START $lang:$title · " . count($wanted) . ' revisioni');

try {
    /* 1) metadati + wikitesto, verificati contro lo sha1 di Wikipedia */
    $revs = [];
    $warn = [];
    foreach (array_chunk($wanted, 20) as $chunk) {
        $j = wiki_api($lang, [
            'action' => 'query', 'prop' => 'revisions', 'revids' => implode('|', $chunk),
            'rvprop' => 'ids|timestamp|user|userid|size|comment|sha1|flags|tags|content', 'rvslots' => 'main',
        ]);
        foreach ($j['query']['pages'] ?? [] as $pg) {
            if ((int)($pg['pageid'] ?? 0) !== (int)$job['pageid']) {
                $warn['altra'] = 'revisioni di un\'altra voce scartate';
                continue;
            }
            foreach ($pg['revisions'] ?? [] as $raw) {
                $rv = wiki_norm_rev($raw);
                $content = $raw['slots']['main']['content'] ?? null;
                if (!is_string($content) || !empty($raw['slots']['main']['texthidden'])) {
                    $warn['oscurate'] = 'alcune revisioni hanno il testo oscurato da Wikipedia e non sono state archiviate';
                    continue;
                }
                $rv['content'] = $content;
                $rv['sha1_calc'] = sha1($content);
                $rv['sha1_ok'] = $rv['sha1'] !== null && hash_equals((string)$rv['sha1'], $rv['sha1_calc']);
                $rv['sha256'] = hash('sha256', $content);
                $revs[$rv['revid']] = $rv;
            }
        }
        if (!empty($j['query']['badrevids'])) {
            $warn['inesistenti'] = 'alcune revisioni richieste non esistono più';
        }
        usleep(150_000);
    }
    if (!$revs) {
        wk_finish_error('nessuna revisione disponibile: ' . (implode('; ', $warn) ?: 'Wikipedia non ha restituito contenuti'));
    }
    uasort($revs, fn($a, $b) => [$a['ts'], $a['revid']] <=> [$b['ts'], $b['revid']]);
    $order = array_values($revs);
    $acquired = gmdate('Y-m-d H:i:s');
    $ctx = ['lang' => $lang, 'title' => $title, 'short' => $short, 'n' => count($order), 'acquired' => $acquired];

    /* 2) resa di ogni revisione */
    $modules = ['site.styles' => true, 'skins.vector.styles' => true];
    $texts = [];
    foreach ($order as $i => $rv) {
        $id = $rv['revid'];
        $dir = "$root/rev/$id";
        @mkdir($dir, 0755, true);
        file_put_contents("$dir/wikitext.txt", $rv['content']);

        $pj = wiki_api($lang, [
            'action' => 'parse', 'oldid' => (string)$id,
            'prop' => 'text|modules|categories|sections|externallinks|images|templates|langlinks|displaytitle|revid',
            'disableeditsection' => '1', 'disablelimitreport' => '1',
        ]);
        $parse = $pj['parse'] ?? [];
        $html = (string)($parse['text'] ?? '');
        foreach ($parse['modulestyles'] ?? [] as $m) {
            if (preg_match('/^[A-Za-z0-9._-]+$/', (string)$m)) {
                $modules[(string)$m] = true;
            }
        }
        unset($parse['text']);
        file_put_contents("$dir/parse.json", json_encode($parse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        [$content, $css, $text] = wk_transform($html, $lang, $title, $root);
        file_put_contents("$dir/page.css", $css);
        $texts[$id] = $text;

        $rv['pos'] = $i + 1;
        $order[$i] = $rv;
        file_put_contents("$dir/index.html", wk_rev_page($rv, $ctx, $order[$i - 1] ?? null, $order[$i + 1] ?? null, $content));
        $meta = $rv;
        unset($meta['content'], $meta['pos']);
        $meta += ['lang' => $lang, 'page_title' => $title, 'pageid' => (int)$job['pageid'],
                  'oldid_url' => wiki_oldid_url($lang, $id), 'acquired_utc' => $acquired, 'user_agent' => WIKI_UA];
        file_put_contents("$dir/meta.json", json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        wlog("rev $id ok (" . strlen($rv['content']) . ' byte, sha1 ' . ($rv['sha1_ok'] ? 'ok' : 'NON coincide') . ')');
        usleep(150_000);
    }

    /* 3) stili di Wikipedia, una volta per tutta la prova */
    $mods = array_keys($modules);
    sort($mods);
    [$code, $cssBody] = wiki_http_get("https://$lang.wikipedia.org/w/load.php?" . http_build_query([
        'lang' => $lang, 'modules' => implode('|', $mods), 'only' => 'styles', 'skin' => 'vector',
    ], '', '&', PHP_QUERY_RFC3986), 10_000_000, 60);
    file_put_contents("$root/assets/wiki.css", $code === 200 ? wk_css($cssBody) : "/* stili non disponibili (HTTP $code) */\n");
    file_put_contents("$root/assets/snapper-wiki.css", WK_OWN_CSS);
    if ($IMG['skipped']) {
        $warn['img'] = $IMG['skipped'] . ' immagini non scaricate (fuori limite o non disponibili)';
    }

    /* 4) testo per la ricerca: la revisione più recente */
    $last = end($order);
    file_put_contents("$root/text.txt", "$title\n\n" . $texts[$last['revid']]);

    /* 5) manifesto + marca temporale (index.html escluso: ots-upgrade lo aggiorna) */
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if ($f->isFile() && !in_array($rel, ['index.html', 'SHA256SUMS', 'SHA256SUMS.ots', 'bundle.zip'], true)
            && !str_starts_with($rel, '.home/')) {
            $files[] = $rel;
        }
    }
    sort($files, SORT_STRING);
    $sums = '';
    foreach ($files as $rel) {
        $sums .= hash_file('sha256', "$root/$rel") . "  $rel\n";
    }
    file_put_contents("$root/SHA256SUMS", $sums);
    $manifestSha = hash('sha256', $sums);
    $otsStatus = 'none';
    $ots = trim((string)shell_exec('command -v ots 2>/dev/null'));
    if ($ots !== '') {
        @mkdir(DATA_DIR . '/.ots-cache', 0750, true);
        $cmd = 'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($ots) . ' --cache '
             . escapeshellarg(DATA_DIR . '/.ots-cache') . ' stamp SHA256SUMS >/dev/null 2>&1';
        exec($cmd, $o, $rc);
        $otsStatus = ($rc === 0 && is_file("$root/SHA256SUMS.ots")) ? 'stamped' : 'none';
        if ($otsStatus === 'none') {
            wlog('ots stamp fallito');
        }
    }

    /* 6) indice della prova */
    $rowsHtml = '';
    foreach (array_reverse($order) as $rv) {
        $rowsHtml .= '<tr><td class="n">' . e(ts_local($rv['ts'])) . '</td>'
            . '<td class="n"><a href="rev/' . $rv['revid'] . '/">' . $rv['revid'] . '</a></td>'
            . '<td>' . ($rv['user'] === null ? '<i>nascosto</i>' : e($rv['user'])) . '</td>'
            . '<td>' . ($rv['comment'] === null ? '<i>nascosto</i>' : e(mb_strimwidth($rv['comment'], 0, 160, '…'))) . '</td>'
            . '<td class="n">' . number_format($rv['size'], 0, ',', '.') . '</td>'
            . '<td class="n">' . ($rv['sha1_ok'] ? '<span class="snp-ok">✓</span>' : '<span class="snp-bad">✗</span>') . '</td>'
            . '<td class="n"><a href="rev/' . $rv['revid'] . '/wikitext.txt">wikitesto</a></td></tr>';
    }
    $okAll = count(array_filter($order, fn($r) => $r['sha1_ok'])) === count($order);
    $first = $order[0];
    $verify = "# Verifica indipendente della revisione {$first['revid']}:\n"
        . "sha1sum rev/{$first['revid']}/wikitext.txt\n"
        . "# deve coincidere con lo sha1 che Wikipedia stessa pubblica:\n"
        . "curl -s 'https://$lang.wikipedia.org/w/api.php?action=query&prop=revisions&revids={$first['revid']}&rvprop=sha1&format=json'\n"
        . "# Integrità di tutti i file della prova:\n"
        . "sha256sum -c SHA256SUMS\n"
        . ($otsStatus === 'stamped' ? "ots verify SHA256SUMS.ots\n" : '');
    $index = '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>Prova ' . e($short) . ' — ' . e($title) . ' (Wikipedia)</title>'
        . '<link rel="stylesheet" href="assets/snapper-wiki.css"></head><body class="snp-index"><div class="snp-card">'
        . '<div class="snp-kicker">Snapper · prova <span class="snp-code">' . e($short) . '</span> · Wikipedia</div>'
        . '<h1>' . e($title) . ' · ' . count($order) . ' ' . (count($order) === 1 ? 'revisione' : 'revisioni') . '</h1>'
        . '<dl class="snp-meta">'
        . '<dt>Voce</dt><dd><a href="' . e(wiki_article_url($lang, $title)) . '" rel="noopener noreferrer">' . e(wiki_article_url($lang, $title)) . '</a> · pageid ' . (int)$job['pageid'] . '</dd>'
        . '<dt>Acquisita</dt><dd>' . e(ts_local($acquired)) . ' (' . e($acquired) . ' UTC)</dd>'
        . '<dt>Provenienza</dt><dd>' . ($okAll ? '<span class="snp-ok">✓ ogni wikitesto coincide con lo sha1 pubblicato da Wikipedia</span>'
                                                 : '<span class="snp-bad">✗ almeno un wikitesto non coincide: vedi la tabella</span>') . '</dd>'
        . '<dt>Manifesto</dt><dd>SHA256SUMS · ' . count($files) . ' file · sha256 <code>' . $manifestSha . '</code></dd>'
        . '<dt>Timestamp</dt><dd>OpenTimestamps: ' . $otsStatus . '</dd>'
        . ($warn ? '<dt>Avvisi</dt><dd>' . e(implode('; ', $warn)) . '</dd>' : '')
        . '</dl><div class="snp-scroll"><table class="snp-tbl"><thead><tr><th>Data</th><th>Revisione</th><th>Autore</th><th>Commento</th><th>Byte</th><th>sha1</th><th></th></tr></thead><tbody>'
        . $rowsHtml . '</tbody></table></div>'
        . '<p>La resa grafica è ricostruita da Wikipedia al momento dell\'acquisizione, con i template e le immagini di quel momento; '
        . 'il wikitesto di ogni revisione è esatto e verificabile in modo indipendente:</p>'
        . '<div class="snp-verify">' . e($verify) . '</div>'
        . '<div class="snp-chips"><a href="SHA256SUMS">SHA256SUMS</a>'
        . ($otsStatus === 'stamped' ? '<a href="SHA256SUMS.ots">SHA256SUMS.ots</a>' : '')
        . '<a href="bundle.zip">Bundle ZIP</a><a href="text.txt">Testo</a>'
        . '<a href="/snapper/wiki.php?page=' . (int)$job['id'] . '">Dossier della voce</a></div>'
        . '</div></body></html>';
    file_put_contents("$root/index.html", $index);

    /* 7) ZIP */
    $zip = new ZipArchive();
    if ($zip->open("$root/bundle.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        foreach (array_merge($files, ['index.html', 'SHA256SUMS'], $otsStatus === 'stamped' ? ['SHA256SUMS.ots'] : []) as $rel) {
            $zip->addFile("$root/$rel", $rel);
        }
        $zip->close();
    } else {
        wlog('zip non creato');
    }

    /* 8) permalink */
    $link = ARCH_DIR . '/' . $short;
    if (is_link($link)) {
        @unlink($link);
    }
    if (!@symlink($root, $link)) {
        wlog('symlink non creato');
    }

    /* 9) revisioni nel dossier della voce */
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO wiki_revisions(wiki_page, revid, parentid, ts, user, anon, comment, size,
                          sha1, sha1_ok, sha256_wikitext, minor, tags, short) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($order as $rv) {
        $ins->execute([(int)$job['id'], $rv['revid'], $rv['parentid'], $rv['ts'], $rv['user'], (int)$rv['anon'],
            $rv['comment'], $rv['size'], $rv['sha1'], (int)$rv['sha1_ok'], $rv['sha256'], (int)$rv['minor'],
            json_encode($rv['tags']), $short]);
    }
    $pdo->prepare('UPDATE wiki_pages SET updated=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$job['id']]);
    $pdo->commit();

    /* 10) prova conclusa (stesso canale del worker: FTS e confinamento di body_file) */
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        $size += $f->isFile() ? $f->getSize() : 0;
    }
    if (!$okAll) {
        $warn['sha1'] = 'il wikitesto di almeno una revisione non coincide con lo sha1 di Wikipedia';
    }
    $payload = json_encode([
        'title' => '', 'size_bytes' => $size, 'sha256' => $manifestSha,
        'capture_ms' => (int)(microtime(true) * 1000) - $t0, 'ots_status' => $otsStatus,
        'body_file' => "$root/text.txt", 'diff_pct' => '', 'warn' => implode('; ', $warn),
    ]);
    $proc = proc_open([PHP_BINARY, __DIR__ . '/worker-db.php', 'ready', $short], [0 => ['pipe', 'r']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('impossibile registrare l\'esito');
    }
    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    if (proc_close($proc) !== 0) {
        throw new RuntimeException('registrazione dell\'esito fallita');
    }
    wlog('DONE ready · ' . count($order) . ' revisioni · ' . $IMG['count'] . ' immagini · ' . round($size / 1048576, 1) . ' MB');
} catch (Throwable $ex) {
    wk_finish_error($ex->getMessage());
}
wk_drain();
exit(0);
