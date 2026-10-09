<?php
declare(strict_types=1);

/* =========================================================================
 * Snapper – crawler di siti interi.  Solo include (crawl-worker.php, sites.php).
 *
 * Barriera applicativa anti-SSRF. Il crawler segue link scelti da altri, quindi
 * il controllo non può limitarsi all'indirizzo di partenza: OGNI richiesta
 * (pagine, immagini, fogli di stile, robots.txt, ogni passo di un redirect)
 *   1. risolve il nome e rifiuta se anche uno solo degli indirizzi non è
 *      pubblico (ip_is_public, severa) o è l'IP pubblico di questo server;
 *   2. si collega proprio all'indirizzo verificato (CURLOPT_RESOLVE): un nome
 *      che cambia indirizzo dopo il controllo (DNS rebinding) non lo aggira;
 *   3. non segue redirect da sola: ogni Location torna al punto 1;
 *   4. accetta solo http/https, su porte web, senza proxy.
 * Non sostituisce una barriera nel kernel, ma copre tutto ciò che il crawler
 * fa. Il JavaScript delle pagine non viene eseguito (niente browser).
 * =======================================================================*/

const CRAWL_UA    = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150 Safari/537.36 Snapper/1.0';
const CRAWL_PORTS = [80, 443, 8000, 8080, 8443];
const CRAWL_TYPES = ['html', 'img', 'css', 'js', 'font', 'pdf', 'doc', 'media', 'archive', 'other'];
const CRAWL_DROP_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'fbclid', 'gclid',
    'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'igshid', 'yclid', '_ga', 'ref_src', 'phpsessid', 'jsessionid', 'sid', 'sessionid'];

/* ---- Opzioni e profili ------------------------------------------------ */

function crawl_defaults(): array
{
    return [
        'scope' => 'path', 'include' => [], 'exclude' => [], 'accept_re' => '', 'reject_re' => '',
        'depth' => 5, 'pages' => 300, 'mb' => 500, 'minutes' => 60,
        'types' => ['html', 'img', 'css', 'font', 'pdf'], 'external' => 'requisites',
        'traps' => true, 'robots' => true, 'delay' => 700, 'rate' => 0, 'drop' => CRAWL_DROP_PARAMS,
    ];
}

/** profili pronti: punti di partenza prudenti, modificabili */
function crawl_presets(): array
{
    $d = crawl_defaults();
    return [
        'sezione' => ['label' => 'Solo questa sezione', 'hint' => 'le pagine sotto l\'indirizzo indicato',
                      'o' => $d],
        'sito' => ['label' => 'Sito intero, prudente', 'hint' => 'tutto l\'host, con limiti contenuti',
                   'o' => ['scope' => 'host', 'depth' => 10, 'pages' => 2000, 'mb' => 2048, 'minutes' => 240] + $d],
        'documentazione' => ['label' => 'Documentazione', 'hint' => 'molte pagine di testo, PDF e documenti, niente risorse esterne',
                             'o' => ['scope' => 'path', 'depth' => 20, 'pages' => 5000, 'mb' => 2048, 'minutes' => 240,
                                     'types' => ['html', 'img', 'css', 'font', 'pdf', 'doc'], 'external' => 'none'] + $d],
        'blog' => ['label' => 'Blog senza archivi e tag', 'hint' => 'articoli e pagine, esclusi tag, categorie, autori, paginazioni e feed',
                   'o' => ['scope' => 'host', 'depth' => 8, 'pages' => 1500, 'mb' => 2048, 'minutes' => 240,
                           'reject_re' => '/(tag|category|categoria|author|autore|page|feed|amp)(/|$)|[?&](s|p|paged|share)=|/\d{4}/(\d{2}/)?$'] + $d],
    ];
}

/** valida e limita le opzioni ricevute (modulo, API, profilo) */
function crawl_options(array $in): array
{
    $d = crawl_defaults();
    $int = fn(string $k, int $lo, int $hi) => max($lo, min($hi, (int)($in[$k] ?? $d[$k])));
    $paths = function ($v): array {
        $v = is_array($v) ? $v : preg_split('/[\r\n]+/', (string)$v);
        $out = [];
        foreach ($v as $p) {
            $p = trim((string)$p);
            if ($p === '' || mb_strlen($p) > 200) continue;
            $out[] = '/' . ltrim($p, '/');
        }
        return array_slice(array_values(array_unique($out)), 0, 50);
    };
    $re = function ($v): string {
        $v = trim((string)$v);
        return ($v !== '' && mb_strlen($v) <= 500 && @preg_match(crawl_re($v), '') !== false) ? $v : '';
    };
    $types = array_values(array_intersect(CRAWL_TYPES, (array)($in['types'] ?? $d['types'])));
    if (!in_array('html', $types, true)) array_unshift($types, 'html');
    $drop = is_array($in['drop'] ?? null) ? $in['drop'] : preg_split('/[\s,]+/', (string)($in['drop'] ?? implode(' ', $d['drop'])));
    return [
        'scope'     => in_array($in['scope'] ?? '', ['host', 'subdomains', 'path'], true) ? $in['scope'] : $d['scope'],
        'include'   => $paths($in['include'] ?? []),
        'exclude'   => $paths($in['exclude'] ?? []),
        'accept_re' => $re($in['accept_re'] ?? ''),
        'reject_re' => $re($in['reject_re'] ?? ''),
        'depth'     => $int('depth', 0, 50),
        'pages'     => $int('pages', 1, 20000),
        'mb'        => $int('mb', 1, 20480),
        'minutes'   => $int('minutes', 1, 720),
        'types'     => $types,
        'external'  => in_array($in['external'] ?? '', ['none', 'requisites'], true) ? $in['external'] : $d['external'],
        'traps'     => (bool)($in['traps'] ?? $d['traps']),
        'robots'    => (bool)($in['robots'] ?? $d['robots']),
        'delay'     => $int('delay', 0, 60000),
        'rate'      => $int('rate', 0, 100000),
        'drop'      => array_slice(array_values(array_filter(array_map(fn($x) => strtolower(trim((string)$x)), $drop),
                                   fn($x) => $x !== '' && preg_match('/^[a-z0-9_.\[\]-]{1,40}$/', $x))), 0, 60),
    ];
}

