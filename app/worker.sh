#!/usr/bin/env bash
# /var/www/html/snapper/worker.sh
# Cattura: mirror statico + HTML self-contained (monolith) + PNG piena pagina + PDF
# + TXT (+ OCR fallback) + diff visivo + ZIP + SHA256SUMS (+ OpenTimestamps). FTS via PHP.

set -Eeuo pipefail
export LANG=C LC_ALL=C

SHORT="${1:?short mancante}"
URL="${2:?url mancante}"

ROOT="/srv/snapshots/${SHORT}"
ARCH="/var/www/html/archives"
LOG="/srv/snapshots/worker.log"
APPDIR="/var/www/html/snapper"
DBHELP="$APPDIR/worker-db.php"
PHP="$(command -v php || echo /usr/bin/php)"

# HOME dedicata: evita errori .pki/nssdb e sporca-cache di Chromium
export HOME="$ROOT/.home"
export XDG_CACHE_HOME="$ROOT/.home/.cache"
export XDG_CONFIG_HOME="$ROOT/.home/.config"

WGET="$(command -v wget || true)"
CURL="$(command -v curl || true)"
CHROME="$(command -v chromium || command -v chromium-browser || true)"
W3M="$(command -v w3m || true)"
ZIP="$(command -v zip || true)"
MONOLITH="$(command -v monolith || true)"
OTS="$(command -v ots || true)"
TIMEOUT="$(command -v timeout || true)"
SHA="$(command -v sha256sum || true)"

UA="Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150 Safari/537.36"
HDR_ACCEPT="text/html,application/xhtml+xml;q=0.9,*/*;q=0.8"
HDR_LANG="it-IT,it;q=0.9,en;q=0.8"

START_TS=$(date +%s%3N)
log(){ printf '[%(%F %T)T] %s\n' -1 "$*" >>"$LOG"; }

fail(){
  local msg="$1"
  log "ERROR $SHORT :: $msg"
  "$PHP" -r 'echo json_encode(["msg"=>$argv[1]]);' "$msg" | "$PHP" "$DBHELP" error "$SHORT" || true
  "$PHP" "$APPDIR/drain.php" >>"$LOG" 2>&1 || true
  exit 1
}
trap 'fail "errore imprevisto alla riga $LINENO"' ERR

# ---- guardia anti-SSRF lato shell -----------------------------------------
host_of_url(){ printf '%s' "$1" | sed -E 's#^[a-zA-Z]+://([^/@]*@)?([^/:?#]+).*#\2#'; }
host_is_private(){
  local h="$1" ip
  while read -r ip _; do
    case "$ip" in
      10.*|127.*|0.*|169.254.*|192.168.*|::1|fe80:*|fc[0-9a-f][0-9a-f]:*|fd[0-9a-f][0-9a-f]:*) return 0 ;;
      172.1[6-9].*|172.2[0-9].*|172.3[0-1].*) return 0 ;;
    esac
  done < <(getent ahosts "$h" 2>/dev/null || true)
  return 1
}
host_resolves(){ getent ahosts "$1" >/dev/null 2>&1; }
URL_HOST="$(host_of_url "$URL")"
[ -z "$URL_HOST" ] && fail "URL senza host riconoscibile"
# La risolvibilita' va verificata davvero: senza questo controllo un dominio
# inesistente supera la guardia e piu' avanti finiamo per archiviare (e
# marcare temporalmente) la pagina d'errore del browser.
host_resolves "$URL_HOST" || fail "host non risolvibile: $URL_HOST"
host_is_private "$URL_HOST" && fail "host non pubblico: $URL_HOST"

umask 022
mkdir -p "$ROOT/site" "$ROOT/.home/.cache" "$ROOT/.home/.config" "$ARCH" \
  || fail "impossibile creare $ROOT o $ARCH"
log "START $SHORT :: $URL"

