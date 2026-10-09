<?php
declare(strict_types=1);

/* =========================================================================
 * Snapper – crawler di siti interi.  SOLO da CLI.
 *
 *   php crawl-worker.php capture  <short>   cattura (prova di tipo 'site'),
 *                                           avviata da worker.sh
 *   php crawl-worker.php estimate <id>      stima a vuoto (site_estimates):
 *                                           legge solo le pagine, conta le risorse
 *
 * Il risultato di una cattura:
 *   site/<host>/...          albero navigabile senza rete (collegamenti riscritti)
 *   warc/<short>.warc.gz     ogni scambio HTTP così com'è passato in rete
 *   warc/<short>.cdxj        indice del WARC
 *   indice-sito.html, pagine.json   ogni risorsa con stato, tipo, dimensione, impronta
 *   SHA256SUMS(.ots), index.html, bundle.zip, text.txt
 * Le richieste passano tutte dalla barriera di crawllib.php.
 * =======================================================================*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
ini_set('memory_limit', '2048M');
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/crawllib.php';

$mode = (string)($argv[1] ?? '');
$arg  = (string)($argv[2] ?? '');
$TAG  = $arg;

function clog(string $m): void
{
    global $TAG;
    $now = new DateTime('now', new DateTimeZone('Europe/Rome'));
    fwrite(STDERR, sprintf("[%s] SITE %s :: %s\n", $now->format('Y-m-d H:i:s'), $TAG, $m));
}

function e2(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Il giro di raccolta, comune a stima e cattura.
 * @param ?string $root  cartella della prova (null in stima: non si salva nulla)
 */
