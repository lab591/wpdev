# Scenari end-to-end

Scenari manuali eseguiti su un'installazione WordPress locale, uno per milestone.

## Ambiente di prova usato

- WAMP su Windows 11: Apache 2.4 + PHP 8.4 (FastCGI), MariaDB 11.8; CLI con PHP 8.3; Node 22.
- Sito `http://localhost/lab591_wpdev_site` (WordPress 7.1.2), `WP_ENVIRONMENT_TYPE=local`,
  `WP_DEBUG_LOG=true`, permalink "nome articolo".
- Tema attivo `dbtest-child` (figlio di Twenty Twenty-Five), plugin custom `dbtest-widgets`.
- Con FastCGI serve che Apache passi l'header `Authorization` a PHP; nel `.htaccess`:
  `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`.
- Nota prestazioni: nel PHP di Apache di WAMP è attivo Xdebug (`xdebug.mode=develop`, log livello 7),
  che rallenta molto `/grep` e `/manifest`. Con Xdebug disattivato (CLI) un grep su `wp-content`
  intero richiede circa 0,3 s.

Preparazione del sito:

```bash
wp plugin activate lab591-dev-bridge
wp option update devbridge_settings '{"allowed_user_ids":[1],"writable_roots":["wp-content/themes/dbtest-child","wp-content/plugins/dbtest-widgets"],"read_roots":[""]}' --format=json
wp user application-password create admin wpdev --porcelain   # -> .env.local del progetto
wp devbridge enable --mode=read --hours=2
```

(In produzione le impostazioni si configurano dalla pagina *Impostazioni → Dev Bridge*.)

## M1 — Sola lettura

| # | Passo | Esito atteso | Esito |
|---|---|---|---|
| 1 | `GET /status` senza credenziali, modalità `off` | `{"mode":"off"}` | ok |
| 2 | `POST /list` senza credenziali, modalità `read` | `401 app_password_required` (prima della validazione dei parametri) | ok |
| 3 | `POST /read {"path":"wp-config.php"}` | `403 path_denied` | ok |
| 4 | `POST /read {"path":"../lab591_wpdev/SPEC.md"}` | `400 path_invalid` | ok |
| 5 | `POST /archive` di 2 file | zip con 2 voci, percorsi relativi ad ABSPATH | ok |
| 6 | `wpdev --insecure-local init --site ... -y` | crea `wpdev.json`, aggiorna `.gitignore`, avvisa per `core.autocrlf=true`, `/status` ok | ok |
| 7 | `wpdev status` | modalità, scadenza, versioni, root coerenti con `wpdev.json` | ok |
| 8 | `wpdev pull` | scarica i 3 file delle cartelle scrivibili | ok |
| 9 | Modifica locale di `style.css` + modifiche sul server a `style.css`, `functions.php` e nuovo `readme.txt`; `wpdev diff` | 2 modificati sul server, 1 in conflitto | ok |
| 10 | `wpdev pull` | scarica i 2 file del server, non sovrascrive `style.css`, exit 1 | ok |
| 11 | `wpdev pull --path wp-content/themes/twentytwentyfive/patterns` | 98 file in `.wpdev/readonly/` | ok |
| 12 | `wpdev pull --path wp-content/themes/dbtest-child` | rifiutato: è una cartella scrivibile | ok |
| 13 | `wpdev log -n 3` | ultime righe di `debug.log` | ok |
| 14 | MCP `site_status`, `site_list`, `site_read` (con numeri di riga), `site_grep` (con contesto) | output compatto, troncamenti espliciti | ok |
| 15 | MCP `site_read` su un file scrivibile | rifiutato con invito a usare i file locali | ok |
| 16 | MCP `site_read wp-config.php` | `path_denied` | ok |

## M2 — Scrittura sicura

Preparazione: `wp devbridge enable --mode=write --hours=4`; progetto locale creato con
`wpdev --insecure-local init --site http://localhost/lab591_wpdev_site --user admin ... -y`.

