<?php
declare(strict_types=1);

/* =========================================================================
 * Snapper – Wikipedia: funzioni condivise fra wiki.php (interfaccia) e
 * wiki-worker.php (acquisizione in background).  Solo include.
 *
 * Contatta soltanto <lingua>.wikipedia.org (API e fogli di stile) e, per le
 * immagini, gli host multimediali di Wikimedia in WIKI_MEDIA_HOSTS. La lingua
 * è validata in modo che l'host costruito non possa essere altro: niente punti,
 * niente porte, niente credenziali.
 *
 * Galateo Wikimedia: richieste in serie, User-Agent con recapito (l'URL del
 * repo pubblico, mai l'email del proprietario), parametro maxlag rispettato.
 * =======================================================================*/

const WIKI_UA           = 'Snapper/1.0 (https://github.com/Indr1d-C0ld/Snapper; archivio personale)';
const WIKI_MAX_REVS     = 50;     // revisioni per acquisizione
const WIKI_HISTORY_PAGE = 100;    // righe di cronologia per schermata
const WIKI_MEDIA_HOSTS  = ['upload.wikimedia.org', 'thumb.wikimedia.org', 'maps.wikimedia.org'];
const WIKI_REVERT_TAGS  = ['mw-rollback', 'mw-undo', 'mw-manual-revert'];

function wiki_valid_lang(string $l): bool
{
    // it, en, simple, zh-min-nan, be-tarask, roa-tara…: mai un punto.
    return (bool)preg_match('/^[a-z][a-z0-9]{1,11}(-[a-z0-9]{2,12}){0,2}$/', $l);
}

function wiki_valid_title(string $t): bool
{
    return $t !== '' && mb_strlen($t) <= 255 && !preg_match('/[\x00-\x1f\x7f<>\[\]{}|#]/u', $t);
}

/**
 * Riconosce un indirizzo di Wikipedia in tutte le forme comuni:
 *   https://it.wikipedia.org/wiki/Roma            https://it.m.wikipedia.org/wiki/Roma
 *   https://it.wikipedia.org/w/index.php?title=Roma&oldid=123
 *   https://it.wikipedia.org/w/index.php?diff=124&oldid=123      ?curid=4567
 * @return array{lang:string,title:?string,oldid:?int,diff:?int,curid:?int}|null
 */
function wiki_parse_url(string $url): ?array
{
    $u = parse_url(trim($url));
    if (!$u || empty($u['host']) || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true)) {
        return null;
    }
    if (!preg_match('/^([a-z0-9-]+)(?:\.m)?\.wikipedia\.org$/', strtolower($u['host']), $m) || !wiki_valid_lang($m[1])) {
        return null;
    }
    $q = [];
    parse_str($u['query'] ?? '', $q);
    $title = null;
    $path = $u['path'] ?? '';
    if (str_starts_with($path, '/wiki/')) {
        $title = rawurldecode(substr($path, 6));
    } elseif (isset($q['title']) && is_string($q['title'])) {
        $title = $q['title'];
    }
    if ($title !== null) {
        $title = trim(str_replace('_', ' ', $title));
        if (!wiki_valid_title($title)) {
            $title = null;
        }
    }
    $int = fn($k) => (isset($q[$k]) && is_string($q[$k]) && ctype_digit($q[$k])) ? (int)$q[$k] : null;
    $diff = $int('diff');
    $r = ['lang' => $m[1], 'title' => $title, 'oldid' => $int('oldid'), 'diff' => $diff, 'curid' => $int('curid')];
    if ($r['title'] === null && $r['oldid'] === null && $r['diff'] === null && $r['curid'] === null) {
        return null;
    }
    return $r;
}

function wiki_article_url(string $lang, string $title): string
{
    $t = strtr(rawurlencode(str_replace(' ', '_', $title)), ['%2F' => '/', '%3A' => ':', '%28' => '(', '%29' => ')', '%2C' => ',']);
    return "https://$lang.wikipedia.org/wiki/$t";
}