/** espressione regolare dell'utente, con delimitatori sicuri */
function crawl_re(string $re): string
{
    return '#' . str_replace('#', '\#', $re) . '#iu';
}

/* ---- URL ------------------------------------------------------------- */

/** risolve un riferimento rispetto a una base e lo normalizza; null se non http(s) */
function crawl_norm(string $ref, ?string $base = null, array $drop = CRAWL_DROP_PARAMS): ?string
{
    $ref = trim(html_entity_decode($ref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $ref = preg_replace('/[\t\r\n]/', '', $ref);
    if ($ref === '' || preg_match('/^(javascript|data|mailto|tel|sms|about|blob|file|ftp):/i', $ref)) {
        return null;
    }
    if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $ref)) {
        if ($base === null) return null;
        $b = parse_url($base);
        if (!$b || empty($b['host'])) return null;
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '//')) {
            $ref = $b['scheme'] . ':' . $ref;
        } elseif (str_starts_with($ref, '/')) {
            $ref = $origin . $ref;
        } elseif (str_starts_with($ref, '?')) {
            $ref = $origin . ($b['path'] ?? '/') . $ref;
        } elseif (str_starts_with($ref, '#')) {
            $ref = $base;
        } else {
            $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
            $ref = $origin . ($dir === '' ? '/' : $dir) . $ref;
        }
    }
    $u = parse_url($ref);
    if (!$u || empty($u['host']) || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) || isset($u['user'])) {
        return null;
    }
    $scheme = strtolower($u['scheme']);
    $host = strtolower(rtrim($u['host'], '.'));
    if (!str_starts_with($host, '[') && preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
        $host = idn_to_ascii($host) ?: $host;
    }
    $port = isset($u['port']) && !(($scheme === 'http' && $u['port'] == 80) || ($scheme === 'https' && $u['port'] == 443)) ? ':' . (int)$u['port'] : '';
    $path = crawl_dots($u['path'] ?? '/');
    $path = preg_replace_callback('/[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/%]/', fn($m) => rawurlencode($m[0]), $path);
    $q = '';
    if (isset($u['query']) && $u['query'] !== '') {
        $parts = [];
        foreach (explode('&', $u['query']) as $kv) {
            if ($kv === '') continue;
            $k = strtolower(rawurldecode(explode('=', $kv, 2)[0]));
            if (in_array($k, $drop, true)) continue;
            $parts[] = $kv;
        }
        if ($parts) $q = '?' . implode('&', $parts);
    }
    return "$scheme://$host$port$path$q";
}

function crawl_dots(string $path): string
{
    $out = [];
    foreach (explode('/', $path) as $i => $seg) {
        if ($seg === '..') { if (count($out) > 1) array_pop($out); continue; }
        if ($seg === '.') continue;
        $out[] = $seg;
    }
    $p = implode('/', $out);
    if (!str_starts_with($p, '/')) $p = '/' . $p;
    if (preg_match('#/\.\.?$#', $path)) $p = rtrim($p, '/') . '/';
    return $p;
}

function crawl_host(string $url): string
{
    return strtolower((string)parse_url($url, PHP_URL_HOST));
}

/* ---- Barriera anti-SSRF ----------------------------------------------- */

/**
 * IP pubblici di questo server (dai nomi in ServerName/ServerAlias di
 * Apache): una pagina non deve poter far tornare il crawler sui servizi di
 * casa passando da fuori.
 */
function crawl_self_ips(): array
{
    $ips = [];
    foreach (glob('/etc/apache2/sites-enabled/*.conf') ?: [] as $f) {
        foreach (@file($f) ?: [] as $line) {
            if (preg_match('/^\s*Server(?:Name|Alias)\s+(.+)$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $name) {
                    $name = preg_replace('/:\d+$/', '', $name);
                    if ($name !== '' && !str_contains($name, '*')) {
                        foreach (crawl_dns($name) as $ip) $ips[$ip] = true;
                    }
                }
            }
        }
    }
    return array_keys($ips);
}

