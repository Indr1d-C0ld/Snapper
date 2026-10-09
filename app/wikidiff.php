<?php
declare(strict_types=1);

/* =========================================================================
 * Snapper – motore di confronto fra revisioni di Wikipedia.  Solo include.
 * Usato da wikicmp.php (banco di confronto) e da wiki-worker.php (export).
 *
 * Il confronto è a due livelli, come quello di MediaWiki: prima i blocchi
 * (paragrafi, voci di elenco, righe di tabella; righe del wikitesto), poi le
 * parole dentro i blocchi cambiati. Così le sequenze restano corte anche per
 * voci molto lunghe, e il risultato si legge come un testo, non come una
 * cascata di caratteri.
 *
 * L'algoritmo è quello di Myers (differenza minima), nella variante a spazio
 * lineare con ricerca bidirezionale ("bisect"), con un tetto di tempo oltre il
 * quale un tratto viene dato come sostituito per intero invece di bloccare
 * la pagina.
 * =======================================================================*/

const WD_TIME_BUDGET = 3.0;   // secondi per un intero confronto

/* ---- Differenza minima fra due sequenze ------------------------------- */

/**
 * @param string[] $a  chiavi di confronto
 * @param string[] $b
 * @return array<int,array{0:string,1:int,2:int}>  ['=', i, j] | ['-', i, -1] | ['+', -1, j]
 */
function wd_diff(array $a, array $b, ?float $deadline = null): array
{
    $deadline ??= microtime(true) + WD_TIME_BUDGET;
    // chiavi -> interi: confronti più rapidi e meno memoria
    $map = [];
    $ia = [];
    foreach ($a as $k) { $ia[] = $map[$k] ??= count($map); }
    $ib = [];
    foreach ($b as $k) { $ib[] = $map[$k] ??= count($map); }
    $ops = [];
    wd_rec($ia, 0, count($ia), $ib, 0, count($ib), $ops, $deadline);
    return $ops;
}

function wd_rec(array &$a, int $aLo, int $aHi, array &$b, int $bLo, int $bHi, array &$ops, float $deadline): void
{
    // prefisso comune
    while ($aLo < $aHi && $bLo < $bHi && $a[$aLo] === $b[$bLo]) {
        $ops[] = ['=', $aLo++, $bLo++];
    }
    // suffisso comune (aggiunto in coda, nell'ordine giusto)
    $suf = 0;
    while ($aLo < $aHi && $bLo < $bHi && $a[$aHi - 1] === $b[$bHi - 1]) {
        $aHi--; $bHi--; $suf++;
    }
    if ($aLo === $aHi) {
        for ($j = $bLo; $j < $bHi; $j++) $ops[] = ['+', -1, $j];
    } elseif ($bLo === $bHi) {
        for ($i = $aLo; $i < $aHi; $i++) $ops[] = ['-', $i, -1];
    } else {
        // tratti grandi senza alcun elemento in comune: inutile cercare
        $mid = null;
        $big = ($aHi - $aLo) * ($bHi - $bLo) > 250_000;
        if (!$big || array_intersect_key(array_flip(array_slice($a, $aLo, $aHi - $aLo)), array_flip(array_slice($b, $bLo, $bHi - $bLo)))) {
            $mid = microtime(true) < $deadline ? wd_bisect($a, $aLo, $aHi, $b, $bLo, $bHi, $deadline) : null;
        }
        if ($mid === null) {
            // tempo scaduto o nessun punto in comune: sostituzione intera
            for ($i = $aLo; $i < $aHi; $i++) $ops[] = ['-', $i, -1];
            for ($j = $bLo; $j < $bHi; $j++) $ops[] = ['+', -1, $j];
        } else {
            [$x, $y] = $mid;
            wd_rec($a, $aLo, $x, $b, $bLo, $y, $ops, $deadline);
            wd_rec($a, $x, $aHi, $b, $y, $bHi, $ops, $deadline);
        }
    }
    for ($k = 0; $k < $suf; $k++) {
        $ops[] = ['=', $aHi + $k, $bHi + $k];
    }
}

/**
 * Trova il punto d'incontro delle ricerche in avanti e all'indietro
 * (Myers 1986, nella forma di diff-match-patch). Restituisce gli indici
 * assoluti in cui spezzare il problema, o null.
 */
function wd_bisect(array &$a, int $aLo, int $aHi, array &$b, int $bLo, int $bHi, float $deadline): ?array
{
    $n = $aHi - $aLo;
    $m = $bHi - $bLo;
    $maxD = intdiv($n + $m + 1, 2);
    $off = $maxD;
    $len = 2 * $maxD + 2;
    $v1 = array_fill(0, $len, -1);
    $v2 = array_fill(0, $len, -1);
    $v1[$off + 1] = 0;
    $v2[$off + 1] = 0;
    $delta = $n - $m;
    $front = ($delta % 2) !== 0;
    $k1s = $k1e = $k2s = $k2e = 0;
    for ($d = 0; $d < $maxD; $d++) {
        if (($d & 31) === 31 && microtime(true) > $deadline) {
            return null;
        }
        for ($k1 = -$d + $k1s; $k1 <= $d - $k1e; $k1 += 2) {
            $o1 = $off + $k1;
            $x1 = ($k1 === -$d || ($k1 !== $d && $v1[$o1 - 1] < $v1[$o1 + 1])) ? $v1[$o1 + 1] : $v1[$o1 - 1] + 1;
            $y1 = $x1 - $k1;
            while ($x1 < $n && $y1 < $m && $a[$aLo + $x1] === $b[$bLo + $y1]) { $x1++; $y1++; }
            $v1[$o1] = $x1;
            if ($x1 > $n) { $k1e += 2; }
            elseif ($y1 > $m) { $k1s += 2; }
            elseif ($front) {
                $o2 = $off + $delta - $k1;
                if ($o2 >= 0 && $o2 < $len && $v2[$o2] !== -1 && $x1 >= $n - $v2[$o2]) {
                    return [$aLo + $x1, $bLo + $y1];
                }
            }
        }
        for ($k2 = -$d + $k2s; $k2 <= $d - $k2e; $k2 += 2) {
            $o2 = $off + $k2;
            $x2 = ($k2 === -$d || ($k2 !== $d && $v2[$o2 - 1] < $v2[$o2 + 1])) ? $v2[$o2 + 1] : $v2[$o2 - 1] + 1;
            $y2 = $x2 - $k2;
            while ($x2 < $n && $y2 < $m && $a[$aLo + $n - $x2 - 1] === $b[$bLo + $m - $y2 - 1]) { $x2++; $y2++; }
            $v2[$o2] = $x2;
            if ($x2 > $n) { $k2e += 2; }
            elseif ($y2 > $m) { $k2s += 2; }
            elseif (!$front) {
                $o1 = $off + $delta - $k2;
                if ($o1 >= 0 && $o1 < $len && $v1[$o1] !== -1) {
                    $x1 = $v1[$o1];
                    $y1 = $off + $x1 - $o1;
                    if ($x1 >= $n - $x2) {
                        return [$aLo + $x1, $bLo + $y1];
                    }
                }
            }
        }
    }
    return null;
}

