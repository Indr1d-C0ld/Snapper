# Snapper

Archiviatore web personale self-hosted. Dato un URL, ne conserva una copia
completa e datata — mirror statico, pagina in un solo file, screenshot a piena
pagina, PDF, testo, bundle ZIP — con ricerca full-text sull'intero archivio e
un'interfaccia a *provino fotografico* (contact sheet). Un bot Telegram
facoltativo permette di archiviare inoltrando un link dal telefono. Per
Wikipedia archivia revisioni specifiche di una voce, con il wikitesto
verificabile contro l'impronta che Wikipedia stessa pubblica. Può scaricare anche
siti interi, isolati dal resto del web e navigabili senza rete, con il WARC.

Stack: **PHP 8.1+** e **SQLite** (FTS5) per la webapp, **bash** per il worker di
cattura. Nessun framework, nessun database server, nessuna build. Dipende solo
da strumenti di sistema comuni (`wget`, `chromium`, `w3m`, `zip`); alcuni extra
sono opzionali (`monolith`, `tesseract`, ImageMagick, `ots`).

## Caratteristiche

- **Cattura multi-formato**: mirror `wget` con link riscritti, HTML in un solo
  file (`monolith` se presente, altrimenti `chromium --dump-dom`), PNG a piena
  pagina e PDF via Chromium headless, dump testo `w3m`, bundle `.zip`.
- **Metadati HTTP**: stato finale, URL dopo i redirect, `content-type`.
- **Ricerca full-text** FTS5 (operatori `AND`/`OR`/`NOT`, frasi, prefissi) su
  titolo, URL e corpo del testo; OCR di fallback (`tesseract`) per le pagine
  senza testo estraibile.
- **Integrità**: `SHA256SUMS` di tutti gli artefatti e, se `ots` è installato,
  marca temporale OpenTimestamps sul manifesto. Per verificare un bundle:
  estrarlo e controllarne i file contro il `SHA256SUMS` che contiene — è quel
  manifesto a portare la marca (includervi l'impronta del bundle, che a sua
  volta contiene il manifesto, sarebbe circolare).
- **Validazione della cattura**: se il server non risponde affatto, la cattura
  viene marcata `error` invece di archiviare (e marcare temporalmente) la
  schermata d'errore del browser. Una risposta `4xx`/`5xx` resta archiviabile —
  è una prova legittima — ma viene etichettata.
- **Versioni & diff visivo**: «ri-cattura» crea una nuova versione concatenata;
  Snapper calcola la percentuale di pixel cambiati e produce un `diff.png`.
- **Watch programmati**: osserva un URL e ri-catturalo ogni N ore (pagina di
  gestione dedicata; esecuzione via cron).
- **Coda** con `flock`: conteggio, presa in carico e avvio dei worker sono
  atomici fra tutti i punti d'ingresso, quindi il limite di concorrenza vale
  anche sotto richieste simultanee; recupero dei worker interrotti.
- **Due viste**: *Provino* (griglia di fotogrammi) e *Registro* (tabella),
  paginazione, filtro rapido lato client, stampa come contact sheet.

## Sicurezza dell'accesso

- Login a utente singolo con hash **bcrypt**; segreti in un file fuori dal
  document root (`/etc/snapper/auth.php`).
- **2FA TOTP** opzionale (RFC 6238, verifica self-contained).
- Sessione: cookie `HttpOnly` + `SameSite=Lax` + `Secure` su HTTPS, path
  ristretto, `session_regenerate_id` al login, timeout di inattività e assoluto.