function crawl_run(string $start, array $o, ?string $root, ?CrawlWarc $warc, callable $tick, callable $cancelled, bool $estimate): array
{
    $self = crawl_self_ips();
    $t0 = time();
    $deadline = $t0 + ($estimate ? min($o['minutes'], 5) : $o['minutes']) * 60;
    $maxBytes = $o['mb'] * 1048576;
    $fileMax = min(500 * 1048576, $maxBytes);
    $qReq = new SplQueue();
    $qNav = new SplQueue();
    $qNav->enqueue([$start, 0, 'nav']);
    $seen = [$start => true];
    $inScope = [$start => true];
    $res = [];          // url finale => scheda della risorsa
    $map = [];          // url (anche di partenza di un redirect) => percorso locale
    $used = [];         // percorso locale => url (per evitare collisioni)
    $skip = [];         // motivo => [conteggio, esempi]
    $blocked = [];
    $extHosts = [];
    $robots = [];
    $qvar = [];
    $st = ['pages' => 0, 'files' => 0, 'bytes' => 0, 'errors' => 0, 'requests' => 0, 'stop' => null,
           'est_assets' => [], 'est_sample' => [], 'html_bytes' => 0];
    $note = function (string $why, string $url) use (&$skip) {
        $skip[$why][0] = ($skip[$why][0] ?? 0) + 1;
        if (count($skip[$why][1] ?? []) < 8) $skip[$why][1][] = $url;
    };
    $lastReq = 0.0;
    $polite = function () use (&$lastReq, $o) {
        if ($lastReq > 0 && $o['delay'] > 0) {
            $wait = $o['delay'] * (0.5 + mt_rand() / mt_getrandmax()) / 1000 - (microtime(true) - $lastReq);
            if ($wait > 0) usleep((int)($wait * 1e6));
        }
        $lastReq = microtime(true);
    };
    $lastTick = 0;
    $sameSite = function (string $u) use (&$start, $o): bool {
        $h = crawl_host($u);
        $sh = crawl_host($start);
        $base = preg_replace('/^www\./', '', $sh);
        return $h === $sh || ($o['scope'] === 'subdomains' && ($h === $base || str_ends_with($h, '.' . $base)));
    };

    while (!$qReq->isEmpty() || !$qNav->isEmpty()) {
        if ($cancelled()) { $st['stop'] = 'annullata dall\'utente'; break; }
        if (time() > $deadline) { $st['stop'] = 'tempo massimo raggiunto'; break; }
        if ($st['bytes'] >= $maxBytes) { $st['stop'] = 'dimensione massima raggiunta'; break; }
        if (time() - $lastTick >= 2) {
            $tick($st + ['queue' => $qReq->count() + $qNav->count(), 'current' => null]);
            $lastTick = time();
        }
        $isReq = !$qReq->isEmpty();
        [$url, $depth, $kind] = $isReq ? $qReq->dequeue() : $qNav->dequeue();
        $isNav = $kind === 'nav';
        $etype = crawl_type_ext($url);
        if ($isNav && $etype === 'html' && $st['pages'] >= $o['pages']) {
            $note('limite di pagine', $url);
            continue;
        }
        if ($etype !== 'html' && $etype !== 'other' && !in_array($etype, $o['types'], true)) {
            $note('tipo escluso', $url);
            continue;
        }
        // robots.txt, una volta per origine (e anch'esso attraverso la barriera)
        if ($o['robots']) {
            $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $url);
            if (!isset($robots[$origin])) {
                $polite();
                $r = crawl_fetch("$origin/robots.txt", $self, $o, 512 * 1024);
                $st['requests']++;
                $robots[$origin] = ($r['status'] === 200 && !$r['blocked']) ? crawl_robots_parse(crawl_decode_body($r['body'], $r['raw_headers'])) : [];
            }
            $pq = (string)(parse_url($url, PHP_URL_PATH) ?? '/') . (($q = parse_url($url, PHP_URL_QUERY)) ? "?$q" : '');
            if (!crawl_robots_allowed($robots[$origin], $pq)) {
                $note('vietato da robots.txt', $url);
                continue;
            }
        }
        // in stima le risorse si contano, non si scaricano (salvo un campione per la dimensione)
        if ($estimate && !($isNav && $etype === 'html')) {
            $st['est_assets'][$etype] = ($st['est_assets'][$etype] ?? 0) + 1;
            if (count($st['est_sample'][$etype] ?? []) < 15) {
                $polite();
                $h = crawl_fetch($url, $self, $o, 1, null, 'HEAD');
                $st['requests']++;
                if (!$h['blocked'] && preg_match('/^content-length:\s*(\d+)/mi', $h['raw_headers'], $m)) {
                    $st['est_sample'][$etype][] = (int)$m[1];
                }
            }
            continue;
        }

        $polite();
        $types = $o['types'];
        $r = crawl_fetch($url, $self, $o, $estimate ? 10 * 1048576 : $fileMax, function (string $ct) use ($types, $isNav) {
            if ($ct === '') return true;
            $t = crawl_type_ctype($ct);
            return in_array($t, $types, true) || $t === 'other' || ($isNav && $t === 'html');
        });
        $st['requests']++;
        if ($warc) {
            foreach ($r['exchanges'] as $ex) $warc->exchange($ex);
        }
        if ($r['blocked']) {
            if (count($blocked) < 200) $blocked[] = [$url, $r['blocked'], $r['final']];
            $note('bloccata per sicurezza', $r['final']);
            continue;
        }
        if ($r['error']) {
            $st['errors']++;
            $res[$url] = ['url' => $url, 'status' => 0, 'error' => $r['error'], 'type' => $etype, 'depth' => $depth, 'kind' => $kind];
            continue;
        }
        $final = $r['final'];
        if ($final !== $url && $url === $start && $depth === 0) {
            // la partenza stessa fa redirect (http→https, /docs→/docs/): l'albero è quello di arrivo
            clog("la partenza rimanda a $final: ambito ricalcolato da lì");
            $start = $final;
            $inScope[$final] = true;
        }
        if ($final !== $url) {
            if ($isNav && crawl_scope($final, $start, $o) !== null) {
                $res[$url] = ['url' => $url, 'status' => $r['redirects'][0]['status'] ?? 301, 'error' => 'redirect fuori ambito: ' . $final,
                              'type' => $etype, 'depth' => $depth, 'kind' => $kind];
                $note('redirect fuori ambito', $url);
                continue;
            }
            if (isset($seen[$final]) && isset($map[$final])) {
                $map[$url] = $map[$final];
                continue;
            }
            $seen[$final] = true;
            if ($isNav) $inScope[$final] = true;
        }
        $st['bytes'] += strlen($r['body']);
        if (!empty($r['skipped'])) {
            $note('tipo escluso', $final);
            continue;
        }
        $ctype = $r['ctype'];
        $type = $ctype !== '' ? crawl_type_ctype($ctype) : $etype;
        if ($type === 'other' && !in_array('other', $types, true) && !in_array($etype, $types, true)) {
            $note('tipo escluso', $final);
            continue;
        }
        $rec = ['url' => $final, 'status' => $r['status'], 'ctype' => $ctype, 'type' => $type, 'depth' => $depth, 'kind' => $kind,
                'size' => strlen($r['body']), 'truncated' => $r['truncated']];
        if ($r['status'] >= 400 || $r['status'] < 200) {
            $rec['error'] = 'HTTP ' . $r['status'];
            $res[$final] = $rec;
            if ($final !== $url) $res[$url] ??= $rec;
            continue;
        }
        $body = crawl_decode_body($r['body'], $r['raw_headers']);
        $charset = crawl_charset($ctype);
        if ($type === 'html') {
            $st['pages']++;
            $st['html_bytes'] += strlen($r['body']);
            $ex = crawl_extract($body, $final, $o, $charset);
            $rec['title'] = $ex['title'];
            $rec['text'] = mb_substr($ex['text'], 0, 200_000);
            $rec['charset'] = $charset;
            foreach ($ex['nav'] as $n) {
                if (isset($seen[$n])) continue;
                if (!$sameSite($n)) {
                    $extHosts[crawl_host($n)] = ($extHosts[crawl_host($n)] ?? 0) + 1;
                }
                $why = crawl_scope($n, $start, $o);
                if ($why !== null) { $seen[$n] = true; $note($why, $n); continue; }
                $inScope[$n] = true;
                if ($depth + 1 > $o['depth']) { $note('oltre la profondità', $n); continue; }
                if ($o['traps'] && ($t = crawl_trap($n)) !== null) { $seen[$n] = true; $note('trappola: ' . $t, $n); continue; }
                if (parse_url($n, PHP_URL_QUERY)) {
                    $pk = preg_replace('/\?.*$/', '', $n);
                    if (($qvar[$pk] = ($qvar[$pk] ?? 0) + 1) > 25) { $seen[$n] = true; $note('troppe varianti della stessa pagina', $n); continue; }
                }
                $seen[$n] = true;
                $qNav->enqueue([$n, $depth + 1, 'nav']);
            }
            foreach ($ex['req'] as $q) {
                if (isset($seen[$q])) continue;
                $seen[$q] = true;
                if (!$sameSite($q)) {
                    $extHosts[crawl_host($q)] = ($extHosts[crawl_host($q)] ?? 0) + 1;
                    if ($o['external'] === 'none') { $note('risorsa esterna', $q); continue; }
                }
                $qReq->enqueue([$q, $depth, 'req']);
            }
        } elseif ($type === 'css') {
            foreach (crawl_css_refs($body) as $ref) {
                $q = crawl_norm($ref, $final, $o['drop']);
                if ($q === null || isset($seen[$q])) continue;
                $seen[$q] = true;
                if (!$sameSite($q) && $o['external'] === 'none') { $note('risorsa esterna', $q); continue; }
                $qReq->enqueue([$q, $depth, 'req']);
            }
        }
        if ($root !== null) {
            $local = crawl_local($final, $type, $ctype);
            if (isset($used[$local]) && $used[$local] !== $final) {
                $local = preg_replace('/(\.[A-Za-z0-9]+)$/', '__' . substr(sha1($final), 0, 8) . '$1', $local);
            }
            $used[$local] = $final;
            $abs = "$root/$local";
            if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) {
                // un file con lo stesso nome di una cartella: si ripiega su un nome a impronta
                $local = 'site/_altro/' . substr(sha1($final), 0, 16) . '.' . pathinfo($local, PATHINFO_EXTENSION);
                @mkdir("$root/site/_altro", 0755, true);
                $abs = "$root/$local";
            }
            if (is_dir($abs)) {
                $local .= '__' . substr(sha1($final), 0, 8) . '.' . ($type === 'html' ? 'html' : 'bin');
                $abs = "$root/$local";
            }
            file_put_contents($abs, $body);
            $rec['local'] = $local;
            $rec['sha256'] = hash('sha256', $body);
            $map[$final] = $local;
            if ($final !== $url) $map[$url] = $local;
            $st['files']++;
        }
        $res[$final] = $rec;
    }
    if ($st['stop'] !== null) {
        foreach ([$qReq, $qNav] as $q) {
            while (!$q->isEmpty()) {
                [$u] = $q->dequeue();
                $note('non scaricata per il limite', $u);
            }
        }
    }
    arsort($extHosts);
    return ['start' => $start, 'res' => $res, 'map' => $map, 'inscope' => $inScope, 'skip' => $skip, 'blocked' => $blocked,
            'ext' => array_slice($extHosts, 0, 30, true), 'st' => $st + ['elapsed' => time() - $t0]];
}

