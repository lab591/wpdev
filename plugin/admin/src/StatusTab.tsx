import { Button, ExternalLink, Notice } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import type { Notify } from './App';
import { boot, errorMessage, get, post } from './api';
import {
	CopyableCode,
	modeLabel,
	NumberField,
	Section,
	Segmented,
	StatusIcon,
	useNow,
} from './components';
import { formatDateTime, formatRemaining } from './format';
import type { StatusData } from './types';

interface Props {
	status: StatusData;
	setStatus: ( status: StatusData ) => void;
	notify: Notify;
	openSettings: () => void;
}

export default function StatusTab( {
	status,
	setStatus,
	notify,
	openSettings,
}: Props ) {
	const [ checking, setChecking ] = useState( false );
	const checkAgain = async () => {
		setChecking( true );
		try {
			setStatus(
				await get< StatusData >( 'devbridge_status', { refresh: 1 } )
			);
			notify( __( 'Checks updated.', 'lab591-dev-bridge' ) );
		} catch ( e ) {
			notify( errorMessage( e ) );
		} finally {
			setChecking( false );
		}
	};
	const problems = status.checks.filter(
		( c ) => c.status === 'error' || c.status === 'warning'
	).length;
	return (
		<div className="devbridge-grid">
			<ModeCard
				status={ status }
				setStatus={ setStatus }
				notify={ notify }
			/>
			<Section
				title={ __( 'Setup checks', 'lab591-dev-bridge' ) }
				description={
					problems
						? sprintf(
								/* translators: %d: number of checks needing attention. */
								_n(
									'%d item needs attention.',
									'%d items need attention.',
									problems,
									'lab591-dev-bridge'
								),
								problems
							)
						: __( 'Everything is ready.', 'lab591-dev-bridge' )
				}
				actions={
					<Button
						variant="secondary"
						size="compact"
						icon="update"
						isBusy={ checking }
						disabled={ checking }
						onClick={ checkAgain }
					>
						{ __( 'Check again', 'lab591-dev-bridge' ) }
					</Button>
				}
			>
				<ul className="devbridge-checks">
					{ status.checks.map( ( check ) => (
						<li
							key={ check.id }
							className={ `devbridge-check is-${ check.status }` }
						>
							<StatusIcon status={ check.status } />
							<div className="devbridge-check__body">
								<strong>{ check.title }</strong>
								{ check.detail && <p>{ check.detail }</p> }
								{ check.code && (
									<CopyableCode code={ check.code } />
								) }
							</div>
						</li>
					) ) }
				</ul>
			</Section>
			{ status.preview.active && (
				<PreviewCard
					status={ status }
					setStatus={ setStatus }
					notify={ notify }
				/>
			) }
			<ConnectCard />
			<Section
				title={ __( 'Writable folders', 'lab591-dev-bridge' ) }
				description={ __(
					'The only folders Claude can change. The companion reads this list from the site.',
					'lab591-dev-bridge'
				) }
				actions={
					<Button
						variant="secondary"
						size="compact"
						onClick={ openSettings }
					>
						{ __( 'Change', 'lab591-dev-bridge' ) }
					</Button>
				}
			>
				{ status.writableRoots.length ? (
					<ul className="devbridge-chips">
						{ status.writableRoots.map( ( root ) => (
							<li key={ root } className="devbridge-chip">
								{ root }
							</li>
						) ) }
					</ul>
				) : (
					<p className="devbridge-muted">
						{ __(
							'None yet: Claude can read the site but not deploy.',
							'lab591-dev-bridge'
						) }
					</p>
				) }
			</Section>
			<Section
				className="devbridge-grid__wide"
				title={ __( 'Recent releases', 'lab591-dev-bridge' ) }
				description={ __(
					'Every deploy keeps a backup. Roll back with "wpdev rollback" or "wp devbridge rollback".',
					'lab591-dev-bridge'
				) }
			>
				{ status.releases.length ? (
					<div className="devbridge-table-wrap">
						<table className="devbridge-table">
							<thead>
								<tr>
									<th>
										{ __( 'Release', 'lab591-dev-bridge' ) }
									</th>
									<th>
										{ __( 'Date', 'lab591-dev-bridge' ) }
									</th>
									<th>
										{ __( 'User', 'lab591-dev-bridge' ) }
									</th>
									<th className="is-numeric">
										{ __( 'Written', 'lab591-dev-bridge' ) }
									</th>
									<th className="is-numeric">
										{ __( 'Deleted', 'lab591-dev-bridge' ) }
									</th>
									<th>
										{ __( 'Result', 'lab591-dev-bridge' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ status.releases.map( ( r ) => (
									<tr key={ r.id }>
										<td>
											<code>{ r.id }</code>
										</td>
										<td>
											{ formatDateTime( r.createdAt ) }
										</td>
										<td>{ r.user }</td>
										<td className="is-numeric">
											{ r.written }
										</td>
										<td className="is-numeric">
											{ r.deleted }
										</td>
										<td>
											<span
												className={ `devbridge-pill is-${ r.status.replace( /[^a-z_]/g, '' ) }` }
											>
												{ r.status }
											</span>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) : (
					<p className="devbridge-muted">
						{ __( 'No deploys yet.', 'lab591-dev-bridge' ) }
					</p>
				) }
			</Section>
		</div>
	);
}

function ModeCard( {
	status,
	setStatus,
	notify,
}: Omit< Props, 'openSettings' > ) {
	const now = useNow();
	const { mode } = status;
	const active = mode.mode !== 'off' && mode.expiresAt > now;
	const [ requested, setRequested ] = useState< 'read' | 'write' >(
		mode.mode === 'write' ? 'write' : 'read'
	);
	const [ hours, setHours ] = useState( 4 );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const max = mode.maxHours[ requested ];

	const send = async ( payload: object ) => {
		setBusy( true );
		setError( '' );
		try {
			const result = await post< StatusData >(
				'devbridge_mode',
				payload
			);
			setStatus( result );
			if ( result.message ) {
				notify( result.message );
			}
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Section
			title={ __( 'Development mode', 'lab591-dev-bridge' ) }
			description={ __(
				'Claude can connect only while a mode is active. It switches off by itself when the time is up.',
				'lab591-dev-bridge'
			) }
		>
			<div
				className={ `devbridge-mode is-${ active ? mode.mode : 'off' }` }
			>
				<span className="devbridge-mode__state">
					{ modeLabel( active ? mode.mode : 'off' ) }
				</span>
				<span className="devbridge-mode__detail">
					{ active
						? sprintf(
								/* translators: 1: remaining time, 2: date and time. */
								__(
									'%1$s left · until %2$s',
									'lab591-dev-bridge'
								),
								formatRemaining( mode.expiresAt, now ),
								formatDateTime( mode.expiresAt )
							)
						: __(
								'No access: the API answers only with the mode.',
								'lab591-dev-bridge'
							) }
				</span>
			</div>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<div className="devbridge-mode-form">
				<Segmented
					label={ __( 'Access', 'lab591-dev-bridge' ) }
					value={ requested }
					options={ [
						{
							value: 'read',
							label: __( 'Read', 'lab591-dev-bridge' ),
						},
						{
							value: 'write',
							label: __( 'Read + write', 'lab591-dev-bridge' ),
						},
					] }
					onChange={ ( value ) =>
						setRequested( value === 'write' ? 'write' : 'read' )
					}
				/>
				<NumberField
					label={ __( 'Hours', 'lab591-dev-bridge' ) }
					min={ 1 }
					max={ max }
					value={ Math.min( hours, max ) }
					onChange={ setHours }
					help={ sprintf(
						/* translators: %d: maximum hours. */
						__( 'Up to %d hours.', 'lab591-dev-bridge' ),
						max
					) }
				/>
			</div>
			<div className="devbridge-actions">
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () =>
						send( {
							op: 'enable',
							mode: requested,
							hours: Math.min( hours, max ),
						} )
					}
				>
					{ active
						? __( 'Update', 'lab591-dev-bridge' )
						: __( 'Enable', 'lab591-dev-bridge' ) }
				</Button>
				{ active && (
					<Button
						variant="secondary"
						isDestructive
						disabled={ busy }
						onClick={ () => send( { op: 'disable' } ) }
					>
						{ __( 'Disable now', 'lab591-dev-bridge' ) }
					</Button>
				) }
			</div>
			<p className="devbridge-muted devbridge-small">
				{ __(
					'The mode can only be changed here or with WP-CLI (wp devbridge enable|disable): no API can change it.',
					'lab591-dev-bridge'
				) }
			</p>
		</Section>
	);
}

function ConnectCard() {
	return (
		<Section
			title={ __( 'Connect Claude Code', 'lab591-dev-bridge' ) }
			description={ __(
				'Three steps on your computer, in the folder of the local project.',
				'lab591-dev-bridge'
			) }
		>
			<ol className="devbridge-steps">
				<li>
					{ __(
						'Create an Application Password for your user.',
						'lab591-dev-bridge'
					) }{ ' ' }
					<ExternalLink href={ boot.profile }>
						{ __( 'Open your profile', 'lab591-dev-bridge' ) }
					</ExternalLink>
				</li>
				<li>
					{ __(
						'Save it in .env.local and initialize the project:',
						'lab591-dev-bridge'
					) }
					<CopyableCode
						code={ `echo "WPDEV_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx" > .env.local\nwpdev init --site ${ boot.siteUrl } --user ${ boot.user }` }
					/>
				</li>
				<li>
					{ __(
						'Download the writable folders, then open Claude Code in that folder:',
						'lab591-dev-bridge'
					) }
					<CopyableCode code="wpdev pull" />
				</li>
			</ol>
			<p className="devbridge-muted devbridge-small">
				{ __( 'REST endpoint:', 'lab591-dev-bridge' ) }{ ' ' }
				<code>{ boot.restUrl }</code>
			</p>
		</Section>
	);
}

function PreviewCard( {
	status,
	setStatus,
	notify,
}: Omit< Props, 'openSettings' > ) {
	const [ busy, setBusy ] = useState( false );
	const preview = status.preview;
	if ( ! preview.active ) {
		return null;
	}
	const act = async ( op: 'publish' | 'discard' ) => {
		setBusy( true );
		try {
			const result = await post< StatusData >( 'devbridge_preview', {
				op,
			} );
			setStatus( result );
			if ( result.message ) {
				notify( result.message );
			}
		} catch ( e ) {
			notify( errorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};
	return (
		<Section
			className="devbridge-grid__wide devbridge-preview-card"
			title={ __( 'Preview waiting', 'lab591-dev-bridge' ) }
			description={ __(
				'Changes visible only through the preview link: visitors still see the live site.',
				'lab591-dev-bridge'
			) }
		>
			<ul className="devbridge-chips">
				{ preview.units.map( ( unit ) => (
					<li key={ unit } className="devbridge-chip">
						{ unit }
					</li>
				) ) }
			</ul>
			<ul className="devbridge-preview-files">
				{ preview.files.slice( 0, 20 ).map( ( f ) => (
					<li key={ f }>
						<code>{ f }</code>
					</li>
				) ) }
			</ul>
			<p className="devbridge-muted devbridge-small">
				{ preview.expired
					? __(
							'The preview link expired: ask Claude (or run "wpdev preview") for a new one.',
							'lab591-dev-bridge'
						)
					: sprintf(
							/* translators: %s: date and time. */
							__(
								'The link sent to the developer works until %s.',
								'lab591-dev-bridge'
							),
							formatDateTime( preview.expires_at )
						) }
			</p>
			<div className="devbridge-actions">
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => act( 'publish' ) }
				>
					{ __( 'Publish', 'lab591-dev-bridge' ) }
				</Button>
				<Button
					variant="secondary"
					isDestructive
					disabled={ busy }
					onClick={ () => act( 'discard' ) }
				>
					{ __( 'Discard preview', 'lab591-dev-bridge' ) }
				</Button>
			</div>
		</Section>
	);
}