# ---- 0) preflight HTTP: stato, url finale, content-type ------------------
HTTP_CODE=""; FINAL_URL=""; CTYPE=""; CAP_WARN=""
if [ -n "$CURL" ]; then
  META="$("$CURL" -sS -A "$UA" -L --max-redirs 5 --connect-timeout 15 --max-time 40 \
        -o /dev/null -w '%{http_code}\t%{url_effective}\t%{content_type}' "$URL" 2>>"$LOG" || true)"
  HTTP_CODE="$(printf '%s' "$META" | cut -f1)"
  FINAL_URL="$(printf '%s' "$META" | cut -f2)"
  CTYPE="$(printf '%s' "$META" | cut -f3)"
  if [ -n "$FINAL_URL" ] && host_is_private "$(host_of_url "$FINAL_URL")"; then
    fail "redirect verso host non pubblico: $(host_of_url "$FINAL_URL")"
  fi
  "$PHP" -r 'echo json_encode(["final_url"=>$argv[1]?:null,"http_status"=>$argv[2]?:null,"content_type"=>$argv[3]?:null]);' \
      "$FINAL_URL" "$HTTP_CODE" "$CTYPE" | "$PHP" "$DBHELP" meta "$SHORT" || true
  log "HTTP $SHORT :: ${HTTP_CODE:-?} ${FINAL_URL:-$URL} ${CTYPE:-?}"

  # Nessuna connessione stabilita (DNS/TCP/TLS falliti): ogni artefatto
  # prodotto da qui in avanti sarebbe la schermata d'errore del browser.
  # Ci fermiamo subito, prima di sprecare wget + due avvii di Chromium.
  case "${HTTP_CODE:-000}" in
    000|0|"") fail "nessuna risposta dal server (connessione o TLS falliti)" ;;
  esac
  if [ "$HTTP_CODE" -ge 400 ] 2>/dev/null; then
    # Una 404/410 e' una prova legittima da archiviare ("a quella data non
    # c'era piu'"), ma va etichettata: resta 'ready' con avviso visibile.
    CAP_WARN="il server ha risposto HTTP ${HTTP_CODE}: la copia potrebbe essere una pagina di errore"
    log "WARN $SHORT :: $CAP_WARN"
  fi
fi

# ---- 1) mirror statico (wget) ------------------------------------------
if [ -n "$WGET" ]; then
  WGET_OPTS=(
    --no-verbose --page-requisites --convert-links --adjust-extension
    --no-parent --execute robots=off --timeout=25 --tries=2 --quota=200m
    --max-redirect=5 --user-agent="$UA"
    --header="Accept: $HDR_ACCEPT" --header="Accept-Language: $HDR_LANG"
    --header="Accept-Encoding: identity" --directory-prefix="$ROOT/site"
  )
  if [ -n "$TIMEOUT" ]; then
    "$TIMEOUT" 300 "$WGET" "${WGET_OPTS[@]}" "$URL" || log "wget parziale, continuo"
  else
    "$WGET" "${WGET_OPTS[@]}" "$URL" || log "wget parziale, continuo"
  fi
else
  log "wget assente, salto mirror"
fi

# ---- 2) entry HTML locale --------------------------------------------
ENTRY="$(find "$ROOT/site" -type f \( -iname '*.html' -o -iname '*.htm' \) | head -n1 || true)"
[ -z "$ENTRY" ] && ENTRY="$(find "$ROOT/site" -type f | head -n1 || true)"
REL=""; [ -n "$ENTRY" ] && REL="${ENTRY#${ROOT}/site/}"

# ---- 3) titolo (rispetta quello manuale nel DB) --------------------
TITLE=""
if [ -n "$ENTRY" ] && [ -s "$ENTRY" ]; then
  TITLE="$(grep -iPo '(?<=<title>).*?(?=</title>)' "$ENTRY" | head -n1 | tr -d '\r' || true)"