function wiki_oldid_url(string $lang, int $revid): string
{
    return "https://$lang.wikipedia.org/w/index.php?oldid=$revid";
}

/** "2026-10-04T17:46:09Z" -> "2026-10-04 17:46:09" (UTC, come CURRENT_TIMESTAMP di SQLite) */
function wiki_ts(string $iso): string
{
    $t = strtotime($iso);
    return $t ? gmdate('Y-m-d H:i:s', $t) : $iso;
}

/* ---- HTTP ------------------------------------------------------------- */

/**
 * GET in HTTPS verso un host consentito, senza seguire redirect e con un
 * tetto alla dimensione della risposta.
 * @return array{0:int,1:string,2:string}  [codice HTTP, corpo, content-type]
 */
function wiki_http_get(string $url, int $maxBytes = 40_000_000, int $timeout = 90): array
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $okHost = in_array($host, WIKI_MEDIA_HOSTS, true)
        || (preg_match('/^([a-z0-9-]+)\.wikipedia\.org$/', $host, $m) && wiki_valid_lang($m[1]));
    if (!str_starts_with($url, 'https://') || !$okHost) {
        throw new RuntimeException("host non consentito: $host");
    }
    $buf = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_USERAGENT      => WIKI_UA,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '',                 // gzip accettato e decompresso
        CURLOPT_HTTPHEADER     => ['Accept: application/json, text/css, image/*;q=0.9, */*;q=0.5'],
        CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$buf, $maxBytes): int {
            if (strlen($buf) + strlen($chunk) > $maxBytes) {
                return 0;                              // interrompe: risposta troppo grande
            }
            $buf .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($ok === false) {
        throw new RuntimeException(strlen($buf) >= $maxBytes ? "risposta oltre il limite da $host" : "rete: $err");
    }
    return [$code, $buf, $ctype];
}

/** Chiamata alle API di MediaWiki, con gestione di maxlag. */
function wiki_api(string $lang, array $params): array
{
    if (!wiki_valid_lang($lang)) {
        throw new InvalidArgumentException('lingua non valida');
    }
    $params += ['format' => 'json', 'formatversion' => '2', 'maxlag' => '5', 'errorformat' => 'plaintext'];
    $url = "https://$lang.wikipedia.org/w/api.php?" . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    for ($try = 0; $try < 4; $try++) {
        [$code, $body] = wiki_http_get($url);
        $j = json_decode($body, true);
        if (!is_array($j)) {
            throw new RuntimeException("risposta non valida dalle API di Wikipedia (HTTP $code)");
        }
        $errs = $j['errors'] ?? [];
        if ($errs && ($errs[0]['code'] ?? '') === 'maxlag') {
            sleep(min(5 * ($try + 1), 20));           // server sotto carico: si aspetta
            continue;
        }
        if ($errs) {
            throw new RuntimeException('Wikipedia: ' . ($errs[0]['text'] ?? $errs[0]['code'] ?? 'errore'));
        }
        return $j;
    }
    throw new RuntimeException('Wikipedia è sotto carico (maxlag): riprova fra qualche minuto');
}

/* ---- Voci e cronologia ----------------------------------------------- */

/**
 * Risolve una voce da titolo, revisione o id pagina, seguendo i redirect.
 * @return array{pageid:int,title:string,lang:string}|null
 */
function wiki_resolve_page(string $lang, ?string $title, ?int $oldid = null, ?int $curid = null): ?array
{
    $p = ['action' => 'query', 'redirects' => '1'];
    if ($oldid) {
        $p['revids'] = (string)$oldid;
    } elseif ($curid) {
        $p['pageids'] = (string)$curid;
    } elseif ($title !== null) {
        $p['titles'] = $title;
    } else {
        return null;
    }
    $j = wiki_api($lang, $p);
    $pg = $j['query']['pages'][0] ?? null;
    if (!$pg || !empty($pg['missing']) || !empty($pg['invalid']) || empty($pg['pageid'])) {
        return null;
    }
    return ['pageid' => (int)$pg['pageid'], 'title' => (string)$pg['title'], 'lang' => $lang];
}