/** indirizzi di un nome (A e AAAA), o l'indirizzo stesso se è letterale */
function crawl_dns(string $host): array
{
    $h = trim($host, '[]');
    if (filter_var($h, FILTER_VALIDATE_IP)) {
        return [$h];
    }
    if (!preg_match('/^[a-z0-9.-]{1,253}$/i', $h)) {
        return [];
    }
    $ips = [];
    foreach (@dns_get_record($h, DNS_A | DNS_AAAA) ?: [] as $r) {
        if (!empty($r['ip'])) $ips[] = $r['ip'];
        if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    foreach (@gethostbynamel($h) ?: [] as $ip) $ips[] = $ip;
    return array_values(array_unique($ips));
}

/**
 * Verifica un URL prima di ogni richiesta.
 * @return array{0:?string,1:?string,2:int}  [ip da usare, motivo del rifiuto, porta]
 */
function crawl_check(string $url, array $selfIps): array
{
    $u = parse_url($url);
    $scheme = strtolower($u['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true) || empty($u['host']) || isset($u['user'])) {
        return [null, 'indirizzo non http/https', 0];
    }
    $port = (int)($u['port'] ?? ($scheme === 'https' ? 443 : 80));
    $host = strtolower(trim($u['host'], '[]'));
    // solo per il collaudo in sandbox: un elenco esplicito di host:porta locali
    if (defined('CRAWL_TEST_ALLOW') && in_array("$host:$port", (array)constant('CRAWL_TEST_ALLOW'), true)) {
        return [$host, null, $port];
    }
    if (!in_array($port, CRAWL_PORTS, true)) {
        return [null, "porta $port non ammessa", $port];
    }
    $ips = crawl_dns($host);
    if (!$ips) {
        return [null, 'nome non risolvibile', $port];
    }
    foreach ($ips as $ip) {
        if (!ip_is_public($ip)) {
            return [null, "indirizzo non pubblico ($ip)", $port];
        }
        if (in_array($ip, $selfIps, true)) {
            return [null, 'indirizzo di questo server', $port];
        }
    }
    // prima IPv4, poi IPv6
    usort($ips, fn($a, $b) => (str_contains($a, ':') <=> str_contains($b, ':')));
    return [$ips[0], null, $port];
}

/**
 * Una richiesta HTTP attraverso la barriera, seguendo a mano fino a 5 redirect.
 * $accept(ctype, headers) decide se scaricare il corpo dopo aver visto le intestazioni.
 * @return array  url, final, status, ctype, raw_headers, req_headers, body, ip, redirects[], blocked, error, truncated
 */
function crawl_fetch(string $url, array $selfIps, array $o, int $maxBytes, ?callable $accept = null, string $method = 'GET'): array
{
    $res = ['url' => $url, 'final' => $url, 'status' => 0, 'ctype' => '', 'raw_headers' => '', 'req_headers' => '',
            'body' => '', 'ip' => null, 'redirects' => [], 'blocked' => null, 'error' => null, 'truncated' => false, 'exchanges' => []];
    $cur = $url;
    for ($hop = 0; $hop <= 5; $hop++) {
        [$ip, $why, $port] = crawl_check($cur, $selfIps);
        if ($ip === null) {
            $res['blocked'] = $why;
            $res['final'] = $cur;
            return $res;
        }
        $host = trim(strtolower((string)parse_url($cur, PHP_URL_HOST)), '[]');
        $hdr = '';
        $body = '';
        $abort = false;
        $tooBig = false;
        $ch = curl_init($cur);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? "[$ip]" : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY          => '',
            CURLOPT_NOPROXY        => '*',
            CURLOPT_USERAGENT      => CRAWL_UA,
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,text/css,image/*,*/*;q=0.8',
                                       'Accept-Language: it-IT,it;q=0.9,en;q=0.8', 'Accept-Encoding: identity'],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_LOW_SPEED_LIMIT => 64,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_MAX_RECV_SPEED_LARGE => $o['rate'] > 0 ? $o['rate'] * 1024 : 0,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$hdr) {
                $hdr .= $line;
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$body, &$abort, &$tooBig, &$hdr, $accept, $maxBytes) {
                if ($body === '' && $accept !== null) {
                    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                    if ($code < 300 && !$accept($ct, $hdr)) { $abort = true; return 0; }
                }
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    $body .= substr($chunk, 0, max(0, $maxBytes - strlen($body)));
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $reqH = (string)curl_getinfo($ch, CURLINFO_HEADER_OUT);
        $err = curl_error($ch);
        $connIp = (string)curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);
        // difesa in profondità: il collegamento deve essere avvenuto all'indirizzo verificato
        if ($connIp !== '' && $connIp !== $ip && !defined('CRAWL_TEST_ALLOW')) {
            $res['blocked'] = "collegamento a un indirizzo diverso da quello verificato ($connIp)";
            return $res;
        }
        $res['exchanges'][] = ['url' => $cur, 'ip' => $ip, 'req' => $reqH, 'hdr' => $hdr, 'body' => $body, 'status' => $status, 'truncated' => $tooBig];
        if ($ok === false && !$abort && !$tooBig) {
            $res['error'] = $err ?: 'errore di rete';
            $res['final'] = $cur;
            return $res;
        }
        if ($status >= 300 && $status < 400 && preg_match('/^location:\s*(.+)$/mi', $hdr, $m)) {
            $next = crawl_norm(trim($m[1]), $cur, $o['drop'] ?? CRAWL_DROP_PARAMS);
            $res['redirects'][] = ['from' => $cur, 'to' => $next, 'status' => $status];
            if ($next === null) {
                $res['error'] = 'redirect verso un indirizzo non valido';
                return $res;
            }
            $cur = $next;
            continue;
        }
        $res['final'] = $cur;
        $res['status'] = $status;
        $res['ctype'] = $ctype;
        $res['raw_headers'] = $hdr;
        $res['req_headers'] = $reqH;
        $res['body'] = $abort ? '' : $body;
        $res['ip'] = $ip;
        $res['truncated'] = $tooBig;
        $res['skipped'] = $abort;
        return $res;
    }
    $res['error'] = 'troppi redirect';
    return $res;
}

/** corpo decodificato se il server l'ha compresso nonostante "identity" */
function crawl_decode_body(string $body, string $headers): string
{
    if (preg_match('/^content-encoding:\s*(gzip|x-gzip|deflate)/mi', $headers, $m)) {
        $d = $m[1] === 'deflate' ? (@gzuncompress($body) ?: @gzinflate($body)) : @gzdecode($body);
        return $d === false || $d === null ? $body : $d;
    }
    return $body;
}

/* ---- robots.txt ------------------------------------------------------- */

/** regole per "*" e per "snapper": [[allow(bool), pattern], ...] */
function crawl_robots_parse(string $txt): array
{
    $groups = [];
    $agents = [];
    $rules = [];
    $inRules = false;
    foreach (preg_split('/\r?\n/', $txt) as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if ($line === '' || !str_contains($line, ':')) continue;
        [$k, $v] = array_map('trim', explode(':', $line, 2));
        $k = strtolower($k);
        if ($k === 'user-agent') {
            if ($inRules) { $groups[] = [$agents, $rules]; $agents = []; $rules = []; $inRules = false; }
            $agents[] = strtolower($v);
        } elseif ($k === 'allow' || $k === 'disallow') {
            $inRules = true;
            if ($v !== '' || $k === 'allow') $rules[] = [$k === 'allow', $v];
        }
    }
    if ($agents) $groups[] = [$agents, $rules];
    $mine = [];
    $star = [];
    foreach ($groups as [$ag, $r]) {
        foreach ($ag as $a) {
            if (str_contains($a, 'snapper')) $mine = array_merge($mine, $r);
            if ($a === '*') $star = array_merge($star, $r);
        }
    }
    return $mine ?: $star;
}

/** vince la regola più lunga che corrisponde (semantica di Google) */
function crawl_robots_allowed(array $rules, string $pathQuery): bool
{
    $best = -1;
    $allow = true;
    foreach ($rules as [$isAllow, $pat]) {
        if ($pat === '') continue;
        $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($pat, '#')) . '#';
        if (preg_match($re, $pathQuery) && strlen($pat) > $best) {
            $best = strlen($pat);
            $allow = $isAllow;
        } elseif (preg_match($re, $pathQuery) && strlen($pat) === $best && $isAllow) {
            $allow = true;
        }
    }
    return $allow;
}

