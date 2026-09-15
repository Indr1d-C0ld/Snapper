#!/usr/bin/env bash
# Cron di Snapper: svuota la coda e accoda le ri-catture programmate (watches).
# Esempio crontab (utente www-data):
#   */5 * * * * /var/www/html/snapper/cron-snapper.sh >> /srv/snapshots/cron.log 2>&1
set -Eeuo pipefail

# cron esegue con PATH=/usr/bin:/bin: senza questa riga gli strumenti
# installati in /usr/local/bin (es. ots) risultano "non installati".
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin"
APPDIR="/var/www/html/snapper"
PHP="$(command -v php || echo /usr/bin/php)"

# 1) ricatture programmate: per ogni watch scaduto, clona come nuova versione "pending"
"$PHP" -r '
require "'"$APPDIR"'/config.php";
require "'"$APPDIR"'/lib.php";
$pdo = db();
$due = $pdo->query("
  SELECT * FROM watches
  WHERE enabled=1
    AND (last_run IS NULL OR strftime(\"%s\",\"now\") - strftime(\"%s\",last_run) >= every_hours*3600)
")->fetchAll();
foreach ($due as $w) {
  [$ok,,] = validate_public_url($w["url"]);
  if (!$ok) { fwrite(STDERR, "watch {$w[id]} scartato: url non pubblico\n"); continue; }
  $new = safe_short(7);
  $parent = null;
  if ($w["last_short"]) {
    $r = $pdo->prepare("SELECT COALESCE(parent_short, short) p FROM snapshots WHERE short=?");
    $r->execute([$w["last_short"]]);
    $parent = ($r->fetch()["p"] ?? null) ?: $w["last_short"];
  }
  $pdo->prepare("INSERT INTO snapshots(short,url,title,status,parent_short) VALUES(?,?,?,?,?)")
      ->execute([$new, $w["url"], $w["title"], "pending", $parent]);
  $pdo->prepare("UPDATE watches SET last_run=CURRENT_TIMESTAMP, last_short=? WHERE id=?")
      ->execute([$new, $w["id"]]);
  echo "watch {$w[id]} -> $new\n";
}
'

# 2) svuota la coda rispettando MAX_CONCURRENCY
"$PHP" "$APPDIR/drain.php"

# 3) recupero worker "morti": running da oltre 30 min senza processo -> error
"$PHP" -r '
require "'"$APPDIR"'/config.php";
$pdo = db();
$stale = $pdo->query("SELECT short FROM snapshots WHERE status=\"running\"
  AND strftime(\"%s\",\"now\") - strftime(\"%s\",ts) > 1800")->fetchAll();
foreach ($stale as $s) {
  $pdo->prepare("UPDATE snapshots SET status=\"error\", status_msg=\"worker interrotto (timeout)\" WHERE short=?")
      ->execute([$s["short"]]);
  echo "stale {$s[short]} -> error\n";
}
'
