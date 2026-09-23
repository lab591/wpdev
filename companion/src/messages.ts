import { ConfigError } from './config.js';
import { ApiError } from './http.js';
import { PathError } from './paths.js';

/** Short, user-facing (Italian) description of an error. Never includes secrets. */
export function describeError(e: unknown): string {
  if (e instanceof ApiError) {
    switch (e.code) {
      case 'mode_off':
        return 'Modalità sviluppo disattivata sul server: attivala dal pannello Dev Bridge o con `wp devbridge enable --mode=read --hours=N`.';
      case 'mode_insufficient':
        return 'Modalità insufficiente: attiva la modalità write dal pannello o con `wp devbridge enable --mode=write --hours=N`.';
      case 'app_password_required':
      case 'invalid_credentials':
      case 'incorrect_password':
      case 'invalid_username':
      case 'rest_not_logged_in':
        return 'Autenticazione fallita: controlla utente e Application Password.';
      case 'forbidden_user':
        return 'Utente non autorizzato: serve manage_options e l\'inclusione in allowed_user_ids nelle impostazioni Dev Bridge.';
      case 'forbidden_ip':
        return 'IP non autorizzato dalla allowlist di Dev Bridge.';
      case 'https_required':
        return 'Il server accetta solo richieste HTTPS.';
      case 'rate_limited':
        return `Troppe richieste: riprova tra ${e.retryAfter ?? 60} s.`;
      case 'rest_no_route':
        return 'Endpoint Dev Bridge non trovato: il plugin è installato e attivo?';
      default:
        return `${e.message} [${e.code}${e.status ? ` ${e.status}` : ''}]`;
    }
  }
  if (e instanceof ConfigError || e instanceof PathError) {
    return e.message;
  }
  if (e instanceof Error) {
    return e.message;
  }
  return String(e);
}

export function formatExpiry(expiresAt: number, now: number = Date.now() / 1000): string {
  const left = Math.max(0, Math.round(expiresAt - now));
  const h = Math.floor(left / 3600);
  const m = Math.floor((left % 3600) / 60);
  const when = new Date(expiresAt * 1000).toLocaleString('it-IT', { dateStyle: 'short', timeStyle: 'short' });
  return `scade tra ${h > 0 ? `${h}h ` : ''}${m}m (${when})`;
}

export function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes}B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)}K`;
  return `${(bytes / 1024 / 1024).toFixed(1)}M`;
}
