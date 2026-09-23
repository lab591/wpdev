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
