import {
	Button,
	CheckboxControl,
	FormTokenField,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import type { Notify } from './App';
import { boot, errorMessage, get, post } from './api';
import { NumberField, Section, Segmented } from './components';
import FolderTree from './FolderTree';
import PageLockSection from './PageLockSection';
import { formatBytes } from './format';
import type { Settings, SettingsData } from './types';

interface Props {
	notify: Notify;
	onSaved: () => void;
	pageLock: boolean;
	onPageLock: ( enabled: boolean ) => void;
}

type ListKey =
	| 'notify_emails'
	| 'read_roots'
	| 'deny_patterns'
	| 'write_extensions'
	| 'ip_allowlist'
	| 'trusted_proxies'
	| 'grep_skip_dirs'
	| 'health_urls'
	| 'db_excluded_tables';

const LIMIT_LABELS: Record< string, { label: string; bytes?: boolean } > = {
	read_bytes: {
		label: __( 'Bytes per read', 'lab591-dev-bridge' ),
		bytes: true,
	},
	grep_results: { label: __( 'Search results', 'lab591-dev-bridge' ) },
	grep_ms: { label: __( 'Search time (ms)', 'lab591-dev-bridge' ) },
	grep_file_bytes: {
		label: __( 'Largest file searched', 'lab591-dev-bridge' ),
		bytes: true,
	},
	manifest_files: { label: __( 'Files per manifest', 'lab591-dev-bridge' ) },
	archive_files: { label: __( 'Files per download', 'lab591-dev-bridge' ) },
	archive_bytes: {
		label: __( 'Bytes per download', 'lab591-dev-bridge' ),
		bytes: true,
	},
	deploy_zip_bytes: {
		label: __( 'Deploy size', 'lab591-dev-bridge' ),
		bytes: true,
	},
	deploy_files: { label: __( 'Files per deploy', 'lab591-dev-bridge' ) },
	deploy_file_bytes: {
		label: __( 'Largest deployed file', 'lab591-dev-bridge' ),
		bytes: true,
	},
};

export default function SettingsTab( {
	notify,
	onSaved,
	pageLock,
	onPageLock,
}: Props ) {
	const [ data, setData ] = useState< SettingsData | null >( null );
	const [ draft, setDraft ] = useState< Settings | null >( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ errors, setErrors ] = useState< string[] >( [] );
	const [ treeKey, setTreeKey ] = useState( 0 );

	const load = ( result: SettingsData ) => {
		setData( result );
		setDraft( result.settings );
	};

	useEffect( () => {
		get< SettingsData >( 'devbridge_settings' ).then( load, ( e ) =>
			setLoadError( errorMessage( e ) )
		);
	}, [] );

	const dirty = useMemo(
		() =>
			!! data &&
			!! draft &&
			JSON.stringify( data.settings ) !== JSON.stringify( draft ),
		[ data, draft ]
	);

	useEffect( () => {
		const warn = ( event: BeforeUnloadEvent ) => {
			if ( dirty ) {
				event.preventDefault();
			}
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	if ( loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ loadError }
			</Notice>
		);
	}
	if ( ! data || ! draft ) {
		return (
			<div className="devbridge-loading">
				<Spinner />
			</div>
		);
	}

	const set = < K extends keyof Settings >( key: K, value: Settings[ K ] ) =>
		setDraft( { ...draft, [ key ]: value } );
	const muChanged = draft.allow_mu_plugins !== data.settings.allow_mu_plugins;

	const save = async () => {
		setSaving( true );
		setErrors( [] );
		try {
			const result = await post< SettingsData >( 'devbridge_save', {
				settings: draft,
			} );
			load( result );
			setErrors( result.errors ?? [] );
			if ( ! result.errors?.length && result.message ) {
				notify( result.message );
			}
			if ( muChanged ) {
				setTreeKey( treeKey + 1 );
			}
			onSaved();
		} catch ( e ) {
			setErrors( [ errorMessage( e ) ] );
		} finally {
			setSaving( false );
		}
	};

	const list = (
		key: ListKey,
		label: string,
		help: string,
		placeholder = ''
	) => (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ draft[ key ] }
			placeholder={ placeholder }
			onChange={ ( tokens ) =>
				set(
					key,
					tokens
						.map( ( t ) =>
							( typeof t === 'string' ? t : t.value ).trim()
						)
						.filter( Boolean )
				)
			}
			displayTransform={ ( token: string ) =>
				key === 'read_roots' && token === '.'
					? __( '. (whole site)', 'lab591-dev-bridge' )
					: token
			}
			help={ help }
		/>
	);

	const number = (
		key:
			| 'retention_releases'
			| 'audit_retention_days'
			| 'max_read_hours'
			| 'max_write_hours',
		label: string,
		help = ''
	) => (
		<NumberField
			label={ label }
			value={ draft[ key ] }
			help={ help }
			onChange={ ( v ) => set( key, v ) }
		/>
	);

	return (
		<div className="devbridge-settings">
			{ errors.length > 0 && (
				<Notice status="warning" onRemove={ () => setErrors( [] ) }>
					<p>
						{ __(
							'Settings saved, some entries were discarded:',
							'lab591-dev-bridge'
						) }
					</p>
					<ul className="devbridge-error-list">
						{ errors.map( ( e ) => (
							<li key={ e }>{ e }</li>
						) ) }
					</ul>
				</Notice>
			) }

			<Section
				title={ __( 'Access', 'lab591-dev-bridge' ) }
				description={
					boot.network
						? __(
								'Only network super admins, authenticated with an Application Password.',
								'lab591-dev-bridge'
							)
						: __(
								'Only administrators, authenticated with an Application Password.',
								'lab591-dev-bridge'
							)
				}
			>
				<fieldset className="devbridge-field">
					<legend className="devbridge-field__label">
						{ __( 'Authorized users', 'lab591-dev-bridge' ) }
					</legend>
					<div className="devbridge-users">
						{ data.users.map( ( u ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ u.id }
								label={
									u.name && u.name !== u.login
										? `${ u.name } (${ u.login })`
										: u.login
								}
								checked={ draft.allowed_user_ids.includes(
									u.id
								) }
								onChange={ ( on: boolean ) =>
									set(
										'allowed_user_ids',
										on
											? [
													...draft.allowed_user_ids,
													u.id,
												]
											: draft.allowed_user_ids.filter(
													( id ) => id !== u.id
												)
									)
								}
							/>
						) ) }
					</div>
					{ draft.allowed_user_ids.length === 0 && (
						<p className="devbridge-field__warning">
							{ __(
								'Nobody can connect until you select a user.',
								'lab591-dev-bridge'
							) }
						</p>
					) }
				</fieldset>
				<div className="devbridge-two-columns">
					{ list(
						'ip_allowlist',
						__( 'IP allowlist', 'lab591-dev-bridge' ),
						__(
							'Empty = any IP. IPv4/IPv6 or CIDR, e.g. 203.0.113.0/24.',
							'lab591-dev-bridge'
						)
					) }
					{ list(
						'trusted_proxies',
						__( 'Trusted proxies', 'lab591-dev-bridge' ),
						__(
							'X-Forwarded-For is used only for requests coming from these addresses.',
							'lab591-dev-bridge'
						)
					) }
				</div>
			</Section>

			<Section
				title={ __( 'Writable folders', 'lab591-dev-bridge' ) }
				description={ __(
					'Choose the themes and plugins Claude works on (or only some of their sub-folders). Selecting a folder includes its sub-folders. The whole themes/ and plugins/ folders and Dev Bridge itself cannot be selected.',
					'lab591-dev-bridge'
				) }
			>
				{ draft.invalid_roots.length > 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: list of folders. */
							__(
								'Saved folders that are no longer valid and will be removed on save: %s',
								'lab591-dev-bridge'
							),
							draft.invalid_roots.join( ', ' )
						) }
					</Notice>
				) }
				<FolderTree
					key={ treeKey }
					containers={ data.containers }
					selected={ draft.writable_roots }
					onChange={ ( roots ) => set( 'writable_roots', roots ) }
				/>
				<NewFolder
					containers={ data.containers }
					onCreated={ ( result ) => {
						// Keep unsaved edits, add the new folder to them and to the saved settings.
						setData( result );
						setDraft( {
							...draft,
							writable_roots: [
								...draft.writable_roots.filter(
									( r ) =>
										! r
											.toLowerCase()
											.startsWith(
												`${ result.created?.toLowerCase() }/`
											)
								),
								result.created ?? '',
							].filter( Boolean ),
						} );
						setTreeKey( treeKey + 1 );
						if ( result.message ) {
							notify( result.message );
						}
						onSaved();
					} }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Allow folders in wp-content/mu-plugins/',
						'lab591-dev-bridge'
					) }
					checked={ draft.allow_mu_plugins }
					onChange={ ( on: boolean ) =>
						set( 'allow_mu_plugins', on )
					}
					help={
						muChanged
							? __(
									'Save to update the list of folders.',
									'lab591-dev-bridge'
								)
							: __(
									'Must-use plugins load on every request: enable only if you need it.',
									'lab591-dev-bridge'
								)
					}
				/>
			</Section>

			<Section
				title={ __( 'Reading and search', 'lab591-dev-bridge' ) }
				description={ __(
					'What Claude can read. wp-config, .env, .git, .htpasswd and the private storage are always excluded.',
					'lab591-dev-bridge'
				) }
			>
				<div className="devbridge-two-columns">
					{ list(
						'read_roots',
						__( 'Readable folders', 'lab591-dev-bridge' ),
						__( '"." is the whole site.', 'lab591-dev-bridge' )
					) }
					{ list(
						'deny_patterns',
						__( 'Deny list (glob)', 'lab591-dev-bridge' ),
						__(
							'Applied after resolving paths, e.g. **/*.sql.',
							'lab591-dev-bridge'
						)
					) }
					{ list(
						'grep_skip_dirs',
						__( 'Folders skipped by search', 'lab591-dev-bridge' ),
						__(
							'Folder names, e.g. node_modules.',
							'lab591-dev-bridge'
						)
					) }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'ripgrep acceleration (optional)',
							'lab591-dev-bridge'
						) }
						value={ draft.grep_rg }
						placeholder="rg"
						onChange={ ( v: string ) => set( 'grep_rg', v ) }
						help={ __(
							'Empty = off. "rg" from the PATH or the absolute path of rg/rg.exe. Results still go through the path checks and the deny list.',
							'lab591-dev-bridge'
						) }
					/>
				</div>
			</Section>

			<Section
				title={ __( 'Database', 'lab591-dev-bridge' ) }
				description={ __(
					'Read-only access to understand the data and debug. Claude never writes to the database through Dev Bridge. Password, key and token columns are never readable.',
					'lab591-dev-bridge'
				) }
			>
				<div className="devbridge-two-columns">
					<div>
						<Segmented
							label={ __(
								'Database access',
								'lab591-dev-bridge'
							) }
							value={ draft.db_access }
							options={ [
								{
									value: 'off',
									label: __( 'Off', 'lab591-dev-bridge' ),
								},
								{
									value: 'schema',
									label: __(
										'Structure',
										'lab591-dev-bridge'
									),
								},
								{
									value: 'read',
									label: __(
										'Read data',
										'lab591-dev-bridge'
									),
								},
							] }
							onChange={ ( v ) =>
								set( 'db_access', v as Settings[ 'db_access' ] )
							}
						/>
						<p className="devbridge-help">
							{
								{
									off: __(
										'Claude cannot see the database.',
										'lab591-dev-bridge'
									),
									schema: __(
										'Tables, columns, indexes, meta keys and option names with counts and sizes: no stored value.',
										'lab591-dev-bridge'
									),
									read: __(
										'Tables, columns, meta keys and rows (at most 100 per query, through structured queries: no free SQL). Values of secret options and meta are redacted.',
										'lab591-dev-bridge'
									),
								}[ draft.db_access ]
							}
						</p>
					</div>
					<div>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Mask personal data',
								'lab591-dev-bridge'
							) }
							help={ __(
								'Emails, IP addresses, phone numbers, names and addresses are replaced with [personal]. Recommended: what Claude reads ends up in the conversation.',
								'lab591-dev-bridge'
							) }
							checked={ draft.db_redact_personal }
							disabled={ draft.db_access !== 'read' }
							onChange={ ( on: boolean ) =>
								set( 'db_redact_personal', on )
							}
						/>
					</div>
					{ list(
						'db_excluded_tables',
						__( 'Excluded tables', 'lab591-dev-bridge' ),
						__(
							'Never visible, not even their structure. Use * as wildcard, e.g. wp_wc_orders*.',
							'lab591-dev-bridge'
						)
					) }
				</div>
			</Section>

			<Section
				title={ __( 'Deploy and health check', 'lab591-dev-bridge' ) }
			>
				<div className="devbridge-two-columns">
					{ list(
						'write_extensions',
						__( 'Writable file extensions', 'lab591-dev-bridge' ),
						__(
							'.htaccess, .user.ini, .phar and alternative PHP extensions are never writable.',
							'lab591-dev-bridge'
						)
					) }
					{ list(
						'health_urls',
						__( 'Health check URLs', 'lab591-dev-bridge' ),
						__(
							'Checked after every deploy. Empty = home page. Only URLs of this site (or network).',
							'lab591-dev-bridge'
						),
						'https://…'
					) }
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Also check the back end after each deploy',
							'lab591-dev-bridge'
						) }
						help={ __(
							'Login page and an admin-ajax request (which runs admin_init): a fatal error that only breaks wp-admin triggers the rollback too.',
							'lab591-dev-bridge'
						) }
						checked={ draft.health_backend }
						onChange={ ( on: boolean ) =>
							set( 'health_backend', on )
						}
					/>
					{ number(
						'retention_releases',
						__( 'Releases kept', 'lab591-dev-bridge' ),
						__( 'Older backups are deleted.', 'lab591-dev-bridge' )
					) }
					{ number(
						'audit_retention_days',
						__( 'Audit log days', 'lab591-dev-bridge' )
					) }
				</div>
			</Section>

			<NotificationsSection
				draft={ draft }
				set={ set }
				dirty={ dirty }
				notify={ notify }
				emails={ list(
					'notify_emails',
					__( 'Email addresses', 'lab591-dev-bridge' ),
					__( 'Empty = no emails.', 'lab591-dev-bridge' )
				) }
			/>

			<Section
				title={ __( 'Durations and limits', 'lab591-dev-bridge' ) }
				description={ __(
					'Defaults suit most sites.',
					'lab591-dev-bridge'
				) }
			>
				<div className="devbridge-two-columns">
					{ number(
						'max_read_hours',
						__( 'Longest read mode (hours)', 'lab591-dev-bridge' )
					) }
					{ number(
						'max_write_hours',
						__( 'Longest write mode (hours)', 'lab591-dev-bridge' )
					) }
				</div>
				<div className="devbridge-limits">
					{ Object.keys( data.limits ).map( ( key ) => {
						const meta = LIMIT_LABELS[ key ] ?? { label: key };
						const value = draft.limits[ key ] ?? data.limits[ key ];
						return (
							<NumberField
								key={ key }
								label={ meta.label }
								value={ value }
								help={
									meta.bytes
										? formatBytes( Number( value ) )
										: undefined
								}
								onChange={ ( v ) =>
									set( 'limits', {
										...draft.limits,
										[ key ]: v,
									} )
								}
							/>
						);
					} ) }
				</div>
			</Section>

			<PageLockSection
				enabled={ pageLock }
				onChange={ onPageLock }
				notify={ notify }
			/>

			<div className={ `devbridge-savebar${ dirty ? ' is-dirty' : '' }` }>
				<span className="devbridge-savebar__status">
					{ dirty
						? __( 'Unsaved changes', 'lab591-dev-bridge' )
						: __( 'All changes saved', 'lab591-dev-bridge' ) }
				</span>
				{ dirty && (
					<Button
						variant="tertiary"
						onClick={ () => setDraft( data.settings ) }
						disabled={ saving }
					>
						{ __( 'Discard', 'lab591-dev-bridge' ) }
					</Button>
				) }
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ saving }
					disabled={ saving || ! dirty }
				>
					{ __( 'Save settings', 'lab591-dev-bridge' ) }
				</Button>
			</div>
		</div>
	);
}

