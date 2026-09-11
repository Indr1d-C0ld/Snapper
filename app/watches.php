<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require_login();

$csrf = csrf_token();
$pdo  = db();

/* -------------------------------------------------- azioni (POST) -------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['act'] ?? '');
    $id  = (int)($_POST['id'] ?? 0);

    if ($act === 'add') {
        $url   = trim((string)($_POST['url'] ?? ''));
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 300);
        $every = max(1, min(8760, (int)($_POST['every_hours'] ?? 24)));
        [$ok, $reason] = validate_public_url($url);
        if (!$ok) {
            $_SESSION['flash'] = "URL rifiutato: $reason";
        } else {
            $dup = $pdo->prepare('SELECT id FROM watches WHERE url=?');
            $dup->execute([$url]);
            if ($dup->fetch()) {
                $_SESSION['flash'] = 'Esiste già un watch per questo URL.';
            } else {
                $pdo->prepare('INSERT INTO watches(url, title, every_hours, enabled) VALUES(?,?,?,1)')
                    ->execute([$url, ($title !== '' ? $title : null), $every]);
                $_SESSION['flash'] = 'Watch aggiunto.';
                audit('WATCH add ip=' . client_ip() . " url=$url every=$every");
            }
        }
    } elseif ($act === 'save' && $id) {
        $every = max(1, min(8760, (int)($_POST['every_hours'] ?? 24)));
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 300);
        $pdo->prepare('UPDATE watches SET every_hours=?, title=? WHERE id=?')
            ->execute([$every, ($title !== '' ? $title : null), $id]);
        $_SESSION['flash'] = "Watch #$id aggiornato.";
    } elseif ($act === 'toggle' && $id) {
        $pdo->prepare('UPDATE watches SET enabled = 1 - enabled WHERE id=?')->execute([$id]);
        $_SESSION['flash'] = "Watch #$id commutato.";
    } elseif ($act === 'del' && $id) {
        $pdo->prepare('DELETE FROM watches WHERE id=?')->execute([$id]);
        $_SESSION['flash'] = "Watch #$id eliminato.";
        audit('WATCH del ip=' . client_ip() . " id=$id");
    } elseif ($act === 'run' && $id) {
        $w = $pdo->prepare('SELECT * FROM watches WHERE id=?');
        $w->execute([$id]);
        $row = $w->fetch();
        if ($row) {
            [$ok, $reason] = validate_public_url((string)$row['url']);
            if (!$ok) {
                $_SESSION['flash'] = "Cattura annullata: $reason";
            } else {
                $parent = $row['last_short'] ? chain_parent((string)$row['last_short']) : null;
                [$short, $started] = enqueue_capture((string)$row['url'], $row['title'], $parent);
                $pdo->prepare('UPDATE watches SET last_run=CURRENT_TIMESTAMP, last_short=? WHERE id=?')
                    ->execute([$short, $id]);
                $_SESSION['flash'] = $started
                    ? "Cattura avviata ($short)."
                    : "Cattura in coda ($short).";
                audit("WATCH run ip=" . client_ip() . " id=$id short=$short");
            }
        }
    }
    // "add" torna a pagina 1 (il nuovo watch compare in cima); gli altri restano dov'erano
    $pg = ($act === 'add') ? 1 : max(1, (int)($_POST['page'] ?? 1));
    header('Location: /snapper/watches.php' . ($pg > 1 ? '?page=' . $pg : ''));
    exit;
}

/* -------------------------------------------------- elenco (GET) -------- */
$per   = 40;
$page  = max(1, (int)($_GET['page'] ?? 1));
$total       = (int)$pdo->query('SELECT COUNT(*) c FROM watches')->fetch()['c'];
$activeTotal = (int)$pdo->query('SELECT COUNT(*) c FROM watches WHERE enabled=1')->fetch()['c'];
$pages = max(1, (int)ceil($total / $per));
if ($page > $pages) {
    $page = $pages;
}
$off = ($page - 1) * $per;