function wiki_norm_rev(array $r): array
{
    return [
        'revid'    => (int)$r['revid'],
        'parentid' => (int)($r['parentid'] ?? 0),
        'ts'       => wiki_ts((string)($r['timestamp'] ?? '')),
        'user'     => !empty($r['userhidden']) ? null : (string)($r['user'] ?? ''),
        'anon'     => !empty($r['anon']) || (isset($r['userid']) && (int)$r['userid'] === 0),
        'size'     => (int)($r['size'] ?? 0),
        'comment'  => !empty($r['commenthidden']) ? null : (string)($r['comment'] ?? ''),
        'sha1'     => !empty($r['sha1hidden']) ? null : ($r['sha1'] ?? null),
        'minor'    => !empty($r['minor']),
        'tags'     => array_values(array_map('strval', $r['tags'] ?? [])),
        'hidden'   => !empty($r['texthidden']) || !empty($r['sha1hidden']),
    ];
}

/**
 * Una schermata di cronologia, dalla più recente alla più vecchia.
 * Filtri: from/to (AAAA-MM-GG), user.  $cont = cursore "AAAAMMGGhhmmss|revid".
 * Si chiede una riga in più: serve a calcolare la variazione in byte
 * dell'ultima riga mostrata e a costruire il cursore successivo.
 * @return array{0:array,1:?string}  [righe, cursore successivo]
 */
function wiki_history(string $lang, int $pageid, array $f, ?string $cont, int $limit = WIKI_HISTORY_PAGE): array
{
    $p = [
        'action' => 'query', 'prop' => 'revisions', 'pageids' => (string)$pageid,
        'rvprop' => 'ids|timestamp|user|userid|size|comment|sha1|flags|tags',
        'rvlimit' => (string)min(500, $limit + 1),
    ];
    if (!empty($f['to']))   { $p['rvstart'] = $f['to'] . 'T23:59:59Z'; }
    if (!empty($f['from'])) { $p['rvend']   = $f['from'] . 'T00:00:00Z'; }
    if (!empty($f['user'])) { $p['rvuser']  = $f['user']; }
    if ($cont !== null && preg_match('/^\d{14}\|\d+$/', $cont)) {
        $p['rvcontinue'] = $cont;
    }
    $j = wiki_api($lang, $p);
    $raw = $j['query']['pages'][0]['revisions'] ?? [];
    $rows = array_map('wiki_norm_rev', $raw);
    $next = null;
    if (count($rows) > $limit) {
        $x = $rows[$limit];
        $next = gmdate('YmdHis', strtotime($x['ts'] . ' UTC')) . '|' . $x['revid'];
    } elseif (isset($j['continue']['rvcontinue'])) {
        $next = (string)$j['continue']['rvcontinue'];
    }
    return [$rows, $next];
}

/**
 * Aggiunge alle righe (dalla più recente) variazione in byte e segni di revert.
 * Le righe in eccesso oltre $show servono solo da contesto e vengono tolte.
 */
function wiki_annotate(array $rows, int $show): array
{
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        $older = $rows[$i + 1] ?? null;
        $rows[$i]['delta'] = $older ? $rows[$i]['size'] - $older['size'] : null;
        $rows[$i]['is_revert']    = (bool)array_intersect($rows[$i]['tags'], WIKI_REVERT_TAGS);
        $rows[$i]['was_reverted'] = in_array('mw-reverted', $rows[$i]['tags'], true);
        // Stesso sha1 di una revisione più vecchia: il testo torna identico a
        // com'era allora. È il segno più affidabile di un ripristino.
        $rows[$i]['restores'] = null;
        if ($rows[$i]['sha1'] !== null) {
            for ($k = $i + 2; $k < $n; $k++) {
                if ($rows[$k]['sha1'] === $rows[$i]['sha1']) {
                    $rows[$i]['restores'] = ['revid' => $rows[$k]['revid'], 'ts' => $rows[$k]['ts']];
                    break;
                }
            }
        }
    }
    return array_slice($rows, 0, $show);
}

