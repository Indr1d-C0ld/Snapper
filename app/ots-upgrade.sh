#!/usr/bin/env bash
# =============================================================================
# Completa le marche OpenTimestamps "in sospeso": per ogni snapshot con
# ots_status='stamped' interroga i calendar server e, se la transazione
# Bitcoin che ancora il gruppo di marche e' stata confermata, aggiorna il
# file SHA256SUMS.ots con la prova completa (verificabile da chiunque, senza
# piu' dover contattare il calendar server) e segna ots_status='complete'.
#
# Bassa frequenza di proposito: i calendar server sono infrastruttura
# pubblica gratuita e una marca impiega tipicamente ore, non minuti, a essere
# confermata su Bitcoin — interrogarli piu' spesso del cron della coda (ogni
# 5') non avrebbe senso ed e' scortese verso un servizio condiviso.
#
# Richiede "ots" installato — vedi ops/install-ots.sh. Se assente esce senza
# errore (nessuna marca da completare senza il comando).
#
# Esempio crontab (utente www-data), ogni 6 ore:
#   0 */6 * * * /var/www/html/snapper/ots-upgrade.sh >> /srv/snapshots/ots-upgrade.log 2>&1
# =============================================================================
set -Eeuo pipefail

# cron esegue con PATH=/usr/bin:/bin: senza questa riga gli strumenti
# installati in /usr/local/bin (es. ots) risultano "non installati".
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin"

APPDIR="/var/www/html/snapper"
DATA="/srv/snapshots"
CACHE="$DATA/.ots-cache"
PHP="$(command -v php || echo /usr/bin/php)"
OTS="$(command -v ots || true)"

log(){ printf '[%(%F %T)T] %s\n' -1 "$*"; }

if [ -z "$OTS" ]; then
  log "ots non installato (vedi ops/install-ots.sh), niente da fare."
  exit 0
fi
mkdir -p "$CACHE"

LIST="$("$PHP" -r '
require "'"$APPDIR"'/config.php";
foreach (db()->query("SELECT short FROM snapshots WHERE ots_status=\"stamped\"")->fetchAll() as $r) {
  echo $r["short"], "\n";
}
')"

if [ -z "$LIST" ]; then
  log "nessuna marca in sospeso."
  exit 0
fi

while IFS= read -r SHORT; do
  [ -z "$SHORT" ] && continue
  F="$DATA/$SHORT/SHA256SUMS.ots"
  if [ ! -s "$F" ]; then
    log "$SHORT: SHA256SUMS.ots assente, salto"
    continue
  fi

  # "upgrade" non tocca il file se non c'e' ancora conferma Bitcoin: e'
  # l'esito normale nella stragrande maggioranza dei run, non un errore.
  # Silenziamo l'output di ots (che in quel caso stampa un fuorviante
  # "Failed! Timestamp not complete"): l'esito vero lo stabiliamo sotto,
  # ispezionando il file con "ots info".
  "$OTS" --cache "$CACHE" upgrade "$F" >/dev/null 2>&1 || true

  if "$OTS" --cache "$CACHE" info "$F" 2>/dev/null | grep -q BitcoinBlockHeaderAttestation; then
    "$PHP" -r '
      require "'"$APPDIR"'/config.php";
      db()->prepare("UPDATE snapshots SET ots_status=\"complete\" WHERE short=?")->execute([$argv[1]]);
    ' "$SHORT"
    # rispecchia lo stato anche nella pagina statica della singola prova
    IDX="$DATA/$SHORT/index.html"
    if [ -f "$IDX" ] && grep -q 'OpenTimestamps: stamped' "$IDX"; then
      sed -i 's/OpenTimestamps: stamped/OpenTimestamps: completata (ancorata a Bitcoin, verificabile senza calendar server)/' "$IDX"
    fi
    log "$SHORT: completata"
  else
    log "$SHORT: ancora in sospeso"
  fi
done <<< "$LIST"
