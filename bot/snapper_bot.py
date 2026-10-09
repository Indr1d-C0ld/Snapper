#!/usr/bin/env python3
"""Snapper — bot Telegram dedicato.

Inoltra un link al bot e Snapper lo archivia: il messaggio "in sviluppo"
viene poi aggiornato con l'esito (titolo, HTTP, impronta SHA-256, stato
OpenTimestamps, collegamento alla prova). Il bot avvisa anche quando una
pagina osservata cambia e quando una ricattura programmata fallisce.

Parla solo con l'API di Snapper sul loopback e con Telegram; risponde solo
alle chat elencate in ALLOWED_CHAT_IDS, ignorando in silenzio tutte le altre.
Solo libreria standard: niente dipendenze da aggiornare.

Configurazione (variabili d'ambiente, di norma /etc/snapper/bot.env):
  BOT_TOKEN            token del bot (BotFather)                  obbligatorio
  ALLOWED_CHAT_IDS     id delle chat ammesse, separati da virgola  obbligatorio
  SNAPPER_API_TOKEN    token dell'API (api-token.php)              obbligatorio
  SNAPPER_API_URL      default http://127.0.0.1/snapper/api.php
  PUBLIC_BASE_URL      es. https://example.org  (per i collegamenti alle prove)
  DIFF_THRESHOLD       % di variazione oltre cui avvisare (default 1.0)
  STATE_DIRECTORY      impostata da systemd (StateDirectory=)
  TELEGRAM_API_BASE    solo per i test (default https://api.telegram.org)
"""

import html
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

# --------------------------------------------------------------------------
# configurazione

def env(name, default=None, required=False):
    v = os.environ.get(name, default)
    if required and not v:
        sys.exit(f"configurazione mancante: {name}")
    return v

BOT_TOKEN = env("BOT_TOKEN", required=True)
API_TOKEN = env("SNAPPER_API_TOKEN", required=True)
API_URL = env("SNAPPER_API_URL", "http://127.0.0.1/snapper/api.php")
PUBLIC_BASE = (env("PUBLIC_BASE_URL", "") or "").rstrip("/")
TG_BASE = (env("TELEGRAM_API_BASE", "https://api.telegram.org") or "").rstrip("/")
STATE_DIR = env("STATE_DIRECTORY", os.path.dirname(os.path.abspath(__file__)))
STATE_FILE = os.path.join(STATE_DIR.split(":")[0], "state.json")

try:
    ALLOWED = {int(x) for x in env("ALLOWED_CHAT_IDS", required=True).split(",") if x.strip()}
except ValueError:
    sys.exit("ALLOWED_CHAT_IDS deve contenere id numerici separati da virgola")
if not ALLOWED:
    sys.exit("ALLOWED_CHAT_IDS è vuoto: il bot non risponderebbe a nessuno")

try:
    DIFF_THRESHOLD = float(env("DIFF_THRESHOLD", "1.0"))
except ValueError:
    DIFF_THRESHOLD = 1.0

PENDING_GIVE_UP = 45 * 60      # dopo 45 minuti senza esito si smette di attendere
EVENTS_FAST = 3                # secondi fra un controllo e l'altro con catture in corso
EVENTS_SLOW = 45               # ... e senza
MAX_URLS_PER_MESSAGE = 5

URL_RE = re.compile(r"https?://[^\s<>\"'«»]+", re.I)


def log(msg):
    # Mai il contenuto dei messaggi né i token: solo eventi e identificativi.
    print(msg, flush=True)


# --------------------------------------------------------------------------
# stato persistente: offset di Telegram, cursore eventi, catture in attesa

def load_state():
    try:
        with open(STATE_FILE, encoding="utf-8") as f:
            s = json.load(f)
    except (OSError, ValueError):
        s = {}
    s.setdefault("offset", 0)
    s.setdefault("cursor", None)
    s.setdefault("pending", {})
    return s


def save_state(s):
    tmp = STATE_FILE + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(s, f)
    os.replace(tmp, STATE_FILE)   # atomico: un riavvio a metà non lo corrompe


