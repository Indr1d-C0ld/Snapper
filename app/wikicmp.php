<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/wikilib.php';
require __DIR__ . '/wikidiff.php';
require_login();

/* =========================================================================
 * Snapper – Wikipedia: banco di confronto.
 *   wikicmp.php?page=<id>&a=<rev>&b=<rev>&tab=testo|affiancato|wikitesto|struttura
 *   wikicmp.php?page=<id>&tab=dinamiche[&user=<nome>]
 *   wikicmp.php?page=<id>&tab=attribuzione[&rev=<rev>]
 * Il confronto lavora solo su revisioni già archiviate: legge i file delle
 * prove, non Wikipedia. Le dinamiche usano la cronologia completa (copia
 * locale di 6 ore); l'attribuzione interroga WikiWho solo dopo un consenso
 * esplicito per la voce.
 * =======================================================================*/

$csrf = csrf_token();
$pdo  = db();
$wid  = (int)($_GET['page'] ?? $_POST['page'] ?? 0);
$s = $pdo->prepare('SELECT * FROM wiki_pages WHERE id=?');
$s->execute([$wid]);
$wp = $s->fetch();
$s->closeCursor();
if (!$wp) {
    http_response_code(404);
    layout_head('Snapper — confronto');
    layout_masthead('wiki');
    echo '<div class="wrap"><div class="empty">Dossier inesistente. <a href="wiki.php">Torna all\'elenco</a>.</div></div>';
    layout_foot();
    exit;
}
$lang = (string)$wp['lang'];

