/**
 * Checkbox tree of the folders that can be made writable. Sub-folders are loaded on demand;
 * what is listed as selectable is exactly what the server accepts (same validator as saving).
 */
import { Button, CheckboxControl, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { errorMessage, get } from './api';
import type { FolderItem, FolderList } from './types';

interface TreeProps {
	containers: string[];
	selected: string[];
	onChange: ( selected: string[] ) => void;
}

const lower = ( s: string ) => s.toLowerCase();
const isInside = ( path: string, root: string ) =>
	lower( path ).startsWith( lower( root ) + '/' );

export default function FolderTree( {
	containers,
	selected,
	onChange,
}: TreeProps ) {
	const toggle = ( path: string, checked: boolean ) => {
		const rest = selected.filter( ( s ) => lower( s ) !== lower( path ) );
		// Selecting a folder includes its sub-folders: drop the ones selected before.
		onChange(
			checked
				? [ ...rest.filter( ( s ) => ! isInside( s, path ) ), path ]
				: rest
		);
	};
	return (
		<div className="devbridge-tree">
			{ containers.map( ( container ) => (
				<div key={ container } className="devbridge-tree__container">
					<div className="devbridge-tree__container-name">
						{ container }/
					</div>
					<FolderLevel
						path={ container }
						selected={ selected }
						toggle={ toggle }
					/>
				</div>
			) ) }
		</div>
	);
}

function FolderLevel( {
	path,
	selected,
	toggle,
}: {
	path: string;
	selected: string[];
	toggle: ( path: string, checked: boolean ) => void;
} ) {
	const [ list, setList ] = useState< FolderList | null >( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let live = true;
		get< FolderList >( 'devbridge_folders', { path } )
			.then( ( data ) => live && setList( data ) )
			.catch( ( e ) => live && setError( errorMessage( e ) ) );
		return () => {
			live = false;
		};
	}, [ path ] );

	if ( error ) {
		return <p className="devbridge-tree__error">{ error }</p>;
	}
	if ( ! list ) {
		return (
			<div className="devbridge-tree__loading">
				<Spinner />
			</div>
		);
	}
	if ( ! list.items.length ) {
		return (
			<p className="devbridge-muted devbridge-tree__empty">
				{ __( 'No folders.', 'lab591-dev-bridge' ) }
			</p>
		);
	}
	return (
		<ul className="devbridge-tree__list">
			{ list.items.map( ( item ) => (
				<FolderNode
					key={ item.path }
					item={ item }
					selected={ selected }
					toggle={ toggle }
				/>
			) ) }
			{ list.truncated && (
				<li className="devbridge-muted">
					{ __( '… list truncated', 'lab591-dev-bridge' ) }
				</li>
			) }
		</ul>
	);
}

function FolderNode( {
	item,
	selected,
	toggle,
}: {
	item: FolderItem;
	selected: string[];
	toggle: ( path: string, checked: boolean ) => void;
} ) {
	const checked = selected.some( ( s ) => lower( s ) === lower( item.path ) );
	const included =
		! checked && selected.some( ( s ) => isInside( item.path, s ) );
	const hasSelectedInside = selected.some( ( s ) =>
		isInside( s, item.path )
	);
	const [ open, setOpen ] = useState( hasSelectedInside );

	return (
		<li
			className={ `devbridge-tree__node${ item.selectable ? '' : ' is-locked' }` }
		>
			<div className="devbridge-tree__row">
				<CheckboxControl
					__nextHasNoMarginBottom
					checked={ checked || included }
					disabled={ ! item.selectable || included }
					onChange={ ( value: boolean ) =>
						toggle( item.path, value )
					}
					className="devbridge-tree__check"
					label={ item.name }
				/>
				{ ( item.label || item.reason || included ) && (
					<span
						className={ `devbridge-tree__note${ item.reason ? ' is-reason' : '' }` }
					>
						{ item.reason ||
							( included
								? __( 'included', 'lab591-dev-bridge' )
								: item.label ) }
					</span>
				) }
				{ item.expandable && (
					<Button
						variant="link"
						className="devbridge-tree__expand"
						aria-expanded={ open }
						onClick={ () => setOpen( ! open ) }
					>
						{ open
							? __( 'Hide sub-folders', 'lab591-dev-bridge' )
							: __( 'Sub-folders', 'lab591-dev-bridge' ) }
					</Button>
				) }
			</div>
			{ open && item.expandable && (
				<FolderLevel
					path={ item.path }
					selected={ selected }
					toggle={ toggle }
				/>
			) }
		</li>
	);
}
