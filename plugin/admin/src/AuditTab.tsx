import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { boot, errorMessage, get } from './api';
import { Section } from './components';
import { formatBytes } from './format';
import type { AuditData } from './types';

interface Filters {
	endpoint: string;
	blog_id: string;
	user_id: string;
	status: string;
	from: string;
	to: string;
}

const EMPTY: Filters = {
	endpoint: '',
	blog_id: '',
	user_id: '',
	status: '',
	from: '',
	to: '',
};

export default function AuditTab() {
	const [ filters, setFilters ] = useState< Filters >( EMPTY );
	const [ applied, setApplied ] = useState< Filters >( EMPTY );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState< AuditData | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	const load = useCallback( async ( f: Filters, p: number ) => {
		setLoading( true );
		setError( '' );
		try {
			const params: Record< string, string | number > = { page: p };
			for ( const [ key, value ] of Object.entries( f ) ) {
				if ( value ) {
					params[ key ] = value;
				}
			}
			setData( await get< AuditData >( 'devbridge_audit', params ) );
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		load( applied, page );
	}, [ applied, page, load ] );

	const set = ( key: keyof Filters, value: string ) =>
		setFilters( { ...filters, [ key ]: value } );
	const apply = () => {
		setPage( 1 );
		setApplied( { ...filters } );
	};
	const network = boot.network && data?.sites;

	return (
		<Section
			title={ __( 'Audit log', 'lab591-dev-bridge' ) }
			description={ __(
				'Every API request: who, from where, what and with which result. File contents and passwords are never logged.',
				'lab591-dev-bridge'
			) }
			actions={
				<Button
					variant="tertiary"
					icon="update"
					onClick={ () => load( applied, page ) }
					disabled={ loading }
					label={ __( 'Refresh', 'lab591-dev-bridge' ) }
					showTooltip
				/>
			}
		>
			<form
				className="devbridge-filters"
				onSubmit={ ( event ) => {
					event.preventDefault();
					apply();
				} }
			>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Endpoint', 'lab591-dev-bridge' ) }
					value={ filters.endpoint }
					options={ [
						{ value: '', label: __( 'All', 'lab591-dev-bridge' ) },
						...boot.endpoint.map( ( e ) => ( {
							value: e,
							label: e,
						} ) ),
					] }
					onChange={ ( v: string ) => set( 'endpoint', v ) }
				/>
				{ network && (
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Site', 'lab591-dev-bridge' ) }
						value={ filters.blog_id }
						options={ [
							{
								value: '',
								label: __( 'All', 'lab591-dev-bridge' ),
							},
							...Object.entries( data?.sites ?? {} ).map(
								( [ id, label ] ) => ( { value: id, label } )
							),
						] }
						onChange={ ( v: string ) => set( 'blog_id', v ) }
					/>
				) }
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Result', 'lab591-dev-bridge' ) }
					value={ filters.status }
					options={
						[
							{
								value: '',
								label: __( 'All', 'lab591-dev-bridge' ),
							},
							{
								value: 'ok',
								label: __( 'Successful', 'lab591-dev-bridge' ),
							},
							{
								value: 'errors',
								label: __( 'Errors', 'lab591-dev-bridge' ),
							},
						] as { value: string; label: string }[]
					}
					onChange={ ( v: string ) => set( 'status', v ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="number"
					label={ __( 'User ID', 'lab591-dev-bridge' ) }
					value={ filters.user_id }
					onChange={ ( v: string ) => set( 'user_id', v ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="date"
					label={ __( 'From', 'lab591-dev-bridge' ) }
					value={ filters.from }
					onChange={ ( v: string ) => set( 'from', v ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					type="date"
					label={ __( 'To', 'lab591-dev-bridge' ) }
					value={ filters.to }
					onChange={ ( v: string ) => set( 'to', v ) }
				/>
				<div className="devbridge-filters__buttons">
					<Button
						variant="primary"
						type="submit"
						__next40pxDefaultSize
					>
						{ __( 'Filter', 'lab591-dev-bridge' ) }
					</Button>
					<Button
						variant="tertiary"
						__next40pxDefaultSize
						onClick={ () => {
							setFilters( EMPTY );
							setPage( 1 );
							setApplied( EMPTY );
						} }
					>
						{ __( 'Reset', 'lab591-dev-bridge' ) }
					</Button>
				</div>
			</form>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<div
				className={ `devbridge-table-wrap${ loading ? ' is-loading' : '' }` }
			>
				{ loading && ! data && (
					<div className="devbridge-loading">
						<Spinner />
					</div>
				) }
				{ data && (
					<table className="devbridge-table devbridge-table--audit">
						<thead>
							<tr>
								<th>
									{ __( 'Date (UTC)', 'lab591-dev-bridge' ) }
								</th>
								{ network && (
									<th>
										{ __( 'Site', 'lab591-dev-bridge' ) }
									</th>
								) }
								<th>{ __( 'User', 'lab591-dev-bridge' ) }</th>
								<th>{ __( 'IP', 'lab591-dev-bridge' ) }</th>
								<th>
									{ __( 'Endpoint', 'lab591-dev-bridge' ) }
								</th>
								<th>{ __( 'Mode', 'lab591-dev-bridge' ) }</th>
								<th>{ __( 'Paths', 'lab591-dev-bridge' ) }</th>
								<th className="is-numeric">
									{ __( 'Size', 'lab591-dev-bridge' ) }
								</th>
								<th className="is-numeric">
									{ __( 'Result', 'lab591-dev-bridge' ) }
								</th>
								<th className="is-numeric">
									{ __( 'Time', 'lab591-dev-bridge' ) }
								</th>
								<th>
									{ __( 'Release', 'lab591-dev-bridge' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ data.rows.length === 0 && (
								<tr>
									<td
										colSpan={ network ? 11 : 10 }
										className="devbridge-table__empty"
									>
										{ __(
											'No entries.',
											'lab591-dev-bridge'
										) }
									</td>
								</tr>
							) }
							{ data.rows.map( ( row ) => (
								<tr key={ row.id }>
									<td className="devbridge-nowrap">
										{ row.ts }
									</td>
									{ network && <td>{ row.site }</td> }
									<td>{ row.user }</td>
									<td className="devbridge-nowrap">
										{ row.ip }
									</td>
									<td>
										<code>{ row.endpoint }</code>
									</td>
									<td>{ row.mode }</td>
									<td
										className="devbridge-paths"
										title={ row.paths.join( '\n' ) }
									>
										{ row.paths.length
											? row.paths.join( ', ' )
											: '—' }
									</td>
									<td className="is-numeric devbridge-nowrap">
										{ row.bytes
											? formatBytes( row.bytes )
											: '—' }
									</td>
									<td className="is-numeric">
										<span
											className={ `devbridge-pill ${ row.status >= 400 ? 'is-error' : 'is-ok' }` }
										>
											{ row.status }
										</span>
									</td>
									<td className="is-numeric devbridge-nowrap">
										{ row.durationMs } ms
									</td>
									<td>
										{ row.releaseId ? (
											<code>{ row.releaseId }</code>
										) : (
											''
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
			</div>

			{ data && (
				<div className="devbridge-pagination">
					<span className="devbridge-muted">
						{ sprintf(
							/* translators: %d: number of entries. */
							_n(
								'%d entry',
								'%d entries',
								data.total,
								'lab591-dev-bridge'
							),
							data.total
						) }
					</span>
					<Button
						variant="secondary"
						size="compact"
						disabled={ loading || data.page <= 1 }
						onClick={ () => setPage( data.page - 1 ) }
					>
						{ __( 'Previous', 'lab591-dev-bridge' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'lab591-dev-bridge' ),
							data.page,
							data.pages
						) }
					</span>
					<Button
						variant="secondary"
						size="compact"
						disabled={ loading || data.page >= data.pages }
						onClick={ () => setPage( data.page + 1 ) }
					>
						{ __( 'Next', 'lab591-dev-bridge' ) }
					</Button>
				</div>
			) }
		</Section>
	);
}