# --------------------------------------------------------------------------
# HTTP

class ApiError(Exception):
    def __init__(self, msg, code=0):
        super().__init__(msg)
        self.code = code


def _request(url, data=None, headers=None, timeout=30):
    body = None
    hdrs = dict(headers or {})
    if data is not None:
        body = json.dumps(data).encode()
        hdrs["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=body, headers=hdrs, method="POST" if body is not None else "GET")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, json.loads(r.read().decode() or "{}")
    except urllib.error.HTTPError as e:
        try:
            payload = json.loads(e.read().decode() or "{}")
        except ValueError:
            payload = {}
        return e.code, payload


def snapper(action, params=None, data=None):
    url = API_URL + "?" + urllib.parse.urlencode({"a": action, **(params or {})})
    try:
        code, j = _request(url, data=data, headers={"Authorization": "Bearer " + API_TOKEN})
    except (urllib.error.URLError, OSError, ValueError) as e:
        raise ApiError(f"Snapper non raggiungibile ({e.__class__.__name__})")
    if not j.get("ok"):
        raise ApiError(j.get("error") or f"errore HTTP {code}", code)
    return j


def tg(method, timeout=40, **params):
    # L'URL contiene il token del bot: non deve mai finire in un log o in
    # un'eccezione, quindi qui si riportano solo il metodo e il tipo d'errore.
    url = f"{TG_BASE}/bot{BOT_TOKEN}/{method}"
    for attempt in range(3):
        try:
            code, j = _request(url, data=params, timeout=timeout)
        except (urllib.error.URLError, OSError, ValueError) as e:
            raise ApiError(f"Telegram {method}: {e.__class__.__name__}")
        if code == 429:
            wait = int((j.get("parameters") or {}).get("retry_after", 5))
            time.sleep(min(wait, 60))
            continue
        if not j.get("ok"):
            raise ApiError(f"Telegram {method}: {j.get('description', code)}", code)
        return j.get("result")
    raise ApiError(f"Telegram {method}: troppe richieste")


def send(chat, text, reply_to=None):
    p = {"chat_id": chat, "text": text, "parse_mode": "HTML", "disable_web_page_preview": True}
    if reply_to:
        p["reply_parameters"] = {"message_id": reply_to, "allow_sending_without_reply": True}
    return tg("sendMessage", **p)


def edit(chat, message_id, text):
    try:
        tg("editMessageText", chat_id=chat, message_id=message_id, text=text,
           parse_mode="HTML", disable_web_page_preview=True)
    except ApiError as e:
        # messaggio cancellato dall'utente o troppo vecchio: si invia un nuovo messaggio
        if "not modified" in str(e):
            return
        send(chat, text)


# --------------------------------------------------------------------------
# presentazione

e = html.escape


def human_size(n):
    n = float(n or 0)
    for unit in ("B", "KB", "MB", "GB"):
        if n < 1024 or unit == "GB":
            return f"{n:.0f} {unit}" if unit == "B" else f"{n:.1f} {unit}"
        n /= 1024


def host(url):
    try:
        return urllib.parse.urlsplit(url).hostname or url
    except ValueError:
        return url


def link(s):
    return PUBLIC_BASE + (s.get("permalink") or "")


def anchor(s, label):
    # Telegram rifiuta i collegamenti relativi: senza PUBLIC_BASE_URL niente link.
    if not PUBLIC_BASE:
        return e(label)
    return f"<a href=\"{e(link(s), quote=True)}\">{e(label)}</a>"


OTS_LABEL = {
    "stamped": "marcatura inviata, conferma in Bitcoin entro qualche ora",
    "complete": "marcatura confermata in Bitcoin",
    "none": "nessuna marcatura temporale",
}


def render_result(s):
    title = s.get("title") or host(s.get("url", ""))
    if s.get("status") == "error":
        return (f"✗ <b>Cattura fallita</b> — <code>{e(s['short'])}</code>\n"
                f"{e(s.get('url', ''))}\n"
                f"{e(s.get('status_msg') or 'motivo sconosciuto')}")
    bits = [e(host(s.get("final_url") or s.get("url", "")))]
    if s.get("http_status"):
        bits.append(f"HTTP {s['http_status']}")
    bits.append(human_size(s.get("size_bytes")))
    if s.get("capture_ms"):
        bits.append(f"{s['capture_ms'] / 1000:.1f} s")
    lines = [f"✓ <b>{e(title)}</b>", " · ".join(bits)]
    if s.get("sha256"):
        h = s["sha256"]
        lines.append(f"SHA-256 <code>{e(h[:16])}…{e(h[-8:])}</code>")
    lines.append("⏱ " + OTS_LABEL.get(s.get("ots_status") or "none", e(str(s.get("ots_status")))))
    if s.get("diff_pct") is not None and s.get("parent_short"):
        lines.append(f"Δ {s['diff_pct']:.2f}% rispetto alla versione precedente")
    if s.get("status_msg"):
        lines.append(f"⚠ {e(s['status_msg'])}")
    lines.append(f"Prova <code>{e(s['short'])}</code>" + (" · " + anchor(s, "apri") if PUBLIC_BASE else ""))
    return "\n".join(lines)


def render_brief(s):
    mark = {"ready": "✓", "error": "✗", "pending": "⏳", "running": "⏳"}.get(s.get("status"), "·")
    title = s.get("title") or host(s.get("url", ""))
    when = (s.get("done_at") or s.get("created_at") or "")[:16]
    return f"{mark} {anchor(s, title[:80])}\n   <code>{e(s['short'])}</code> · {e(when)} UTC"


HELP = (
    "<b>Snapper</b> — archivio di prove\n\n"
    "Inoltrami o incollami un link: lo archivio e ti rispondo con l'impronta e la marcatura temporale.\n\n"
    "/cerca <i>parole</i> — ricerca nel testo delle pagine archiviate\n"
    "/ultimi [n] — le ultime prove\n"
    "/prova <i>codice</i> — dettaglio di una prova\n"
    "/osserva <i>url</i> [ore] — ricattura periodica (default ogni 24 h)\n"
    "/sito <i>url</i> [profilo] — scarica un sito intero: sezione (predefinito), sito, documentazione, blog\n"
    "/stato — salute del sistema\n"
    "/aiuto — questo messaggio"
)


# --------------------------------------------------------------------------
# comandi

def cmd_capture(state, chat, mid, urls):
    for url in urls[:MAX_URLS_PER_MESSAGE]:
        try:
            r = snapper("capture", data={"url": url})
        except ApiError as ex:
            send(chat, f"✗ {e(url)}\n{e(str(ex))}", reply_to=mid)
            continue
        if r.get("status") in ("ready", "error"):
            send(chat, ("↺ già archiviato pochi minuti fa\n" if r.get("duplicate") else "") + render_result(r),
                 reply_to=mid)
            continue
        msg = send(chat, f"⏳ <b>In sviluppo</b> — <code>{e(r['short'])}</code>\n{e(url)}", reply_to=mid)
        state["pending"][r["short"]] = {"chat": chat, "mid": msg["message_id"], "t": time.time()}
        log(f"cattura {r['short']} accodata (chat {chat})")
    if len(urls) > MAX_URLS_PER_MESSAGE:
        send(chat, f"Ho preso i primi {MAX_URLS_PER_MESSAGE} link del messaggio.", reply_to=mid)


def cmd_search(chat, mid, arg):
    if not arg:
        return send(chat, "Uso: /cerca <i>parole</i>  (anche \"frase esatta\", OR, NOT, prefisso*)", reply_to=mid)
    try:
        r = snapper("search", {"q": arg, "limit": 5})
    except ApiError as ex:
        return send(chat, f"✗ {e(str(ex))}", reply_to=mid)
    if not r["items"]:
        return send(chat, f"Nessuna prova contiene «{e(arg)}».", reply_to=mid)
    out = [f"<b>{len(r['items'])} risultati</b> per «{e(arg)}»"]
    for s in r["items"]:
        ex = e(" ".join((s.get("excerpt") or "").split())).replace("«", "<b>").replace("»", "</b>")
        out.append(render_brief(s) + (f"\n   <i>{ex}</i>" if ex else ""))
    send(chat, "\n\n".join(out), reply_to=mid)


def cmd_recent(chat, mid, arg):
    n = int(arg) if arg.isdigit() else 5
    try:
        r = snapper("recent", {"limit": max(1, min(n, 20))})
    except ApiError as ex:
        return send(chat, f"✗ {e(str(ex))}", reply_to=mid)
    if not r["items"]:
        return send(chat, "Archivio vuoto.", reply_to=mid)
    send(chat, "\n".join(render_brief(s) for s in r["items"]), reply_to=mid)


def cmd_proof(chat, mid, arg):
    if not re.fullmatch(r"[A-Za-z0-9]{5,12}", arg or ""):
        return send(chat, "Uso: /prova <i>codice</i>  (es. /prova aB3dE9x)", reply_to=mid)
    try:
        s = snapper("status", {"short": arg})
    except ApiError as ex:
        return send(chat, f"✗ {e(str(ex))}", reply_to=mid)
    if s["status"] in ("pending", "running"):
        return send(chat, f"⏳ <code>{e(arg)}</code> è ancora in sviluppo.", reply_to=mid)
    send(chat, render_result(s), reply_to=mid)


def cmd_watch(chat, mid, arg):
    parts = arg.split()
    if not parts or not URL_RE.fullmatch(parts[0]):
        return send(chat, "Uso: /osserva <i>url</i> [ore]  (1–720, default 24)", reply_to=mid)
    hours = int(parts[1]) if len(parts) > 1 and parts[1].isdigit() else 24
    try:
        r = snapper("watch", data={"url": parts[0], "every_hours": hours})
    except ApiError as ex:
        return send(chat, f"✗ {e(str(ex))}", reply_to=mid)
    send(chat, f"👁 Osservo {e(host(parts[0]))} ogni {r['every_hours']} h.\n"
               f"Prima cattura: <code>{e(r['short'])}</code>. Ti avviso se cambia più del {DIFF_THRESHOLD:g}%.",
         reply_to=mid)
    return r


SITE_PRESETS = {"sezione": 60, "sito": 240, "documentazione": 240, "blog": 240}   # minuti massimi del profilo


def cmd_site(state, chat, mid, arg):
    parts = arg.split()
    if not parts or not URL_RE.fullmatch(parts[0]):
        return send(chat, "Uso: /sito <i>url</i> [profilo]\nProfili: " + ", ".join(SITE_PRESETS) +
                    ". Per scegliere filtri e limiti, e per una stima prima di scaricare, usa la pagina Siti.", reply_to=mid)
    preset = parts[1].lower() if len(parts) > 1 else "sezione"
    if preset not in SITE_PRESETS:
        return send(chat, "Profilo sconosciuto. Profili: " + ", ".join(SITE_PRESETS), reply_to=mid)
    try:
        r = snapper("site", data={"url": parts[0], "preset": preset})
    except ApiError as ex:
        return send(chat, f"✗ {e(str(ex))}", reply_to=mid)
    msg = send(chat, f"⏳ <b>Download del sito</b> — <code>{e(r['short'])}</code>\n{e(parts[0])}\n"
                     f"profilo «{e(preset)}», al massimo {SITE_PRESETS[preset]} minuti"
                     + ("" if r.get("started") else " · in coda"), reply_to=mid)
    state["pending"][r["short"]] = {"chat": chat, "mid": msg["message_id"], "t": time.time(),
                                     "ttl": SITE_PRESETS[preset] * 60 + 1800}
    log(f"sito {r['short']} accodato (chat {chat})")


def cmd_status(chat, mid):
    try:
        h = snapper("health")
    except ApiError as ex:
        return send(chat, f"✗ {e(str(ex))}", reply_to=mid)
    q, a = h["queue"], h["archive"]
    lines = [
        "<b>Stato di Snapper</b>",
        f"Archivio: {a['ready']} prove, {a['error']} fallite · "
        + (f"{h['watches']} pagine osservate" if h["watches"] != 1 else "1 pagina osservata"),
        f"Coda: {q['pending']} in attesa, {q['running']} in corso",
    ]
    ots = h.get("ots") or {}
    lines.append(f"OpenTimestamps: {ots.get('complete', 0)} confermate, {ots.get('stamped', 0)} in attesa")
    b = h.get("last_backup")
    lines.append(f"Backup: {'✓' if b and b['ok'] else '✗'} {e(b['at']) if b else 'nessuno registrato'}")
    d = h.get("disk") or {}
    if d.get("free") and d.get("total"):
        lines.append(f"Disco: {human_size(d['free'])} liberi su {human_size(d['total'])}")
    src = h.get("sources_14d") or {}
    if src:
        lines.append("Catture 14 gg: " + ", ".join(f"{e(k)} {v}" for k, v in sorted(src.items())))
    err = h.get("last_error")
    if err:
        lines.append(f"Ultimo errore: <code>{e(err['short'])}</code> {e((err.get('status_msg') or '')[:80])}")
    send(chat, "\n".join(lines), reply_to=mid)


def extract_urls(msg):
    text = msg.get("text") or msg.get("caption") or ""
    ents = msg.get("entities") or msg.get("caption_entities") or []
    urls = []
    # Le entità di Telegram sono in unità UTF-16: si estrae il testo su quella base.
    t16 = text.encode("utf-16-le")
    for en in ents:
        if en.get("type") == "text_link" and en.get("url"):
            urls.append(en["url"])
        elif en.get("type") == "url":
            frag = t16[en["offset"] * 2:(en["offset"] + en["length"]) * 2].decode("utf-16-le", "ignore")
            urls.append(frag if re.match(r"https?://", frag, re.I) else "https://" + frag)
    if not urls:
        urls = URL_RE.findall(text)
    seen, out = set(), []
    for u in urls:
        u = u.rstrip(".,;:!?)")
        if u not in seen:
            seen.add(u)
            out.append(u)
    return out


def handle(state, upd):
    msg = upd.get("message") or upd.get("channel_post")
    if not msg:
        return
    chat = (msg.get("chat") or {}).get("id")
    if chat not in ALLOWED:
        log(f"messaggio ignorato da chat non autorizzata {chat}")
        return
    mid = msg.get("message_id")
    text = (msg.get("text") or msg.get("caption") or "").strip()
    cmd, _, arg = text.partition(" ")
    cmd = cmd.split("@")[0].lower() if cmd.startswith("/") else ""
    arg = arg.strip()
    try:
        if cmd in ("/start", "/aiuto", "/help"):
            send(chat, HELP, reply_to=mid)
        elif cmd == "/cerca":
            cmd_search(chat, mid, arg)
        elif cmd == "/ultimi":
            cmd_recent(chat, mid, arg)
        elif cmd == "/prova":
            cmd_proof(chat, mid, arg)
        elif cmd == "/osserva":
            r = cmd_watch(chat, mid, arg)
            if r and r.get("status") in ("pending", "running"):
                state["pending"][r["short"]] = {"chat": chat, "mid": None, "t": time.time()}
        elif cmd == "/sito":
            cmd_site(state, chat, mid, arg)
        elif cmd == "/stato":
            cmd_status(chat, mid)
        elif cmd:
            send(chat, "Comando sconosciuto. /aiuto per l'elenco.", reply_to=mid)
        else:
            urls = extract_urls(msg)
            if urls:
                cmd_capture(state, chat, mid, urls)
            else:
                send(chat, "Mandami un link da archiviare, oppure /aiuto.", reply_to=mid)
    except ApiError as ex:
        log(f"errore gestendo un messaggio: {ex}")


# --------------------------------------------------------------------------
# eventi: catture concluse

def broadcast(text):
    for chat in sorted(ALLOWED):
        try:
            send(chat, text)
        except ApiError as ex:
            log(f"avviso non consegnato a {chat}: {ex}")


def poll_events(state):
    if not state["cursor"]:
        state["cursor"] = snapper("events")["cursor"]
        return
    while True:
        r = snapper("events", {"since": state["cursor"]})
        for s in r["items"]:
            p = state["pending"].pop(s["short"], None)
            if p and p.get("mid"):
                edit(p["chat"], p["mid"], render_result(s))
            elif p:
                send(p["chat"], render_result(s))
            elif s.get("source") == "watch":
                if s["status"] == "error":
                    broadcast(f"⚠ <b>Ricattura programmata fallita</b>\n" + render_result(s))
                elif (s.get("diff_pct") or 0) >= DIFF_THRESHOLD:
                    broadcast(f"👁 <b>{e(host(s['url']))} è cambiata</b> ({s['diff_pct']:.2f}%)\n" + render_result(s))
            elif s["status"] == "error" and (s.get("source") or "").startswith("api:"):
                # catture chieste da altri client dell'API: si segnala solo il fallimento
                broadcast("⚠ " + render_result(s))
        state["cursor"] = r["cursor"]
        if len(r["items"]) < 50:
            break
    now = time.time()
    for short, p in list(state["pending"].items()):
        if now - p["t"] > p.get("ttl", PENDING_GIVE_UP):
            del state["pending"][short]
            text = (f"⌛ <code>{e(short)}</code>: nessun esito dopo {int(p.get('ttl', PENDING_GIVE_UP)) // 60} minuti.\n"
                    f"Controlla con /prova {e(short)} o /stato.")
            if p.get("mid"):
                edit(p["chat"], p["mid"], text)
            else:
                send(p["chat"], text)


# --------------------------------------------------------------------------

def main():
    state = load_state()
    me = tg("getMe")
    log(f"avviato come @{me.get('username')} · chat ammesse: {len(ALLOWED)} · stato in {STATE_FILE}")
    tg("setMyCommands", commands=[
        {"command": "cerca", "description": "ricerca nel testo delle prove"},
        {"command": "ultimi", "description": "le ultime prove"},
        {"command": "prova", "description": "dettaglio di una prova"},
        {"command": "osserva", "description": "ricattura periodica di un URL"},
        {"command": "sito", "description": "scarica un sito intero"},
        {"command": "stato", "description": "salute del sistema"},
        {"command": "aiuto", "description": "come si usa"},
    ])
    last_events = 0.0
    backoff = 1
    while True:
        try:
            interval = EVENTS_FAST if state["pending"] else EVENTS_SLOW
            wait = max(0, min(25, int(interval - (time.time() - last_events))))
            updates = tg_updates(state["offset"], wait)
            for u in updates:
                # L'offset si salva PRIMA di gestire il messaggio: un messaggio
                # che manda in errore il bot non deve ripresentarsi a ogni riavvio.
                state["offset"] = u["update_id"] + 1
                save_state(state)
                try:
                    handle(state, u)
                except Exception as ex:  # noqa: BLE001 — un messaggio non deve fermare il bot
                    log(f"messaggio {u.get('update_id')} scartato: {ex.__class__.__name__}: {ex}")
                save_state(state)
            if time.time() - last_events >= interval or updates:
                poll_events(state)
                last_events = time.time()
            save_state(state)
            backoff = 1
        except ApiError as ex:
            log(f"errore: {ex} — riprovo fra {backoff} s")
            time.sleep(backoff)
            backoff = min(backoff * 2, 120)


def tg_updates(offset, wait):
    # Il parametro `timeout` di getUpdates (attesa lato Telegram) è distinto da
    # quello della connessione HTTP, che deve essere un po' più lungo.
    url = f"{TG_BASE}/bot{BOT_TOKEN}/getUpdates"
    try:
        code, j = _request(url, data={"offset": offset, "timeout": wait,
                                      "allowed_updates": ["message", "channel_post"]},
                           timeout=wait + 15)
    except (urllib.error.URLError, OSError, ValueError) as ex:
        raise ApiError(f"Telegram getUpdates: {ex.__class__.__name__}")
    if code == 409:
        raise ApiError("Telegram getUpdates: un'altra istanza del bot è in esecuzione")
    if not j.get("ok"):
        raise ApiError(f"Telegram getUpdates: {j.get('description', code)}", code)
    return j.get("result") or []


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
