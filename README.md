# Dev Bridge

[![CI](https://github.com/lab591/wpdev/actions/workflows/ci.yml/badge.svg)](https://github.com/lab591/wpdev/actions/workflows/ci.yml)

Permette a **Claude Code in locale** di lavorare su un sito WordPress remoto in modo sicuro:

- legge il codice del sito (core, plugin, temi) tramite strumenti MCP, senza scaricarlo tutto;
- modifica in locale **solo** le cartelle abilitate (child theme, plugin custom);
- alla fine di ogni turno pubblica le modifiche con **lint, health check e rollback automatico**;
- se un errore fatale rende irraggiungibile WordPress, recupera il sito con un **rollback fuori banda**.

Il sistema è composto da due parti:

| Parte | Cartella | Cosa fa |
|---|---|---|
| Plugin WordPress **Lab591 Dev Bridge** | `plugin/` | API REST `devbridge/v1`, PathGuard, deploy/backup/rollback, audit log, mu-plugin di rescue |
| Companion **`wpdev`** | `companion/` | CLI e server MCP per Claude Code (Node 20+) |

La specifica completa è in [`SPEC.md`](SPEC.md); i dettagli del protocollo in
[`docs/protocol.md`](docs/protocol.md); gli scenari di prova in [`docs/e2e.md`](docs/e2e.md).

---

## 1. Installare il plugin sul sito

Requisiti: WordPress ≥ 6.6, PHP ≥ 8.1 con estensione `zip`, HTTPS (in locale basta
`WP_ENVIRONMENT_TYPE` = `local`).

1. Crea lo zip: `cd plugin && npm install && npm run package` → `dist/lab591-dev-bridge-<versione>.zip`
   (compila l'interfaccia di amministrazione e impacchetta; servono Node 20+ e Composer).
2. Caricalo da *Plugin → Aggiungi nuovo → Carica plugin* e attivalo.
   All'attivazione viene copiato il mu-plugin di rescue in `wp-content/mu-plugins/`.
3. **Consigliato:** sposta lo storage privato (backup delle release, token) fuori dalla document root,
   in `wp-config.php`:

   ```php
   define( 'DEVBRIDGE_STORAGE_DIR', '/percorso/fuori/webroot/devbridge' );
   ```

   Senza la costante lo storage è in `wp-content/devbridge-<suffisso casuale>/`, protetto da
   `.htaccess`. **Su Nginx** serve una regola esplicita (la pagina del plugin mostra lo snippet esatto):
   `location ~ ^/wp-content/devbridge-<suffisso>/ { deny all; return 404; }`.
4. In *Impostazioni → Dev Bridge* la scheda **Stato** mostra modalità, controlli di configurazione (utenti,
   HTTPS, rescue, storage, ripgrep) e i comandi per collegare Claude Code. Nella scheda **Impostazioni**:
   - **Utenti autorizzati**: spunta il tuo utente amministratore (di default nessuno può accedere);
   - **Cartelle scrivibili**: spunta il tema e/o il plugin su cui lavorare (es. `mio-child`, `mio-plugin`);
     con *sottocartelle* puoi scegliere anche solo una parte. L'elenco mostra solo ciò che è ammesso: le
     cartelle intere `themes/`/`plugins/` e Dev Bridge non sono selezionabili. **Sono l'unico posto dove
     si decidono**: il companion le legge dal sito;
   - **Nuova cartella**: per un plugin o tema che non esiste ancora, scegli `plugins/` o `themes/`, scrivi il
     nome (es. `mio-plugin`, oppure `mio-tema/blocks` per una sottocartella) e premi *Crea e seleziona*:
     la cartella viene creata vuota e resa scrivibile. Dopo `wpdev pull` puoi chiedere a Claude, ad esempio,
     "inizializza in wp-content/plugins/mio-plugin un plugin che fa…" o "crea un tema figlio di X in
     wp-content/themes/mio-child";
   - facoltativi: allowlist IP, proxy fidati, URL di health check, limiti, deny list aggiuntiva;
   - **Notifiche** (facoltative): email e/o un webhook (es. Slack o Discord) quando Claude pubblica, quando c'è
     un rollback o quando viene attivata la scrittura, con il pulsante *Invia una prova*.
     `wp-config*.php`, `.env*`, `.git`, `.htpasswd` e lo storage sono sempre esclusi.
5. Crea una **Application Password** per quell'utente (*Utenti → Profilo → Password applicazione*).

Se `wp-content/mu-plugins` non è scrivibile direttamente, la pagina del plugin spiega come copiare a
mano il file di rescue; finché manca, `wpdev status` lo segnala.

### Modalità sviluppo

Con modalità **off** (predefinita) ogni endpoint risponde 403. La modalità si attiva **solo** dal
pannello (*Stato → Attiva*) o da WP-CLI, sempre con scadenza (read max 72 h, write max 8 h):

```bash
wp devbridge enable --mode=read --hours=24     # esplorazione
wp devbridge enable --mode=write --hours=4     # anche deploy e rollback
wp devbridge disable
wp devbridge status
wp devbridge releases
wp devbridge rollback [<id>] [--force]
```

Nessun endpoint REST può cambiare modalità, impostazioni o cartelle.

---

## 2. Installare il companion `wpdev`

Requisiti: Node ≥ 20. Nessun modulo nativo: funziona su Windows, macOS e Linux.

```bash
cd companion
npm install
npm run build
npm link        # rende disponibile il comando `wpdev`
```

PHP in locale è facoltativo: prima di ogni deploy la sintassi dei `.php` viene controllata con `php -l`
se PHP c'è (si indica con `"php"` in `wpdev.json`, anche un percorso assoluto), altrimenti con il parser PHP
integrato in `wpdev`, esatto sul codice moderno.

## 3. Collegare un progetto al sito

In una cartella vuota (il progetto locale del sito):

```bash
echo "WPDEV_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx" > .env.local
wpdev init --site https://www.esempio.it --user mioutente
wpdev pull
```

Le cartelle scrivibili non vanno indicate: `wpdev` usa quelle scelte nel pannello del sito (`wpdev status`
le mostra). Se ne aggiungi una nel pannello, basta un `wpdev pull` per scaricarla. Solo se vuoi che questo
progetto lavori su **una parte** di quelle cartelle, indicale in `wpdev.json`
(`"writable": ["wp-content/themes/mio-child"]`) o con `wpdev init --writable ...`.

`wpdev init` crea:

| File | Scopo |
|---|---|
| `wpdev.json` | configurazione (versionata; **mai** la password) |
| `.gitignore` | esclude `.wpdev/` e `.env.local` |
| `.gitattributes` | `* -text`: i file restano identici byte per byte al server (fondamentale su Windows con `core.autocrlf=true`) |
| `.claude/settings.json` | hook **Stop** → `wpdev deploy --hook` (deploy automatico a fine turno) |
| `.mcp.json` | server MCP `wpdev` per Claude Code |
| `CLAUDE.md` | istruzioni per Claude sul sito, in una sezione tra i marcatori `wpdev:start`/`wpdev:end` (se il file esiste già la sezione viene aggiunta in fondo) |

Se il progetto è un repository git, dopo ogni deploy riuscito `wpdev` fa un commit con **solo** i file
pubblicati (messaggio `wpdev deploy <release>`), così la cronologia locale segue le release del sito; le altre
modifiche restano come sono. Si disattiva con `"deploy": { "gitCommit": false }` in `wpdev.json`.

### Più ambienti (staging e produzione)

Si può lavorare con un sito di prova e pubblicare in produzione solo quando serve:

```json
{
  "environments": {
    "staging":    { "site": "https://staging.esempio.it", "user": "mioutente", "passwordEnv": "WPDEV_STAGING_PASSWORD" },
    "production": { "site": "https://www.esempio.it", "user": "mioutente", "passwordEnv": "WPDEV_PROD_PASSWORD", "autoDeploy": false }
  },
  "defaultEnv": "staging"
}
```

Claude e l'hook lavorano sull'ambiente predefinito (staging). La produzione, con `"autoDeploy": false`, non
riceve mai deploy automatici: si pubblica da terminale con `wpdev --env production deploy`, che chiede conferma.
Ogni ambiente ha il suo stato in `.wpdev/env/<nome>/`; il primo deploy verso un ambiente mai scaricato confronta
i file locali con quelli del server e pubblica solo le differenze. Qualsiasi comando accetta `--env` (anche
`status`, `pull`, `info`); in alternativa la variabile `WPDEV_ENV`.

Se `wpdev.json` esiste già, `wpdev init` lo riusa e ricrea solo il resto (utile dopo un `git clone`);
`--force` lo ricrea da zero. Quando cambiano le cartelle scrivibili o altro sul sito, `wpdev claude-md`
aggiorna la sezione di `CLAUDE.md` senza toccare quello che hai scritto fuori dai marcatori.

Per un sito locale in `http://` aggiungi `--insecure-local` (accettato solo per `localhost`,
`*.local`, `*.test`); viene propagato anche all'hook e al server MCP.

## 4. Lavorare con Claude Code

Apri Claude Code nella cartella del progetto. Claude:

- modifica i file delle cartelle scrivibili **in locale** con i propri strumenti;
- legge tutto il resto con gli strumenti MCP `site_status`, `site_list`, `site_read`, `site_grep`,
  `site_log` (le letture passano da una cache validata con la finestra `cache.trustWindowSec`);
- capisce il sito con `site_info`: versioni, tema e plugin attivi, tipi di contenuto, tassonomie,
  shortcode, callback di un hook con file e riga, rotte REST, cron, blocchi (anche da terminale:
  `wpdev info overview`, `wpdev info hook init`);
- a fine turno l'hook esegue `wpdev deploy --hook`: lint PHP, controllo conflitti, upload, health
  check. Se il deploy fallisce o viene annullato, Claude riceve gli errori e li corregge; per evitare
  cicli, un secondo fallimento consecutivo non blocca più il turno.

Comandi utili:

| Comando | Descrizione |
|---|---|
| `wpdev status` | modalità, scadenza, versioni, coerenza delle cartelle, stato del rescue |
| `wpdev pull [--path <cartella>] [--force]` | scarica le cartelle scrivibili (incrementale); con `--path` una cartella in sola lettura in `.wpdev/readonly/` |
| `wpdev diff` | modificati in locale, modificati sul server, in conflitto |
| `wpdev deploy [--dry-run] [--force]` | pubblica le modifiche locali |
| `wpdev rollback [<id>] [--force]` | annulla l'ultima release (o quella indicata e le successive) |
| `wpdev rollback --rescue` | rollback fuori banda quando WordPress non risponde (token dell'ultimo deploy, monouso, 24 h) |
| `wpdev health` | health check su richiesta |
| `wpdev log [-n 200]` | ultime righe di `debug.log` |
| `wpdev restore <percorsi...>` | riporta file o cartelle locali alla versione del server, scartando le modifiche locali (niente viene inviato al sito) |
| `wpdev claude-md [--force]` | aggiorna la sezione wpdev di `CLAUDE.md` con i dati attuali del sito |
| `wpdev info <argomento> [nome]` | informazioni sul sito: `overview`, `post_types`, `taxonomies`, `shortcodes`, `hook <nome>`, `rest_routes [prefisso]`, `cron`, `blocks [prefisso]` |
| `wpdev cache clear` | svuota la cache di lettura |

Exit code: `0` ok, `1` errore, `2` deploy fallito o annullato con rollback.

### Pagine da verificare (health check dell'agente)

Oltre agli URL configurati dall'amministratore, `wpdev.json` ha una sezione che Claude può aggiornare
quando tocca pagine specifiche:

```json
"health": { "paths": ["/shop/", "/contatti/"] }
```

I percorsi (max 10, solo del sito) vengono inviati a ogni deploy e controllati **in aggiunta** a quelli
dell'amministratore: un 500 o un nuovo errore fatale attiva il rollback automatico. Non vengono mai salvati
sul server, quindi l'agente non può ridurre i controlli configurati in WordPress. `wpdev health` e lo
strumento MCP `health` li usano anche per una verifica immediata.

### Cosa succede durante un deploy

1. Il companion calcola le modifiche rispetto all'ultima sincronizzazione (nessuna modifica → nessuna chiamata).
2. `php -l` sui file PHP modificati: un errore di sintassi blocca tutto prima dell'upload.
3. Il server valida **tutto prima di scrivere**: ogni percorso passa da PathGuard, lo zip deve
   corrispondere esattamente al manifest, gli hash devono coincidere, nessun conflitto con modifiche
   fatte sul server nel frattempo (altrimenti 409: `wpdev pull` oppure `--force`).
4. Backup dei file coinvolti, scrittura atomica, token di rescue.
5. Health check (home e URL configurati + nuovi errori fatali in `debug.log`): se fallisce, i file
   vengono ripristinati subito e il comando esce con `2`.

---

## 5. Reti multisite

In una rete multisite Dev Bridge è **un'unica istanza per tutta la rete**, perché temi e plugin sono
condivisi da tutti i siti:

- si attiva solo da *Amministrazione rete → Plugin → Attiva sulla rete* (l'attivazione su un singolo
  sito è rifiutata);
- la pagina è in *Amministrazione rete → Impostazioni → Dev Bridge* e la usano solo i **super admin**:
  gli amministratori dei singoli siti non la vedono e l'API risponde loro 403;
- impostazioni, modalità, release, rescue e audit log sono comuni a tutta la rete (l'audit indica su
  quale sito è arrivata ogni richiesta);
- in `wpdev.json` `site` può essere l'URL di qualsiasi sito della rete: i percorsi di `health.paths` sono
  relativi a quel sito, e si possono aggiungere URL completi di altri siti della rete, così un deploy fatto
  dal "negozio" controlla anche il "blog" che usa lo stesso plugin:

  ```json
  "health": { "paths": ["/", "/carrello/", "https://www.esempio.it/blog/"] }
  ```

- `wpdev status` mostra la rete e `wpdev init` aggiunge a `CLAUDE.md` una sezione dedicata.

## 6. Sicurezza in breve

- Modalità off di default e sempre a scadenza; utenti e IP in allowlist; rate limit; audit log di
  ogni richiesta (senza contenuti dei file).
- Solo Application Password su HTTPS; l'autenticazione via cookie è rifiutata.
- PathGuard: nessun `..`, percorsi assoluti, wrapper, byte nulli; `realpath` + prefisso della root;
  symlink verso l'esterno rifiutati; deny list applicata dopo la risoluzione (anche a grep, archive, manifest).
- Scrittura solo nelle cartelle abilitate, con allowlist di estensioni; `.htaccess`, `.user.ini`,
  `.phtml`, `.phar`, `.php3-8`, doppie estensioni tipo `x.php.jpg` sempre vietati.
- Il plugin non può scrivere su sé stesso né sullo storage; nessuna esecuzione di comandi di sistema
  (unica eccezione: ripgrep facoltativo, avviato senza shell).
- Il companion non manda mai contenuti di file negli argomenti MCP: il deploy legge dal disco.

## 7. Accelerazione ripgrep (facoltativa)

Con molti file la ricerca PHP può essere lenta. Se sul server è disponibile
[ripgrep](https://github.com/BurntSushi/ripgrep), indica `rg` (o il percorso assoluto di `rg`/`rg.exe`)
in *Impostazioni → Accelerazione ripgrep*. I risultati passano comunque da PathGuard e dalla deny list;
le regex richiedono ripgrep con PCRE2, altrimenti si usa la ricerca PHP. `site_grep` mostra il motore usato.

## 8. Risoluzione dei problemi

| Sintomo | Soluzione |
|---|---|
| `401 app_password_required` con credenziali corrette | Con PHP in FastCGI/CGI l'header `Authorization` non arriva a PHP: aggiungi in `.htaccess` `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` |
| `403 mode_off` / `mode_insufficient` | Attiva la modalità dal pannello o con `wp devbridge enable` |
| `403 forbidden_user` | Aggiungi l'utente in *Utenti autorizzati* |
| `413 too_large` sul deploy | Limiti PHP `upload_max_filesize` / `post_max_size` (il valore effettivo è in `wpdev status`) |
| `health_unknown` | Il sito non riesce a chiamare sé stesso (loopback bloccato): nessun rollback automatico, verifica a mano |
| Tutti i file risultano modificati | `core.autocrlf=true` senza `.gitattributes` `* -text`: rigenera con `wpdev init` e riesegui il checkout |
| Rescue "non disponibile" | Nessun deploy recente da questo progetto, token scaduto (24 h) o già usato: ripristina via FTP/SSH da `storage/releases/<id>/files/` |
| Ricerche lente in locale con WAMP/XAMPP | Xdebug attivo nel PHP del web server rallenta molto `/grep` e `/manifest` |

## 9. Sviluppo

```bash
cd plugin    && composer install && composer test && composer lint
cd plugin    && npm install && npm run lint && npm run build   # interfaccia admin (React)
cd companion && npm install && npm test && npm run lint && npm run build
```

L'interfaccia di amministrazione è in `plugin/admin/src/` (React + TypeScript con i componenti di
WordPress, `@wordpress/components`); `npm run build` la compila in `plugin/build/` (non versionata, inclusa
nello zip). `npm run start` la ricompila a ogni modifica. Parla con il PHP tramite `admin-ajax`
(`Admin\AdminController`), **mai** tramite la REST API: le Application Password usate dal companion
valgono per la REST API, e nessuna API raggiungibile con quelle credenziali può cambiare modalità o
impostazioni.

Traduzioni: stringhe in inglese nel codice, italiano in `plugin/tools/translations/it_IT.json`.
`npm run i18n` (richiede WP-CLI) rigenera `languages/` (`.pot`, `.po`, `.mo` e il JSON per il JavaScript).

**CI** (GitHub Actions, `.github/workflows/ci.yml`): a ogni push e pull request girano i test PHP su 8.1–8.4 con
PHPCS, lint e build dell'interfaccia admin, e i test del companion su Linux e Windows con Node 20 e 22.

**Rilasciare una versione:** aggiorna la versione in `plugin/lab591-dev-bridge.php` (intestazione e costante
`VERSION`), `plugin/package.json`, `companion/package.json` e `companion/src/version.ts`, poi crea e pubblica il tag
(`git tag v0.5.0 && git push origin v0.5.0`). `.github/workflows/release.yml` controlla che le versioni coincidano
con il tag, rifà i test e allega alla release lo zip del plugin (con l'interfaccia compilata) e il pacchetto del
companion.

I test PHPUnit del plugin girano senza WordPress. I test sui symlink vengono saltati dove il sistema
non permette di crearli (Windows senza modalità sviluppatore); quelli di ripgrep girano se
`DEVBRIDGE_TEST_RG` indica un eseguibile `rg`.

## Licenza

Copyright (C) 2026 Lab591

Dev Bridge (plugin e companion `wpdev`) è software libero, distribuito con licenza
**GNU General Public License v2.0 o successiva** (GPL-2.0-or-later), la stessa di WordPress.
Il testo completo è nel file [`LICENSE`](LICENSE).