/* ---- Unità di confronto ----------------------------------------------- */

/**
 * Spezza un testo in unità (parole o frasi) che, concatenate, lo ricompongono
 * esattamente. Ogni unità ha una chiave di confronto che tiene conto delle
 * opzioni: spazi e punteggiatura possono essere ignorati senza sparire dalla
 * visualizzazione.
 * @return array<int,array{0:string,1:string}>  [chiave, testo]
 */
function wd_units(string $s, array $o): array
{
    if (($o['gran'] ?? 'word') === 'sentence') {
        $parts = preg_split('/(?<=[.!?…;:])(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$s];
        $out = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $txt = $parts[$i] . ($parts[$i + 1] ?? '');
            if ($txt === '') continue;
            $out[] = [wd_norm($parts[$i], $o), $txt];
        }
        return $out;
    }
    preg_match_all('/[\p{L}\p{N}\p{M}]+(?:[\'’][\p{L}\p{N}\p{M}]+)*|\s+|./us', $s, $m);
    $out = [];
    foreach ($m[0] as $t) {
        $isSpace = trim($t) === '';
        $isPunct = !$isSpace && !preg_match('/[\p{L}\p{N}]/u', $t);
        $attach = $out && (($isSpace && !empty($o['ws'])) || ($isPunct && !empty($o['punct'])));
        if ($attach) {
            $out[count($out) - 1][1] .= $t;          // si mostra, non si confronta
        } elseif ($isSpace && !empty($o['ws'])) {
            $out[] = ['', $t];                        // spazio iniziale
        } else {
            $out[] = [$isSpace ? ' ' . $t : $t, $t];
        }
    }
    return $out;
}

/** chiave di confronto di un blocco o di una frase */
function wd_norm(string $s, array $o): string
{
    if (!empty($o['cites'])) {
        $s = wd_norm_cites($s);
    }
    if (!empty($o['punct'])) {
        $s = preg_replace('/[^\p{L}\p{N}\p{M}\s]+/u', '', $s);
    }
    if (!empty($o['ws'])) {
        $s = trim(preg_replace('/\s+/u', ' ', $s));
    }
    return $s;
}

/**
 * Citazioni riformattate ma uguali nella sostanza: parametri dei template
 * di citazione riordinati e spazi normalizzati. Serve solo al confronto.
 */
function wd_norm_cites(string $s): string
{
    return preg_replace_callback('/\{\{\s*(cit[ae][^|{}]*|cite[^|{}]*)\|([^{}]*)\}\}/iu', function ($m) {
        $params = array_map(fn($p) => preg_replace('/\s+/u', ' ', trim($p)), explode('|', $m[2]));
        $params = array_values(array_filter($params, fn($p) => $p !== '' && !preg_match('/^[^=]+=\s*$/u', $p)));
        sort($params, SORT_STRING);
        return '{{' . mb_strtolower(trim($m[1])) . '|' . implode('|', $params) . '}}';
    }, $s);
}

function wd_words_of(string $s): array
{
    preg_match_all('/[\p{L}\p{N}\p{M}]+/u', mb_strtolower($s), $m);
    return $m[0];
}

/** somiglianza fra due testi (Dice sulle parole), 0..1 */
function wd_sim(string $x, string $y): float
{
    $a = array_count_values(wd_words_of($x));
    $b = array_count_values(wd_words_of($y));
    $na = array_sum($a);
    $nb = array_sum($b);
    if ($na + $nb === 0) {
        return $x === $y ? 1.0 : 0.0;
    }
    $c = 0;
    foreach ($a as $w => $n) {
        if (isset($b[$w])) $c += min($n, $b[$w]);
    }
    return 2 * $c / ($na + $nb);
}

function wd_count_words(string $s): int
{
    return preg_match_all('/[\p{L}\p{N}]+/u', $s);
}

/* ---- Confronto inline fra due blocchi --------------------------------- */

/**
 * @return array{html:string,left:string,right:string,add:int,del:int}
 */
function wd_inline(string $x, string $y, array $o, float $deadline): array
{
    $ua = wd_units($x, $o);
    $ub = wd_units($y, $o);
    $ops = wd_diff(array_column($ua, 0), array_column($ub, 0), $deadline);
    $html = $left = $right = '';
    $add = $del = 0;
    $run = ['-' => '', '+' => ''];
    $flush = function () use (&$run, &$html, &$left, &$right) {
        if ($run['-'] !== '') {
            $t = '<del>' . h2($run['-']) . '</del>';
            $html .= $t; $left .= $t;
        }
        if ($run['+'] !== '') {
            $t = '<ins>' . h2($run['+']) . '</ins>';
            $html .= $t; $right .= $t;
        }
        $run = ['-' => '', '+' => ''];
    };
    foreach ($ops as [$op, $i, $j]) {
        if ($op === '=') {
            $flush();
            $t = h2($ub[$j][1]);
            $html .= $t; $right .= $t; $left .= h2($ua[$i][1]);
        } elseif ($op === '-') {
            $run['-'] .= $ua[$i][1];
            $del += wd_count_words($ua[$i][1]);
        } else {
            $run['+'] .= $ub[$j][1];
            $add += wd_count_words($ub[$j][1]);
        }
    }
    $flush();
    return ['html' => $html, 'left' => $left, 'right' => $right, 'add' => $add, 'del' => $del];
}