- **Rate-limiting** del login per IP con backoff progressivo, più una penalità
  fissa su ogni tentativo fallito (il backoff per IP non morde un attacco
  distribuito, e un blocco globale permetterebbe a un terzo di chiudere fuori
  l'utente legittimo); tentativi registrati in un log dedicato.
- **CSRF** su tutte le azioni POST, **login compreso**.
- Il browser di cattura gira senza telemetria né aggiornamento componenti:
  nessuna connessione in uscita oltre al sito che stai archiviando.
- **Anti-SSRF**: gli URL da archiviare vengono risolti e rifiutati se puntano a
  indirizzi loopback / privati / link-local; ricontrollo dopo i redirect, sia
  lato PHP sia nel worker.
- **Isolamento degli archivi**: le pagine di terzi archiviate vanno servite da
  un contesto separato con `Content-Security-Policy: sandbox` (niente script,
  niente cookie di sessione) e senza esecuzione di codice — vedi
  `deploy/apache-archives.conf.sample`. Idealmente da un hostname distinto.

> La protezione SSRF completa richiede comunque un filtro d'uscita a livello di
> rete (firewall/proxy egress): le guardie applicative coprono i casi pratici.

## Componenti

| Percorso | Ruolo |
|---|---|
| `app/index.php` | Provino / Registro, ricerca, form di archiviazione, azioni |
| `app/watches.php` | gestione dei watch: aggiunta, pausa, intervallo, «cattura ora», rimozione |
| `app/save.php` `resnap.php` `pin.php` `delete.php` | azioni POST (con CSRF) |
| `app/login.php` `logout.php` | autenticazione (bcrypt + TOTP opzionale + throttling) |
| `app/lib.php` | anti-SSRF, TOTP, throttling, coda, helper di presentazione, layout/tema |
| `app/config.sample.php` | modello di configurazione (copia in `config.php`) |
| `app/worker.sh` | worker di cattura (mirror, screenshot, PDF, testo, diff, hash, ZIP) |
| `app/worker-db.php` | scritture DB del worker via prepared statement |
| `app/drain.php` | avvio dei lavori in coda entro `MAX_CONCURRENCY` (`flock`) |
| `app/migrate.php` | migrazione idempotente dello schema |
| `app/reindex-fts.php` | ricostruisce l'indice full-text dai `text.txt` su disco |
| `app/cron-snapper.sh` | coda + ri-catture programmate + recupero worker morti |
| `app/backup.sh` | backup del DB (`.backup`) e del codice, con rotazione |
| `app/ots-upgrade.sh` | completa le marche OpenTimestamps "in sospeso" (cron separato, bassa frequenza) |
| `app/snapper-perms.sh` | verifica/ripristino di permessi e ownership |
| `app/api.php` | API JSON per servizi: solo da loopback, token Bearer con ambiti |
| `app/api-token.php` | crea / elenca / ruota / revoca i token dell'API (solo CLI) |
| `app/wiki.php` | Wikipedia: dossier delle voci, cronologia filtrabile, acquisizione di revisioni |
| `app/wikilib.php` | Wikipedia: riconoscimento degli URL, client delle API, cronologia annotata (solo include) |
| `app/wiki-worker.php` | Wikipedia: acquisizione ed esportazione del dossier in background, avviate da `worker.sh` (solo CLI) |
| `app/wikicmp.php` | Wikipedia: banco di confronto, dinamiche della voce, attribuzione |
| `app/wikidiff.php` | Wikipedia: motore di confronto, analisi strutturale, cronologia, WikiWho (solo include) |
| `app/sites.php` | Siti interi: modulo con profili e filtri, stima, avanzamento, dettaglio con ricerca |
| `app/crawllib.php` | Siti interi: barriera anti-SSRF, ambito, filtri, trappole, riscrittura, WARC (solo include) |
| `app/crawl-worker.php` | Siti interi: stima e cattura in background (solo CLI) |
| `bot/snapper_bot.py` | bot Telegram dedicato (solo libreria standard Python) |
| `bot/snapper-bot.service` | unità systemd con utente effimero e sandbox stretta |
| `bot/bot.env.sample` | modello di `/etc/snapper/bot.env` |
| `deploy/install-bot.sh` | installa il bot: token, abbinamento della chat con `/start`, avvio |
| `app/assets/snapper.css` | tema «Camera Oscura / Provino» (nessun asset esterno) |
| `deploy/apache-archives.conf.sample` | sandbox degli archivi + stop all'esecuzione di codice |
| `deploy/auth.php.sample` | modello per `/etc/snapper/auth.php` |
| `deploy/install-ots.sh` | installa il comando `ots` in un venv di sistema (vedi sotto) |
| `deploy/logrotate-snapper.conf.sample` | rotazione dei log (`worker.log` cresce in fretta) |

## Schema dati (SQLite)

- `snapshots` — una riga per cattura: `short` (id pubblico), `url`, `title`,
  `ts`, `status` (`pending`/`running`/`ready`/`error`), `status_msg` (motivo
  dell'errore, oppure avviso su una cattura comunque valida — es. HTTP 404),
  `size_bytes`, `final_url`, `http_status`, `content_type`, `sha256`,
  `capture_ms`, `pinned`, `note`, `tags`, `parent_short` (catena di versioni),
  `ots_status`, `diff_pct`, `done_at` (conclusione della cattura: cursore
  degli eventi), `source` (`web`, `watch`, `api:<nome token>`), `kind`
  (`page` o `wiki`).
- `snapshots_fts` — indice FTS5 (`title`, `url`, `body`).
- `watches` — URL osservati: `url`, `title`, `every_hours`, `last_run`,
  `last_short`, `enabled`.
- `api_tokens` — token dell'API: solo l'impronta SHA-256, `scopes`,
  `last_used`, `uses`, `revoked`.
- `wiki_pages` — voci di Wikipedia con revisioni archiviate (`lang`, `pageid`, `title`,
  `wikiwho`: consenso all'attribuzione per quella voce).
- `wiki_revisions` — revisioni archiviate: `revid`, data, autore, commento,
  dimensione, `sha1` pubblicato da Wikipedia, `sha1_ok`, `sha256_wikitext`,
  etichette, e la prova (`short`) che le contiene.
- `wiki_jobs` — revisioni richieste da un'acquisizione in coda.
- `site_jobs`, `site_estimates` — opzioni delle catture di siti e risultati delle stime.
- `site_pages` — ogni risorsa di un sito: URL, percorso locale, tipo, stato HTTP,
  dimensione, SHA-256; `site_pages_fts` — ricerca nel testo delle pagine.

Gli artefatti di ogni cattura stanno in `<DATA_DIR>/<short>/` e sono esposti
pubblicamente come `/archives/<short>/` tramite un symlink.

## Installazione

```bash
git clone https://github.com/Indr1d-C0ld/Snapper.git
cd Snapper

# 1. File applicativi
sudo mkdir -p /var/www/html/snapper
sudo cp -r app/. /var/www/html/snapper/
sudo cp app/config.sample.php /var/www/html/snapper/config.php
sudoedit /var/www/html/snapper/config.php     # rivedi i percorsi

# 2. Dati e database
sudo mkdir -p /srv/snapshots /var/www/html/archives
sudo -u www-data php /var/www/html/snapper/migrate.php   # crea/aggiorna lo schema

# 3. Credenziali (fuori dal document root)
sudo mkdir -p /etc/snapper
sudo cp deploy/auth.php.sample /etc/snapper/auth.php
php -r 'echo password_hash("LA-TUA-PASSWORD", PASSWORD_BCRYPT), PHP_EOL;'
sudoedit /etc/snapper/auth.php                 # incolla l'hash; opzionale: totp_secret
sudo chown root:www-data /etc/snapper/auth.php && sudo chmod 640 /etc/snapper/auth.php

# 4. Apache: sandbox degli archivi
sudo cp deploy/apache-archives.conf.sample /etc/apache2/conf-available/snapper-archives.conf
sudo a2enmod headers && sudo a2enconf snapper-archives && sudo systemctl reload apache2

# 5. Permessi
sudo bash /var/www/html/snapper/snapper-perms.sh --fix

# 6. Rotazione dei log (worker.log raccoglie tutto lo stderr di Chromium:
#    senza rotazione cresce senza limite)
sudo cp deploy/logrotate-snapper.conf.sample /etc/logrotate.d/snapper
sudo logrotate -d /etc/logrotate.d/snapper     # verifica a vuoto

# 7. (Opzionale) cron per coda + watch + backup, come utente www-data
#    */5 * * * * /var/www/html/snapper/cron-snapper.sh >> /srv/snapshots/cron.log 2>&1
#    17 3  * * * /var/www/html/snapper/backup.sh        >> /srv/snapshots/backup.log 2>&1
```

L'app presuppone di essere servita sotto `/snapper/` con gli archivi sotto
`/archives/` sullo stesso host (rivedi i percorsi in `config.php` e i redirect
in-app se cambi prefisso).

## Wikipedia

Scheda **Wikipedia**: si incolla l'indirizzo di una voce (qualsiasi lingua; anche
link con `?oldid=` o `?diff=`) e si apre la cronologia, filtrabile per date, autore,
variazione minima e con la possibilità di nascondere modifiche minori, bot, revert e
modifiche annullate. Ogni riga è annotata: revert (etichette `mw-rollback`,
`mw-undo`, `mw-manual-revert`), modifiche poi annullate (`mw-reverted`), ritorni a un
testo identico a una revisione precedente (stesso sha1), bot, autori non registrati.

Si acquisiscono le revisioni selezionate, le ultime N, un intervallo di date, tutte
le modifiche di un autore o la versione in vigore a una data (massimo 50 per volta;
quelle già archiviate vengono saltate). Ogni acquisizione è una prova immutabile:

| File | Contenuto |
|---|---|
| `rev/<revid>/wikitext.txt` | il testo sorgente esatto, verificato con lo sha1 pubblicato da Wikipedia |
| `rev/<revid>/index.html` | la revisione resa, navigabile senza rete |
| `rev/<revid>/page.css` | stili della revisione (TemplateStyles e stili inline convertiti in classi) |
| `rev/<revid>/meta.json`, `parse.json` | metadati; sezioni, categorie, link esterni, template |
| `assets/` | fogli di stile di Wikipedia e immagini, scaricati una volta per prova |
| `SHA256SUMS`, `SHA256SUMS.ots`, `bundle.zip` | manifesto, marca temporale, archivio |

La verifica non dipende da Snapper: `sha1sum rev/<revid>/wikitext.txt` deve
coincidere con lo sha1 che le API di Wikipedia restituiscono per quella revisione.

**Isolamento.** Le copie non contattano nulla all'esterno: immagini e stili sono
locali, i link verso voci già archiviate puntano alla copia locale, tutti gli altri
sono resi inerti (l'indirizzo resta leggibile passandoci sopra). Gli stili inline
diventano classi perché la CSP degli archivi non ammette `unsafe-inline`.

**Limite dichiarato.** Wikipedia ricostruisce le revisioni vecchie con i template e
le immagini attuali: il wikitesto è esatto, la resa grafica di una revisione vecchia
è un'approssimazione, e la pagina lo dice.

Snapper contatta solo `<lingua>.wikipedia.org` e gli host multimediali di Wikimedia,
in serie, con il parametro `maxlag` e un User-Agent con l'URL di questo repository.

### Banco di confronto

Dal dossier di una voce si scelgono due revisioni archiviate, A e B. Il confronto
legge i file delle prove, non Wikipedia.

| Scheda | Contenuto |
|---|---|
| Testo, Affiancato | differenze parola per parola (o per frase), con i passaggi spostati riconosciuti come tali |
| Wikitesto | il sorgente: commenti nascosti, parametri, categorie; citazioni solo riformattate ignorabili |
| Struttura | sezioni (nuove, tolte, rinominate, spostate), note e domini delle fonti, infobox campo per campo, template con gli avvisi di manutenzione, categorie, collegamenti, immagini |
| Dinamiche | dimensione nel tempo con i revert, alternanze fra versioni identiche (stesso sha1) con chi ripristina e chi viene annullato, autori con byte aggiunti e tolti |
| Attribuzione | chi ha scritto ogni frammento e quando (WikiWho), colorato per anno di inserimento |

Il confronto è a due livelli, come quello di MediaWiki: prima i blocchi (paragrafi,
voci di elenco, righe di tabella; righe del wikitesto), poi le parole dentro i blocchi
cambiati. L'algoritmo è quello di Myers a spazio lineare, con un tetto di tempo; è
collaudato su migliaia di casi casuali contro la sottosequenza comune più lunga
calcolata per forza bruta. Sotto ogni confronto: le modifiche intermedie su Wikipedia,
con autore e commento.

**Attribuzione.** WikiWho è un servizio di ricerca su Wikimedia Cloud: riceve lingua e
id delle revisioni analizzate, il che rivela quale voce si sta studiando. Si attiva voce
per voce con un clic esplicito, e l'attivazione resta nel registro di audit. I frammenti
di WikiWho (minuscoli, senza spazi) vengono riallineati al wikitesto archiviato.

**Esportazione del dossier.** Una nuova prova con le prove originali copiate intatte
(manifesti e marche del giorno dell'acquisizione compresi), i confronti già calcolati
fra revisioni consecutive e fra la prima e l'ultima, la linea del tempo, un nuovo
manifesto marcato e lo ZIP. Si apre con un normale browser.

Le dinamiche leggono al massimo le ultime 5.000 modifiche di una voce (copia locale di
6 ore in `<DATA_DIR>/.wikicache`).

## Siti interi

Scheda **Siti**. Si indica l'indirizzo di partenza e si sceglie un profilo (solo
questa sezione, sito intero prudente, documentazione, blog senza archivi e tag),
oppure si regolano filtri e limiti:

| Gruppo | Opzioni |
|---|---|
| Ambito | sotto il percorso, tutto l'host, host e sottodomini; directory incluse ed escluse |
| Limiti | profondità dei collegamenti, pagine, MB, minuti |
| Tipi | immagini, stili, caratteri, script, PDF, documenti, audio e video, archivi |
| Indirizzi | espressioni regolari di inclusione ed esclusione, parametri da ignorare (tracciamento, sessioni) |
| Trappole | calendari, ordinamenti, login e carrelli, percorsi ripetuti, troppe varianti della stessa pagina |
| Esterni e cortesia | risorse esterne necessarie alla pagina sì/no (i link esterni non si seguono mai), robots.txt, pausa, banda |

**Stima prima** legge solo le pagine, per qualche minuto, e riporta numero di
pagine, dimensione stimata, directory più popolose, trappole e richieste bloccate.
Dall'API: `POST ?a=site {url, preset}`; dal bot: `/sito <url> [profilo]`.

**Barriera anti-SSRF nel crawler.** Un crawler segue link scelti da altri, quindi
il controllo non si ferma all'indirizzo di partenza: ogni richiesta, ogni passo di
ogni redirect, risolve il nome e viene rifiutata se anche un solo indirizzo non è
pubblico o è l'IP pubblico del server stesso (ricavato da ServerName/ServerAlias di
Apache); il collegamento avviene proprio all'indirizzo verificato (contro il DNS
rebinding) e viene ricontrollato a posteriori; solo http/https su porte web. Non
sostituisce una barriera di rete nel kernel, che resta consigliabile. Il JavaScript
delle pagine non viene eseguito.

Ogni sito diventa una prova con la copia navigabile in `site/<host>/` (collegamenti
riscritti verso le copie locali, esterni e non scaricati resi inerti, script
rimossi, stili inline spostati in file per la CSP degli archivi), il **WARC**
(`warc/<codice>.warc.gz`, ogni scambio HTTP così com'è passato in rete) con
l'indice CDXJ, l'indice delle pagine, `pagine.json`, il manifesto marcato e lo ZIP.

La coda ha due corsie: un sito alla volta, senza occupare i posti delle catture
di pagina.

## API e bot Telegram (opzionale)

Per archiviare senza aprire l'interfaccia web: inoltri un link al bot, il
messaggio «⏳ In sviluppo» diventa l'esito della cattura (titolo, HTTP,
SHA-256, stato OpenTimestamps, collegamento alla prova). Il bot avvisa anche
quando una pagina osservata cambia oltre una soglia (`DIFF_THRESHOLD`, default
1%) e quando una ricattura programmata fallisce. Comandi: `/cerca`, `/ultimi`,
`/prova`, `/osserva`, `/stato`, `/aiuto`.

```bash
# api.php e api-token.php sono già in app/; la conf Apache del passo 4 limita
# api.php al loopback. Poi crea un bot NUOVO con @BotFather e:
sudo bash deploy/install-bot.sh
```

Lo script chiede il token del bot senza mostrarlo, abbina la tua chat quando
scrivi `/start`, crea il token dell'API, lo collauda, scrive
`/etc/snapper/bot.env` (root, 600) e avvia il servizio.

**Sicurezza.** `api.php` risponde solo da `127.0.0.1`/`::1`, controllato due
volte: `Require local` in Apache e di nuovo in PHP (dall'esterno: 404). Se
davanti ad Apache c'è un reverse proxy *sulla stessa macchina*, le richieste
esterne arrivano da loopback: in quel caso questa barriera non vale e va
rivista. Token di 32 byte casuali, nel DB solo l'impronta; accettati solo
nell'header `Authorization: Bearer` (in query string: 400). Ambiti `capture` /
`read` per token, stessa validazione anti-SSRF dell'interfaccia, massimo 60
catture/ora per token. Il bot risponde solo alle chat abbinate e gira con un
utente systemd effimero senza accesso ai dati.

| Azione | Metodo | Ambito | |
|---|---|---|---|
| `?a=capture` | POST `{url, title?}` | capture | archivia (stesso URL entro 10 min: restituisce la prova esistente) |
| `?a=watch` | POST `{url, every_hours, title?}` | capture | osserva e ricattura periodicamente |
| `?a=status&short=` | GET | read | stato di una prova |
| `?a=recent&limit=` | GET | read | ultime prove |
| `?a=search&q=` | GET | read | ricerca full-text con estratti, ordinata per pertinenza |
| `?a=events&since=` | GET | read | catture concluse dopo il cursore `done_at\|short` |
| `?a=health` | GET | read | coda, archivio, OTS, ultimo backup, disco, catture per origine |

```bash
sudo -u www-data php /var/www/html/snapper/api-token.php list
sudo -u www-data php /var/www/html/snapper/api-token.php create integrazione --scopes=read
sudo -u www-data php /var/www/html/snapper/api-token.php revoke telegram-bot
```

## Strumenti opzionali

| Strumento | A cosa serve | Se manca |
|---|---|---|
| `monolith` | HTML self-contained fedele | ripiego su `chromium --dump-dom` |
| `tesseract` | OCR di fallback per pagine senza testo | nessun testo per quelle pagine |
| ImageMagick (`compare`,`convert`,`identify`) | diff visivo tra versioni | nessun `diff.png` / `diff_pct` |
| `ots` (opentimestamps-client) | marca temporale sui `SHA256SUMS` | solo hash, niente timestamp |

`ots` **non è su apt** (`python3-opentimestamps` è solo la libreria, non fornisce
il comando) e **non va installato con `pip`/`pipx --user` da root**: finirebbe in
una home non raggiungibile da `www-data`, che è chi lo invoca dal worker.

```bash
sudo bash deploy/install-ots.sh
```

Crea un venv dedicato in `/opt/opentimestamps`, un symlink in `/usr/local/bin/ots`
(già nel `PATH` con cui `save.php`/`resnap.php`/`drain.php` avviano il worker), e
chiude con una marca temporale di prova reale eseguita come `www-data`. Una volta
installato il worker lo rileva da solo. Le marche restano "in sospeso" finché non
confermate su Bitcoin (di norma qualche ora). Per completarle **automaticamente**,
`app/ots-upgrade.sh` — cron **separato** da quello della coda, a bassa frequenza
di proposito (i calendar server sono infrastruttura pubblica gratuita):

```
0 */6 * * * /var/www/html/snapper/ots-upgrade.sh >> /srv/snapshots/ots-upgrade.log 2>&1
```

In alternativa, a mano: `sudo -u www-data ots upgrade <short>/SHA256SUMS.ots`.

## Licenza

GPL-3.0-or-later. Vedi [`LICENSE`](LICENSE).