fi
CURTITLE="$(printf '{}' | "$PHP" "$DBHELP" get "$SHORT" | "$PHP" -r '$j=json_decode(stream_get_contents(STDIN),true);echo $j["title"]??"";' || true)"
[ -n "$CURTITLE" ] && TITLE="$CURTITLE"

# ---- 4) sorgente di rendering -------------------------------------
RENDER_URL=""
if [ -n "$ENTRY" ] && [ -s "$ENTRY" ] && grep -qsiE '<html|<!doctype|<head|<body' "$ENTRY"; then
  RENDER_URL="file://$ENTRY"
fi
[ -z "$RENDER_URL" ] && { RENDER_URL="${FINAL_URL:-$URL}"; log "rendering su URL remoto"; }

# ---- 5) pagina in un solo file: monolith se presente, altrimenti DOM post-JS ----
SF="$ROOT/page.singlefile.html"
if [ -n "$MONOLITH" ]; then
  if [ -n "$TIMEOUT" ]; then
    "$TIMEOUT" 120 "$MONOLITH" -o "$SF" "${FINAL_URL:-$URL}" || log "monolith fallito"
  else
    "$MONOLITH" -o "$SF" "${FINAL_URL:-$URL}" || log "monolith fallito"
  fi
  [ -s "$SF" ] && log "single-file via monolith (self-contained)"
fi
if [ ! -s "$SF" ] && [ -n "$CHROME" ]; then
  DD_OPTS=(--headless --no-sandbox --disable-gpu --disable-dev-shm-usage
           --virtual-time-budget=8000 --user-agent="$UA" "--user-data-dir=$ROOT/.home/.chromium")
  if [ -n "$TIMEOUT" ]; then
    "$TIMEOUT" 90 "$CHROME" "${DD_OPTS[@]}" --dump-dom "$RENDER_URL" > "$SF" 2>/dev/null || log "dump-dom fallito"
  else
    "$CHROME" "${DD_OPTS[@]}" --dump-dom "$RENDER_URL" > "$SF" 2>/dev/null || log "dump-dom fallito"
  fi
  if [ -s "$SF" ]; then
    log "single-file via chromium --dump-dom (DOM post-JS, risorse non incorporate)"
  else
    rm -f "$SF"
    log "single-file non disponibile (installa 'monolith' per una copia self-contained)"
  fi
fi

# ---- 6) screenshot piena pagina + PDF (Chromium headless) ------
if [ -n "$CHROME" ]; then
  CHR_OPTS=(--headless --no-sandbox --disable-gpu --disable-dev-shm-usage
            --hide-scrollbars --force-color-profile=srgb --window-size=1366,900
            --virtual-time-budget=8000 --run-all-compositor-stages-before-draw
            --user-agent="$UA" "--user-data-dir=$ROOT/.home/.chromium")
  if [ -n "$TIMEOUT" ]; then
    "$TIMEOUT" 150 "$CHROME" "${CHR_OPTS[@]}" --screenshot="$ROOT/shot.png" "$RENDER_URL" || log "screenshot fallito"
    "$TIMEOUT" 210 "$CHROME" "${CHR_OPTS[@]}" --no-pdf-header-footer --print-to-pdf="$ROOT/page.pdf" "$RENDER_URL" || log "pdf fallito"
  else
    "$CHROME" "${CHR_OPTS[@]}" --screenshot="$ROOT/shot.png" "$RENDER_URL" || log "screenshot fallito"
    "$CHROME" "${CHR_OPTS[@]}" --no-pdf-header-footer --print-to-pdf="$ROOT/page.pdf" "$RENDER_URL" || log "pdf fallito"
  fi
else
  log "chromium assente, salto PNG/PDF"
fi

