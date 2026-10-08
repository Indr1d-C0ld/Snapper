#!/usr/bin/env bash
# Installa (o riconfigura) il bot Telegram dedicato di Snapper.  ESEGUIRE COME ROOT.
#
#   sudo bash ops/install-bot.sh
#
# Prima: crea un bot NUOVO con @BotFather (/newbot) e tieni a portata il token.
# Non riusare il token di un bot già in servizio: due programmi che leggono gli
# aggiornamenti dello stesso bot si rubano i messaggi a vicenda.
#
# Lo script:
#   1. chiede il token del bot (senza mostrarlo) e lo verifica con Telegram;
#   2. abbina la tua chat: scrivi /start al bot e lui ti riconosce;
#   3. crea (o ruota) il token dell'API "telegram-bot" e lo collauda sul loopback;
#   4. scrive /etc/snapper/bot.env (root, 600), installa codice e unità systemd;
#   5. avvia il servizio e ti manda un messaggio di prova.
#
# Nessun segreto passa sulla riga di comando di curl (sarebbe visibile in `ps`):
# gli URL con il token vengono passati a curl via stdin (--config -).
set -Eeuo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="/var/www/html/snapper"
ENVF="/etc/snapper/bot.env"
OPT="/opt/snapper-bot"
UNIT="/etc/systemd/system/snapper-bot.service"
API="http://127.0.0.1/snapper/api.php"
TG="${TELEGRAM_API_BASE:-https://api.telegram.org}"   # variabile solo per i collaudi

