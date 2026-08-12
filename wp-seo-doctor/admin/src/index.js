/**
 * WP SEO Doctor admin app entry point. Deliberately dependency-free
 * beyond the @wordpress/* packages WordPress core already ships (no
 * react-router, no state library) — a single mount point per admin page
 * with a data-route attribute, switched on below.
 *
 * Every screen in the menu is wired to real data now: Overview, SEO
 * Audit, Links, 404 Monitor, Redirects, Action Plan, Content, Search
 * Console, AI Assistant, Reports, and Settings.
 */

import { render, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';

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

function ActionPlanScreen() {
	const { data, error, loading } = useFetch( '/seodoc/v1/action-plan', [] );

	if ( loading ) {
		return <Spinner />;
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load the action plan.', 'wp-seo-doctor' ) }
			</Notice>
		);
	}

	if ( ! data || 0 === data.length ) {
		return <p>{ __( 'No open issues — nice work.', 'wp-seo-doctor' ) }</p>;
	}

	return (
		<ol className="seodoc-action-plan">
			{ data.map( ( group ) => (
				<li key={ group.check_id } className="seodoc-action-plan__item">
					<span className={ 'seodoc-badge seodoc-badge--' + group.severity }>
						{ group.severity }
					</span>{ ' ' }
					<strong>{ group.sample_title }</strong>{ ' ' }
					{ sprintf(
						/* translators: %d: number of affected pages. */
						__( '(%d affected)', 'wp-seo-doctor' ),
						group.affected_count
					) }
					{ group.sample_urls && group.sample_urls.length > 0 && (
						<ul className="seodoc-action-plan__urls">
							{ group.sample_urls.map( ( url ) => (
								<li key={ url }>
									<a href={ url } target="_blank" rel="noreferrer">
										{ url }
									</a>
								</li>
							) ) }
						</ul>
					) }
				</li>
			) ) }
		</ol>
	);
}

