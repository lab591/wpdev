# Changelog

Tutte le versioni del plugin **Lab591 Dev Bridge** e del companion **wpdev**.

## Non ancora rilasciato

- **Protezione della pagina con password** (facoltativa): senza password la pagina Dev Bridge mostra solo il
  campo per sbloccarla e tutte le azioni sono rifiutate. Sblocco per sessione, 30 minuti di inattività,
  pulsante *Blocca*, limite ai tentativi, eventi nell'audit, recupero con `wp devbridge lock --clear`.

## 0.6.0

- **Database in sola lettura** (spento per default, *Impostazioni → Database*): livello *Struttura* (tabelle,
  colonne, indici, chiavi meta, nomi delle opzioni) o *Lettura dati* (righe con query strutturate, max 100).
  Colonne segrete mai leggibili, segreti sempre oscurati anche dentro JSON e dati serializzati, solo confronti
  esatti sui valori di opzioni e meta, dati personali mascherati per default, tabelle escludibili, audit senza
  valori. Strumenti MCP `db_schema` e `db_query`, comando `wpdev db`.

- **Cache**: il plugin rileva cache delle pagine (drop-in `advanced-cache.php`, plugin di cache lato server,
  hosting gestiti), ottimizzazione CSS/JS, object cache e OPcache non aggiornabile, e lo dice nella scheda Stato,
  in `site_info` e dopo ogni deploy, con le istruzioni per Claude. Non svuota niente: per farlo in automatico
  basta agganciare l'azione `devbridge_deployed` (esempi nel README).
- **Health check**: una pagina servita da una cache (proxy, CDN) non conta più come "ok": l'esito diventa "non
  verificabile".
- **Anteprima**: cookie rinominato `wordpress_devbridge_preview` (saltato da molte cache di server e CDN); se una
  cache davanti a PHP lo ignora, `wpdev preview` lo segnala invece di dare un link che mostra il sito live.

## 0.5.0

- **Anteprima prima della pubblicazione**: `wpdev preview` mostra le modifiche solo a chi ha il link (cookie);
  poi `publish` (deploy normale con backup, health check e rollback) o `discard`. Pulsanti anche nella pagina
  admin; `"deploy": { "target": "preview" }` per l'hook di fine turno.
- **Ambienti multipli** (`environments`, `--env`): staging e produzione con stato separato; `autoDeploy: false`
  protegge la produzione da deploy automatici.
- **Health check del backend**: login, admin-ajax (`admin_init`) e indice REST (`rest_api_init`); nuovi
  **avvisi PHP** dei file pubblicati riportati a Claude.
- **Introspezione del sito** (`site_info`, `wpdev info`): versioni, plugin, tipi di contenuto, shortcode, callback
  di un hook con file e riga, rotte REST, cron, blocchi.
- **Lint PHP senza PHP locale** (parser integrato).
- **Notifiche** email e webhook (Slack, Discord…) per deploy, rollback e attivazione della scrittura.
- **Commit git automatico** dei file pubblicati; `wpdev restore` per scartare modifiche locali;
  `wpdev claude-md` per aggiornare le istruzioni di Claude; `init` riusa un `wpdev.json` esistente.
- **Aggiornamenti del plugin dalle release GitHub**; pacchetto npm pronto.
- CI (GitHub Actions) e test end-to-end automatici con WordPress in Docker.
- Correzioni: i siti con permalink "semplici" ora funzionano (fallback a `?rest_route=`); un ripgrep non
  eseguibile ripiega sulla ricerca PHP invece di restituire zero risultati; `wpdev … | head` non va più in errore.

## 0.4.0

- Nuova interfaccia di amministrazione (React + componenti WordPress), in inglese con traduzione italiana.
- Licenza GPL-2.0-or-later. Richiede WordPress 6.6.

## 0.3.0

- Cartelle scrivibili decise solo dal sito (selezione con caselle, creazione di nuove cartelle vuote);
  `writable` in `wpdev.json` diventa facoltativo.

## 0.2.0

- Supporto multisite (attivazione solo di rete, super admin, audit per sito, health check su tutta la rete).

## 0.1.0

- Prima versione: esplorazione in sola lettura via MCP, deploy con validazione completa, health check,
  rollback automatico e manuale, rescue fuori banda, cache di lettura, ripgrep facoltativo.
