# Roadmap 0.5 → 1.0

Miglioramenti decisi dopo la 0.4.0, in ordine di esecuzione. Priorità: efficienza, sicurezza ed esperienza
d'uso prima, poi infrastruttura del progetto pubblico, infine le funzioni più ambiziose. Ogni voce segue lo
stesso ciclo: specifica → codice con test → prova sui siti locali (singolo e rete) → documentazione → commit.
Gli invarianti di sicurezza di `CLAUDE.md` valgono per tutte.

| # | Voce | Perché | Stato |
|---|---|---|---|
| 1 | **Lint PHP senza PHP locale** — il companion include un parser PHP in JavaScript; usa `php -l` se c'è, altrimenti il parser | evita deploy rotti su PC senza PHP | fatto (0.5.0) |
| 2 | **"Verifica di nuovo"** nella scheda Stato — rifà subito i controlli (ripgrep, rescue) ignorando la cache | esperienza d'uso | fatto (0.5.0) |
| 3 | **Health check del backend** — dopo il deploy si controllano anche `wp-login.php` e una richiesta admin-ajax (carica `admin_init`), senza autenticazione | un fatale solo in admin non deve chiuderti fuori | fatto (0.5.0) |
| 4 | **Introspezione del sito** (sola lettura) — endpoint `/introspect` e strumento MCP `site_info`: plugin e temi attivi, tipi di contenuto, tassonomie, shortcode, callback di un hook con file e riga, rotte REST, cron | Claude capisce il sito con una chiamata invece di tanti grep | fatto (0.5.0) |
| 5 | **Warning e notice del deploy** — l'health check riporta anche i nuovi warning/notice/deprecated (senza rollback) | problemi visti prima che diventino fatali | fatto (0.5.0) |
| 6 | **`wpdev claude-md`** e `init` che riusa un `wpdev.json` esistente — sezione di `CLAUDE.md` tra marcatori, aggiornabile senza toccare il resto | istruzioni per Claude sempre attuali | fatto (0.5.0) |
| 7 | **Notifiche** — email e/o webhook (es. Slack) per deploy, rollback e attivazione della scrittura; solo percorsi e metadati, mai contenuti | sicurezza: l'amministratore sa cosa succede | fatto (0.5.0) |
| 8 | **Commit git automatico** dopo ogni deploy riuscito (solo i file pubblicati, messaggio con la release) | cronologia locale allineata alle release | fatto (0.5.0) |
| 8b | **`wpdev restore <percorso>`** — riporta un file locale alla versione del server (scarta una modifica locale) | emerso nei test: oggi `pull` non tocca le modifiche solo locali | fatto (0.5.0) |
| 9 | **Ambienti multipli** — `environments` in `wpdev.json` (es. staging e produzione), `--env`, stato separato per ambiente; l'hook pubblica solo sull'ambiente predefinito | sicurezza: la produzione non si tocca per sbaglio | fatto (0.5.0) |
| 10 | **CI GitHub Actions** — test e lint a ogni push, zip del plugin allegato alle release | progetto pubblico affidabile | fatto (0.5.0) |
| 11 | **Test end-to-end automatici** — WordPress in container (`wp-env`) + scenari del companion | le prove manuali diventano ripetibili | |
| 12 | **Distribuzione** — `wpdev` pronto per npm; aggiornamenti del plugin da GitHub Releases | installazione e aggiornamenti semplici | |
| 13 | **Anteprima prima della pubblicazione** — i file nuovi serviti solo a chi ha un cookie firmato, poi "pubblica" o "scarta" | quasi zero rischio sui siti reali | |
