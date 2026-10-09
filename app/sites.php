<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/crawllib.php';
require_login();

/* =========================================================================
 * Snapper – siti interi.
 *   sites.php                 modulo, stime, download in corso e conclusi
 *   sites.php?est=<id>        risultato di una stima
 *   sites.php?site=<short>    dettaglio di un sito scaricato (con ricerca)
 *   sites.php?progress=1      avanzamento in JSON (per l'aggiornamento della pagina)
 * =======================================================================*/

$csrf = csrf_token();
$pdo  = db();
$presets = crawl_presets();

function st_back(string $to, string $msg): never
{
    $_SESSION['flash'] = $msg;
    header('Location: ' . $to);
    exit;
}

/** opzioni dal modulo: se le avanzate sono state toccate valgono quelle, altrimenti il profilo */
function st_form_options(array $src, array $presets): array
{
    $p = $presets[(string)($src['preset'] ?? 'sezione')] ?? $presets['sezione'];
    if (empty($src['adv'])) {
        return crawl_options($p['o']);
    }
    return crawl_options([
        'scope' => $src['scope'] ?? null, 'include' => $src['include'] ?? '', 'exclude' => $src['exclude'] ?? '',
        'accept_re' => $src['accept_re'] ?? '', 'reject_re' => $src['reject_re'] ?? '',
        'depth' => $src['depth'] ?? null, 'pages' => $src['pages'] ?? null, 'mb' => $src['mb'] ?? null, 'minutes' => $src['minutes'] ?? null,
        'types' => (array)($src['types'] ?? []), 'external' => $src['external'] ?? null,
        'traps' => !empty($src['traps']), 'robots' => !empty($src['robots']),
        'delay' => $src['delay'] ?? null, 'rate' => $src['rate'] ?? null, 'drop' => $src['drop'] ?? '',
    ]);
}

function st_progress(string $short): ?array
{
    $f = DATA_DIR . '/' . $short . '/.progress.json';
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($j) ? $j : null;
}

/* -------------------------------------------------- avanzamento (JSON) -- */
if (isset($_GET['progress'])) {
    header('Content-Type: application/json');
    $out = ['sites' => [], 'estimates' => []];
    foreach ($pdo->query("SELECT short, status FROM snapshots WHERE kind='site' AND status IN ('pending','running')") as $r) {
        $out['sites'][$r['short']] = ['status' => $r['status'], 'p' => st_progress((string)$r['short'])];
    }
    foreach ($pdo->query("SELECT id, status, result FROM site_estimates WHERE status='running'") as $r) {
        $out['estimates'][$r['id']] = json_decode((string)$r['result'], true);
    }
    echo json_encode($out);
    exit;
}

