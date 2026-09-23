/**
 * Builds languages/lab591-dev-bridge-<locale>.po from the POT and tools/translations/<locale>.json
 * (English source -> translation; plurals as [singular, plural]). Dev tool only.
 *
 * Usage: node tools/translations/make-po.mjs it_IT   (normally through `npm run i18n`)
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const locale = process.argv[ 2 ] ?? 'it_IT';
const pluralForms = { it_IT: 'nplurals=2; plural=(n != 1);' };

const pot = readFileSync( join( root, 'languages', 'lab591-dev-bridge.pot' ), 'utf8' );
const translations = JSON.parse( readFileSync( join( root, 'tools', 'translations', `${ locale }.json` ), 'utf8' ) );

const blocks = pot.trim().split( /\n\n/ );
let header = blocks[ 0 ].replace( /"Language: [^"]*"/, `"Language: ${ locale }\\n"` );
if ( ! header.includes( '"Language:' ) ) {
	header = header.replace( '"MIME-Version', `"Language: ${ locale }\\n"\n"MIME-Version` );
}
header = header.replace( '"Content-Type', `"Plural-Forms: ${ pluralForms[ locale ] ?? 'nplurals=2; plural=(n != 1);' }\\n"\n"Content-Type` );

const out = [ header ];
const missing = [];
for ( const block of blocks.slice( 1 ) ) {
	const lines = block.split( '\n' );
	const read = ( prefix ) => {
		const line = lines.find( ( l ) => l.startsWith( prefix ) );
		return line === undefined ? undefined : JSON.parse( line.slice( prefix.length ) );
	};
	const msgid = read( 'msgid ' );
	const plural = read( 'msgid_plural ' );
	if ( msgid === undefined ) {
		continue;
	}
	const value = translations[ msgid ];
	if ( value === undefined ) {
		missing.push( msgid );
	}
	const kept = lines.filter( ( l ) => ! l.startsWith( 'msgstr' ) );
	if ( plural !== undefined ) {
		const forms = Array.isArray( value ) ? value : [ '', '' ];
		forms.forEach( ( form, i ) => kept.push( `msgstr[${ i }] ${ JSON.stringify( form ) }` ) );
	} else {
		kept.push( `msgstr ${ JSON.stringify( typeof value === 'string' ? value : '' ) }` );
	}
	out.push( kept.join( '\n' ) );
}

writeFileSync( join( root, 'languages', `lab591-dev-bridge-${ locale }.po` ), out.join( '\n\n' ) + '\n', 'utf8' );
console.log( `${ locale }: ${ blocks.length - 1 - missing.length } translated, ${ missing.length } missing` );
missing.forEach( ( m ) => console.log( `  missing: ${ m }` ) );
process.exitCode = missing.length ? 1 : 0;
