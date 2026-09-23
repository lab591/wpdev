# Dev Bridge — Specifica tecnica

Sistema per far lavorare **Claude Code in locale** su un sito WordPress remoto:
legge il codice del sito via MCP, modifica in locale solo le cartelle abilitate,
e al termine di ogni turno pubblica le modifiche con health check e rollback automatico.

Priorità di progetto, in quest'ordine: **sicurezza**, **efficienza**, semplicità.

---

## 1. Architettura

```
┌──────────────── PC (Windows) ────────────────┐        ┌────────────── Server WordPress ──────────────┐
│ Claude Code                                  │        │ Plugin "Dev Bridge" (REST devbridge/v1)       │
│   ├─ tool nativi → cartelle scrivibili locali│        │   ├─ PathGuard (jail + deny list)             │
│   ├─ MCP "wpdev" (stdio) ────────────────────┼─HTTPS─▶│   ├─ read / list / grep / manifest / archive  │
│   └─ hook Stop → `wpdev deploy --hook` ──────┼─HTTPS─▶│   ├─ deploy → backup → health → rollback      │
│ Companion `wpdev` (Node 20+, TypeScript)     │        │   └─ audit log                                │
│   ├─ CLI                                     │        │ mu-plugin "rescue" (rollback fuori banda)     │
│   ├─ server MCP                              │        │ Storage privato (backup, lock, token)         │
│   └─ .wpdev/ (stato, cache)                  │        └──────────────────────────────────────────────┘
└──────────────────────────────────────────────┘
```

Principi:

1. **Il contenuto dei file non passa mai negli argomenti di un tool MCP.** Il deploy legge i file dal disco locale; Claude chiama `deploy()` senza contenuti.
2. **In locale esistono solo le cartelle scrivibili**, con la stessa struttura di percorsi del server (`wp-content/themes/x/...`). Tutto il resto si legge via MCP.
3. **Il companion non può mai abilitare da solo la scrittura.** L'abilitazione avviene solo da admin WordPress o WP-CLI sul server.
4. **Ogni percorso passa da PathGuard**, senza eccezioni.

Repo: monorepo con `plugin/` (PHP) e `companion/` (TypeScript).
Distribuzione privata (non WordPress.org).

---

## 2. Plugin WordPress

### 2.1 Requisiti

- PHP ≥ 8.1 (`declare(strict_types=1)`, `hash('xxh128')` disponibile), WordPress ≥ 6.6 (l'interfaccia admin usa React di WordPress).
- Nessuna dipendenza Composer a runtime. Estensioni: `zip` (ZipArchive) obbligatoria; `opcache` opzionale.
- Slug `lab591-dev-bridge`, namespace PHP `Lab591\DevBridge`, namespace REST `devbridge/v1`.
- Nessuna chiamata a `exec`, `shell_exec`, `system`, `passthru`. Unica eccezione opzionale: `proc_open` in forma array (senza shell) per `rg`, disattivata di default (M3).

### 2.2 Modalità sviluppo (gate principale)

| Stato | Effetto |
|---|---|
| `off` (default) | Tutti gli endpoint rispondono 403, tranne `GET /status` che restituisce solo `{mode:"off"}` |
| `read` | Endpoint di lettura attivi |
| `write` | Lettura + deploy/rollback |

- Ogni attivazione ha una **scadenza obbligatoria**: `read` fino a 72 h, `write` fino a 8 h (massimi configurabili al ribasso). Allo scadere si torna a `off`.
- Attivazione solo da: pagina admin (capability `manage_options`, nonce) oppure `wp devbridge enable --mode=write --hours=4`. **Nessun endpoint REST può cambiare la modalità.**
- `wp devbridge disable` e pulsante "Disattiva ora" in admin.

### 2.3 Autenticazione e autorizzazione

- Application Password WordPress (Basic auth su HTTPS). Rifiutare richieste non HTTPS salvo `wp_get_environment_type() === 'local'`.
- L'utente deve avere `manage_options` **e** essere nella lista `allowed_user_ids` delle impostazioni (vuota di default, quindi niente accesso finché non la configuri).
- Allowlist IP opzionale (IPv4/IPv6, CIDR). L'IP si legge da `REMOTE_ADDR`; header tipo `X-Forwarded-For` usati solo se l'IP del proxy è in una lista `trusted_proxies`.
- Rate limit per utente (transient, finestra 60 s): 120 richieste di lettura, 10 deploy/rollback. Superato → 429 con `retry_after`.
- Nessun cookie/nonce: gli endpoint accettano solo Application Password (rifiutare autenticazione via cookie per evitare CSRF).

### 2.4 Impostazioni (pagina admin "Dev Bridge")

- `writable_roots`: elenco di cartelle relative ad `ABSPATH`. Vincoli, verificati al salvataggio e a ogni richiesta:
  - devono stare sotto `wp-content/themes/` o `wp-content/plugins/` (o `wp-content/mu-plugins/` solo se abilitato esplicitamente);
  - non possono essere né contenere il plugin Dev Bridge né la cartella di storage;
  - non possono essere la radice di `themes/` o `plugins/`.

  Nella pagina admin (0.4.0: interfaccia React con `@wordpress/components`, dati via admin-ajax con
  cookie + nonce + capability, mai via REST; inglese con traduzione italiana) si scelgono con caselle di spunta: l'elenco mostra le cartelle di `themes/`,
  `plugins/` (e `mu-plugins/` se abilitato) con il nome del tema/plugin; le sottocartelle si caricano
  espandendo (admin-ajax di sola lettura, capability + nonce, ogni cartella validata con gli stessi
  controlli del salvataggio). Le cartelle non ammesse (Dev Bridge, deny list, symlink verso fuori) sono
  visibili ma non selezionabili; una sottocartella di una cartella già selezionata viene scartata.

  **Nuova cartella** (0.3.0): dalla stessa pagina l'amministratore può creare una cartella vuota (anche
  annidata, max 4 livelli) sotto un contenitore ammesso, per esempio per un nuovo plugin o tema figlio; viene
  aggiunta alle `writable_roots`. Solo da admin (mai via REST, invariante 2): il genitore esistente è risolto
  da PathGuard, i nuovi segmenti sono validati (`[A-Za-z0-9][A-Za-z0-9._-]*`), la cartella creata deve passare
  la stessa validazione delle `writable_roots`, altrimenti viene rimossa. `wpdev pull` crea in locale le
  cartelle scrivibili ancora vuote.