function h2(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---- Confronto a blocchi ---------------------------------------------- */

/**
 * Confronta due liste di blocchi.
 * @param array<int,array{t:string,kind?:string,sec?:string}> $A
 * @param array<int,array{t:string,kind?:string,sec?:string}> $B
 * @return array{rows:array,stats:array}
 *   righe: ['eq',a,b] | ['del',a] | ['ins',b] | ['mod',a,b,inline] | ['mvf',a,id] | ['mvt',b,id,inline|null]
 */
function wd_blocks(array $A, array $B, array $o): array
{
    $deadline = microtime(true) + WD_TIME_BUDGET;
    $ka = array_map(fn($x) => wd_norm($x['t'], $o), $A);
    $kb = array_map(fn($x) => wd_norm($x['t'], $o), $B);
    $ops = wd_diff($ka, $kb, $deadline);

    // tratti di modifiche consecutive -> coppie di blocchi simili
    $rows = [];
    $dels = $inss = [];
    $flushHunk = function () use (&$dels, &$inss, &$rows, $A, $B, $ka, $kb) {
        $used = [];
        $last = -1;
        $pairs = [];
        foreach ($dels as $i) {
            $best = null;
            $bestS = 0.45;
            foreach ($inss as $p => $j) {
                if ($p <= $last || isset($used[$p])) continue;
                $s = wd_sim($A[$i]['t'], $B[$j]['t']);
                if ($s > $bestS) { $bestS = $s; $best = $p; }
            }
            if ($best !== null) {
                $pairs[$i] = $inss[$best];
                $used[$best] = true;
                $last = $best;
            }
        }
        // ordine di presentazione: segue il testo nuovo, con le rimozioni al loro posto
        $di = 0;
        $pi = 0;
        $dlist = $dels;
        $ilist = $inss;
        while ($di < count($dlist) || $pi < count($ilist)) {
            $i = $dlist[$di] ?? null;
            $j = $ilist[$pi] ?? null;
            if ($i !== null && !isset($pairs[$i])) { $rows[] = ['del', $i]; $di++; continue; }
            if ($j !== null && !in_array($j, $pairs, true)) { $rows[] = ['ins', $j]; $pi++; continue; }
            if ($i !== null && isset($pairs[$i]) && $pairs[$i] === $j) { $rows[] = ['mod', $i, $j]; $di++; $pi++; continue; }
            // coppia sfasata: avanza dal lato che la precede
            if ($j !== null) { $rows[] = ['ins', $j]; $pi++; } else { $rows[] = ['del', $i]; $di++; }
        }
        $dels = $inss = [];
    };
    foreach ($ops as [$op, $i, $j]) {
        if ($op === '=') {
            if ($dels || $inss) $flushHunk();
            $rows[] = ['eq', $i, $j];
        } elseif ($op === '-') {
            $dels[] = $i;
        } else {
            $inss[] = $j;
        }
    }
    if ($dels || $inss) $flushHunk();

    // spostamenti: un blocco tolto qui e ricomparso altrove, identico o quasi
    $freeDel = [];
    $freeIns = [];
    foreach ($rows as $r => $row) {
        if ($row[0] === 'del' && mb_strlen($ka[$row[1]]) >= 20) $freeDel[$r] = $row[1];
        if ($row[0] === 'ins' && mb_strlen($kb[$row[1]]) >= 20) $freeIns[$r] = $row[1];
    }
    $mid = 0;
    foreach ($freeDel as $rd => $i) {
        $best = null;
        $bestS = 0.85;
        foreach ($freeIns as $ri => $j) {
            $s = $ka[$i] === $kb[$j] ? 1.0 : wd_sim($A[$i]['t'], $B[$j]['t']);
            if ($s > $bestS || ($s === 1.0 && $bestS < 1.0)) { $bestS = $s; $best = $ri; }
            if ($s === 1.0) break;
        }
        if ($best !== null) {
            $mid++;
            $j = $freeIns[$best];
            $rows[$rd] = ['mvf', $i, $mid];
            $rows[$best] = ['mvt', $j, $mid, $i];
            unset($freeIns[$best]);
        }
    }

    // contenuto inline e statistiche
    $st = ['add' => 0, 'del' => 0, 'mod' => 0, 'ins_blocks' => 0, 'del_blocks' => 0, 'moved' => $mid, 'changes' => 0];
    foreach ($rows as $r => $row) {
        switch ($row[0]) {
            case 'mod':
                $in = wd_inline($A[$row[1]]['t'], $B[$row[2]]['t'], $o, $deadline);
                $rows[$r][3] = $in;
                $st['add'] += $in['add'];
                $st['del'] += $in['del'];
                $st['mod']++;
                $st['changes']++;
                break;
            case 'del':
                $st['del'] += wd_count_words($A[$row[1]]['t']);
                $st['del_blocks']++;
                $st['changes']++;
                break;
            case 'ins':
                $st['add'] += wd_count_words($B[$row[1]]['t']);
                $st['ins_blocks']++;
                $st['changes']++;
                break;
            case 'mvt':
                $src = $A[$row[3]]['t'];
                $rows[$r][4] = $ka[$row[3]] === $kb[$row[1]] ? null : wd_inline($src, $B[$row[1]]['t'], $o, $deadline);
                $st['changes']++;
                break;
        }
    }
    $st['timeout'] = microtime(true) > $deadline;
    return ['rows' => $rows, 'stats' => $st];
}

/* ---- Estrazione dei blocchi ------------------------------------------- */

/** carica il contenuto di una revisione archiviata (HTML trasformato da wiki-worker) */
function wd_load_content(string $revDir): ?Dom\Element
{
    $f = $revDir . '/index.html';
    if (!is_file($f)) {
        return null;
    }
    $doc = Dom\HTMLDocument::createFromFile($f, LIBXML_NOERROR, 'UTF-8');
    return $doc->getElementById('mw-content-text');
}

/** figli elemento (in PHP 8.4 Dom\Element non ha la proprietà children) */
function wd_kids(Dom\Element $el): array
{
    $out = [];
    for ($c = $el->firstElementChild; $c !== null; $c = $c->nextElementSibling) {
        $out[] = $c;
    }
    return $out;
}

function wd_has_class(Dom\Element $e, string ...$cls): bool
{
    foreach ($cls as $c) {
        if ($e->classList->contains($c)) return true;
    }
    return false;
}

/**
 * Parti escluse dal confronto del testo perché analizzate a parte (infobox,
 * note, navbox) o generate da sole (indice). I segni di nota [1] [2] cambiano
 * numero a ogni nota aggiunta: di norma si ignorano.
 */
function wd_skip(Dom\Element $e, bool $keepRefMarks = false): bool
{
    $tag = strtolower($e->localName);
    if ($tag === 'style' || $tag === 'script') return true;
    if ($tag === 'table' && wd_has_class($e, 'infobox', 'sinottico', 'infobox_v2', 'infobox_v3')) return true;
    if ($tag === 'ol' && $e->classList->contains('references')) return true;
    if (!$keepRefMarks && $tag === 'sup' && $e->classList->contains('reference')) return true;
    if (wd_has_class($e, 'navbox', 'navbox-styles', 'reflist', 'mw-references-wrap', 'toc', 'mw-editsection')) return true;
    return $e->getAttribute('id') === 'toc';
}

/** testo di un nodo, saltando i discendenti per cui $skip è vero (senza modificare il documento) */
function wd_text(Dom\Node $n, callable $skip): string
{
    $s = '';
    for ($c = $n->firstChild; $c !== null; $c = $c->nextSibling) {
        if ($c instanceof Dom\Element) {
            if (!$skip($c)) $s .= wd_text($c, $skip);
        } elseif ($c instanceof Dom\Text) {
            $s .= $c->data;
        }
    }
    return $s;
}

/**
 * Blocchi leggibili della revisione: titoli, paragrafi, voci di elenco,
 * righe di tabella, didascalie.
 * @return array<int,array{t:string,kind:string,sec:string}>
 */
function wd_text_blocks(Dom\Element $root, bool $keepRefMarks = false): array
{
    $skip = fn(Dom\Element $e) => wd_skip($e, $keepRefMarks);
    $ownSkip = fn(Dom\Element $e) => wd_skip($e, $keepRefMarks)
        || in_array(strtolower($e->localName), ['ul', 'ol', 'dl', 'table'], true);
    $out = [];
    $sec = '';
    $walk = function (Dom\Element $el) use (&$walk, &$out, &$sec, $skip, $ownSkip): void {
        foreach (wd_kids($el) as $c) {
            if ($skip($c)) continue;
            $tag = strtolower($c->localName);
            if (preg_match('/^h[1-6]$/', $tag) || ($tag === 'div' && $c->classList->contains('mw-heading'))) {
                $t = wd_clean(wd_text($c, $skip));
                if ($t !== '') {
                    $sec = $t;
                    $out[] = ['t' => $t, 'kind' => 'h', 'sec' => $sec];
                }
                continue;
            }
            if ($tag === 'tr') {
                $cells = [];
                foreach (wd_kids($c) as $cell) {
                    if ($skip($cell)) continue;
                    $ct = wd_clean(wd_text($cell, $skip));
                    if ($ct !== '') $cells[] = $ct;
                }
                if ($cells) $out[] = ['t' => implode(' | ', $cells), 'kind' => 'tr', 'sec' => $sec];
                continue;
            }
            if (in_array($tag, ['p', 'li', 'dd', 'dt', 'caption', 'figcaption', 'pre', 'blockquote'], true)) {
                // il testo proprio del blocco; elenchi e tabelle annidati diventano blocchi a sé
                $t = wd_clean(wd_text($c, $ownSkip));
                if ($t !== '') $out[] = ['t' => $t, 'kind' => $tag, 'sec' => $sec];
                foreach (wd_kids($c) as $nested) {
                    if (in_array(strtolower($nested->localName), ['ul', 'ol', 'dl', 'table'], true) && !$skip($nested)) {
                        $walk($nested);
                    }
                }
                continue;
            }
            $walk($c);
        }
    };
    $walk($root);
    return $out;
}

function wd_clean(string $s): string
{
    return trim(preg_replace('/[ \t\x{00A0}\r\n]+/u', ' ', $s));
}

/** righe del wikitesto come blocchi */
function wd_wikitext_blocks(string $wt): array
{
    $out = [];
    $sec = '';
    foreach (preg_split('/\r?\n/', $wt) as $line) {
        if (preg_match('/^(={2,6})\s*(.*?)\s*\1\s*$/u', $line, $m)) {
            $sec = $m[2];
        }
        $out[] = ['t' => $line, 'kind' => 'line', 'sec' => $sec];
    }
    return $out;
}

/* ---- Analisi strutturale ---------------------------------------------- */

function wd_parse_json(string $revDir): array
{
    $j = json_decode((string)@file_get_contents($revDir . '/parse.json'), true);
    return is_array($j) ? $j : [];
}

/** differenza fra due insiemi (con molteplicità) */
function wd_set_diff(array $a, array $b): array
{
    $ca = array_count_values($a);
    $cb = array_count_values($b);
    $add = $rem = [];
    foreach ($cb as $k => $n) {
        for ($i = 0; $i < $n - ($ca[$k] ?? 0); $i++) $add[] = (string)$k;
    }
    foreach ($ca as $k => $n) {
        for ($i = 0; $i < $n - ($cb[$k] ?? 0); $i++) $rem[] = (string)$k;
    }
    return ['add' => $add, 'rem' => $rem, 'same' => count($a) - count($rem)];
}

function wd_host(string $url): string
{
    $h = strtolower((string)parse_url(str_starts_with($url, '//') ? 'https:' . $url : $url, PHP_URL_HOST));
    return preg_replace('/^www\d?\./', '', $h);
}

/** note e fonti della revisione: testo, collegamenti, domini */
function wd_refs(Dom\Element $root): array
{
    $out = [];
    $noBack = fn(Dom\Element $e) => $e->classList->contains('mw-cite-backlink') || strtolower($e->localName) === 'style';
    foreach ($root->querySelectorAll('ol.references > li') as $li) {
        $urls = [];
        foreach ($li->querySelectorAll('a[data-href]') as $a) {
            $u = (string)$a->getAttribute('data-href');
            if (preg_match('#^https?://#i', $u) && !preg_match('#^https?://[a-z-]+\.(m\.)?wikipedia\.org/#i', $u)) {
                $urls[] = $u;
            }
        }
        $t = wd_clean(ltrim(wd_text($li, $noBack), " ^↑\u{2191}"));
        if ($t !== '') {
            $out[] = ['t' => $t, 'urls' => array_values(array_unique($urls))];
        }
    }
    return $out;
}

/** campi dell'infobox (sinottico): etichetta => valore */
function wd_infobox(Dom\Element $root): array
{
    $box = $root->querySelector('table.infobox, table.sinottico, table.infobox_v2, table.infobox_v3');
    if (!$box) {
        return [];
    }
    $skip = fn(Dom\Element $e) => in_array(strtolower($e->localName), ['style', 'script'], true)
        || (strtolower($e->localName) === 'sup' && $e->classList->contains('reference'));
    $f = [];
    foreach ($box->querySelectorAll('tr') as $tr) {
        $th = $td = null;
        foreach (wd_kids($tr) as $cell) {
            $tag = strtolower($cell->localName);
            if ($tag === 'th' && !$th) $th = $cell;
            if ($tag === 'td' && !$td) $td = $cell;
        }
        if (!$th || !$td) continue;
        $k = wd_clean(wd_text($th, $skip));
        $v = wd_clean(wd_text($td, $skip));
        if ($k === '') continue;
        $base = $k;
        for ($n = 2; isset($f[$k]); $n++) $k = "$base ($n)";
        $f[$k] = $v;
    }
    return $f;
}

/** collegamenti interni del wikitesto, normalizzati */
function wd_wikilinks(string $wt): array
{
    preg_match_all('/\[\[\s*([^\]\|#\n]+)/u', $wt, $m);
    $out = [];
    foreach ($m[1] as $t) {
        $t = trim(str_replace('_', ' ', $t));
        if ($t === '' || preg_match('/^(file|immagine|image|categoria|category|media|wikt|wikisource|commons|[a-z]{2,3}(-[a-z]+)?)\s*:/iu', $t)) {
            continue;
        }
        $out[] = mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }
    return $out;
}

/** template che segnalano problemi della voce (it e en) */
const WD_MAINT = ['F', 'Senza fonti', 'Citazione necessaria', 'Cn', 'NN', 'P', 'A', 'W', 'C', 'S', 'E', 'O', 'U', 'T',
    'Controllare', 'Aggiornare', 'Torna a', 'Correggere', 'Avvisounicode', 'Bozza',
    'Citation needed', 'Unreferenced', 'More citations needed', 'Refimprove', 'POV', 'Disputed', 'Cleanup',
    'Neutrality', 'Original research', 'Update', 'Multiple issues', 'Dubious', 'Who', 'When'];

/**
 * Struttura a confronto: sezioni, note, infobox, categorie, collegamenti,
 * immagini, template.
 */
function wd_structure(array $pa, array $pb, ?Dom\Element $ca, ?Dom\Element $cb, string $wa, string $wb): array
{
    $r = [];

    // sezioni: aggiunte, rimosse, rinominate, spostate
    $secs = fn(array $p) => array_map(fn($s) => ['t' => wd_clean(strip_tags((string)$s['line'])), 'l' => (int)$s['level']], $p['sections'] ?? []);
    $sa = $secs($pa);
    $sb = $secs($pb);
    $ta = array_column($sa, 't');
    $tb = array_column($sb, 't');
    $ops = wd_diff($ta, $tb);
    $inLcs = [];
    foreach ($ops as [$op, $i, $j]) {
        if ($op === '=') $inLcs[$ta[$i]] = true;
    }
    $sd = wd_set_diff($ta, $tb);
    $moved = [];
    foreach (array_intersect($ta, $tb) as $t) {
        if (!isset($inLcs[$t])) $moved[$t] = true;
    }
    $renamed = [];
    // rinomina: stessa posizione relativa e stesso livello, titolo diverso
    $remL = $sd['rem'];
    $addL = $sd['add'];
    foreach ($remL as $ri => $old) {
        $pos = array_search($old, $ta, true);
        foreach ($addL as $ai => $new) {
            $posB = array_search($new, $tb, true);
            $lvA = $sa[$pos]['l'] ?? 0;
            $lvB = $sb[$posB]['l'] ?? -1;
            $prevA = $pos > 0 ? $ta[$pos - 1] : '';
            $prevB = $posB > 0 ? $tb[$posB - 1] : '';
            if ($lvA === $lvB && ($prevA === $prevB || wd_sim($old, $new) >= 0.5)) {
                $renamed[] = [$old, $new];
                unset($remL[$ri], $addL[$ai]);
                break;
            }
        }
    }
    $r['sections'] = ['add' => array_values($addL), 'rem' => array_values($remL), 'renamed' => $renamed,
                      'moved' => array_keys($moved), 'na' => count($ta), 'nb' => count($tb)];

    // note e fonti
    $ra = $ca ? wd_refs($ca) : [];
    $rb = $cb ? wd_refs($cb) : [];
    $keyR = fn($x) => mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $x['t']));
    $mapA = [];
    foreach ($ra as $x) $mapA[$keyR($x)] = $x;
    $mapB = [];
    foreach ($rb as $x) $mapB[$keyR($x)] = $x;
    $refRem = array_values(array_diff_key($mapA, $mapB));
    $refAdd = array_values(array_diff_key($mapB, $mapA));
    $domA = array_count_values(array_map('wd_host', array_merge(...array_column($ra ?: [['urls' => []]], 'urls'))));
    $domB = array_count_values(array_map('wd_host', array_merge(...array_column($rb ?: [['urls' => []]], 'urls'))));
    $r['refs'] = [
        'na' => count($ra), 'nb' => count($rb), 'add' => $refAdd, 'rem' => $refRem,
        'dom_gone' => array_keys(array_diff_key($domA, $domB)),
        'dom_new'  => array_keys(array_diff_key($domB, $domA)),
    ];

    // infobox
    $ia = $ca ? wd_infobox($ca) : [];
    $ib = $cb ? wd_infobox($cb) : [];
    $chg = [];
    foreach ($ia as $k => $v) {
        if (array_key_exists($k, $ib) && $ib[$k] !== $v) $chg[$k] = [$v, $ib[$k]];
    }
    $r['infobox'] = ['present' => $ia || $ib, 'add' => array_diff_key($ib, $ia), 'rem' => array_diff_key($ia, $ib), 'chg' => $chg];

    // categorie, collegamenti, immagini, template
    $cats = fn(array $p, bool $hidden) => array_map(fn($c) => str_replace('_', ' ', (string)$c['category']),
        array_filter($p['categories'] ?? [], fn($c) => !empty($c['hidden']) === $hidden));
    $r['categories'] = wd_set_diff(array_unique($cats($pa, false)), array_unique($cats($pb, false)));
    $r['categories_hidden'] = wd_set_diff(array_unique($cats($pa, true)), array_unique($cats($pb, true)));
    $r['links'] = wd_set_diff(array_values(array_unique(wd_wikilinks($wa))), array_values(array_unique(wd_wikilinks($wb))));
    $ea = array_values(array_unique($pa['externallinks'] ?? []));
    $eb = array_values(array_unique($pb['externallinks'] ?? []));
    $r['extlinks'] = wd_set_diff($ea, $eb);
    $r['images'] = wd_set_diff(array_values(array_unique($pa['images'] ?? [])), array_values(array_unique($pb['images'] ?? [])));
    $tpl = fn(array $p) => array_values(array_unique(array_map(fn($t) => preg_replace('/^[^:]+:/u', '', (string)$t['title']), $p['templates'] ?? [])));
    $td = wd_set_diff($tpl($pa), $tpl($pb));
    $td['maint_add'] = array_values(array_intersect($td['add'], WD_MAINT));
    $td['maint_rem'] = array_values(array_intersect($td['rem'], WD_MAINT));
    $r['templates'] = $td;
    return $r;
}