# ---- 7) testo (+ OCR di fallback) -----------------------------
if [ -n "$W3M" ]; then
  if [[ "$RENDER_URL" == file://* ]]; then
    "$W3M" -dump "$RENDER_URL" | head -c 5242880 > "$ROOT/text.txt" || log "dump testo locale fallito"
  else
    "$W3M" -dump "${FINAL_URL:-$URL}" | head -c 5242880 > "$ROOT/text.txt" || log "dump testo remoto fallito"
  fi
fi
if command -v tesseract >/dev/null 2>&1 && [ -s "$ROOT/shot.png" ]; then
  if [ ! -s "$ROOT/text.txt" ] || [ "$(wc -c < "$ROOT/text.txt")" -lt 40 ]; then
    tesseract "$ROOT/shot.png" - -l ita+eng 2>/dev/null | head -c 5242880 > "$ROOT/text.txt" || true
    log "OCR di fallback applicato"
  fi
fi

# ---- 7b) validazione finale degli artefatti ----------------------
# Rete di sicurezza indipendente dal codice HTTP (es. curl assente): sta QUI,
# prima di hash, marca temporale e ZIP, perche' una cattura non valida non
# deve mai ricevere una marca OpenTimestamps.
CAP_OK=0
[ -s "$ROOT/shot.png" ] && CAP_OK=$((CAP_OK + 1))
[ -s "$ROOT/text.txt" ] && [ "$(wc -c < "$ROOT/text.txt")" -ge 32 ] && CAP_OK=$((CAP_OK + 1))
[ -n "$ENTRY" ] && [ -s "$ENTRY" ] && CAP_OK=$((CAP_OK + 1))
[ "$CAP_OK" -eq 0 ] && fail "nessun artefatto utile prodotto (ne' screenshot, ne' testo, ne' copia statica)"

# ---- 8) diff visivo con la versione precedente della stessa catena ----
DIFF_PCT=""
PREV="$("$PHP" -r '
  require "/var/www/html/snapper/config.php";
  $me=$argv[1];
  $r=db()->prepare("SELECT parent_short FROM snapshots WHERE short=?"); $r->execute([$me]);
  $p=$r->fetch(); $parent=($p && $p["parent_short"]) ? $p["parent_short"] : $me;
  $q=db()->prepare("SELECT short FROM snapshots WHERE status=\"ready\" AND short<>? AND (short=? OR parent_short=?) ORDER BY ts DESC LIMIT 1");
  $q->execute([$me,$parent,$parent]);
  $x=$q->fetch(); echo $x ? $x["short"] : "";
' "$SHORT" 2>/dev/null || true)"
if [ -n "$PREV" ] && [ -s "$ROOT/shot.png" ] && [ -s "/srv/snapshots/$PREV/shot.png" ] \
   && command -v compare >/dev/null 2>&1 && command -v convert >/dev/null 2>&1 \
   && command -v identify >/dev/null 2>&1; then
  W=1000; TMP="$ROOT/.home"
  convert "/srv/snapshots/$PREV/shot.png" -resize ${W}x "$TMP/a.png" 2>/dev/null || true
  convert "$ROOT/shot.png"                -resize ${W}x "$TMP/b.png" 2>/dev/null || true
  if [ -s "$TMP/a.png" ] && [ -s "$TMP/b.png" ]; then
    HA=$(identify -format '%h' "$TMP/a.png" 2>/dev/null || echo 0)
    HB=$(identify -format '%h' "$TMP/b.png" 2>/dev/null || echo 0)
    H=$(( HA < HB ? HA : HB ))
    if [ "$H" -gt 0 ]; then
      convert "$TMP/a.png" -crop ${W}x${H}+0+0 +repage "$TMP/a2.png" 2>/dev/null || true
      convert "$TMP/b.png" -crop ${W}x${H}+0+0 +repage "$TMP/b2.png" 2>/dev/null || true
      AE="$(compare -metric AE -fuzz 5% "$TMP/a2.png" "$TMP/b2.png" "$ROOT/diff.png" 2>&1 || true)"
      DIFF_PCT="$("$PHP" -r '
        $tot=max(1,(int)$argv[1]*(int)$argv[2]);
        $ae=(float)preg_replace("/[^0-9.].*$/s","",trim($argv[3]));
        echo round($ae/$tot*100,3);' "$W" "$H" "$AE" 2>/dev/null || echo "")"
      log "DIFF $SHORT vs $PREV :: ${DIFF_PCT:-?}%"
    fi
  fi
  rm -f "$TMP"/a.png "$TMP"/b.png "$TMP"/a2.png "$TMP"/b2.png
fi

# ---- 9) redirect interno all'entry reale ----------------------
if [ -n "$REL" ]; then
  REL_ESC="$(printf '%s' "$REL" | sed 's/&/\&amp;/g')"
  cat > "$ROOT/site/index.html" <<HTML
<!doctype html><meta charset="utf-8">
<meta http-equiv="refresh" content="0; url=./$REL_ESC">
<p>Vai alla copia: <a href="./$REL_ESC">$REL_ESC</a></p>
HTML
fi
LINK="site/"; [ -n "$REL" ] && LINK="site/$REL"

# ---- 10) SHA256SUMS + OpenTimestamps ------------------------
OTS_STATUS="none"
if [ -n "$SHA" ]; then
  FILES=()
  for f in shot.png page.pdf page.singlefile.html text.txt diff.png; do
    [ -s "$ROOT/$f" ] && FILES+=("$f")
  done
  if [ "${#FILES[@]}" -gt 0 ]; then
    ( cd "$ROOT" && "$SHA" "${FILES[@]}" > SHA256SUMS 2>/dev/null ) || true
  fi
  if [ -n "$OTS" ] && [ -s "$ROOT/SHA256SUMS" ]; then
    ( cd "$ROOT" && "$OTS" stamp SHA256SUMS >/dev/null 2>&1 ) && OTS_STATUS="stamped" || log "ots stamp fallito"
  fi
fi
SHOT_SHA="$( [ -s "$ROOT/shot.png" ] && "$SHA" "$ROOT/shot.png" 2>/dev/null | cut -d' ' -f1 || true )"

# ---- 11) pagina indice (scheda provino) --------------------
esc(){ "$PHP" -r 'echo htmlspecialchars($argv[1], ENT_QUOTES);' "$1"; }
T_H="$(esc "${TITLE:-$URL}")"; U_H="$(esc "$URL")"; FU_H="$(esc "${FINAL_URL:-$URL}")"
DIFF_CHIP=""
[ -s "$ROOT/diff.png" ] && DIFF_CHIP='<a href="diff.png">Diff visivo'"${DIFF_PCT:+ (${DIFF_PCT}%)}"'</a>'
SF_CHIP=""
[ -s "$ROOT/page.singlefile.html" ] && SF_CHIP='<a href="page.singlefile.html">Pagina (1 file)</a>'
cat > "$ROOT/index.html" <<HTML
<!doctype html><html lang="it"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Prova ${SHORT} — ${T_H}</title>
<style>
 :root{--paper:#f2ecdd;--ink:#1c1a15;--muted:#6f6857;--line:#c8bd9f;--red:#c8402f}
 *{box-sizing:border-box}body{margin:0;background:#17150f;color:var(--paper);
   font:15px/1.55 ui-sans-serif,-apple-system,Segoe UI,Roboto,sans-serif;padding:2rem clamp(1rem,4vw,3rem)}
 .card{max-width:1100px;margin:0 auto;background:var(--paper);color:var(--ink);
   border:1px solid var(--line);padding:1.4rem 1.6rem 2rem;box-shadow:0 20px 50px rgba(0,0,0,.45)}
 h1{font-size:1.15rem;margin:.2rem 0 1rem}
 .tabk{font:700 10px/1 ui-monospace,monospace;letter-spacing:.2em;text-transform:uppercase;color:var(--red)}
 img.shot{width:100%;height:auto;border:1px solid var(--line);background:#0d0c08;display:block;margin:1rem 0}
 dl{display:grid;grid-template-columns:9rem 1fr;gap:.35rem .8rem;font:13px/1.5 ui-monospace,monospace;margin:0}
 dt{color:var(--muted);text-transform:uppercase;letter-spacing:.08em;font-size:11px}
 dd{margin:0;word-break:break-all}
 .chips{display:flex;flex-wrap:wrap;gap:.5rem;margin:1.2rem 0 0}
 .chips a{border:1px solid var(--line);padding:.5rem .8rem;color:var(--ink);text-decoration:none;
   font:600 12px/1 ui-sans-serif;text-transform:uppercase;letter-spacing:.06em}
 .chips a:hover{background:#e6dbc2}
 a{color:#7a5a12}
</style></head><body>
<div class="card">
 <div class="tabk">Snapper &middot; prova ${SHORT}</div>
 <h1>${T_H}</h1>
 <img class="shot" src="shot.png" alt="Screenshot" onerror="this.style.display='none'">
 <dl>
  <dt>Origine</dt><dd><a href="${U_H}" rel="noopener noreferrer">${U_H}</a></dd>
  <dt>URL finale</dt><dd>${FU_H}</dd>
  <dt>HTTP</dt><dd>${HTTP_CODE:-n/d} &middot; ${CTYPE:-n/d}</dd>
  <dt>SHA-256 PNG</dt><dd>${SHOT_SHA:-n/d}</dd>
  <dt>Timestamp</dt><dd>OpenTimestamps: ${OTS_STATUS}</dd>
  <dt>Variazione</dt><dd>${DIFF_PCT:-n/d}${DIFF_PCT:+ % rispetto alla versione precedente}</dd>
 </dl>
 <div class="chips">
  <a href="${LINK}">Copia statica</a>
  ${SF_CHIP}
  <a href="shot.png">Screenshot PNG</a>
  <a href="page.pdf">PDF</a>
  <a href="text.txt">Testo</a>
  <a href="bundle.zip">Bundle ZIP</a>
  <a href="SHA256SUMS">SHA256SUMS</a>
  ${DIFF_CHIP}
 </div>
 <p style="margin-top:1.4rem"><a href="../">&larr; Indice archivio</a></p>
</div></body></html>
HTML

# ---- 12) ZIP -----------------------------------------------
if [ -n "$ZIP" ]; then
  ( cd "$ROOT" && "$ZIP" -qr "bundle.zip" . -x '.home/*' ) || log "zip fallito"
fi

# ---- 13) permalink ---------------------------------------
ln -sfn "$ROOT" "$ARCH/${SHORT}" || log "symlink non creato"

# ---- 14) DB + FTS (prepared statement via PHP) ----------
SIZE="$(du -sb --exclude='.home' "$ROOT" | awk '{print $1}')"
END_TS=$(date +%s%3N); DUR=$((END_TS - START_TS))
"$PHP" -r '
  echo json_encode([
    "title"=>$argv[1],"size_bytes"=>(int)$argv[2],"sha256"=>$argv[3]?:null,
    "capture_ms"=>(int)$argv[4],"ots_status"=>$argv[5],"body_file"=>$argv[6],"diff_pct"=>$argv[7],
    "warn"=>$argv[8],
  ]);' "$TITLE" "$SIZE" "$SHOT_SHA" "$DUR" "$OTS_STATUS" "$ROOT/text.txt" "$DIFF_PCT" "$CAP_WARN" \
  | "$PHP" "$DBHELP" ready "$SHORT"

log "DONE  $SHORT :: ready (${DUR}ms, $(numfmt --to=iec "$SIZE" 2>/dev/null || echo "${SIZE}B"))"

# ---- 15) avvia il prossimo in coda ---------------------
"$PHP" "$APPDIR/drain.php" >>"$LOG" 2>&1 || true
exit 0