- `read_roots`: default `ABSPATH`.
- `deny_patterns` (glob, applicati dopo la risoluzione del percorso), default:
  `wp-config.php`, `wp-config-*.php`, `**/.env*`, `**/.git/**`, `**/*.sql`, `**/*.sql.gz`, `**/*.log`, `**/*.key`, `**/*.pem`, `wp-content/uploads/**`, `wp-content/devbridge-*/**`, `**/.htpasswd`.
  (`debug.log` si legge solo tramite `/log`.)
- `write_extensions` (allowlist): `php, js, mjs, css, scss, json, html, twig, svg, png, jpg, jpeg, webp, gif, woff, woff2, ttf, txt, md, pot, po, mo, xml`.
  Sempre vietati in scrittura: `.htaccess`, `.user.ini`, `php.ini`, `.phar`, `.phtml`, `.php3-8`, `.pht`, file senza estensione, nomi che iniziano con `.` (eccetto `.gitkeep`).
- Limiti (default): lettura 512 KB per chiamata; grep 200 risultati, 5 s; deploy 20 MB zip, 500 file, 5 MB per file.
- `health_urls`: URL da controllare dopo il deploy (default: home). Solo URL dello stesso host.
- `retention_releases`: backup conservati (default 10). `audit_retention_days` (default 90).

### 2.5 PathGuard (componente critico)

Unico punto di ingresso per qualsiasi percorso. Firma indicativa:
`resolve(string $relPath, Access $access /* READ|WRITE|WRITE_NEW */): ResolvedPath` — lancia eccezione in caso di rifiuto.

Algoritmo:

1. Rifiuta: stringa vuota, byte nulli, caratteri di controllo, percorsi assoluti (`/`, `\`, `C:`), wrapper (`://`), lunghezza > 1024.
2. Normalizza i separatori in `/` e rifiuta ogni segmento `..` o `.` (nessuna normalizzazione "tollerante").
3. Unisci ad `ABSPATH` e risolvi con `realpath()`. Per file nuovi (`WRITE_NEW`): `realpath()` della cartella genitore esistente più vicina, poi riaggiungi i segmenti mancanti (validati come al punto 1–2).
4. Il percorso risolto deve iniziare con `realpath(root) . DIRECTORY_SEPARATOR` di una root ammessa per quel tipo di accesso (`read_roots` o `writable_roots`). Confronto case-insensitive se il filesystem lo è.
5. Link simbolici: il controllo del punto 4 avviene sul percorso risolto, quindi un symlink che punta fuori viene rifiutato. In scrittura, rifiutare se il file di destinazione è un symlink.
6. Applica `deny_patterns` sul percorso relativo risolto; in scrittura applica anche le regole sulle estensioni.
7. Restituisce percorso assoluto risolto e percorso relativo canonico (con `/`).

Test obbligatori (PHPUnit, M1): traversal con `..` codificato e non, `....//`, backslash, percorsi assoluti Windows e Unix, byte nulli, symlink verso fuori, symlink verso la deny list, case-variant di `wp-config.php`, creazione di file in cartelle inesistenti, estensioni doppie (`x.php.jpg`, `x.jpg.php`), nomi con spazi e Unicode.

### 2.6 Endpoint REST

Tutte le risposte sono JSON compatto. Errori: `{ "error": { "code": "path_denied", "message": "..." } }` con status HTTP coerente (400/401/403/404/409/413/422/429/500). Nessun dettaglio di percorsi assoluti del server negli errori.

| Metodo | Endpoint | Modalità | Descrizione |
|---|---|---|---|
| GET | `/status` | tutte | versione plugin, WP, PHP, tema attivo, modalità e scadenza, `writable_roots`, limiti. Con `off` solo `{mode:"off"}` |
| POST | `/list` | read | `{path, depth=1 (max 3), max_entries=500}` → voci `{p, t:"f"\|"d", s, m}` |
| POST | `/read` | read | vedi 2.6.1 |
| POST | `/grep` | read | vedi 2.6.2 |
| POST | `/manifest` | read | `{root}` → `[{p, s, m, h}]` per tutti i file di una root (scrivibile o in `read_roots`) |
| POST | `/archive` | read | `{paths:[...]}` → zip in streaming dei file indicati (max 500) o di una root; applica deny list |
| GET | `/log` | read | `?lines=200&since=<ts>` → ultime righe di `debug.log` (max 1000), solo se `WP_DEBUG_LOG` è attivo |
| POST | `/deploy` | write | vedi 2.7 |
| POST | `/rollback` | write | `{release_id?}` (default: ultima) |
| GET | `/releases` | write | elenco release con data, numero file, esito |
| POST | `/health` | read | esegue l'health check su richiesta |
| POST | `/cache-flush` | write | `{targets:["opcache","object","elementor"]}` |

Abbreviazioni nei payload: `p` percorso, `s` dimensione, `m` mtime (unix), `h` hash xxh128 esadecimale.

#### 2.6.1 `/read`

Richiesta: `{path, from?, to?, known?: {s, m, h}}`

- Se `known` è presente e `s`+`m` coincidono → `{status:"unchanged"}`.
- Se `s` o `m` differiscono, calcola `h`: se uguale → `{status:"unchanged", s, m}`; altrimenti restituisce il contenuto.
- Risposta con contenuto: `{status:"ok", s, m, h, total_lines, from, to, content, truncated}`.
- Rifiuta file binari (byte nulli nei primi 8 KB) → `422 binary_file`. Oltre il limite di dimensione restituisce il range richiesto o le prime N righe con `truncated:true`.

#### 2.6.2 `/grep`

Richiesta: `{pattern, regex=false, case_sensitive=false, path, glob?, max_results=100 (max 200), context=1 (max 3), time_budget_ms=5000}`

- Implementazione PHP con `RecursiveDirectoryIterator`: salta file > 1 MB, binari, deny list, `node_modules`, `vendor` (configurabile), `.git`.
- `regex=true`: pattern PCRE validato; `pcre.backtrack_limit` ridotto durante la ricerca; errori di regex → 422.
- Si ferma al raggiungimento di `max_results` o del budget di tempo.
- Risposta: `{matches:[{p, l, text, before:[], after:[]}], files_scanned, truncated, reason?: "max_results"|"time_budget"}`. Righe troncate a 300 caratteri.

### 2.7 Deploy

Richiesta `multipart/form-data`:
- `manifest` (JSON): `{files:[{p, action:"write"|"delete", h?, base_h?}], force:false}`
  - `h`: hash del nuovo contenuto (per `write`);
  - `base_h`: hash del file sul server all'ultima sincronizzazione nota al client (assente se file nuovo).
- `bundle`: zip con i file `write`, con percorsi relativi ad `ABSPATH`.

Sequenza (tutto nella stessa richiesta):

1. Modalità `write` attiva, lock esclusivo (`flock` su file nello storage; se occupato → 409).
2. Limiti: dimensione zip, numero file, dimensione singoli file.
3. **Validazione completa prima di scrivere qualsiasi cosa**: ogni percorso passa da PathGuard (`WRITE`/`WRITE_NEW`); ogni voce dello zip deve corrispondere a una voce del manifest e viceversa; hash del contenuto estratto = `h`. Voci dello zip con percorsi assoluti, `..` o symlink → rifiuto dell'intero deploy.
4. **Controllo conflitti**: se il file esiste sul server e il suo hash ≠ `base_h` (qualcuno l'ha modificato sul server), rifiuta con 409 e lista dei conflitti, salvo `force:true`.
5. Backup: copia dei file esistenti coinvolti nella cartella della release (`storage/releases/<id>/`), più `release.json` con l'elenco delle operazioni e degli hash.
6. Scrittura atomica per file: scrittura su file temporaneo nella stessa cartella, poi `rename()`. Permessi: file 0644, cartelle 0755 (rispettando umask se più restrittivo). `opcache_invalidate($file, true)` per ogni `.php`.
7. Genera il **token di rescue** (sezione 2.9).
8. **Health check** (2.8). Se fallisce → ripristino automatico dal backup, release marcata `rolled_back`.
9. Risposta: `{release_id, status:"ok"|"rolled_back"|"health_unknown", written, deleted, health:{...}, errors?:[righe fatal dal log], rescue_token}`.

Le cancellazioni sono ammesse solo dentro `writable_roots`; le cartelle rimaste vuote vengono rimosse.

### 2.8 Health check

- Per ogni URL in `health_urls`: `wp_remote_get` con `timeout 10`, `sslverify` attivo, cache-buster in query.
- Fallimento se: status ≥ 500, oppure nuove righe `PHP Fatal error` / `PHP Parse error` in `debug.log` scritte dopo l'inizio del deploy (se il log è attivo).
- Se il loopback non è raggiungibile (errore di rete, bloccato dall'hosting) → `health_unknown`: **nessun rollback automatico**, ma avviso esplicito nella risposta.

### 2.9 Rete di sicurezza fuori banda (mu-plugin rescue)

Problema: un errore fatale in un plugin o tema può rompere tutte le richieste, comprese quelle REST verso Dev Bridge, rendendo impossibile il rollback normale.

Soluzione: un mu-plugin minimale (`devbridge-rescue.php`), caricato prima di plugin e temi.

Ciclo di vita:
- Il file è distribuito dentro il plugin (`plugin/mu-plugin/devbridge-rescue.php`) e viene **copiato**, mai generato da stringhe, in `wp-content/mu-plugins/` (cartella creata se manca), con permessi 0644. Dopo la copia si verifica l'hash.
- **Installazione all'attivazione e autoriparazione:** l'hook di attivazione non scatta sugli aggiornamenti, quindi a ogni `admin_init` (con controllo leggero, cache in opzione) il plugin verifica che il file installato esista e abbia l'hash della versione corrente; altrimenti lo ricopia.
- **Rimozione alla disattivazione e alla disinstallazione:** disattivare il plugin deve spegnere anche il rescue. Si cancella solo se l'header del file identifica il nostro mu-plugin (non si tocca un file omonimo di terzi).
- Scrittura solo con accesso diretto al filesystem: se `mu-plugins` non è scrivibile o `FS_METHOD` non è `direct`, nessun tentativo via FTP; avviso in admin con istruzioni per la copia manuale, e `/status` riporta `rescue: "missing"` (il companion lo segnala a ogni `status` e prima del primo deploy).
- **Inerte se orfano:** se la cartella del plugin non esiste più (es. cancellata via FTP senza disinstallare) o `rescue.json` manca o è scaduto, il mu-plugin esce subito senza fare nulla.

- Si attiva **solo** se la richiesta contiene l'header `X-DevBridge-Rescue`. In tutti gli altri casi esce subito (costo trascurabile).
- A ogni deploy il plugin genera un token casuale di 32 byte, restituito al client, e salva nello storage `rescue.json`: `sha256(token)`, `release_id`, scadenza (24 h), allowlist IP.
- Il mu-plugin, in PHP puro senza funzioni WordPress: verifica scadenza e IP, confronta `hash_equals(sha256(header), stored)`, ripristina i file della release dal backup, invalida il token, risponde JSON ed esegue `exit`.
- Il token è monouso e valido solo per l'ultima release.

### 2.10 Storage privato

- Percorso da costante `DEVBRIDGE_STORAGE_DIR` in `wp-config.php`, **consigliato fuori dalla document root**.
- Fallback: `wp-content/devbridge-<suffisso casuale>/` con `.htaccess` (`Require all denied`), `index.php` vuoto e avviso in admin: su Nginx serve una regola `deny` esplicita (snippet mostrato in admin).
- Contiene: `releases/`, `rescue.json`, `deploy.lock`. Rotazione delle release oltre `retention_releases`.

### 2.11 Audit log

Tabella `{prefix}devbridge_audit`: `id, ts, user_id, ip, endpoint, mode, paths (JSON, troncato), bytes, status, duration_ms, release_id`.
Pagina admin con filtri. Pulizia via cron oltre `audit_retention_days`. I contenuti dei file non vengono mai registrati.

### 2.12 WP-CLI

`wp devbridge status | enable --mode=read|write --hours=N | disable | releases | rollback [<id>]`

### 2.13 Disinstallazione

`uninstall.php`: rimuove opzioni, tabella audit, mu-plugin rescue (se ancora presente), storage (chiedendo conferma nella pagina admin prima della disattivazione se ci sono release).

### 2.14 Multisite (M4)

In una rete multisite temi, plugin e file sono condivisi da tutti i siti: Dev Bridge è quindi
**un'unica istanza a livello di rete**, mai per singolo sito.

- **Attivazione solo di rete.** L'attivazione su un singolo sito della rete viene rifiutata con un
  messaggio chiaro (anche da WP-CLI senza `--network`). Se il plugin risulta attivo solo su un sito
  (es. attivato prima della conversione a multisite) non registra endpoint né pagine e mostra un avviso.
- **Chi può usarlo.** Solo i **super admin**: capability `manage_network_options` al posto di
  `manage_options`, sia per la pagina di amministrazione sia per l'API (oltre alla lista
  `allowed_user_ids`, che in rete propone solo i super admin). Gli amministratori dei singoli siti
  non hanno accesso, perché una modifica a temi o plugin vale per tutta la rete.
- **Pagina di amministrazione** in *Amministrazione rete → Impostazioni → Dev Bridge* (stesse schede
  Stato, Impostazioni, Audit log). Nessuna pagina nei singoli siti.
- **Dati condivisi dalla rete:** impostazioni, modalità sviluppo, token di rescue, release, lock,
  cache degli hash e rate limit stanno in opzioni/transient di rete (`*_site_option`,
  `*_site_transient`) e nello storage unico; la tabella di audit usa il prefisso base
  (`{base_prefix}devbridge_audit`) con la colonna `blog_id` del sito su cui è arrivata la richiesta.
  Il cron di pulizia gira solo sul sito principale.
- **API su qualsiasi sito della rete.** Gli endpoint rispondono sull'URL REST di ogni sito (il
  companion usa l'URL indicato in `wpdev.json`); stato e controlli sono gli stessi. `/status`
  riporta `network: {main_site, sites}` e l'URL del sito che ha risposto.
- **Health check sulla rete.** Gli URL configurati dall'amministratore possono puntare a qualsiasi
  sito della rete (host tra i domini dei siti); default: home del sito principale. I percorsi
  dichiarati dall'agente (`health.paths`) sono relativi al sito indicato in `wpdev.json` oppure, solo in
  rete, URL completi `http(s)://` il cui host e il cui percorso iniziale corrispondono a un sito della
  rete (mai host esterni, niente credenziali o frammenti).
- **Deny list:** aggiunto `wp-content/blogs.dir/**` (upload dei multisite storici).
- Il mu-plugin di rescue è già globale e funziona su qualsiasi sito della rete.

---

## 3. Companion `wpdev`

### 3.1 Requisiti

- Node ≥ 20, TypeScript strict, ESM. Un solo pacchetto con CLI (`wpdev`) e server MCP (`wpdev mcp`, trasporto stdio).
- Dipendenze minime: SDK MCP ufficiale TypeScript, un parser di argomenti, una libreria zip, `xxhash-wasm` (o equivalente) per xxh128. Niente dipendenze native da compilare (deve funzionare su Windows senza toolchain).
- Deve funzionare identico su Windows (PowerShell/cmd), Linux e macOS, senza presupporre uno stack locale specifico. Nel protocollo i percorsi sono sempre relativi con `/`.

### 3.2 Configurazione

`wpdev.json` nella root del progetto locale (versionato):

```json
{
  "site": "https://example.com",
  "user": "claudio",
  "passwordEnv": "WPDEV_APP_PASSWORD",
  "exclude": ["**/node_modules/**", "**/.git/**", "**/*.map"],
  "php": "php",
  "cache": { "enabled": true, "trustWindowSec": 60 },
  "deploy": { "allowDelete": true, "lintPhp": true }
}
```

- La password **non sta mai** in `wpdev.json`: variabile d'ambiente, oppure `.env.local` (escluso da git) caricato dal companion.
- Le cartelle scrivibili le decide **solo il sito** (`writable_roots`, impostate dall'amministratore): il companion
  le legge da `/status` e salva l'ultimo elenco ricevuto in `.wpdev/writable-roots.json` (usato quando il sito
  non è raggiungibile o la modalità è off, e dal deploy, che senza modifiche non fa chiamate di rete).
  L'elenco ricevuto viene validato come ogni percorso esterno (solo cartelle sotto `themes/`, `plugins/`,
  `mu-plugins/`, niente `..` o percorsi assoluti).
- `writable` (facoltativo, 0.3.0) serve solo a **restringere** il progetto ad alcune di quelle cartelle,
  es. `"writable": ["wp-content/themes/mio-child"]`. Vuoto o assente = tutte quelle del sito. Una cartella
  elencata ma non abilitata sul sito viene segnalata (il server rifiuterebbe il deploy).
- `php` è opzionale: default `php` cercato nel PATH; si può indicare un percorso assoluto (qualsiasi installazione: Homebrew, apt, XAMPP/WAMP/MAMP, Laragon, LocalWP, container). Se PHP non è disponibile, il lint viene saltato con un avviso, senza bloccare il deploy.
- HTTPS obbligatorio; `http://` accettato solo con `--insecure-local` e host `localhost`/`*.local`/`*.test`. Verifica TLS sempre attiva.

### 3.3 Stato locale

Cartella `.wpdev/` (in `.gitignore`):
- `state.json`: per ogni file sincronizzato `{p, h_base, s, m}`, cioè l'hash del file sul server all'ultimo pull/deploy riuscito. È la base del rilevamento modifiche e conflitti.
- `rescue.json`: ultimo token di rescue e `release_id`.
- `writable-roots.json`: ultimo elenco delle cartelle scrivibili ricevuto dal sito (vedi 3.2).
- `cache/` (M3).

### 3.4 Comandi CLI

| Comando | Descrizione |
|---|---|
| `wpdev init` | Crea `wpdev.json` guidato (sito, utente, variabile della password; le cartelle scrivibili vengono dal sito, `--writable` solo per restringere), aggiorna `.gitignore`, crea `.gitattributes` (vedi 3.7), testa `/status` |
| `wpdev status` | Modalità e scadenza sul server, cartelle scrivibili (dal sito o ristrette da `wpdev.json`) |
| `wpdev pull [--path <p>] [--force]` | Scarica le cartelle scrivibili. Incrementale: `/manifest` → confronto con file locali → `/archive` solo dei file diversi. Se un file locale è stato modificato rispetto a `state.json` e anche il server è cambiato, **non sovrascrive**: segnala il conflitto (salvo `--force`). Con `--path` scarica una cartella di sola lettura in `.wpdev/readonly/` (mai deployata) |
| `wpdev diff` | Tre elenchi: modificati in locale, modificati sul server, in conflitto |
| `wpdev deploy [--dry-run] [--force] [--hook]` | Vedi 3.5 |
| `wpdev rollback [<id>] [--rescue]` | Rollback normale; con `--rescue` usa il token e il mu-plugin. Senza argomenti, se il rollback normale riceve 5xx, propone il rescue |
| `wpdev log [-n 200]` | Ultime righe di `debug.log` |
| `wpdev health` | Health check su richiesta |
| `wpdev mcp` | Avvia il server MCP (stdio) |
| `wpdev cache clear` | (M3) |

Exit code: `0` ok, `1` errore, `2` deploy fallito/rollback eseguito (usato dall'hook).

### 3.5 Deploy lato companion

1. Calcola l'insieme delle modifiche confrontando i file locali delle cartelle scrivibili (elenco in cache, vedi 3.2) con `state.json`: nuovi, modificati, cancellati (se `allowDelete`). Nessuna modifica → esce subito con `0`, senza chiamate di rete.
2. Se `lintPhp` è attivo e PHP è disponibile: `php -l` su ogni `.php` modificato. Errore di sintassi → blocca il deploy prima dell'upload, riporta file e riga.
3. Costruisce manifest (`h`, `base_h`) e zip in memoria.
4. `POST /deploy`. Gestisce 409 conflitto (mostra i file), 403 modalità non attiva (messaggio chiaro: "attiva la modalità write dal pannello o con `wp devbridge enable`").
5. Esito `ok` → aggiorna `state.json` e salva il token rescue. Esito `rolled_back` → stampa le righe di errore restituite, exit `2`.
6. Output sintetico (poche righe): release, file scritti/cancellati, esito health.

### 3.6 Server MCP

Strumenti (nomi e schemi stabili, descrizioni brevi e precise):

| Tool | Argomenti | Note |
|---|---|---|
| `site_status` | — | modalità, root, versioni, tema attivo |
| `site_list` | `path, depth?` | |
| `site_read` | `path, from?, to?` | output con numeri di riga; usa la cache (M3) |
| `site_grep` | `pattern, path, glob?, regex?, case_sensitive?, max_results?, context?` | |
| `site_log` | `lines?` | |
| `deploy` | `dry_run?` | **nessun contenuto negli argomenti**: legge dal disco |
| `rollback` | `release_id?` | |
| `health` | — | |
| `cache_flush` | `targets?` | |

Regole di output: testo compatto, mai JSON verboso; risultati troncati con indicazione esplicita di cosa è stato omesso e di come restringere la richiesta. Gli strumenti di lettura rifiutano percorsi dentro `writable` suggerendo di usare i file locali (evita divergenze).

### 3.7 Integrazione con Claude Code (per i progetti sito)

- Hook `Stop` in `.claude/settings.json` del progetto sito: `wpdev deploy --hook`. In modalità `--hook` il comando è non interattivo, silenzioso se non ci sono modifiche, ed esce con `2` se il deploy fallisce o viene annullato con rollback, scrivendo su stderr un riepilogo con gli errori, così Claude li vede e può correggere. Per evitare cicli: se l'input dell'hook indica che è già attivo (`stop_hook_active`) e il deploy fallisce di nuovo, uscire con `0` e un messaggio. (Verificare la semantica attuale degli hook nella documentazione di Claude Code.)
- `.gitattributes` con `* -text`: git conserva i file byte per byte, senza convertire i fine riga. Serve soprattutto su Windows con `core.autocrlf=true`, dove altrimenti ogni checkout trasformerebbe i file in CRLF e tutti risulterebbero modificati rispetto al server. Su macOS e Linux è neutro. In più `wpdev init` e `wpdev diff` avvisano se rilevano `core.autocrlf=true`, e il deploy trasferisce sempre i byte esatti (nessuna normalizzazione dei fine riga lato companion o server).
- Template di `CLAUDE.md` per i progetti sito generato da `wpdev init` (sezione 6).

### 3.8 Cache con validazione (M3)

- `.wpdev/cache/<percorso>` + metadati `{s, m, h, validated_at}`.
- `site_read` su file in cache: se `validated_at` è entro `trustWindowSec` → risponde dalla cache; altrimenti chiama `/read` con `known` e aggiorna.
- Invalidazione per versione: il companion memorizza la versione di ogni plugin/tema (header del file principale, letto al primo accesso); se cambia, scarta tutta la cache di quel plugin.
- La cache è in sola lettura e non viene mai considerata nel deploy.

---

## 4. Modello di sicurezza (riepilogo)

| Minaccia | Mitigazioni |
|---|---|
| Credenziali rubate | Modalità off di default con scadenza; write max 8 h; allowlist utenti e IP; rate limit; audit log; HTTPS |
| Path traversal / symlink | PathGuard unico, `realpath` + prefisso, deny list post-risoluzione, test estensivi |
| Lettura di segreti | Deny list (`wp-config`, `.env`, chiavi, dump, uploads, storage); `debug.log` solo via endpoint dedicato |
| Upload di codice malevolo fuori dal perimetro | Solo `writable_roots`, allowlist estensioni, divieto `.htaccess`/`.user.ini`/estensioni PHP alternative |
| Errore di Claude che rompe il sito | Lint locale, health check, rollback automatico, rescue fuori banda, backup delle release |
| Modifiche concorrenti sul server | Controllo conflitti con `base_h`, lock di deploy |
| Esposizione dello storage | Costante fuori webroot, fallback con nome casuale + deny, avviso Nginx |
| Escalation dal client | Nessun endpoint cambia modalità o impostazioni; il plugin non può scrivere su sé stesso |
| Amministratore di un sottosito (multisite) | Solo super admin, attivazione solo di rete, dati e pagina solo in Amministrazione rete |
| ReDoS / DoS | Limiti su regex, tempo, risultati, dimensioni |

## 5. Efficienza (riepilogo)

- Nessun contenuto di file negli argomenti dei tool; deploy da disco.
- Sincronizzazione differenziale per hash (xxh128); zip per i trasferimenti multi-file.
- Lettura per intervalli di righe; grep lato server con limiti; output compatti e troncati con istruzioni.
- Deploy senza rete se non ci sono modifiche; lint prima dell'upload.
- Cache con validazione condizionale e finestra di fiducia (M3).

---

## 6. Template `CLAUDE.md` per i progetti sito

```markdown
# Sito: <nome> — <url>

## Come lavori su questo sito
- Puoi modificare SOLO i file in: <elenco cartelle scrivibili> (decise dall'amministratore nelle impostazioni
  Dev Bridge del sito; l'elenco aggiornato è in `site_status`). Le modifiche vengono pubblicate
  automaticamente alla fine di ogni turno (hook), con health check e rollback.
- Per leggere qualsiasi altro file del sito (core, plugin, tema padre) usa gli strumenti
  MCP `site_list`, `site_read`, `site_grep`. Preferisci `site_grep` per trovare hook,
  filtri e classi; poi leggi solo le righe che servono con `site_read`.
- Non cercare di scrivere fuori dalle cartelle consentite: il server lo rifiuta.
- Se il deploy fallisce, leggi gli errori riportati, controlla `site_log` e correggi.
- Dopo modifiche visibili, verifica la pagina nel browser (Chrome).
- Contenuti e pagine Elementor si gestiscono con l'MCP del sito (WSP), non via file.

## Convenzioni
- Child theme: <nome>. Plugin custom: <nome>, prefisso funzioni `<prefisso>_`.
- Page builder: Elementor.
```

---

## 7. Milestone

**M1 — Sola lettura (fondamenta)**
- Plugin: bootstrap, impostazioni, modalità con scadenza, WP-CLI, autenticazione, rate limit, PathGuard con test completi, `/status`, `/list`, `/read` (senza `known`), `/grep` (PHP), `/manifest`, `/archive`, `/log`, audit log.
- Companion: `init`, `status`, `pull`, `diff`, `log`; MCP con `site_status`, `site_list`, `site_read`, `site_grep`, `site_log`.
- Criterio di uscita: da Claude Code in locale si esplora un sito reale e si scaricano le cartelle scrivibili; tutti i test PathGuard verdi.

**M2 — Scrittura sicura**
- Plugin: storage, `/deploy` completo, health check, rollback automatico e manuale, `/releases`, mu-plugin rescue, `/cache-flush`.
- Companion: `deploy` (lint, conflitti, `--hook`), `rollback` (anche `--rescue`), `health`; MCP `deploy`, `rollback`, `health`, `cache_flush`; generazione `.gitattributes`, hook e template `CLAUDE.md` in `init`.
- Criterio di uscita: un errore fatale introdotto volutamente viene annullato in automatico; un fatale che sfugge all'health check si recupera con `wpdev rollback --rescue`.

**M3 — Ottimizzazioni**
- Cache con validazione e invalidazione per versione; `/read` con `known`; accelerazione `rg` opzionale lato server; hash in cache lato server (chiave `path|size|mtime`).

**M4 — Multisite** (sezione 2.14)
- Plugin: attivazione solo di rete, super admin, opzioni/transient di rete, pagina in Amministrazione rete, audit con `blog_id`, health check su siti della rete, `blogs.dir` in deny list.
- Companion: `status` mostra la rete; `health.paths` accetta URL completi di siti della rete (validati dal server).
- Criterio di uscita: su una rete locale un amministratore di sottosito non può usare il plugin né vederne la pagina; un super admin esplora e fa deploy dal sito principale e da un sottosito, con health check su più siti e rollback automatico.

## 8. Test

- Plugin: PHPUnit per PathGuard e validazione deploy (unit, senza WordPress); test di integrazione su un'installazione WordPress locale (qualsiasi ambiente: `wp-env`, DDEV, LocalWP, Docker, XAMPP/WAMP/MAMP).
- Companion: Vitest; client HTTP testato contro un server mock; test dei percorsi Windows (`\`, lettere di unità, maiuscole/minuscole).
- Scenario end-to-end manuale per ogni milestone, documentato in `docs/e2e.md`.

## 9. Fuori perimetro

Modifiche al database, installazione/aggiornamento di plugin, gestione multiutente, distribuzione su WordPress.org, interfaccia grafica del companion.