function ContentScreen() {
	const { data, error, loading, reload } = useFetch( '/seodoc/v1/issues?category=content', [] );
	const [ busyId, setBusyId ] = useState( null );

	const ignoreIssue = ( id ) => {
		setBusyId( id );
		apiFetch( { path: `/seodoc/v1/issues/${ id }/ignore`, method: 'POST' } )
			.then( reload )
			.finally( () => setBusyId( null ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load content issues.', 'wp-seo-doctor' ) }
			</Notice>
		);
	}

	if ( ! data || 0 === data.length ) {
		return (
			<p>
				{ __(
					'No content issues yet. SEO Opportunity Finder and Content Decay detection require a Pro license and a connected Google Search Console property — see the Search Console tab.',
					'wp-seo-doctor'
				) }
			</p>
		);
	}

	return (
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
				{ data.map( ( item ) => (
					<tr key={ item.id }>
						<td>
							<span className={ 'seodoc-badge seodoc-badge--' + item.severity }>
								{ item.severity }
							</span>
						</td>
						<td>{ item.title }</td>
						<td>
							<a href={ item.url } target="_blank" rel="noreferrer">
								{ item.url }
							</a>
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
	);
}

function SearchConsoleScreen() {
	const [ status, setStatus ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ connecting, setConnecting ] = useState( false );
	const [ opportunities, setOpportunities ] = useState( [] );
	const [ decay, setDecay ] = useState( [] );

	const loadStatus = () => {
		setLoading( true );
		apiFetch( { path: '/seodoc/v1/gsc/status' } )
			.then( ( result ) => {
				setStatus( result );
				setError( null );
				if ( result.connected ) {
					apiFetch( { path: '/seodoc/v1/gsc/opportunities' } )
						.then( setOpportunities )
						.catch( () => {} );
					apiFetch( { path: '/seodoc/v1/gsc/content-decay' } )
						.then( setDecay )
						.catch( () => {} );
				}
			} )
			.catch( ( err ) => setError( err ) )
			.finally( () => setLoading( false ) );
	};

	useEffect( () => {
		const params = new URLSearchParams( window.location.search );
		const grantCode = params.get( 'grant_code' );
		const state = params.get( 'state' );

		if ( grantCode && state ) {
			apiFetch( {
				path: '/seodoc/v1/gsc/callback',
				method: 'POST',
				data: { grant_code: grantCode, state },
			} )
				.catch( () => {} )
				.finally( () => {
					params.delete( 'grant_code' );
					params.delete( 'state' );
					const query = params.toString();
					window.history.replaceState( {}, '', window.location.pathname + ( query ? '?' + query : '' ) );
					loadStatus();
				} );
		} else {
			loadStatus();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const connect = () => {
		setConnecting( true );
		apiFetch( { path: '/seodoc/v1/gsc/connect-url' } )
			.then( ( result ) => {
				window.location.href = result.url;
			} )
			.catch( () => setConnecting( false ) );
	};

	const disconnect = () => {
		apiFetch( { path: '/seodoc/v1/gsc/disconnect', method: 'POST' } ).then( loadStatus );
	};

	if ( loading ) {
		return <Spinner />;
	}

	if ( error || ! status ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load Search Console status.', 'wp-seo-doctor' ) }
			</Notice>
		);
	}

	if ( ! status.licensed ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'Google Search Console integration requires a Pro license. Add your license key in Settings.',
					'wp-seo-doctor'
				) }
			</Notice>
		);
	}

	if ( ! status.connected ) {
		return (
			<div>
				<p>
					{ __(
						'Connect Google Search Console to unlock the SEO Opportunity Finder and Content Decay detection.',
						'wp-seo-doctor'
					) }
				</p>
				<Button variant="primary" onClick={ connect } isBusy={ connecting } disabled={ connecting }>
					{ __( 'Connect Google Search Console', 'wp-seo-doctor' ) }
				</Button>
			</div>
		);
	}

	return (
		<div className="seodoc-gsc">
			<Button variant="secondary" onClick={ disconnect }>
				{ __( 'Disconnect', 'wp-seo-doctor' ) }
			</Button>

			<h2>{ __( 'SEO Opportunities', 'wp-seo-doctor' ) }</h2>
			{ 0 === opportunities.length ? (
				<p>{ __( 'No opportunities found in the last 28 days.', 'wp-seo-doctor' ) }</p>
			) : (
				<table className="seodoc-table">
					<thead>
						<tr>
							<th>{ __( 'Page', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'Position', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'Impressions', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'CTR', 'wp-seo-doctor' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ opportunities.map( ( row ) => (
							<tr key={ row.page }>
								<td>{ row.page }</td>
								<td>{ row.position }</td>
								<td>{ row.impressions }</td>
								<td>{ row.ctr }%</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<h2>{ __( 'Content Decay', 'wp-seo-doctor' ) }</h2>
			{ 0 === decay.length ? (
				<p>{ __( 'No declining pages found.', 'wp-seo-doctor' ) }</p>
			) : (
				<table className="seodoc-table">
					<thead>
						<tr>
							<th>{ __( 'Page', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'Prior clicks', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'Recent clicks', 'wp-seo-doctor' ) }</th>
							<th>{ __( 'Change', 'wp-seo-doctor' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ decay.map( ( row ) => (
							<tr key={ row.page }>
								<td>{ row.page }</td>
								<td>{ row.prior_clicks }</td>
								<td>{ row.recent_clicks }</td>
								<td>{ row.change_percent }%</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

function AiAssistantScreen() {
	const [ summary, setSummary ] = useState( '' );
	const [ summaryError, setSummaryError ] = useState( '' );
	const [ summaryLoading, setSummaryLoading ] = useState( true );

	const [ postId, setPostId ] = useState( '' );
	const [ question, setQuestion ] = useState( '' );
	const [ answer, setAnswer ] = useState( '' );
	const [ askError, setAskError ] = useState( '' );
	const [ asking, setAsking ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/seodoc/v1/ai/action-plan-summary' } )
			.then( ( result ) => setSummary( result.summary || '' ) )
			.catch( ( err ) =>
				setSummaryError( err.message || __( 'Could not load an AI summary.', 'wp-seo-doctor' ) )
			)
			.finally( () => setSummaryLoading( false ) );
	}, [] );

	const ask = ( event ) => {
		event.preventDefault();
		setAsking( true );
		setAskError( '' );
		setAnswer( '' );
		apiFetch( {
			path: `/seodoc/v1/ai/ask/${ postId }`,
			method: 'POST',
			data: { question },
		} )
			.then( ( result ) => setAnswer( result.answer || '' ) )
			.catch( ( err ) => setAskError( err.message || __( 'Could not get an answer.', 'wp-seo-doctor' ) ) )
			.finally( () => setAsking( false ) );
	};

	return (
		<div className="seodoc-ai">
			<h2>{ __( 'Action Plan Summary', 'wp-seo-doctor' ) }</h2>
			{ summaryLoading && <Spinner /> }
			{ summaryError && (
				<Notice status="warning" isDismissible={ false }>
					{ summaryError }
				</Notice>
			) }
			{ ! summaryLoading && ! summaryError && (
				<p>{ summary || __( 'No summary available yet.', 'wp-seo-doctor' ) }</p>
			) }

			<h2>{ __( 'Ask About a Page', 'wp-seo-doctor' ) }</h2>
			<form onSubmit={ ask } className="seodoc-ai-ask">
				<TextControl label={ __( 'Post ID', 'wp-seo-doctor' ) } value={ postId } onChange={ setPostId } type="number" />
				<TextareaControl
					label={ __( 'Question', 'wp-seo-doctor' ) }
					value={ question }
					onChange={ setQuestion }
					placeholder={ __( 'Why is this page not SEO optimized?', 'wp-seo-doctor' ) }
				/>
				{ askError && (
					<Notice status="error" isDismissible={ false }>
						{ askError }
					</Notice>
				) }
				<Button variant="primary" type="submit" isBusy={ asking } disabled={ asking || ! postId || ! question }>
					{ __( 'Ask', 'wp-seo-doctor' ) }
				</Button>
			</form>
			{ answer && <p className="seodoc-ai-answer">{ answer }</p> }
		</div>
	);
}

function ReportsScreen() {
	const [ exporting, setExporting ] = useState( false );
	const [ error, setError ] = useState( '' );

	const exportCsv = () => {
		setExporting( true );
		setError( '' );
		apiFetch( { path: '/seodoc/v1/reports/issues' } )
			.then( ( rows ) => {
				const header = [ 'Severity', 'Category', 'Title', 'URL', 'Status', 'First Detected' ];
				const csvRows = [ header.join( ',' ) ];

				rows.forEach( ( row ) => {
					const cells = [ row.severity, row.category, row.title, row.url, row.status, row.first_detected ];
					csvRows.push( cells.map( ( cell ) => '"' + String( cell || '' ).replace( /"/g, '""' ) + '"' ).join( ',' ) );
				} );

				const blob = new Blob( [ csvRows.join( '\n' ) ], { type: 'text/csv' } );
				const url = window.URL.createObjectURL( blob );
				const link = document.createElement( 'a' );
				link.href = url;
				link.download = 'wp-seo-doctor-issues.csv';
				document.body.appendChild( link );
				link.click();
				document.body.removeChild( link );
				window.URL.revokeObjectURL( url );
			} )
			.catch( () => setError( __( 'Could not export issues.', 'wp-seo-doctor' ) ) )
			.finally( () => setExporting( false ) );
	};

	return (
		<div className="seodoc-reports">
			<p>{ __( 'Export every currently open issue as a CSV file.', 'wp-seo-doctor' ) }</p>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<Button variant="primary" onClick={ exportCsv } isBusy={ exporting } disabled={ exporting }>
				{ __( 'Export Open Issues (CSV)', 'wp-seo-doctor' ) }
			</Button>
		</div>
	);
}

function SettingsScreen() {
	const { data, error, loading } = useFetch( '/seodoc/v1/settings', [] );
	const [ deleteOnUninstall, setDeleteOnUninstall ] = useState( false );
	const [ licenseKey, setLicenseKey ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		if ( data ) {
			setDeleteOnUninstall( !! data.delete_data_on_uninstall );
			setLicenseKey( data.license_key || '' );
		}
	}, [ data ] );

	const save = ( event ) => {
		event.preventDefault();
		setSaving( true );
		setSaved( false );
		apiFetch( {
			path: '/seodoc/v1/settings',
			method: 'POST',
			data: { delete_data_on_uninstall: deleteOnUninstall, license_key: licenseKey },
		} )
			.then( () => setSaved( true ) )
			.finally( () => setSaving( false ) );
	};

	if ( loading ) {
		return <Spinner />;
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __( 'Could not load settings.', 'wp-seo-doctor' ) }
			</Notice>
		);
	}

	return (
		<form onSubmit={ save } className="seodoc-settings">
			<TextControl
				label={ __( 'Pro License Key', 'wp-seo-doctor' ) }
				value={ licenseKey }
				onChange={ setLicenseKey }
				help={ __(
					'Unlocks unlimited suggestions, extra redirect types, Search Console integration, and the AI Assistant.',
					'wp-seo-doctor'
				) }
			/>
			<ToggleControl
				label={ __( 'Delete all data when this plugin is uninstalled', 'wp-seo-doctor' ) }
				checked={ deleteOnUninstall }
				onChange={ setDeleteOnUninstall }
				help={ __(
					'Off by default — your scan history, issues, and redirects are kept unless you turn this on.',
					'wp-seo-doctor'
				) }
			/>
			{ saved && (
				<Notice status="success" isDismissible={ false }>
					{ __( 'Settings saved.', 'wp-seo-doctor' ) }
				</Notice>
			) }
			<Button variant="primary" type="submit" isBusy={ saving } disabled={ saving }>
				{ __( 'Save Settings', 'wp-seo-doctor' ) }
			</Button>
		</form>
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
		case 'action-plan':
			return <ActionPlanScreen />;
		case 'links':
			return <LinksScreen />;
		case '404-monitor':
			return <Monitor404Screen />;
		case 'redirects':
			return <RedirectsScreen />;
		case 'content':
			return <ContentScreen />;
		case 'search-console':
			return <SearchConsoleScreen />;
		case 'ai-assistant':
			return <AiAssistantScreen />;
		case 'reports':
			return <ReportsScreen />;
		case 'settings':
			return <SettingsScreen />;
		default:
			return <ComingSoon />;
	}
}

document.querySelectorAll( '#seodoc-app' ).forEach( ( container ) => {
	const route = container.getAttribute( 'data-route' ) || 'overview';
	render( <App route={ route } />, container );
} );