/** Quali autori sono bot (gruppo "bot"). Gli IP non vengono interrogati. */
function wiki_bots(string $lang, array $users): array
{
    $users = array_values(array_unique(array_filter($users, fn($u) => $u !== null && $u !== ''
        && !filter_var($u, FILTER_VALIDATE_IP))));
    $bots = [];
    foreach (array_chunk($users, 50) as $chunk) {
        $j = wiki_api($lang, ['action' => 'query', 'list' => 'users', 'ususers' => implode('|', $chunk), 'usprop' => 'groups']);
        foreach ($j['query']['users'] ?? [] as $u) {
            if (in_array('bot', $u['groups'] ?? [], true)) {
                $bots[(string)$u['name']] = true;
            }
        }
    }
    return $bots;
}

/* ---- Database --------------------------------------------------------- */

function wiki_page_id(PDO $pdo, string $lang, int $pageid, string $title): int
{
    $pdo->prepare('INSERT INTO wiki_pages(lang, pageid, title) VALUES(?,?,?)
                   ON CONFLICT(lang, pageid) DO UPDATE SET title=excluded.title, updated=CURRENT_TIMESTAMP')
        ->execute([$lang, $pageid, $title]);
    $s = $pdo->prepare('SELECT id FROM wiki_pages WHERE lang=? AND pageid=?');
    $s->execute([$lang, $pageid]);
    $id = (int)$s->fetch()['id'];
    $s->closeCursor();
    return $id;
}

/**
 * Revisioni già archiviate (prove concluse) o in arrivo (prove in coda o in
 * sviluppo) per una voce.
 * @return array{0:array<int,string>,1:array<int,string>}  [revid => short archiviato, revid => short in arrivo]
 */
function wiki_known_revs(PDO $pdo, int $wikiPage): array
{
    $done = [];
    $s = $pdo->prepare("SELECT r.revid, r.short FROM wiki_revisions r JOIN snapshots s ON s.short = r.short
                        WHERE r.wiki_page = ? AND s.status = 'ready' ORDER BY s.ts");
    $s->execute([$wikiPage]);
    foreach ($s as $r) {
        $done[(int)$r['revid']] = (string)$r['short'];
    }
    $pending = [];
    $s = $pdo->prepare("SELECT j.short, j.revids FROM wiki_jobs j JOIN snapshots s ON s.short = j.short
                        WHERE j.wiki_page = ? AND s.status IN ('pending','running')");
    $s->execute([$wikiPage]);
    foreach ($s as $r) {
        foreach (json_decode((string)$r['revids'], true) ?: [] as $id) {
            $pending[(int)$id] = (string)$r['short'];
        }
    }
    return [$done, $pending];
}

/**
 * Accoda un'acquisizione: una prova di tipo 'wiki' più l'elenco delle
 * revisioni. Il worker la riconosce dal tipo e la affida a wiki-worker.php.
 * @return array{0:string,1:bool}  [short, avviata subito]
 */
function wiki_enqueue(PDO $pdo, int $wikiPage, string $lang, string $title, array $revids, string $source = 'web'): array
{
    $revids = array_values(array_unique(array_map('intval', $revids)));
    $short = safe_short(7);
    $n = count($revids);
    $label = "Wikipedia · $title ($lang) · $n " . ($n === 1 ? 'revisione' : 'revisioni');
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO snapshots(short, url, title, status, source, kind) VALUES(?,?,?,'pending',?,'wiki')")
        ->execute([$short, wiki_article_url($lang, $title), $label, mb_substr($source, 0, 64)]);
    $pdo->prepare('INSERT INTO wiki_jobs(short, wiki_page, revids) VALUES(?,?,?)')
        ->execute([$short, $wikiPage, json_encode($revids)]);
    $pdo->commit();
    $started = with_queue_lock(
        fn() => spawn_worker_locked($short, wiki_article_url($lang, $title)),
        fn() => false
    );
    return [$short, $started];
}
