/**
 * Calls to AdminController through admin-ajax (cookie + nonce). Deliberately not the REST API:
 * see AdminController for the reason (security invariant 2).
 */
import { __, sprintf } from '@wordpress/i18n';
import type { Bootstrap } from './types';

declare global {
	interface Window {
		devbridgeAdmin: Bootstrap;
	}
}

export const boot: Bootstrap = window.devbridgeAdmin;

export class ApiError extends Error {}

async function unwrap< T >( response: Response ): Promise< T > {
	let json: { success?: boolean; data?: unknown } | null = null;
	try {
		json = ( await response.json() ) as {
			success?: boolean;
			data?: unknown;
		};
	} catch {
		json = null;
	}
	if ( ! json || json.success !== true ) {
		const data = json?.data as
			{ message?: string; locked?: boolean } | undefined;
		if ( data?.locked ) {
			// Unlock expired (inactivity, logout elsewhere): the page shows the password form.
			window.location.reload();
		}
		throw new ApiError(
			data?.message ??
				sprintf(
					/* translators: %d: HTTP status code. */
					__(
						'The request failed (HTTP %d). Reload the page and try again.',
						'lab591-dev-bridge'
					),
					response.status
				)
		);
	}
	return json.data as T;
}

export async function get< T >(
	action: string,
	params: Record< string, string | number > = {}
): Promise< T > {
	const url = new URL( boot.ajaxUrl, window.location.href );
	url.searchParams.set( 'action', action );
	url.searchParams.set( '_ajax_nonce', boot.nonce );
	for ( const [ key, value ] of Object.entries( params ) ) {
		url.searchParams.set( key, String( value ) );
	}
	return unwrap< T >(
		await fetch( url.toString(), { credentials: 'same-origin' } )
	);
}

export async function post< T >(
	action: string,
	payload: unknown
): Promise< T > {
	const body = new URLSearchParams( {
		action,
		_ajax_nonce: boot.nonce,
		payload: JSON.stringify( payload ),
	} );
	return unwrap< T >(
		await fetch( boot.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} )
	);
}

export function errorMessage( error: unknown ): string {
	return error instanceof Error ? error.message : String( error );
}
