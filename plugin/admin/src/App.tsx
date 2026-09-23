import { Notice, SnackbarList, Spinner } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { boot, errorMessage, get } from './api';
import AuditTab from './AuditTab';
import { ModeBadge } from './components';
import SettingsTab from './SettingsTab';
import StatusTab from './StatusTab';
import type { StatusData } from './types';

export type Notify = ( message: string ) => void;

interface Toast {
	id: string;
	content: string;
}

const TAB_NAMES = [ 'status', 'settings', 'audit' ] as const;
type TabName = ( typeof TAB_NAMES )[ number ];

function initialTab(): TabName {
	const tab = new URLSearchParams( window.location.search ).get( 'tab' );
	return ( TAB_NAMES as readonly string[] ).includes( tab ?? '' )
		? ( tab as TabName )
		: 'status';
}

export default function App() {
	const [ status, setStatus ] = useState< StatusData | null >( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ toasts, setToasts ] = useState< Toast[] >( [] );
	const [ tab, setTabState ] = useState< TabName >( initialTab );

	const setTab = useCallback( ( name: TabName ) => {
		setTabState( name );
		const url = new URL( window.location.href );
		url.searchParams.set( 'tab', name );
		window.history.replaceState( null, '', url.toString() );
	}, [] );

	const notify: Notify = useCallback( ( content: string ) => {
		setToasts( ( list ) => [
			...list,
			{ id: `${ Date.now() }-${ Math.random() }`, content },
		] );
	}, [] );

	const refreshStatus = useCallback( async () => {
		try {
			setStatus( await get< StatusData >( 'devbridge_status' ) );
			setLoadError( '' );
		} catch ( error ) {
			setLoadError( errorMessage( error ) );
		}
	}, [] );

	useEffect( () => {
		refreshStatus();
	}, [ refreshStatus ] );

	const tabs: { name: TabName; title: string }[] = [
		{ name: 'status', title: __( 'Status', 'lab591-dev-bridge' ) },
		{ name: 'settings', title: __( 'Settings', 'lab591-dev-bridge' ) },
		{ name: 'audit', title: __( 'Audit log', 'lab591-dev-bridge' ) },
	];

	return (
		<div className="devbridge-app">
			<header className="devbridge-header">
				<div className="devbridge-header__brand">
					<span className="devbridge-header__logo" aria-hidden="true">
						{ '</>' }
					</span>
					<div>
						<h1 className="devbridge-header__title">Dev Bridge</h1>
						<p className="devbridge-header__subtitle">
							{ sprintf(
								/* translators: 1: "whole network" or empty, 2: plugin version. */
								__(
									'Claude Code ↔ WordPress%1$s · v%2$s',
									'lab591-dev-bridge'
								),
								boot.network
									? ` · ${ __( 'whole network', 'lab591-dev-bridge' ) }`
									: '',
								boot.version
							) }
						</p>
					</div>
				</div>
				{ status && <ModeBadge mode={ status.mode } /> }
			</header>

			<div
				className="devbridge-tabs"
				role="tablist"
				aria-label={ __( 'Dev Bridge sections', 'lab591-dev-bridge' ) }
			>
				{ tabs.map( ( t ) => (
					<button
						key={ t.name }
						type="button"
						role="tab"
						id={ `devbridge-tab-${ t.name }` }
						aria-selected={ tab === t.name }
						aria-controls="devbridge-panel"
						className={ `devbridge-tabs__tab${ tab === t.name ? ' is-active' : '' }` }
						onClick={ () => setTab( t.name ) }
					>
						{ t.title }
					</button>
				) ) }
			</div>

			{ loadError && (
				<Notice status="error" isDismissible={ false }>
					{ loadError }
				</Notice>
			) }

			<div
				id="devbridge-panel"
				role="tabpanel"
				aria-labelledby={ `devbridge-tab-${ tab }` }
				className="devbridge-panel"
			>
				{ tab === 'settings' && (
					<SettingsTab notify={ notify } onSaved={ refreshStatus } />
				) }
				{ tab === 'audit' && <AuditTab /> }
				{ tab === 'status' &&
					( status ? (
						<StatusTab
							status={ status }
							setStatus={ setStatus }
							notify={ notify }
							openSettings={ () => setTab( 'settings' ) }
						/>
					) : (
						! loadError && (
							<div className="devbridge-loading">
								<Spinner />
							</div>
						)
					) ) }
			</div>

			<SnackbarList
				className="devbridge-snackbars"
				notices={ toasts.map( ( t ) => ( {
					...t,
					spokenMessage: t.content,
				} ) ) }
				onRemove={ ( id: string ) =>
					setToasts( ( list ) => list.filter( ( t ) => t.id !== id ) )
				}
			/>
		</div>
	);
}
