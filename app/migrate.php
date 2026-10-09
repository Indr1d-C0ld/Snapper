<?php
declare(strict_types=1);

/* Migrazione idempotente dello schema Snapper.
 * Uso:  sudo -u www-data php ops/migrate.php   (o via deploy.sh)
 *
 * SOLO da CLI: e' uno script di manutenzione che esegue DDL sul database di
 * produzione e non ha alcun controllo di sessione. La conf Apache lo nega
 * gia' via <FilesMatch>, questo e' la seconda barriera indipendente. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$dbPath = getenv('SNAPPER_DB') ?: '/srv/snapshots/snapper.db';
$pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

function cols(PDO $pdo, string $t): array
{
    $out = [];
    foreach ($pdo->query("PRAGMA table_info($t)") as $r) {
        $out[] = $r['name'];
    }
    return $out;
}

$have = cols($pdo, 'snapshots');
$want = [
    'final_url'    => 'TEXT',
    'http_status'  => 'INTEGER',
    'content_type' => 'TEXT',
    'sha256'       => 'TEXT',
    'capture_ms'   => 'INTEGER',
    'pinned'       => 'INTEGER DEFAULT 0',
    'note'         => 'TEXT',
    'tags'         => 'TEXT',
    'parent_short' => 'TEXT',
    'ots_status'   => "TEXT DEFAULT 'none'",
    'diff_pct'     => 'REAL',
    // quando la cattura si è conclusa (ready o error): cursore degli eventi
    // per il bot. Non si può usare l'id: non è AUTOINCREMENT e può essere
    // riusato dopo una cancellazione.
    'done_at'      => 'DATETIME',
    // chi l'ha richiesta: 'web', 'watch', 'api:<nome token>'. Serve anche a
    // misurare quante catture arrivano senza passare dall'interfaccia.
    'source'       => "TEXT DEFAULT 'web'",
    // tipo di prova: 'page' (cattura di una pagina) o 'wiki' (revisioni di
    // una voce di Wikipedia, eseguita da wiki-worker.php)
    'kind'         => "TEXT DEFAULT 'page'",
];
foreach ($want as $name => $type) {
    if (!in_array($name, $have, true)) {
        $pdo->exec("ALTER TABLE snapshots ADD COLUMN $name $type");
        echo "+ colonna snapshots.$name\n";
    }
}

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_status ON snapshots(status)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_parent ON snapshots(parent_short)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_pinned ON snapshots(pinned)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_done ON snapshots(done_at)");

/* Token dell'API: si salva solo l'impronta SHA-256, mai il token. Un token è
 * 32 byte casuali, quindi un hash veloce basta: non c'è nulla da indovinare
 * per forza bruta, a differenza di una password. */
$pdo->exec("
CREATE TABLE IF NOT EXISTS api_tokens (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL UNIQUE,
  token_hash  TEXT NOT NULL UNIQUE,
  scopes      TEXT NOT NULL,              -- 'capture', 'read' o 'capture,read'
  created     DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_used   DATETIME,
  uses        INTEGER DEFAULT 0,
  revoked     DATETIME
)");

/* Wikipedia. Una voce (wiki_pages) raccoglie nel tempo le revisioni
 * archiviate (wiki_revisions), ciascuna dentro la prova che l'ha acquisita:
 * le prove restano immutabili, così ogni marcatura temporale resta valida.
 * wiki_jobs ricorda quali revisioni una prova in coda deve scaricare. */
$pdo->exec("
CREATE TABLE IF NOT EXISTS wiki_pages (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  lang     TEXT NOT NULL,
  pageid   INTEGER NOT NULL,
  title    TEXT NOT NULL,
  created  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated  DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(lang, pageid)
)");
$pdo->exec("
CREATE TABLE IF NOT EXISTS wiki_revisions (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  wiki_page        INTEGER NOT NULL,
  revid            INTEGER NOT NULL,
  parentid         INTEGER,
  ts               DATETIME NOT NULL,       -- data della revisione su Wikipedia (UTC)
  user             TEXT,
  anon             INTEGER DEFAULT 0,
  comment          TEXT,
  size             INTEGER,
  sha1             TEXT,                    -- impronta pubblicata da Wikipedia
  sha1_ok          INTEGER,                 -- 1 = coincide con il wikitesto archiviato
  sha256_wikitext  TEXT,
  minor            INTEGER DEFAULT 0,
  tags             TEXT,                    -- JSON
  short            TEXT NOT NULL,           -- prova che la contiene
  UNIQUE(wiki_page, revid, short)
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_wrev_page ON wiki_revisions(wiki_page, ts)");
// consenso all'attribuzione con WikiWho, voce per voce (fase 2)
$wpCols = [];
foreach ($pdo->query('PRAGMA table_info(wiki_pages)') as $r) {
    $wpCols[] = $r['name'];
}
if (!in_array('wikiwho', $wpCols, true)) {
    $pdo->exec('ALTER TABLE wiki_pages ADD COLUMN wikiwho INTEGER DEFAULT 0');
    echo "+ colonna wiki_pages.wikiwho\n";
}
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_wrev_short ON wiki_revisions(short)");
$pdo->exec("
CREATE TABLE IF NOT EXISTS wiki_jobs (
  short      TEXT PRIMARY KEY,
  wiki_page  INTEGER NOT NULL,
  revids     TEXT NOT NULL,                 -- JSON
  created    DATETIME DEFAULT CURRENT_TIMESTAMP
)");

/* Siti interi (fase 4). site_jobs: opzioni di una cattura; site_estimates:
 * stime a vuoto; site_pages: ogni risorsa raccolta, con stato e impronta;
 * site_pages_fts: ricerca nel testo delle pagine di ogni sito. */
$pdo->exec("
CREATE TABLE IF NOT EXISTS site_jobs (
  short    TEXT PRIMARY KEY,
  url      TEXT NOT NULL,
  options  TEXT NOT NULL,
  created  DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("
CREATE TABLE IF NOT EXISTS site_estimates (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  url       TEXT NOT NULL,
  options   TEXT NOT NULL,
  status    TEXT DEFAULT 'running',
  result    TEXT,
  created   DATETIME DEFAULT CURRENT_TIMESTAMP,
  finished  DATETIME
)");
$pdo->exec("
CREATE TABLE IF NOT EXISTS site_pages (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  short   TEXT NOT NULL,
  url     TEXT NOT NULL,
  local   TEXT,
  type    TEXT,
  status  INTEGER,
  ctype   TEXT,
  size    INTEGER,
  sha256  TEXT,
  depth   INTEGER,
  title   TEXT,
  note    TEXT
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_spages_short ON site_pages(short, type)");
$pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS site_pages_fts USING fts5(short UNINDEXED, url, title, body, tokenize='porter')");

$pdo->exec("
CREATE TABLE IF NOT EXISTS watches (
  id           INTEGER PRIMARY KEY,
  url          TEXT NOT NULL,
  title        TEXT,
  every_hours  INTEGER DEFAULT 24,
  last_run     DATETIME,
  last_short   TEXT,
  enabled      INTEGER DEFAULT 1,
  created      DATETIME DEFAULT CURRENT_TIMESTAMP
)");

echo "migrazione completata su $dbPath\n";
