<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/wikilib.php';
require_login();

/* =========================================================================
 * Snapper – Wikipedia: dossier delle voci, cronologia, acquisizione.
 *   wiki.php                       dossier archiviati + campo per una voce
 *   wiki.php?u=<indirizzo>         riconosce la voce e apre la cronologia
 *   wiki.php?lang=it&title=Roma    cronologia filtrabile, scelta delle revisioni
 *   wiki.php?page=<id>             dossier di una voce
 * =======================================================================*/

$csrf = csrf_token();
$pdo  = db();

/** filtri della cronologia, da GET o POST, già validati */
function wk_filters(array $src): array
{
    $date = fn($k) => (isset($src[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$src[$k])) ? (string)$src[$k] : '';
    $user = trim((string)($src['user'] ?? ''));
    return [
        'from'      => $date('from'),
        'to'        => $date('to'),
        'user'      => ($user !== '' && mb_strlen($user) <= 85 && !preg_match('/[|#<>\[\]{}\x00-\x1f]/u', $user)) ? $user : '',
        'hideminor' => !empty($src['hideminor']),
        'hidebots'  => !empty($src['hidebots']),
        'hiderev'   => !empty($src['hiderev']),
        'minbytes'  => max(0, min(1_000_000, (int)($src['minbytes'] ?? 0))),
    ];
}

/** applica i filtri che le API non sanno applicare */
function wk_apply(array $rows, array $f, array $bots): array
{
    return array_values(array_filter($rows, function ($r) use ($f, $bots) {
        if ($f['hideminor'] && $r['minor']) return false;
        if ($f['hidebots'] && $r['user'] !== null && isset($bots[$r['user']])) return false;
        if ($f['hiderev'] && ($r['is_revert'] || $r['was_reverted'] || $r['restores'])) return false;
        if ($f['minbytes'] > 0 && $r['delta'] !== null && abs($r['delta']) < $f['minbytes']) return false;
        return true;
    }));
}

/**
 * Raccoglie fino a $want revisioni che passano i filtri, scorrendo la
 * cronologia. Si ferma dopo 20 schermate (10.000 revisioni lette).
 * @return array  righe; se più di $want, la richiesta va ristretta
 */
function wk_collect(string $lang, int $pageid, array $f, int $want): array
{
    $out = [];
    $cont = null;
    for ($i = 0; $i < 20 && count($out) <= $want; $i++) {
        [$rows, $cont] = wiki_history($lang, $pageid, $f, $cont, 500);
        $rows = wiki_annotate($rows, 500);
        $bots = $f['hidebots'] ? wiki_bots($lang, array_column($rows, 'user')) : [];
        $out = array_merge($out, wk_apply($rows, $f, $bots));
        if ($cont === null) break;
    }
    return $out;
}

function wk_back(string $to, string $msg): never
{
    $_SESSION['flash'] = $msg;
    header('Location: ' . $to);
    exit;
}

/* -------------------------------------------------- acquisizione (POST) -- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $lang   = (string)($_POST['lang'] ?? '');
    $pageid = (int)($_POST['pageid'] ?? 0);
    $mode   = (string)($_POST['mode'] ?? 'sel');
    $f      = wk_filters($_POST);
    if (!wiki_valid_lang($lang) || $pageid <= 0) {
        wk_back('wiki.php', 'Voce non valida.');
    }
    try {
        $page = wiki_resolve_page($lang, null, null, $pageid);
        if (!$page) {
            wk_back('wiki.php', 'La voce non esiste più su Wikipedia.');
        }
        $hist = 'wiki.php?' . http_build_query(['lang' => $lang, 'title' => $page['title']] + array_filter($f));
        $revids = [];
        switch ($mode) {
            case 'sel':
                $revids = array_slice(array_values(array_filter(array_map('intval', (array)($_POST['rev'] ?? [])), fn($x) => $x > 0)), 0, WIKI_MAX_REVS + 1);
                if (!$revids) wk_back($hist, 'Nessuna revisione selezionata nella tabella.');
                break;
            case 'last':
                $n = max(1, min(WIKI_MAX_REVS, (int)($_POST['last_n'] ?? 10)));
                $revids = array_column(array_slice(wk_collect($lang, $pageid, $f, $n), 0, $n), 'revid');
                break;
            case 'range':
                $rf = wk_filters(['from' => $_POST['range_from'] ?? '', 'to' => $_POST['range_to'] ?? ''] + $_POST);
                if ($rf['from'] === '' || $rf['to'] === '' || $rf['from'] > $rf['to']) {
                    wk_back($hist, 'Intervallo non valido: indica entrambe le date, la prima non successiva alla seconda.');
                }
                $revids = array_column(wk_collect($lang, $pageid, $rf, WIKI_MAX_REVS), 'revid');
                break;
            case 'user':
                $uf = wk_filters(['user' => $_POST['by_user'] ?? ''] + $_POST);
                if ($uf['user'] === '') wk_back($hist, 'Indica il nome dell\'autore.');
                $revids = array_column(wk_collect($lang, $pageid, $uf, WIKI_MAX_REVS), 'revid');
                break;
            case 'at':
                $af = wk_filters(['to' => $_POST['at_date'] ?? '']);
                if ($af['to'] === '') wk_back($hist, 'Indica la data.');
                [$rows] = wiki_history($lang, $pageid, ['to' => $af['to']], null, 1);
                $revids = $rows ? [$rows[0]['revid']] : [];
                if (!$revids) wk_back($hist, 'In quella data la voce non esisteva ancora.');
                break;
            default:
                wk_back($hist, 'Modalità sconosciuta.');
        }
        if (!$revids) {
            wk_back($hist, 'Nessuna revisione corrisponde alla richiesta.');
        }
        if (count($revids) > WIKI_MAX_REVS) {
            wk_back($hist, 'La richiesta comprende più di ' . WIKI_MAX_REVS . ' revisioni: restringi l\'intervallo o usa i filtri.');
        }
        $wid = wiki_page_id($pdo, $lang, $page['pageid'], $page['title']);
        [$done, $pending] = wiki_known_revs($pdo, $wid);
        $new = array_values(array_filter($revids, fn($id) => !isset($done[$id]) && !isset($pending[$id])));
        if (!$new) {
            wk_back("wiki.php?page=$wid", 'Le revisioni richieste sono già archiviate o in arrivo.');
        }
        [$short, $started] = wiki_enqueue($pdo, $wid, $lang, $page['title'], $new);
        audit('WIKI acquire ip=' . client_ip() . " short=$short $lang:" . $page['title'] . ' revs=' . count($new));
        $skip = count($revids) - count($new);
        wk_back("wiki.php?page=$wid", ($started ? "Acquisizione avviata ($short)" : "Acquisizione in coda ($short)")
            . ': ' . count($new) . ' ' . (count($new) === 1 ? 'revisione' : 'revisioni')
            . ($skip ? ", $skip già archiviate o in arrivo" : '') . '.');
    } catch (PDOException $ex) {
        // PDOException è una RuntimeException: va intercettata prima, o un
        // guasto del database verrebbe attribuito a Wikipedia.
        wk_back('wiki.php', 'Archivio occupato, riprova fra qualche secondo (' . $ex->getMessage() . ').');
    } catch (RuntimeException $ex) {
        wk_back('wiki.php', 'Wikipedia non raggiungibile: ' . $ex->getMessage());
    }
}

/* -------------------------------------------------- indirizzo incollato -- */
if (isset($_GET['u'])) {
    $p = wiki_parse_url((string)$_GET['u']);
    if (!$p) {
        wk_back('wiki.php', 'Non è un indirizzo di una voce di Wikipedia (es. https://it.wikipedia.org/wiki/Roma).');
    }
    try {
        $page = wiki_resolve_page($p['lang'], $p['title'], $p['oldid'] ?? $p['diff'], $p['curid']);
    } catch (RuntimeException $ex) {
        wk_back('wiki.php', 'Wikipedia non raggiungibile: ' . $ex->getMessage());
    }
    if (!$page) {
        wk_back('wiki.php', 'Voce non trovata su Wikipedia.');
    }
    $sel = array_filter([$p['oldid'], $p['diff']]);
    header('Location: wiki.php?' . http_build_query(['lang' => $page['lang'], 'title' => $page['title']]
        + ($sel ? ['sel' => implode(',', $sel)] : [])));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

layout_head('Snapper — Wikipedia');
layout_masthead('wiki');
echo '<div class="wrap">';
if ($flash) {
    echo '<p class="flash">' . h($flash) . '</p>';
}

/* ======================================================= dossier ======= */
if (isset($_GET['page'])) {
    $wid = (int)$_GET['page'];
    $s = $pdo->prepare('SELECT * FROM wiki_pages WHERE id=?');
    $s->execute([$wid]);
    $wp = $s->fetch();
    $s->closeCursor();
    if (!$wp) {
        echo '<div class="empty">Dossier inesistente. <a href="wiki.php">Torna all\'elenco</a>.</div></div>';
        layout_foot();
        exit;
    }
    $revs = $pdo->prepare("SELECT r.*, s.status FROM wiki_revisions r JOIN snapshots s ON s.short = r.short
                           WHERE r.wiki_page = ? ORDER BY r.ts DESC, r.revid DESC");
    $revs->execute([$wid]);
    $revs = $revs->fetchAll();
    $jobs = $pdo->prepare("SELECT s.short, s.status, s.status_msg, s.ts, j.revids FROM wiki_jobs j
                           JOIN snapshots s ON s.short = j.short WHERE j.wiki_page = ? ORDER BY s.ts DESC LIMIT 20");
    $jobs->execute([$wid]);
    $jobs = $jobs->fetchAll();
    $okN = count(array_filter($revs, fn($r) => (int)$r['sha1_ok'] === 1));
    ?>
    <div class="wk-head">
      <div>
        <span class="wk-kicker">Dossier · <?= h($wp['lang']) ?>.wikipedia</span>
        <h2 class="wk-title"><?= h($wp['title']) ?></h2>
        <span class="hint"><?= count($revs) ?> revisioni archiviate
          <?php if ($revs): ?> · provenienza: <?= $okN ?>/<?= count($revs) ?> wikitesti coincidono con lo sha1 di Wikipedia<?php endif; ?></span>
      </div>
      <div class="wk-actions">
        <a class="wk-btn" href="wiki.php?<?= h(http_build_query(['lang' => $wp['lang'], 'title' => $wp['title']])) ?>">Cronologia e acquisizione</a>
        <a class="wk-btn ghost" href="<?= h(wiki_article_url($wp['lang'], $wp['title'])) ?>" target="_blank" rel="noopener noreferrer">Voce su Wikipedia ↗</a>
      </div>
    </div>

    <?php if ($jobs): ?>
      <div class="wk-jobs">
      <?php foreach ($jobs as $j): $n = count(json_decode((string)$j['revids'], true) ?: []); ?>
        <span class="wk-job"><?= status_stamp((string)$j['status']) ?>
          <a href="/archives/<?= h(rawurlencode($j['short'])) ?>/" target="_blank" rel="noopener"><?= h($j['short']) ?></a>
          · <?= $n ?> rev. · <?= h(ts_local($j['ts'])) ?>
          <?php if (!empty($j['status_msg'])): ?><span class="wk-warn"><?= h($j['status_msg']) ?></span><?php endif; ?>
        </span>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$revs): ?>
      <div class="empty">Nessuna revisione ancora archiviata per questa voce.</div>
    <?php else: ?>
      <div class="tbl-scroll"><table class="ledger wk-tbl">
        <thead><tr><th>Data (ora italiana)</th><th>Revisione</th><th>Autore</th><th>Commento</th><th class="num">Byte</th><th>sha1</th><th>Prova</th></tr></thead>
        <tbody>
        <?php foreach ($revs as $r):
            $base = '/archives/' . rawurlencode((string)$r['short']); ?>
          <tr>
            <td class="nowrap"><?= h(ts_local($r['ts'])) ?></td>
            <td class="nowrap"><a href="<?= $base ?>/rev/<?= (int)$r['revid'] ?>/" target="_blank" rel="noopener"><?= (int)$r['revid'] ?></a>
              · <a class="u" href="<?= $base ?>/rev/<?= (int)$r['revid'] ?>/wikitext.txt" target="_blank" rel="noopener">wikitesto</a></td>
            <td><?= $r['user'] === null ? '<i>nascosto</i>' : h($r['user']) ?></td>
            <td class="wk-cmt"><?= $r['comment'] === null ? '<i>nascosto</i>' : h($r['comment']) ?></td>
            <td class="num"><?= number_format((int)$r['size'], 0, ',', '.') ?></td>
            <td><?= (int)$r['sha1_ok'] === 1 ? '<span class="wk-ok" title="Coincide con lo sha1 pubblicato da Wikipedia">✓</span>'
                                             : '<span class="wk-bad" title="Non coincide con lo sha1 di Wikipedia">✗</span>' ?></td>
            <td class="nowrap"><a class="u" href="<?= $base ?>/" target="_blank" rel="noopener"><?= h($r['short']) ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif;
    echo '</div>';
    layout_foot();
    exit;
}

/* ======================================================= cronologia ===== */
if (isset($_GET['lang'], $_GET['title'])) {
    $lang  = (string)$_GET['lang'];
    $title = trim((string)$_GET['title']);
    $f     = wk_filters($_GET);
    $cont  = (isset($_GET['cont']) && preg_match('/^\d{14}\|\d+$/', (string)$_GET['cont'])) ? (string)$_GET['cont'] : null;
    $sel   = array_map('intval', array_filter(explode(',', (string)($_GET['sel'] ?? '')), 'ctype_digit'));
    $error = null;
    $rows = [];
    $next = null;
    $page = null;
    if (!wiki_valid_lang($lang) || !wiki_valid_title($title)) {
        $error = 'Voce non valida.';
    } else {
        try {
            $page = wiki_resolve_page($lang, $title);
            if (!$page) {
                $error = 'Voce non trovata su Wikipedia.';
            } else {
                [$raw, $next] = wiki_history($lang, $page['pageid'], $f, $cont);
                $raw = wiki_annotate($raw, WIKI_HISTORY_PAGE);
                $bots = wiki_bots($lang, array_column($raw, 'user'));
                $rows = wk_apply($raw, $f, $bots);
                $hiddenN = count($raw) - count($rows);
            }
        } catch (RuntimeException $ex) {
            $error = 'Wikipedia non raggiungibile: ' . $ex->getMessage();
        }
    }
    if ($error) {
        echo '<p class="flash">' . h($error) . '</p><p><a href="wiki.php">Torna all\'elenco</a></p></div>';
        layout_foot();
        exit;
    }
    $s = $pdo->prepare('SELECT id FROM wiki_pages WHERE lang=? AND pageid=?');
    $s->execute([$lang, $page['pageid']]);
    $wid = (int)($s->fetch()['id'] ?? 0);
    $s->closeCursor();
    [$done, $pending] = $wid ? wiki_known_revs($pdo, $wid) : [[], []];
    $base = ['lang' => $lang, 'title' => $page['title']];
    $fq = array_filter($f);
    ?>
    <div class="wk-head">
      <div>
        <span class="wk-kicker">Cronologia · <?= h($lang) ?>.wikipedia · pageid <?= (int)$page['pageid'] ?></span>
        <h2 class="wk-title"><?= h($page['title']) ?></h2>
        <span class="hint">Dalla più recente. Seleziona le revisioni da archiviare, oppure scegli una modalità qui sotto.</span>
      </div>
      <div class="wk-actions">
        <?php if ($wid): ?><a class="wk-btn" href="wiki.php?page=<?= $wid ?>">Dossier (<?= count($done) ?>)</a><?php endif; ?>
        <a class="wk-btn ghost" href="<?= h(wiki_article_url($lang, $page['title'])) ?>?action=history" target="_blank" rel="noopener noreferrer">Cronologia su Wikipedia ↗</a>
      </div>
    </div>

    <form class="wk-filters" method="get" action="wiki.php">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <input type="hidden" name="title" value="<?= h($page['title']) ?>">
      <label>Dal <input type="date" name="from" value="<?= h($f['from']) ?>"></label>
      <label>al <input type="date" name="to" value="<?= h($f['to']) ?>"></label>
      <label>Autore <input type="text" name="user" value="<?= h($f['user']) ?>" placeholder="nome o IP"></label>
      <label>Variazione minima <input type="number" name="minbytes" min="0" value="<?= $f['minbytes'] ?: '' ?>" placeholder="byte"></label>
      <label class="chk"><input type="checkbox" name="hideminor" value="1" <?= $f['hideminor'] ? 'checked' : '' ?>> nascondi minori</label>
      <label class="chk"><input type="checkbox" name="hidebots" value="1" <?= $f['hidebots'] ? 'checked' : '' ?>> nascondi bot</label>
      <label class="chk"><input type="checkbox" name="hiderev" value="1" <?= $f['hiderev'] ? 'checked' : '' ?>> nascondi revert e modifiche annullate</label>
      <button type="submit">Applica</button>
      <?php if ($fq): ?><a class="hint" href="wiki.php?<?= h(http_build_query($base)) ?>">Azzera filtri</a><?php endif; ?>
    </form>

    <form method="post" action="wiki.php" id="acq">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <input type="hidden" name="pageid" value="<?= (int)$page['pageid'] ?>">
      <?php foreach ($fq as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>"><?php endforeach; ?>

      <div class="panel wk-modes">
        <h2>Acquisisci</h2>
        <label class="mode"><input type="radio" name="mode" value="sel" checked> le revisioni <b>selezionate</b> nella tabella <span class="hint" id="selcount"></span></label>
        <label class="mode"><input type="radio" name="mode" value="last"> le ultime
          <input type="number" name="last_n" min="1" max="<?= WIKI_MAX_REVS ?>" value="10" class="wk-n"> revisioni, con i filtri attivi</label>
        <label class="mode"><input type="radio" name="mode" value="range"> tutte quelle dal
          <input type="date" name="range_from" value="<?= h($f['from']) ?>"> al <input type="date" name="range_to" value="<?= h($f['to']) ?>">, con i filtri attivi</label>
        <label class="mode"><input type="radio" name="mode" value="user"> tutte le modifiche dell'autore
          <input type="text" name="by_user" value="<?= h($f['user']) ?>" placeholder="nome o IP" class="wk-u"></label>
        <label class="mode"><input type="radio" name="mode" value="at"> la versione <b>in vigore</b> il
          <input type="date" name="at_date"></label>
        <div class="wk-go">
          <button type="submit">Acquisisci</button>
          <span class="hint">Al massimo <?= WIKI_MAX_REVS ?> revisioni per volta; quelle già archiviate vengono saltate.
            Per ognuna: wikitesto verificato con lo sha1 di Wikipedia, copia navigabile senza rete, manifesto marcato.</span>
        </div>
      </div>

      <?php if (!$rows): ?>
        <div class="empty">Nessuna revisione in questa schermata<?= $hiddenN ? " ($hiddenN nascoste dai filtri)" : '' ?>.</div>
      <?php else: ?>
      <div class="toolbar"><span class="count"><?= count($rows) ?> revisioni mostrate<?= $hiddenN ? " · $hiddenN nascoste dai filtri" : '' ?></span></div>
      <div class="tbl-scroll"><table class="ledger wk-tbl">
        <thead><tr><th></th><th>Data (ora italiana)</th><th>Autore</th><th>Commento</th><th class="num">Δ byte</th><th>Segni</th><th>Snapper</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $id = $r['revid'];
            $isBot = $r['user'] !== null && isset($bots[$r['user']]);
            $d = $r['delta'];
            $known = isset($done[$id]) || isset($pending[$id]); ?>
          <tr class="<?= ($r['is_revert'] || $r['was_reverted']) ? 'wk-rv' : '' ?>">
            <td><input type="checkbox" name="rev[]" value="<?= $id ?>" aria-label="Seleziona la revisione <?= $id ?>"
                 <?= $known ? 'disabled' : (in_array($id, $sel, true) ? 'checked' : '') ?>></td>
            <td class="nowrap"><a class="u" href="<?= h(wiki_oldid_url($lang, $id)) ?>" target="_blank" rel="noopener noreferrer"><?= h(ts_local($r['ts'])) ?></a></td>
            <td><?= $r['user'] === null ? '<i>nascosto</i>' : h($r['user']) ?></td>
            <td class="wk-cmt"><?= $r['comment'] === null ? '<i>nascosto</i>' : h($r['comment']) ?></td>
            <td class="num <?= $d === null ? '' : ($d > 0 ? 'wk-plus' : ($d < 0 ? 'wk-minus' : '')) ?>"><?= $d === null ? '' : ($d > 0 ? '+' : '') . number_format($d, 0, ',', '.') ?></td>
            <td class="wk-signs">
              <?php if ($r['minor']): ?><span class="wk-b" title="Modifica minore">m</span><?php endif; ?>
              <?php if ($r['anon']): ?><span class="wk-b" title="Autore non registrato">IP</span><?php endif; ?>
              <?php if ($isBot): ?><span class="wk-b" title="Account del gruppo bot">bot</span><?php endif; ?>
              <?php if ($r['is_revert']): ?><span class="wk-b rv" title="Annulla modifiche precedenti">revert</span><?php endif; ?>
              <?php if ($r['was_reverted']): ?><span class="wk-b rd" title="Questa modifica è stata annullata in seguito">annullata</span><?php endif; ?>
              <?php if ($r['restores']): ?><span class="wk-b rv" title="Il testo torna identico (stesso sha1) a quello della revisione <?= (int)$r['restores']['revid'] ?>">= <?= h(ts_local($r['restores']['ts'])) ?></span><?php endif; ?>
            </td>
            <td class="nowrap">
              <?php if (isset($done[$id])): ?><a class="wk-b ok" href="/archives/<?= h(rawurlencode($done[$id])) ?>/rev/<?= $id ?>/" target="_blank" rel="noopener">archiviata</a>
              <?php elseif (isset($pending[$id])): ?><span class="wk-b">in arrivo</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </form>

    <nav class="pager">
      <?php if ($cont !== null): ?><a href="wiki.php?<?= h(http_build_query($base + $fq)) ?>">« più recenti</a><?php endif; ?>
      <?php if ($next !== null): ?><a href="wiki.php?<?= h(http_build_query($base + $fq + ['cont' => $next])) ?>">più vecchie ›</a><?php endif; ?>
    </nav>

    <script>
    (function () {
      var boxes = document.querySelectorAll('#acq input[name="rev[]"]');
      var out = document.getElementById('selcount');
      var sel = document.querySelector('#acq input[value="sel"]');
      function upd() {
        var n = 0; boxes.forEach(function (b) { if (b.checked) n++; });
        out.textContent = n ? '(' + n + ')' : '';
        if (n) sel.checked = true;
      }
      boxes.forEach(function (b) { b.addEventListener('change', upd); });
      upd();
    })();
    </script>
    <?php
    echo '</div>';
    layout_foot();
    exit;
}

/* ======================================================= elenco ========= */
$pages = $pdo->query("SELECT p.*, COUNT(DISTINCT r.revid) n, MAX(r.ts) last_rev,
                             (SELECT MAX(s.ts) FROM wiki_jobs j JOIN snapshots s ON s.short = j.short WHERE j.wiki_page = p.id) last_job
                      FROM wiki_pages p LEFT JOIN wiki_revisions r ON r.wiki_page = p.id
                      GROUP BY p.id ORDER BY last_job DESC, p.title")->fetchAll();
?>
<div class="panels">
  <div class="panel">
    <h2>Voce di Wikipedia</h2>
    <form method="get" action="wiki.php" autocomplete="off">
      <label for="u">Indirizzo della voce, di una revisione o di un confronto</label>
      <input id="u" type="url" name="u" placeholder="https://it.wikipedia.org/wiki/Roma" required>
      <button type="submit">Apri la cronologia</button>
      <span class="hint">Qualunque lingua. Vanno bene anche indirizzi con <code>?oldid=</code> o <code>?diff=</code>:
        le revisioni indicate risultano già selezionate.</span>
    </form>
  </div>
  <div class="panel">
    <h2>Cosa resta archiviato</h2>
    <p class="hint wk-explain">Per ogni revisione: il <b>wikitesto esatto</b>, verificato con lo sha1 che Wikipedia
      pubblica (chiunque può ripetere la verifica), la <b>pagina navigabile senza rete</b> con immagini e stili
      copiati in locale, i metadati, e un manifesto SHA-256 marcato con OpenTimestamps.
      La resa grafica usa i template di oggi: per le revisioni vecchie è un'approssimazione, e la pagina lo dichiara.</p>
  </div>
</div>

<?php if (!$pages): ?>
  <div class="empty">Nessun dossier ancora: incolla l'indirizzo di una voce qui sopra.</div>
<?php else: ?>
  <div class="tbl-scroll"><table class="ledger wk-tbl">
    <thead><tr><th>Voce</th><th>Lingua</th><th class="num">Revisioni archiviate</th><th>Revisione più recente</th><th>Ultima acquisizione</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pages as $p): ?>
      <tr>
        <td><a href="wiki.php?page=<?= (int)$p['id'] ?>"><b><?= h($p['title']) ?></b></a></td>
        <td><?= h($p['lang']) ?></td>
        <td class="num"><?= (int)$p['n'] ?></td>
        <td class="nowrap"><?= $p['last_rev'] ? h(ts_local($p['last_rev'])) : '—' ?></td>
        <td class="nowrap"><?= $p['last_job'] ? h(ts_local($p['last_job'])) : '—' ?></td>
        <td class="nowrap"><a class="u" href="wiki.php?<?= h(http_build_query(['lang' => $p['lang'], 'title' => $p['title']])) ?>">cronologia</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php
layout_foot();