/* ---- Cronologia completa e dinamiche ---------------------------------- */

const WD_HISTORY_MAX = 5000;      // revisioni lette al massimo per voce
const WD_HISTORY_TTL = 6 * 3600;  // validità della copia locale

/**
 * Cronologia della voce (dalla più vecchia), con una copia locale per non
 * interrogare Wikipedia a ogni pagina.
 * @return array{rows:array,fetched:int,truncated:bool}
 */
function wd_history(string $lang, int $pageid, bool $refresh = false): array
{
    $dir = DATA_DIR . '/.wikicache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $f = "$dir/$lang-$pageid.json";
    if (!$refresh && is_file($f) && filemtime($f) > time() - WD_HISTORY_TTL) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j) && isset($j['rows'])) {
            return $j;
        }
    }
    $rows = [];
    $cont = null;
    do {
        [$chunk, $cont] = wiki_history($lang, $pageid, [], $cont, 499);
        foreach ($chunk as $r) {
            $rows[] = $r;
        }
        if ($cont !== null) usleep(120_000);
    } while ($cont !== null && count($rows) < WD_HISTORY_MAX);
    $truncated = $cont !== null;
    $rows = array_reverse(wiki_annotate_chrono($rows));    // dalla più vecchia
    $out = ['rows' => $rows, 'fetched' => time(), 'truncated' => $truncated];
    @file_put_contents($f, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/**
 * Modifiche fra due date (estremi inclusi a giorno intero), dalla più vecchia:
 * se la copia locale della cronologia è fresca la si usa, altrimenti basta una
 * richiesta limitata all'intervallo invece dell'intera cronologia.
 * @return array{rows:array,more:bool}
 */
function wd_between(string $lang, int $pageid, string $tsA, string $tsB): array
{
    $f = DATA_DIR . "/.wikicache/$lang-$pageid.json";
    if (is_file($f) && filemtime($f) > time() - WD_HISTORY_TTL) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j) && isset($j['rows']) && (!$j['truncated'] || ($j['rows'][0]['ts'] ?? '9') <= $tsA)) {
            return ['rows' => array_values(array_filter($j['rows'], fn($r) => $r['ts'] > $tsA && $r['ts'] <= $tsB)), 'more' => false];
        }
    }
    [$rows, $cont] = wiki_history($lang, $pageid, ['from' => substr($tsA, 0, 10), 'to' => substr($tsB, 0, 10)], null, 499);
    $rows = array_reverse(wiki_annotate_chrono($rows));
    return ['rows' => array_values(array_filter($rows, fn($r) => $r['ts'] > $tsA && $r['ts'] <= $tsB)), 'more' => $cont !== null];
}