/* -------------------------------------------------- azioni (POST) -------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');

    if ($act === 'cancel') {
        $short = (string)($_POST['short'] ?? '');
        if (!preg_match('/^[A-Za-z0-9]{5,12}$/', $short)) st_back('sites.php', 'Codice non valido.');
        $s = $pdo->prepare("SELECT status FROM snapshots WHERE short=? AND kind='site'");
        $s->execute([$short]);
        $stt = (string)($s->fetch()['status'] ?? '');
        $s->closeCursor();
        if ($stt === 'pending') {
            $pdo->prepare("UPDATE snapshots SET status='error', status_msg='annullata prima dell''avvio', done_at=CURRENT_TIMESTAMP WHERE short=? AND status='pending'")
                ->execute([$short]);
            st_back('sites.php', "Download $short annullato prima dell'avvio.");
        }
        if ($stt === 'running') {
            @touch(DATA_DIR . '/' . $short . '/.cancel');
            audit('SITE cancel ip=' . client_ip() . " short=$short");
            st_back('sites.php', "Interruzione richiesta per $short: quanto già scaricato viene sigillato e resta consultabile.");
        }
        st_back('sites.php', 'Niente da interrompere.');
    }

    $url = trim((string)($_POST['url'] ?? ''));
    if ($act === 'start_est') {
        $s = $pdo->prepare('SELECT * FROM site_estimates WHERE id=?');
        $s->execute([(int)($_POST['est'] ?? 0)]);
        $est = $s->fetch();
        $s->closeCursor();
        if (!$est) st_back('sites.php', 'Stima inesistente.');
        $url = (string)$est['url'];
        $o = crawl_options(json_decode((string)$est['options'], true) ?: []);
    } else {
        $o = st_form_options($_POST, $presets);
    }
    [$ok, $reason, $host] = validate_public_url($url);
    if (!$ok) st_back('sites.php', "Indirizzo rifiutato: $reason");
    $start = crawl_norm($url);
    if ($start === null) st_back('sites.php', 'Indirizzo non valido.');

    if ($act === 'estimate') {
        $busy = (int)$pdo->query("SELECT count(*) n FROM site_estimates WHERE status='running' AND created > datetime('now','-15 minutes')")->fetch()['n'];
        if ($busy) st_back('sites.php', 'C\'è già una stima in corso: attendi che finisca.');
        $pdo->prepare('INSERT INTO site_estimates(url, options) VALUES(?,?)')->execute([$start, json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        $id = (int)$pdo->lastInsertId();
        $cmd = 'PATH=' . WORKER_PATH . ' nohup ' . escapeshellarg(PHP_BINARY ?: '/usr/bin/php') . ' ' . escapeshellarg(__DIR__ . '/crawl-worker.php')
             . ' estimate ' . $id . ' >> ' . escapeshellarg(DATA_DIR . '/worker.log') . ' 2>&1 &';
        shell_exec($cmd);
        audit('SITE estimate ip=' . client_ip() . " id=$id host=$host");
        st_back("sites.php?est=$id", 'Stima avviata: legge solo le pagine (al massimo 5 minuti), senza salvare nulla.');
    }
    if ($act === 'start' || $act === 'start_est') {
        [$short, $started] = site_enqueue($pdo, $start, $o, 'web');
        audit('SITE start ip=' . client_ip() . " short=$short host=$host scope={$o['scope']} pages={$o['pages']}");
        st_back('sites.php', ($started ? "Download avviato ($short)" : "Download in coda ($short)") . ': si può seguire qui sotto.');
    }
    st_back('sites.php', 'Azione sconosciuta.');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* stime rimaste appese (processo terminato in modo anomalo) */