| # | Passo | Esito atteso | Esito |
|---|---|---|---|
| 1 | Attivazione del plugin | mu-plugin `devbridge-rescue.php` copiato in `wp-content/mu-plugins/` | ok |
| 2 | `wpdev init` | `.gitattributes` (`* -text`), hook Stop in `.claude/settings.json`, `.mcp.json`, `CLAUDE.md` con nome del sito | ok |
| 3 | `wpdev deploy` senza modifiche | "Nessuna modifica", exit 0, nessuna chiamata di rete | ok |
| 4 | Modifica di `functions.php` + nuovo `assets/extra.css`; `wpdev deploy --dry-run` | elenco modifiche e lint, nessun invio | ok |
| 5 | `wpdev deploy` | release creata, 2 file scritti, health check 200, pagina aggiornata | ok |
| 6 | Chiamata a funzione inesistente in `functions.php`; `wpdev deploy` | home 500 → **rollback automatico**, errore fatale riportato (percorso relativo), exit 2, file sul server invariato | ok |
| 7 | Errore di sintassi in un `.php`; `wpdev deploy` | bloccato dal lint prima dell'upload, file e riga, exit 1 | ok |
| 8 | Stesso errore con `deploy --hook` e `stop_hook_active=false` | riepilogo su stderr, exit 2 | ok |
| 9 | Idem con `stop_hook_active=true` | messaggio "mi fermo per evitare un ciclo", exit 0 | ok |
| 10 | `deploy --hook` dopo la correzione / senza modifiche | una riga di esito, exit 0 / silenzioso | ok |
| 11 | Fatale solo nelle richieste REST (`rest_api_init`): `wpdev deploy` | health check ok (la home funziona), deploy `ok` | ok |
| 12 | `wpdev status` / `wpdev rollback` | 500 dal server; il rollback suggerisce `--rescue`, exit 1 | ok |
| 13 | `wpdev rollback --rescue` | mu-plugin ripristina la release, `state.json` aggiornato, REST di nuovo funzionante | ok |
| 14 | Secondo `wpdev rollback --rescue` | token monouso già consumato: rifiutato | ok |
| 15 | MCP `deploy {dry_run}`, `deploy`, `health`, `cache_flush`, `rollback` | output compatto; con REST rotto l'errore invita a chiedere il rescue all'utente | ok |
| 16 | Nuovo deploy dello stesso contenuto | nessuna release, `release_id: null` | ok |
| 17 | Disattivazione del plugin | mu-plugin rimosso | ok |
| 18 | `wp plugin uninstall --skip-delete` | tabella audit, opzioni, storage rimossi | ok |
| 19 | Pagina admin (Stato, Impostazioni, Audit) | nessun errore PHP | ok |

Criterio di uscita M2: un errore fatale introdotto volutamente viene annullato in automatico (passo 6);
un fatale che sfugge all'health check si recupera con `wpdev rollback --rescue` (passi 11–13).

## M3 — Ottimizzazioni

| # | Passo | Esito atteso | Esito |
|---|---|---|---|
| 1 | `POST /read` con `known` uguale al file | `{"status":"unchanged"}` | ok |
| 2 | `POST /read` con `known.h` malformato | `400 invalid_param` | ok |
| 3 | `/grep` letterale su `wp-content/themes` con motore PHP (Xdebug attivo) | `engine: php`, ~4,4 s | ok |
| 4 | Impostazione "Accelerazione ripgrep" = percorso di `rg.exe` (14.1.1, PCRE2) | stesse ricerche con `engine: rg` in 0,2–0,4 s | ok |
| 5 | `/grep` `DB_PASSWORD` su tutto il sito con rg | risultati da `wp-admin`/`wp-includes`, **mai** `wp-config.php` né `wp-config-sample.php` | ok |
| 6 | `/grep` regex con rg (`add_action\(\s*'init'`, `max_results` 5) | 5 risultati, `reason: max_results` | ok |
| 7 | MCP `site_read` due volte sullo stesso file (range diversi) | 2 richieste `/read` totali (versione del tema + file intero), la seconda lettura dalla cache | ok |
| 8 | `wpdev cache clear` | "Cache svuotata: 2 file rimossi" | ok |