/** annotazioni (variazioni, revert, ripristini) su righe dalla più recente */
function wiki_annotate_chrono(array $newestFirst): array
{
    $n = count($newestFirst);
    // dalla più vecchia: l'ultima occorrenza precedente di ogni sha1
    $chrono = array_reverse($newestFirst);
    $seen = [];
    foreach ($chrono as $k => $r) {
        $prev = $chrono[$k - 1] ?? null;
        $chrono[$k]['delta'] = $prev ? $r['size'] - $prev['size'] : $r['size'];
        $chrono[$k]['is_revert'] = (bool)array_intersect($r['tags'], WIKI_REVERT_TAGS);
        $chrono[$k]['was_reverted'] = in_array('mw-reverted', $r['tags'], true);
        $chrono[$k]['restores'] = null;
        if ($r['sha1'] !== null) {
            if (isset($seen[$r['sha1']]) && $seen[$r['sha1']] < $k - 1) {
                $j = $seen[$r['sha1']];
                $chrono[$k]['restores'] = ['revid' => $chrono[$j]['revid'], 'ts' => $chrono[$j]['ts'], 'idx' => $j];
            }
            $seen[$r['sha1']] = $k;
        }
    }
    return array_reverse($chrono);
}

/**
 * Alternanze fra versioni identiche (stesso sha1) ravvicinate nel tempo: il
 * segno, a livello di revisione, di una guerra di modifica.
 */
