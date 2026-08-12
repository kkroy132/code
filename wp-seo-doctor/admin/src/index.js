/**
 * WP SEO Doctor admin app entry point. Deliberately dependency-free
 * beyond the @wordpress/* packages WordPress core already ships (no
 * react-router, no state library) — a single mount point per admin page
 * with a data-route attribute, switched on below.
 *
 * Overview, SEO Audit, Links (suggestions), 404 Monitor, and Redirects
 * are wired to real data — their REST APIs were already complete, only
 * the screens were missing. Content/Search Console/AI Assistant/
 * Reports/Settings still render "coming soon": those need more than a
 * list+action screen (an OAuth connect flow, a chat-style Q&A UI, a
 * settings form), so they're left for follow-up rather than shipped
 * half-built.
 */

import { render, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';

import './style.css';

/**
 * Generic GET-and-store hook shared by every list screen below.
 */
function useFetch( path, deps ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( true );

	const load = () => {
		setLoading( true );
		apiFetch( { path } )
			.then( ( result ) => {
				setData( result );
				setError( null );
			} )
			.catch( ( err ) => setError( err ) )
			.finally( () => setLoading( false ) );
	};

	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, deps );

	return { data, error, loading, reload: load };
}

const SEVERITY_LABELS = [
	[ 'critical', __( 'Critical', 'wp-seo-doctor' ) ],
	[ 'high', __( 'High', 'wp-seo-doctor' ) ],
	[ 'medium', __( 'Medium', 'wp-seo-doctor' ) ],
	[ 'low', __( 'Low', 'wp-seo-doctor' ) ],
];

function SeverityCounts( { counts } ) {
	return (
		<div className="seodoc-severity-counts">
			{ SEVERITY_LABELS.map( ( pair ) => {
				const key = pair[ 0 ];
				const label = pair[ 1 ];
				return (
					<div key={ key } className={ 'seodoc-count seodoc-count--' + key }>
						<span className="seodoc-count__value">
							{ counts && counts[ key ] ? counts[ key ] : 0 }
						</span>
						<span className="seodoc-count__label">{ label }</span>
					</div>
				);
			} ) }
		</div>
	);
}

function FixFirstList( { items } ) {
	if ( ! items || 0 === items.length ) {
		return <p>{ __( 'No open issues — nice work.', 'wp-seo-doctor' ) }</p>;
	}

	return (
		<ol className="seodoc-fix-first">
			{ items.map( ( item ) => (
				<li key={ item.check_id }>
					<strong>{ item.sample_title }</strong>{ ' ' }
					{ sprintf(
						/* translators: %d: number of affected pages. */
						__( '(%d affected)', 'wp-seo-doctor' ),
						item.affected_count
					) }
				</li>
			) ) }
		</ol>
	);
}