c_ok(){   printf '\033[32m%s\033[0m\n' "$*"; }
c_warn(){ printf '\033[33m%s\033[0m\n' "$*"; }
c_err(){  printf '\033[31m%s\033[0m\n' "$*" >&2; }
c_info(){ printf '%s\n' "$*"; }
ask(){ local a; read -r -p "$1 [s/N] " a; [[ "$a" =~ ^[sSyY]$ ]]; }
die(){ c_err "$*"; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Esegui come root (sudo)."
for b in curl python3 php systemctl; do
  command -v "$b" >/dev/null || die "Manca '$b'."
done
[ -f "$APP/api.php" ] && [ -f "$APP/api-token.php" ] \
  || die "API non installata in $APP: installa prima api.php e api-token.php (deploy.sh --with-apache)"
grep -q 'api.php' /etc/apache2/conf-available/snapper-archives.conf 2>/dev/null \
  || die "La conf Apache non limita ancora api.php al loopback: aggiorna snapper-archives.conf (deploy.sh --with-apache)"

# chiamata a Telegram con l'URL (che contiene il token) letto da stdin
tg(){ # tg <metodo> [json]
  local method="$1" body="${2:-}"
  [ -n "$body" ] || body='{}'
  printf 'url = "%s/bot%s/%s"\n' "$TG" "$BOT_TOKEN" "$method" \
    | curl -sS --max-time 40 --config - -H 'Content-Type: application/json' --data "$body"
}
api(){ # api <query> con il token dell'API nell'header, mai sulla riga di comando
  printf 'header = "Authorization: Bearer %s"\nurl = "%s?%s"\n' "$API_TOKEN" "$API" "$1" \
    | curl -sS --max-time 20 --config -
}
jget(){ # jget <espressione python su j> < json
  python3 -c 'import json,sys; j=json.load(sys.stdin); print(eval(sys.argv[1]))' "$1"
}

echo "== Bot Telegram di Snapper =="

# ---------------------------------------------------------------- 1. token del bot
if [ -f "$ENVF" ] && ask "Esiste già una configurazione ($ENVF). Mantengo token del bot e chat abbinate?"; then
  BOT_TOKEN="$(sed -n 's/^BOT_TOKEN=//p' "$ENVF")"
  CHATS="$(sed -n 's/^ALLOWED_CHAT_IDS=//p' "$ENVF")"
  KEEP=1
else
  KEEP=0
  read -r -s -p "Token del bot (da @BotFather, non viene mostrato): " BOT_TOKEN; echo
fi
[[ "$BOT_TOKEN" =~ ^[0-9]{5,}:[A-Za-z0-9_-]{30,}$ ]] || die "Il token non ha il formato atteso (123456:ABC…)."

ME="$(tg getMe)" || die "Telegram non raggiungibile."
[ "$(jget 'j.get("ok")' <<<"$ME")" = "True" ] || die "Telegram rifiuta il token: $(jget 'j.get("description")' <<<"$ME")"
BOTNAME="$(jget 'j["result"]["username"]' <<<"$ME")"
c_ok "   bot: @$BOTNAME"

# Un bot già in servizio altrove (webhook impostato) non va dirottato.
WH="$(tg getWebhookInfo | jget '(j.get("result") or {}).get("url","")')"
[ -z "$WH" ] || die "Questo bot ha un webhook attivo: è già usato da un altro servizio. Creane uno nuovo con @BotFather."

systemctl stop snapper-bot 2>/dev/null || true

# ---------------------------------------------------------------- 2. abbinamento
# Si accetta anche un /start già inviato negli ultimi 15 minuti: chi crea il
# bot di solito preme subito "Avvia", e Telegram quel pulsante lo mostra una
# volta sola. Appena lo vede, il bot risponde su Telegram: la conferma vera e
# propria resta qui nel terminale.
if [ "$KEEP" -eq 0 ]; then
  SINCE=$(( $(date +%s) - 900 ))
  OFF=0
  c_info ""
  c_info "Apri Telegram e scrivi  /start  a @$BOTNAME  (anche se l'hai già fatto: hai 5 minuti)."
  CHATS=""
  DEADLINE=$(( $(date +%s) + 300 ))
  while [ -z "$CHATS" ] && [ "$(date +%s)" -lt "$DEADLINE" ]; do
    printf '\r   in attesa di /start… %3ss ' "$(( DEADLINE - $(date +%s) ))"
    R="$(tg getUpdates "{\"timeout\":20,\"offset\":$OFF,\"allowed_updates\":[\"message\"]}")" || { sleep 3; continue; }
    FOUND="$(python3 -c '
import json, sys
try:
    j = json.loads(sys.stdin.read() or "{}")
except ValueError:
    j = {"description": "risposta non JSON da Telegram"}
off, since = int(sys.argv[1]), int(sys.argv[2])
if not j.get("ok"):
    print("ERR", j.get("error_code", ""), (j.get("description") or "risposta non valida").replace("\t", " "), sep="\t")
    sys.exit()
for u in j.get("result", []):
    off = max(off, u["update_id"] + 1)
    m = u.get("message") or {}
    if ((m.get("text") or "").startswith("/start") and m.get("chat", {}).get("type") == "private"
            and m.get("date", 0) >= since):
        f = m.get("from") or {}
        name = " ".join(x for x in (f.get("first_name"), f.get("last_name")) if x)
        user = ("@" + f["username"]) if f.get("username") else "senza username"
        print(off, m["chat"]["id"], f"{name} ({user})".replace("\t", " "), sep="\t")
        sys.exit()
print(off, "", "", sep="\t")
' "$OFF" "$SINCE" <<<"$R")"
    IFS=$'\t' read -r A1 A2 A3 <<<"$FOUND"
    if [ "$A1" = "ERR" ]; then
      echo
      [ "$A2" = "409" ] && die "Telegram: un altro programma sta già leggendo i messaggi di questo bot (${A3}). Usa un bot nuovo, creato solo per Snapper."
      die "Telegram getUpdates: $A2 $A3"
    fi
    OFF="$A1"; CID="$A2"; WHO="$A3"
    if [ -n "$CID" ]; then
      echo
      tg sendMessage "$(python3 -c 'import json,sys; print(json.dumps({"chat_id": int(sys.argv[1]), "text": "Ti vedo. Conferma nel terminale dove stai installando Snapper."}))' "$CID")" >/dev/null || true
      if ask "   Messaggio da $WHO, chat $CID. Sei tu?"; then
        CHATS="$CID"
      else
        c_warn "   Ignorato. Riscrivi /start dal tuo account."
      fi
    fi
  done
  echo
  # conferma a Telegram gli aggiornamenti letti, così il bot non li rielabora
  tg getUpdates "{\"timeout\":0,\"offset\":$OFF}" >/dev/null || true
  [ -n "$CHATS" ] || die "Nessun /start ricevuto in 5 minuti. Rilancia lo script."
fi
c_ok "   chat ammesse: $CHATS"

# ---------------------------------------------------------------- 3. token dell'API
TOKLIST="$(sudo -u www-data php "$APP/api-token.php" list)"
if grep -q '^telegram-bot ' <<<"$TOKLIST"; then
  API_TOKEN="$(sudo -u www-data php "$APP/api-token.php" rotate telegram-bot 2>/dev/null)"
else
  API_TOKEN="$(sudo -u www-data php "$APP/api-token.php" create telegram-bot --scopes=capture,read 2>/dev/null)"
fi
[[ "$API_TOKEN" =~ ^snp_[0-9a-f]{64}$ ]] || die "Creazione del token API fallita."

H="$(api 'a=health')" || die "API non raggiungibile su $API"
if [ "$(jget 'j.get("ok")' <<<"$H" 2>/dev/null)" != "True" ]; then
  ERR="$(jget 'j.get("error")' <<<"$H" 2>/dev/null || echo "$H" | head -c 200)"
  [ "$ERR" = "token mancante" ] && ERR="$ERR: Apache non passa l'header Authorization a PHP"
  die "Collaudo dell'API fallito: $ERR"
fi
c_ok "   API ok: $(jget 'j["archive"]["ready"]' <<<"$H") prove in archivio"

# ---------------------------------------------------------------- 4. configurazione
OLD_BASE="$(sed -n 's/^PUBLIC_BASE_URL=//p' "$ENVF" 2>/dev/null || true)"
SN="$(grep -h '^\s*ServerName' /etc/apache2/sites-enabled/*ssl* 2>/dev/null | awk '{print $2; exit}')"
DEF="${OLD_BASE:-${SN:+https://$SN}}"
read -r -p "Indirizzo pubblico per i collegamenti alle prove [$DEF]: " BASE
BASE="${BASE:-$DEF}"; BASE="${BASE%/}"
[ -z "$BASE" ] || [[ "$BASE" =~ ^https?://[A-Za-z0-9.:-]+$ ]] || die "Indirizzo non valido: $BASE"
THR="$(sed -n 's/^DIFF_THRESHOLD=//p' "$ENVF" 2>/dev/null || true)"

# /etc/snapper esiste già e contiene auth.php, letto da www-data: non se ne
# toccano proprietario e permessi, si crea solo se manca.
[ -d /etc/snapper ] || install -d -o root -g www-data -m 0750 /etc/snapper
( umask 077
  cat > "$ENVF.new" <<EOF
# Snapper - bot Telegram. Generato da install-bot.sh il $(date +'%F %T').
BOT_TOKEN=$BOT_TOKEN
ALLOWED_CHAT_IDS=$CHATS
SNAPPER_API_TOKEN=$API_TOKEN
SNAPPER_API_URL=$API
PUBLIC_BASE_URL=$BASE
DIFF_THRESHOLD=${THR:-1.0}
EOF
)
chown root:root "$ENVF.new"; chmod 600 "$ENVF.new"; mv -f "$ENVF.new" "$ENVF"
c_ok "   configurazione -> $ENVF (root, 600)"

install -d -o root -g root -m 0755 "$OPT"
install -o root -g root -m 0644 "$SRC/bot/snapper_bot.py" "$OPT/snapper_bot.py"
install -o root -g root -m 0644 "$SRC/bot/snapper-bot.service" "$UNIT"
systemctl daemon-reload

# ---------------------------------------------------------------- 5. avvio
systemctl enable --now snapper-bot >/dev/null 2>&1 || true
systemctl restart snapper-bot
sleep 4
if systemctl is-active --quiet snapper-bot; then
  c_ok "   servizio snapper-bot attivo"
else
  c_err "   il servizio non è partito. Ultime righe del registro:"
  journalctl -u snapper-bot -n 20 --no-pager
  exit 1
fi
journalctl -u snapper-bot -n 3 --no-pager -o cat | sed 's/^/   /'

IFS=',' read -r -a IDS <<<"$CHATS"
for id in "${IDS[@]}"; do
  SENT="$(tg sendMessage "$(python3 -c 'import json,sys; print(json.dumps({"chat_id": int(sys.argv[1]), "text": "✓ Snapper è collegato. Mandami un link da archiviare, oppure /aiuto."}))' "$id")" || true)"
  if [ "$(jget 'j.get("ok")' <<<"$SENT" 2>/dev/null)" = "True" ]; then
    c_ok "   messaggio di prova inviato alla chat $id"
  else
    c_warn "   messaggio di prova NON consegnato alla chat $id: $(jget 'j.get("description")' <<<"$SENT" 2>/dev/null || echo 'nessuna risposta')"
  fi
done

cat <<EOF

Fatto. Comandi utili:
  journalctl -u snapper-bot -f                                   registro del bot
  sudo -u www-data php $APP/api-token.php list                   token e ultimo uso
  sudo -u www-data php $APP/api-token.php revoke telegram-bot    blocca subito il bot
  sudo bash $SRC/ops/install-bot.sh                              riconfigura / ruota i token
EOF