function NewFolder( {
	containers,
	onCreated,
}: {
	containers: string[];
	onCreated: ( result: SettingsData ) => void;
} ) {
	const [ container, setContainer ] = useState(
		containers[ 1 ] ?? containers[ 0 ] ?? ''
	);
	const [ path, setPath ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const create = async () => {
		setBusy( true );
		setError( '' );
		try {
			onCreated(
				await post< SettingsData >( 'devbridge_mkdir', {
					container,
					path,
				} )
			);
			setPath( '' );
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="devbridge-newfolder">
			<h3 className="devbridge-newfolder__title">
				{ __( 'New folder', 'lab591-dev-bridge' ) }
			</h3>
			<p className="devbridge-muted devbridge-small">
				{ __(
					'For a plugin or theme that does not exist yet: the folder is created empty and made writable. After "wpdev pull" you can ask Claude, for example, to create a plugin or a child theme there.',
					'lab591-dev-bridge'
				) }
			</p>
			<form
				className="devbridge-newfolder__form"
				onSubmit={ ( event ) => {
					event.preventDefault();
					if ( path.trim() ) {
						create();
					}
				} }
			>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'In', 'lab591-dev-bridge' ) }
					value={ container }
					options={ containers.map( ( c ) => ( {
						value: c,
						label: `${ c }/`,
					} ) ) }
					onChange={ ( v: string ) => setContainer( v ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Name', 'lab591-dev-bridge' ) }
					value={ path }
					placeholder={ __(
						'my-plugin or my-theme/blocks',
						'lab591-dev-bridge'
					) }
					onChange={ ( v: string ) => setPath( v ) }
				/>
				<Button
					variant="secondary"
					type="submit"
					isBusy={ busy }
					disabled={ busy || ! path.trim() }
					__next40pxDefaultSize
				>
					{ __( 'Create and select', 'lab591-dev-bridge' ) }
				</Button>
			</form>
			{ error && <p className="devbridge-field__warning">{ error }</p> }
		</div>
	);
}

function NotificationsSection( {
	draft,
	set,
	dirty,
	notify,
	emails,
}: {
	draft: Settings;
	set: < K extends keyof Settings >( key: K, value: Settings[ K ] ) => void;
	dirty: boolean;
	notify: Notify;
	emails: ReactNode;
} ) {
	const [ testing, setTesting ] = useState( false );
	const events: { value: string; label: string }[] = [
		{
			value: 'deploy',
			label: __(
				'Deploys (and automatic rollbacks)',
				'lab591-dev-bridge'
			),
		},
		{
			value: 'rollback',
			label: __( 'Manual rollbacks', 'lab591-dev-bridge' ),
		},
		{
			value: 'write',
			label: __( 'Write mode enabled', 'lab591-dev-bridge' ),
		},
	];
	const test = async () => {
		if ( dirty ) {
			notify(
				__(
					'Save the settings first: the test uses the saved ones.',
					'lab591-dev-bridge'
				)
			);
			return;
		}
		setTesting( true );
		try {
			const result = await post< { message: string } >(
				'devbridge_notify',
				{}
			);
			notify( result.message );
		} catch ( e ) {
			notify( errorMessage( e ) );
		} finally {
			setTesting( false );
		}
	};
	return (
		<Section
			title={ __( 'Notifications', 'lab591-dev-bridge' ) }
			description={ __(
				'Know when something changes: email and/or a webhook (Slack, Discord or any service accepting JSON). Messages contain site, user, release, result and file paths, never file contents.',
				'lab591-dev-bridge'
			) }
			actions={
				<Button
					variant="secondary"
					size="compact"
					isBusy={ testing }
					disabled={
						testing ||
						( ! draft.notify_emails.length &&
							! draft.notify_webhook )
					}
					onClick={ test }
				>
					{ __( 'Send test', 'lab591-dev-bridge' ) }
				</Button>
			}
		>
			<div className="devbridge-two-columns">
				{ emails }
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="url"
					label={ __( 'Webhook URL', 'lab591-dev-bridge' ) }
					value={ draft.notify_webhook }
					placeholder="https://hooks.slack.com/services/…"
					onChange={ ( v: string ) =>
						set( 'notify_webhook', v.trim() )
					}
					help={ __(
						'https only. Receives a JSON with "text" (Slack), "content" (Discord) and the structured fields.',
						'lab591-dev-bridge'
					) }
				/>
			</div>
			<fieldset className="devbridge-field devbridge-events">
				<legend className="devbridge-field__label">
					{ __( 'Notify', 'lab591-dev-bridge' ) }
				</legend>
				{ events.map( ( e ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ e.value }
						label={ e.label }
						checked={ draft.notify_events.includes( e.value ) }
						onChange={ ( on: boolean ) =>
							set(
								'notify_events',
								on
									? [ ...draft.notify_events, e.value ]
									: draft.notify_events.filter(
											( x ) => x !== e.value
										)
							)
						}
					/>
				) ) }
			</fieldset>
		</Section>
	);
}