function OverviewScreen() {
	const { data, error, loading, reload } = useFetch( '/seodoc/v1/overview', [] );
	const [ starting, setStarting ] = useState( false );

	const startScan = () => {
		setStarting( true );
		apiFetch( { path: '/seodoc/v1/scans', method: 'POST' } )
			.then( reload )
			.finally( () => setStarting( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	if ( error || ! data ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load the SEO overview.', 'wp-seo-doctor' ) }
			</Notice>
		);
	}

	const hasScore = null !== data.health_score;
	const hasPrevious = hasScore && null !== data.previous_health_score;
	const delta = hasPrevious ? data.health_score - data.previous_health_score : 0;

	return (
		<div className="seodoc-overview">
			<div className="seodoc-health-score">
				<span className="seodoc-health-score__value">
					{ hasScore ? data.health_score : '—' }
				</span>
				<span className="seodoc-health-score__max">{ __( '/ 100', 'wp-seo-doctor' ) }</span>
				{ hasPrevious && (
					<span className="seodoc-health-score__delta">
						{ ( delta >= 0 ? '↑ ' : '↓ ' ) + Math.abs( delta ) + ' ' }
						{ __( 'since last scan', 'wp-seo-doctor' ) }
					</span>
				) }
			</div>

			<SeverityCounts counts={ data.issue_counts } />

			<Button variant="primary" onClick={ startScan } isBusy={ starting } disabled={ starting }>
				{ __( 'Run a new scan', 'wp-seo-doctor' ) }
			</Button>

			<h2>{ __( 'Fix First', 'wp-seo-doctor' ) }</h2>
			<FixFirstList items={ data.fix_first } />
		</div>
	);
}

function SeoAuditScreen() {
	const [ severity, setSeverity ] = useState( '' );
	const [ category, setCategory ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ items, setItems ] = useState( [] );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ busyId, setBusyId ] = useState( null );

	const load = () => {
		setLoading( true );
		const query = new URLSearchParams();
		query.set( 'page', String( page ) );
		if ( severity ) {
			query.set( 'severity', severity );
		}
		if ( category ) {
			query.set( 'category', category );
		}

		apiFetch( { path: '/seodoc/v1/issues?' + query.toString(), parse: false } )
			.then( ( response ) => {
				setTotalPages( parseInt( response.headers.get( 'X-WP-TotalPages' ) || '1', 10 ) );
				return response.json();
			} )
			.then( ( result ) => {
				setItems( result );
				setError( null );
			} )
			.catch( ( err ) => setError( err ) )
			.finally( () => setLoading( false ) );
	};

	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, [ severity, category, page ] );

	const ignoreIssue = ( id ) => {
		setBusyId( id );
		apiFetch( { path: `/seodoc/v1/issues/${ id }/ignore`, method: 'POST' } )
			.then( load )
			.finally( () => setBusyId( null ) );
	};

	return (
		<div className="seodoc-audit">
			<div className="seodoc-filters">
				<SelectControl
					label={ __( 'Severity', 'wp-seo-doctor' ) }
					value={ severity }
					options={ [
						{ label: __( 'All severities', 'wp-seo-doctor' ), value: '' },
						{ label: __( 'Critical', 'wp-seo-doctor' ), value: 'critical' },
						{ label: __( 'High', 'wp-seo-doctor' ), value: 'high' },
						{ label: __( 'Medium', 'wp-seo-doctor' ), value: 'medium' },
						{ label: __( 'Low', 'wp-seo-doctor' ), value: 'low' },
					] }
					onChange={ ( value ) => {
						setPage( 1 );
						setSeverity( value );
					} }
				/>
				<SelectControl
					label={ __( 'Category', 'wp-seo-doctor' ) }
					value={ category }
					options={ [
						{ label: __( 'All categories', 'wp-seo-doctor' ), value: '' },
						{ label: __( 'On-page', 'wp-seo-doctor' ), value: 'on-page' },
						{ label: __( 'Technical', 'wp-seo-doctor' ), value: 'technical' },
						{ label: __( 'Links', 'wp-seo-doctor' ), value: 'links' },
						{ label: __( 'Content', 'wp-seo-doctor' ), value: 'content' },
					] }
					onChange={ ( value ) => {
						setPage( 1 );
						setCategory( value );
					} }
				/>
			</div>

			{ loading && <Spinner /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load issues.', 'wp-seo-doctor' ) }
				</Notice>
			) }

			{ ! loading && ! error && (
				<table className="seodoc-table">
					<thead>
						<tr>
							<th>{ __( 'Severity', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'Issue', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'URL', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'Action', 'wp-seo-doctor' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ 0 === items.length && (
							<tr>
								<td colSpan="4">{ __( 'No open issues match these filters.', 'wp-seo-doctor' ) }</td>
							</tr>
						) }
						{ items.map( ( item ) => (
							<tr key={ item.id }>
								<td>
									<span className={ 'seodoc-badge seodoc-badge--' + item.severity }>
										{ item.severity }
									</span>
								</td>
								<td>{ item.title }</td>
								<td>
									{ item.url ? (
										<a href={ item.url } target="_blank" rel="noreferrer">
											{ item.url }
										</a>
									) : (
										'—'
									) }
								</td>
								<td>
									<Button
										variant="secondary"
										isBusy={ busyId === item.id }
										disabled={ busyId === item.id }
										onClick={ () => ignoreIssue( item.id ) }
									>
										{ __( 'Ignore', 'wp-seo-doctor' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ totalPages > 1 && (
				<div className="seodoc-pagination">
					<Button variant="secondary" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
						{ __( 'Previous', 'wp-seo-doctor' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'wp-seo-doctor' ),
							page,
							totalPages
						) }
					</span>
					<Button variant="secondary" disabled={ page >= totalPages } onClick={ () => setPage( page + 1 ) }>
						{ __( 'Next', 'wp-seo-doctor' ) }
					</Button>
				</div>
			) }
		</div>
	);
}

function LinksScreen() {
	const { data, error, loading, reload } = useFetch( '/seodoc/v1/suggestions', [] );
	const [ busyId, setBusyId ] = useState( null );

	const act = ( id, action ) => {
		setBusyId( id );
		apiFetch( { path: `/seodoc/v1/suggestions/${ id }/${ action }`, method: 'POST' } )
			.then( reload )
			.catch( () => {} )
			.finally( () => setBusyId( null ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load link suggestions.', 'wp-seo-doctor' ) }
			</Notice>
		);
	}

	if ( ! data || 0 === data.length ) {
		return (
			<p>
				{ __(
					'No pending internal-link suggestions right now — run a scan to generate more.',
					'wp-seo-doctor'
				) }
			</p>
		);
	}

	return (
		<table className="seodoc-table">
			<thead>
				<tr>
					<th>{ __( 'Suggested anchor', 'wp-seo-doctor' ) }</th>
					<th>{ __( 'From', 'wp-seo-doctor' ) }</th>
					<th>{ __( 'To', 'wp-seo-doctor' ) }</th>
					<th>{ __( 'Reason', 'wp-seo-doctor' ) }</th>
					<th>{ __( 'Actions', 'wp-seo-doctor' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( item ) => (
					<tr key={ item.id }>
						<td>{ item.suggested_anchor }</td>
						<td>
							<a href={ item.source_url } target="_blank" rel="noreferrer">
								{ item.source_url }
							</a>
						</td>
						<td>
							<a href={ item.target_url } target="_blank" rel="noreferrer">
								{ item.target_url }
							</a>
						</td>
						<td>{ item.reason }</td>
						<td>
							<Button
								variant="primary"
								isBusy={ busyId === item.id }
								disabled={ busyId === item.id }
								onClick={ () => act( item.id, 'approve' ) }
							>
								{ __( 'Approve & Insert', 'wp-seo-doctor' ) }
							</Button>{ ' ' }
							<Button
								variant="secondary"
								isBusy={ busyId === item.id }
								disabled={ busyId === item.id }
								onClick={ () => act( item.id, 'dismiss' ) }
							>
								{ __( 'Dismiss', 'wp-seo-doctor' ) }
							</Button>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function Monitor404Screen() {
	const { data, error, loading, reload } = useFetch( '/seodoc/v1/404s', [] );
	const [ busyUrl, setBusyUrl ] = useState( null );

	const createRedirect = ( item ) => {
		setBusyUrl( item.url );
		const source = item.url.replace( /^https?:\/\/[^/]+/, '' ) || '/';
		apiFetch( {
			path: '/seodoc/v1/redirects',
			method: 'POST',
			data: { source, destination: item.suggested_redirect, redirect_type: 301 },
		} )
			.then( reload )
			.catch( () => {} )
			.finally( () => setBusyUrl( null ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load 404 hits.', 'wp-seo-doctor' ) }
			</Notice>
		);
	}

	if ( ! data || 0 === data.length ) {
		return <p>{ __( 'No 404 hits recorded yet.', 'wp-seo-doctor' ) }</p>;
	}

	return (
		<table className="seodoc-table">
			<thead>
				<tr>
					<th>{ __( 'URL', 'wp-seo-doctor' ) }</th>
					<th>{ __( 'Hits', 'wp-seo-doctor' ) }</th>
					<th>{ __( 'Last seen', 'wp-seo-doctor' ) }</th>
					<th>{ __( 'Suggested redirect', 'wp-seo-doctor' ) }</th>
					<th>{ __( 'Action', 'wp-seo-doctor' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( item ) => (
					<tr key={ item.id }>
						<td>{ item.url }</td>
						<td>{ item.hit_count }</td>
						<td>{ item.last_seen }</td>
						<td>{ item.suggested_redirect || '—' }</td>
						<td>
							{ item.suggested_redirect && (
								<Button
									variant="primary"
									isBusy={ busyUrl === item.url }
									disabled={ busyUrl === item.url }
									onClick={ () => createRedirect( item ) }
								>
									{ __( 'Create 301 Redirect', 'wp-seo-doctor' ) }
								</Button>
							) }
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function RedirectsScreen() {
	const { data, error, loading, reload } = useFetch( '/seodoc/v1/redirects', [] );
	const [ source, setSource ] = useState( '' );
	const [ destination, setDestination ] = useState( '' );
	const [ redirectType, setRedirectType ] = useState( '301' );
	const [ formError, setFormError ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ busyId, setBusyId ] = useState( null );

	const submit = ( event ) => {
		event.preventDefault();
		setSubmitting( true );
		setFormError( '' );
		apiFetch( {
			path: '/seodoc/v1/redirects',
			method: 'POST',
			data: { source, destination, redirect_type: parseInt( redirectType, 10 ) },
		} )
			.then( () => {
				setSource( '' );
				setDestination( '' );
				reload();
			} )
			.catch( ( err ) =>
				setFormError( err.message || __( 'Could not create this redirect.', 'wp-seo-doctor' ) )
			)
			.finally( () => setSubmitting( false ) );
	};

	const remove = ( id ) => {
		setBusyId( id );
		apiFetch( { path: `/seodoc/v1/redirects/${ id }`, method: 'DELETE' } )
			.then( reload )
			.finally( () => setBusyId( null ) );
	};

	return (
		<div className="seodoc-redirects">
			<form onSubmit={ submit } className="seodoc-redirect-form">
				<TextControl
					label={ __( 'From (path)', 'wp-seo-doctor' ) }
					value={ source }
					onChange={ setSource }
					placeholder="/old-page/"
				/>
				<TextControl
					label={ __( 'To (URL or path)', 'wp-seo-doctor' ) }
					value={ destination }
					onChange={ setDestination }
					placeholder="/new-page/"
				/>
				<SelectControl
					label={ __( 'Type', 'wp-seo-doctor' ) }
					value={ redirectType }
					options={ [
						{ label: '301', value: '301' },
						{ label: '302', value: '302' },
					] }
					onChange={ setRedirectType }
				/>
				{ formError && (
					<Notice status="error" isDismissible={ false }>
						{ formError }
					</Notice>
				) }
				<Button
					variant="primary"
					type="submit"
					isBusy={ submitting }
					disabled={ submitting || ! source || ! destination }
				>
					{ __( 'Add Redirect', 'wp-seo-doctor' ) }
				</Button>
			</form>

			{ loading && <Spinner /> }
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ __( 'Could not load redirects.', 'wp-seo-doctor' ) }
				</Notice>
			) }

			{ ! loading && ! error && (
				<table className="seodoc-table">
					<thead>
						<tr>
							<th>{ __( 'From', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'To', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'Type', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'Hits', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'Action', 'wp-seo-doctor' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ ( ! data || 0 === data.length ) && (
							<tr>
								<td colSpan="5">{ __( 'No redirects yet.', 'wp-seo-doctor' ) }</td>
							</tr>
						) }
						{ data &&
							data.map( ( item ) => (
								<tr key={ item.id }>
									<td>{ item.source_path }</td>
									<td>{ item.destination_url }</td>
									<td>{ item.redirect_type }</td>
									<td>{ item.hit_count }</td>
									<td>
										<Button
											variant="secondary"
											isDestructive
											isBusy={ busyId === item.id }
											disabled={ busyId === item.id }
											onClick={ () => remove( item.id ) }
										>
											{ __( 'Delete', 'wp-seo-doctor' ) }
										</Button>
									</td>
								</tr>
							) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

function ComingSoon() {
	return <p>{ __( 'This section is coming soon.', 'wp-seo-doctor' ) }</p>;
}

function App( { route } ) {
	switch ( route ) {
		case 'overview':
			return <OverviewScreen />;
		case 'audit':
			return <SeoAuditScreen />;
		case 'links':
			return <LinksScreen />;
		case '404-monitor':
			return <Monitor404Screen />;
		case 'redirects':
			return <RedirectsScreen />;
		default:
			return <ComingSoon />;
	}
}

document.querySelectorAll( '#seodoc-app' ).forEach( ( container ) => {
	const route = container.getAttribute( 'data-route' ) || 'overview';
	render( <App route={ route } />, container );
} );
