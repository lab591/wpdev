/**
 * Keeping the settings when the plugin is deleted, and settings export/import as JSON (0.7.1).
 * The toggle is saved with the other settings; the import saves on its own, like the form.
 */
import {
	Button,
	Notice,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { Notify } from './App';
import { errorMessage, get, post } from './api';
import { Section } from './components';
import type { SettingsData } from './types';

interface Props {
	keep: boolean;
	onKeep: ( keep: boolean ) => void;
	dirty: boolean;
	notify: Notify;
	onImported: ( result: SettingsData ) => void;
}

export default function TransferSection( {
	keep,
	onKeep,
	dirty,
	notify,
	onImported,
}: Props ) {
	const [ text, setText ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const exportSettings = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await get< { export: unknown } >(
				'devbridge_export'
			);
			const json = JSON.stringify( result.export, null, 2 );
			setText( json );
			try {
				await window.navigator.clipboard.writeText( json );
				notify(
					__(
						'Settings exported and copied to the clipboard.',
						'lab591-dev-bridge'
					)
				);
			} catch {
				notify(
					__(
						'Settings exported: copy the text below.',
						'lab591-dev-bridge'
					)
				);
			}
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};

	const importSettings = async () => {
		setBusy( true );
		setError( '' );
		try {
			const result = await post< SettingsData >( 'devbridge_import', {
				json: text,
			} );
			setText( '' );
			onImported( result );
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Section title={ __( 'Keep, export and import', 'lab591-dev-bridge' ) }>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Keep the settings when the plugin is deleted',
					'lab591-dev-bridge'
				) }
				help={ __(
					'Deleting the plugin then keeps these settings and the page password, so a later reinstall starts from them. Releases, backups and the audit log are deleted anyway. Saved with the other settings.',
					'lab591-dev-bridge'
				) }
				checked={ keep }
				onChange={ onKeep }
			/>
			<p className="devbridge-help">
				{ __(
					'Export copies the saved settings as JSON (never the development mode, the page password or the audit log); paste it in another site and import it. Allowed users are matched by login and folders that do not exist there are discarded. Settings missing from the text keep their value.',
					'lab591-dev-bridge'
				) }
			</p>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }
			<TextareaControl
				__nextHasNoMarginBottom
				className="devbridge-transfer"
				label={ __( 'Settings (JSON)', 'lab591-dev-bridge' ) }
				value={ text }
				rows={ 8 }
				spellCheck={ false }
				onChange={ setText }
			/>
			<div className="devbridge-actions">
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ exportSettings }
				>
					{ __( 'Export', 'lab591-dev-bridge' ) }
				</Button>
				<Button
					variant="primary"
					disabled={ busy || dirty || text.trim() === '' }
					onClick={ importSettings }
				>
					{ __( 'Import', 'lab591-dev-bridge' ) }
				</Button>
				{ dirty && (
					<span className="devbridge-help">
						{ __(
							'Save or discard your changes before importing.',
							'lab591-dev-bridge'
						) }
					</span>
				) }
			</div>
		</Section>
	);
}