$pdo->exec("UPDATE site_estimates SET status='error', result='{\"error\":\"stima interrotta\"}', finished=CURRENT_TIMESTAMP
            WHERE status='running' AND created < datetime('now','-15 minutes')");

layout_head('Snapper — siti');
layout_masthead('sites');
echo '<div class="wrap">';
if ($flash) echo '<p class="flash">' . h($flash) . '</p>';

$typeLabels = ['html' => 'pagine', 'img' => 'immagini', 'css' => 'fogli di stile', 'js' => 'script', 'font' => 'caratteri',
               'pdf' => 'PDF', 'doc' => 'documenti', 'media' => 'audio e video', 'archive' => 'archivi compressi', 'other' => 'altro'];

/* ======================================================= stima ========== */
if (isset($_GET['est'])) {
    $s = $pdo->prepare('SELECT * FROM site_estimates WHERE id=?');
    $s->execute([(int)$_GET['est']]);
    $est = $s->fetch();
    $s->closeCursor();
    if (!$est) {
        echo '<div class="empty">Stima inesistente. <a href="sites.php">Torna ai siti</a>.</div></div>';
        layout_foot();
        exit;
    }
    $res = json_decode((string)$est['result'], true) ?: [];
    $o = crawl_options(json_decode((string)$est['options'], true) ?: []);
    ?>
    <div class="wk-head">
      <div>
        <span class="wk-kicker">Stima a vuoto · <?= h(crawl_host((string)$est['url'])) ?></span>
        <h2 class="wk-title"><?= h((string)$est['url']) ?></h2>
        <span class="hint">ambito <?= h($o['scope']) ?> · profondità <?= $o['depth'] ?> · max <?= $o['pages'] ?> pagine · <?= $o['mb'] ?> MB · <?= $o['minutes'] ?> min</span>
      </div>
      <div class="wk-actions"><a class="wk-btn ghost" href="sites.php">Tutti i siti</a></div>
    </div>
    <?php if ($est['status'] === 'running'): ?>
      <div class="panel st-live" data-est="<?= (int)$est['id'] ?>"><h2>Stima in corso</h2>
        <p class="hint">Legge le pagine senza salvarle e conta le risorse; per la dimensione ne misura un campione. Al massimo 5 minuti.</p>
        <p class="st-p">pagine lette: <b class="st-n"><?= (int)($res['progress'] ?? 0) ?></b></p></div>
    <?php elseif ($est['status'] === 'error'): ?>
      <p class="flash">Stima non riuscita: <?= h((string)($res['error'] ?? 'errore')) ?></p>
    <?php else:
        $more = !empty($res['more']); ?>
      <div class="st-facts">
        <div class="st-fact"><span class="n"><?= (int)$res['pages'] ?><?= $more ? '+' : '' ?></span><span class="k">pagine<?= $more ? ' (lettura fermata da tempo o limite: il sito ne ha di più)' : '' ?></span></div>
        <div class="st-fact"><span class="n">~<?= human_size((int)$res['bytes']) ?></span><span class="k">dimensione stimata del download</span></div>
        <div class="st-fact"><span class="n"><?= array_sum($res['assets'] ?? []) ?></span><span class="k">risorse da scaricare (immagini, stili, documenti…)</span></div>
        <div class="st-fact"><span class="n"><?= count($res['blocked'] ?? []) ?></span><span class="k">richieste bloccate per sicurezza</span></div>
      </div>
      <div class="panels">
        <div class="panel"><h2>Directory più popolose</h2>
          <?php foreach ($res['dirs'] ?? [] as $d => $n): ?><div class="wd-stat"><span class="st-mono"><?= h($d) ?></span><b><?= (int)$n ?></b></div><?php endforeach; ?>
        </div>
        <div class="panel"><h2>Risorse per tipo</h2>
          <?php foreach ($res['assets'] ?? [] as $t => $n): ?><div class="wd-stat"><span><?= h($typeLabels[$t] ?? $t) ?></span><b><?= (int)$n ?><?= !empty($res['avg'][$t]) ? ' · ~' . h(human_size((int)$res['avg'][$t])) . ' l\'una' : '' ?></b></div><?php endforeach; ?>
        </div>
      </div>
      <?php if (!empty($res['traps'])): ?>
        <div class="panel st-warnp"><h2>Trappole riconosciute ed escluse</h2>
          <?php foreach ($res['traps'] as $why => [$n, $ex]): ?>
            <p><b><?= h($why) ?></b> · <?= (int)$n ?> indirizzi, es. <span class="st-mono"><?= h((string)($ex[0] ?? '')) ?></span></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <details class="panel"><summary>Escluse dai filtri (<?= array_sum(array_map(fn($x) => $x[0], $res['skip'] ?? [])) ?>) · domini esterni (<?= count($res['ext'] ?? []) ?>)</summary>
        <?php foreach ($res['skip'] ?? [] as $why => [$n, $ex]): ?>
          <div class="wd-stat"><span><?= h($why) ?></span><b><?= (int)$n ?></b></div>
        <?php endforeach; ?>
        <?php if (!empty($res['ext'])): ?><p class="hint">Domini esterni citati: <?= h(implode(', ', array_keys($res['ext']))) ?></p><?php endif; ?>
        <?php foreach ($res['blocked'] ?? [] as [$u, $why]): ?><p class="wk-warn"><?= h($u) ?> — <?= h($why) ?></p><?php endforeach; ?>
      </details>
      <form method="post" action="sites.php" class="st-go">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="act" value="start_est">
        <input type="hidden" name="est" value="<?= (int)$est['id'] ?>">
        <button type="submit">Scarica con queste opzioni</button>
        <span class="hint">Oppure torna ai <a href="sites.php">siti</a> e correggi filtri e limiti.</span>
      </form>
    <?php endif;
    echo '</div>';
    st_poll_script();
    layout_foot();
    exit;
}

/* ======================================================= dettaglio ====== */
if (isset($_GET['site'])) {
    $short = (string)$_GET['site'];
    $s = $pdo->prepare("SELECT s.*, j.options FROM snapshots s LEFT JOIN site_jobs j ON j.short = s.short WHERE s.short=? AND s.kind='site'");
    $s->execute([$short]);
    $snap = $s->fetch();
    $s->closeCursor();
    if (!$snap) {
        echo '<div class="empty">Sito inesistente. <a href="sites.php">Torna ai siti</a>.</div></div>';
        layout_foot();
        exit;
    }
    $o = crawl_options(json_decode((string)$snap['options'], true) ?: []);
    $base = '/archives/' . rawurlencode($short);
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['p'] ?? 1));
    $types = [];
    $s = $pdo->prepare("SELECT type, count(*) n, sum(size) b, sum(note IS NOT NULL) e FROM site_pages WHERE short=? GROUP BY type ORDER BY n DESC");
    $s->execute([$short]);
    $types = $s->fetchAll();
    ?>
    <div class="wk-head">
      <div>
        <span class="wk-kicker">Sito · prova <?= h($short) ?> · <?= status_stamp((string)$snap['status']) ?></span>
        <h2 class="wk-title"><?= h(crawl_host((string)$snap['url'])) ?></h2>
        <span class="hint"><?= h((string)$snap['url']) ?> · <?= h(ts_local($snap['ts'])) ?></span>
      </div>
      <div class="wk-actions">
        <?php if ($snap['status'] === 'ready'): ?>
          <a class="wk-btn" href="<?= $base ?>/" target="_blank" rel="noopener">Scheda della prova</a>
          <a class="wk-btn ghost" href="<?= $base ?>/indice-sito.html" target="_blank" rel="noopener">Indice delle pagine</a>
          <a class="wk-btn ghost" href="<?= $base ?>/warc/<?= h(rawurlencode($short)) ?>.warc.gz">WARC</a>
        <?php endif; ?>
        <a class="wk-btn ghost" href="sites.php">Tutti i siti</a>
      </div>
    </div>
    <?php if (!empty($snap['status_msg'])): ?><p class="flash"><?= h((string)$snap['status_msg']) ?></p><?php endif; ?>
    <?php if (in_array($snap['status'], ['pending', 'running'], true)): $p = st_progress($short); ?>
      <div class="panel st-live" data-short="<?= h($short) ?>"><h2>Download in corso</h2>
        <p class="st-p"><?= $p ? st_progress_text($p) : 'in attesa di partire…' ?></p></div>
    <?php endif; ?>
    <p class="hint">Opzioni: ambito <?= h($o['scope']) ?> · profondità <?= $o['depth'] ?> · max <?= $o['pages'] ?> pagine, <?= $o['mb'] ?> MB, <?= $o['minutes'] ?> min ·
      tipi <?= h(implode(', ', array_map(fn($t) => $typeLabels[$t] ?? $t, $o['types']))) ?> · risorse esterne <?= $o['external'] === 'none' ? 'no' : 'sì' ?> ·
      robots.txt <?= $o['robots'] ? 'rispettato' : 'ignorato' ?><?= $o['reject_re'] !== '' ? ' · esclusi: <code>' . h($o['reject_re']) . '</code>' : '' ?></p>
    <?php if ($types): ?>
      <div class="st-facts">
        <?php foreach ($types as $t): ?>
          <div class="st-fact"><span class="n"><?= (int)$t['n'] - (int)$t['e'] ?></span><span class="k"><?= h($typeLabels[$t['type']] ?? $t['type']) ?> · <?= h(human_size((int)$t['b'])) ?><?= (int)$t['e'] ? ' · ' . (int)$t['e'] . ' in errore' : '' ?></span></div>
        <?php endforeach; ?>
      </div>
      <form method="get" action="sites.php" class="wk-filters">
        <input type="hidden" name="site" value="<?= h($short) ?>">
        <label>Cerca nelle pagine del sito <input type="text" name="q" value="<?= h($q) ?>" placeholder='parole, "frasi", prefissi*' class="st-q"></label>
        <button type="submit">Cerca</button>
        <?php if ($q !== ''): ?><a class="hint" href="sites.php?site=<?= h(rawurlencode($short)) ?>">Azzera</a><?php endif; ?>
      </form>
      <?php
      $per = 100;
      $rows = [];
      $err = null;
      try {
          if ($q !== '') {
              $s = $pdo->prepare("SELECT p.url, p.local, p.status, p.size, p.depth, p.title, snippet(site_pages_fts, 3, '«', '»', '…', 12) ex
                                  FROM site_pages_fts f JOIN site_pages p ON p.short = f.short AND p.url = f.url
                                  WHERE site_pages_fts MATCH ? AND f.short = ? ORDER BY bm25(site_pages_fts) LIMIT 200");
              $s->execute([$q, $short]);
          } else {
              $s = $pdo->prepare("SELECT url, local, status, size, depth, title, note FROM site_pages WHERE short=? AND type='html'
                                  ORDER BY note IS NOT NULL, depth, url LIMIT ? OFFSET ?");
              $s->bindValue(1, $short);
              $s->bindValue(2, $per, PDO::PARAM_INT);
              $s->bindValue(3, ($page - 1) * $per, PDO::PARAM_INT);
              $s->execute();
          }
          $rows = $s->fetchAll();
      } catch (Throwable) {
          $err = 'Sintassi di ricerca non valida.';
      }
      if ($err) echo '<p class="flash">' . h($err) . '</p>';
      ?>
      <div class="tbl-scroll"><table class="ledger wk-tbl">
        <thead><tr><th>Pagina</th><th class="num">HTTP</th><th class="num">Livello</th><th class="num">Byte</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <?php if (!empty($r['local']) && $snap['status'] === 'ready'): ?><a href="<?= $base ?>/<?= h(implode('/', array_map('rawurlencode', explode('/', (string)$r['local'])))) ?>" target="_blank" rel="noopener"><?= h($r['title'] ?: $r['url']) ?></a>
              <?php else: ?><?= h($r['title'] ?: $r['url']) ?><?php endif; ?>
              <br><span class="u"><?= h($r['url']) ?></span>
              <?php if (!empty($r['note'])): ?><br><span class="wk-warn"><?= h($r['note']) ?></span><?php endif; ?>
              <?php if (!empty($r['ex'])): ?><br><span class="hint"><?= str_replace(['«', '»'], ['<mark>', '</mark>'], h($r['ex'])) ?></span><?php endif; ?>
            </td>
            <td class="num"><?= (int)$r['status'] ?: '—' ?></td>
            <td class="num"><?= (int)$r['depth'] ?></td>
            <td class="num"><?= number_format((int)$r['size'], 0, ',', '.') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="4" class="hint">Nessuna pagina<?= $q !== '' ? ' per questa ricerca' : '' ?>.</td></tr><?php endif; ?>
        </tbody></table></div>
      <?php if ($q === '' && count($rows) === $per): ?>
        <nav class="pager"><?php if ($page > 1): ?><a href="sites.php?site=<?= h(rawurlencode($short)) ?>&amp;p=<?= $page - 1 ?>">‹ prec</a><?php endif; ?>
          <span class="cur"><?= $page ?></span><a href="sites.php?site=<?= h(rawurlencode($short)) ?>&amp;p=<?= $page + 1 ?>">succ ›</a></nav>
      <?php endif; ?>
    <?php endif;
    echo '</div>';
    st_poll_script();
    layout_foot();
    exit;
}

/* ======================================================= elenco ========= */
$sites = $pdo->query("SELECT s.*, (SELECT count(*) FROM site_pages p WHERE p.short = s.short AND p.type='html' AND p.note IS NULL) np
                      FROM snapshots s WHERE s.kind='site' ORDER BY s.ts DESC LIMIT 100")->fetchAll();
$ests = $pdo->query("SELECT * FROM site_estimates ORDER BY id DESC LIMIT 8")->fetchAll();
$d = crawl_defaults();
?>
<div class="panel st-form">
  <h2>Scarica un sito</h2>
  <form method="post" action="sites.php" autocomplete="off" id="stf">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <label for="url">Indirizzo di partenza</label>
    <input id="url" type="url" name="url" placeholder="https://esempio.org/documentazione/" required>
    <div class="st-presets">
      <?php $first = true; foreach ($presets as $k => $p): ?>
        <label class="st-preset"><input type="radio" name="preset" value="<?= h($k) ?>" <?= $first ? 'checked' : '' ?>>
          <span><b><?= h($p['label']) ?></b><span class="hint"><?= h($p['hint']) ?> · max <?= (int)$p['o']['pages'] ?> pagine, <?= human_size((int)$p['o']['mb'] * 1048576) ?>, <?= (int)$p['o']['minutes'] ?> min</span></span></label>
      <?php $first = false; endforeach; ?>
    </div>
    <details class="st-adv" id="adv">
      <summary>Filtri e limiti</summary>
      <input type="hidden" name="adv" value="" id="advflag">
      <div class="st-grid">
        <fieldset><legend>Ambito</legend>
          <label class="chk"><input type="radio" name="scope" value="path" checked> solo sotto il percorso indicato</label>
          <label class="chk"><input type="radio" name="scope" value="host"> tutto l'host</label>
          <label class="chk"><input type="radio" name="scope" value="subdomains"> host e sottodomini</label>
          <label>Solo queste directory <span class="hint">(una per riga)</span><textarea name="include" rows="2" placeholder="/docs/&#10;/guide/"></textarea></label>
          <label>Escludi queste directory<textarea name="exclude" rows="2" placeholder="/forum/&#10;/shop/"></textarea></label>
        </fieldset>
        <fieldset><legend>Limiti</legend>
          <label>Profondità dei collegamenti <input type="number" name="depth" min="0" max="50" value="<?= $d['depth'] ?>"></label>
          <label>Pagine al massimo <input type="number" name="pages" min="1" max="20000" value="<?= $d['pages'] ?>"></label>
          <label>Dimensione massima (MB) <input type="number" name="mb" min="1" max="20480" value="<?= $d['mb'] ?>"></label>
          <label>Durata massima (minuti) <input type="number" name="minutes" min="1" max="720" value="<?= $d['minutes'] ?>"></label>
        </fieldset>
        <fieldset><legend>Tipi di file</legend>
          <?php foreach (['img', 'css', 'font', 'js', 'pdf', 'doc', 'media', 'archive', 'other'] as $t): ?>
            <label class="chk"><input type="checkbox" name="types[]" value="<?= $t ?>" <?= in_array($t, $d['types'], true) ? 'checked' : '' ?>> <?= h($typeLabels[$t]) ?></label>
          <?php endforeach; ?>
          <span class="hint">Le pagine sono sempre comprese. Gli script si conservano nel WARC ma non nella copia navigabile.</span>
        </fieldset>
        <fieldset><legend>Filtri sugli indirizzi</legend>
          <label>Includi solo se corrisponde a <span class="hint">(espressione regolare)</span><input type="text" name="accept_re" placeholder="/202[0-6]/"></label>
          <label>Escludi se corrisponde a<input type="text" name="reject_re" placeholder="/(tag|author)/|\?print="></label>
          <label>Parametri da ignorare negli indirizzi<input type="text" name="drop" value="<?= h(implode(' ', $d['drop'])) ?>"></label>
          <label class="chk"><input type="checkbox" name="traps" value="1" checked> escludi le trappole (calendari, ordinamenti, login, carrelli, percorsi ripetuti)</label>
        </fieldset>
        <fieldset><legend>Collegamenti esterni e cortesia</legend>
          <label class="chk"><input type="radio" name="external" value="requisites" checked> scarica le risorse esterne necessarie alla pagina (font, stili, immagini da CDN), senza seguire i link</label>
          <label class="chk"><input type="radio" name="external" value="none"> nessun contatto con altri siti</label>
          <label class="chk"><input type="checkbox" name="robots" value="1" checked> rispetta robots.txt</label>
          <label>Pausa fra le richieste (ms) <input type="number" name="delay" min="0" max="60000" value="<?= $d['delay'] ?>"></label>
          <label>Limite di banda (KB/s, 0 = nessuno) <input type="number" name="rate" min="0" max="100000" value="0"></label>
        </fieldset>
      </div>
    </details>
    <div class="st-go">
      <button type="submit" name="act" value="estimate" class="ghost">Stima prima</button>
      <button type="submit" name="act" value="start">Scarica</button>
      <span class="hint">La stima legge solo le pagine, per qualche minuto, e dice quanto è grande il sito e dove sono le trappole.
        Ogni richiesta è controllata: niente indirizzi locali o della rete di casa, nemmeno dopo un redirect.</span>
    </div>
  </form>
</div>

<?php if ($ests): ?>
  <h3 class="wd-h3">Stime recenti</h3>
  <div class="tbl-scroll"><table class="ledger wk-tbl">
    <thead><tr><th>Quando</th><th>Indirizzo</th><th>Esito</th><th></th></tr></thead><tbody>
    <?php foreach ($ests as $e): $r = json_decode((string)$e['result'], true) ?: []; ?>
      <tr>
        <td class="nowrap"><?= h(ts_local($e['created'])) ?></td>
        <td class="u"><?= h((string)$e['url']) ?></td>
        <td><?php if ($e['status'] === 'running'): ?><span class="st-live" data-est="<?= (int)$e['id'] ?>"><?= status_stamp('running') ?> <span class="st-n"><?= (int)($r['progress'] ?? 0) ?></span> pagine lette</span>
            <?php elseif ($e['status'] === 'error'): ?><span class="wk-bad"><?= h((string)($r['error'] ?? 'errore')) ?></span>
            <?php else: ?><?= (int)$r['pages'] ?><?= !empty($r['more']) ? '+' : '' ?> pagine · ~<?= h(human_size((int)$r['bytes'])) ?><?php endif; ?></td>
        <td class="nowrap"><a href="sites.php?est=<?= (int)$e['id'] ?>">dettaglio</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>

<h3 class="wd-h3">Siti scaricati</h3>
<?php if (!$sites): ?>
  <div class="empty">Nessun sito ancora.</div>
<?php else: ?>
  <div class="tbl-scroll"><table class="ledger wk-tbl">
    <thead><tr><th>Quando</th><th>Sito</th><th>Stato</th><th class="num">Pagine</th><th class="num">Peso</th><th></th></tr></thead><tbody>
    <?php foreach ($sites as $r): $sh = (string)$r['short']; $live = in_array($r['status'], ['pending', 'running'], true); ?>
      <tr>
        <td class="nowrap"><?= h(ts_local($r['ts'])) ?></td>
        <td><a href="sites.php?site=<?= h(rawurlencode($sh)) ?>"><b><?= h(crawl_host((string)$r['url'])) ?></b></a><br><span class="u"><?= h((string)$r['url']) ?></span>
          <?php if (!empty($r['status_msg'])): ?><br><span class="wk-warn"><?= h((string)$r['status_msg']) ?></span><?php endif; ?></td>
        <td class="nowrap"><?= status_stamp((string)$r['status']) ?>
          <?php if ($live): $p = st_progress($sh); ?><br><span class="hint st-live" data-short="<?= h($sh) ?>"><span class="st-p"><?= $p ? st_progress_text($p) : 'in attesa…' ?></span></span><?php endif; ?></td>
        <td class="num"><?= $r['status'] === 'ready' ? (int)$r['np'] : '' ?></td>
        <td class="num"><?= $r['status'] === 'ready' ? h(human_size((int)$r['size_bytes'])) : '' ?></td>
        <td class="nowrap">
          <?php if ($live): ?>
            <form method="post" action="sites.php" class="st-inline" onsubmit="return confirm('Interrompere il download? Quanto già scaricato resta e viene sigillato.')">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="act" value="cancel"><input type="hidden" name="short" value="<?= h($sh) ?>">
              <button type="submit" class="danger">Interrompi</button></form>
          <?php elseif ($r['status'] === 'ready'): ?>
            <a class="u" href="/archives/<?= h(rawurlencode($sh)) ?>/" target="_blank" rel="noopener">apri</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
<script>
(function () {
  // le opzioni avanzate valgono solo se aperte; il profilo scelto le precompila
  var presets = <?= json_encode(array_map(fn($p) => $p['o'], $presets), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var f = document.getElementById('stf'), adv = document.getElementById('adv'), flag = document.getElementById('advflag');
  function fill(o) {
    f.querySelectorAll('input[name=scope]').forEach(function (x) { x.checked = x.value === o.scope; });
    f.querySelectorAll('input[name=external]').forEach(function (x) { x.checked = x.value === o.external; });
    ['depth', 'pages', 'mb', 'minutes', 'delay', 'rate'].forEach(function (k) { f.elements[k].value = o[k]; });
    f.elements.include.value = o.include.join('\n'); f.elements.exclude.value = o.exclude.join('\n');
    f.elements.accept_re.value = o.accept_re; f.elements.reject_re.value = o.reject_re;
    f.elements.traps.checked = !!o.traps; f.elements.robots.checked = !!o.robots;
    f.querySelectorAll('input[name="types[]"]').forEach(function (x) { x.checked = o.types.indexOf(x.value) >= 0; });
  }
  f.querySelectorAll('input[name=preset]').forEach(function (r) {
    r.addEventListener('change', function () { fill(presets[r.value]); });
  });
  fill(presets[f.querySelector('input[name=preset]:checked').value]);
  adv.addEventListener('toggle', function () { flag.value = adv.open ? '1' : ''; });
})();
</script>
<?php
st_poll_script();
layout_foot();

/* ---- aiuti di presentazione ---------------------------------------------- */

function st_progress_text(array $p): string
{
    $phase = ['raccolta' => 'raccolta', 'riscrittura' => 'riscrittura dei collegamenti', 'sigillo' => 'manifesto e marca'][$p['phase'] ?? 'raccolta'] ?? 'raccolta';
    return h($phase) . ' · <b>' . (int)($p['pages'] ?? 0) . '</b> pagine · ' . (int)($p['files'] ?? 0) . ' file · '
        . h(human_size((int)($p['bytes'] ?? 0))) . ' · ' . (int)($p['queue'] ?? 0) . ' in coda'
        . (!empty($p['errors']) ? ' · ' . (int)$p['errors'] . ' errori' : '');
}

function st_poll_script(): void
{
    ?>
    <script>
    (function () {
      var live = document.querySelectorAll('.st-live');
      if (!live.length) return;
      function fmt(b) { var u = ['B', 'KB', 'MB', 'GB'], i = 0; while (b >= 1024 && i < 3) { b /= 1024; i++; } return (i ? b.toFixed(1) : b) + ' ' + u[i]; }
      var timer = setInterval(function () {
        fetch('sites.php?progress=1', {credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
          var done = false;
          live.forEach(function (el) {
            if (el.dataset.short) {
              var s = j.sites[el.dataset.short], out = el.querySelector('.st-p');
              if (!s) { done = true; return; }
              if (s.p && out) out.innerHTML = s.p.phase + ' · <b>' + s.p.pages + '</b> pagine · ' + s.p.files + ' file · ' + fmt(s.p.bytes) + ' · ' + s.p.queue + ' in coda';
            } else if (el.dataset.est) {
              var e = j.estimates[el.dataset.est];
              if (!e) { done = true; return; }
              var n = el.querySelector('.st-n'); if (n) n.textContent = e.progress || 0;
            }
          });
          if (done) {
            clearInterval(timer);
            var u = document.getElementById('url');
            if (u && u.value) {
              // non si ricarica sotto le mani di chi sta scrivendo un indirizzo
              var n = document.createElement('p'); n.className = 'flash ok';
              n.textContent = 'Un download o una stima è terminato: aggiorna la pagina per vederne l\'esito.';
              document.querySelector('.wrap').prepend(n);
            } else {
              setTimeout(function () { location.reload(); }, 800);
            }
          }
        }).catch(function () {});
      }, 4000);
    })();
    </script>
    <?php
}
