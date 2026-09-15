# Changelog

## 2026-09-15 (4) — Audit fase 4: rifiniture di sicurezza e correttezza

Ultima fase correttiva dell'audit. Chiude i rilievi minori rimasti.

- **Escape mancante** (`app/worker.sh`): stato HTTP, `Content-Type` e link alla
  copia statica finivano grezzi nella scheda dell'archivio, pur provenendo da un
  server terzo che li controlla. Non erano sfruttabili per eseguire codice — la
  CSP `sandbox` degli archivi lo impedisce — ma la CSP non deve restare l'unica
  barriera. Ora passano tutti da `esc()`.
- **Ricerca persa selezionando una prova** (`app/pin.php`, `app/index.php`): il
  filtro anti-redirect applicato all'URL già assemblato rifiutava qualunque
  ricerca con uno spazio (codificato `+`) o con virgolette — cioè il caso
  normale — riportando all'elenco completo. La destinazione viene ora
  ricostruita dai singoli parametri validati: il redirect verso host esterni
  resta impossibile per costruzione, perché l'URL lo compone l'applicazione.
- **Ordine di cancellazione** (`app/delete.php`): prima il disco, poi il
  database. Cancellando prima le righe, un fallimento su disco lasciava cartelle
  orfane non più elencate — spazio occupato e invisibile. Ora un fallimento
  lascia lo snapshot visibile e ri-eliminabile, con avviso esplicito.
- **Lettura confinata** (`app/worker-db.php`): `body_file` arriva da stdin e
  viene ora vincolato alla cartella dati via `realpath`.
- **CSRF sul login** (`app/login.php`): token anche sul modulo di accesso. Un
  POST respinto per token mancante **non consuma tentativi** di throttling, non
  essendo un tentativo di password. Aggiunta inoltre una penalità fissa di
  750 ms su ogni fallimento: il backoff per IP non morde un attacco
  distribuito, e un blocco globale permetterebbe a un terzo di chiudere fuori
  l'utente legittimo.
- **Chromium senza telemetria** (`app/worker.sh`): `--disable-background-networking`,
  `--disable-component-update`, `--disable-sync` e affini. Un archiviatore non
  deve contattare i servizi del browser; verificato, azzera quelle connessioni.
- `app/snapper-perms.sh` riconosce `.ots-cache`, i log operativi e i `.gz`
  prodotti da logrotate.

### Due rilievi rientrati, non corretti

- **Prestazioni**: avevo misurato 89 secondi per archiviare una pagina semplice.
  Rimisurando con i tempi per singola fase il worker impiega **4,6 secondi**:
  gli 89 s erano il costo una tantum della primissima esecuzione di Chromium
  (inizializzazione componenti e tentativi verso i servizi Google). Non era un
  difetto sistemico. I flag qui sopra restano un miglioramento a sé.
- **`SHA256SUMS` non copre `bundle.zip`**: non è correggibile ed è corretto
  così. Il bundle *contiene* il manifesto, quindi includervi l'impronta del
  bundle sarebbe circolare. La verifica si fa estraendo l'archivio e
  controllando i file contro il `SHA256SUMS` che vi si trova dentro — ed è quel
  manifesto a portare la marca temporale.

## 2026-09-15 (3) — Audit fase 3: pulizia del profilo Chromium e coda atomica

### Ogni cattura non abbandona più 4,8 MB sul disco

Il profilo Chromium per-cattura (`$ROOT/.home`), creato per evitare gli errori
di cache del browser, non veniva mai rimosso: 214 file, ~4,8 MB **per singola
prova**. Essendo escluso dal calcolo di `size_bytes`, dal bundle ZIP e dai
backup, la crescita era invisibile ovunque la si guardasse — l'interfaccia
dichiarava 72 KB per una cartella che ne occupava quasi 5 MB.

Rimozione via `trap … EXIT` in `app/worker.sh`, quindi valida anche sui
fallimenti. Aggiunta una validazione difensiva dello `short` in testa allo
script: oggi arriva sempre da `safe_short()`, ma finisce dentro un `rm -rf` e
un punto d'ingresso futuro non deve potervi infilare un percorso relativo.

### Il limite di worker simultanei non è più aggirabile

`save.php`, `resnap.php` ed `enqueue_capture()` contavano i worker attivi e poi
ne avviavano uno **senza lock**: due richieste contemporanee leggevano lo stesso
conteggio e partivano entrambe. Solo `drain.php` prendeva il `flock`.

Conteggio, presa in carico e avvio avvengono ora dentro quel lock, con una
struttura a due livelli: `spawn_worker_locked()` presuppone il lock (usata da
`drain.php`, che lo tiene per tutto il giro) e `with_queue_lock()` lo acquisisce
(usata dai punti d'ingresso web). La distinzione è necessaria: una funzione che
riacquisisse il lock si bloccherebbe da sé su un secondo descrittore.

Il lock non è bloccante — dieci tentativi da 50 ms, poi rinuncia e lascia lo
snapshot in coda: appendere una richiesta web sarebbe peggio del problema, e
`drain.php` lo raccoglie comunque al giro successivo.

Verificato iniettando un ritardo identico di 40 ms fra conteggio e avvio in
entrambe le versioni: la precedente lanciava 8 worker contro un limite di 2, la
corretta si ferma a 2.

### Un solo punto di avvio del worker

Erano quattro copie della stessa logica, con la stringa del `PATH` ripetuta ogni
volta — ed è precisamente ciò che aveva permesso al difetto del `PATH` di
ripresentarsi in `ots-upgrade.sh`. Ora una sola implementazione e una sola
costante `WORKER_PATH`.

## 2026-09-15 (2) — Audit fase 2: osservabilità di backup e log

### Il backup non fallisce più in silenzio

`backup.sh` moriva con codice 2 sull'ultimo pattern di rotazione: `ls` su un
glob senza corrispondenze falliva, `2>/dev/null` ne nascondeva il messaggio e
`pipefail` + `set -e` interrompevano lo script **prima della riga di conferma
finale**. I backup venivano comunque creati, ma il log restava vuoto — e un log
vuoto è indistinguibile da «non è mai partito».

- Rotazione riscritta con `mapfile < <(ls … || true)`: un pattern vuoto produce
  zero file invece di abortire lo script.
- **Trap `ERR`**: ogni fallimento futuro scrive `BACKUP FALLITO alla riga N` ed
  esce con codice ≠ 0, così `cron` segnala l'anomalia.
- Riepilogo finale con numero di dump, archivi codice e spazio occupato.
- Corretto anche `--with-archives`, che includeva nel tar la cartella dei backup
  — cioè se stesso — dato che `backups/` risiede sotto la cartella dati. Escluse
  ora anche `.home`, `.ots-cache`, `ratelimit` e i sidecar SQLite.

### Rotazione dei log

Nuovo `deploy/logrotate-snapper.conf.sample`. `worker.log` raccoglie l'intero
stderr di Chromium a ogni cattura: senza rotazione cresce senza limite. Due
regimi distinti: `worker`/`cron`/`backup`/`ots-upgrade` settimanali con
`maxsize 20M` e 8 rotazioni; `auth.log` mensile con 24 rotazioni e senza
troncamento, essendo il registro degli accessi.

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
