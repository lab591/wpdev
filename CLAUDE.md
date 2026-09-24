# Dev Bridge — istruzioni per Claude Code

La specifica completa è in `SPEC.md`. Leggila prima di iniziare qualsiasi milestone e
consideralo il riferimento: se qualcosa non è chiaro o sembra sbagliato, chiedi invece di
improvvisare.

## Struttura del repo

- `plugin/` — plugin WordPress `lab591-dev-bridge` (PHP 8.1+, namespace `Lab591\DevBridge`)
- `plugin/mu-plugin/devbridge-rescue.php` — rete di sicurezza fuori banda (PHP puro)
- `plugin/tests/` — PHPUnit
- `companion/` — CLI + server MCP `wpdev` (Node 20+, TypeScript strict, ESM)
- `companion/test/` — Vitest
- `docs/` — note, scenari end-to-end

## Come lavorare

- Una milestone alla volta (M1 → M2 → M3), nell'ordine della specifica. Non anticipare
  funzionalità di milestone successive.
- Prima di scrivere codice per una milestone, proponi un piano breve (file, classi, ordine) e
  attendi conferma.
- Scrivi i test insieme al codice, non dopo. PathGuard e la validazione del deploy vanno
  scritti partendo dai test.
- Dopo ogni blocco di lavoro esegui test e lint e riporta l'esito.
- Codice, commenti e messaggi di commit in inglese; documentazione per l'utente in italiano.

## Invarianti di sicurezza (non negoziabili)

Queste regole non si allentano mai, nemmeno "temporaneamente" o per far passare un test:

1. Ogni percorso ricevuto dall'esterno passa da `PathGuard::resolve()`. Nessun accesso al
   filesystem con percorsi non risolti da PathGuard.
2. Nessun endpoint REST può cambiare la modalità sviluppo, le impostazioni o le root.
3. Nessun uso di `exec`, `shell_exec`, `system`, `passthru`, backtick. `proc_open` solo nella
   forma array e solo dove la specifica lo prevede (M3, `rg`).
4. Nessun `eval`, `create_function`, `unserialize` su dati esterni, `include` di percorsi variabili.
5. La deny list si applica dopo la risoluzione del percorso, anche a grep, archive e manifest.
6. Il deploy valida tutto prima di scrivere qualsiasi file. O passa tutto, o non si scrive niente.
7. Mai registrare password, token o contenuti di file nei log (audit o console).
8. Il companion non invia mai contenuti di file negli argomenti dei tool MCP: il deploy legge
   dal disco.
9. HTTPS e verifica TLS sempre attivi, salvo le eccezioni per ambienti locali previste dalla specifica.

10. Il database è accessibile solo in lettura (`SHOW`/`SELECT`), mai con SQL libero: tabelle e colonne passano da
    `TableGuard` (verificate sullo schema reale), i valori solo come segnaposto di `$wpdb->prepare()`.
11. Le colonne segrete non si leggono, non si filtrano e non si ordinano; i valori di chiavi segrete sono sempre
    oscurati e non interrogabili con confronti parziali. Nessuna impostazione può disattivarlo.
12. Mai registrare valori letti dal database (audit o console): solo tabella, colonne e operatori.

Se un requisito sembra richiedere di violare un invariante, fermati e chiedi.

## Convenzioni PHP

- `declare(strict_types=1);` in ogni file, classi `final` dove possibile, tipi ovunque.
- WordPress Coding Standards (PHPCS), con le eccezioni necessarie per namespace e PSR-4.
- Autoload PSR-4 senza dipendenze Composer a runtime (Composer solo per gli strumenti di sviluppo).
- Logica nelle classi di servizio (`Services\`), endpoint REST come strato sottile (`Rest\`):
  la logica deve poter essere riusata in futuro da WP-CLI o dall'Abilities API.
- Sanitizzazione e validazione esplicite di ogni parametro con `args` di `register_rest_route`.
- Errori come `WP_Error` con codici stabili (vedi specifica), senza percorsi assoluti nei messaggi.

## Convenzioni TypeScript

- `strict: true`, nessun `any` non giustificato, ESM.
- Percorsi: internamente relativi con `/`; convertire da/verso i percorsi nativi solo ai bordi
  (lettura/scrittura su disco). Testare con percorsi Windows.
- Dipendenze minime e senza moduli nativi da compilare.
- Output CLI sintetico; output dei tool MCP compatto e testuale, con troncamenti espliciti.

## Comandi

- Plugin: `composer install` (solo dev), `composer test`, `composer lint`
- Interfaccia admin del plugin (`plugin/admin/src`, React/TS): `npm install`, `npm run lint`, `npm run build`,
  `npm run i18n` (WP-CLI); zip: `npm run package`
- Companion: `npm install`, `npm test`, `npm run lint`, `npm run build`
- Prova locale del companion: `npm run dev -- <comando>`
