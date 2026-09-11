<?php
declare(strict_types=1);

/* Migrazione idempotente dello schema Snapper.
 * Uso:  sudo -u www-data php ops/migrate.php   (o via deploy.sh) */

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
