# Protocollo REST `devbridge/v1` — dettagli di implementazione

Complementa la sezione 2.6 di `SPEC.md` con le scelte concrete condivise da plugin e companion.

## Convenzioni generali

- Base URL: `<site>/wp-json/devbridge/v1/`. Il companion usa sempre questa forma
  (se i permalink sono disattivati, `?rest_route=/devbridge/v1/...`).
- Autenticazione: solo Application Password (`Authorization: Basic base64(user:app_password)`).
  Le richieste autenticate via cookie vengono rifiutate (`401 app_password_required`).
- Corpo delle richieste POST: JSON (`Content-Type: application/json`), tranne `/deploy` (multipart).
- Percorsi: sempre relativi ad `ABSPATH`, separatore `/`, senza `/` iniziale.
  La radice del sito si indica con la stringa `"."` solo per `/list`, `/manifest`, `/grep`.
- Errori: `{ "error": { "code": "<codice>", "message": "<testo>" } }` con status coerente.
  Campi aggiuntivi opzionali dentro `error` (es. `retry_after`, `conflicts`).

## Codici di errore stabili

| Codice | HTTP | Significato |
|---|---|---|
| `mode_off` | 403 | Modalità sviluppo disattivata |
| `mode_insufficient` | 403 | Serve `write` (o `read`) ma la modalità attiva è inferiore |
| `https_required` | 403 | Richiesta non HTTPS su ambiente non `local` |
| `app_password_required` | 401 | Nessuna Application Password nella richiesta |
| `invalid_credentials` | 401 | Credenziali rifiutate da WordPress |
| `forbidden_user` | 403 | Utente senza `manage_options` o non in `allowed_user_ids` |
| `forbidden_ip` | 403 | IP non nella allowlist |
| `rate_limited` | 429 | Limite superato; `error.retry_after` in secondi |
| `invalid_param` | 400 | Parametro mancante o non valido |
| `path_invalid` | 400 | Percorso malformato (assoluto, `..`, byte nulli, ...) |
| `path_denied` | 403 | Percorso fuori dalle root o nella deny list |
| `extension_denied` | 403 | Estensione o nome file non ammesso in scrittura |
| `not_found` | 404 | File o cartella inesistente |
| `not_a_file` / `not_a_directory` | 400 | Tipo di voce non adatto all'operazione |
| `binary_file` | 422 | File binario in `/read` |
| `invalid_regex` | 422 | Pattern PCRE non valido in `/grep` |
| `too_many_files` | 413 | Troppi file (manifest, archive, deploy) |
| `too_large` | 413 | Dimensione oltre i limiti |
| `log_disabled` | 404 | `WP_DEBUG_LOG` non attivo o file assente |
| `internal_error` | 500 | Errore interno (dettagli solo nel log del server) |

## Endpoint (M1)

### `GET /status`

Con modalità `off`: `{"mode":"off"}` (nessuna autenticazione richiesta, nessun altro dato).
Altrimenti (autenticato):

```json
{
  "mode": "read", "expires_at": 1790000000, "name": "Titolo del sito",
  "plugin": "0.1.0", "wp": "6.8.2", "php": "8.3.19",
  "theme": {"stylesheet": "mio-child", "template": "hello-elementor", "version": "1.2.0"},
  "writable_roots": ["wp-content/themes/mio-child"],
  "limits": {"read_bytes": 524288, "grep_results": 200, "grep_ms": 5000,
             "deploy_zip_bytes": 20971520, "deploy_files": 500, "deploy_file_bytes": 5242880},
  "debug_log": true,
  "rescue": "installed",
  "site_url": "https://example.com/",
  "network": {"main_site": "https://example.com/", "sites": 3}
}
```

`network` è presente solo sulle reti multisite (M4); `site_url` è il sito della rete che ha risposto.

### `POST /list`

`{path, depth=1 (1..3), max_entries=500 (1..500)}` →
`{"path":"wp-content/themes","entries":[{"p":"wp-content/themes/x","t":"d","s":0,"m":1700000000}],"truncated":false}`.
Le voci nella deny list o che si risolvono fuori dalle root vengono omesse.

### `POST /read`

`{path, from?, to?, known?: {s, m, h}}` (righe 1-based, inclusive) →
`{status:"ok", s, m, h, total_lines, from, to, content, truncated}`.

Con `known` (M3, validazione condizionale per la cache del companion):

- `s` e `m` coincidono con il file sul server → `{"status":"unchanged"}` (nessun hash calcolato);
- `s` o `m` diversi ma hash uguale a `known.h` → `{"status":"unchanged","s":…,"m":…}` (il client aggiorna `s`/`m`);
- altrimenti la risposta completa con il contenuto, come senza `known`.

`known.h` deve essere un hash xxh128 esadecimale minuscolo; `s` e `m` interi ≥ 0.

### `POST /grep`

Come da specifica 2.6.2. `glob` si applica al percorso relativo del file
(pattern senza `/` → confronto sul solo nome file).