function wd_edit_wars(array $rows, int $windowH = 72, int $minRestores = 3): array
{
    $events = [];
    foreach ($rows as $k => $r) {
        if (!$r['restores']) continue;
        $undone = [];
        for ($x = $r['restores']['idx'] + 1; $x < $k; $x++) {
            $undone[] = $rows[$x]['user'] ?? '(nascosto)';
        }
        $events[] = ['k' => $k, 't' => strtotime($r['ts'] . ' UTC'), 'by' => $r['user'] ?? '(nascosto)', 'undone' => $undone];
    }
    $eps = [];
    $cur = null;
    foreach ($events as $e) {
        if ($cur && $e['t'] - $cur['end'] <= $windowH * 3600) {
            $cur['ev'][] = $e;
            $cur['end'] = $e['t'];
        } else {
            if ($cur) $eps[] = $cur;
            $cur = ['start' => $e['t'], 'end' => $e['t'], 'ev' => [$e]];
        }
    }
    if ($cur) $eps[] = $cur;
    $out = [];
    foreach ($eps as $ep) {
        if (count($ep['ev']) < $minRestores) continue;
        $who = [];
        foreach ($ep['ev'] as $e) {
            $who[$e['by']]['restore'] = ($who[$e['by']]['restore'] ?? 0) + 1;
            foreach ($e['undone'] as $u) {
                $who[$u]['undone'] = ($who[$u]['undone'] ?? 0) + 1;
            }
        }
        $out[] = ['start' => $ep['start'], 'end' => $ep['end'], 'restores' => count($ep['ev']),
                  'first' => $ep['ev'][0]['k'], 'last' => end($ep['ev'])['k'], 'who' => $who];
    }
    return $out;
}

/** riepilogo per autore */
function wd_authors(array $rows): array
{
    $a = [];
    foreach ($rows as $r) {
        $u = $r['user'] ?? '(nascosto)';
        $x = $a[$u] ?? ['user' => $u, 'anon' => $r['anon'], 'edits' => 0, 'plus' => 0, 'minus' => 0, 'reverts' => 0,
                        'reverted' => 0, 'first' => $r['ts'], 'last' => $r['ts']];
        $x['edits']++;
        $d = (int)($r['delta'] ?? 0);
        $d > 0 ? $x['plus'] += $d : $x['minus'] += -$d;
        $x['reverts'] += ($r['is_revert'] || $r['restores']) ? 1 : 0;
        $x['reverted'] += $r['was_reverted'] ? 1 : 0;
        $x['last'] = $r['ts'];
        $a[$u] = $x;
    }
    usort($a, fn($p, $q) => $q['edits'] <=> $p['edits'] ?: strcmp($p['user'], $q['user']));
    return $a;
}

