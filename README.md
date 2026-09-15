# Snapper

Archiviatore web personale self-hosted. Dato un URL, ne conserva una copia
completa e datata — mirror statico, pagina in un solo file, screenshot a piena
pagina, PDF, testo, bundle ZIP — con ricerca full-text sull'intero archivio e
un'interfaccia a *provino fotografico* (contact sheet).

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
  marca temporale OpenTimestamps.
- **Validazione della cattura**: se il server non risponde affatto, la cattura
  viene marcata `error` invece di archiviare (e marcare temporalmente) la
  schermata d'errore del browser. Una risposta `4xx`/`5xx` resta archiviabile —
  è una prova legittima — ma viene etichettata.
- **Versioni & diff visivo**: «ri-cattura» crea una nuova versione concatenata;
  Snapper calcola la percentuale di pixel cambiati e produce un `diff.png`.
- **Watch programmati**: osserva un URL e ri-catturalo ogni N ore (pagina di
  gestione dedicata; esecuzione via cron).
- **Coda** con `flock` e limite di concorrenza; recupero dei worker interrotti.
- **Due viste**: *Provino* (griglia di fotogrammi) e *Registro* (tabella),
  paginazione, filtro rapido lato client, stampa come contact sheet.

## Sicurezza dell'accesso

- Login a utente singolo con hash **bcrypt**; segreti in un file fuori dal
  document root (`/etc/snapper/auth.php`).
- **2FA TOTP** opzionale (RFC 6238, verifica self-contained).
- Sessione: cookie `HttpOnly` + `SameSite=Lax` + `Secure` su HTTPS, path
  ristretto, `session_regenerate_id` al login, timeout di inattività e assoluto.
- **Rate-limiting** del login per IP con backoff progressivo; tentativi
  registrati in un log dedicato.
- **CSRF** su tutte le azioni POST.
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
| `app/cron-snapper.sh` | coda + ri-catture programmate + recupero worker morti |
| `app/backup.sh` | backup del DB (`.backup`) e del codice, con rotazione |
| `app/ots-upgrade.sh` | completa le marche OpenTimestamps "in sospeso" (cron separato, bassa frequenza) |
| `app/snapper-perms.sh` | verifica/ripristino di permessi e ownership |
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
  `ots_status`, `diff_pct`.
- `snapshots_fts` — indice FTS5 (`title`, `url`, `body`).
- `watches` — URL osservati: `url`, `title`, `every_hours`, `last_run`,
  `last_short`, `enabled`.

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