La risposta include `engine`: `php` (implementazione PHP) oppure `rg` (accelerazione ripgrep
facoltativa, M3, attivabile dalle impostazioni). Con `rg` i file segnalati passano comunque da
PathGuard e dalla deny list; `files_scanned` conta solo i file con risultati (ripgrep non fornisce
il totale in modalità JSON). Le regex con `rg` richiedono il supporto PCRE2, altrimenti si usa PHP.

### `POST /manifest`

`{root, exclude?: [glob...]}` → `{"root":"...","files":[{p,s,m,h}]}`.
Massimo 20000 file (oltre → `413 too_many_files`, restringere `root` o usare `exclude`).

### `POST /archive`

`{paths:[...]}` (solo file, max 500) oppure `{root, exclude?}` → `application/zip`.
Voci nello zip con percorso relativo ad `ABSPATH`. I file negati dalla deny list vengono
omessi in modalità `root` e causano `403 path_denied` in modalità `paths`.

### `GET /log`

`?lines=200 (1..1000)&since=<unix ts>` →
`{"lines":["[23-Sep-2026 10:00:00 UTC] PHP Warning: ..."],"truncated":false}`.

### `GET /introspect` (0.5.0)

`?topic=<argomento>&name=<nome>` → `{topic, items: [...], truncated, note?}` (max 300 voci).

| topic | voci | `name` |
|---|---|---|
| `overview` | `{key, value, name?, version?, parent?, network?}`: versioni, ambiente, multisite, lingua, permalink, object cache, costanti di debug, tema, plugin attivi, mu-plugin | — |
| `post_types` | `{name, label, public, hierarchical, show_in_rest, has_archive, rewrite, supports}` | — |
| `taxonomies` | `{name, label, object_type, public, hierarchical, show_in_rest}` | — |
| `shortcodes` | `{tag, callback, file?, line?}` | — |
| `hook` | `{priority, args, callback, file?, line?}` in ordine di priorità | nome dell'hook (obbligatorio) |
| `rest_routes` | `{route, methods}` | prefisso (es. `wc/v3`) |
| `cron` | `{hook, next, schedule}` | — |
| `blocks` | `{name, title, callback?, file?, line?}`; i blocchi `core/` sono riassunti in una voce | prefisso (es. `core/`) |

`callback`: `funzione`, `Classe::metodo`, `Classe->metodo`, `{closure}`. `file` è relativo ad `ABSPATH`; per il
codice fuori dalla root del sito (o interno a PHP) la posizione non viene indicata. `name`:
`[A-Za-z0-9_\-./:{}\[\]]`, max 200 caratteri, altrimenti `invalid_param`.

## Endpoint (M2)

Modalità richiesta: `write` per `/deploy`, `/rollback`, `/releases`, `/cache-flush`; `read` per `/health`.
Rate limit: bucket "deploy" (10/min) per `/deploy` e `/rollback`, bucket "read" per gli altri.

### `POST /deploy` (multipart/form-data)

- `manifest` (campo testo, JSON):
  `{"files":[{"p":"wp-content/themes/x/a.php","action":"write","h":"<xxh128>","base_h":"<xxh128>"},{"p":"...","action":"delete","base_h":"..."}],"force":false}`
  - `h` obbligatorio per `write` (32 caratteri esadecimali minuscoli); `base_h` assente/`null` se il client
    considera il file nuovo; per `delete` `base_h` è l'hash che il client si aspetta sul server.
  - `health_paths` (facoltativo): fino a 10 percorsi del sito dichiarati dall'agente (es. `"/shop/"`;
    in multisite anche URL completi `http(s)://` di siti della rete: host e percorso iniziale devono
    corrispondere a un sito, niente credenziali, porte o frammenti),
    controllati **in aggiunta** agli URL configurati dall'amministratore e mai salvati sul server.
    Solo percorsi relativi al sito: devono iniziare con `/` (non `//`), niente URL completi, `..`, `#`, `@`,
    backslash o spazi, max 200 caratteri; l'URL viene costruito dal server con `home_url()`.
    Un percorso non valido rende invalido il manifest (`400 invalid_manifest`).
- `bundle` (file zip): una voce per ogni `write`, nome della voce = `p` identico, nessuna cartella,
  nessun symlink. Può mancare se il manifest contiene solo `delete`.

Risposta `200`:

```json
{
  "release_id": "20260923-101500-a1b2c3",
  "status": "ok",
  "written": 2, "deleted": 1,
  "health": {"status": "ok", "checks": [{"url": "https://example.com/", "code": 200, "ms": 180}]},
  "errors": ["[23-Sep-2026 10:15:01 UTC] PHP Fatal error: ..."],
  "rescue_token": "<64 caratteri esadecimali>"
}
```

- `status`: `ok` | `rolled_back` (health check fallito, file ripristinati) | `health_unknown`
  (loopback non raggiungibile: nessun rollback, `health.message` spiega il motivo).
- `health.status`: `ok` | `fail` | `unknown`; `checks[].error` presente per errori di rete;
  `checks[].source`: `admin` (impostazioni), `agent` (`health_paths`) o `backend` (login e admin-ajax, 0.5.0).
