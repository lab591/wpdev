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
  "mode": "read", "expires_at": 1790000000,
  "plugin": "0.1.0", "wp": "6.8.2", "php": "8.3.19",
  "theme": {"stylesheet": "mio-child", "template": "hello-elementor"},
  "writable_roots": ["wp-content/themes/mio-child"],
  "limits": {"read_bytes": 524288, "grep_results": 200, "grep_ms": 5000,
             "deploy_zip_bytes": 20971520, "deploy_files": 500, "deploy_file_bytes": 5242880},
  "debug_log": true,
  "rescue": "n/a"
}
```

### `POST /list`

`{path, depth=1 (1..3), max_entries=500 (1..500)}` →
`{"path":"wp-content/themes","entries":[{"p":"wp-content/themes/x","t":"d","s":0,"m":1700000000}],"truncated":false}`.
Le voci nella deny list o che si risolvono fuori dalle root vengono omesse.

### `POST /read`

`{path, from?, to?}` (righe 1-based, inclusive) →
`{status:"ok", s, m, h, total_lines, from, to, content, truncated}`.

### `POST /grep`

Come da specifica 2.6.2. `glob` si applica al percorso relativo del file
(pattern senza `/` → confronto sul solo nome file).

### `POST /manifest`

`{root, exclude?: [glob...]}` → `{"root":"...","files":[{p,s,m,h}],"truncated":false}`.
Massimo 20000 file (oltre → `413 too_many_files`, restringere `root` o usare `exclude`).

### `POST /archive`

`{paths:[...]}` (solo file, max 500) oppure `{root, exclude?}` → `application/zip`.
Voci nello zip con percorso relativo ad `ABSPATH`. I file negati dalla deny list vengono
omessi in modalità `root` e causano `403 path_denied` in modalità `paths`.

### `GET /log`

`?lines=200 (1..1000)&since=<unix ts>` →
`{"lines":["[23-Sep-2026 10:00:00 UTC] PHP Warning: ..."],"truncated":false}`.

## Glob

Sintassi comune a plugin e companion (confronto case-insensitive per la deny list):

- `*` qualsiasi sequenza senza `/`; `?` un carattere diverso da `/`;
- `**/` zero o più cartelle; `/**` in coda: tutto il contenuto;
- pattern senza `/`: confrontato con il solo nome del file/cartella, a qualsiasi livello.