/**
 * Linea del tempo della dimensione, in SVG. Segna i revert, le revisioni
 * archiviate e, se dato, l'intervallo fra due revisioni.
 */
function wd_timeline_svg(array $rows, array $archived = [], ?array $range = null, int $w = 960, int $hgt = 180): string
{
    if (count($rows) < 2) {
        return '';
    }
    $t0 = strtotime($rows[0]['ts'] . ' UTC');
    $t1 = strtotime(end($rows)['ts'] . ' UTC');
    $span = max(1, $t1 - $t0);
    $maxS = max(1, max(array_column($rows, 'size')));
    $pl = 46; $pr = 10; $pt = 12; $pb = 26;
    $X = fn($ts) => $pl + ($w - $pl - $pr) * (strtotime($ts . ' UTC') - $t0) / $span;
    $Y = fn($s) => $pt + ($hgt - $pt - $pb) * (1 - $s / $maxS);
    $pts = [];
    $prevY = null;
    foreach ($rows as $r) {
        $x = round($X($r['ts']), 1);
        $y = round($Y($r['size']), 1);
        if ($prevY !== null) $pts[] = "$x,$prevY";    // a gradini: la dimensione resta fino alla modifica dopo
        $pts[] = "$x,$y";
        $prevY = $y;
    }
    $svg = '<svg class="wd-tl" viewBox="0 0 ' . $w . ' ' . $hgt . '" role="img" aria-label="Dimensione della voce nel tempo">';
    if ($range) {
        $xa = round($X($range[0]), 1);
        $xb = round($X($range[1]), 1);
        $svg .= '<rect class="wd-tl-range" x="' . min($xa, $xb) . '" y="' . $pt . '" width="' . max(2, abs($xb - $xa)) . '" height="' . ($hgt - $pt - $pb) . '"/>';
    }
    // griglia: 0, metà, massimo
    foreach ([0, 0.5, 1] as $f) {
        $y = round($Y($maxS * $f), 1);
        $svg .= '<line class="wd-tl-grid" x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . $y . '" y2="' . $y . '"/>'
              . '<text class="wd-tl-lab" x="' . ($pl - 6) . '" y="' . ($y + 3) . '" text-anchor="end">' . wd_kb((int)round($maxS * $f)) . '</text>';
    }
    $svg .= '<polyline class="wd-tl-line" points="' . implode(' ', $pts) . '"/>';
    foreach ($rows as $r) {
        if ($r['is_revert'] || $r['restores']) {
            $svg .= '<circle class="wd-tl-rv" cx="' . round($X($r['ts']), 1) . '" cy="' . round($Y($r['size']), 1) . '" r="2.4"><title>revert · '
                  . h2($r['ts']) . ' · ' . h2((string)$r['user']) . '</title></circle>';
        }
    }
    foreach ($rows as $r) {
        if (isset($archived[$r['revid']])) {
            $x = round($X($r['ts']), 1);
            $svg .= '<line class="wd-tl-arch" x1="' . $x . '" x2="' . $x . '" y1="' . ($hgt - $pb) . '" y2="' . ($hgt - $pb + 7) . '"><title>archiviata · ' . $r['revid'] . '</title></line>';
        }
    }
    $svg .= '<text class="wd-tl-lab" x="' . $pl . '" y="' . ($hgt - 6) . '">' . h2(substr($rows[0]['ts'], 0, 10)) . '</text>'
          . '<text class="wd-tl-lab" x="' . ($w - $pr) . '" y="' . ($hgt - 6) . '" text-anchor="end">' . h2(substr(end($rows)['ts'], 0, 10)) . '</text>';
    return $svg . '</svg>';
}

function wd_kb(int $b): string
{
    return $b >= 1024 ? round($b / 1024) . ' KB' : $b . ' B';
}

/* ---- WikiWho: chi ha scritto cosa -------------------------------------- */

const WIKIWHO_HOST = 'wikiwho-api.wmcloud.org';

/**
 * Attribuzione dei frammenti di una revisione (WikiWho), con copia locale.
 * Va chiamata solo dopo un consenso esplicito per la voce.
 */
function wd_wikiwho(string $lang, int $revid): array
{
    $dir = DATA_DIR . '/.wikiwho';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $f = "$dir/$lang-$revid.json";
    if (is_file($f)) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j)) return $j;
    }
    $url = 'https://' . WIKIWHO_HOST . '/' . rawurlencode($lang) . '/api/v1.0.0-beta/rev_content/rev_id/' . $revid
         . '/?o_rev_id=true&editor=true&token_id=false&out=true&in=true';
    [$code, $body] = wiki_http_get($url, 60_000_000, 120);
    $j = json_decode($body, true);
    if ($code !== 200 || !is_array($j) || empty($j['success'])) {
        throw new RuntimeException('WikiWho non ha restituito l\'attribuzione' . (is_array($j) && !empty($j['message']) ? ': ' . $j['message'] : " (HTTP $code)"));
    }
    $rev = $j['revisions'][0][(string)$revid] ?? (array_values($j['revisions'][0] ?? [])[0] ?? null);
    if (!$rev || !isset($rev['tokens'])) {
        throw new RuntimeException('risposta di WikiWho senza frammenti');
    }
    $toks = array_map(fn($t) => [(string)$t['str'], (int)$t['o_rev_id'], (string)$t['editor'], count($t['out'] ?? [])], $rev['tokens']);
    $out = ['revid' => $revid, 'fetched' => time(), 'tokens' => $toks];
    @file_put_contents($f, json_encode($out, JSON_UNESCAPED_UNICODE));
    return $out;
}

/**
 * Riallinea i frammenti di WikiWho (minuscoli, senza spazi) al wikitesto
 * originale. Restituisce i tratti [testo originale, indice del frammento|null].
 */
