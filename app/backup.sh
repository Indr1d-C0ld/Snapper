#!/usr/bin/env bash
# Backup coerente di Snapper: codice + DB (+ opzionale archivi).
# Uso:  sudo /var/www/html/snapper/backup.sh [--with-archives]
# Cron giornaliero consigliato:
#   17 3 * * *  /var/www/html/snapper/backup.sh >> /srv/snapshots/backup.log 2>&1
set -Eeuo pipefail

APP="/var/www/html/snapper"
DATA="/srv/snapshots"
DB="$DATA/snapper.db"
DEST="$DATA/backups"
TS="$(date +%Y%m%d-%H%M%S)"
WITH_ARCHIVES=0
[ "${1:-}" = "--with-archives" ] && WITH_ARCHIVES=1

mkdir -p "$DEST"

# 1) DB: copia atomica con l'API di backup di SQLite (sicura anche a caldo)
if command -v sqlite3 >/dev/null 2>&1 && [ -f "$DB" ]; then
  sqlite3 "$DB" ".backup '$DEST/snapper-$TS.db'"
  gzip -f "$DEST/snapper-$TS.db"
fi

# 2) Codice applicativo
tar -czf "$DEST/snapper-app-$TS.tar.gz" -C "$(dirname "$APP")" "$(basename "$APP")"

# 3) Archivi (grandi: opzionale)
if [ "$WITH_ARCHIVES" -eq 1 ]; then
  tar -czf "$DEST/snapper-snapshots-$TS.tar.gz" \
    --exclude='*/.home' -C "$DATA" . 2>/dev/null || true
fi

# 4) Rotazione: tieni gli ultimi 30 file per tipo
for pat in 'snapper-*.db.gz' 'snapper-app-*.tar.gz' 'snapper-snapshots-*.tar.gz'; do
  ls -1t "$DEST"/$pat 2>/dev/null | tail -n +31 | xargs -r rm -f
done

echo "[$(date +%F' '%T)] backup ok -> $DEST (ts=$TS)"