/* ---- Tipi e trappole -------------------------------------------------- */

function crawl_type_ext(string $url): string
{
    $ext = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    return match (true) {
        $ext === '' || in_array($ext, ['html', 'htm', 'xhtml', 'php', 'asp', 'aspx', 'jsp', 'shtml', 'cfm'], true) => 'html',
        in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff'], true) => 'img',
        $ext === 'css' => 'css',
        in_array($ext, ['js', 'mjs'], true) => 'js',
        in_array($ext, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true) => 'font',
        $ext === 'pdf' => 'pdf',
        in_array($ext, ['doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp', 'epub', 'xml', 'json'], true) => 'doc',
        in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv', 'mp3', 'ogg', 'oga', 'wav', 'flac', 'm4a', 'm4v'], true) => 'media',
        in_array($ext, ['zip', 'gz', 'tgz', 'rar', '7z', 'tar', 'bz2', 'xz', 'iso', 'dmg', 'exe', 'msi', 'apk', 'deb', 'rpm'], true) => 'archive',
        default => 'other',
    };
}

function crawl_type_ctype(string $ct): string
{
    $ct = strtolower(trim(explode(';', $ct)[0]));
    return match (true) {
        in_array($ct, ['text/html', 'application/xhtml+xml'], true) => 'html',
        str_starts_with($ct, 'image/') => 'img',
        $ct === 'text/css' => 'css',
        str_contains($ct, 'javascript') || $ct === 'application/ecmascript' => 'js',
        str_starts_with($ct, 'font/') || str_contains($ct, 'font') => 'font',
        $ct === 'application/pdf' => 'pdf',
        str_starts_with($ct, 'video/') || str_starts_with($ct, 'audio/') => 'media',
        str_starts_with($ct, 'text/') || str_contains($ct, 'officedocument') || str_contains($ct, 'opendocument')
            || in_array($ct, ['application/msword', 'application/rtf', 'application/json', 'application/xml', 'application/epub+zip'], true) => 'doc',
        str_contains($ct, 'zip') || str_contains($ct, 'compressed') || str_contains($ct, 'x-tar') || $ct === 'application/octet-stream' => 'archive',
        default => 'other',
    };
}

/** pagine generate all'infinito o che non servono a un archivio */
function crawl_trap(string $url): ?string
{
    if (strlen($url) > 400) return 'indirizzo troppo lungo';
    $u = parse_url($url);
    $path = strtolower($u['path'] ?? '/');
    if (preg_match('#/(calendar|calendario|events?/(list|day|week|month)|wp-json|xmlrpc\.php|wp-login\.php|wp-admin|login|logout|signin|signup|cart|carrello|checkout|my-account)(/|$|\?)#', $path)) {
        return 'area dinamica';
    }
    $segs = array_values(array_filter(explode('/', $path), 'strlen'));
    $counts = array_count_values($segs);
    if ($counts && max($counts) >= 3) return 'segmenti ripetuti';
    if (count($segs) > 15) return 'percorso troppo profondo';
    if (!empty($u['query'])) {
        parse_str($u['query'], $q);
        foreach (array_keys($q) as $k) {
            if (in_array(strtolower((string)$k), ['replytocom', 'share', 'action', 'sort', 'order', 'orderby', 'filter', 'calendar',
                'ical', 'ics', 'month', 'year', 'day', 'week', 'date', 'print', 'format', 'output', 'redirect_to', 'return', 'add-to-cart'], true)) {
                return 'parametro dinamico (' . $k . ')';
            }
        }
    }
    return null;
}

/* ---- Ambito ----------------------------------------------------------- */

/**
 * Una pagina rientra nell'albero scelto?
 * @return ?string  null se sì, altrimenti il motivo
 */
function crawl_scope(string $url, string $start, array $o): ?string
{
    $h = crawl_host($url);
    $sh = crawl_host($start);
    $base = preg_replace('/^www\./', '', $sh);
    $ok = match ($o['scope']) {
        'subdomains' => $h === $sh || $h === $base || str_ends_with($h, '.' . $base),
        default => $h === $sh,
    };
    if (!$ok) return 'fuori dall\'host';
    if ((parse_url($url, PHP_URL_SCHEME) ?? '') !== (parse_url($start, PHP_URL_SCHEME) ?? '') && $h === $sh
        && parse_url($start, PHP_URL_SCHEME) === 'https') {
        // la stessa pagina in http: si tiene la versione https
        return 'versione http di un sito https';
    }
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '/');
    if ($o['scope'] === 'path') {
        $sp = (string)(parse_url($start, PHP_URL_PATH) ?? '/');
        $dir = str_ends_with($sp, '/') ? $sp : preg_replace('#/[^/]*$#', '/', $sp);
        if (!str_starts_with($path, $dir) && $path !== rtrim($dir, '/')) return 'fuori dalla sezione';
    }
    foreach ($o['exclude'] as $p) {
        if (str_starts_with($path, $p)) return 'directory esclusa';
    }
    if ($o['include']) {
        $in = false;
        foreach ($o['include'] as $p) {
            if (str_starts_with($path, $p)) { $in = true; break; }
        }
        if (!$in && $url !== $start) return 'fuori dalle directory incluse';
    }
    if ($o['accept_re'] !== '' && !preg_match(crawl_re($o['accept_re']), $url) && $url !== $start) return 'non corrisponde al filtro di inclusione';
    if ($o['reject_re'] !== '' && preg_match(crawl_re($o['reject_re']), $url) && $url !== $start) return 'escluso dal filtro';
    return null;
}

/* ---- Estrazione dei collegamenti -------------------------------------- */

/** documento HTML con la codifica dichiarata dal server, se il parser la conosce */
function crawl_doc(string $html, string $charset): Dom\HTMLDocument
{
    if ($charset !== '') {
        try {
            return Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, $charset);
        } catch (ValueError) {
            // codifica sconosciuta: decide il parser dal <meta charset>
        }
    }
    return Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

