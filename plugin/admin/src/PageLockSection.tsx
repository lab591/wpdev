/**
 * Optional password in front of the whole Dev Bridge page (0.7.0). Saved on its own, not with the
 * other settings: it takes effect immediately.
 */
import { Button, Notice, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { Notify } from './App';
import { errorMessage, post } from './api';
import { Section } from './components';

const MIN_LENGTH = 10;

interface Props {
	enabled: boolean;
	onChange: ( enabled: boolean ) => void;
	notify: Notify;
}

interface LockResult {
	message?: string;
	pageLock: boolean;
}

export default function PageLockSection( {
	enabled,
	onChange,
	notify,
}: Props ) {
	const [ current, setCurrent ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ confirm, setConfirm ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const run = async ( payload: Record< string, string > ) => {
		setBusy( true );
		setError( '' );
		try {
			const result = await post< LockResult >(
				'devbridge_lock',
				payload
			);
			setCurrent( '' );
			setPassword( '' );
			setConfirm( '' );
			onChange( result.pageLock );
			if ( result.message ) {
				notify( result.message );
			}
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};

	const field = (
		label: string,
		value: string,
		onValue: ( v: string ) => void,
		autoComplete: string,
		help?: string
	) => (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			type="password"
			label={ label }
			value={ value }
			autoComplete={ autoComplete }
			help={ help }
			onChange={ onValue }
		/>
	);

	const canSet =
		password.length >= MIN_LENGTH &&
		password === confirm &&
		( ! enabled || current !== '' );

	return (
		<Section
			title={ __( 'Page protection', 'lab591-dev-bridge' ) }
			description={ __(
				'Put this whole page behind a password, useful when the site owner is an administrator but not a developer: without the password the page shows nothing. It prevents accidental changes, it does not stop an administrator who wants to deactivate plugins. Forgotten password: "wp devbridge lock --clear" on the server.',
				'lab591-dev-bridge'
			) }
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<p className="devbridge-help">
				{ enabled
					? __(
							'Active: the page asks for the password in every new login session and after 30 minutes of inactivity.',
							'lab591-dev-bridge'
						)
					: __( 'Not active.', 'lab591-dev-bridge' ) }
			</p>
			<div className="devbridge-two-columns">
				{ enabled &&
					field(
						__( 'Current password', 'lab591-dev-bridge' ),
						current,
						setCurrent,
						'current-password'
					) }
				{ field(
					enabled
						? __( 'New password', 'lab591-dev-bridge' )
						: __( 'Password', 'lab591-dev-bridge' ),
					password,
					setPassword,
					'new-password',
					sprintf(
						/* translators: %d: minimum number of characters. */
						__( 'At least %d characters.', 'lab591-dev-bridge' ),
						MIN_LENGTH
					)
				) }
				{ field(
					__( 'Repeat the password', 'lab591-dev-bridge' ),
					confirm,
					setConfirm,
					'new-password'
				) }
			</div>
			<div className="devbridge-actions">
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || ! canSet }
					onClick={ () =>
						run( { op: 'set', current, password, confirm } )
					}
				>
					{ enabled
						? __( 'Change password', 'lab591-dev-bridge' )
						: __( 'Protect the page', 'lab591-dev-bridge' ) }
				</Button>
				{ enabled && (
					<Button
						variant="secondary"
						isDestructive
						disabled={ busy || current === '' }
						onClick={ () => run( { op: 'remove', current } ) }
					>
						{ __( 'Remove protection', 'lab591-dev-bridge' ) }
					</Button>
				) }
			</div>
		</Section>
	);
}
