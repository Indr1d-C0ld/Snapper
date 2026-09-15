# Changelog

## 2026-09-15 — Audit: validazione delle catture, PATH dei cron, chiusura di migrate.php

Esito della prima fase correttiva di un audit completo della piattaforma. I tre
rilievi più gravi; ciascuno verificato eseguendo il codice, non solo leggendolo.

### Una cattura fallita non viene più archiviata come riuscita

Era il difetto peggiore per un archiviatore di prove: archiviando un dominio
irraggiungibile, il worker fotografava la **pagina d'errore del browser**, la
salvava in PDF e HTML, ne calcolava gli hash e vi apponeva una **marca
temporale OpenTimestamps**, registrando il tutto come `ready`. Nell'interfaccia
era indistinguibile da una prova autentica. Il segnale per accorgersene
(`http_status = 0`) era già in database ma non veniva mai letto.

Tre barriere, in ordine di costo crescente (`app/worker.sh`):

1. **Risolvibilità reale** dell'host (`getent ahosts`) nella guardia anti-SSRF.
   Il messaggio d'errore prometteva già questo controllo senza effettuarlo.
2. **Cancello HTTP** subito dopo il preflight: `http_code` `000` significa che
   nessuna connessione è stata stabilita, quindi qualunque artefatto sarebbe la
   schermata d'errore. Si interrompe lì, prima di sprecare `wget` e due avvii
   di Chromium.
3. **Rete di sicurezza sugli artefatti** prima di hash e marca temporale, per il
   caso in cui `curl` non sia disponibile.

Una risposta `4xx`/`5xx` resta **archiviabile** — «a quella data la pagina non
c'era più» è una prova legittima — ma ora viene etichettata: lo snapshot resta
`ready` con un avviso visibile in `status_msg` (`app/worker-db.php`, che prima
azzerava sempre quel campo).

Collaudato su quattro scenari reali: dominio inesistente e porta chiusa →
`error` senza artefatti né marca; 404 reale → archiviato con avviso; pagina
normale → invariata. I fallimenti costano ora 0,2 s e 15 s invece di ~90 s.

### Gli script da cron non trovavano gli strumenti in `/usr/local/bin`

`cron` esegue con `PATH=/usr/bin:/bin`. `ots-upgrade.sh` si affidava al PATH
d'ambiente e quindi non trovava `ots` (installato in `/usr/local/bin`):
registrava «ots non installato» a ogni giro, restando inerte. `PATH` esplicito
ora in `ots-upgrade.sh`, `cron-snapper.sh` e `backup.sh`. Silenziato anche
l'output di `ots upgrade`, che per una marca semplicemente in attesa stampa un
fuorviante «Failed! Timestamp not complete».

### `migrate.php` non è più raggiungibile dal web

Era l'unico script di manutenzione privo sia della guardia `PHP_SAPI === 'cli'`
sia di una voce nella deny-list Apache: rispondeva `200` da internet, eseguiva
DDL sul database e rivelava il percorso assoluto. Aggiunte **entrambe** le
barriere, indipendenti fra loro.

## 2026-09-11 (3) — Completamento automatico delle marche OpenTimestamps

- **`app/ots-upgrade.sh`** (nuovo, deployato come `cron-snapper.sh`/`backup.sh`):
  cron **separato** da quello della coda, ogni 6 ore di proposito (i calendar
  server OpenTimestamps sono infrastruttura pubblica gratuita — interrogarli
  più spesso non avrebbe senso visti i tempi di conferma Bitcoin). Per ogni
  snapshot con `ots_status='stamped'` lancia `ots upgrade` con una cache
  scrivibile dedicata (`/srv/snapshots/.ots-cache`, stesso bug HOME/cache già
  visto nel self-test di `install-ots.sh`, qui risolto in modo permanente);
  se trova un'attestazione Bitcoin confermata (`BitcoinBlockHeaderAttestation`
  nell'output di `ots info` — corretto anche con altri calendar ancora
  pending: basta una prova valida) segna `ots_status='complete'` nel DB e
  aggiorna la riga "OpenTimestamps: stamped" nella pagina statica di quello
  snapshot.
- `ots_status` ha ora tre stati: `none` → `stamped` → `complete`.
- README/CHANGELOG: istruzioni e riga di crontab per `ots-upgrade.sh`.

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
