<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require_login();

$csrf  = csrf_token();
$q     = trim((string)($_GET['q'] ?? ''));
$view  = ($_GET['view'] ?? 'sheet') === 'ledger' ? 'ledger' : 'sheet';
$per   = $view === 'sheet' ? 48 : 120;
$page  = max(1, (int)($_GET['page'] ?? 1));
$off   = ($page - 1) * $per;

$pdo = db();

if ($q !== '') {
    $cnt = $pdo->prepare("SELECT COUNT(*) c FROM snapshots_fts f
                          JOIN snapshots s ON s.short = f.short
                          WHERE snapshots_fts MATCH :qq");
    try {
        $cnt->execute([':qq' => $q]);
        $total = (int)$cnt->fetch()['c'];
        $st = $pdo->prepare("SELECT s.* FROM snapshots_fts f
                             JOIN snapshots s ON s.short = f.short
                             WHERE snapshots_fts MATCH :qq
                             ORDER BY s.ts DESC LIMIT :lim OFFSET :off");
        $st->bindValue(':qq', $q);
        $st->bindValue(':lim', $per, PDO::PARAM_INT);
        $st->bindValue(':off', $off, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
        $total = 0;
        $searchError = 'Sintassi di ricerca non valida.';
    }
} else {
    $total = (int)$pdo->query("SELECT COUNT(*) c FROM snapshots")->fetch()['c'];
    $st = $pdo->prepare("SELECT * FROM snapshots ORDER BY ts DESC LIMIT :lim OFFSET :off");
    $st->bindValue(':lim', $per, PDO::PARAM_INT);
    $st->bindValue(':off', $off, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
}

$pages   = max(1, (int)ceil($total / $per));
$startNo = $total - $off;                       // numero fotogramma del primo elemento
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/** costruisce un URL mantenendo i parametri correnti */
$mkurl = function (array $ov) use ($q, $view, $page): string {
    $p = array_merge(['q' => $q, 'view' => $view, 'page' => $page], $ov);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'index.php?' . http_build_query($p);
};

layout_head('Snapper — provino');
layout_masthead('sheet');
?>
<div class="wrap">

<?php if ($flash): ?><p class="flash"><?= h($flash) ?></p><?php endif; ?>

<div class="panels">
  <div class="panel">
    <h2>Nuova prova</h2>
    <form action="save.php" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <label for="url">URL da archiviare</label>
      <input id="url" type="url" name="url" placeholder="https://esempio.com/pagina" required>
      <label for="title">Titolo (opzionale)</label>
      <input id="title" type="text" name="title" placeholder="Etichetta da mostrare nel provino">
      <label style="display:flex;gap:.4rem;align-items:center;font-weight:400">
        <input type="checkbox" name="watch" value="1" style="width:auto">
        Osserva e ri-cattura ogni
        <input type="text" name="every_hours" value="24" inputmode="numeric"
               style="width:4rem;display:inline-block"> ore
      </label>
      <button type="submit">Sviluppa</button>
      <span class="hint">Genera: copia statica, pagina in 1 file, PNG a piena pagina, PDF,
        TXT, ZIP, <code>SHA256SUMS</code>. Permalink in <code>/archives/&lt;short&gt;/</code>.</span>
    </form>
  </div>

  <div class="panel">
    <h2>Ricerca full-text</h2>
    <form method="get">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <label for="q">Testo, "frasi", prefissi*</label>
      <input id="q" name="q" type="search" value="<?= h($q) ?>" placeholder='guerra OR clima  "parola esatta"  cli*'>
      <button type="submit">Cerca</button>
      <?php if ($q !== ''): ?>
        <a class="hint" href="index.php?view=<?= h($view) ?>">Azzera ricerca</a>
      <?php endif; ?>
      <span class="hint">Operatori <code>AND</code> <code>OR</code> <code>NOT</code>;
        frasi tra virgolette; prefissi con <code>*</code>.</span>
    </form>
  </div>
</div>

<div class="toolbar">
  <span class="count">
    <?= $total ?> prov<?= $total === 1 ? 'a' : 'e' ?>
    <?php if ($q !== ''): ?> · filtro: <b><?= h($q) ?></b><?php endif; ?>
    <?php if ($pages > 1): ?> · pag. <?= $page ?>/<?= $pages ?><?php endif; ?>
  </span>
  <input id="flt" class="grow" placeholder="filtro rapido nella pagina">
  <div class="viewtoggle">
    <a class="<?= $view === 'sheet' ? 'on' : '' ?>"  href="<?= h($mkurl(['view' => 'sheet', 'page' => 1])) ?>">Provino</a>
    <a class="<?= $view === 'ledger' ? 'on' : '' ?>" href="<?= h($mkurl(['view' => 'ledger', 'page' => 1])) ?>">Registro</a>
  </div>
</div>

<?php if (!empty($searchError)): ?><p class="flash"><?= h($searchError) ?></p><?php endif; ?>

<?php if (!$rows): ?>
  <div class="empty">Nessuna prova in archivio<?= $q !== '' ? ' per questa ricerca' : '' ?>.</div>

<?php elseif ($view === 'sheet'): ?>
  <section class="sheet" id="grid">
  <?php $n = $startNo; foreach ($rows as $r):
      $short = (string)$r['short'];
      $base  = '/archives/' . rawurlencode($short);
      $st    = (string)($r['status'] ?? '');
      $dom   = host_of($r['url']);
      $pinned = !empty($r['pinned']);
      $hasShot = $st === 'ready';
  ?>
    <figure class="frame <?= $pinned ? 'pinned' : '' ?>" data-text="<?= h($dom . ' ' . ($r['title'] ?? '') . ' ' . $r['url']) ?>">
      <span class="no">#<?= str_pad((string)$n, 3, '0', STR_PAD_LEFT) ?></span>
      <form class="pin" action="pin.php" method="post" title="Segna come selezionata">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="short" value="<?= h($short) ?>">
        <input type="hidden" name="to" value="<?= $pinned ? '0' : '1' ?>">
        <input type="hidden" name="back" value="<?= h($mkurl([])) ?>">
        <button type="submit" aria-label="selezione">
          <svg viewBox="0 0 30 30"><ellipse class="ring" cx="15" cy="15" rx="12" ry="9"/></svg>
        </button>
      </form>

      <a class="thumb <?= $hasShot ? '' : 'empty' ?>" href="<?= $base ?>/" target="_blank" rel="noopener noreferrer">
        <?php if ($hasShot): ?>
          <img loading="lazy" src="<?= $base ?>/shot.png" alt="Anteprima di <?= h($dom) ?>">
        <?php else: ?>
          <span><?= $st === 'error' ? 'velato' : 'in sviluppo' ?></span>
        <?php endif; ?>
        <span class="st"><?= status_stamp($st) ?></span>
      </a>

      <figcaption>
        <span class="dom"><?= h($dom) ?></span>
        <span class="meta">
          <span><?= h(ts_local($r['ts'] ?? null)) ?></span>
          <span>
            <?php if (isset($r['diff_pct']) && $r['diff_pct'] !== null): ?>
              <a href="<?= $base ?>/diff.png" target="_blank" rel="noopener"
                 title="Variazione dalla versione precedente">&Delta;&#8239;<?= h(rtrim(rtrim(number_format((float)$r['diff_pct'], 2), '0'), '.')) ?>%</a> ·
            <?php endif; ?>
            <?= h(human_size((int)($r['size_bytes'] ?? 0))) ?>
          </span>
        </span>
        <?php if (!empty($r['title'])): ?><span class="ttl"><?= h($r['title']) ?></span><?php endif; ?>
        <?php if (!empty($r['status_msg'])): ?><span class="ttl" style="color:var(--fog)"><?= h($r['status_msg']) ?></span><?php endif; ?>
      </figcaption>

      <div class="ops">
        <a href="<?= $base ?>/shot.png" target="_blank" rel="noopener">PNG</a>
        <a href="<?= $base ?>/page.pdf" target="_blank" rel="noopener">PDF</a>
        <a href="<?= $base ?>/text.txt" target="_blank" rel="noopener">TXT</a>
        <a href="<?= $base ?>/bundle.zip" target="_blank" rel="noopener">ZIP</a>
        <span class="spring"></span>
        <form action="resnap.php" method="post" onsubmit="return confirm('Ri-catturare ora come nuova versione?')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="short" value="<?= h($short) ?>">
          <button type="submit" title="Nuova versione">Ri-cattura</button>
        </form>
        <form action="delete.php" method="post" onsubmit="return confirm('Eliminare definitivamente <?= h($short) ?>?')">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="short" value="<?= h($short) ?>">
          <button type="submit" class="danger">Elimina</button>
        </form>
      </div>
    </figure>
  <?php $n--; endforeach; ?>
  </section>

<?php else: /* ---- REGISTRO ---- */ ?>
  <table class="ledger" id="grid">
    <thead><tr>
      <th>#</th><th>Anteprima</th><th>Quando</th><th>Dominio / URL</th>
      <th>Titolo</th><th>Peso</th><th>HTTP</th><th>Stato</th><th>File</th><th>Azioni</th>
    </tr></thead>
    <tbody>
    <?php $n = $startNo; foreach ($rows as $r):
        $short = (string)$r['short'];
        $base  = '/archives/' . rawurlencode($short);
        $st    = (string)($r['status'] ?? '');
    ?>
      <tr data-text="<?= h(host_of($r['url']) . ' ' . ($r['title'] ?? '') . ' ' . $r['url']) ?>">
        <td class="nowrap">#<?= str_pad((string)$n, 3, '0', STR_PAD_LEFT) ?></td>
        <td class="mini">
          <?php if ($st === 'ready'): ?>
            <a href="<?= $base ?>/" target="_blank" rel="noopener"><img loading="lazy" src="<?= $base ?>/shot.png" alt=""></a>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td class="nowrap"><?= h(ts_local($r['ts'] ?? null)) ?></td>
        <td>
          <b><?= h(host_of($r['url'])) ?></b><br>
          <a class="u" href="<?= h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['url']) ?></a>
        </td>
        <td><?= h($r['title'] ?? '') ?></td>
        <td class="nowrap"><?= h(human_size((int)($r['size_bytes'] ?? 0))) ?></td>
        <td class="nowrap"><?= h((string)($r['http_status'] ?? '')) ?></td>
        <td class="nowrap"><?= status_stamp($st) ?>
          <?php if (!empty($r['status_msg'])): ?><br><span class="u" style="color:var(--fog)"><?= h($r['status_msg']) ?></span><?php endif; ?>
        </td>
        <td class="dl">
          <a href="<?= $base ?>/" target="_blank" rel="noopener">archivio</a><br>
          <a href="<?= $base ?>/shot.png" target="_blank" rel="noopener">png</a> ·
          <a href="<?= $base ?>/page.pdf" target="_blank" rel="noopener">pdf</a><br>
          <a href="<?= $base ?>/text.txt" target="_blank" rel="noopener">txt</a> ·
          <a href="<?= $base ?>/bundle.zip" target="_blank" rel="noopener">zip</a>
        </td>
        <td class="nowrap">
          <form action="resnap.php" method="post" style="display:inline"
                onsubmit="return confirm('Ri-catturare ora?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="short" value="<?= h($short) ?>">
            <button type="submit">Ri-cattura</button>
          </form>
          <form action="delete.php" method="post" style="display:inline"
                onsubmit="return confirm('Eliminare <?= h($short) ?>?')">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="short" value="<?= h($short) ?>">
            <button type="submit" class="danger">Elimina</button>
          </form>
        </td>
      </tr>
    <?php $n--; endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="<?= h($mkurl(['page' => $page - 1])) ?>">‹ prec</a><?php endif; ?>
    <span class="cur"><?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a href="<?= h($mkurl(['page' => $page + 1])) ?>">succ ›</a><?php endif; ?>
  </nav>
<?php endif; ?>

</div>

<script>
// filtro rapido lato client (nasconde righe/fotogrammi non corrispondenti)
(function () {
  const flt = document.getElementById('flt');
  const items = document.querySelectorAll('#grid [data-text]');
  flt.addEventListener('input', function (e) {
    const q = e.target.value.toLowerCase();
    items.forEach(function (el) {
      el.style.display = el.dataset.text.toLowerCase().includes(q) ? '' : 'none';
    });
  });
})();
</script>
<?php
layout_foot();