$st = $pdo->prepare('
    SELECT w.*, s.status AS last_status
    FROM watches w
    LEFT JOIN snapshots s ON s.short = w.last_short
    ORDER BY w.enabled DESC, w.id DESC
    LIMIT :lim OFFSET :off
');
$st->bindValue(':lim', $per, PDO::PARAM_INT);
$st->bindValue(':off', $off, PDO::PARAM_INT);
$st->execute();
$rows = $st->fetchAll();

$now   = time();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/** stima prossima esecuzione: last_run + every_hours */
$next_txt = function (?string $lastRun, int $everyH, int $enabled) use ($now): string {
    if (!$enabled)  return 'in pausa';
    if (!$lastRun)  return 'al prossimo giro';
    try {
        $t = (new DateTime($lastRun . ' UTC'))->getTimestamp() + $everyH * 3600;
    } catch (Throwable) {
        return '—';
    }
    $d = $t - $now;
    if ($d <= 0) return 'in attesa del cron';
    if ($d < 3600)   return 'tra ' . max(1, (int)round($d / 60)) . ' min';
    if ($d < 86400)  return 'tra ' . round($d / 3600, 1) . ' h';
    return 'tra ' . round($d / 86400, 1) . ' g';
};

layout_head('Snapper — watch');
layout_masthead('watch');
?>
<div class="wrap">

<?php if ($flash): ?><p class="flash"><?= h($flash) ?></p><?php endif; ?>

<div class="panels">
  <div class="panel">
    <h2>Nuovo watch</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="act" value="add">
      <label for="url">URL da osservare</label>
      <input id="url" type="url" name="url" placeholder="https://esempio.com/pagina" required>
      <label for="title">Etichetta (opzionale)</label>
      <input id="title" type="text" name="title" placeholder="Nome nel registro">
      <label for="every">Intervallo (ore)</label>
      <input id="every" type="text" name="every_hours" value="24" inputmode="numeric" style="max-width:8rem">
      <button type="submit">Aggiungi</button>
      <span class="hint">La ri-cattura effettiva la esegue <code>cron-snapper.sh</code>.
        Senza cron installato, usa «Cattura ora» qui sotto.</span>
    </form>
  </div>
  <div class="panel">
    <h2>Come funziona</h2>
    <p class="hint">
      Ogni watch genera una <b>nuova versione</b> dello stesso URL alla scadenza
      dell'intervallo, concatenata alla precedente (catena <code>parent_short</code>)
      così il <b>diff visivo</b> viene calcolato in automatico.<br><br>
      «In pausa» = il watch resta ma non viene eseguito.
      Eliminare un watch non tocca gli snapshot già archiviati.
    </p>
  </div>
</div>

<div class="toolbar">
  <span class="count"><?= $total ?> watch · <?= $activeTotal ?> attivi<?php
    if ($pages > 1): ?> · pag. <?= $page ?>/<?= $pages ?><?php endif; ?></span>
  <input id="flt" class="grow" placeholder="filtro rapido (in questa pagina)">
</div>

<?php if (!$rows): ?>
  <div class="empty">Nessun watch. Aggiungine uno qui sopra, oppure spunta
    «Osserva e ri-cattura» quando archivi un URL dal Provino.</div>
<?php else: ?>
<div class="tbl-scroll">
<table class="ledger" id="grid">
  <thead><tr>
    <th>#</th><th>Stato</th><th>URL / etichetta</th><th>Ogni</th>
    <th class="col-sec">Ultima</th><th>Prossima</th><th>Ultimo snapshot</th><th>Azioni</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $id = (int)$r['id'];
      $en = (int)$r['enabled'];
  ?>
    <tr data-text="<?= h(($r['title'] ?? '') . ' ' . $r['url']) ?>"<?= $en ? '' : ' style="opacity:.55"' ?>>
      <td class="nowrap">#<?= $id ?></td>
      <td class="nowrap">
        <?php if ($en): ?><span class="stamp dev">Attivo</span>
        <?php else: ?><span class="stamp wait">Pausa</span><?php endif; ?>
      </td>
      <td>
        <a class="u" href="<?= h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($r['url']) ?></a>
        <?php if (!empty($r['title'])): ?><br><b><?= h($r['title']) ?></b><?php endif; ?>
      </td>
      <td class="nowrap">
        <form method="post" style="display:flex;gap:.3rem;align-items:center">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="act" value="save">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="page" value="<?= $page ?>">
          <input type="text" name="every_hours" value="<?= (int)$r['every_hours'] ?>"
                 inputmode="numeric" style="width:4.5rem;font-size:12px" aria-label="ore">
          <button type="submit" title="Salva intervallo">↵</button>
        </form>
      </td>
      <td class="nowrap col-sec"><?= h($r['last_run'] ? ts_local($r['last_run']) : '—') ?></td>
      <td class="nowrap"><?= h($next_txt($r['last_run'], (int)$r['every_hours'], $en)) ?></td>
      <td class="nowrap">
        <?php if (!empty($r['last_short'])): ?>
          <a class="permalink" href="/archives/<?= h(rawurlencode((string)$r['last_short'])) ?>/"
             target="_blank" rel="noopener"><?= h($r['last_short']) ?></a>
          <?php if (!empty($r['last_status'])): ?> <?= status_stamp((string)$r['last_status']) ?><?php endif; ?>
        <?php else: ?>—<?php endif; ?>
      </td>
      <td class="nowrap">
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="page" value="<?= $page ?>">
          <button type="submit" name="act" value="run" title="Cattura subito una nuova versione">Cattura ora</button>
          <button type="submit" name="act" value="toggle" class="ghost"><?= $en ? 'Pausa' : 'Riattiva' ?></button>
          <button type="submit" name="act" value="del" class="danger"
                  onclick="return confirm('Eliminare il watch #<?= $id ?>? Gli snapshot restano.')">Elimina</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="watches.php?page=<?= $page - 1 ?>">&lsaquo; prec</a><?php endif; ?>
    <span class="cur"><?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a href="watches.php?page=<?= $page + 1 ?>">succ &rsaquo;</a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>

</div>

<script>
(function () {
  var flt = document.getElementById('flt');
  if (!flt) return;
  var items = document.querySelectorAll('#grid tbody tr');
  flt.addEventListener('input', function (e) {
    var q = e.target.value.toLowerCase();
    items.forEach(function (el) {
      el.style.display = (el.dataset.text || '').toLowerCase().includes(q) ? '' : 'none';
    });
  });
})();
</script>
<?php
layout_foot();