function crawl_charset(string $ctype): string
{
    return preg_match('/charset\s*=\s*["\']?([A-Za-z0-9_.:-]+)/i', $ctype, $m) ? $m[1] : '';
}

/** URL in un srcset */
function crawl_srcset(string $v): array
{
    $out = [];
    foreach (preg_split('/,\s+(?=\S)/', trim($v)) as $part) {
        $u = preg_split('/\s+/', trim($part))[0] ?? '';
        if ($u !== '') $out[] = $u;
    }
    return $out;
}

/** riferimenti url() e @import di un foglio di stile */
function crawl_css_refs(string $css): array
{
    preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)|@import\s+([\'"])(.*?)\3/is', $css, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $u = trim($x[2] !== '' ? $x[2] : ($x[4] ?? ''));
        if ($u !== '' && !str_starts_with(strtolower($u), 'data:') && !str_starts_with($u, '#')) $out[] = $u;
    }
    return $out;
}

const CRAWL_REQ_ATTRS = [
    'img' => ['src', 'srcset', 'data-src', 'data-srcset', 'data-lazy-src', 'data-original'],
    'source' => ['src', 'srcset', 'data-src', 'data-srcset'], 'video' => ['src', 'poster'], 'audio' => ['src'],
    'track' => ['src'], 'embed' => ['src'], 'input' => ['src'], 'script' => ['src'],
];

/**
 * Collegamenti di una pagina: da seguire (nav) e risorse per mostrarla (req).
 * @return array{nav:string[],req:string[],title:string,text:string,base:string}
 */
function crawl_extract(string $html, string $pageUrl, array $o, string $charset = ''): array
{
    $doc = crawl_doc($html, $charset);
    $base = $pageUrl;
    if ($b = $doc->querySelector('base[href]')) {
        $base = crawl_norm((string)$b->getAttribute('href'), $pageUrl, $o['drop']) ?? $pageUrl;
    }
    $nav = $req = [];
    $add = function (array &$list, string $ref) use ($base, $o) {
        $u = crawl_norm($ref, $base, $o['drop']);
        if ($u !== null) $list[$u] = true;
    };
    foreach ($doc->querySelectorAll('a[href], area[href], iframe[src], frame[src]') as $e) {
        $add($nav, (string)$e->getAttribute($e->hasAttribute('href') ? 'href' : 'src'));
    }
    foreach ($doc->querySelectorAll('link[href]') as $e) {
        $rel = strtolower((string)$e->getAttribute('rel'));
        if (preg_match('/\b(stylesheet|icon|apple-touch-icon|preload|manifest|mask-icon)\b/', $rel)) {
            $add($req, (string)$e->getAttribute('href'));
        }
    }
    foreach (CRAWL_REQ_ATTRS as $tag => $attrs) {
        foreach ($doc->querySelectorAll($tag) as $e) {
            foreach ($attrs as $at) {
                if (!$e->hasAttribute($at)) continue;
                $v = (string)$e->getAttribute($at);
                foreach (str_contains($at, 'srcset') ? crawl_srcset($v) : [$v] as $r) $add($req, $r);
            }
        }
    }
    foreach ($doc->querySelectorAll('object[data]') as $e) $add($req, (string)$e->getAttribute('data'));
    foreach ($doc->querySelectorAll('style') as $e) {
        foreach (crawl_css_refs((string)$e->textContent) as $r) $add($req, $r);
    }
    foreach ($doc->querySelectorAll('[style]') as $e) {
        foreach (crawl_css_refs((string)$e->getAttribute('style')) as $r) $add($req, $r);
    }
    foreach ($doc->querySelectorAll('meta[http-equiv]') as $e) {
        if (strtolower((string)$e->getAttribute('http-equiv')) === 'refresh'
            && preg_match('/url\s*=\s*[\'"]?([^\'";]+)/i', (string)$e->getAttribute('content'), $m)) {
            $add($nav, $m[1]);
        }
    }
    $title = '';
    if ($t = $doc->querySelector('title')) $title = trim(preg_replace('/\s+/u', ' ', (string)$t->textContent));
    $text = '';
    if ($body = $doc->querySelector('body')) {
        $text = crawl_text($body);
    }
    return ['nav' => array_keys($nav), 'req' => array_keys($req), 'title' => mb_substr($title, 0, 300), 'text' => $text, 'base' => $base];
}

function crawl_text(Dom\Node $n): string
{
    $s = '';
    for ($c = $n->firstChild; $c !== null; $c = $c->nextSibling) {
        if ($c instanceof Dom\Element) {
            $t = strtolower($c->localName);
            if (in_array($t, ['script', 'style', 'noscript', 'template', 'svg'], true)) continue;
            $s .= crawl_text($c);
            if (in_array($t, ['p', 'div', 'li', 'tr', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'section', 'article'], true)) $s .= "\n";
        } elseif ($c instanceof Dom\Text) {
            $s .= $c->data;
        }
    }
    return trim(preg_replace(['/[ \t\x{00A0}]+/u', '/\n\s*\n+/'], [' ', "\n"], $s));
}

/* ---- Percorsi locali -------------------------------------------------- */