Note: al primo avvio ripgrep può impiegare molto più tempo (cache del filesystem fredda e scansione
antivirus dell'eseguibile appena scaricato); le esecuzioni successive sono nell'ordine dei decimi di
secondo. Hash in cache lato server: `storage/hash-cache.json`, chiave `percorso|dimensione|mtime`,
non usata per i controlli di conflitto del deploy (che calcolano sempre l'hash reale).

## Verifica manuale: link e junction su Windows

I test PHPUnit sui symlink vengono saltati su Windows senza modalità sviluppatore. Verifica manuale
con **junction** NTFS (creabili senza privilegi, `New-Item -ItemType Junction`) dentro la cartella
scrivibile `child`: `ext` → cartella fuori dal sito, `parentlink` → tema padre, `cfg` → radice del sito.

| Operazione | Esito |
|---|---|
| read `child/ext/secret.txt`, write-new `child/ext/new.php` | `path_denied` |
| write `child/parentlink/style.css` | `path_denied` (il file reale è fuori dalle cartelle scrivibili) |
| read `child/parentlink/style.css` | consentito, percorso canonico `themes/parent/style.css` |
| read `child/cfg/wp-config.php` | `path_denied` |
| `/list` di `child` (depth 2) | `cfg` e `parentlink` elencati con il loro nome ma non attraversati; `ext` omesso |
| `/manifest` di `child`, `/grep` e `/archive` sulla radice (PHP e ripgrep) | nessuna junction attraversata, nessun file esterno o in deny list |

Problema trovato e corretto durante la verifica: `is_link()` di PHP restituisce `false` per le
junction, quindi il walker le attraversava (ciclo fino al limite di file/tempo con una junction verso
la radice). Ora una cartella è considerata un link quando il suo `realpath` non coincide con
`genitore/nome` (`PathGuard::isLinkLike`).

## Estensione: percorsi di health check dichiarati dall'agente

| # | Passo | Esito atteso | Esito |
|---|---|---|---|
| 1 | `wpdev.json` → `"health": {"paths": ["/sample-page/", "/?p=1"]}`; `wpdev health` | home (admin) + 2 percorsi marcati `[wpdev.json]` | ok |
| 2 | `wpdev deploy` di una modifica CSS | stessi 3 controlli nell'health check del deploy | ok |
| 3 | Percorso `https://evil.test/` in `wpdev.json` | rifiutato già dal companion; il server lo rifiuta comunque (`invalid_manifest`) | ok (test) |

## M4 — Multisite

Rete locale `http://localhost/lab591_wpdev_ms/` (sottocartelle): sito principale, `/negozio/`, `/notizie/`.
Utenti: `superadmin` (super admin) e `sitoadmin` (solo amministratore di `/negozio/`, inserito di proposito
anche in `allowed_user_ids`). Tema `rete-child` (attivo su `/negozio/`) e plugin `rete-tools` attivato
sulla rete, entrambi cartelle scrivibili.

| # | Passo | Esito atteso | Esito |
|---|---|---|---|
| 1 | `wp plugin activate` sul sottosito o sul principale senza `--network` | attivato comunque sulla rete (header `Network: true`); mai per singolo sito | ok |
| 2 | Hook di attivazione chiamato per un singolo sito | bloccato con messaggio | ok |
| 3 | Plugin forzato attivo solo su `/notizie/` (`active_plugins` del sito) | nessun endpoint (`rest_no_route`) | ok |
| 4 | Impostazioni, modalità, rescue | salvate in `wp_sitemeta`; tabella unica `wp_devbridge_audit` | ok |
| 5 | `sitoadmin` con Application Password su `/negozio/wp-json/devbridge/v1/status` e `/read` | `403 forbidden_user` | ok |
| 6 | `superadmin` su principale e `/negozio/` | `/status` con `site_url` del sito e `network: {sites: 3}` | ok |
| 7 | `/health` da `/notizie/` con `/`, URL di `/negozio/` e `http://evil.test/` | host esterno rifiutato; senza di esso 3 controlli (admin = home del principale) | ok |
| 8 | Pagina di amministrazione | visibile al super admin solo in Amministrazione rete; `sitoadmin` non la vede né in rete né nel suo sito | ok |
| 9 | `wpdev init` sul sottosito `/negozio/` | `status` mostra la rete; `CLAUDE.md` con sezione "Rete multisite" | ok |
| 10 | Deploy di una modifica a `rete-tools` con `health.paths` = `/` + URL di `/notizie/` | 3 controlli ok; modifica visibile su tutti i siti | ok |
| 11 | Deploy di un fatale che si verifica **solo** su `/notizie/` | 500 solo sul controllo di `/notizie/` → rollback automatico, riga fatale riportata, tutti i siti a 200 | ok |
| 12 | Fatale solo nelle richieste REST; `wpdev rollback` e poi `--rescue` dal sottosito | rollback normale impossibile (500), rescue ok | ok |
| 13 | Audit | colonna `blog_id` = 2 per le richieste arrivate da `/negozio/` | ok |
| 14 | Disinstallazione di rete | nessuna opzione di rete, tabella, storage o mu-plugin residuo | ok |
| 15 | Regressione su sito singolo (`lab591_wpdev_site`) | deploy e health check invariati; audit aggiornato alla versione 2 | ok |

Problemi trovati e corretti durante la prova:
- se `debug.log` non esisteva ancora prima del deploy, l'health check non riportava la riga del primo
  errore fatale (il rollback avveniva comunque per il 500): ora usa il percorso configurato del log;
- la disinstallazione non rimuoveva `hash-cache.json` (introdotto in M3), quindi la cartella di storage restava.

## 0.3.0 — Cartelle scrivibili decise dal sito, scelta con caselle, nuova cartella

Sito locale `lab591_wpdev_site`, progetto nuovo senza `writable` in `wpdev.json`.

| # | Passo | Esito atteso | Esito |
|---|---|---|---|
| 1 | Zip caricato da *Plugin → Aggiungi → Carica* su WordPress pulito | "Attiva" punta a `lab591-dev-bridge.php` (prima: "intestazione non valida") | ok |
| 2 | Pagina Impostazioni | caselle per temi/plugin con nome; Dev Bridge bloccato con motivo | ok |
| 3 | *sottocartelle* (admin-ajax) | elenco caricato; `uploads`, Dev Bridge, nonce errato, utente non loggato rifiutati | ok |
| 4 | Selezione di `twentytwentyfive/assets` e di `twentytwentyfour` + `twentytwentyfour/parts` | salvate `…/assets` (genitore espanso alla riapertura) e solo `twentytwentyfour` | ok |
| 5 | Nuova cartella `../uploads/x` | rifiutata, nulla creato | ok |
| 6 | Nuova cartella `plugins/e2e-nuovo` | creata vuota e selezionata | ok |
| 7 | `wpdev init` senza `--writable` | nessuna domanda sulle cartelle; `wpdev.json` senza `writable`; cartelle "dal sito" in output e in `CLAUDE.md` | ok |
| 8 | `wpdev status` / `wpdev pull` | elenco dal sito; la cartella vuota viene creata in locale | ok |
| 9 | File del nuovo plugin creato in locale + `wpdev deploy` | plugin visibile in WordPress | ok |
| 10 | `wpdev deploy --hook` senza modifiche | exit 0, usa l'elenco in cache (nessuna chiamata) | ok |
