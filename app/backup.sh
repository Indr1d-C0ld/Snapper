#!/usr/bin/env bash
# Backup coerente di Snapper: codice + DB (+ opzionale archivi).
# Uso:  sudo /var/www/html/snapper/backup.sh [--with-archives]
# Cron giornaliero consigliato:
#   17 3 * * *  /var/www/html/snapper/backup.sh >> /srv/snapshots/backup.log 2>&1
set -Eeuo pipefail

# cron esegue con PATH=/usr/bin:/bin: senza questa riga gli strumenti
# installati in /usr/local/bin (es. ots) risultano "non installati".
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin"

APP="/var/www/html/snapper"
DATA="/srv/snapshots"
DB="$DATA/snapper.db"
DEST="$DATA/backups"
TS="$(date +%Y%m%d-%H%M%S)"
KEEP=30
WITH_ARCHIVES=0
[ "${1:-}" = "--with-archives" ] && WITH_ARCHIVES=1

log(){ printf '[%s] %s\n' "$(date +'%F %T')" "$*"; }

# Qualunque fallimento dev'essere RUMOROSO: questo script gira da cron con
# l'output rediretto su un file, e un'uscita silenziosa e' indistinguibile da
# "non e' mai partito" — che e' esattamente il problema che si era presentato.
trap 'rc=$?; log "BACKUP FALLITO alla riga $LINENO (codice $rc)" >&2; exit $rc' ERR

mkdir -p "$DEST"

# 1) DB: copia atomica con l'API di backup di SQLite (sicura anche a caldo)
if command -v sqlite3 >/dev/null 2>&1 && [ -f "$DB" ]; then
  sqlite3 "$DB" ".backup '$DEST/snapper-$TS.db'"
  gzip -f "$DEST/snapper-$TS.db"
fi

# 2) Codice applicativo
tar -czf "$DEST/snapper-app-$TS.tar.gz" -C "$(dirname "$APP")" "$(basename "$APP")"

# 3) Archivi (grandi: opzionale)
# NB: $DEST sta DENTRO $DATA, quindi va escluso esplicitamente, altrimenti il
# tar includerebbe la cartella dei backup — se stesso compreso.
if [ "$WITH_ARCHIVES" -eq 1 ]; then
  tar -czf "$DEST/snapper-snapshots-$TS.tar.gz" \
    --exclude='./backups' --exclude='./.home' --exclude='*/.home' \
    --exclude='./.ots-cache' --exclude='./ratelimit' \
    --exclude='./snapper.db-wal' --exclude='./snapper.db-shm' \
    -C "$DATA" . 2>/dev/null || true
fi

# 4) Rotazione: tieni gli ultimi $KEEP file per tipo.
# Un pattern senza corrispondenze fa fallire 'ls'; con pipefail+set -e questo
# faceva morire lo script in silenzio prima della riga di conferma finale.
# 'mapfile' isola l'esito e un pattern vuoto diventa semplicemente 0 file.
for pat in 'snapper-*.db.gz' 'snapper-app-*.tar.gz' 'snapper-snapshots-*.tar.gz'; do
  mapfile -t found < <(ls -1t -- "$DEST"/$pat 2>/dev/null || true)
  if [ "${#found[@]}" -gt "$KEEP" ]; then
    rm -f -- "${found[@]:$KEEP}"
    log "rotazione $pat: rimossi $(( ${#found[@]} - KEEP )) file, tenuti $KEEP"
  fi
done

# Riepilogo finale (stessa cautela: niente pipeline che possa far fallire lo script)
mapfile -t dbs  < <(ls -1 -- "$DEST"/snapper-*.db.gz      2>/dev/null || true)
mapfile -t apps < <(ls -1 -- "$DEST"/snapper-app-*.tar.gz 2>/dev/null || true)
SIZE_TOT="$(du -sh "$DEST" 2>/dev/null | awk '{print $1}' || true)"
log "backup ok (ts=$TS) -> ${#dbs[@]} dump DB, ${#apps[@]} archivi codice, ${SIZE_TOT:-?} totali in $DEST"
