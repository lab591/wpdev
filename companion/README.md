# wpdev

Companion di **Dev Bridge**: CLI e server MCP per lavorare con Claude Code su un sito WordPress remoto in modo
sicuro (lettura del sito via MCP, modifiche in locale, deploy con lint, health check e rollback automatico).

Richiede il plugin WordPress **Lab591 Dev Bridge** installato sul sito (zip nelle
[release](https://github.com/lab591/wpdev/releases)) e Node.js 20 o successivo.

```bash
npm install -g wpdev
cd mio-progetto
echo "WPDEV_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx" > .env.local
wpdev init --site https://www.esempio.it --user mioutente
wpdev pull
```

Poi apri Claude Code nella cartella: le modifiche vengono pubblicate a fine turno.

Comandi principali: `init`, `status`, `pull`, `diff`, `deploy`, `rollback`, `restore`, `info`, `log`,
`health`, `claude-md`, `mcp`. Documentazione completa, sicurezza e configurazione:
[README del progetto](https://github.com/lab591/wpdev#readme).

Licenza: GPL-2.0-or-later.
