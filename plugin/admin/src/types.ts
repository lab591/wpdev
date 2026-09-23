/** Shapes of the data exchanged with AdminController (PHP). */

export interface Bootstrap {
	ajaxUrl: string;
	nonce: string;
	version: string;
	network: boolean;
	siteUrl: string;
	restUrl: string;
	user: string;
	locale: string;
	profile: string;
	endpoint: string[];
}

export type ModeName = 'off' | 'read' | 'write';

export interface ModeState {
	mode: ModeName;
	expiresAt: number;
	since: number;
	maxHours: { read: number; write: number };
}

export type CheckStatus = 'ok' | 'warning' | 'error' | 'info';

export interface Check {
	id: string;
	status: CheckStatus;
	title: string;
	detail: string;
	code: string;
}

export interface Release {
	id: string;
	createdAt: number;
	user: string;
	written: number;
	deleted: number;
	status: string;
}

export type PreviewState =
	| { active: false }
	| {
			active: true;
			expires_at: number;
			expired: boolean;
			units: string[];
			files: string[];
	  };

export interface StatusData {
	mode: ModeState;
	preview: PreviewState;
	writableRoots: string[];
	checks: Check[];
	releases: Release[];
	network: { sites: number } | null;
	message?: string;
}

export type Limits = Record< string, number >;

export interface Settings {
	allowed_user_ids: number[];
	writable_roots: string[];
	invalid_roots: string[];
	allow_mu_plugins: boolean;
	read_roots: string[];
	deny_patterns: string[];
	write_extensions: string[];
	ip_allowlist: string[];
	trusted_proxies: string[];
	grep_skip_dirs: string[];
	grep_rg: string;
	health_urls: string[];
	health_backend: boolean;
	notify_emails: string[];
	notify_webhook: string;
	notify_events: string[];
	retention_releases: number;
	audit_retention_days: number;
	max_read_hours: number;
	max_write_hours: number;
	limits: Limits;
}

export interface UserOption {
	id: number;
	login: string;
	name: string;
}

export interface SettingsData {
	settings: Settings;
	users: UserOption[];
	containers: string[];
	limits: Limits;
	errors?: string[];
	message?: string;
	created?: string;
}

export interface FolderItem {
	path: string;
	name: string;
	label: string;
	selectable: boolean;
	reason: string;
	expandable: boolean;
}

export interface FolderList {
	items: FolderItem[];
	truncated: boolean;
}

export interface AuditRow {
	id: number;
	ts: string;
	site: string;
	user: string;
	ip: string;
	endpoint: string;
	mode: string;
	paths: string[];
	bytes: number;
	status: number;
	durationMs: number;
	releaseId: string;
}

export interface AuditData {
	rows: AuditRow[];
	total: number;
	pages: number;
	page: number;
	sites: Record< string, string > | null;
}