/** estensione sicura per il tipo: mai un'estensione eseguibile o negata da Apache */
function crawl_safe_ext(string $type, string $ext, string $ctype): string
{
    $ext = strtolower($ext);
    $ok = [
        'html' => ['html', 'htm'], 'css' => ['css'], 'js' => ['js', 'mjs'],
        'img' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff'],
        'font' => ['woff', 'woff2', 'ttf', 'otf', 'eot'], 'pdf' => ['pdf'],
        'doc' => ['doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp', 'epub', 'xml', 'json'],
        'media' => ['mp4', 'webm', 'mov', 'mp3', 'ogg', 'oga', 'wav', 'flac', 'm4a', 'm4v'],
        'archive' => ['zip', 'gz', 'tgz', 'rar', '7z', 'tar', 'bz2', 'xz'],
    ];
    if (in_array($ext, $ok[$type] ?? [], true)) return $ext;
    $ct = strtolower(trim(explode(';', $ctype)[0]));
    $map = ['html' => 'html', 'css' => 'css', 'js' => 'js', 'pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg',
            'image/gif' => 'gif', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/avif' => 'avif', 'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico', 'font/woff2' => 'woff2', 'font/woff' => 'woff', 'font/ttf' => 'ttf'];
    return $map[$ct] ?? $map[$type] ?? 'bin';
}

/**
 * Percorso locale (relativo alla prova) di un URL. Le pagine finiscono in
 * <percorso>/index.html, così i collegamenti relativi restano naturali.
 */
function crawl_local(string $url, string $type, string $ctype): string
{
    $u = parse_url($url);
    $host = preg_replace('/[^a-z0-9.-]/', '_', strtolower(trim($u['host'], '[]'))) . (isset($u['port']) ? '_' . (int)$u['port'] : '');
    $segs = [];
    foreach (explode('/', $u['path'] ?? '/') as $s) {
        if ($s === '') continue;
        $s = rawurldecode($s);
        $s = preg_replace('/[^\p{L}\p{N}._~ -]+/u', '_', $s);
        $s = trim($s, ". ");
        if ($s === '' || $s === '_') $s = '_';
        if (mb_strlen($s) > 100) $s = mb_substr($s, 0, 80) . '_' . substr(sha1($s), 0, 8);
        $segs[] = $s;
    }
    $endsSlash = str_ends_with($u['path'] ?? '/', '/') || !$segs;
    $qh = !empty($u['query']) ? '__' . substr(sha1($u['query']), 0, 10) : '';
    if ($type === 'html') {
        $last = $endsSlash ? '' : array_pop($segs);
        $ext = strtolower(pathinfo($last, PATHINFO_EXTENSION));
        if ($last !== '' && in_array($ext, ['html', 'htm'], true) && $qh === '') {
            $segs[] = $last;
        } else {
            if ($last !== '') $segs[] = $last . $qh;
            elseif ($qh !== '') $segs[] = $qh;
            $segs[] = 'index.html';
        }
    } else {
        $last = array_pop($segs) ?? 'index';
        $ext = pathinfo($last, PATHINFO_EXTENSION);
        $name = $ext !== '' ? substr($last, 0, -strlen($ext) - 1) : $last;
        $segs[] = ($name !== '' ? $name : 'file') . $qh . '.' . crawl_safe_ext($type, $ext, $ctype);
    }
    return 'site/' . $host . '/' . implode('/', $segs);
}

/** percorso relativo da un file a un altro (entrambi relativi alla prova) */
function crawl_rel(string $from, string $to): string
{
    $d = dirname($from);
    $f = $d === '.' ? [] : explode('/', $d);      // file nella radice della prova
    $t = explode('/', $to);
    while ($f && $t && $f[0] === $t[0]) { array_shift($f); array_shift($t); }
    return (str_repeat('../', count($f)) ?: '') . implode('/', array_map('rawurlencode', $t));
}

/* ---- Riscrittura ------------------------------------------------------ */

function crawl_rewrite_css(string $css, string $cssUrl, string $cssLocal, array $map, array $o): string
{
    $fix = function (string $ref) use ($cssUrl, $cssLocal, $map, $o): ?string {
        if (str_starts_with(strtolower(trim($ref)), 'data:')) return $ref;
        $abs = crawl_norm($ref, $cssUrl, $o['drop']);
        return ($abs !== null && isset($map[$abs])) ? crawl_rel($cssLocal, $map[$abs]) : null;
    };
    $css = preg_replace_callback('/@import\s+(?:url\(\s*)?([\'"]?)([^\'")\s;]+)\1\s*\)?([^;]*);/i', function ($m) use ($fix) {
        $r = $fix($m[2]);
        return $r === null ? '' : '@import url("' . $r . '")' . $m[3] . ';';
    }, $css);
    $css = preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', function ($m) use ($fix) {
        $r = $fix($m[2]);
        return $r === null ? 'none' : 'url("' . str_replace('"', '%22', $r) . '")';
    }, $css);
    return str_ireplace(['expression(', '-moz-binding', 'behavior:'], ['x(', 'x', 'x:'], $css);
}

/**
 * Rende autosufficiente una pagina: collegamenti verso le copie locali,
 * tutto il resto inerte; niente script; stili inline spostati in un file
 * (la CSP degli archivi non li ammette).
 * @return array{0:string,1:string}  [html, css della pagina]
 */
