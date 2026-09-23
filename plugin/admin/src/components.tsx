/** Small presentational pieces shared by the tabs. */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Dashicon,
	TextControl,
} from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { formatDateTime, formatRemaining } from './format';
import type { CheckStatus, ModeState } from './types';

export function useNow( intervalMs = 30000 ): number {
	const [ now, setNow ] = useState( () => Math.floor( Date.now() / 1000 ) );
	useEffect( () => {
		const id = window.setInterval(
			() => setNow( Math.floor( Date.now() / 1000 ) ),
			intervalMs
		);
		return () => window.clearInterval( id );
	}, [ intervalMs ] );
	return now;
}

export function modeLabel( mode: string ): string {
	if ( mode === 'read' ) {
		return __( 'Read', 'lab591-dev-bridge' );
	}
	if ( mode === 'write' ) {
		return __( 'Write', 'lab591-dev-bridge' );
	}
	return __( 'Off', 'lab591-dev-bridge' );
}

export function ModeBadge( { mode }: { mode: ModeState } ) {
	const now = useNow();
	const active = mode.mode !== 'off' && mode.expiresAt > now;
	return (
		<div
			className={ `devbridge-mode-badge is-${ active ? mode.mode : 'off' }` }
		>
			<span className="devbridge-mode-badge__dot" aria-hidden="true" />
			<span className="devbridge-mode-badge__label">
				{ modeLabel( active ? mode.mode : 'off' ) }
			</span>
			{ active && (
				<span
					className="devbridge-mode-badge__time"
					title={ formatDateTime( mode.expiresAt ) }
				>
					{ sprintf(
						/* translators: %s: remaining time. */
						__( '%s left', 'lab591-dev-bridge' ),
						formatRemaining( mode.expiresAt, now )
					) }
				</span>
			) }
		</div>
	);
}

const STATUS_ICON: Record< CheckStatus, string > = {
	ok: 'yes-alt',
	warning: 'warning',
	error: 'dismiss',
	info: 'info-outline',
};

export function StatusIcon( { status }: { status: CheckStatus } ) {
	return (
		<Dashicon
			className={ `devbridge-status-icon is-${ status }` }
			icon={ STATUS_ICON[ status ] as 'yes-alt' }
		/>
	);
}

export function Section( {
	title,
	description,
	actions,
	children,
	className = '',
}: {
	title: string;
	description?: ReactNode;
	actions?: ReactNode;
	children: ReactNode;
	className?: string;
} ) {
	return (
		<Card className={ `devbridge-card ${ className }` } size="medium">
			<CardHeader className="devbridge-card__header">
				<div>
					<h2 className="devbridge-card__title">{ title }</h2>
					{ description && (
						<p className="devbridge-card__description">
							{ description }
						</p>
					) }
				</div>
				{ actions }
			</CardHeader>
			<CardBody>{ children }</CardBody>
		</Card>
	);
}

export function CopyableCode( {
	code,
	label,
}: {
	code: string;
	label?: string;
} ) {
	const [ copied, setCopied ] = useState( false );
	const ref = useCopyToClipboard( code, () => {
		setCopied( true );
		window.setTimeout( () => setCopied( false ), 2000 );
	} );
	return (
		<div className="devbridge-code">
			<pre className="devbridge-code__text">{ code }</pre>
			<Button
				ref={ ref }
				variant="tertiary"
				size="small"
				icon={ copied ? 'yes' : 'admin-page' }
				label={ label ?? __( 'Copy', 'lab591-dev-bridge' ) }
				showTooltip
			>
				{ copied
					? __( 'Copied', 'lab591-dev-bridge' )
					: __( 'Copy', 'lab591-dev-bridge' ) }
			</Button>
		</div>
	);
}

/**
 * Numeric field on the stable TextControl; the text can be emptied while typing and is
 * committed only when it is a valid number.
 *
 * @param {Object}                  props          Component props.
 * @param {string}                  props.label    Label.
 * @param {number}                  props.value    Current value.
 * @param {(value: number) => void} props.onChange Receives the new number.
 * @param {number}                  props.min      Minimum.
 * @param {number}                  props.max      Maximum (optional).
 * @param {string}                  props.help     Help text (optional).
 */
export function NumberField( {
	label,
	value,
	onChange,
	min = 1,
	max,
	help,
}: {
	label: string;
	value: number;
	onChange: ( value: number ) => void;
	min?: number;
	max?: number;
	help?: string;
} ) {
	const [ text, setText ] = useState( String( value ) );
	useEffect( () => {
		setText( ( current ) =>
			Number( current ) === value ? current : String( value )
		);
	}, [ value ] );
	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			type="number"
			label={ label }
			value={ text }
			min={ min }
			max={ max }
			help={ help }
			onChange={ ( next: string ) => {
				setText( next );
				const n = Number( next );
				if ( next.trim() !== '' && Number.isFinite( n ) ) {
					onChange( Math.max( min, max ? Math.min( max, n ) : n ) );
				}
			} }
			onBlur={ () => setText( String( value ) ) }
		/>
	);
}

/**
 * Mutually exclusive options as a row of buttons.
 *
 * @param {Object}                  props          Component props.
 * @param {string}                  props.label    Group label.
 * @param {string}                  props.value    Selected value.
 * @param {Array}                   props.options  Options ({ value, label }).
 * @param {(value: string) => void} props.onChange Receives the selected value.
 */
export function Segmented( {
	label,
	value,
	options,
	onChange,
}: {
	label: string;
	value: string;
	options: { value: string; label: string }[];
	onChange: ( value: string ) => void;
} ) {
	return (
		<div className="devbridge-segmented">
			<span className="devbridge-field__label">{ label }</span>
			<div
				className="devbridge-segmented__buttons"
				role="group"
				aria-label={ label }
			>
				{ options.map( ( option ) => (
					<Button
						key={ option.value }
						__next40pxDefaultSize
						variant={
							option.value === value ? 'primary' : 'secondary'
						}
						aria-pressed={ option.value === value }
						onClick={ () => onChange( option.value ) }
					>
						{ option.label }
					</Button>
				) ) }
			</div>
		</div>
	);
}