/* ===================================================================== */
/* ---- Stima ---------------------------------------------------------- */

if ($mode === 'estimate') {
    $id = (int)$arg;
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM site_estimates WHERE id=?');
    $s->execute([$id]);
    $est = $s->fetch();
    $s->closeCursor();
    if (!$est) exit(2);
    $o = crawl_options(json_decode((string)$est['options'], true) ?: []);
    $start = (string)$est['url'];
    clog("STIMA $start");
    try {
        $out = crawl_run($start, $o, null, null, function (array $p) use ($pdo, $id) {
            $pdo->prepare("UPDATE site_estimates SET result=? WHERE id=? AND status='running'")
                ->execute([json_encode(['progress' => $p['pages'], 'requests' => $p['requests']]), $id]);
        }, fn() => false, true);
        $st = $out['st'];
        $avg = [];
        $estBytes = $st['html_bytes'];
        foreach ($st['est_assets'] as $t => $n) {
            $smp = $st['est_sample'][$t] ?? [];
            $avg[$t] = $smp ? (int)(array_sum($smp) / count($smp)) : null;
            $estBytes += $n * ($avg[$t] ?? 50_000);
        }
        // directory più popolose, fra le pagine lette
        $dirs = [];
        foreach ($out['res'] as $r) {
            if (($r['type'] ?? '') !== 'html') continue;
            $p = (string)parse_url($r['url'], PHP_URL_PATH);
            $seg = array_values(array_filter(explode('/', $p), 'strlen'));
            $k = '/' . implode('/', array_slice($seg, 0, min(2, max(0, count($seg) - 1))));
            $dirs[$k] = ($dirs[$k] ?? 0) + 1;
        }
        arsort($dirs);
        $traps = array_filter($out['skip'], fn($k) => str_starts_with($k, 'trappola') || $k === 'troppe varianti della stessa pagina', ARRAY_FILTER_USE_KEY);
        $result = [
            'pages' => $st['pages'], 'more' => $st['stop'] !== null || ($out['skip']['limite di pagine'][0] ?? 0) > 0,
            'stop' => $st['stop'], 'assets' => $st['est_assets'], 'avg' => $avg, 'bytes' => $estBytes,
            'dirs' => array_slice($dirs, 0, 12, true), 'traps' => $traps, 'skip' => $out['skip'],
            'blocked' => array_slice($out['blocked'], 0, 20), 'ext' => $out['ext'], 'errors' => $st['errors'],
            'elapsed' => $st['elapsed'], 'requests' => $st['requests'],
        ];
        $pdo->prepare("UPDATE site_estimates SET status='done', result=?, finished=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
        clog("STIMA conclusa: {$st['pages']} pagine, ~" . round($estBytes / 1048576) . ' MB');
    } catch (Throwable $ex) {
        $pdo->prepare("UPDATE site_estimates SET status='error', result=?, finished=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([json_encode(['error' => $ex->getMessage()]), $id]);
        clog('STIMA fallita: ' . $ex->getMessage());
    }
    exit(0);
}

/* ===================================================================== */
/* ---- Cattura -------------------------------------------------------- */

if ($mode !== 'capture' || !preg_match('/^[A-Za-z0-9]{5,12}$/', $arg)) {
    fwrite(STDERR, "uso: crawl-worker.php capture <short> | estimate <id>\n");
    exit(2);
}
$short = $arg;
$t0ms = (int)(microtime(true) * 1000);
$pdo = db();
$s = $pdo->prepare("SELECT s.*, j.options FROM snapshots s JOIN site_jobs j ON j.short = s.short WHERE s.short=? AND s.kind='site'");
$s->execute([$short]);
$job = $s->fetch();
$s->closeCursor();
if (!$job) {
    fwrite(STDERR, "prova $short inesistente o non di tipo site\n");
    exit(2);
}

function crawl_fail(string $msg): never
{
    global $short;
    clog("ERROR $msg");
    db()->prepare("UPDATE snapshots SET status='error', status_msg=?, done_at=CURRENT_TIMESTAMP WHERE short=?")
        ->execute([mb_substr($msg, 0, 500), $short]);
    @shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/drain.php') . ' >/dev/null 2>&1');
    exit(1);
}

$o = crawl_options(json_decode((string)$job['options'], true) ?: []);
$start = crawl_norm((string)$job['url']) ?? crawl_fail('indirizzo di partenza non valido');
$root = DATA_DIR . '/' . $short;
umask(022);
foreach (["$root/site", "$root/warc", "$root/_snapper"] as $d) {
    if (!is_dir($d) && !@mkdir($d, 0755, true)) crawl_fail("impossibile creare $d");
}
clog("START $start · ambito {$o['scope']}, profondità {$o['depth']}, max {$o['pages']} pagine / {$o['mb']} MB / {$o['minutes']} min");
$prog = "$root/.progress.json";
$cancelFile = "$root/.cancel";
$writeProg = function (array $p, string $phase = 'raccolta') use ($prog) {
    @file_put_contents("$prog.tmp", json_encode($p + ['phase' => $phase, 'at' => time()]));
    @rename("$prog.tmp", $prog);
};

try {
    $warc = new CrawlWarc("$root/warc/$short.warc.gz", "$root/warc/$short.cdxj",
        "software: Snapper (crawler di siti)\r\nformat: WARC File Format 1.1\r\nconformsTo: https://iipc.github.io/warc-specifications/specifications/warc-format/warc-1.1/\r\n"
        . "isPartOf: $short\r\ndescription: " . str_replace(["\r", "\n"], ' ', $start) . "\r\nrobots: " . ($o['robots'] ? 'obey' : 'ignore') . "\r\n");
    $out = crawl_run($start, $o, $root, $warc, fn(array $p) => $writeProg($p), fn() => is_file($cancelFile), false);
    $warc->close("$root/warc/$short.cdxj");
    $res = $out['res'];
    $map = $out['map'];
    $st = $out['st'];
    $start = $out['start'];
    if ($st['pages'] === 0) {
        $first = $res[$start] ?? reset($res) ?: [];
        crawl_fail('nessuna pagina scaricata' . (!empty($first['error']) ? ': ' . $first['error'] : ($out['blocked'] ? ': ' . $out['blocked'][0][1] : '')));
    }

    /* riscrittura: ogni pagina e foglio di stile punta alle copie locali */
    $writeProg($st + ['queue' => 0, 'current' => null], 'riscrittura');
    clog('riscrittura dei collegamenti');
    foreach ($res as $u => $r) {
        if (empty($r['local'])) continue;
        $abs = "$root/{$r['local']}";
        if ($r['type'] === 'html') {
            $cssLocal = preg_replace('/\.html?$/', '', $r['local']) . '.snapper.css';
            try {
                [$html, $css] = crawl_rewrite_html((string)file_get_contents($abs), $u, $r['local'], $map, $out['inscope'], $o, $cssLocal, $r['charset'] ?? '');
                file_put_contents($abs, $html);
                file_put_contents("$root/$cssLocal", $css);
            } catch (Throwable $ex) {
                clog("pagina non riscritta ($u): " . $ex->getMessage());
            }
        } elseif ($r['type'] === 'css') {
            file_put_contents($abs, crawl_rewrite_css((string)file_get_contents($abs), $u, $r['local'], $map, $o));
        }
    }
    file_put_contents("$root/_snapper/inerti.css",
        "a.snp-na,a.snp-ext{text-decoration:underline dotted;cursor:help}\na.snp-ext::after{content:\"\\2197\";font-size:.75em;margin-left:.1em;opacity:.7}\n"
        . "img.snp-noimg{background:repeating-linear-gradient(45deg,#e5e5e5 0 6px,#f5f5f5 6px 12px);min-width:16px;min-height:16px}\n");

    /* indice del sito, testo per la ricerca, righe nel database */
    $host = crawl_host($start);
    $pages = array_filter($res, fn($r) => $r['type'] === 'html' && empty($r['error']));
    $startLocal = $map[$start] ?? (reset($pages)['local'] ?? '');
    $byType = [];
    foreach ($res as $r) {
        if (!empty($r['error'])) continue;
        $byType[$r['type']] = [($byType[$r['type']][0] ?? 0) + 1, ($byType[$r['type']][1] ?? 0) + $r['size']];
    }
    $broken = array_filter($res, fn($r) => !empty($r['error']));
    $index = [];
    foreach ($res as $r) {
        $index[] = array_intersect_key($r, array_flip(['url', 'status', 'type', 'ctype', 'size', 'sha256', 'local', 'depth', 'title', 'error']));
    }
    file_put_contents("$root/pagine.json", json_encode(['start' => $start, 'options' => $o, 'resources' => $index,
        'skipped' => array_map(fn($x) => ['count' => $x[0], 'examples' => $x[1]], $out['skip']), 'blocked' => $out['blocked']],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    $txt = '';
    foreach ($pages as $u => $r) {
        if (strlen($txt) > 5_000_000) break;
        $txt .= "== $u\n" . ($r['title'] ?? '') . "\n" . ($r['text'] ?? '') . "\n\n";
    }
    file_put_contents("$root/text.txt", $txt);

    $rows = '';
    foreach ($pages as $u => $r) {
        $rows .= '<tr><td><a href="' . e2(crawl_rel('indice-sito.html', $r['local'])) . '">' . e2($r['title'] ?: $u) . '</a><br><span class="u">' . e2($u) . '</span></td>'
            . '<td class="n">' . (int)$r['status'] . '</td><td class="n">' . (int)$r['depth'] . '</td><td class="n">' . number_format((int)$r['size'], 0, ',', '.') . '</td></tr>';
    }
    $brows = '';
    foreach (array_slice($broken, 0, 500) as $u => $r) {
        $brows .= '<tr><td class="u">' . e2($u) . '</td><td>' . e2($r['error']) . '</td></tr>';
    }
    $srows = '';
    foreach ($out['skip'] as $why => [$n, $ex]) {
        $srows .= '<tr><td>' . e2($why) . '</td><td class="n">' . $n . '</td><td class="u">' . e2(implode("\n", array_slice($ex, 0, 3))) . '</td></tr>';
    }
    $blrows = '';
    foreach ($out['blocked'] as [$u, $why, $fin]) {
        $blrows .= '<tr><td class="u">' . e2($u) . ($fin !== $u ? '<br>→ ' . e2($fin) : '') . '</td><td>' . e2($why) . '</td></tr>';
    }
    $css = ':root{--paper:#f2ecdd;--ink:#1c1a15;--muted:#6f6857;--line:#c8bd9f;--red:#c8402f;--ok:#3f6b33}'
        . '*{box-sizing:border-box}body{margin:0;background:#17150f;color:var(--paper);font:14.5px/1.55 ui-sans-serif,-apple-system,"Segoe UI",Roboto,sans-serif;padding:2rem clamp(1rem,4vw,3rem)}'
        . '.card{max-width:76rem;margin:0 auto;background:var(--paper);color:var(--ink);border:1px solid var(--line);padding:1.4rem clamp(1rem,3vw,1.8rem) 2rem}'
        . '.k{font:700 10.5px/1 ui-monospace,Menlo,monospace;letter-spacing:.2em;text-transform:uppercase;color:var(--red)}.k .c{text-transform:none;letter-spacing:.04em}'
        . 'h1{font-size:1.3rem;margin:.4rem 0 1rem}h2{font-size:1.02rem;margin:1.6rem 0 .5rem}a{color:#7a5a12}'
        . 'dl{display:grid;grid-template-columns:max-content 1fr;gap:.3rem .9rem;font:12.5px/1.5 ui-monospace,Menlo,monospace;margin:0}dt{color:var(--muted);text-transform:uppercase;font-size:10.5px;letter-spacing:.08em}dd{margin:0;overflow-wrap:anywhere}'
        . '.scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:13px;min-width:40rem}th,td{border-bottom:1px solid var(--line);padding:.4rem .5rem;text-align:left;vertical-align:top}'
        . 'th{font:700 10.5px ui-monospace,Menlo,monospace;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}td.n{font-family:ui-monospace,Menlo,monospace;white-space:nowrap;text-align:right}'
        . '.u{font:11.5px ui-monospace,Menlo,monospace;color:var(--muted);overflow-wrap:anywhere;white-space:pre-line}'
        . '.chips{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1rem}.chips a{border:1px solid var(--line);padding:.45rem .7rem;text-decoration:none;font:600 11.5px/1 ui-sans-serif,sans-serif;text-transform:uppercase;letter-spacing:.06em;color:var(--ink)}'
        . '.verify{font:12px/1.55 ui-monospace,Menlo,monospace;background:#e6dbc2;border:1px solid var(--line);padding:.7rem .9rem;overflow-x:auto;white-space:pre}.warn{color:#a52a1d}';
    file_put_contents("$root/_snapper/indice.css", $css);
    $head = fn(string $t) => '<!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . e2($t) . '</title><link rel="stylesheet" href="_snapper/indice.css"></head><body><div class="card"><div class="k">Snapper · sito · prova <span class="c">' . e2($short) . '</span></div>';
    file_put_contents("$root/indice-sito.html", $head("Indice del sito · $host")
        . '<h1>' . e2($host) . ' · ' . count($pages) . ' pagine</h1><p><a href="index.html">← scheda della prova</a></p>'
        . '<h2>Pagine</h2><div class="scroll"><table><thead><tr><th>Pagina</th><th>HTTP</th><th>Livello</th><th>Byte</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . ($brows ? '<h2>Non scaricate per errore (' . count($broken) . ')</h2><div class="scroll"><table><thead><tr><th>Indirizzo</th><th>Motivo</th></tr></thead><tbody>' . $brows . '</tbody></table></div>' : '')
        . ($blrows ? '<h2>Bloccate per sicurezza</h2><div class="scroll"><table><thead><tr><th>Indirizzo</th><th>Motivo</th></tr></thead><tbody>' . $blrows . '</tbody></table></div>' : '')
        . '<h2>Escluse dai filtri e dai limiti</h2><div class="scroll"><table><thead><tr><th>Motivo</th><th>Quante</th><th>Esempi</th></tr></thead><tbody>' . $srows . '</tbody></table></div>'
        . '</div></body></html>');

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM site_pages WHERE short=?')->execute([$short]);
    $pdo->prepare('DELETE FROM site_pages_fts WHERE short=?')->execute([$short]);
    $ins = $pdo->prepare('INSERT INTO site_pages(short, url, local, type, status, ctype, size, sha256, depth, title, note) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $fts = $pdo->prepare('INSERT INTO site_pages_fts(short, url, title, body) VALUES(?,?,?,?)');
    foreach ($res as $u => $r) {
        $ins->execute([$short, $u, $r['local'] ?? null, $r['type'], (int)$r['status'], $r['ctype'] ?? null, (int)($r['size'] ?? 0),
                       $r['sha256'] ?? null, (int)$r['depth'], $r['title'] ?? null, $r['error'] ?? null]);
        if ($r['type'] === 'html' && empty($r['error'])) {
            $fts->execute([$short, $u, $r['title'] ?? '', $r['text'] ?? '']);
        }
    }
    $pdo->commit();

    /* manifesto, marca, scheda, ZIP, permalink */
    $writeProg($st + ['queue' => 0, 'current' => null], 'sigillo');
    $files = [];
    $total = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if ($f->isFile() && !in_array($rel, ['index.html', 'SHA256SUMS', 'SHA256SUMS.ots', 'bundle.zip', '.progress.json', '.cancel'], true)) {
            $files[] = $rel;
            $total += $f->getSize();
        }
    }
    sort($files, SORT_STRING);
    $sums = '';
    foreach ($files as $rel) $sums .= hash_file('sha256', "$root/$rel") . "  $rel\n";
    file_put_contents("$root/SHA256SUMS", $sums);
    $manifest = hash('sha256', $sums);
    $ots = 'none';
    if (($otsBin = trim((string)shell_exec('command -v ots 2>/dev/null'))) !== '') {
        @mkdir(DATA_DIR . '/.ots-cache', 0750, true);
        exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($otsBin) . ' --cache ' . escapeshellarg(DATA_DIR . '/.ots-cache') . ' stamp SHA256SUMS >/dev/null 2>&1', $oo, $rc);
        $ots = ($rc === 0 && is_file("$root/SHA256SUMS.ots")) ? 'stamped' : 'none';
    }
    $warn = [];
    if ($st['stop']) $warn[] = 'interrotta: ' . $st['stop'];
    if ($out['blocked']) $warn[] = count($out['blocked']) . ' richieste bloccate per sicurezza';
    if ($st['errors']) $warn[] = $st['errors'] . ' risorse in errore';
    $zipOk = $total <= 2 * 1024 ** 3;
    $typeRows = '';
    foreach ($byType as $t => [$n, $b]) $typeRows .= '<tr><td>' . e2($t) . '</td><td class="n">' . $n . '</td><td class="n">' . number_format($b, 0, ',', '.') . '</td></tr>';
    $optLine = 'ambito ' . $o['scope'] . ' · profondità ' . $o['depth'] . ' · max ' . $o['pages'] . ' pagine, ' . $o['mb'] . ' MB, ' . $o['minutes'] . ' min · tipi ' . implode(', ', $o['types'])
        . ' · risorse esterne: ' . ($o['external'] === 'none' ? 'no' : 'sì') . ' · robots.txt ' . ($o['robots'] ? 'rispettato' : 'ignorato') . ' · pausa ' . $o['delay'] . ' ms';
    $verify = "sha256sum -c SHA256SUMS\n" . ($ots === 'stamped' ? "ots verify SHA256SUMS.ots\n" : '')
        . "# Il WARC contiene ogni risposta così com'è arrivata; si riapre con strumenti indipendenti\n# (es. ReplayWeb.page, pywb). L'albero in site/ è la copia navigabile, con i collegamenti riscritti.\n";
    file_put_contents("$root/index.html", $head("Prova $short — $host (sito)")
        . '<h1>' . e2($host) . ' · ' . count($pages) . ' pagine</h1><dl>'
        . '<dt>Partenza</dt><dd><a href="' . e2($start) . '" rel="noopener noreferrer">' . e2($start) . '</a></dd>'
        . '<dt>Scaricato</dt><dd>' . e2(date('d/m/Y H:i')) . ' · ' . round($st['elapsed'] / 60, 1) . ' min · ' . $st['requests'] . ' richieste</dd>'
        . '<dt>Opzioni</dt><dd>' . e2($optLine) . '</dd>'
        . '<dt>Manifesto</dt><dd>SHA256SUMS · ' . count($files) . ' file · sha256 <code>' . $manifest . '</code></dd>'
        . '<dt>Timestamp</dt><dd>OpenTimestamps: ' . $ots . '</dd>'
        . ($warn ? '<dt>Avvisi</dt><dd class="warn">' . e2(implode('; ', $warn)) . '</dd>' : '') . '</dl>'
        . '<div class="chips">' . ($startLocal !== '' ? '<a href="' . e2(crawl_rel('index.html', $startLocal)) . '">Apri il sito</a>' : '')
        . '<a href="indice-sito.html">Indice delle pagine</a><a href="warc/' . e2($short) . '.warc.gz">WARC</a><a href="warc/' . e2($short) . '.cdxj">Indice CDXJ</a>'
        . '<a href="pagine.json">pagine.json</a><a href="SHA256SUMS">SHA256SUMS</a>' . ($ots === 'stamped' ? '<a href="SHA256SUMS.ots">SHA256SUMS.ots</a>' : '')
        . ($zipOk ? '<a href="bundle.zip">Bundle ZIP</a>' : '') . '</div>'
        . '<h2>Risorse per tipo</h2><div class="scroll"><table><thead><tr><th>Tipo</th><th>Quante</th><th>Byte</th></tr></thead><tbody>' . $typeRows . '</tbody></table></div>'
        . '<h2>Verifica</h2><div class="verify">' . e2($verify) . '</div>'
        . '<p class="u">I collegamenti verso pagine non scaricate o esterne sono disattivati: l\'indirizzo resta leggibile passandoci sopra. '
        . 'Gli script delle pagine sono rimossi dalla copia navigabile (restano nel WARC).</p></div></body></html>');
    if ($zipOk) {
        $zip = new ZipArchive();
        if ($zip->open("$root/bundle.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach (array_merge($files, ['index.html', 'SHA256SUMS'], $ots === 'stamped' ? ['SHA256SUMS.ots'] : []) as $rel) {
                $zip->addFile("$root/$rel", $rel);
            }
            $zip->close();
        }
    } else {
        $warn[] = 'ZIP non creato: oltre 2 GB';
    }
    @unlink($prog);
    @unlink($cancelFile);
    $link = ARCH_DIR . '/' . $short;
    if (is_link($link)) @unlink($link);
    @symlink($root, $link);
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        $size += $f->isFile() ? $f->getSize() : 0;
    }
    $proc = proc_open([PHP_BINARY, __DIR__ . '/worker-db.php', 'ready', $short], [0 => ['pipe', 'r']], $pipes);
    fwrite($pipes[0], json_encode(['title' => "Sito · $host · " . count($pages) . ' pagine', 'size_bytes' => $size, 'sha256' => $manifest,
        'capture_ms' => (int)(microtime(true) * 1000) - $t0ms, 'ots_status' => $ots, 'body_file' => "$root/text.txt",
        'diff_pct' => '', 'warn' => implode('; ', $warn)]));
    fclose($pipes[0]);
    if (proc_close($proc) !== 0) throw new RuntimeException('registrazione dell\'esito fallita');
    $pdo->prepare("UPDATE snapshots SET final_url=?, http_status=?, content_type='text/html (sito)' WHERE short=?")
        ->execute([$start, (int)($res[$start]['status'] ?? 200), $short]);
    clog('DONE ' . count($pages) . ' pagine · ' . $st['files'] . ' file · ' . round($size / 1048576, 1) . ' MB' . ($warn ? ' · ' . implode('; ', $warn) : ''));
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    crawl_fail($ex->getMessage());
}
@shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/drain.php') . ' >/dev/null 2>&1');
exit(0);