function crawl_rewrite_html(string $html, string $pageUrl, string $pageLocal, array $map, array $inScope, array $o, string $cssLocal, string $charset = ''): array
{
    $doc = crawl_doc($html, $charset);
    $base = $pageUrl;
    foreach (iterator_to_array($doc->querySelectorAll('base')) as $b) {
        if ($b->hasAttribute('href')) $base = crawl_norm((string)$b->getAttribute('href'), $pageUrl, $o['drop']) ?? $base;
        $b->remove();
    }
    $local = function (string $ref) use ($base, $map, $pageLocal, $o): array {
        $frag = str_contains($ref, '#') ? '#' . rawurlencode(rawurldecode(substr($ref, strpos($ref, '#') + 1))) : '';
        $abs = crawl_norm($ref, $base, $o['drop']);
        if ($abs !== null && isset($map[$abs])) {
            return [crawl_rel($pageLocal, $map[$abs]) . $frag, $abs];
        }
        return [null, $abs];
    };
    $inert = function (Dom\Element $a, string $attr, ?string $abs, bool $inScope) {
        $a->removeAttribute($attr);
        if ($abs !== null) {
            $a->setAttribute('data-href', $abs);
            $a->setAttribute('title', ($inScope ? 'Non archiviata: ' : 'Collegamento esterno: ') . $abs);
        }
        $a->classList->add($inScope ? 'snp-na' : 'snp-ext');
    };
    // script e codice attivo: inutili sotto la CSP, pericolosi nello ZIP aperto in locale
    foreach (iterator_to_array($doc->querySelectorAll('script, object, embed, applet, frameset')) as $x) {
        $x->remove();
    }
    // <noscript>: senza JavaScript è ciò che il visitatore vedrebbe; si scarta il contenitore
    // (il parser di PHP legge <noscript> come elementi veri; se arrivasse come
    // testo, lo si interpreta a parte)
    foreach (iterator_to_array($doc->querySelectorAll('noscript')) as $ns) {
        $frag = $doc->createDocumentFragment();
        if ($ns->firstElementChild === null && trim((string)$ns->textContent) !== '') {
            $tmp = Dom\HTMLDocument::createFromString('<!DOCTYPE html><body>' . $ns->textContent . '</body>', LIBXML_NOERROR);
            foreach (iterator_to_array($tmp->body->childNodes) as $c) {
                $frag->appendChild($doc->importNode($c, true));
            }
        } else {
            foreach (iterator_to_array($ns->childNodes) as $c) {
                $frag->appendChild($c);
            }
        }
        $ns->replaceWith($frag);
    }
    foreach (iterator_to_array($doc->querySelectorAll('meta')) as $m) {
        $he = strtolower((string)$m->getAttribute('http-equiv'));
        if ($m->hasAttribute('charset') || $he === 'content-type' || $he === 'content-security-policy' || $he === 'refresh') {
            if ($he === 'refresh' && preg_match('/^(\s*\d+\s*;\s*url\s*=\s*)[\'"]?([^\'"]+)/i', (string)$m->getAttribute('content'), $mm)) {
                [$l] = $local($mm[2]);
                if ($l !== null) { $m->setAttribute('content', $mm[1] . $l); continue; }
            }
            $m->remove();
        }
    }
    foreach (iterator_to_array($doc->querySelectorAll('a[href], area[href]')) as $a) {
        $ref = (string)$a->getAttribute('href');
        if (str_starts_with(trim($ref), '#')) continue;
        [$l, $abs] = $local($ref);
        if ($l !== null) { $a->setAttribute('href', $l); $a->removeAttribute('target'); continue; }
        $inert($a, 'href', $abs, $abs !== null && isset($inScope[$abs]));
    }
    foreach (iterator_to_array($doc->querySelectorAll('iframe[src], frame[src]')) as $f) {
        [$l, $abs] = $local((string)$f->getAttribute('src'));
        if ($l !== null) { $f->setAttribute('src', $l); continue; }
        $f->removeAttribute('src');
        if ($abs !== null) $f->setAttribute('data-src', $abs);
    }
    foreach (iterator_to_array($doc->querySelectorAll('link[href]')) as $lk) {
        [$l, $abs] = $local((string)$lk->getAttribute('href'));
        $rel = strtolower((string)$lk->getAttribute('rel'));
        if ($l !== null && preg_match('/\b(stylesheet|icon|apple-touch-icon|mask-icon)\b/', $rel)) {
            $lk->setAttribute('href', $l);
            $lk->removeAttribute('integrity');
            $lk->removeAttribute('crossorigin');
        } else {
            $lk->remove();          // preconnect, prefetch, alternate, risorse non scaricate
        }
    }
    foreach (CRAWL_REQ_ATTRS as $tag => $attrs) {
        if ($tag === 'script') continue;
        foreach (iterator_to_array($doc->querySelectorAll($tag)) as $e) {
            // caricamento differito: se c'è data-src e non c'è src scaricato, si usa data-src
            foreach ($attrs as $at) {
                if (!$e->hasAttribute($at)) continue;
                $v = (string)$e->getAttribute($at);
                if (str_contains($at, 'srcset')) {
                    $parts = [];
                    foreach (preg_split('/,\s+(?=\S)/', trim($v)) as $part) {
                        $bits = preg_split('/\s+/', trim($part), 2);
                        [$l] = $local($bits[0]);
                        if ($l !== null) $parts[] = $l . (isset($bits[1]) ? ' ' . $bits[1] : '');
                    }
                    $target = str_starts_with($at, 'data-') ? 'srcset' : $at;
                    $e->removeAttribute($at);
                    if ($parts && ($target === $at || !$e->hasAttribute('srcset'))) $e->setAttribute($target, implode(', ', $parts));
                } else {
                    if (str_starts_with(strtolower(ltrim($v)), 'data:')) continue;   // incorporata: va bene così
                    [$l] = $local($v);
                    $target = str_starts_with($at, 'data-') ? 'src' : $at;
                    if ($at !== $target) $e->removeAttribute($at);
                    if ($l !== null) {
                        $e->setAttribute($target, $l);
                    } elseif ($at === $target) {
                        $e->removeAttribute($at);
                    }
                }
            }
            if ($tag === 'img' && !$e->hasAttribute('src')) {
                $e->classList->add('snp-noimg');
            }
        }
    }
    foreach (iterator_to_array($doc->querySelectorAll('form')) as $f) {
        $f->removeAttribute('action');
    }
    // stili: <style> e attributi style -> file della pagina
    $css = [];
    foreach (iterator_to_array($doc->querySelectorAll('style')) as $s) {
        $css[] = crawl_rewrite_css((string)$s->textContent, $base, $cssLocal, $map, $o);
        $s->remove();
    }
    $classes = [];
    foreach (iterator_to_array($doc->querySelectorAll('*')) as $el) {
        foreach (iterator_to_array($el->attributes) as $at) {
            $n = strtolower($at->name);
            if (str_starts_with($n, 'on') || $n === 'formaction' || $n === 'ping'
                || (in_array($n, ['href', 'src', 'action', 'data'], true) && preg_match('/^\s*javascript:/i', (string)$at->value))) {
                $el->removeAttribute($at->name);
            }
        }
        if ($el->hasAttribute('style')) {
            $st = crawl_rewrite_css((string)$el->getAttribute('style'), $base, $cssLocal, $map, $o);
            $el->removeAttribute('style');
            $decl = [];
            foreach (explode(';', $st) as $d) {
                $d = trim($d);
                if ($d === '' || !str_contains($d, ':')) continue;
                $decl[] = preg_match('/!\s*important\s*$/i', $d) ? $d : "$d !important";
            }
            if ($decl) {
                $k = 'snp-s' . substr(sha1(implode(';', $decl)), 0, 10);
                $classes[$k] = implode(';', $decl);
                $el->classList->add($k);
            }
        }
    }
    foreach ($classes as $k => $d) $css[] = ".$k{" . $d . '}';
    // intestazione: codifica, foglio della pagina, stile dei collegamenti inerti
    $head = $doc->head ?? $doc->documentElement->insertBefore($doc->createElement('head'), $doc->documentElement->firstChild);
    $meta = $doc->createElement('meta');
    $meta->setAttribute('charset', 'utf-8');
    $head->insertBefore($meta, $head->firstChild);
    foreach ([crawl_rel($pageLocal, $cssLocal), crawl_rel($pageLocal, '_snapper/inerti.css')] as $href) {
        $l = $doc->createElement('link');
        $l->setAttribute('rel', 'stylesheet');
        $l->setAttribute('href', $href);
        $head->appendChild($l);
    }
    // si scrive sempre in UTF-8, come dichiara il <meta charset> appena inserito
    // (altrimenti il serializzatore userebbe la codifica d'origine)
    $doc->charset = 'UTF-8';
    return [$doc->saveHtml(), implode("\n", $css) . "\n"];
}