function wd_align_tokens(string $wt, array $tokens): array
{
    $chars = mb_str_split($wt);
    $low = array_map(fn($c) => mb_strtolower($c), $chars);
    $n = count($chars);
    $pos = 0;
    $spans = [];
    $buf = '';
    foreach ($tokens as $ti => $t) {
        $tc = mb_str_split($t[0]);
        if (!$tc) continue;
        // salta gli spazi e cerca il frammento poco più avanti, se serve
        $start = null;
        for ($p = $pos, $lim = min($n, $pos + 400); $p < $lim; $p++) {
            if ($low[$p] === $tc[0]) {
                $ok = true;
                for ($q = 1, $c = count($tc); $q < $c; $q++) {
                    if (($low[$p + $q] ?? null) !== $tc[$q]) { $ok = false; break; }
                }
                if ($ok) { $start = $p; break; }
            }
        }
        if ($start === null) {
            continue;                                   // frammento non ritrovato: resta senza attribuzione
        }
        if ($start > $pos) {
            $spans[] = [implode('', array_slice($chars, $pos, $start - $pos)), null];
        }
        $spans[] = [implode('', array_slice($chars, $start, count($tc))), $ti];
        $pos = $start + count($tc);
    }
    if ($pos < $n) {
        $spans[] = [implode('', array_slice($chars, $pos)), null];
    }
    return $spans;
}

/* ---- Confronto statico (esportazione del dossier) ---------------------- */

/** righe di un confronto in HTML statico, con il contesto e i tratti invariati compressi */
function wd_render_static(array $res, array $A, array $B, int $ctx = 2, bool $mono = false): string
{
    $rows = $res['rows'];
    $n = count($rows);
    $show = array_fill(0, $n, false);
    foreach ($rows as $i => $row) {
        if ($row[0] !== 'eq') {
            for ($k = max(0, $i - $ctx); $k <= min($n - 1, $i + $ctx); $k++) $show[$k] = true;
        }
    }
    $h = '<div class="wd-doc' . ($mono ? ' wd-mono' : '') . '">';
    $skipped = 0;
    $chg = 0;
    foreach ($rows as $i => $row) {
        if (!$show[$i]) { $skipped++; continue; }
        if ($skipped) { $h .= '<div class="wd-gap">… ' . $skipped . ' invariati</div>'; $skipped = 0; }
        $kind = $row[0];
        $blk = in_array($kind, ['ins', 'mvt'], true) ? $B[$row[1]] : ($kind === 'eq' || $kind === 'mod' ? $B[$row[2]] : $A[$row[1]]);
        $hc = $blk['kind'] === 'h' ? ' wd-h' : '';
        $id = $kind !== 'eq' ? ' id="c' . (++$chg) . '"' : '';
        $sec = ($kind !== 'eq' && $blk['sec'] !== '' && $blk['kind'] !== 'h') ? '<span class="wd-sec">' . h2($blk['sec']) . '</span>' : '';
        $h .= match ($kind) {
            'eq'  => "<div class=\"wd-row eq$hc\">" . h2($B[$row[2]]['t']) . '</div>',
            'mod' => "<div class=\"wd-row mod$hc\"$id>$sec" . $row[3]['html'] . '</div>',
            'del' => "<div class=\"wd-row del$hc\"$id>$sec<del>" . h2($A[$row[1]]['t']) . '</del></div>',
            'ins' => "<div class=\"wd-row ins$hc\"$id>$sec<ins>" . h2($B[$row[1]]['t']) . '</ins></div>',
            'mvf' => "<div class=\"wd-row mvf$hc\"$id>$sec<span class=\"wd-mv\">↓ spostato (" . (int)$row[2] . ')</span><mark class="mv">' . h2($A[$row[1]]['t']) . '</mark></div>',
            'mvt' => "<div class=\"wd-row mvt$hc\"$id>$sec<span class=\"wd-mv\">↑ spostato qui (" . (int)$row[2] . ')</span><mark class="mv">'
                     . ($row[4] ? $row[4]['html'] : h2($B[$row[1]]['t'])) . '</mark></div>',
        };
    }
    if ($skipped) $h .= '<div class="wd-gap">… ' . $skipped . ' invariati</div>';
    if (!$chg) $h .= '<p class="wd-gap">Nessuna differenza.</p>';
    return $h . '</div>';
}

/** riepilogo strutturale in HTML statico */
function wd_render_structure(array $s): string
{
    $li = function (string $t, array $add, array $rem, int $max = 40) {
        if (!$add && !$rem) return '';
        $h = '<h4>' . $t . ' <small>+' . count($add) . ' / −' . count($rem) . '</small></h4><ul class="wd-slist">';
        foreach (array_slice($rem, 0, $max) as $x) $h .= '<li class="rem">− ' . h2(is_array($x) ? $x['t'] : (string)$x) . '</li>';
        foreach (array_slice($add, 0, $max) as $x) $h .= '<li class="add">+ ' . h2(is_array($x) ? $x['t'] : (string)$x) . '</li>';
        return $h . '</ul>';
    };
    $sx = $s['sections'];
    $h = $li('Sezioni', $sx['add'], $sx['rem']);
    foreach ($sx['renamed'] as [$x, $y]) $h .= '<p>Sezione rinominata: <del>' . h2($x) . '</del> → <ins>' . h2($y) . '</ins></p>';
    if ($sx['moved']) $h .= '<p>Sezioni spostate: ' . h2(implode(', ', $sx['moved'])) . '</p>';
    $h .= $li('Note e fonti (' . $s['refs']['na'] . ' → ' . $s['refs']['nb'] . ')', $s['refs']['add'], $s['refs']['rem']);
    $h .= $li('Domini delle fonti', $s['refs']['dom_new'], $s['refs']['dom_gone']);
    $ib = $s['infobox'];
    if ($ib['chg'] || $ib['add'] || $ib['rem']) {
        $h .= '<h4>Infobox</h4><ul class="wd-slist">';
        foreach ($ib['chg'] as $k => [$x, $y]) $h .= '<li><b>' . h2($k) . '</b>: <del>' . h2($x) . '</del> → <ins>' . h2($y) . '</ins></li>';
        foreach ($ib['rem'] as $k => $x) $h .= '<li class="rem"><b>' . h2($k) . '</b>: <del>' . h2($x) . '</del></li>';
        foreach ($ib['add'] as $k => $y) $h .= '<li class="add"><b>' . h2($k) . '</b>: <ins>' . h2($y) . '</ins></li>';
        $h .= '</ul>';
    }
    $h .= $li('Template', $s['templates']['add'], $s['templates']['rem']);
    $h .= $li('Categorie', $s['categories']['add'], $s['categories']['rem']);
    $h .= $li('Collegamenti interni', $s['links']['add'], $s['links']['rem']);
    $h .= $li('Collegamenti esterni', $s['extlinks']['add'], $s['extlinks']['rem']);
    $h .= $li('Immagini', $s['images']['add'], $s['images']['rem']);
    return $h !== '' ? $h : '<p class="wd-gap">Nessuna differenza strutturale.</p>';
}
