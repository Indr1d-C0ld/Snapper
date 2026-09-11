# Changelog

## 2026-09-11 (2) — Interfaccia responsive + OpenTimestamps attivabile

- **Mobile/tablet**: nuove regole tutte dentro `@media (max-width:640px)` (o
  innocue a qualsiasi larghezza) — `.tbl-scroll` per far scorrere solo le
  tabelle larghe (Registro, Watch) senza scroll orizzontale di pagina,
  `.col-sec` per nascondere le colonne meno essenziali sotto i 640px, testata
  impilata con nav a piena larghezza, bottoni primari a piena larghezza, input
  a 16px (niente zoom automatico di iOS Safari). Verificato che sopra i 640px
  non cambi nulla. File: `app/assets/snapper.css`, `app/index.php`,
  `app/watches.php`.
- **`deploy/install-ots.sh`** (nuovo): installa il comando `ots` in un venv
  dedicato (`/opt/opentimestamps` + symlink `/usr/local/bin/ots`), leggibile
  da qualunque utente, con self-test reale (marca temporale eseguita come
  `www-data`, lo stesso utente/contesto del worker). `ots` non è su apt
  (`python3-opentimestamps` è solo la libreria) e non va installato con
  `pip`/`pipx --user` da root, altrimenti finirebbe in una home non
  raggiungibile da `www-data`.
- **Fix**: il `PATH` ristretto con cui `save.php`/`resnap.php`/`drain.php`
  avviano il worker (`app/lib.php` → `enqueue_capture()`) non includeva
  `/usr/local/bin` — dove finisce il symlink di `ots` (e in generale dove
  vive software non pacchettizzato via apt). Senza questo fix `ots` non
  sarebbe mai stato trovato dal worker anche installandolo correttamente.
  Ora: `/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin`.

## 2026-09-11 — Prima pubblicazione: tema «Camera Oscura» + hardening

Rework completo del servizio, portato in produzione e pubblicato in versione
neutra.

### Sicurezza dell'accesso

- **Sessione**: cookie `HttpOnly` + `SameSite=Lax` + `Secure` su HTTPS, `path`
  ristretto all'app (non raggiunge `/archives/`), nome dedicato,
  `session_regenerate_id(true)` al login, `use_strict_mode=1`, timeout di
  inattività (1 h) e assoluto (12 h).
- **Login**: rate-limiting per IP con backoff progressivo (15 s → 15 min),
  tentativi registrati in un log dedicato, messaggi d'errore generici.
- **2FA TOTP** opzionale (RFC 6238, SHA1, verifica self-contained ~40 righe).
- **CSRF** su tutte le azioni POST (`save`, `delete`, `pin`, `resnap`,
  `watches`) con `hash_equals`.
- **Anti-SSRF**: `validate_public_url()` risolve il DNS e rifiuta
  loopback/privati/link-local; ricontrollo dopo i redirect lato PHP e nel
  worker (`getent ahosts`).
- **Segreti** spostati fuori dal document root in `/etc/snapper/auth.php`
  (fallback compatibile).
- **Archivi isolati**: `deploy/apache-archives.conf.sample` applica
  `Content-Security-Policy: sandbox` senza `allow-scripts`, disattiva
  l'esecuzione di codice sotto `/archives/`, nega l'accesso web ai file non
  entrypoint dell'app.
- **Worker**: le scritture sul DB passano da `worker-db.php` con prepared
  statement — il `<title>` remoto non entra più per interpolazione nell'SQL.

### Motore di cattura

- `HOME`/`XDG_*` dedicati per Chromium (via gli errori `.pki/nssdb`).
- Preflight HTTP (`curl`): stato, URL finale, `content-type` salvati in DB.
- HTML in un solo file: `monolith` se presente, altrimenti
  `chromium --dump-dom` (DOM post-JS).
- Screenshot a piena pagina; PDF senza header/footer.
- `SHA256SUMS` di tutti gli artefatti; `ots stamp` opzionale (`ots_status`).
- **Diff visivo** tra versioni della stessa catena (`parent_short`) →
  `diff.png` + `diff_pct` (ImageMagick).
- OCR `tesseract` di fallback quando il testo estratto è vuoto.
- **Coda** con `flock` e `MAX_CONCURRENCY`; `drain.php` a fine cattura;
  `cron-snapper.sh` recupera i worker interrotti da oltre 30 min.

### Interfaccia — tema «Camera Oscura / Provino»

- Fondo camera oscura, schede in «carta fotografica», accento rosso safelight,
  nessun font o asset esterno (zero richieste di rete).
- Vista **Provino**: griglia di fotogrammi numerati con crocini di taglio e
  timbri di stato (`Sviluppato` / `In sviluppo` con shimmer / `In coda` /
  `Velato`); miniatura = screenshot reale; cerchio rosso «chinagraph» per le
  prove selezionate (pin); badge `Δ x%` quando esiste un diff.
- Vista **Registro**: tabella con mini-anteprima, dominio, peso, stato HTTP.
- Paginazione su Provino e su Watch, filtro rapido lato client, ricerca FTS.
- Pagina `watches.php` per la gestione dei watch (aggiunta, pausa, intervallo
  inline, «cattura ora», rimozione), paginata.
- Pagina archivio `/archives/<short>/` ridisegnata a «scheda provino» (CSS
  inline: funziona anche sotto sandbox).
- `@media print` → stampa come un contact sheet.

### Schema (migrazione additiva)

`snapshots` +: `final_url`, `http_status`, `content_type`, `sha256`,
`capture_ms`, `pinned`, `note`, `tags`, `parent_short`, `ots_status`,
`diff_pct`. Nuova tabella `watches`. Indici su `status`, `parent_short`,
`pinned`.
