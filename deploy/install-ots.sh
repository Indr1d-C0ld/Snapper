#!/usr/bin/env bash
# =============================================================================
# Installa opentimestamps-client (comando "ots") in un venv dedicato, così è
# raggiungibile da QUALSIASI utente — incluso www-data, che è chi lo invoca da
# worker.sh — senza toccare i pacchetti Python di sistema (niente
# "externally-managed-environment").  NON è su apt: python3-opentimestamps
# fornisce solo la libreria, non il comando a riga "ots" (verificato).
#
# ESEGUIRE COME ROOT:
#   sudo bash ops/install-ots.sh
#
# Cosa fa:
#   - crea/aggiorna il venv in /opt/opentimestamps
#   - vi installa opentimestamps-client
#   - symlink /usr/local/bin/ots -> /opt/opentimestamps/bin/ots
#   - permessi a+rX sul venv (leggibile/eseguibile da chiunque, non scrivibile)
#   - self-test: versione + una marca temporale reale su un file usa e getta,
#     eseguita come www-data (lo stesso utente/PATH del worker)
#
# Nota su save.php/resnap.php/drain.php: il worker viene avviato con un PATH
# ristretto per sicurezza. Questo pacchetto lo estende a
# "/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin" — SENZA questo
# script installato PRIMA del deploy, "ots" non verrebbe comunque trovato anche
# se presente altrove (es. ~/.local/bin), perché quel PATH è quello che conta.
# =============================================================================
set -Eeuo pipefail

VENV=/opt/opentimestamps
LINK=/usr/local/bin/ots
WEBUSER=www-data

[ "$(id -u)" -eq 0 ] || { echo "Esegui come root: sudo bash $0"; exit 1; }
command -v python3 >/dev/null || { echo "python3 non trovato."; exit 1; }

echo ">> venv in $VENV"
python3 -m venv "$VENV" 2>&1 | grep -v '^$' || true
[ -x "$VENV/bin/python3" ] || { echo "ERRORE: venv non creato (manca python3-venv?)."; exit 1; }

echo ">> installazione/aggiornamento di opentimestamps-client"
"$VENV/bin/pip" install --quiet --upgrade pip
"$VENV/bin/pip" install --quiet --upgrade opentimestamps-client

echo ">> permessi (leggibile ed eseguibile da tutti, scrivibile solo da root)"
chown -R root:root "$VENV"
chmod -R a+rX "$VENV"

echo ">> collegamento $LINK -> $VENV/bin/ots"
ln -sf "$VENV/bin/ots" "$LINK"

echo ">> verifica versione"
"$LINK" --version

echo ">> self-test: marca temporale reale come utente $WEBUSER (stesso contesto del worker)"
# www-data in questo sistema ha come HOME /var/www, root:root 0755: non puo'
# scriverci dentro (ne' .cache/ots ne' altro). worker.sh lo sa gia' ed esporta
# HOME/XDG_CACHE_HOME su una cartella scrivibile prima di invocare "ots" — qui
# nel self-test otteniamo lo stesso risultato passando --cache esplicito, cosi'
# il test resta indipendente dalla HOME reale di www-data.
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/cache"; chmod 777 "$TMP" "$TMP/cache"   # solo per il test: cartelle temporanee, non l'app
echo "snapper self-test $(date -Iseconds)" > "$TMP/SHA256SUMS"
if command -v runuser >/dev/null; then
  runuser -u "$WEBUSER" -- "$LINK" --cache "$TMP/cache" stamp "$TMP/SHA256SUMS"
else
  su -s /bin/sh -c "'$LINK' --cache '$TMP/cache' stamp '$TMP/SHA256SUMS'" "$WEBUSER"
fi
if [ -s "$TMP/SHA256SUMS.ots" ]; then
  echo "OK: $TMP/SHA256SUMS.ots creato ($(stat -c%s "$TMP/SHA256SUMS.ots") byte)."
else
  echo "ATTENZIONE: nessun file .ots prodotto dal self-test."; exit 1
fi

cat <<EOF

======================================================================
 ots installato: $(readlink -f "$LINK")
 Da qui in poi ogni nuova cattura di Snapper produce anche
 SHA256SUMS.ots (marca temporale OpenTimestamps sull'hash degli
 artefatti) — nessun'altra azione richiesta lato app.

 La marca è "pending" finché non viene confermata su Bitcoin (di solito
 qualche ora): per completarla in seguito, come www-data:
   ots upgrade /srv/snapshots/<short>/SHA256SUMS.ots
 (cron-snapper.sh non lo fa automaticamente; puoi aggiungerlo in seguito
 se ti interessa un aggiornamento periodico delle marche pendenti.)
======================================================================
EOF