/* ---- WARC ------------------------------------------------------------- */

function crawl_b32(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function crawl_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return sprintf('urn:uuid:%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
}

/** chiave SURT per l'indice CDXJ: com,esempio)/percorso?query */
function crawl_surt(string $url): string
{
    $u = parse_url($url);
    $host = implode(',', array_reverse(explode('.', preg_replace('/^www\d?\./', '', strtolower($u['host'] ?? '')))));
    return $host . (isset($u['port']) ? ':' . $u['port'] : '') . ')' . strtolower($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
}

/** scrittore WARC 1.1: un membro gzip per record, più l'indice CDXJ */
final class CrawlWarc
{
    private $fh;
    private $cdx;
    public int $records = 0;

    public function __construct(private string $path, string $cdxPath, string $info)
    {
        $this->fh = fopen($path, 'wb');
        $this->cdx = fopen($cdxPath, 'wb');
        $this->write('warcinfo', null, 'application/warc-fields', $info, []);
    }

    private function write(string $type, ?string $uri, string $ctype, string $block, array $extra): array
    {
        $id = crawl_uuid();
        $h = "WARC/1.1\r\nWARC-Type: $type\r\nWARC-Record-ID: <$id>\r\nWARC-Date: " . gmdate('Y-m-d\TH:i:s\Z') . "\r\n";
        if ($uri !== null) $h .= "WARC-Target-URI: $uri\r\n";
        foreach ($extra as $k => $v) $h .= "$k: $v\r\n";
        $h .= 'WARC-Block-Digest: sha1:' . crawl_b32(sha1($block, true)) . "\r\n";
        $h .= "Content-Type: $ctype\r\nContent-Length: " . strlen($block) . "\r\n\r\n";
        $gz = gzencode($h . $block . "\r\n\r\n", 6);
        $off = ftell($this->fh);
        fwrite($this->fh, $gz);
        $this->records++;
        return [$id, $off, strlen($gz)];
    }

    /** una richiesta e la sua risposta, così come sono passate in rete */
    public function exchange(array $ex): void
    {
        if ($ex['hdr'] === '') return;
        // l'ultima risposta (dopo eventuali 100 Continue)
        $hdr = $ex['hdr'];
        if (preg_match_all('/^HTTP\/[\d.]+ \d{3}/m', $hdr, $mm, PREG_OFFSET_CAPTURE) && count($mm[0]) > 1) {
            $hdr = substr($hdr, end($mm[0])[1]);
        }
        $ip = (string)$ex['ip'];
        $extra = ['WARC-IP-Address' => $ip, 'WARC-Payload-Digest' => 'sha1:' . crawl_b32(sha1($ex['body'], true))];
        if ($ex['truncated']) $extra['WARC-Truncated'] = 'length';
        [$rid, $off, $len] = $this->write('response', $ex['url'], 'application/http;msgtype=response', $hdr . $ex['body'], $extra);
        if ($ex['req'] !== '') {
            $this->write('request', $ex['url'], 'application/http;msgtype=request', $ex['req'], ['WARC-Concurrent-To' => "<$rid>", 'WARC-IP-Address' => $ip]);
        }
        $mime = preg_match('/^content-type:\s*([^;\r\n]+)/mi', $hdr, $m) ? strtolower(trim($m[1])) : 'unk';
        fwrite($this->cdx, crawl_surt($ex['url']) . ' ' . gmdate('YmdHis') . ' ' . json_encode([
            'url' => $ex['url'], 'mime' => $mime, 'status' => (string)$ex['status'],
            'digest' => 'sha1:' . crawl_b32(sha1($ex['body'], true)), 'length' => (string)$len, 'offset' => (string)$off,
            'filename' => basename($this->path),
        ], JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** chiude il WARC e ordina l'indice per chiave, come richiede il formato CDXJ */
    public function close(string $cdxPath): void
    {
        fclose($this->fh);
        fclose($this->cdx);
        $lines = file($cdxPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        sort($lines, SORT_STRING);
        file_put_contents($cdxPath, $lines ? implode("\n", $lines) . "\n" : '');
    }
}

/* ---- Coda ------------------------------------------------------------- */

/**
 * Accoda la cattura di un sito: una prova di tipo 'site' più le sue opzioni.
 * L'indirizzo deve aver già superato validate_public_url().
 * @return array{0:string,1:bool}  [short, avviata subito]
 */
function site_enqueue(PDO $pdo, string $url, array $o, string $source = 'web', ?string $parent = null): array
{
    $short = safe_short(7);
    $host = crawl_host($url);
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO snapshots(short, url, title, status, source, kind, parent_short) VALUES(?,?,?,'pending',?,'site',?)")
        ->execute([$short, $url, "Sito · $host", mb_substr($source, 0, 64), $parent]);
    $pdo->prepare('INSERT INTO site_jobs(short, url, options) VALUES(?,?,?)')
        ->execute([$short, $url, json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    $pdo->commit();
    $started = with_queue_lock(fn() => spawn_worker_locked($short, $url), fn() => false);
    return [$short, $started];
}
