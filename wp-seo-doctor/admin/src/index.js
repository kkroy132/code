/**
 * WP SEO Doctor admin app entry point. Deliberately dependency-free
 * beyond the @wordpress/* packages WordPress core already ships (no
 * react-router, no state library) — a single mount point per admin page
 * with a data-route attribute, switched on below. Links/404 Monitor/
 * Redirects/Content/Search Console/AI Assistant routes render a
 * "coming soon" placeholder until their backing modules land in
 * Steps 9-13; only Overview is wired to real data right now.
 */

import { render, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';

import './style.css';

function useOverview() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( true );

	const load = () => {
		setLoading( true );
		apiFetch( { path: '/seodoc/v1/overview' } )
			.then( ( result ) => {
				setData( result );
				setError( null );
			} )
			.catch( ( err ) => setError( err ) )
			.finally( () => setLoading( false ) );
	};

	useEffect( load, [] );

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
	const { data, error, loading, reload } = useOverview();
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

function ComingSoon() {
	return <p>{ __( 'This section is coming soon.', 'wp-seo-doctor' ) }</p>;
}

function App( { route } ) {
	if ( 'overview' === route ) {
		return <OverviewScreen />;
	}

	return <ComingSoon />;
}

document.querySelectorAll( '#seodoc-app' ).forEach( ( container ) => {
	const route = container.getAttribute( 'data-route' ) || 'overview';
	render( <App route={ route } />, container );
} );
