import { __, _n, sprintf } from '@wordpress/i18n';
import { boot } from './api';

const locale = ( boot?.locale || 'en_US' ).replace( '_', '-' );

export function formatDateTime( timestamp: number ): string {
	return new Intl.DateTimeFormat( locale, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} ).format( new Date( timestamp * 1000 ) );
}

/**
 * "2 h 15 min" style remaining time.
 *
 * @param {number} expiresAt Unix timestamp of the expiry.
 * @param {number} now       Current Unix timestamp.
 */
export function formatRemaining( expiresAt: number, now: number ): string {
	const minutes = Math.max( 0, Math.round( ( expiresAt - now ) / 60 ) );
	if ( minutes < 60 ) {
		return sprintf(
			/* translators: %d: minutes. */
			_n( '%d minute', '%d minutes', minutes, 'lab591-dev-bridge' ),
			minutes
		);
	}
	const hours = Math.floor( minutes / 60 );
	const rest = minutes % 60;
	if ( rest ) {
		return sprintf(
			/* translators: 1: hours, 2: minutes. */
			__( '%1$d h %2$d min', 'lab591-dev-bridge' ),
			hours,
			rest
		);
	}
	return sprintf(
		/* translators: %d: hours. */
		_n( '%d hour', '%d hours', hours, 'lab591-dev-bridge' ),
		hours
	);
}

export function formatBytes( bytes: number ): string {
	if ( bytes < 1024 ) {
		return `${ bytes } B`;
	}
	const units = [ 'KB', 'MB', 'GB' ];
	let value = bytes / 1024;
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit++;
	}
	return `${ new Intl.NumberFormat( locale, { maximumFractionDigits: 1 } ).format( value ) } ${ units[ unit ] }`;
}
