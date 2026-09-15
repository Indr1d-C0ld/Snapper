#!/usr/bin/env bash
# /var/www/html/snapper/snapper-perms.sh
# Controllo e ripristino permessi/ownership per Snapper (Apache + PHP + SQLite).
# Dry-run di default. Usa --fix per applicare. Usa --verbose per dettagli.

set -Eeuo pipefail

FIX=0
VERBOSE=0
for a in "$@"; do
  case "$a" in
    --fix) FIX=1 ;;
    -v|--verbose) VERBOSE=1 ;;
    -h|--help) echo "Uso: $(basename "$0") [--fix] [--verbose]"; exit 0 ;;
  esac
done

log() { printf '%s\n' "$*"; }
vlog(){ [ "$VERBOSE" -eq 1 ] && printf '%s\n' "$*" || true; }

need_root(){
  if [ "$FIX" -eq 1 ] && [ "$(id -u)" -ne 0 ]; then
    echo "Errore: --fix richiede root." >&2; exit 1
  fi
}

WEBUSER="www-data"; WEBGROUP="www-data"
APP="/var/www/html/snapper"
ARCH="/var/www/html/archives"
DATA="/srv/snapshots"
DB="$DATA/snapper.db"
LOGF="$DATA/worker.log"
AUTHLOG="$DATA/auth.log"
RLDIR="$DATA/ratelimit"
QLOCK="$DATA/.queue.lock"
OTSCACHE="$DATA/.ots-cache"
SECRETDIR="/etc/snapper"
SECRET="$SECRETDIR/auth.php"

ensure_dir(){
  local d="$1" mode="$2" owner="$3" group="$4"
  if [ ! -d "$d" ]; then
    log "[DIR] mancante: $d"
    [ "$FIX" -eq 1 ] && { mkdir -p "$d"; vlog "  creato: $d"; }
  fi
  if [ -d "$d" ]; then
    local cm co; cm=$(stat -c '%a' "$d" || echo '?'); co=$(stat -c '%U:%G' "$d" || echo '?:?')
    [ "$cm" != "$mode" ]  && { log "[MODE] $d $cm -> $mode"; [ "$FIX" -eq 1 ] && chmod "$mode" "$d"; }
    [ "$co" != "$owner:$group" ] && { log "[OWN]  $d $co -> $owner:$group"; [ "$FIX" -eq 1 ] && chown "$owner:$group" "$d"; }
  fi
}
ensure_file(){
  local f="$1" mode="$2" owner="$3" group="$4"
  [ ! -e "$f" ] && { vlog "[skip] assente: $f"; return; }
  local cm co; cm=$(stat -c '%a' "$f" || echo '?'); co=$(stat -c '%U:%G' "$f" || echo '?:?')
  [ "$cm" != "$mode" ]  && { log "[MODE] $f $cm -> $mode"; [ "$FIX" -eq 1 ] && chmod "$mode" "$f"; }
  [ "$co" != "$owner:$group" ] && { log "[OWN]  $f $co -> $owner:$group"; [ "$FIX" -eq 1 ] && chown "$owner:$group" "$f"; }
}

recur_app(){
  while IFS= read -r -d '' d; do ensure_file "$d" 755 "$WEBUSER" "$WEBGROUP"; done < <(find "$APP" -type d -print0)
  while IFS= read -r -d '' f; do ensure_file "$f" 644 "$WEBUSER" "$WEBGROUP"; done \
    < <(find "$APP" -type f \( -name '*.php' -o -name '*.html' -o -name '*.css' -o -name '*.js' \) -print0)
  while IFS= read -r -d '' f; do ensure_file "$f" 755 "$WEBUSER" "$WEBGROUP"; done < <(find "$APP" -type f -name '*.sh' -print0)
}

recur_data(){
  while IFS= read -r -d '' d; do ensure_file "$d" 755 "$WEBUSER" "$WEBGROUP"; done \
    < <(find "$DATA" -type d -not -name '.home' -not -path '*/.home/*' -print0)
  while IFS= read -r -d '' f; do ensure_file "$f" 644 "$WEBUSER" "$WEBGROUP"; done \
    < <(find "$DATA" -type f -not -path '*/.home/*' -not -name 'snapper.db' -not -name 'auth.log' -not -name 'worker.log' -print0)
  ensure_file "$DB"      640 "$WEBUSER" "$WEBGROUP"
  ensure_file "$LOGF"    640 "$WEBUSER" "$WEBGROUP"
  ensure_file "$AUTHLOG" 640 "$WEBUSER" "$WEBGROUP"
  ensure_file "$QLOCK"   644 "$WEBUSER" "$WEBGROUP"
  ensure_dir  "$RLDIR"   750 "$WEBUSER" "$WEBGROUP"
  ensure_dir  "$OTSCACHE" 755 "$WEBUSER" "$WEBGROUP"
  # log operativi: creati da cron, devono restare scrivibili da www-data
  for lg in cron.log backup.log ots-upgrade.log; do
    ensure_file "$DATA/$lg" 640 "$WEBUSER" "$WEBGROUP"
  done
  # i .gz prodotti da logrotate
  while IFS= read -r -d '' f; do ensure_file "$f" 640 "$WEBUSER" "$WEBGROUP"; done \
    < <(find "$DATA" -maxdepth 1 -name '*.log.*' -print0 2>/dev/null)
}

recur_arch(){
  ensure_dir "$ARCH" 755 "$WEBUSER" "$WEBGROUP"
  while IFS= read -r -d '' lnk; do
    local tgt; tgt=$(readlink -f "$lnk" || true)
    [[ -n "$tgt" && "$tgt" != "$DATA/"* ]] && log "[WARN] symlink anomalo: $lnk -> $tgt"
    [ "$FIX" -eq 1 ] && chown -h "$WEBUSER:$WEBGROUP" "$lnk" || true
  done < <(find "$ARCH" -maxdepth 1 -type l -print0)
}

recur_secret(){
  [ -d "$SECRETDIR" ] && ensure_dir "$SECRETDIR" 750 root "$WEBGROUP"
  [ -e "$SECRET" ]    && ensure_file "$SECRET"   640 root "$WEBGROUP"
}

summary(){
  log "==== Snapper perms ===="
  log "APP=$APP  ARCH=$ARCH  DATA=$DATA"
  log "Modalità: $([ "$FIX" -eq 1 ] && echo FIX || echo DRY-RUN)"
}

main(){
  summary; need_root
  ensure_dir "$APP" 755 "$WEBUSER" "$WEBGROUP"
  ensure_dir "$DATA" 755 "$WEBUSER" "$WEBGROUP"
  ensure_dir "$ARCH" 755 "$WEBUSER" "$WEBGROUP"
  recur_app; recur_data; recur_arch; recur_secret
  log "Completato."
  [ "$FIX" -ne 1 ] && log "Nessuna modifica applicata. Riesegui con --fix."
}
main "$@"