- `errors`: righe `PHP Fatal error` / `PHP Parse error` comparse in `debug.log` durante il deploy (max 20).
- `rescue_token`: presente solo con `ok` e `health_unknown`; monouso, valido 24 h per questa release.
- Se tutti i file del manifest hanno già sul server il contenuto indicato (`h` uguale all'hash attuale)
  non viene creata alcuna release: `{"release_id": null, "status": "ok", "written": 0, "deleted": 0, "health": {"status": "skipped", "checks": []}}`.
- `limits.deploy_zip_bytes` in `/status` è già ridotto ai limiti di upload di PHP (`upload_max_filesize`, `post_max_size`).

Errori specifici:

| Codice | HTTP | Note |
|---|---|---|
| `deploy_locked` | 409 | Un altro deploy/rollback è in corso |
| `conflict` | 409 | `error.conflicts: [{"p": "...", "reason": "modified"\|"exists"\|"deleted"}]`; ripetere con `force:true` per sovrascrivere |
| `invalid_manifest` | 400 | Manifest JSON non valido; `error.path` se riferito a un file |
| `invalid_bundle` | 422 | Zip non valido, voci in più/mancanti, percorsi assoluti, `..`, symlink, hash diverso da `h`; `error.path` se riferito a una voce |
| `too_large` / `too_many_files` | 413 | Limiti `deploy_zip_bytes`, `deploy_files`, `deploy_file_bytes` |
| `path_denied` / `extension_denied` / `path_invalid` | 403/400 | Da PathGuard; `error.path` con il percorso relativo |
| `deploy_failed` | 500 | Errore di scrittura: i file già scritti sono stati ripristinati dal backup |

Nessun file viene scritto se una qualsiasi verifica fallisce.

### `POST /rollback`

`{"release_id"?: "...", "force"?: false}` → annulla la release indicata **e tutte quelle successive**
(dalla più recente), oppure l'ultima release attiva se `release_id` manca.

```json
{"status": "ok", "rolled_back": ["20260923-101500-a1b2c3"], "files": [{"p": "wp-content/themes/x/a.php", "h": "<hash ripristinato>"}, {"p": "wp-content/themes/x/new.php", "h": null}]}
```

`files[].h` è l'hash del file dopo il ripristino (`null` = file rimosso).
Il rollback verifica che i file siano ancora quelli lasciati dalla release (hash `h`, o assenti se cancellati). Errori: `no_release` (404),
`conflict` (409, file modificati sul server dopo la release; `force:true` per procedere), `deploy_locked` (409).
Il token di rescue viene invalidato.

### `GET /releases`

`{"releases":[{"id":"...","created_at":1790000000,"user_id":1,"written":2,"deleted":1,"status":"ok"}]}`
(dalla più recente). `status`: `ok` | `health_unknown` | `rolled_back` | `rescued`.

### `POST /health`

`{"paths"?: ["/shop/"]}` → `{"status":"ok"|"fail"|"unknown","checks":[...],"errors":[...]}`; `errors` = righe
fatali di `debug.log` negli ultimi 5 minuti; `paths` con le stesse regole di `health_paths`.

### `POST /cache-flush`

`{"targets"?: ["opcache","object","elementor"]}` (default: tutti) →
`{"results":{"opcache":"ok","object":"ok","elementor":"unavailable"}}`.

## Rescue fuori banda (mu-plugin)

- Richiesta: `GET <site>/?devbridge_rescue=1` con header `X-DevBridge-Rescue: <token>`.
- Successo `200`: `{"status":"ok","release_id":"...","files":[{"p":"...","h":"..."|null}]}`.
- Errori JSON: `rescue_denied` (403: token errato, scaduto, IP non ammesso, HTTP senza HTTPS),
  `rescue_failed` (500: ripristino parziale, `error.files` con quanto ripristinato).
- Se il rescue non è disponibile (nessun token attivo, plugin rimosso) il mu-plugin non risponde e
  WordPress restituisce la pagina normale: il companion lo rileva perché la risposta non è JSON.

## Glob

Sintassi comune a plugin e companion (confronto case-insensitive per la deny list):

- `*` qualsiasi sequenza senza `/`; `?` un carattere diverso da `/`;
- `**/` zero o più cartelle; `/**` in coda: tutto il contenuto;
- pattern senza `/`: confrontato con il solo nome del file/cartella, a qualsiasi livello.

## Multisite (M4)

- Plugin attivabile solo a livello di rete; senza attivazione di rete non registra endpoint.
- Autorizzazione: capability `manage_network_options` (super admin) al posto di `manage_options`;
  un amministratore di sottosito riceve `403 forbidden_user` anche se inserito in `allowed_user_ids`.
- Gli endpoint rispondono sull'URL REST di ogni sito della rete con gli stessi dati di rete
  (impostazioni, modalità, release, rescue, rate limit sono condivisi).
- I percorsi relativi di `health_paths` / `paths` sono risolti sul sito che riceve la richiesta.
- La tabella di audit (`{base_prefix}devbridge_audit`) registra anche `blog_id`.