/* -------------------------------------------------- azioni (POST) -------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'wikiwho_on' || $act === 'wikiwho_off') {
        $on = $act === 'wikiwho_on' ? 1 : 0;
        $pdo->prepare('UPDATE wiki_pages SET wikiwho=? WHERE id=?')->execute([$on, $wid]);
        audit('WIKI wikiwho=' . $on . ' ip=' . client_ip() . " page=$wid $lang:" . $wp['title']);
        $_SESSION['flash'] = $on ? 'Attribuzione attivata per questa voce.' : 'Attribuzione disattivata per questa voce.';
        header("Location: wikicmp.php?page=$wid&tab=attribuzione");
        exit;
    }
    if ($act === 'export') {
        $revs = wk_archived($pdo, $wid);
        if (!$revs) {
            $_SESSION['flash'] = 'Nessuna revisione archiviata da esportare.';
        } else {
            $ids = array_slice(array_map('intval', array_column($revs, 'revid')), -200);
            $short = safe_short(7);
            $label = 'Dossier Wikipedia · ' . $wp['title'] . " ($lang) · " . count($ids) . ' revisioni';
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO snapshots(short, url, title, status, source, kind) VALUES(?,?,?,'pending','web','wikiexport')")
                ->execute([$short, wiki_article_url($lang, (string)$wp['title']), $label]);
            $pdo->prepare('INSERT INTO wiki_jobs(short, wiki_page, revids) VALUES(?,?,?)')
                ->execute([$short, $wid, json_encode($ids)]);
            $pdo->commit();
            $started = with_queue_lock(fn() => spawn_worker_locked($short, wiki_article_url($lang, (string)$wp['title'])), fn() => false);
            audit('WIKI export ip=' . client_ip() . " short=$short page=$wid revs=" . count($ids));
            $_SESSION['flash'] = ($started ? 'Esportazione avviata' : 'Esportazione in coda') . " ($short): " . count($ids) . ' revisioni con i confronti già calcolati, manifesto marcato e ZIP.';
        }
        header("Location: wiki.php?page=$wid");
        exit;
    }
    header("Location: wikicmp.php?page=$wid");
    exit;
}

/** revisioni archiviate (una copia per revisione), dalla più vecchia */
function wk_archived(PDO $pdo, int $wid): array
{
    $s = $pdo->prepare("SELECT r.* FROM wiki_revisions r JOIN snapshots s ON s.short = r.short
                        WHERE r.wiki_page = ? AND s.status = 'ready' ORDER BY r.ts, r.revid, r.id");
    $s->execute([$wid]);
    $out = [];
    foreach ($s as $r) {
        $out[(int)$r['revid']] ??= $r;
    }
    return array_values($out);
}

function wk_revdir(array $r): ?string
{
    $d = path_within_data(DATA_DIR . '/' . $r['short'] . '/rev/' . (int)$r['revid']);
    return ($d !== false && is_dir($d)) ? $d : null;
}

$revs = wk_archived($pdo, $wid);
$byId = [];
foreach ($revs as $r) {
    $byId[(int)$r['revid']] = $r;
}
$tab = (string)($_GET['tab'] ?? 'testo');
if (!in_array($tab, ['testo', 'affiancato', 'wikitesto', 'struttura', 'dinamiche', 'attribuzione'], true)) {
    $tab = 'testo';
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* opzioni del confronto. Dal modulo (opt=1) una casella assente vale "no";
 * nei collegamenti valgono i valori predefiniti salvo ws=0 / cites=0. */
$fromForm = isset($_GET['opt']);
$flag = fn(string $k, bool $def) => $fromForm ? isset($_GET[$k]) : ($def ? ($_GET[$k] ?? '1') !== '0' : ($_GET[$k] ?? '0') === '1');
$o = [
    'ws'    => $flag('ws', true),
    'punct' => $flag('punct', false),
    'cites' => $flag('cites', true),
    'gran'  => ($_GET['gran'] ?? 'word') === 'sentence' ? 'sentence' : 'word',
    'refs'  => $flag('refs', false),
];
$ctx = max(0, min(5, (int)($_GET['ctx'] ?? 1)));
$all = $flag('all', false);

/* revisioni A e B: per difetto le ultime due archiviate */
$a = (int)($_GET['a'] ?? 0);
$b = (int)($_GET['b'] ?? 0);
if (!isset($byId[$b])) $b = $revs ? (int)end($revs)['revid'] : 0;
if (!isset($byId[$a]) || $a === $b) {
    $a = 0;
    foreach ($revs as $r) {
        if ((int)$r['revid'] === $b) break;
        $a = (int)$r['revid'];
    }
    if (!$a && count($revs) > 1) $a = (int)$revs[0]['revid'];
}
if ($a && $b && $byId[$a]['ts'] > $byId[$b]['ts']) {
    [$a, $b] = [$b, $a];                         // A è sempre la più vecchia
}

$qs = fn(array $ov = []) => 'wikicmp.php?' . http_build_query(array_merge(
    ['page' => $wid, 'a' => $a ?: null, 'b' => $b ?: null, 'tab' => $tab,
     'ws' => $o['ws'] ? null : '0', 'punct' => $o['punct'] ? '1' : null, 'cites' => $o['cites'] ? null : '0',
     'gran' => $o['gran'] === 'sentence' ? 'sentence' : null, 'refs' => $o['refs'] ? '1' : null,
     'ctx' => $ctx !== 1 ? $ctx : null, 'all' => $all ? '1' : null], $ov));

/* cronologia completa (per dinamiche e commenti fra A e B) */
$hist = null;
$histErr = null;
$needHist = in_array($tab, ['dinamiche', 'attribuzione'], true);
if ($needHist) {
    try {
        $hist = wd_history($lang, (int)$wp['pageid'], ($_GET['refresh'] ?? '') === '1');
    } catch (Throwable $ex) {
        $histErr = 'Cronologia non disponibile: ' . $ex->getMessage();
    }
}

layout_head('Snapper — ' . $wp['title'] . ' · confronto');
layout_masthead('wiki');
?>
<div class="wrap">
<?php if ($flash): ?><p class="flash"><?= h($flash) ?></p><?php endif; ?>

<div class="wk-head">
  <div>
    <span class="wk-kicker">Banco di confronto · <?= h($lang) ?>.wikipedia · <?= count($revs) ?> revisioni archiviate</span>
    <h2 class="wk-title"><?= h($wp['title']) ?></h2>
  </div>
  <div class="wk-actions">
    <a class="wk-btn ghost" href="wiki.php?page=<?= $wid ?>">Dossier</a>
    <a class="wk-btn ghost" href="wiki.php?<?= h(http_build_query(['lang' => $lang, 'title' => $wp['title']])) ?>">Cronologia e acquisizione</a>
  </div>
</div>

<nav class="wd-tabs">
  <?php foreach (['testo' => 'Testo', 'affiancato' => 'Affiancato', 'wikitesto' => 'Wikitesto', 'struttura' => 'Struttura',
                  'dinamiche' => 'Dinamiche', 'attribuzione' => 'Attribuzione'] as $k => $lbl): ?>
    <a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= h($qs(['tab' => $k])) ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</nav>

<?php
/* ======================================================= confronto ====== */
if (in_array($tab, ['testo', 'affiancato', 'wikitesto', 'struttura'], true)):
    if (count($revs) < 2): ?>
      <div class="empty">Per confrontare servono almeno due revisioni archiviate.
        <a href="wiki.php?<?= h(http_build_query(['lang' => $lang, 'title' => $wp['title']])) ?>">Acquisiscine dalla cronologia</a>.</div>
    <?php else:
    $ra = $byId[$a];
    $rb = $byId[$b];
    $da = wk_revdir($ra);
    $db = wk_revdir($rb);
    if (!$da || !$db) {
        echo '<p class="flash">File della revisione non trovati su disco.</p></div>';
        layout_foot();
        exit;
    }
    $t0 = microtime(true);
    $ca = wd_load_content($da);
    $cb = wd_load_content($db);
    $wa = (string)file_get_contents("$da/wikitext.txt");
    $wb = (string)file_get_contents("$db/wikitext.txt");
    $struct = wd_structure(wd_parse_json($da), wd_parse_json($db), $ca, $cb, $wa, $wb);
    if ($tab === 'wikitesto') {
        $A = wd_wikitext_blocks($wa);
        $B = wd_wikitext_blocks($wb);
    } else {
        $A = $ca ? wd_text_blocks($ca, $o['refs']) : [];
        $B = $cb ? wd_text_blocks($cb, $o['refs']) : [];
    }
    $res = wd_blocks($A, $B, $tab === 'wikitesto' ? $o : array_merge($o, ['cites' => false]));
    $st = $res['stats'];
    $ms = (int)round((microtime(true) - $t0) * 1000);

    // modifiche fra A e B nella cronologia di Wikipedia
    $between = [];
    $betweenMore = false;
    try {
        $bw = wd_between($lang, (int)$wp['pageid'], (string)$ra['ts'], (string)$rb['ts']);
        $between = $bw['rows'];
        $betweenMore = $bw['more'];
    } catch (Throwable $ex) {
        $histErr = 'Cronologia non disponibile: ' . $ex->getMessage();
    }
    $authorsB = count(array_unique(array_map(fn($x) => $x['user'] ?? '?', $between)));
    $revertsB = count(array_filter($between, fn($x) => $x['is_revert'] || $x['restores']));
    $revOpt = function (int $sel) use ($revs) {
        $h = '';
        foreach (array_reverse($revs) as $r) {
            $h .= '<option value="' . (int)$r['revid'] . '"' . ((int)$r['revid'] === $sel ? ' selected' : '') . '>'
                . h(ts_local($r['ts'])) . ' · ' . (int)$r['revid'] . ' · ' . h(mb_strimwidth((string)($r['user'] ?? '—'), 0, 24, '…')) . '</option>';
        }
        return $h;
    };
    ?>
    <form class="wd-pick" method="get" action="wikicmp.php">
      <input type="hidden" name="page" value="<?= $wid ?>">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <label>Da <select name="a"><?= $revOpt($a) ?></select></label>
      <span class="wd-arrow">→</span>
      <label>a <select name="b"><?= $revOpt($b) ?></select></label>
      <?php if ($tab !== 'struttura'): ?>
      <details class="wd-opts">
        <summary>Opzioni</summary>
        <label class="chk"><input type="checkbox" name="ws" value="1" <?= $o['ws'] ? 'checked' : '' ?>> ignora gli spazi</label>
        <label class="chk"><input type="checkbox" name="punct" value="1" <?= $o['punct'] ? 'checked' : '' ?>> ignora la punteggiatura</label>
        <?php if ($tab === 'wikitesto'): ?>
        <label class="chk"><input type="checkbox" name="cites" value="1" <?= $o['cites'] ? 'checked' : '' ?>> ignora la sola riformattazione delle citazioni</label>
        <?php else: ?>
        <label class="chk"><input type="checkbox" name="refs" value="1" <?= $o['refs'] ? 'checked' : '' ?>> considera i numeri delle note [1]</label>
        <?php endif; ?>
        <label>Unità <select name="gran"><option value="word">parola</option><option value="sentence" <?= $o['gran'] === 'sentence' ? 'selected' : '' ?>>frase</option></select></label>
        <label>Contesto <select name="ctx"><?php foreach ([0, 1, 2, 3, 5] as $c): ?><option value="<?= $c ?>" <?= $ctx === $c ? 'selected' : '' ?>><?= $c ?> blocchi</option><?php endforeach; ?></select></label>
        <label class="chk"><input type="checkbox" name="all" value="1" <?= $all ? 'checked' : '' ?>> mostra tutto il testo</label>
        <input type="hidden" name="opt" value="1">
      </details>
      <?php endif; ?>
      <button type="submit">Confronta</button>
    </form>

    <div class="wd-bench">
      <aside class="wd-side">
        <div class="wd-card">
          <h4>Revisioni</h4>
          <p class="wd-rev"><span class="wd-a">A</span> <a href="/archives/<?= h(rawurlencode($ra['short'])) ?>/rev/<?= $a ?>/" target="_blank" rel="noopener"><?= h(ts_local($ra['ts'])) ?></a><br><span class="hint"><?= h((string)$ra['user']) ?> · <?= number_format((int)$ra['size'], 0, ',', '.') ?> byte</span></p>
          <p class="wd-rev"><span class="wd-b">B</span> <a href="/archives/<?= h(rawurlencode($rb['short'])) ?>/rev/<?= $b ?>/" target="_blank" rel="noopener"><?= h(ts_local($rb['ts'])) ?></a><br><span class="hint"><?= h((string)$rb['user']) ?> · <?= number_format((int)$rb['size'], 0, ',', '.') ?> byte</span></p>
          <p class="hint">Provenienza: A <?= (int)$ra['sha1_ok'] ? '<span class="wk-ok">✓</span>' : '<span class="wk-bad">✗</span>' ?> · B <?= (int)$rb['sha1_ok'] ? '<span class="wk-ok">✓</span>' : '<span class="wk-bad">✗</span>' ?> sha1 di Wikipedia</p>
        </div>
        <?php if ($tab !== 'struttura'): ?>
        <div class="wd-card">
          <h4>Riepilogo</h4>
          <div class="wd-stat"><span><span class="wd-t add">+</span> parole aggiunte</span><b><?= $st['add'] ?></b></div>
          <div class="wd-stat"><span><span class="wd-t rem">−</span> parole rimosse</span><b><?= $st['del'] ?></b></div>
          <div class="wd-stat"><span><span class="wd-t mov">↕</span> passaggi spostati</span><b><?= $st['moved'] ?></b></div>
          <div class="wd-stat"><span>blocchi modificati</span><b><?= $st['mod'] ?></b></div>
          <div class="wd-stat"><span>blocchi nuovi / tolti</span><b><?= $st['ins_blocks'] ?> / <?= $st['del_blocks'] ?></b></div>
          <?php if ($st['timeout']): ?><p class="wk-warn">Confronto troppo lungo: alcuni tratti sono mostrati come sostituiti per intero.</p><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="wd-card">
          <h4>Fonti e note</h4>
          <div class="wd-stat"><span>note</span><b><?= $struct['refs']['na'] ?> → <?= $struct['refs']['nb'] ?></b></div>
          <div class="wd-stat"><span><span class="wd-t rem">−</span> note rimosse</span><b><?= count($struct['refs']['rem']) ?></b></div>
          <div class="wd-stat"><span><span class="wd-t add">+</span> note aggiunte</span><b><?= count($struct['refs']['add']) ?></b></div>
          <div class="wd-stat"><span>domini spariti</span><b class="<?= $struct['refs']['dom_gone'] ? 'wk-bad' : '' ?>"><?= count($struct['refs']['dom_gone']) ?></b></div>
          <div class="wd-stat"><span>domini nuovi</span><b><?= count($struct['refs']['dom_new']) ?></b></div>
        </div>
        <div class="wd-card">
          <h4>Struttura</h4>
          <?php $sx = $struct['sections']; ?>
          <div class="wd-stat"><span>sezioni nuove / tolte</span><b><?= count($sx['add']) ?> / <?= count($sx['rem']) ?></b></div>
          <div class="wd-stat"><span>rinominate / spostate</span><b><?= count($sx['renamed']) ?> / <?= count($sx['moved']) ?></b></div>
          <div class="wd-stat"><span>campi infobox cambiati</span><b><?= count($struct['infobox']['chg']) + count($struct['infobox']['add']) + count($struct['infobox']['rem']) ?></b></div>
          <?php if ($struct['templates']['maint_add'] || $struct['templates']['maint_rem']): ?>
            <p class="wk-warn">Avvisi di manutenzione: <?= h(implode(', ', array_merge(array_map(fn($x) => "+$x", $struct['templates']['maint_add']), array_map(fn($x) => "−$x", $struct['templates']['maint_rem'])))) ?></p>
          <?php endif; ?>
          <a class="hint" href="<?= h($qs(['tab' => 'struttura'])) ?>">dettaglio ›</a>
        </div>
        <div class="wd-card">
          <h4>Fra A e B su Wikipedia</h4>
          <?php if ($histErr): ?><p class="wk-warn"><?= h($histErr) ?></p>
          <?php else: ?>
            <div class="wd-stat"><span>modifiche</span><b><?= count($between) ?><?= $betweenMore ? '+' : '' ?></b></div>
            <div class="wd-stat"><span>autori</span><b><?= $authorsB ?></b></div>
            <div class="wd-stat"><span>revert</span><b class="<?= $revertsB ? 'wk-bad' : '' ?>"><?= $revertsB ?></b></div>
            <?php if ($betweenMore): ?><p class="hint">Più di 500 modifiche nell'intervallo: elencate le più vecchie.</p><?php endif; ?>
          <?php endif; ?>
          <p class="hint"><?= $ms ?> ms</p>
        </div>
      </aside>

      <section class="wd-main">
      <?php if ($tab === 'struttura'):
          $list = function (string $title, array $add, array $rem, int $max = 60, ?callable $fmt = null) {
              $fmt ??= fn($x) => h((string)$x);
              if (!$add && !$rem) return '<div class="wd-sblock"><h4>' . $title . '</h4><p class="hint">nessuna differenza</p></div>';
              $h = '<div class="wd-sblock"><h4>' . $title . ' <span class="hint">+' . count($add) . ' / −' . count($rem) . '</span></h4><ul class="wd-slist">';
              foreach (array_slice($rem, 0, $max) as $x) $h .= '<li class="rem"><span class="wd-t rem">−</span> ' . $fmt($x) . '</li>';
              foreach (array_slice($add, 0, $max) as $x) $h .= '<li class="add"><span class="wd-t add">+</span> ' . $fmt($x) . '</li>';
              $more = max(0, count($rem) - $max) + max(0, count($add) - $max);
              return $h . '</ul>' . ($more ? '<p class="hint">e altri ' . $more . '</p>' : '') . '</div>';
          };
          $sx = $struct['sections'];
          echo '<div class="wd-sblock"><h4>Sezioni <span class="hint">' . $sx['na'] . ' → ' . $sx['nb'] . '</span></h4><ul class="wd-slist">';
          foreach ($sx['rem'] as $x) echo '<li class="rem"><span class="wd-t rem">−</span> ' . h($x) . '</li>';
          foreach ($sx['add'] as $x) echo '<li class="add"><span class="wd-t add">+</span> ' . h($x) . '</li>';
          foreach ($sx['renamed'] as [$x, $y]) echo '<li class="mov"><span class="wd-t mov">≠</span> <del>' . h($x) . '</del> → <ins>' . h($y) . '</ins></li>';
          foreach ($sx['moved'] as $x) echo '<li class="mov"><span class="wd-t mov">↕</span> ' . h($x) . ' (spostata)</li>';
          if (!$sx['rem'] && !$sx['add'] && !$sx['renamed'] && !$sx['moved']) echo '<li class="hint">nessuna differenza</li>';
          echo '</ul></div>';

          $rf = $struct['refs'];
          $refFmt = function ($x) {
              $h = h(mb_strimwidth($x['t'], 0, 400, '…'));
              if ($x['urls']) $h .= '<br><span class="wd-dom">' . h(implode(' · ', array_unique(array_map('wd_host', $x['urls'])))) . '</span>';
              return $h;
          };
          echo $list('Note e fonti <span class="hint">' . $rf['na'] . ' → ' . $rf['nb'] . '</span>', $rf['add'], $rf['rem'], 80, $refFmt);
          echo $list('Domini delle fonti', $rf['dom_new'], $rf['dom_gone']);

          $ib = $struct['infobox'];
          echo '<div class="wd-sblock"><h4>Infobox</h4>';
          if (!$ib['present']) {
              echo '<p class="hint">nessun infobox</p>';
          } elseif (!$ib['add'] && !$ib['rem'] && !$ib['chg']) {
              echo '<p class="hint">nessuna differenza</p>';
          } else {
              echo '<div class="tbl-scroll"><table class="ledger wd-ib"><thead><tr><th>Campo</th><th>A</th><th>B</th></tr></thead><tbody>';
              foreach ($ib['chg'] as $k => [$x, $y]) {
                  $in = wd_inline($x, $y, ['ws' => true, 'gran' => 'word'], microtime(true) + 1);
                  echo '<tr><td><b>' . h($k) . '</b></td><td>' . $in['left'] . '</td><td>' . $in['right'] . '</td></tr>';
              }
              foreach ($ib['rem'] as $k => $x) echo '<tr><td><b>' . h($k) . '</b></td><td><del>' . h($x) . '</del></td><td class="hint">(tolto)</td></tr>';
              foreach ($ib['add'] as $k => $y) echo '<tr><td><b>' . h($k) . '</b></td><td class="hint">(assente)</td><td><ins>' . h($y) . '</ins></td></tr>';
              echo '</tbody></table></div>';
          }
          echo '</div>';
          $tp = $struct['templates'];
          echo $list('Template', $tp['add'], $tp['rem'], 60, fn($x) => h($x) . (in_array($x, WD_MAINT, true) ? ' <span class="wk-b rd">manutenzione</span>' : ''));
          echo $list('Categorie', $struct['categories']['add'], $struct['categories']['rem']);
          echo $list('Categorie nascoste', $struct['categories_hidden']['add'], $struct['categories_hidden']['rem']);
          echo $list('Collegamenti interni', $struct['links']['add'], $struct['links']['rem']);
          echo $list('Collegamenti esterni', $struct['extlinks']['add'], $struct['extlinks']['rem'], 60, fn($x) => '<span class="wd-url">' . h($x) . '</span>');
          echo $list('Immagini', $struct['images']['add'], $struct['images']['rem'], 60, fn($x) => h(str_replace('_', ' ', $x)));
      else:
          // righe da mostrare: modifiche + contesto, il resto compresso
          $rows = $res['rows'];
          $n = count($rows);
          $show = array_fill(0, $n, $all);
          foreach ($rows as $i => $row) {
              if ($row[0] !== 'eq') {
                  for ($k = max(0, $i - $ctx); $k <= min($n - 1, $i + $ctx); $k++) $show[$k] = true;
              }
          }
          $isWt = $tab === 'wikitesto';
          $side = $tab === 'affiancato';
          $cls = 'wd-doc' . ($isWt ? ' wd-mono' : '') . ($side ? ' wd-side-by-side' : '');
          $chg = 0;
          echo '<div class="' . $cls . '" id="doc">';
          if ($side) echo '<div class="wd-sbs-head"><span><span class="wd-a">A</span> ' . h(ts_local($ra['ts'])) . '</span><span><span class="wd-b">B</span> ' . h(ts_local($rb['ts'])) . '</span></div>';
          $skipped = 0;
          $lastSec = null;
          $flushSkip = function () use (&$skipped, $isWt) {
              if ($skipped) {
                  echo '<div class="wd-gap">… ' . $skipped . ' ' . ($isWt ? ($skipped === 1 ? 'riga invariata' : 'righe invariate') : ($skipped === 1 ? 'blocco invariato' : 'blocchi invariati')) . '</div>';
                  $skipped = 0;
              }
          };
          foreach ($rows as $i => $row) {
              if (!$show[$i]) { $skipped++; continue; }
              $flushSkip();
              $kind = $row[0];
              $blk = in_array($kind, ['ins', 'mvt'], true) ? $B[$row[1]] : ($kind === 'eq' || $kind === 'mod' ? $B[$row[2]] : $A[$row[1]]);
              $tagCls = $blk['kind'] === 'h' ? ' wd-h' : '';
              $sec = ($kind !== 'eq' && $blk['sec'] !== '' && $blk['sec'] !== $lastSec && $blk['kind'] !== 'h') ? '<span class="wd-sec">' . h($blk['sec']) . '</span>' : '';
              if ($kind !== 'eq') $lastSec = $blk['sec'];
              $id = $kind !== 'eq' ? ' id="c' . (++$chg) . '"' : '';
              switch ($kind) {
                  case 'eq':
                      $t = h($B[$row[2]]['t']);
                      echo $side ? "<div class=\"wd-row eq$tagCls\"><div>" . h($A[$row[1]]['t']) . "</div><div>$t</div></div>" : "<div class=\"wd-row eq$tagCls\">$t</div>";
                      break;
                  case 'mod':
                      $in = $row[3];
                      echo $side ? "<div class=\"wd-row mod$tagCls\"$id>$sec<div>{$in['left']}</div><div>{$in['right']}</div></div>"
                                 : "<div class=\"wd-row mod$tagCls\"$id>$sec{$in['html']}</div>";
                      break;
                  case 'del':
                      $t = '<del>' . h($A[$row[1]]['t']) . '</del>';
                      echo $side ? "<div class=\"wd-row del$tagCls\"$id>$sec<div>$t</div><div></div></div>" : "<div class=\"wd-row del$tagCls\"$id>$sec$t</div>";
                      break;
                  case 'ins':
                      $t = '<ins>' . h($B[$row[1]]['t']) . '</ins>';
                      echo $side ? "<div class=\"wd-row ins$tagCls\"$id>$sec<div></div><div>$t</div></div>" : "<div class=\"wd-row ins$tagCls\"$id>$sec$t</div>";
                      break;
                  case 'mvf':
                      $t = '<span class="wd-mv">↓ spostato (' . (int)$row[2] . ')</span><mark class="mv">' . h($A[$row[1]]['t']) . '</mark>';
                      echo $side ? "<div class=\"wd-row mvf$tagCls\"$id>$sec<div>$t</div><div></div></div>" : "<div class=\"wd-row mvf$tagCls\"$id>$sec$t</div>";
                      break;
                  case 'mvt':
                      $body = $row[4] ? $row[4]['html'] : h($B[$row[1]]['t']);
                      $t = '<span class="wd-mv">↑ spostato qui (' . (int)$row[2] . ')' . ($row[4] ? ', con modifiche' : '') . '</span><mark class="mv">' . $body . '</mark>';
                      echo $side ? "<div class=\"wd-row mvt$tagCls\"$id>$sec<div></div><div>$t</div></div>" : "<div class=\"wd-row mvt$tagCls\"$id>$sec$t</div>";
                      break;
              }
          }
          $flushSkip();
          if (!$chg) echo '<div class="empty">Nessuna differenza con queste opzioni.</div>';
          echo '</div>';
          if ($chg): ?>
            <div class="wd-nav" id="wdnav">
              <button type="button" data-d="-1" title="Modifica precedente (k)">‹</button>
              <span id="wdpos">— / <?= $chg ?></span>
              <button type="button" data-d="1" title="Modifica successiva (j)">›</button>
            </div>
            <script>
            (function () {
              var n = <?= $chg ?>, cur = 0, pos = document.getElementById('wdpos');
              function go(d) {
                cur = Math.min(n, Math.max(1, cur + d));
                var el = document.getElementById('c' + cur);
                if (!el) return;
                document.querySelectorAll('.wd-row.cur').forEach(function (x) { x.classList.remove('cur'); });
                el.classList.add('cur');
                el.scrollIntoView({block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
                pos.textContent = cur + ' / ' + n;
              }
              document.querySelectorAll('#wdnav button').forEach(function (b) {
                b.addEventListener('click', function () { go(+b.dataset.d); });
              });
              document.addEventListener('keydown', function (e) {
                if (/input|select|textarea/i.test(e.target.tagName) || e.ctrlKey || e.metaKey || e.altKey) return;
                if (e.key === 'j' || e.key === 'n') go(1);
                if (e.key === 'k' || e.key === 'p') go(-1);
              });
            })();
            </script>
          <?php endif;
      endif; ?>

      <?php if ($between): ?>
        <h3 class="wd-h3"><?= count($between) === 1 ? 'La modifica fra A e B, con il suo commento' : 'Le ' . count($between) . ' modifiche fra A e B, con i loro commenti' ?></h3>
        <div class="tbl-scroll"><table class="ledger wk-tbl">
          <thead><tr><th>Data</th><th>Autore</th><th>Commento</th><th class="num">Δ byte</th><th>Segni</th><th></th></tr></thead><tbody>
          <?php foreach (array_reverse($between) as $h): $d = $h['delta']; ?>
            <tr class="<?= ($h['is_revert'] || $h['was_reverted']) ? 'wk-rv' : '' ?>">
              <td class="nowrap"><?= h(ts_local($h['ts'])) ?></td>
              <td><?= $h['user'] === null ? '<i>nascosto</i>' : h($h['user']) ?></td>
              <td class="wk-cmt"><?= $h['comment'] === null ? '<i>nascosto</i>' : h($h['comment']) ?></td>
              <td class="num <?= $d > 0 ? 'wk-plus' : ($d < 0 ? 'wk-minus' : '') ?>"><?= ($d > 0 ? '+' : '') . number_format((int)$d, 0, ',', '.') ?></td>
              <td class="wk-signs">
                <?php if ($h['minor']): ?><span class="wk-b">m</span><?php endif; ?>
                <?php if ($h['is_revert']): ?><span class="wk-b rv">revert</span><?php endif; ?>
                <?php if ($h['was_reverted']): ?><span class="wk-b rd">annullata</span><?php endif; ?>
                <?php if ($h['restores']): ?><span class="wk-b rv">= <?= h(ts_local($h['restores']['ts'])) ?></span><?php endif; ?>
              </td>
              <td class="nowrap">
                <?php if (isset($byId[$h['revid']])): ?><a class="wk-b ok" href="/archives/<?= h(rawurlencode($byId[$h['revid']]['short'])) ?>/rev/<?= (int)$h['revid'] ?>/" target="_blank" rel="noopener">archiviata</a>
                <?php else: ?><a class="u" href="https://<?= h($lang) ?>.wikipedia.org/w/index.php?diff=<?= (int)$h['revid'] ?>" target="_blank" rel="noopener noreferrer">diff ↗</a><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
      <?php endif; ?>
      </section>
    </div>
    <?php endif;

/* ======================================================= dinamiche ====== */
elseif ($tab === 'dinamiche'):
    if ($histErr):
        echo '<p class="flash">' . h($histErr) . '</p>';
    elseif ($hist):
        $rows = $hist['rows'];
        $user = trim((string)($_GET['user'] ?? ''));
        $wars = wd_edit_wars($rows);
        $authors = wd_authors($rows);
        $nRev = count(array_filter($rows, fn($r) => $r['is_revert'] || $r['restores']));
        ?>
        <div class="wd-dyn-head">
          <span class="hint"><?= count($rows) ?> modifiche<?= $hist['truncated'] ? ' (le ultime ' . WD_HISTORY_MAX . ')' : '' ?> ·
            dal <?= h(ts_local($rows[0]['ts'] ?? '')) ?> · <?= count($authors) ?> autori · <?= $nRev ?> revert ·
            cronologia letta <?= h(ts_local(gmdate('Y-m-d H:i:s', (int)$hist['fetched']))) ?></span>
          <a class="hint" href="<?= h($qs(['refresh' => '1'])) ?>">aggiorna</a>
        </div>
        <div class="wd-card wd-tl-card">
          <?= wd_timeline_svg($rows, $byId) ?>
          <div class="wd-legend"><span class="l-line">dimensione</span><span class="l-rv">revert</span><span class="l-arch">archiviata in Snapper</span></div>
        </div>

        <h3 class="wd-h3">Alternanze fra versioni identiche</h3>
        <p class="hint wd-explain">Episodi in cui il testo è tornato più volte, in poco tempo, esattamente a una versione precedente
          (stesso sha1): il segno di una guerra di modifica. Si conta a livello di revisione, non di frase.</p>
        <?php if (!$wars): ?>
          <div class="empty">Nessun episodio con almeno 3 ripristini ravvicinati.</div>
        <?php else: ?>
          <div class="tbl-scroll"><table class="ledger wk-tbl">
            <thead><tr><th>Periodo</th><th class="num">Ripristini</th><th>Chi ripristina</th><th>Chi viene annullato</th><th></th></tr></thead><tbody>
            <?php foreach (array_reverse($wars) as $w):
                $r1 = []; $r2 = [];
                foreach ($w['who'] as $u => $c) {
                    if (!empty($c['restore'])) $r1[] = h($u) . ' (' . $c['restore'] . ')';
                    if (!empty($c['undone'])) $r2[] = h($u) . ' (' . $c['undone'] . ')';
                }
                $fr = $rows[$w['first']]; $lr = $rows[$w['last']]; ?>
              <tr>
                <td class="nowrap"><?= h(ts_local(gmdate('Y-m-d H:i:s', $w['start']))) ?><br>→ <?= h(ts_local(gmdate('Y-m-d H:i:s', $w['end']))) ?></td>
                <td class="num"><?= $w['restores'] ?></td>
                <td><?= implode(', ', $r1) ?></td>
                <td><?= implode(', ', $r2) ?></td>
                <td class="nowrap"><a class="u" href="https://<?= h($lang) ?>.wikipedia.org/w/index.php?<?= h(http_build_query(['title' => $wp['title'], 'action' => 'history', 'offset' => gmdate('YmdHis', $w['end'] + 1), 'limit' => max(10, $w['last'] - $w['first'] + 4)])) ?>" target="_blank" rel="noopener noreferrer">su Wikipedia ↗</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>

        <h3 class="wd-h3">Autori<?= $user !== '' ? ' · ' . h($user) : '' ?></h3>
        <?php if ($user === ''): ?>
          <div class="tbl-scroll"><table class="ledger wk-tbl">
            <thead><tr><th>Autore</th><th class="num">Modifiche</th><th class="num">Byte aggiunti</th><th class="num">Byte tolti</th><th class="num">Revert fatti</th><th class="num">Annullate</th><th>Periodo</th></tr></thead><tbody>
            <?php foreach (array_slice($authors, 0, 60) as $x): ?>
              <tr>
                <td><a href="<?= h($qs(['user' => $x['user']])) ?>"><?= h($x['user']) ?></a><?= $x['anon'] ? ' <span class="wk-b">IP</span>' : '' ?></td>
                <td class="num"><?= $x['edits'] ?></td>
                <td class="num wk-plus">+<?= number_format($x['plus'], 0, ',', '.') ?></td>
                <td class="num wk-minus">−<?= number_format($x['minus'], 0, ',', '.') ?></td>
                <td class="num"><?= $x['reverts'] ?: '' ?></td>
                <td class="num"><?= $x['reverted'] ?: '' ?></td>
                <td class="nowrap"><?= h(substr($x['first'], 0, 10)) ?> → <?= h(substr($x['last'], 0, 10)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody></table></div>
          <?php if (count($authors) > 60): ?><p class="hint">e altri <?= count($authors) - 60 ?> autori</p><?php endif; ?>
        <?php else:
            $mine = array_reverse(array_values(array_filter($rows, fn($r) => ($r['user'] ?? '') === $user))); ?>
          <p><a class="hint" href="<?= h($qs(['user' => null])) ?>">‹ tutti gli autori</a></p>
          <div class="tbl-scroll"><table class="ledger wk-tbl">
            <thead><tr><th>Data</th><th>Commento</th><th class="num">Δ byte</th><th>Segni</th><th></th></tr></thead><tbody>
            <?php foreach ($mine as $h): $d = (int)$h['delta']; ?>
              <tr>
                <td class="nowrap"><?= h(ts_local($h['ts'])) ?></td>
                <td class="wk-cmt"><?= $h['comment'] === null ? '<i>nascosto</i>' : h($h['comment']) ?></td>
                <td class="num <?= $d > 0 ? 'wk-plus' : ($d < 0 ? 'wk-minus' : '') ?>"><?= ($d > 0 ? '+' : '') . number_format($d, 0, ',', '.') ?></td>
                <td class="wk-signs"><?= $h['is_revert'] ? '<span class="wk-b rv">revert</span>' : '' ?><?= $h['was_reverted'] ? '<span class="wk-b rd">annullata</span>' : '' ?></td>
                <td class="nowrap">
                  <?php if (isset($byId[$h['revid']], $byId[$h['parentid']])): ?>
                    <a class="wk-b ok" href="<?= h($qs(['tab' => 'testo', 'a' => $h['parentid'], 'b' => $h['revid'], 'user' => null])) ?>">confronta</a>
                  <?php else: ?>
                    <a class="u" href="https://<?= h($lang) ?>.wikipedia.org/w/index.php?diff=<?= (int)$h['revid'] ?>" target="_blank" rel="noopener noreferrer">diff ↗</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif;
    endif;

/* ======================================================= attribuzione === */
else:
    if (!(int)($wp['wikiwho'] ?? 0)): ?>
      <div class="panel wd-consent">
        <h2>Chi ha scritto cosa</h2>
        <p>Per ogni frammento di una revisione archiviata: chi l'ha inserito, in quale revisione e quando, e se è stato tolto e poi
          rimesso. Il calcolo lo fa <b>WikiWho</b>, un servizio di ricerca ospitato da Wikimedia Cloud.</p>
        <p class="hint">Attivandolo, Snapper invierà a <code>wikiwho-api.wmcloud.org</code> la lingua e l'id delle revisioni di questa
          voce che vorrai analizzare. È un'informazione pubblica, ma rivela quale voce stai studiando. Si attiva voce per voce,
          e si può disattivare.</p>
        <form method="post" action="wikicmp.php">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="page" value="<?= $wid ?>">
          <input type="hidden" name="act" value="wikiwho_on">
          <button type="submit">Attiva l'attribuzione per «<?= h($wp['title']) ?>»</button>
        </form>
      </div>
    <?php elseif (!$revs): ?>
      <div class="empty">Nessuna revisione archiviata da analizzare.</div>
    <?php else:
        $rv = (int)($_GET['rev'] ?? 0);
        if (!isset($byId[$rv])) $rv = (int)end($revs)['revid'];
        $r = $byId[$rv];
        $dir = wk_revdir($r);
        $err = null;
        $ww = null;
        try {
            $ww = wd_wikiwho($lang, $rv);
        } catch (Throwable $ex) {
            $err = $ex->getMessage();
        }
        ?>
        <form class="wd-pick" method="get" action="wikicmp.php">
          <input type="hidden" name="page" value="<?= $wid ?>"><input type="hidden" name="tab" value="attribuzione">
          <label>Revisione <select name="rev">
            <?php foreach (array_reverse($revs) as $x): ?><option value="<?= (int)$x['revid'] ?>" <?= (int)$x['revid'] === $rv ? 'selected' : '' ?>><?= h(ts_local($x['ts'])) ?> · <?= (int)$x['revid'] ?></option><?php endforeach; ?>
          </select></label>
          <button type="submit">Analizza</button>
        </form>
        <?php if ($err || !$dir): ?>
          <p class="flash"><?= h($err ?? 'File della revisione non trovati.') ?></p>
        <?php else:
            // nomi degli autori: id -> nome dalla cronologia; date delle revisioni d'origine
            $uname = [];
            $rts = [];
            foreach ($hist['rows'] ?? [] as $h) {
                if (!empty($h['userid']) && $h['user'] !== null) $uname[(string)$h['userid']] = $h['user'];
                $rts[$h['revid']] = $h['ts'];
            }
            $who = fn(string $ed) => str_starts_with($ed, '0|') ? substr($ed, 2) . ' (IP)' : ($uname[$ed] ?? "utente #$ed");
            $toks = $ww['tokens'];
            $total = count($toks);
            $byEd = [];
            $byYear = [];
            $reins = 0;
            foreach ($toks as [$str, $orev, $ed, $outs]) {
                $byEd[$ed] = ($byEd[$ed] ?? 0) + 1;
                $y = isset($rts[$orev]) ? substr($rts[$orev], 0, 4) : '?';
                $byYear[$y] = ($byYear[$y] ?? 0) + 1;
                if ($outs > 0) $reins++;
            }
            arsort($byEd);
            ksort($byYear);
            $years = array_values(array_filter(array_keys($byYear), fn($y) => $y !== '?'));
            $ymin = $years ? (int)min($years) : 0;
            $ymax = $years ? (int)max($years) : 0;
            $spans = wd_align_tokens((string)file_get_contents("$dir/wikitext.txt"), $toks);
            ?>
            <div class="wd-bench">
              <aside class="wd-side">
                <div class="wd-card">
                  <h4>Revisione</h4>
                  <p class="wd-rev"><a href="/archives/<?= h(rawurlencode($r['short'])) ?>/rev/<?= $rv ?>/" target="_blank" rel="noopener"><?= h(ts_local($r['ts'])) ?></a><br><span class="hint"><?= number_format($total, 0, ',', '.') ?> frammenti · <?= $reins ?> tolti e rimessi almeno una volta</span></p>
                </div>
                <div class="wd-card">
                  <h4>Autori del testo attuale</h4>
                  <?php foreach (array_slice($byEd, 0, 12, true) as $ed => $c): ?>
                    <div class="wd-stat"><span><?= h($who((string)$ed)) ?></span><b><?= round(100 * $c / max(1, $total), 1) ?>%</b></div>
                  <?php endforeach; ?>
                </div>
                <div class="wd-card">
                  <h4>Anno di inserimento</h4>
                  <?php foreach ($byYear as $y => $c): ?>
                    <div class="wd-stat"><span><span class="wd-age a<?= $y === '?' ? 'x' : (int)round(5 * (((int)$y - $ymin) / max(1, $ymax - $ymin))) ?>"></span> <?= h((string)$y) ?></span><b><?= round(100 * $c / max(1, $total), 1) ?>%</b></div>
                  <?php endforeach; ?>
                  <?php if (isset($byYear['?'])): ?><p class="hint">«?»: revisioni più vecchie della cronologia letta.</p><?php endif; ?>
                </div>
                <form method="post" action="wikicmp.php" class="wd-card">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="page" value="<?= $wid ?>">
                  <input type="hidden" name="act" value="wikiwho_off">
                  <button type="submit" class="danger">Disattiva per questa voce</button>
                </form>
              </aside>
              <section class="wd-main">
                <p class="hint">Wikitesto della revisione, colorato per anno di inserimento (dal più vecchio, scuro, al più recente, chiaro).
                  Passa sopra un tratto per vedere autore e data; i tratti sottolineati sono stati tolti e rimessi.</p>
                <div class="wd-doc wd-mono wd-who"><?php
                    $out = '';
                    $prevKey = null;
                    $buf = '';
                    $emit = function () use (&$out, &$buf, &$prevKey) {
                        if ($buf === '') return;
                        $out .= $prevKey === null ? h2($buf) : '<span class="' . $prevKey[0] . '" title="' . h2($prevKey[1]) . '">' . h2($buf) . '</span>';
                        $buf = '';
                    };
                    foreach ($spans as [$txt, $ti]) {
                        if ($ti === null) {
                            $key = null;
                        } else {
                            [, $orev, $ed, $outs] = $toks[$ti];
                            $y = isset($rts[$orev]) ? (int)substr($rts[$orev], 0, 4) : null;
                            $cls = 'wd-age a' . ($y === null ? 'x' : (int)round(5 * (($y - $ymin) / max(1, $ymax - $ymin)))) . ($outs ? ' re' : '');
                            $key = [$cls, $who($ed) . ' · rev ' . $orev . (isset($rts[$orev]) ? ' · ' . ts_local($rts[$orev]) : '') . ($outs ? ' · tolto e rimesso ' . $outs . ' volte' : '')];
                        }
                        if ($key !== $prevKey) {
                            $emit();
                            $prevKey = $key;
                        }
                        $buf .= $txt;
                    }
                    $emit();
                    echo $out;
                ?></div>
              </section>
            </div>
        <?php endif;
    endif;
endif; ?>
</div>
<?php
layout_foot();
