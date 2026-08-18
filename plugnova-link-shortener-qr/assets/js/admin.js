/**
 * Plugnova Link Shortener & QR — Admin UI behavior.
 * Handles the Links list table (AJAX search/filter/sort/paginate/CRUD), the create modal,
 * and Chart.js rendering for any canvas with data-labels/data-values attributes.
 *
 * @package QuickLinkQRPro
 */

/* global jQuery, QLQR, Chart, wp */

( function ( $ ) {
	'use strict';

	// Translation helper. wp-i18n is declared as a dependency of this script and the matching
	// wp_set_script_translations() call lives in Admin\AdminMenu, so this resolves to the site's
	// locale when a translation file is present and falls back to the English source when not.
	const { __ } = wp.i18n;

	const state = {
		search: '',
		status: 'all',
		category: '',
		linkStatus: 'all',
		dateFrom: '',
		dateTo: '',
		favoriteOnly: false,
		colorLabel: '',
		tag: '',
		orderby: 'created_at',
		order: 'desc',
		paged: 1,
		perPage: 20,
	};

	/**
	 * The currently-rendered page of links, keyed by ID — lets the Score breakdown modal (and
	 * anything else that needs a row's full data after the initial render) look it up instantly
	 * without a second AJAX round-trip.
	 */
	let linkRowCache = {};

	/**
	 * ISO 3166-1 alpha-2 country codes mapped to English names — shared by the Links page's Geo
	 * targeting rule country dropdown and the Bio Links page's per-button Country visibility
	 * multi-select, both of which resolve visitors the same way (Helpers\GeoLocator::country_for_ip()).
	 */
	const QLQR_COUNTRIES = {
		AF: 'Afghanistan', AL: 'Albania', DZ: 'Algeria', AS: 'American Samoa', AD: 'Andorra',
		AO: 'Angola', AI: 'Anguilla', AQ: 'Antarctica', AG: 'Antigua and Barbuda', AR: 'Argentina',
		AM: 'Armenia', AW: 'Aruba', AU: 'Australia', AT: 'Austria', AZ: 'Azerbaijan',
		BS: 'Bahamas', BH: 'Bahrain', BD: 'Bangladesh', BB: 'Barbados', BY: 'Belarus',
		BE: 'Belgium', BZ: 'Belize', BJ: 'Benin', BM: 'Bermuda', BT: 'Bhutan',
		BO: 'Bolivia', BA: 'Bosnia and Herzegovina', BW: 'Botswana', BR: 'Brazil', BN: 'Brunei',
		BG: 'Bulgaria', BF: 'Burkina Faso', BI: 'Burundi', KH: 'Cambodia', CM: 'Cameroon',
		CA: 'Canada', CV: 'Cape Verde', KY: 'Cayman Islands', CF: 'Central African Republic', TD: 'Chad',
		CL: 'Chile', CN: 'China', CO: 'Colombia', KM: 'Comoros', CG: 'Congo',
		CD: 'Congo (DRC)', CK: 'Cook Islands', CR: 'Costa Rica', CI: "Côte d'Ivoire", HR: 'Croatia',
		CU: 'Cuba', CW: 'Curaçao', CY: 'Cyprus', CZ: 'Czechia', DK: 'Denmark',
		DJ: 'Djibouti', DM: 'Dominica', DO: 'Dominican Republic', EC: 'Ecuador', EG: 'Egypt',
		SV: 'El Salvador', GQ: 'Equatorial Guinea', ER: 'Eritrea', EE: 'Estonia', SZ: 'Eswatini',
		ET: 'Ethiopia', FJ: 'Fiji', FI: 'Finland', FR: 'France', GA: 'Gabon',
		GM: 'Gambia', GE: 'Georgia', DE: 'Germany', GH: 'Ghana', GI: 'Gibraltar',
		GR: 'Greece', GL: 'Greenland', GD: 'Grenada', GU: 'Guam', GT: 'Guatemala',
		GG: 'Guernsey', GN: 'Guinea', GW: 'Guinea-Bissau', GY: 'Guyana', HT: 'Haiti',
		HN: 'Honduras', HK: 'Hong Kong', HU: 'Hungary', IS: 'Iceland', IN: 'India',
		ID: 'Indonesia', IR: 'Iran', IQ: 'Iraq', IE: 'Ireland', IM: 'Isle of Man',
		IL: 'Israel', IT: 'Italy', JM: 'Jamaica', JP: 'Japan', JE: 'Jersey',
		JO: 'Jordan', KZ: 'Kazakhstan', KE: 'Kenya', KI: 'Kiribati', KP: 'Korea (North)',
		KR: 'Korea (South)', KW: 'Kuwait', KG: 'Kyrgyzstan', LA: 'Laos', LV: 'Latvia',
		LB: 'Lebanon', LS: 'Lesotho', LR: 'Liberia', LY: 'Libya', LI: 'Liechtenstein',
		LT: 'Lithuania', LU: 'Luxembourg', MO: 'Macau', MG: 'Madagascar', MW: 'Malawi',
		MY: 'Malaysia', MV: 'Maldives', ML: 'Mali', MT: 'Malta', MH: 'Marshall Islands',
		MR: 'Mauritania', MU: 'Mauritius', MX: 'Mexico', FM: 'Micronesia', MD: 'Moldova',
		MC: 'Monaco', MN: 'Mongolia', ME: 'Montenegro', MS: 'Montserrat', MA: 'Morocco',
		MZ: 'Mozambique', MM: 'Myanmar', NA: 'Namibia', NR: 'Nauru', NP: 'Nepal',
		NL: 'Netherlands', NC: 'New Caledonia', NZ: 'New Zealand', NI: 'Nicaragua', NE: 'Niger',
		NG: 'Nigeria', NU: 'Niue', MK: 'North Macedonia', NO: 'Norway', OM: 'Oman',
		PK: 'Pakistan', PW: 'Palau', PS: 'Palestine', PA: 'Panama', PG: 'Papua New Guinea',
		PY: 'Paraguay', PE: 'Peru', PH: 'Philippines', PL: 'Poland', PT: 'Portugal',
		PR: 'Puerto Rico', QA: 'Qatar', RO: 'Romania', RU: 'Russia', RW: 'Rwanda',
		WS: 'Samoa', SM: 'San Marino', ST: 'Sao Tome and Principe', SA: 'Saudi Arabia', SN: 'Senegal',
		RS: 'Serbia', SC: 'Seychelles', SL: 'Sierra Leone', SG: 'Singapore', SK: 'Slovakia',
		SI: 'Slovenia', SB: 'Solomon Islands', SO: 'Somalia', ZA: 'South Africa', SS: 'South Sudan',
		ES: 'Spain', LK: 'Sri Lanka', SD: 'Sudan', SR: 'Suriname', SE: 'Sweden',
		CH: 'Switzerland', SY: 'Syria', TW: 'Taiwan', TJ: 'Tajikistan', TZ: 'Tanzania',
		TH: 'Thailand', TL: 'Timor-Leste', TG: 'Togo', TO: 'Tonga', TT: 'Trinidad and Tobago',
		TN: 'Tunisia', TR: 'Turkey', TM: 'Turkmenistan', TV: 'Tuvalu', UG: 'Uganda',
		UA: 'Ukraine', AE: 'United Arab Emirates', GB: 'United Kingdom', US: 'United States', UY: 'Uruguay',
		UZ: 'Uzbekistan', VU: 'Vanuatu', VA: 'Vatican City', VE: 'Venezuela', VN: 'Vietnam',
		VG: 'Virgin Islands (British)', VI: 'Virgin Islands (US)', YE: 'Yemen', ZM: 'Zambia', ZW: 'Zimbabwe',
	};

	/**
	 * Short display text for a country multi-select's closed-state toggle button, based on how
	 * many countries are currently checked. Shared by addBioLinkRow() (initial render) and the
	 * checkbox change handler (after the admin picks/unpicks a country).
	 *
	 * @param {Array} codes Selected ISO country codes.
	 * @return {string} Display text, e.g. "All countries", "Bangladesh, India", "5 countries selected".
	 */
	function qlqrCountryMultiselectLabel( codes ) {
		if ( 0 === codes.length ) {
			return __( 'All countries', 'plugnova-link-shortener-qr' );
		}
		if ( codes.length <= 2 ) {
			return codes.map( function ( code ) {
				return QLQR_COUNTRIES[ code ] || code;
			} ).join( ', ' );
		}
		return codes.length + __( ' countries selected', 'plugnova-link-shortener-qr' );
	}

	/**
	 * Perform an admin-ajax POST request with the shared nonce attached.
	 *
	 * @param {string} action Ajax action name (without the wp_ajax_ prefix).
	 * @param {Object} data   Additional POST data.
	 * @return {JQuery.Promise} jQuery ajax promise.
	 */
	function qlqrAjax( action, data ) {
		return $.post( QLQR.ajaxUrl, Object.assign( { action: action, nonce: QLQR.nonce }, data ) );
	}

	/**
	 * Escape a string for safe HTML insertion, in element content *and* inside a quoted attribute.
	 *
	 * Deliberately not the `$( '<div>' ).text( str ).html()` trick: serializing a text node only
	 * escapes &, < and > — quotes pass through untouched. That is fine for element content but not
	 * for the many attribute contexts this helper feeds (title="…", value="…", data-*="…"), where a
	 * value containing a double quote would close the attribute early and let the rest be parsed as
	 * markup. Server-side sanitize_text_field()/sanitize_textarea_field() strip tags but keep
	 * quotes, so titles, notes, categories and bio-button labels can all carry them.
	 *
	 * @param {string} str Raw string.
	 * @return {string} Escaped string, safe in both content and quoted-attribute position.
	 */
	function esc( str ) {
		return String( str == null ? '' : str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' );
	}

	/**
	 * Render a failed create()/clone_link() AJAX response into $el, adding a clickable
	 * "Upgrade to Pro" link whenever the response carries an upgrade_url — i.e. the request was
	 * blocked by the free plan's link/Bio Page limit rather than a validation problem. Kept in
	 * one place so Quick Add, the Add New link modal, and the Add New bio page modal all render
	 * this the same way; $el's own .text()/.show() styling stays with each call site.
	 *
	 * @param {JQuery} $el       Element whose content to replace.
	 * @param {Object} response  The AJAX response object (response.success is falsy).
	 */
	function renderAjaxError( $el, response ) {
		const data = ( response && response.data ) || {};
		const message = data.message || QLQR.i18n.genericError;

		$el.empty().append( document.createTextNode( message ) );

		if ( data.upgrade_url ) {
			$el.append( document.createTextNode( ' ' ) ).append(
				$( '<a>', {
					href: data.upgrade_url,
					target: '_blank',
					rel: 'noopener',
					text: __( 'Upgrade to Pro', 'plugnova-link-shortener-qr' ),
				} )
			);
		}
	}

	/**
	 * For a free-plan account, disable the 30/90-day options in an analytics day-range <select>
	 * and force it to 7 — the server enforces the same 7-day cap regardless of what's requested
	 * (see Helpers\AnalyticsDays::sanitize()), so this only keeps the control itself from
	 * offering a choice the account can't actually use. No-op for a Pro account (QLQR.isPro).
	 *
	 * @param {JQuery} $select The #qlqr-analytics-days / #qlqr-bio-analytics-days element.
	 */
	function applyAnalyticsPlanLimit( $select ) {
		if ( QLQR.isPro ) {
			return;
		}

		$select.find( 'option[value="30"], option[value="90"]' ).prop( 'disabled', true );
		$select.val( '7' );
	}

	/**
	 * Build a friendly download filename for a QR image lightbox (e.g. "my-campaign-link.png")
	 * instead of the server's internal storage filename (a hash-suffixed slug, meaningless to an
	 * admin browsing their downloads folder).
	 *
	 * @param {string} title Link/bio-page title (or slug) to base the filename on.
	 * @param {string} src   The QR image URL, used only to recover its file extension.
	 * @return {string} A filesystem-safe filename with extension.
	 */
	function qlqrDownloadFilename( title, src ) {
		const ext = ( ( src || '' ).split( '.' ).pop() || 'png' ).split( '?' )[ 0 ].split( '#' )[ 0 ] || 'png';
		const slug = ( title || 'qr-code' )
			.toString()
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' );

		return ( slug || 'qr-code' ) + '-qr.' + ext;
	}

	/**
	 * Render one row of the links table.
	 *
	 * @param {Object} link Serialized link object from the server.
	 * @return {string} HTML for a single <tr>.
	 */
	function renderRow( link, serial ) {
		const statusClass = link.is_trashed ? 'qlqr-status-trashed' : ( 'active' === link.status ? 'qlqr-status-active' : 'qlqr-status-disabled' );
		const statusLabel = link.is_trashed ? __( 'Trashed', 'plugnova-link-shortener-qr' ) : ( 'active' === link.status ? __( 'Active', 'plugnova-link-shortener-qr' ) : __( 'Disabled', 'plugnova-link-shortener-qr' ) );

		const qrThumb = link.qr_image
			? '<img class="qlqr-qr-thumb" src="' + esc( link.qr_image ) + ( '" alt="' + __( 'QR', 'plugnova-link-shortener-qr' ) + '" data-action="view-qr" data-src="' ) + esc( link.qr_image ) + '" data-title="' + esc( link.title || link.short_slug ) + '" />'
			: '—';

		// Same idea as the short URL below: "https://www." is eleven characters of nothing in a cell
		// that has to truncate anyway. The full URL stays in the tooltip and on the preview button.
		const destinationText = String( link.destination_url || '' ).replace( /^https?:\/\/(www\.)?/, '' );

		const destinationCell = '<div class="qlqr-cell-flex">' +
			( 'multiple' === link.destination_type
				? '<span class="qlqr-rotation-badge" title="' + esc( link.destination_url ) + ' + more">🔀 ' + ( link.destinations ? link.destinations.length : '' ) + ' destinations</span>'
				: '<span class="qlqr-truncate" title="' + esc( link.destination_url ) + '">' + esc( destinationText ) + '</span>' ) +
			'<button type="button" class="qlqr-preview-btn qlqr-act" data-action="preview-destination" data-url="' + esc( link.destination_url ) + ( '" title="' + __( 'Live Destination Preview', 'plugnova-link-shortener-qr' ) + '">👁️</button>' ) +
			'</div>';

		/*
		 * Every action on one line, as an icon button with its label in the tooltip. Spelled out as
		 * text the six buttons needed about 370px — a quarter of the table — which is what pushed
		 * them into a "⋯" menu previously; as icons they take roughly 170px and fit alongside
		 * everything else. Dashicons ship with wp-admin, so this needs no extra asset and matches
		 * the icons used elsewhere in the admin.
		 */
		const actionButton = ( action, icon, label, extraClass ) =>
			'<button type="button" class="button button-small qlqr-icon-btn qlqr-act' + ( extraClass ? ' ' + extraClass : '' ) +
			'" data-action="' + action + '" data-id="' + link.id +
			'" title="' + esc( label ) + '" aria-label="' + esc( label ) + '">' +
			'<span class="dashicons dashicons-' + icon + '" aria-hidden="true"></span></button>';

		let actions = '';
		if ( link.is_trashed ) {
			actions += actionButton( 'restore', 'undo', __( 'Restore', 'plugnova-link-shortener-qr' ) );
			actions += actionButton( 'delete', 'no-alt', __( 'Delete Permanently', 'plugnova-link-shortener-qr' ), 'qlqr-danger' );
		} else {
			actions += actionButton( 'edit', 'edit', __( 'Edit', 'plugnova-link-shortener-qr' ) );
			actions += actionButton( 'clone', 'admin-page', __( 'Duplicate this link with a new short URL', 'plugnova-link-shortener-qr' ) );
			actions += 'active' === link.status
				? actionButton( 'toggle-status', 'hidden', __( 'Disable', 'plugnova-link-shortener-qr' ) )
				: actionButton( 'toggle-status', 'visibility', __( 'Enable', 'plugnova-link-shortener-qr' ) );
			actions += actionButton( 'regenerate-qr', 'update', __( 'Regenerate QR code', 'plugnova-link-shortener-qr' ) );
			if ( 'multiple' !== link.destination_type ) {
				actions += actionButton( 'recheck-link', 'admin-links', __( 'Check whether the destination URL is still reachable', 'plugnova-link-shortener-qr' ) );
			}
			actions += actionButton( 'trash', 'trash', __( 'Move to Trash', 'plugnova-link-shortener-qr' ), 'qlqr-danger' );
		}

		const favIcon = link.is_favorite ? '★' : '☆';

		/*
		 * The link text is the short URL's path ("/go/VzdsZk"), not the absolute URL. The scheme and
		 * host are identical on every row, so spelling them out spent about a seventh of the table's
		 * width repeating the site's own domain. The href, the copy button and the tooltip all still
		 * carry the full URL, so nothing is actually lost.
		 */
		const shortPath = String( link.short_url || '' ).replace( /^https?:\/\/[^/]+/, '' ) || link.short_url;
		const shortUrlCell =
			'<div class="qlqr-short-url-cell">' +
				'<a href="' + esc( link.short_url ) + '" target="_blank" rel="noopener" title="' + esc( link.short_url ) + '">' + esc( shortPath ) + '</a>' +
				'<button type="button" class="qlqr-copy-inline qlqr-act" data-action="copy" data-url="' + esc( link.short_url ) + ( '" title="' + __( 'Copy short URL', 'plugnova-link-shortener-qr' ) + '">📋</button>' ) +
			'</div>';

		const qrScans = link.qr_scan_count || 0;
		const directClicks = Math.max( 0, ( link.total_clicks || 0 ) - qrScans );
		let trafficBadge = '';
		if ( ( link.total_clicks || 0 ) > 0 ) {
			// The badge is an icon plus a tooltip rather than an icon plus a wrapping label: it sits
			// inline beside the click count now, and "Mostly Direct" spelled out was wide enough to
			// push the Clicks cell onto a second line on its own.
			const counts = qrScans + __( ' via QR scan, ', 'plugnova-link-shortener-qr' ) + directClicks + __( ' direct/other', 'plugnova-link-shortener-qr' );
			let badgeIcon = '🚦';
			let badgeLabel = __( 'Mixed traffic', 'plugnova-link-shortener-qr' );

			if ( qrScans > directClicks ) {
				badgeIcon = '📱';
				badgeLabel = __( 'Mostly QR', 'plugnova-link-shortener-qr' );
			} else if ( directClicks > qrScans ) {
				badgeIcon = '🔗';
				badgeLabel = __( 'Mostly Direct', 'plugnova-link-shortener-qr' );
			}

			trafficBadge = '<span class="qlqr-traffic-badge" title="' + esc( badgeLabel + ' — ' + counts ) + '">' + badgeIcon + '</span>';
		}

		const clicksCell =
			'<span class="qlqr-clicks-cell">' +
				'<button type="button" class="qlqr-clicks-link qlqr-act" data-action="view-analytics" data-id="' + link.id + '">' + ( link.total_clicks || 0 ) + '</button>' +
				( qrScans > 0 ? ( '<span class="qlqr-qr-scan-count" title="' + __( 'Clicks that arrived via a scanned QR code', 'plugnova-link-shortener-qr' ) + '">📱' ) + qrScans + '</span>' : '' ) +
				trafficBadge +
			'</span>';

		const trendCell = renderSparkline( link.trend || [] );

		const brokenBadge = 'broken' === link.link_status
			? ' <span class="qlqr-status-pill qlqr-status-disabled" title="Last checked: ' + esc( link.last_checked_at || '' ) + ( link.http_status ? __( ' (HTTP ', 'plugnova-link-shortener-qr' ) + link.http_status + ')' : '' ) + ( '">' + __( '⚠ Broken', 'plugnova-link-shortener-qr' ) + '</span>' )
			: '';

		/*
		 * A dot rather than "⚡139ms" spelled out. The number is still one hover away, and the colour
		 * (green/amber/red) is what the eye actually reads when scanning a column of these; the text
		 * form was the single widest thing in the Status column.
		 */
		const responseBadge = ( 'number' === typeof link.response_time_ms )
			? ' <span class="qlqr-response-badge ' + responseSpeedClass( link.response_time_ms ) + '" title="' + esc( __( 'Response time at last check', 'plugnova-link-shortener-qr' ) + ': ' + link.response_time_ms + 'ms' ) + '">●</span>'
			: '';

		const scoreCell = link.score
			? '<button type="button" class="qlqr-score-pill qlqr-grade-' + link.score.grade.toLowerCase() + ' qlqr-act" data-action="view-score" data-id="' + link.id + ( '" title="' + __( 'Smart Link Score', 'plugnova-link-shortener-qr' ) + '">' ) + esc( link.score.grade ) + ' ' + link.score.percentage + '%</button>'
			: '—';

		const notesIcon = link.notes ? ' <span class="qlqr-notes-icon" title="' + esc( link.notes ) + '">📝</span>' : '';

		/*
		 * An inset shadow rather than a left border, so the colour bar costs the cell no width. As a
		 * border it narrowed the checkbox cell by 4px on exactly the rows that have a colour label,
		 * which was enough to overflow the cell and draw a stray truncation ellipsis beside them.
		 */
		const colorBorder = link.color_label ? ' style="box-shadow: inset 4px 0 0 ' + esc( link.color_label ) + ';"' : '';

		/*
		 * A flex row, so the trailing notes icon takes only the space it actually occupies. Reserving
		 * a fixed gutter for it instead — which is what a plain max-width calc() does — shortened the
		 * title on every row, including the majority that have no note at all.
		 */
		const title = link.title || __( '(untitled)', 'plugnova-link-shortener-qr' );
		const titleCell = '<div class="qlqr-cell-flex"><span class="qlqr-cell-title" title="' + esc( title ) + '">' + esc( title ) + '</span>' + notesIcon + '</div>';

		// Date only in the column, full timestamp in the tooltip: "2026-07-14 17:44:35" is wide
		// enough to wrap onto a second line on its own, and the clock time is rarely what the eye
		// is scanning for.
		const created = esc( link.created_at );
		const createdCell = '<span class="qlqr-cell-date" title="' + created + '">' + created.slice( 0, 10 ) + '</span>';

		const category = link.category
			? '<span class="qlqr-cell-category" title="' + esc( link.category ) + '">' + esc( link.category ) + '</span>'
			: '—';

		return (
			'<tr data-id="' + link.id + '">' +
				'<td class="qlqr-col-select"' + colorBorder + '><input type="checkbox" class="qlqr-row-select" value="' + link.id + '" /></td>' +
				'<td class="qlqr-col-serial">' + serial + '</td>' +
				'<td class="qlqr-col-fav"><button class="qlqr-fav-toggle" data-action="toggle-favorite" data-id="' + link.id + '">' + favIcon + '</button></td>' +
				'<td class="qlqr-col-score">' + scoreCell + '</td>' +
				'<td class="qlqr-col-title">' + titleCell + '</td>' +
				'<td class="qlqr-col-short">' + shortUrlCell + '</td>' +
				'<td class="qlqr-col-destination">' + destinationCell + '</td>' +
				'<td class="qlqr-col-category">' + category + '</td>' +
				'<td class="qlqr-col-qr">' + qrThumb + '</td>' +
				'<td class="qlqr-col-clicks">' + clicksCell + '</td>' +
				'<td class="qlqr-col-trend">' + trendCell + '</td>' +
				'<td class="qlqr-col-status"><span class="qlqr-status-pill ' + statusClass + '">' + statusLabel + '</span>' + brokenBadge + responseBadge + '</td>' +
				'<td class="qlqr-col-created">' + createdCell + '</td>' +
				'<td class="qlqr-row-actions">' + actions + '</td>' +
			'</tr>'
		);
	}

	/**
	 * Render a tiny inline SVG line chart from a 7-day {date, clicks} series — the Links table's
	 * "Trend" column. No axes/labels/library dependency; just enough to show shape-at-a-glance.
	 *
	 * @param {Array} series Array of {date, clicks}, oldest first.
	 * @return {string} HTML for the sparkline (or an em-dash placeholder if there's no data).
	 */
	function renderSparkline( series ) {
		if ( ! series || 0 === series.length ) {
			return '<span class="qlqr-sparkline-empty">—</span>';
		}

		const values = series.map( function ( d ) { return d.clicks || 0; } );
		const total = values.reduce( function ( a, b ) { return a + b; }, 0 );

		if ( 0 === total ) {
			return ( '<span class="qlqr-sparkline-empty" title="' + __( 'No clicks in the last 7 days', 'plugnova-link-shortener-qr' ) + '">—</span>' );
		}

		// 50px, not 60: the Trend column is a proportion of the table width now, and at the table's
		// minimum width a 60px sparkline was wider than the cell's content box and got clipped.
		const w = 50;
		const h = 20;
		const max = Math.max.apply( null, values ) || 1;
		const step = values.length > 1 ? w / ( values.length - 1 ) : 0;

		const points = values.map( function ( v, i ) {
			const x = Math.round( i * step );
			const y = Math.round( h - ( v / max ) * ( h - 3 ) - 1.5 );
			return x + ',' + y;
		} ).join( ' ' );

		return '<svg class="qlqr-sparkline" width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '"><title>' + total + ' clicks in the last 7 days</title><polyline points="' + points + '" fill="none" stroke="currentColor" stroke-width="1.5" /></svg>';
	}

	/**
	 * CSS class for a response-time badge, tiered green/yellow/red by speed.
	 *
	 * @param {number} ms Response time in milliseconds.
	 * @return {string} CSS class name.
	 */
	function responseSpeedClass( ms ) {
		if ( ms <= 500 ) {
			return 'qlqr-response-fast';
		}
		if ( ms <= 1500 ) {
			return 'qlqr-response-medium';
		}
		return 'qlqr-response-slow';
	}

	/**
	 * Re-sync the bulk-action bar (Apply button enabled state + "N selected" count) with the
	 * currently checked row checkboxes. Called on every checkbox change, and after each table
	 * redraw (rows are freshly rendered/unchecked, so the header checkbox resets too).
	 */
	function updateBulkApplyState() {
		const count = $( '.qlqr-row-select:checked' ).length;
		$( '#qlqr-bulk-apply' ).prop( 'disabled', 0 === count );
		$( '#qlqr-bulk-selected-count' ).text( count ? count + __( ' selected', 'plugnova-link-shortener-qr' ) : '' );
	}

	/**
	 * Fetch links from the server according to the current filter/sort/pagination state and redraw the table.
	 */
	function loadLinks() {
		$( '#qlqr-links-tbody' ).html( ( '<tr><td colspan="14">' + __( 'Loading…', 'plugnova-link-shortener-qr' ) + '</td></tr>' ) );

		qlqrAjax( 'qlqr_query_links', {
			search: state.search,
			status: state.status,
			category: state.category,
			link_status: state.linkStatus,
			date_from: state.dateFrom,
			date_to: state.dateTo,
			favorite_only: state.favoriteOnly ? 1 : 0,
			color_label: state.colorLabel,
			tag: state.tag,
			orderby: state.orderby,
			order: state.order,
			paged: state.paged,
			per_page: state.perPage,
		} ).done( function ( response ) {
			if ( ! response.success ) {
				$( '#qlqr-links-tbody' ).html( '<tr class="qlqr-empty-row"><td colspan="14">' + esc( QLQR.i18n.genericError ) + '</td></tr>' );
				return;
			}

			const items = response.data.items || [];

			linkRowCache = {};
			items.forEach( function ( link ) {
				linkRowCache[ link.id ] = link;
			} );

			if ( 0 === items.length ) {
				$( '#qlqr-links-tbody' ).html( ( '<tr class="qlqr-empty-row"><td colspan="14">' + __( 'No links found.', 'plugnova-link-shortener-qr' ) + '</td></tr>' ) );
			} else {
				const startSerial = ( state.paged - 1 ) * state.perPage + 1;
				const rowsHtml = items.map( function ( link, index ) {
					return renderRow( link, startSerial + index );
				} ).join( '' );
				$( '#qlqr-links-tbody' ).html( rowsHtml );
			}

			$( '#qlqr-select-all' ).prop( 'checked', false );
			updateBulkApplyState();
			renderPagination( response.data.total );
		} );
	}

	/**
	 * Render simple prev/next pagination controls based on total item count.
	 *
	 * @param {number} total Total matching records.
	 */
	function renderPagination( total ) {
		const totalPages = Math.max( 1, Math.ceil( total / state.perPage ) );
		let html = '';

		html += '<button class="button" id="qlqr-prev-page" ' + ( state.paged <= 1 ? 'disabled' : '' ) + ( '>' + __( '&laquo; Prev', 'plugnova-link-shortener-qr' ) + '</button>' );
		html += '<span style="padding:0 10px;">Page ' + state.paged + __( ' of ', 'plugnova-link-shortener-qr' ) + totalPages + ' (' + total + ' total)</span>';
		html += '<button class="button" id="qlqr-next-page" ' + ( state.paged >= totalPages ? 'disabled' : '' ) + ( '>' + __( 'Next &raquo;', 'plugnova-link-shortener-qr' ) + '</button>' );

		$( '#qlqr-pagination' ).html( html );
	}

	/**
	 * Render every VISIBLE, not-yet-initialized Chart.js canvas that carries data-labels/
	 * data-values attributes. Hidden canvases (e.g. on an inactive tab panel) are skipped —
	 * Chart.js measures a display:none canvas as 0×0 and draws nothing — and get initialized
	 * by calling this again when their tab is activated. Each canvas is only ever initialized
	 * once, tracked via a data flag.
	 */
	function renderCharts() {
		if ( 'undefined' === typeof Chart ) {
			return;
		}

		$( 'canvas[data-labels]' ).each( function () {
			const $canvas = $( this );

			if ( this.dataset.qlqrChartDone || ! $canvas.is( ':visible' ) ) {
				return;
			}

			let labels = [];
			let values = [];

			try {
				labels = JSON.parse( $canvas.attr( 'data-labels' ) || '[]' );
				values = JSON.parse( $canvas.attr( 'data-values' ) || '[]' );
			} catch ( e ) {
				return;
			}

			this.dataset.qlqrChartDone = '1';

			// Both the Dashboard and Analytics clicks-over-time canvases ("…clicks-by-day") render
			// as line charts; every other data-driven canvas is a bar chart.
			const isBar = ! ( $canvas.attr( 'id' ) || '' ).includes( 'clicks-by-day' );

			new Chart( this.getContext( '2d' ), {
				type: isBar ? 'bar' : 'line',
				data: {
					labels: labels,
					datasets: [ {
						label: __( 'Clicks', 'plugnova-link-shortener-qr' ),
						data: values,
						backgroundColor: 'rgba(34, 113, 177, 0.5)',
						borderColor: 'rgba(34, 113, 177, 1)',
						borderWidth: 2,
						tension: 0.3,
						fill: ! isBar,
					} ],
				},
				options: {
					responsive: true,
					plugins: { legend: { display: false } },
					scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
				},
			} );
		} );
	}

	// Generic modal behavior, shared by every plugin admin page (Links, Bio Links, Settings):
	// close on the × button, on clicking the dimmed backdrop, or on Escape; open the QR lightbox
	// from any thumbnail. Document-delegated and bound unconditionally — deliberately OUTSIDE the
	// Links-page-only ready handler below, whose early return would otherwise skip these bindings
	// on every screen that lacks #qlqr-links-table (e.g. the Bio Links page).
	//
	// The Create/Edit Link and Create/Edit Bio Page modals mark themselves data('dirty', true) the
	// moment a field inside their <form> is actually touched (see the delegated input/change/click
	// listener below) and clear it again in resetModalToCreateMode()/resetBioModalToCreateMode(),
	// which both run before a modal is shown (fresh, or with an existing item's data). A close
	// attempt on a dirty modal — × button, backdrop click, or Escape — asks for confirmation first,
	// since all three used to discard a filled-in form with no warning at all. Read-only modals
	// (analytics, QR lightbox, score breakdown, …) never get marked dirty, so they keep closing
	// instantly exactly as before.
	function qlqrCloseModal( $modal ) {
		if ( $modal.data( 'dirty' ) && ! window.confirm( __( 'This form has unsaved changes. Close without saving?', 'plugnova-link-shortener-qr' ) ) ) {
			return;
		}

		$modal.hide().attr( 'aria-hidden', 'true' );
	}

	$( document ).on( 'input change click', '#qlqr-create-form, #qlqr-bio-form', function () {
		$( this ).closest( '.qlqr-modal' ).data( 'dirty', true );
	} );

	$( document ).on( 'click', '.qlqr-modal-close', function () {
		qlqrCloseModal( $( this ).closest( '.qlqr-modal' ) );
	} );

	$( document ).on( 'click', '.qlqr-modal', function ( e ) {
		if ( e.target === this ) {
			qlqrCloseModal( $( this ) );
		}
	} );

	$( document ).on( 'click', 'img[data-action="view-qr"]', function () {
		const $img = $( this );
		const title = $img.data( 'title' ) || '';
		const src = $img.data( 'src' );

		$( '#qlqr-lightbox-title' ).text( title );
		$( '#qlqr-lightbox-image' ).attr( 'src', src );
		$( '#qlqr-lightbox-download' ).attr( 'href', src ).attr( 'download', qlqrDownloadFilename( title, src ) );
		$( '#qlqr-qr-lightbox' ).show().attr( 'aria-hidden', 'false' );
	} );

	$( document ).on( 'keyup', function ( e ) {
		if ( 'Escape' === e.key ) {
			$( '.qlqr-modal:visible' ).each( function () {
				qlqrCloseModal( $( this ) );
			} );
		}
	} );

	/**
	 * Activate one of the horizontal navigation tabs on the unified admin page: highlight the
	 * tab, show its panel (hiding the rest), and initialize any Chart.js canvases that just
	 * became visible. Pure client-side — no page reload.
	 *
	 * @param {string} tab Tab key (e.g. "dashboard", "links"). Falls back to the first tab if unknown.
	 */
	function qlqrActivateTab( tab ) {
		const $tabs = $( '.qlqr-nav-tab' );

		if ( 0 === $tabs.length ) {
			return;
		}

		let $target = $tabs.filter( '[data-tab="' + tab + '"]' );
		if ( 0 === $target.length ) {
			$target = $tabs.first();
			tab = $target.data( 'tab' );
		}

		$tabs.removeClass( 'nav-tab-active' );
		$target.addClass( 'nav-tab-active' );

		$( '.qlqr-tab-panel' ).removeClass( 'qlqr-tab-panel-active' );

		const $panel = $( '.qlqr-tab-panel[data-tab-panel="' + tab + '"]' );
		$panel.addClass( 'qlqr-tab-panel-active' );

		qlqrLoadLazyPanel( $panel );

		// Charts on a previously hidden panel couldn't be drawn (0×0 canvas); now that the panel
		// is visible, initialize any that haven't been yet. Already-drawn charts are skipped.
		renderCharts();
	}

	/**
	 * Fetch and inject the markup for a panel that ships empty (see Admin\TabsPage's lazy_action).
	 * Runs at most once per panel: the data-lazy-action attribute is removed as soon as the request
	 * is started, so re-opening the tab reuses what is already on the page.
	 *
	 * @param {jQuery} $panel The panel element being activated.
	 */
	function qlqrLoadLazyPanel( $panel ) {
		const action = $panel.attr( 'data-lazy-action' );

		if ( ! action ) {
			return;
		}

		$panel.removeAttr( 'data-lazy-action' );
		$panel.html( '<div class="qlqr-panel"><span class="spinner is-active" style="float:none;"></span> ' + esc( __( 'Loading…', 'plugnova-link-shortener-qr' ) ) + '</div>' );

		qlqrAjax( action, {} ).done( function ( response ) {
			if ( ! response.success || ! response.data || ! response.data.html ) {
				$panel.html( '<div class="qlqr-panel"><p>' + esc( QLQR.i18n.genericError ) + '</p></div>' );
				return;
			}

			$panel.html( response.data.html );

			// The markup only exists now, so its canvases still need their first draw.
			renderCharts();
		} ).fail( function () {
			$panel.html( '<div class="qlqr-panel"><p>' + esc( QLQR.i18n.genericError ) + '</p></div>' );
		} );
	}

	// Matches both the tab bar itself (.qlqr-nav-tab) and any in-page "jump to this tab" link
	// (.qlqr-nav-tab-link, e.g. the Dashboard's "broken links" notice) — both just need a
	// data-tab attribute naming the target panel.
	$( document ).on( 'click', '.qlqr-nav-tab, .qlqr-nav-tab-link', function ( e ) {
		e.preventDefault();
		const tab = $( this ).data( 'tab' );
		qlqrActivateTab( tab );

		// Record the active tab in the URL hash so reload/bookmark/back-forward restores it —
		// replaceState avoids both a page reload and polluting the back-button history with
		// one entry per tab click.
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + tab );
		}
	} );

	// Direct hash navigation (e.g. a link to admin.php?page=plugnova-link-shortener-qr#analytics clicked
	// while already on the page) also switches tabs without a reload.
	$( window ).on( 'hashchange', function () {
		const tab = ( window.location.hash || '' ).replace( '#', '' );
		if ( tab ) {
			qlqrActivateTab( tab );
		}
	} );

	$( function () {
		if ( 0 === $( '.qlqr-nav-tabs' ).length ) {
			return;
		}

		// Initial tab: after a Settings save, options.php redirects back with settings-updated=true
		// (the URL hash is lost across that redirect), so land on Settings; otherwise honor the
		// hash or a ?tab= query parameter; default to the first tab (Dashboard).
		const params = new URLSearchParams( window.location.search );
		let initial = ( window.location.hash || '' ).replace( '#', '' ) || params.get( 'tab' ) || '';

		if ( params.get( 'settings-updated' ) ) {
			initial = 'settings';
		}

		qlqrActivateTab( initial );
	} );

	$( function () {
		renderCharts();

		if ( 0 === $( '#qlqr-links-table' ).length ) {
			return;
		}

		loadLinks();

		/**
		 * Populate the category filter dropdown and the create/edit modal's category
		 * autocomplete datalist from the server's current distinct-categories list.
		 */
		function loadCategoryOptions() {
			qlqrAjax( 'qlqr_list_categories', {} ).done( function ( response ) {
				if ( ! response.success ) {
					return;
				}

				const categories = response.data.categories || [];
				const currentFilter = $( '#qlqr-filter-category' ).val();

				const filterOptions = categories.map( function ( c ) {
					return '<option value="' + esc( c ) + '">' + esc( c ) + '</option>';
				} ).join( '' );
				$( '#qlqr-filter-category' ).html( ( '<option value="">' + __( 'All categories', 'plugnova-link-shortener-qr' ) + '</option>' ) + filterOptions ).val( currentFilter );

				const datalistOptions = categories.map( function ( c ) {
					return '<option value="' + esc( c ) + '"></option>';
				} ).join( '' );
				$( '#qlqr-category-options' ).html( datalistOptions );
			} );
		}

		loadCategoryOptions();

		/**
		 * Create a link instantly from just a destination URL — the rest of the create() defaults
		 * apply (random slug, default QR style, active status). For power-user options (schedule,
		 * password, targeting rules, etc.) the full "Add New" modal is still there.
		 */
		function submitQuickAdd() {
			const $input = $( '#qlqr-quick-add-url' );
			const url = $input.val().trim();

			if ( '' === url ) {
				return;
			}

			const $btn = $( '#qlqr-quick-add-btn' ).prop( 'disabled', true ).text( __( 'Creating…', 'plugnova-link-shortener-qr' ) );
			$( '#qlqr-quick-add-result' ).text( '' );

			qlqrAjax( 'qlqr_create_link', { data: { destination_url: url } } ).done( function ( response ) {
				$btn.prop( 'disabled', false ).text( __( 'Quick Add', 'plugnova-link-shortener-qr' ) );

				if ( ! response.success ) {
					renderAjaxError( $( '#qlqr-quick-add-result' ).css( 'color', '#d63638' ), response );
					return;
				}

				$input.val( '' );
				$( '#qlqr-quick-add-result' ).css( 'color', '#1a7f37' ).text( __( 'Created: ', 'plugnova-link-shortener-qr' ) + response.data.link.short_url );
				loadLinks();
			} ).fail( function () {
				$btn.prop( 'disabled', false ).text( __( 'Quick Add', 'plugnova-link-shortener-qr' ) );
				$( '#qlqr-quick-add-result' ).css( 'color', '#d63638' ).text( QLQR.i18n.genericError );
			} );
		}

		$( '#qlqr-quick-add-btn' ).on( 'click', submitQuickAdd );
		$( '#qlqr-quick-add-url' ).on( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				submitQuickAdd();
			}
		} );

		let searchTimer = null;
		$( '#qlqr-search' ).on( 'input', function () {
			clearTimeout( searchTimer );
			const value = $( this ).val();
			searchTimer = setTimeout( function () {
				state.search = value;
				state.paged = 1;
				loadLinks();
			}, 350 );
		} );

		$( '#qlqr-filter-status' ).on( 'change', function () {
			state.status = $( this ).val();
			state.paged = 1;
			loadLinks();
		} );

		$( '#qlqr-filter-category' ).on( 'change', function () {
			state.category = $( this ).val();
			state.paged = 1;
			loadLinks();
		} );

		$( '#qlqr-filter-link-status' ).on( 'change', function () {
			state.linkStatus = $( this ).val();
			state.paged = 1;
			loadLinks();
		} );

		$( '#qlqr-filter-color' ).on( 'change', function () {
			state.colorLabel = $( this ).val();
			state.paged = 1;
			loadLinks();
		} );

		$( '#qlqr-filter-favorite' ).on( 'change', function () {
			state.favoriteOnly = $( this ).is( ':checked' );
			state.paged = 1;
			loadLinks();
		} );

		$( '#qlqr-filter-date-from' ).on( 'change', function () {
			state.dateFrom = $( this ).val();
			state.paged = 1;
			loadLinks();
		} );

		$( '#qlqr-filter-date-to' ).on( 'change', function () {
			state.dateTo = $( this ).val();
			state.paged = 1;
			loadLinks();
		} );

		let tagFilterTimer = null;
		$( '#qlqr-filter-tag' ).on( 'input', function () {
			clearTimeout( tagFilterTimer );
			const value = $( this ).val();
			tagFilterTimer = setTimeout( function () {
				state.tag = value;
				state.paged = 1;
				loadLinks();
			}, 350 );
		} );

		$( document ).on( 'click', '#qlqr-links-table th[data-orderby]', function () {
			const column = $( this ).data( 'orderby' );
			if ( state.orderby === column ) {
				state.order = 'asc' === state.order ? 'desc' : 'asc';
			} else {
				state.orderby = column;
				state.order = 'desc';
			}
			loadLinks();
		} );

		$( document ).on( 'click', '#qlqr-prev-page', function () {
			if ( state.paged > 1 ) {
				state.paged -= 1;
				loadLinks();
			}
		} );

		$( document ).on( 'click', '#qlqr-next-page', function () {
			state.paged += 1;
			loadLinks();
		} );

		/**
		 * Build one repeater row for the multi-destination editor and append it.
		 *
		 * @param {Object} data {destination_url, weight, status} — all optional, defaults to a blank active row.
		 */
		function addDestinationRow( data ) {
			data = data || {};
			const url = data.destination_url || '';
			const weight = data.weight || 1;
			const checked = 'disabled' !== data.status ? 'checked' : '';

			const $row = $(
				'<div class="qlqr-destination-row">' +
					( '<span class="qlqr-destination-drag-handle" title="' + __( 'Drag to reorder', 'plugnova-link-shortener-qr' ) + '">⠿</span>' ) +
					( '<input type="url" class="qlqr-dest-url" placeholder="' + __( 'https://example.com/page', 'plugnova-link-shortener-qr' ) + '" value="' ) + esc( url ) + '" />' +
					'<input type="number" class="qlqr-dest-weight" min="1" max="1000" value="' + esc( String( weight ) ) + ( '" title="' + __( 'Weight (used by Weighted Random)', 'plugnova-link-shortener-qr' ) + '" />' ) +
					( '<label class="qlqr-dest-status" title="' + __( 'Uncheck to temporarily skip this destination', 'plugnova-link-shortener-qr' ) + '"><input type="checkbox" class="qlqr-dest-active" ' ) + checked + ( ' /> ' + __( 'Active', 'plugnova-link-shortener-qr' ) + '</label>' ) +
					( '<button type="button" class="qlqr-dest-remove" title="' + __( 'Remove', 'plugnova-link-shortener-qr' ) + '">' + __( '&times;', 'plugnova-link-shortener-qr' ) + '</button>' ) +
				'</div>'
			);

			// Multi-destination rotation is a Pro feature. Same belt-and-suspenders reasoning as
			// addTargetingRuleRow() below: the server already ignores anything a free-plan account
			// submits for destinations (see LinkController::create()/update()) and the wrapper's
			// qlqr-pro-locked class blocks pointer events, but this row is built from an HTML
			// string, so its own controls also get an explicit `disabled` here.
			if ( ! QLQR.isPro ) {
				$row.find( 'input, button' ).prop( 'disabled', true );
			}

			$( '#qlqr-destinations-repeater' ).append( $row );
		}

		/**
		 * Read all repeater rows into a plain array ready to submit as payload.destinations.
		 *
		 * @return {Array} Array of {destination_url, weight, status}.
		 */
		function collectDestinations() {
			const destinations = [];

			$( '#qlqr-destinations-repeater .qlqr-destination-row' ).each( function () {
				const $row = $( this );
				const url = $row.find( '.qlqr-dest-url' ).val().trim();

				if ( '' === url ) {
					return; // skip blank rows rather than erroring the whole save.
				}

				destinations.push( {
					destination_url: url,
					weight: parseInt( $row.find( '.qlqr-dest-weight' ).val(), 10 ) || 1,
					status: $row.find( '.qlqr-dest-active' ).is( ':checked' ) ? 'active' : 'disabled',
				} );
			} );

			return destinations;
		}

		/**
		 * Show/hide the single-URL field vs the multi-destination repeater based on which
		 * "Destination Type" radio is selected, and keep the required-field logic consistent
		 * (the single URL field is only actually required when that mode is active).
		 */
		function toggleDestinationTypeUI() {
			const isMultiple = $( '#qlqr-f-dest-type-multiple' ).is( ':checked' );

			$( '#qlqr-f-single-destination-wrap' ).toggle( ! isMultiple );
			$( '#qlqr-f-multi-destination-wrap' ).toggle( isMultiple );
			$( '#qlqr-f-destination' ).prop( 'required', ! isMultiple );

			// Only auto-seed two blank rows when a Pro account is actively switching into multiple
			// mode for the first time. A free-plan account only ever reaches isMultiple === true
			// here via a link that already has stored destinations (populated before this runs —
			// see the edit-modal populate code below), so the repeater is never empty in that case
			// and this stays a no-op for them.
			if ( QLQR.isPro && isMultiple && 0 === $( '#qlqr-destinations-repeater .qlqr-destination-row' ).length ) {
				addDestinationRow( {} );
				addDestinationRow( {} );
			}
		}

		$( document ).on( 'change', 'input[name="destination_type"]', function () {
			// The "Multiple" radio already renders (and is re-asserted below) with the HTML
			// `disabled` attribute for a free-plan account, so this shouldn't be reachable by a
			// real click — but if a change event ever reaches here anyway (e.g. an attribute lost
			// in transit), force the selection back to single rather than letting a free account
			// switch into multi-destination mode.
			if ( ! QLQR.isPro && 'multiple' === $( this ).val() ) {
				$( '#qlqr-f-dest-type-single' ).prop( 'checked', true );
			}

			toggleDestinationTypeUI();
		} );
		/**
		 * Turn a pasted block of text into destination URLs.
		 *
		 * Split on newlines, commas and whitespace, because a list copied out of a spreadsheet, a
		 * note or a chat message can arrive separated by any of them. Each candidate is then either
		 * accepted, corrected, or rejected — and the caller reports which, so nothing is silently
		 * dropped or silently rewritten.
		 *
		 * @param {string} text Raw pasted text.
		 * @return {Object} {urls, assumedScheme, invalid} — urls are ready to add, in paste order.
		 */
		function parseBulkDestinations( text ) {
			const urls = [];
			const seen = {};
			let assumedScheme = 0;
			let invalid = 0;

			String( text || '' ).split( /[\s,]+/ ).forEach( function ( raw ) {
				let candidate = raw.trim();

				if ( '' === candidate ) {
					return;
				}

				// A list pulled from a spreadsheet often has no scheme. Fill in https:// rather than
				// rejecting it, and count it so the summary can say so.
				if ( ! /^https?:\/\//i.test( candidate ) ) {
					// One or more dot-separated labels then a TLD: "example.com/a" and
					// "www.example.com/b" both qualify, "hello" and "ftp-ish" do not.
					if ( /^([a-z0-9-]+\.)+[a-z]{2,}([\/?#]|$)/i.test( candidate ) ) {
						candidate = 'https://' + candidate;
						assumedScheme++;
					} else {
						invalid++;
						return;
					}
				}

				// Reject anything the browser itself cannot parse as a URL with a host.
				try {
					const parsed = new URL( candidate );
					if ( ! parsed.hostname || ! parsed.hostname.includes( '.' ) ) {
						invalid++;
						return;
					}
				} catch ( e ) {
					invalid++;
					return;
				}

				const key = candidate.toLowerCase();
				if ( seen[ key ] ) {
					return; // duplicate within the pasted block itself
				}
				seen[ key ] = true;

				urls.push( candidate );
			} );

			return { urls: urls, assumedScheme: assumedScheme, invalid: invalid };
		}

		$( '#qlqr-bulk-dest-toggle' ).on( 'click', function () {
			if ( ! QLQR.isPro || $( this ).prop( 'disabled' ) ) {
				return;
			}
			$( '#qlqr-bulk-dest-panel' ).toggle();
			$( '#qlqr-bulk-dest-input' ).trigger( 'focus' );
		} );

		$( '#qlqr-bulk-dest-add' ).on( 'click', function () {
			if ( ! QLQR.isPro || $( this ).prop( 'disabled' ) ) {
				return;
			}
			const parsed = parseBulkDestinations( $( '#qlqr-bulk-dest-input' ).val() );
			const $result = $( '#qlqr-bulk-dest-result' );

			// URLs already in the list, so pasting an overlapping list twice is harmless.
			const existing = {};
			$( '#qlqr-destinations-repeater .qlqr-dest-url' ).each( function () {
				const value = $( this ).val().trim();
				if ( '' !== value ) {
					existing[ value.toLowerCase() ] = true;
				}
			} );

			let added = 0;
			let duplicate = 0;

			parsed.urls.forEach( function ( url ) {
				if ( existing[ url.toLowerCase() ] ) {
					duplicate++;
					return;
				}
				existing[ url.toLowerCase() ] = true;
				addDestinationRow( { destination_url: url } );
				added++;
			} );

			// Blank rows the editor started with are noise once real ones exist.
			if ( added > 0 ) {
				$( '#qlqr-destinations-repeater .qlqr-destination-row' ).each( function () {
					const $row = $( this );
					if ( '' === $row.find( '.qlqr-dest-url' ).val().trim() ) {
						$row.remove();
					}
				} );
			}

			const parts = [ added + __( ' added', 'plugnova-link-shortener-qr' ) ];
			if ( duplicate > 0 ) {
				parts.push( duplicate + __( ' already in the list', 'plugnova-link-shortener-qr' ) );
			}
			if ( parsed.assumedScheme > 0 ) {
				parts.push( parsed.assumedScheme + __( ' given https://', 'plugnova-link-shortener-qr' ) );
			}
			if ( parsed.invalid > 0 ) {
				parts.push( parsed.invalid + __( ' not a URL', 'plugnova-link-shortener-qr' ) );
			}

			$result.text( parts.join( ' · ' ) );

			if ( added > 0 ) {
				$( '#qlqr-bulk-dest-input' ).val( '' );
			}
		} );

		// Multi-destination rotation is a Pro feature. The "Multiple" radio, the add/paste-list
		// buttons and the wrapper's qlqr-pro-locked class already render disabled/dimmed for a
		// free-plan account (see templates/admin/links.php); this re-asserts the button states
		// from JS on load, same reasoning as the targeting-rule button below.
		if ( ! QLQR.isPro ) {
			$( '#qlqr-f-dest-type-multiple, #qlqr-add-destination, #qlqr-bulk-dest-toggle' ).prop( 'disabled', true );
		}

		$( '#qlqr-add-destination' ).on( 'click', function () {
			if ( ! QLQR.isPro || $( this ).prop( 'disabled' ) ) {
				return;
			}
			addDestinationRow( {} );
		} );
		$( document ).on( 'click', '.qlqr-dest-remove', function () {
			if ( ! QLQR.isPro ) {
				return;
			}
			$( this ).closest( '.qlqr-destination-row' ).remove();
		} );

		if ( $( '#qlqr-destinations-repeater' ).length && $.fn.sortable ) {
			$( '#qlqr-destinations-repeater' ).sortable( {
				handle: '.qlqr-destination-drag-handle',
				axis: 'y',
			} );
		}

		/**
		 * Build one repeater row for the Geo/Device targeting rules editor and append it.
		 *
		 * @param {Object} data {rule_type, match_value, destination_url, status} — all optional, defaults to a blank active "country" row.
		 */
		function addTargetingRuleRow( data ) {
			data = data || {};
			const ruleType = 'device' === data.rule_type ? 'device' : 'country';
			const matchValue = data.match_value || '';
			const url = data.destination_url || '';
			const checked = 'disabled' !== data.status ? 'checked' : '';
			const countryDisplay = 'country' === ruleType ? '' : 'display:none;';
			const deviceDisplay = 'device' === ruleType ? '' : 'display:none;';
			const selectedCountry = 'country' === ruleType ? matchValue.toUpperCase() : '';

			const countryOptions = Object.keys( QLQR_COUNTRIES ).map( function ( code ) {
				return '<option value="' + code + '"' + ( code === selectedCountry ? __( ' selected', 'plugnova-link-shortener-qr' ) : '' ) + '>' + esc( QLQR_COUNTRIES[ code ] ) + ' (' + code + ')</option>';
			} ).join( '' );

			const $row = $(
				'<div class="qlqr-targeting-row">' +
					( '<span class="qlqr-targeting-drag-handle" title="' + __( 'Drag to reorder', 'plugnova-link-shortener-qr' ) + '">⠿</span>' ) +
					'<select class="qlqr-targeting-type">' +
						'<option value="country"' + ( 'country' === ruleType ? __( ' selected', 'plugnova-link-shortener-qr' ) : '' ) + ( '>' + __( 'Country', 'plugnova-link-shortener-qr' ) + '</option>' ) +
						'<option value="device"' + ( 'device' === ruleType ? __( ' selected', 'plugnova-link-shortener-qr' ) : '' ) + ( '>' + __( 'Device', 'plugnova-link-shortener-qr' ) + '</option>' ) +
					'</select>' +
					'<select class="qlqr-targeting-value-country" style="' + countryDisplay + '">' +
						( '<option value="">' + __( 'Select a country…', 'plugnova-link-shortener-qr' ) + '</option>' ) +
						countryOptions +
					'</select>' +
					'<select class="qlqr-targeting-value-device" style="' + deviceDisplay + '">' +
						'<option value="mobile"' + ( 'mobile' === matchValue ? __( ' selected', 'plugnova-link-shortener-qr' ) : '' ) + ( '>' + __( 'Mobile', 'plugnova-link-shortener-qr' ) + '</option>' ) +
						'<option value="desktop"' + ( 'desktop' === matchValue ? __( ' selected', 'plugnova-link-shortener-qr' ) : '' ) + ( '>' + __( 'Desktop', 'plugnova-link-shortener-qr' ) + '</option>' ) +
						'<option value="tablet"' + ( 'tablet' === matchValue ? __( ' selected', 'plugnova-link-shortener-qr' ) : '' ) + ( '>' + __( 'Tablet', 'plugnova-link-shortener-qr' ) + '</option>' ) +
					'</select>' +
					( '<input type="url" class="qlqr-targeting-url" placeholder="' + __( 'https://example.com/page', 'plugnova-link-shortener-qr' ) + '" value="' ) + esc( url ) + '" />' +
					( '<label class="qlqr-targeting-status" title="' + __( 'Uncheck to temporarily skip this rule', 'plugnova-link-shortener-qr' ) + '"><input type="checkbox" class="qlqr-targeting-active" ' ) + checked + ( ' /> ' + __( 'Active', 'plugnova-link-shortener-qr' ) + '</label>' ) +
					( '<button type="button" class="qlqr-targeting-remove" title="' + __( 'Remove', 'plugnova-link-shortener-qr' ) + '">' + __( '&times;', 'plugnova-link-shortener-qr' ) + '</button>' ) +
				'</div>'
			);

			// Geo/Device targeting is a Pro feature. The server already ignores anything a
			// free-plan account submits for it (see LinkController::create()/update()), and the
			// repeater's own wrapper carries a CSS class that dims it and blocks pointer events
			// (see templates/admin/links.php), but this row is built from an HTML string above, so
			// its controls also get an explicit `disabled` here — belt and suspenders against any
			// caching layer or manual DOM edit that might strip the wrapper's styling/class alone.
			if ( ! QLQR.isPro ) {
				$row.find( 'select, input, button' ).prop( 'disabled', true );
			}

			$( '#qlqr-targeting-repeater' ).append( $row );
		}

		/**
		 * Read all targeting-rule repeater rows into a plain array ready to submit as payload.targeting_rules.
		 *
		 * @return {Array} Array of {rule_type, match_value, destination_url, status}.
		 */
		function collectTargetingRules() {
			const rules = [];

			$( '#qlqr-targeting-repeater .qlqr-targeting-row' ).each( function () {
				const $row = $( this );
				const url = $row.find( '.qlqr-targeting-url' ).val().trim();

				if ( '' === url ) {
					return; // skip blank rows rather than erroring the whole save.
				}

				const ruleType = $row.find( '.qlqr-targeting-type' ).val();
				const matchValue = 'device' === ruleType
					? $row.find( '.qlqr-targeting-value-device' ).val()
					: $row.find( '.qlqr-targeting-value-country' ).val().trim().toUpperCase();

				rules.push( {
					rule_type: ruleType,
					match_value: matchValue,
					destination_url: url,
					status: $row.find( '.qlqr-targeting-active' ).is( ':checked' ) ? 'active' : 'disabled',
				} );
			} );

			return rules;
		}

		$( document ).on( 'change', '.qlqr-targeting-type', function () {
			const $row = $( this ).closest( '.qlqr-targeting-row' );
			const isDevice = 'device' === $( this ).val();

			$row.find( '.qlqr-targeting-value-country' ).toggle( ! isDevice );
			$row.find( '.qlqr-targeting-value-device' ).toggle( isDevice );
		} );

		// Geo/Device targeting is a Pro feature. The button already renders with the HTML
		// `disabled` attribute for a free-plan account (see templates/admin/links.php); this
		// re-asserts the same state from JS on load, so the control stays genuinely inert even
		// if something between the server and the browser (a page cache, an optimizer that
		// strips attributes it doesn't recognize, etc.) ever loses that attribute in transit.
		if ( ! QLQR.isPro ) {
			$( '#qlqr-add-targeting-rule' ).prop( 'disabled', true );
		}

		$( '#qlqr-add-targeting-rule' ).on( 'click', function () {
			// Belt and suspenders with the above — a disabled button shouldn't dispatch click
			// events at all, but this keeps the create-a-row action blocked either way, and the
			// server ignores any targeting_rules a free account submits regardless.
			if ( ! QLQR.isPro || $( this ).prop( 'disabled' ) ) {
				return;
			}
			addTargetingRuleRow( {} );
		} );

		$( document ).on( 'click', '.qlqr-targeting-remove', function () {
			$( this ).closest( '.qlqr-targeting-row' ).remove();
		} );

		if ( $( '#qlqr-targeting-repeater' ).length && $.fn.sortable ) {
			$( '#qlqr-targeting-repeater' ).sortable( {
				handle: '.qlqr-targeting-drag-handle',
				axis: 'y',
			} );
		}

		/**
		 * Build one repeater row for the keyword auto-linking editor and append it. Keywords used to
		 * live in their own top-level tab with a "link to" dropdown; editing them here means the link
		 * they point at is simply the link being edited, so that dropdown is gone.
		 *
		 * @param {Object} data {keyword, case_sensitive, max_replacements, status} — all optional, defaults to a blank active row.
		 */
		function addKeywordRow( data ) {
			data = data || {};
			const keyword = data.keyword || '';
			const maxReplacements = parseInt( data.max_replacements, 10 ) > 0 ? parseInt( data.max_replacements, 10 ) : 1;
			const activeChecked = 'disabled' !== data.status ? 'checked' : '';
			const caseChecked = data.case_sensitive ? 'checked' : '';

			const $row = $(
				'<div class="qlqr-keyword-row">' +
					( '<input type="text" class="qlqr-keyword-text" maxlength="190" placeholder="' + __( 'e.g. running shoes', 'plugnova-link-shortener-qr' ) + '" value="' ) + esc( keyword ) + '" />' +
					( '<label class="qlqr-keyword-max" title="' + __( 'How many times this keyword may be linked in a single post or page', 'plugnova-link-shortener-qr' ) + '">' + __( 'Max', 'plugnova-link-shortener-qr' ) + ' ' ) +
						'<input type="number" class="qlqr-keyword-max-input" min="1" max="50" value="' + maxReplacements + '" />' +
					'</label>' +
					( '<label class="qlqr-keyword-case" title="' + __( 'Only match text whose capitalisation is identical', 'plugnova-link-shortener-qr' ) + '"><input type="checkbox" class="qlqr-keyword-case-input" ' ) + caseChecked + ( ' /> ' + __( 'Case-sensitive', 'plugnova-link-shortener-qr' ) + '</label>' ) +
					( '<label class="qlqr-keyword-status" title="' + __( 'Uncheck to temporarily stop auto-linking this keyword', 'plugnova-link-shortener-qr' ) + '"><input type="checkbox" class="qlqr-keyword-active" ' ) + activeChecked + ( ' /> ' + __( 'Active', 'plugnova-link-shortener-qr' ) + '</label>' ) +
					( '<button type="button" class="qlqr-keyword-remove" title="' + __( 'Remove', 'plugnova-link-shortener-qr' ) + '">' + __( '&times;', 'plugnova-link-shortener-qr' ) + '</button>' ) +
				'</div>'
			);

			$( '#qlqr-keywords-repeater' ).append( $row );
		}

		/**
		 * Read all keyword repeater rows into a plain array ready to submit as payload.keywords.
		 *
		 * @return {Array} Array of {keyword, case_sensitive, max_replacements, status}.
		 */
		function collectKeywords() {
			const keywords = [];

			$( '#qlqr-keywords-repeater .qlqr-keyword-row' ).each( function () {
				const $row = $( this );
				const text = $row.find( '.qlqr-keyword-text' ).val().trim();

				if ( '' === text ) {
					return; // skip blank rows rather than erroring the whole save.
				}

				keywords.push( {
					keyword: text,
					case_sensitive: $row.find( '.qlqr-keyword-case-input' ).is( ':checked' ) ? 1 : 0,
					max_replacements: parseInt( $row.find( '.qlqr-keyword-max-input' ).val(), 10 ) || 1,
					status: $row.find( '.qlqr-keyword-active' ).is( ':checked' ) ? 'active' : 'disabled',
				} );
			} );

			return keywords;
		}

		$( '#qlqr-add-keyword' ).on( 'click', function () {
			addKeywordRow( {} );
		} );

		$( document ).on( 'click', '.qlqr-keyword-remove', function () {
			$( this ).closest( '.qlqr-keyword-row' ).remove();
		} );

		/**
		 * Reset the shared modal back to "Create Short Link" mode: clears all fields, re-enables
		 * the slug field, hides edit-only notes, and relabels the header/button.
		 */
		function resetModalToCreateMode() {
			$( '#qlqr-create-form' )[ 0 ].reset();
			$( '#qlqr-f-edit-id' ).val( '' );
			$( '#qlqr-modal-title' ).text( __( 'Create Short Link', 'plugnova-link-shortener-qr' ) );
			$( '#qlqr-form-submit-btn' ).text( __( 'Create Link', 'plugnova-link-shortener-qr' ) );
			$( '#qlqr-f-slug' ).prop( 'disabled', false );
			$( '#qlqr-f-slug-locked-note' ).hide();
			$( '#qlqr-f-password-note, #qlqr-f-clear-password-wrap' ).hide();
			$( '#qlqr-form-error' ).hide().text( '' );
			$( '#qlqr-destinations-repeater' ).empty();
			$( '#qlqr-bulk-dest-panel' ).hide();
			$( '#qlqr-bulk-dest-input' ).val( '' );
			$( '#qlqr-bulk-dest-result' ).text( '' );
			$( '#qlqr-f-dest-type-single' ).prop( 'checked', true );
			toggleDestinationTypeUI();
			$( '#qlqr-targeting-repeater' ).empty();
			$( '#qlqr-keywords-repeater' ).empty();
			setQrLogoPreview( '', '' );
			setColorLabel( '' );
			$( '#qlqr-modal' ).data( 'dirty', false );
		}

		/**
		 * Update the QR logo picker's hidden attachment-id field and thumbnail preview, and
		 * toggle the Select/Remove buttons accordingly. Shared by the reset, edit-populate, and
		 * media-frame-selection code paths so they stay in sync.
		 *
		 * @param {number|string} attachmentId Attachment ID, or '' to clear.
		 * @param {string}        url          Thumbnail URL, or '' to clear.
		 */
		function setQrLogoPreview( attachmentId, url ) {
			$( '#qlqr-f-qr-logo-id' ).val( attachmentId || '' );
			$( '#qlqr-f-qr-logo-preview' ).attr( 'src', url || '' );
			$( '#qlqr-f-qr-logo-preview-wrap' ).toggle( !! url );
			$( '#qlqr-f-qr-logo-remove' ).toggle( !! attachmentId );
		}

		/**
		 * Update the color-label hidden field and highlight the matching swatch button.
		 *
		 * @param {string} color Hex color (e.g. "#2271b1"), or '' for no color.
		 */
		function setColorLabel( color ) {
			$( '#qlqr-f-color-label' ).val( color || '' );
			$( '.qlqr-color-swatch' ).removeClass( 'qlqr-color-swatch-selected' );
			$( '.qlqr-color-swatch' ).filter( function () {
				return $( this ).data( 'color' ) === ( color || '' );
			} ).addClass( 'qlqr-color-swatch-selected' );
		}

		$( document ).on( 'click', '.qlqr-color-swatch', function () {
			setColorLabel( $( this ).data( 'color' ) || '' );
		} );

		let qrLogoMediaFrame = null;

		$( '#qlqr-f-qr-logo-select' ).on( 'click', function ( e ) {
			e.preventDefault();

			if ( 'undefined' === typeof wp || ! wp.media ) {
				return;
			}

			if ( qrLogoMediaFrame ) {
				qrLogoMediaFrame.open();
				return;
			}

			qrLogoMediaFrame = wp.media( {
				title: __( 'Select Brand Logo', 'plugnova-link-shortener-qr' ),
				button: { text: __( 'Use this logo', 'plugnova-link-shortener-qr' ) },
				library: { type: 'image' },
				multiple: false,
			} );

			qrLogoMediaFrame.on( 'select', function () {
				const attachment = qrLogoMediaFrame.state().get( 'selection' ).first().toJSON();
				const thumbUrl = ( attachment.sizes && ( attachment.sizes.thumbnail || attachment.sizes.medium ) )
					? ( attachment.sizes.thumbnail || attachment.sizes.medium ).url
					: attachment.url;
				setQrLogoPreview( attachment.id, thumbUrl );
			} );

			qrLogoMediaFrame.open();
		} );

		$( '#qlqr-f-qr-logo-remove' ).on( 'click', function () {
			setQrLogoPreview( '', '' );
		} );

		/**
		 * Chart.js instance for the per-link analytics modal, kept so it can be destroyed and
		 * recreated cleanly each time the modal opens for a (possibly different) link.
		 */
		let analyticsChart = null;

		/**
		 * Render a small ranked table (device/browser/country/referrer breakdown) into a container.
		 *
		 * @param {string} selector jQuery selector for the target <table>.
		 * @param {Array}  rows     Array of {label, clicks}.
		 */
		function renderAnalyticsTable( selector, rows ) {
			if ( ! rows || 0 === rows.length ) {
				$( selector ).html( ( '<tr><td>' + __( 'No data yet.', 'plugnova-link-shortener-qr' ) + '</td></tr>' ) );
				return;
			}

			const html = rows.map( function ( row ) {
				return '<tr><td>' + esc( row.label ) + '</td><td style="text-align:right;">' + row.clicks + '</td></tr>';
			} ).join( '' );

			$( selector ).html( html );
		}

		/**
		 * Show the Smart Link Score breakdown modal for a link. Reads from linkRowCache — the
		 * whole checklist already arrived as part of the table's row data (see
		 * Admin\AjaxHandler::serialize()), so this needs no extra AJAX round-trip.
		 *
		 * @param {number} id Link ID.
		 */
		function openScoreModal( id ) {
			const link = linkRowCache[ id ];
			if ( ! link || ! link.score ) {
				return;
			}

			$( '#qlqr-score-title' ).text( link.title || link.short_slug );
			$( '#qlqr-score-grade' )
				.text( link.score.grade )
				.attr( 'class', 'qlqr-score-grade-badge qlqr-grade-' + link.score.grade.toLowerCase() );
			$( '#qlqr-score-percentage' ).text( link.score.percentage + '% (' + link.score.score + '/' + link.score.max_score + ')' );

			const itemsHtml = link.score.checks.map( function ( check ) {
				const icon = null === check.passed ? '❔' : ( check.passed ? '✅' : '⚠️' );
				const rowClass = null === check.passed ? 'qlqr-score-item-pending' : ( check.passed ? 'qlqr-score-item-pass' : 'qlqr-score-item-fail' );
				const pointsText = 0 !== check.points ? ' (' + ( check.points > 0 ? '+' : '' ) + check.points + ')' : '';
				return '<li class="qlqr-score-item ' + rowClass + '">' + icon + ' ' + esc( check.label ) + pointsText + '</li>';
			} ).join( '' );

			$( '#qlqr-score-checklist' ).html( itemsHtml );
			$( '#qlqr-score-modal' ).show().attr( 'aria-hidden', 'false' );
		}

		/**
		 * Show the Live Destination Preview modal: an iframe pointed at the destination URL, with
		 * a fallback "Open in new tab" link for sites that block being embedded (most browsers
		 * silently render a blank frame in that case — there's no reliable cross-origin way to
		 * detect it from JS, so the note next to the iframe just sets that expectation up front).
		 *
		 * @param {string} url Destination URL to preview.
		 */
		function openDestinationPreviewModal( url ) {
			$( '#qlqr-preview-url-text' ).text( url );
			$( '#qlqr-preview-open-tab' ).attr( 'href', url );
			$( '#qlqr-preview-iframe' ).attr( 'src', url );
			$( '#qlqr-preview-modal' ).show().attr( 'aria-hidden', 'false' );
		}

		// Stop the preview iframe from continuing to load/run in the background once its modal
		// closes via its own × button (the generic .qlqr-modal-close handler above still runs
		// too — this only adds the iframe cleanup on top of it).
		$( '#qlqr-preview-close' ).on( 'click', function () {
			$( '#qlqr-preview-iframe' ).attr( 'src', '' );
		} );

		/**
		 * Link ID currently shown in the per-link analytics modal, so the Date Range / Include
		 * Bot Traffic controls (which live inside the modal, not the row that opened it) know
		 * what to re-fetch when changed.
		 */
		let analyticsCurrentLinkId = null;

		/**
		 * Fetch and display the per-link analytics modal (clicks over time + breakdowns) for a
		 * single link, honoring the modal's own Date Range / Include Bot Traffic controls.
		 *
		 * @param {number}  id        Link ID.
		 * @param {boolean} resetControls Whether to reset the Date Range/Bot Traffic controls back
		 *                                 to their defaults first — true when opening for a (possibly
		 *                                 different) link, false when just re-fetching after the
		 *                                 admin changed one of those controls.
		 */
		function openAnalyticsModal( id, resetControls ) {
			analyticsCurrentLinkId = id;

			if ( false !== resetControls ) {
				$( '#qlqr-analytics-days' ).val( '30' );
				$( '#qlqr-analytics-include-bots' ).prop( 'checked', false );
			}

			applyAnalyticsPlanLimit( $( '#qlqr-analytics-days' ) );

			$( '#qlqr-analytics-modal' ).show().attr( 'aria-hidden', 'false' );
			$( '#qlqr-analytics-title' ).text( __( 'Loading…', 'plugnova-link-shortener-qr' ) );
			$( '#qlqr-analytics-url' ).text( '' );
			$( '#qlqr-analytics-total, #qlqr-analytics-unique, #qlqr-analytics-qr-scans, #qlqr-analytics-bots' ).text( '—' );
			$( '#qlqr-analytics-devices, #qlqr-analytics-browsers, #qlqr-analytics-os, #qlqr-analytics-countries, #qlqr-analytics-referrers, #qlqr-analytics-utm' ).html( '' );
			$( '#qlqr-analytics-destinations-wrap' ).hide();
			$( '#qlqr-analytics-destinations' ).html( '' );

			const days = $( '#qlqr-analytics-days' ).val();
			const includeBots = $( '#qlqr-analytics-include-bots' ).is( ':checked' ) ? 1 : 0;

			qlqrAjax( 'qlqr_link_analytics', { id: id, days: days, include_bots: includeBots } ).done( function ( response ) {
				if ( ! response.success ) {
					window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
					$( '#qlqr-analytics-modal' ).hide().attr( 'aria-hidden', 'true' );
					return;
				}

				const data = response.data;

				$( '#qlqr-analytics-title' ).text( data.title );
				$( '#qlqr-analytics-url' ).text( data.short_url );
				$( '#qlqr-analytics-total' ).text( data.total_clicks.toLocaleString() );
				$( '#qlqr-analytics-unique' ).text( data.unique_clicks.toLocaleString() );
				$( '#qlqr-analytics-qr-scans' ).text( ( data.qr_scans || 0 ).toLocaleString() );
				$( '#qlqr-analytics-bots' ).text( ( data.bot_clicks || 0 ).toLocaleString() );

				if ( data.is_multi_destination && data.destinations && data.destinations.length ) {
					const methodLabels = {
						round_robin: __( 'Round Robin', 'plugnova-link-shortener-qr' ),
						random: __( 'Random', 'plugnova-link-shortener-qr' ),
						weighted_random: __( 'Weighted Random', 'plugnova-link-shortener-qr' ),
					};
					$( '#qlqr-analytics-rotation-method' ).text( __( 'Rotation method: ', 'plugnova-link-shortener-qr' ) + ( methodLabels[ data.rotation_method ] || data.rotation_method ) + ' · ' + data.destinations.length + __( ' destinations', 'plugnova-link-shortener-qr' ) );

					const rows = data.destinations.map( function ( d ) {
						const statusBadge = 'active' === d.status
							? ( '<span class="qlqr-status-pill qlqr-status-active">' + __( 'Active', 'plugnova-link-shortener-qr' ) + '</span>' )
							: ( '<span class="qlqr-status-pill qlqr-status-disabled">' + __( 'Disabled', 'plugnova-link-shortener-qr' ) + '</span>' );
						return '<tr><td class="qlqr-truncate" title="' + esc( d.destination_url ) + '">' + esc( d.destination_url ) + '</td>' +
							'<td>' + d.clicks.toLocaleString() + '</td>' +
							'<td>' + d.percentage + '%</td>' +
							'<td>' + statusBadge + '</td></tr>';
					} ).join( '' );

					$( '#qlqr-analytics-destinations' ).html( rows );
					$( '#qlqr-analytics-destinations-wrap' ).show();
				}

				renderAnalyticsTable( '#qlqr-analytics-devices', data.by_device );
				renderAnalyticsTable( '#qlqr-analytics-browsers', data.by_browser );
				renderAnalyticsTable( '#qlqr-analytics-os', data.by_os );
				renderAnalyticsTable( '#qlqr-analytics-countries', data.by_country );
				renderAnalyticsTable( '#qlqr-analytics-referrers', data.by_referrer );
				renderAnalyticsTable( '#qlqr-analytics-utm', data.by_utm_campaign );

				if ( 'undefined' !== typeof Chart ) {
					if ( analyticsChart ) {
						analyticsChart.destroy();
					}

					const ctx = document.getElementById( 'qlqr-analytics-chart' ).getContext( '2d' );
					analyticsChart = new Chart( ctx, {
						type: 'line',
						data: {
							labels: data.clicks_by_day.map( function ( d ) { return d.date; } ),
							datasets: [ {
								label: __( 'Clicks', 'plugnova-link-shortener-qr' ),
								data: data.clicks_by_day.map( function ( d ) { return d.clicks; } ),
								backgroundColor: 'rgba(34, 113, 177, 0.5)',
								borderColor: 'rgba(34, 113, 177, 1)',
								borderWidth: 2,
								tension: 0.3,
								fill: true,
							} ],
						},
						options: {
							responsive: true,
							plugins: { legend: { display: false } },
							scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
						},
					} );
				}
			} ).fail( function () {
				window.alert( QLQR.i18n.genericError );
				$( '#qlqr-analytics-modal' ).hide().attr( 'aria-hidden', 'true' );
			} );
		}

		$( '#qlqr-analytics-days, #qlqr-analytics-include-bots' ).on( 'change', function () {
			if ( null !== analyticsCurrentLinkId ) {
				openAnalyticsModal( analyticsCurrentLinkId, false );
			}
		} );

		$( '#qlqr-analytics-export-csv' ).on( 'click', function () {
			if ( null === analyticsCurrentLinkId ) {
				return;
			}

			const days = $( '#qlqr-analytics-days' ).val();
			const includeBots = $( '#qlqr-analytics-include-bots' ).is( ':checked' ) ? 1 : 0;
			const url = QLQR.ajaxUrl + '?action=qlqr_export_link_analytics_csv' +
				'&nonce=' + encodeURIComponent( QLQR.nonce ) +
				'&id=' + encodeURIComponent( analyticsCurrentLinkId ) +
				'&days=' + encodeURIComponent( days ) +
				'&include_bots=' + includeBots;

			window.location.href = url;
		} );

		/**
		 * Fetch a link's full details and populate the modal in "Edit" mode.
		 *
		 * @param {number} id Link ID.
		 */
		function openEditModal( id ) {
			qlqrAjax( 'qlqr_get_link', { id: id } ).done( function ( response ) {
				if ( ! response.success ) {
					window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
					return;
				}

				const link = response.data.link;

				resetModalToCreateMode();

				$( '#qlqr-f-edit-id' ).val( link.id );
				$( '#qlqr-modal-title' ).text( __( 'Edit Short Link', 'plugnova-link-shortener-qr' ) );
				$( '#qlqr-form-submit-btn' ).text( __( 'Save Changes', 'plugnova-link-shortener-qr' ) );

				// The slug is permanent once a link exists, so it's shown but disabled, with an
				// explanatory note instead of an editable field.
				$( '#qlqr-f-slug' ).val( link.short_slug ).prop( 'disabled', true );
				$( '#qlqr-f-slug-locked-note' ).show();

				$( '#qlqr-f-title' ).val( link.title );
				$( '#qlqr-f-category' ).val( link.category || '' );
				$( '#qlqr-f-destination' ).val( link.destination_url );
				$( '#qlqr-f-redirect' ).val( link.redirect_type );
				$( '#qlqr-f-status' ).val( link.status );
				$( '#qlqr-f-qr-style' ).val( link.qr_style );
				$( '#qlqr-f-qr-fg' ).val( link.qr_fg_color );
				$( '#qlqr-f-qr-bg' ).val( link.qr_bg_color );
				$( '#qlqr-f-qr-caption' ).val( link.qr_caption_text || '' );
				setQrLogoPreview( link.qr_logo_id || '', link.qr_logo_url || '' );
				$( '#qlqr-f-expires' ).val( link.expires_at || '' );
				$( '#qlqr-f-limit' ).val( link.click_limit || '' );
				$( '#qlqr-f-utm-source' ).val( link.utm_source || '' );
				$( '#qlqr-f-utm-medium' ).val( link.utm_medium || '' );
				$( '#qlqr-f-utm-campaign' ).val( link.utm_campaign || '' );
				$( '#qlqr-f-nofollow' ).prop( 'checked', !! link.nofollow );
				$( '#qlqr-f-sponsored' ).prop( 'checked', !! link.sponsored );
				$( '#qlqr-f-new-tab' ).prop( 'checked', !! link.new_tab );
				$( '#qlqr-f-notes' ).val( link.notes || '' );
				setColorLabel( link.color_label || '' );

				if ( 'multiple' === link.destination_type ) {
					$( '#qlqr-f-dest-type-multiple' ).prop( 'checked', true );
					$( '#qlqr-f-rotation-method' ).val( link.rotation_method || 'round_robin' );
					$( '#qlqr-f-fallback-url' ).val( link.fallback_url || '' );

					$( '#qlqr-destinations-repeater' ).empty();
					( link.destinations || [] ).forEach( function ( destination ) {
						addDestinationRow( destination );
					} );
				}
				toggleDestinationTypeUI();

				$( '#qlqr-targeting-repeater' ).empty();
				( link.targeting_rules || [] ).forEach( function ( rule ) {
					addTargetingRuleRow( rule );
				} );

				$( '#qlqr-keywords-repeater' ).empty();
				( link.keywords || [] ).forEach( function ( keyword ) {
					addKeywordRow( keyword );
				} );

				// Never populate the password field with anything real — just show a note that
				// blank means "keep current", plus an explicit checkbox to remove protection.
				$( '#qlqr-f-password' ).val( '' );
				if ( link.has_password ) {
					$( '#qlqr-f-password-note, #qlqr-f-clear-password-wrap' ).show();
				}

				$( '#qlqr-modal' ).show().attr( 'aria-hidden', 'false' );
			} );
		}

		$( '#qlqr-open-create-modal' ).on( 'click', function () {
			resetModalToCreateMode();
			$( '#qlqr-modal' ).show().attr( 'aria-hidden', 'false' );
		} );

		/**
		 * Render the import result summary (imported/skipped counts + first few errors) and
		 * refresh the links table/category list so newly imported links show up immediately.
		 *
		 * @param {Object} result {imported, skipped, errors}
		 */
		function renderImportResult( result ) {
			let html = '<p><strong>' + result.imported + ( '</strong> ' + __( 'link(s) imported,', 'plugnova-link-shortener-qr' ) + ' ' + '<strong>' ) + result.skipped + ( '</strong> ' + __( 'skipped as duplicates.', 'plugnova-link-shortener-qr' ) + '</p>' );

			if ( result.errors && result.errors.length ) {
				html += ( '<p class="description">' + __( 'Some rows could not be imported:', 'plugnova-link-shortener-qr' ) + '</p><ul>' );
				result.errors.forEach( function ( err ) {
					html += '<li class="description">' + esc( err ) + '</li>';
				} );
				html += '</ul>';
			}

			$( '#qlqr-import-result' ).html( html ).show();
			loadLinks();
			loadCategoryOptions();
		}

		/**
		 * Fetch which other plugins have importable data on this site and render a button for
		 * each one found.
		 */
		function loadMigrateSources() {
			$( '#qlqr-migrate-sources' ).html( ( '<p class="description">' + __( 'Checking for data from other plugins…', 'plugnova-link-shortener-qr' ) + '</p>' ) );

			qlqrAjax( 'qlqr_detect_migration_sources', {} ).done( function ( response ) {
				if ( ! response.success ) {
					$( '#qlqr-migrate-sources' ).html( '<p class="description">' + esc( QLQR.i18n.genericError ) + '</p>' );
					return;
				}

				const sources = response.data;
				const labels = {
					pretty_links: __( 'Pretty Links', 'plugnova-link-shortener-qr' ),
					thirsty_affiliates: __( 'ThirstyAffiliates', 'plugnova-link-shortener-qr' ),
				};

				let html = '';
				Object.keys( labels ).forEach( function ( key ) {
					const count = sources[ key ] || 0;
					if ( count > 0 ) {
						html += '<p><button type="button" class="button qlqr-migrate-btn" data-source="' + key + '">' +
							__( 'Import ', 'plugnova-link-shortener-qr' ) + count + __( ' link(s) from ', 'plugnova-link-shortener-qr' ) + labels[ key ] + '</button></p>';
					}
				} );

				$( '#qlqr-migrate-sources' ).html( html || ( '<p class="description">' + __( 'No data found from other supported plugins on this site.', 'plugnova-link-shortener-qr' ) + '</p>' ) );
			} );
		}

		$( '#qlqr-open-import-modal' ).on( 'click', function () {
			$( '#qlqr-import-result' ).hide().html( '' );
			$( '#qlqr-import-csv-form' )[ 0 ].reset();
			loadMigrateSources();
			$( '#qlqr-import-modal' ).show().attr( 'aria-hidden', 'false' );
		} );

		$( '#qlqr-import-csv-form' ).on( 'submit', function ( e ) {
			e.preventDefault();

			const fileInput = document.getElementById( 'qlqr-import-csv-file' );
			if ( ! fileInput.files.length ) {
				return;
			}

			const formData = new FormData();
			formData.append( 'action', 'qlqr_import_csv' );
			formData.append( 'nonce', QLQR.nonce );
			formData.append( 'file', fileInput.files[ 0 ] );
			formData.append( 'skip_duplicates', $( '#qlqr-import-skip-duplicates' ).is( ':checked' ) ? 1 : 0 );

			const $btn = $( '#qlqr-import-csv-submit' ).prop( 'disabled', true ).text( __( 'Importing…', 'plugnova-link-shortener-qr' ) );

			$.ajax( {
				url: QLQR.ajaxUrl,
				method: __( 'POST', 'plugnova-link-shortener-qr' ),
				data: formData,
				processData: false,
				contentType: false,
			} ).done( function ( response ) {
				$btn.prop( 'disabled', false ).text( __( 'Import CSV', 'plugnova-link-shortener-qr' ) );

				if ( ! response.success ) {
					window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
					return;
				}

				renderImportResult( response.data );
			} ).fail( function () {
				$btn.prop( 'disabled', false ).text( __( 'Import CSV', 'plugnova-link-shortener-qr' ) );
				window.alert( QLQR.i18n.genericError );
			} );
		} );

		$( document ).on( 'click', '.qlqr-migrate-btn', function () {
			const $btn = $( this ).prop( 'disabled', true ).text( __( 'Importing…', 'plugnova-link-shortener-qr' ) );
			const source = $btn.data( 'source' );
			const skipDuplicates = $( '#qlqr-import-skip-duplicates' ).is( ':checked' ) ? 1 : 0;

			qlqrAjax( 'qlqr_migrate_links', { source: source, skip_duplicates: skipDuplicates } ).done( function ( response ) {
				$btn.prop( 'disabled', false );

				if ( ! response.success ) {
					window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
					return;
				}

				renderImportResult( response.data );
				loadMigrateSources();
			} ).fail( function () {
				$btn.prop( 'disabled', false );
				window.alert( QLQR.i18n.genericError );
			} );
		} );

		$( '#qlqr-create-form' ).on( 'submit', function ( e ) {
			e.preventDefault();

			const $form = $( this );
			const $error = $( '#qlqr-form-error' ).hide().text( '' );
			const editId = $( '#qlqr-f-edit-id' ).val();
			const isEdit = '' !== editId;
			const isMultiple = $( '#qlqr-f-dest-type-multiple' ).is( ':checked' );

			if ( isMultiple && 0 === collectDestinations().length ) {
				$error.text( __( 'Please add at least one valid destination URL.', 'plugnova-link-shortener-qr' ) ).show();
				return;
			}

			const payload = {
				title: $( '#qlqr-f-title' ).val(),
				category: $( '#qlqr-f-category' ).val(),
				destination_url: $( '#qlqr-f-destination' ).val(),
				custom_slug: $( '#qlqr-f-slug' ).val(),
				redirect_type: $( '#qlqr-f-redirect' ).val(),
				status: $( '#qlqr-f-status' ).val(),
				qr_style: $( '#qlqr-f-qr-style' ).val(),
				qr_fg_color: $( '#qlqr-f-qr-fg' ).val(),
				qr_bg_color: $( '#qlqr-f-qr-bg' ).val(),
				qr_transparent: $( '#qlqr-f-qr-transparent' ).is( ':checked' ) ? 1 : 0,
				qr_logo_id: $( '#qlqr-f-qr-logo-id' ).val(),
				qr_caption_text: $( '#qlqr-f-qr-caption' ).val(),
				expires_at: $( '#qlqr-f-expires' ).val(),
				click_limit: $( '#qlqr-f-limit' ).val(),
				password: $( '#qlqr-f-password' ).val(),
				utm_source: $( '#qlqr-f-utm-source' ).val(),
				utm_medium: $( '#qlqr-f-utm-medium' ).val(),
				utm_campaign: $( '#qlqr-f-utm-campaign' ).val(),
				nofollow: $( '#qlqr-f-nofollow' ).is( ':checked' ) ? 1 : 0,
				sponsored: $( '#qlqr-f-sponsored' ).is( ':checked' ) ? 1 : 0,
				new_tab: $( '#qlqr-f-new-tab' ).is( ':checked' ) ? 1 : 0,
				notes: $( '#qlqr-f-notes' ).val(),
				color_label: $( '#qlqr-f-color-label' ).val(),
				destination_type: $( '#qlqr-f-dest-type-multiple' ).is( ':checked' ) ? 'multiple' : 'single',
				rotation_method: $( '#qlqr-f-rotation-method' ).val(),
				fallback_url: $( '#qlqr-f-fallback-url' ).val(),
			};

			if ( 'multiple' === payload.destination_type ) {
				payload.destinations = collectDestinations();
			}

			// jQuery's $.param() silently drops keys whose value is an empty array, so "the user
			// removed every rule" would otherwise arrive server-side with no targeting_rules key at
			// all — and the server treats a missing key as "leave rules unchanged". Send an empty
			// string instead: the key survives serialization, and the server reads it as "clear all".
			payload.targeting_rules = collectTargetingRules();
			if ( 0 === payload.targeting_rules.length ) {
				payload.targeting_rules = '';
			}

			// Same empty-array caveat as targeting_rules above.
			payload.keywords = collectKeywords();
			if ( 0 === payload.keywords.length ) {
				payload.keywords = '';
			}

			if ( isEdit ) {
				payload.clear_password = $( '#qlqr-f-clear-password' ).is( ':checked' ) ? 1 : 0;
			}

			const ajaxAction = isEdit ? 'qlqr_update_link' : 'qlqr_create_link';
			const ajaxData = isEdit ? { id: editId, data: payload } : { data: payload };

			qlqrAjax( ajaxAction, ajaxData ).done( function ( response ) {
				if ( ! response.success ) {
					renderAjaxError( $error, response );
					$error.show();
					return;
				}

				resetModalToCreateMode();
				$( '#qlqr-modal' ).hide().attr( 'aria-hidden', 'true' );
				loadLinks();
				loadCategoryOptions();

				if ( response.data.qr_warning ) {
					const prefix = isEdit ? __( 'Link saved, but the QR code could not be regenerated:\n\n', 'plugnova-link-shortener-qr' ) : __( 'Link created, but the QR code could not be generated:\n\n', 'plugnova-link-shortener-qr' );
					window.alert( prefix + response.data.qr_warning );
				}
			} ).fail( function () {
				$error.text( QLQR.i18n.genericError ).show();
			} );
		} );

		$( document ).on( 'click', '.qlqr-act', function () {
			const $btn = $( this );
			const action = $btn.data( 'action' );
			const id = $btn.data( 'id' );

			if ( 'copy' === action ) {
				navigator.clipboard.writeText( $btn.data( 'url' ) ).then( function () {
					window.alert( QLQR.i18n.copied );
				} );
				return;
			}

			if ( 'edit' === action ) {
				openEditModal( id );
				return;
			}

			if ( 'view-analytics' === action ) {
				openAnalyticsModal( id );
				return;
			}

			if ( 'view-score' === action ) {
				openScoreModal( id );
				return;
			}

			if ( 'preview-destination' === action ) {
				openDestinationPreviewModal( $btn.data( 'url' ) );
				return;
			}

			if ( 'trash' === action && ! window.confirm( QLQR.i18n.confirmDelete ) ) {
				return;
			}

			if ( 'delete' === action && ! window.confirm( QLQR.i18n.confirmPermDel ) ) {
				return;
			}

			const actionMap = {
				trash: 'qlqr_trash_link',
				restore: 'qlqr_restore_link',
				delete: 'qlqr_delete_link',
				'toggle-status': 'qlqr_toggle_status',
				'toggle-favorite': 'qlqr_toggle_favorite',
				'regenerate-qr': 'qlqr_regenerate_qr',
				'recheck-link': 'qlqr_recheck_link',
				clone: 'qlqr_clone_link',
			};

			const ajaxAction = actionMap[ action ];
			if ( ! ajaxAction ) {
				return;
			}

			qlqrAjax( ajaxAction, { id: id } ).done( function ( response ) {
				if ( ! response.success ) {
					window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
					return;
				}
				if ( response.data && response.data.qr_warning ) {
					window.alert( __( 'QR code could not be generated:\n\n', 'plugnova-link-shortener-qr' ) + response.data.qr_warning );
				}
				loadLinks();
			} );
		} );

		$( '#qlqr-export-csv' ).on( 'click', function () {
			const url = QLQR.ajaxUrl + '?action=qlqr_export_csv&nonce=' + encodeURIComponent( QLQR.nonce );
			window.location.href = url;
		} );

		$( '#qlqr-recheck-all' ).on( 'click', function () {
			const $btn = $( this ).prop( 'disabled', true ).text( __( 'Checking links…', 'plugnova-link-shortener-qr' ) );

			qlqrAjax( 'qlqr_recheck_all_links', {} ).done( function ( response ) {
				$btn.prop( 'disabled', false ).text( __( 'Check All Links', 'plugnova-link-shortener-qr' ) );

				if ( ! response.success ) {
					window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
					return;
				}

				window.alert( __( 'Checked ', 'plugnova-link-shortener-qr' ) + response.data.checked + __( ' link(s) — ', 'plugnova-link-shortener-qr' ) + response.data.broken + __( ' currently broken.', 'plugnova-link-shortener-qr' ) );
				loadLinks();
			} ).fail( function () {
				$btn.prop( 'disabled', false ).text( __( 'Check All Links', 'plugnova-link-shortener-qr' ) );
				window.alert( QLQR.i18n.genericError );
			} );
		} );

		$( '#qlqr-select-all' ).on( 'change', function () {
			$( '.qlqr-row-select' ).prop( 'checked', $( this ).is( ':checked' ) );
			updateBulkApplyState();
		} );

		$( document ).on( 'change', '.qlqr-row-select', function () {
			if ( ! $( this ).is( ':checked' ) ) {
				$( '#qlqr-select-all' ).prop( 'checked', false );
			}
			updateBulkApplyState();
		} );

		const BULK_ACTIONS_NEEDING_VALUE = [ 'set_category', 'add_tag' ];

		// "Set Redirect Type" takes a value too, but from a dropdown of the three valid codes rather
		// than the free-text box the other two use.
		const BULK_ACTION_REDIRECT_TYPE = 'set_redirect_type';

		$( '#qlqr-bulk-action' ).on( 'change', function () {
			const action = $( this ).val();
			$( '#qlqr-bulk-value' ).toggle( BULK_ACTIONS_NEEDING_VALUE.includes( action ) ).val( '' );
			$( '#qlqr-bulk-redirect-type' ).toggle( BULK_ACTION_REDIRECT_TYPE === action );
			updateBulkApplyState();
		} );

		$( '#qlqr-bulk-apply' ).on( 'click', function () {
			const action = $( '#qlqr-bulk-action' ).val();
			const value = BULK_ACTION_REDIRECT_TYPE === action
				? $( '#qlqr-bulk-redirect-type' ).val()
				: $( '#qlqr-bulk-value' ).val().trim();
			const ids = $( '.qlqr-row-select:checked' ).map( function () {
				return $( this ).val();
			} ).get();

			if ( '' === action || 0 === ids.length ) {
				return;
			}

			if ( BULK_ACTIONS_NEEDING_VALUE.includes( action ) && '' === value ) {
				window.alert( __( 'Please enter a value first.', 'plugnova-link-shortener-qr' ) );
				return;
			}

			if ( 'delete' === action && ! window.confirm( QLQR.i18n.confirmPermDel ) ) {
				return;
			}

			if ( 'trash' === action && ! window.confirm( QLQR.i18n.confirmDelete ) ) {
				return;
			}

			// Not a real server-side bulk action: it just narrows the existing CSV export
			// endpoint to the selected IDs via a query string, same as the toolbar's Export CSV
			// button (see #qlqr-export-csv below) but scoped instead of exporting everything.
			if ( 'export_selected' === action ) {
				const url = QLQR.ajaxUrl + '?action=qlqr_export_csv&nonce=' + encodeURIComponent( QLQR.nonce ) + '&ids=' + encodeURIComponent( ids.join( ',' ) );
				window.location.href = url;
				return;
			}

			const $btn = $( this ).prop( 'disabled', true );

			qlqrAjax( 'qlqr_bulk_action', { bulk_action: action, ids: ids, bulk_value: value } ).done( function ( response ) {
				$btn.prop( 'disabled', false );

				if ( ! response.success ) {
					window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
					return;
				}

				$( '#qlqr-bulk-action' ).val( '' );
				$( '#qlqr-bulk-value' ).hide().val( '' );
				$( '#qlqr-bulk-redirect-type' ).hide();
				loadLinks();
			} ).fail( function () {
				$btn.prop( 'disabled', false );
				window.alert( QLQR.i18n.genericError );
			} );
		} );

	} );

	// GeoIP database panel (Settings page only) — its own ready handler, because the Links-page
	// handler above returns early on any screen without #qlqr-links-table, which would silently
	// skip this initialization on the Settings screen.
	$( function () {
		if ( $( '#qlqr-geoip-status' ).length ) {
			/**
			 * Render the current GeoIP database status line and toggle button visibility.
			 *
			 * @param {Object} status {installed, ranges, size, modified, ipv6_installed, ipv6_ranges, ipv6_size}
			 */
			function renderGeoipStatus( status ) {
				if ( status.installed ) {
					const sizeKb = Math.round( status.size / 1024 );
					let html =
						'<span style="color:#1a7f37;">●</span> IPv4 installed — ' + status.ranges.toLocaleString() +
						__( ' IP ranges (', 'plugnova-link-shortener-qr' ) + sizeKb.toLocaleString() + __( ' KB), last updated ', 'plugnova-link-shortener-qr' ) + status.modified;

					if ( status.ipv6_installed ) {
						const sizeKbV6 = Math.round( status.ipv6_size / 1024 );
						html += '<br /><span style="color:#1a7f37;">●</span> IPv6 installed — ' + status.ipv6_ranges.toLocaleString() +
							__( ' IP ranges (', 'plugnova-link-shortener-qr' ) + sizeKbV6.toLocaleString() + __( ' KB)', 'plugnova-link-shortener-qr' );
					} else {
						html += '<br /><span style="color:#996800;">●</span> IPv6 not available — IPv6 visitors will not show a country until this is retried on a future update.';
					}

					$( '#qlqr-geoip-status' ).html( html );
					$( '#qlqr-geoip-remove' ).show();
					$( '#qlqr-geoip-download' ).text( __( 'Update Database', 'plugnova-link-shortener-qr' ) );
				} else {
					$( '#qlqr-geoip-status' ).html( '<span style="color:#646970;">●</span> Not installed — Top Countries will be empty until you download the database.' );
					$( '#qlqr-geoip-remove' ).hide();
					$( '#qlqr-geoip-download' ).text( __( 'Download Database', 'plugnova-link-shortener-qr' ) );
				}
			}

			qlqrAjax( 'qlqr_geoip_status', {} ).done( function ( response ) {
				if ( response.success ) {
					renderGeoipStatus( response.data );
				}
			} );

			$( '#qlqr-geoip-download' ).on( 'click', function () {
				const $btn = $( this ).prop( 'disabled', true ).text( __( 'Downloading… this can take a minute', 'plugnova-link-shortener-qr' ) );

				qlqrAjax( 'qlqr_geoip_download', {} ).done( function ( response ) {
					$btn.prop( 'disabled', false );

					if ( ! response.success ) {
						window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
						renderGeoipStatus( { installed: false, ranges: 0, size: 0, modified: '' } );
						return;
					}

					renderGeoipStatus( response.data.status );
					window.alert( response.data.message );
				} ).fail( function () {
					$btn.prop( 'disabled', false );
					window.alert( QLQR.i18n.genericError );
				} );
			} );

			$( '#qlqr-geoip-remove' ).on( 'click', function () {
				if ( ! window.confirm( __( 'Remove the GeoIP database? Country tracking will stop until you download it again.', 'plugnova-link-shortener-qr' ) ) ) {
					return;
				}

				qlqrAjax( 'qlqr_geoip_remove', {} ).done( function ( response ) {
					if ( response.success ) {
						renderGeoipStatus( response.data.status );
					}
				} );
			} );
		}
	} );

	/**
	 * Render a list of {label, status, detail} check results (shared by the System Health and
	 * Database Repair Tool panels) as a bulleted list with a colored status dot per row.
	 *
	 * @param {jQuery} $target  Container element to fill.
	 * @param {Array}  results  Array of {label, status, detail}.
	 */
	function renderHealthResults( $target, results ) {
		const dotColor = { ok: '#1a7f37', warning: '#996800', error: '#d63638' };

		const html = results.map( function ( row ) {
			const color = dotColor[ row.status ] || '#646970';
			return (
				'<li style="margin-bottom:6px;"><span style="color:' + color + ';">●</span> <strong>' + esc( row.label ) + '</strong> — ' + esc( row.detail ) + '</li>'
			);
		} ).join( '' );

		$target.html( '<ul style="margin:0;">' + html + '</ul>' );
	}

	// System Health + Database Repair Tool panels (Settings page only) — their own ready handler,
	// same reasoning as the GeoIP block above.
	$( function () {
		if ( $( '#qlqr-health-results' ).length ) {
			function loadHealth() {
				$( '#qlqr-health-results' ).html( '<span class="spinner is-active" style="float:none;"></span> Running checks…' );

				qlqrAjax( 'qlqr_system_health', {} ).done( function ( response ) {
					if ( ! response.success ) {
						$( '#qlqr-health-results' ).text( ( response.data && response.data.message ) || QLQR.i18n.genericError );
						return;
					}
					renderHealthResults( $( '#qlqr-health-results' ), response.data.results );
				} );
			}

			loadHealth();
			$( '#qlqr-health-recheck' ).on( 'click', loadHealth );
		}

		if ( $( '#qlqr-db-results' ).length ) {
			function loadDbCheck() {
				$( '#qlqr-db-results' ).html( '<span class="spinner is-active" style="float:none;"></span> Running checks…' );

				qlqrAjax( 'qlqr_db_check', {} ).done( function ( response ) {
					if ( ! response.success ) {
						$( '#qlqr-db-results' ).text( ( response.data && response.data.message ) || QLQR.i18n.genericError );
						return;
					}
					renderHealthResults( $( '#qlqr-db-results' ), response.data.results );
				} );
			}

			loadDbCheck();
			$( '#qlqr-db-recheck' ).on( 'click', loadDbCheck );

			$( '#qlqr-db-repair' ).on( 'click', function () {
				if ( ! window.confirm( __( 'Run the database repair? This will recreate any missing tables, remove orphaned rows, and optimize all plugin tables.', 'plugnova-link-shortener-qr' ) ) ) {
					return;
				}

				const $btn = $( this ).prop( 'disabled', true ).text( __( 'Repairing…', 'plugnova-link-shortener-qr' ) );

				qlqrAjax( 'qlqr_db_repair', {} ).done( function ( response ) {
					$btn.prop( 'disabled', false ).text( __( 'Repair Now', 'plugnova-link-shortener-qr' ) );

					if ( ! response.success ) {
						window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
						return;
					}

					renderHealthResults( $( '#qlqr-db-results' ), response.data.results );
					window.alert(
						__( 'Repair complete: ', 'plugnova-link-shortener-qr' ) + response.data.summary.orphans_removed + __( ' orphaned row(s) removed, ', 'plugnova-link-shortener-qr' ) +
						response.data.summary.tables_optimized + __( ' table(s) optimized.', 'plugnova-link-shortener-qr' )
					);
				} ).fail( function () {
					$btn.prop( 'disabled', false ).text( __( 'Repair Now', 'plugnova-link-shortener-qr' ) );
					window.alert( QLQR.i18n.genericError );
				} );
			} );
		}
	} );

	// Bio Links admin page (separate ready handler so it isn't skipped by the Links-page-only
	// early return above, which bails out whenever #qlqr-links-table — a Links-page-only element —
	// isn't present on the current admin screen).
	$( function () {
		if ( 0 === $( '#qlqr-bio-table' ).length ) {
			return;
		}

		const bioState = {
			search: '',
			status: 'all',
			paged: 1,
			perPage: 20,
		};

		/**
		 * Render one row of the bio pages table.
		 *
		 * @param {Object} bioPage Serialized bio page object from the server.
		 * @param {number} serial  1-based row number for the current page.
		 * @return {string} HTML for a single <tr>.
		 */
		function renderBioRow( bioPage, serial ) {
			const statusClass = bioPage.is_trashed ? 'qlqr-status-trashed' : ( 'active' === bioPage.status ? 'qlqr-status-active' : 'qlqr-status-disabled' );
			const statusLabel = bioPage.is_trashed ? __( 'Trashed', 'plugnova-link-shortener-qr' ) : ( 'active' === bioPage.status ? __( 'Active', 'plugnova-link-shortener-qr' ) : __( 'Disabled', 'plugnova-link-shortener-qr' ) );

			const qrThumb = bioPage.qr_image
				? '<img class="qlqr-qr-thumb" src="' + esc( bioPage.qr_image ) + ( '" alt="' + __( 'QR', 'plugnova-link-shortener-qr' ) + '" data-action="view-qr" data-src="' ) + esc( bioPage.qr_image ) + '" data-title="' + esc( bioPage.title ) + '" />'
				: '—';

			let actions = '';
			if ( bioPage.is_trashed ) {
				actions += '<button class="button button-small qlqr-bio-act" data-action="restore" data-id="' + bioPage.id + ( '">' + __( 'Restore', 'plugnova-link-shortener-qr' ) + '</button> ' );
				actions += '<button class="button button-small qlqr-bio-act qlqr-danger" data-action="delete" data-id="' + bioPage.id + ( '">' + __( 'Delete Permanently', 'plugnova-link-shortener-qr' ) + '</button>' );
			} else {
				actions += '<button class="button button-small qlqr-bio-act" data-action="edit" data-id="' + bioPage.id + ( '">' + __( 'Edit', 'plugnova-link-shortener-qr' ) + '</button> ' );
				actions += '<button class="button button-small qlqr-bio-act" data-action="view-analytics" data-id="' + bioPage.id + ( '">' + __( 'Analytics', 'plugnova-link-shortener-qr' ) + '</button> ' );
				actions += '<button class="button button-small qlqr-bio-act" data-action="toggle-status" data-id="' + bioPage.id + '">' + ( 'active' === bioPage.status ? __( 'Disable', 'plugnova-link-shortener-qr' ) : __( 'Enable', 'plugnova-link-shortener-qr' ) ) + '</button> ';
				actions += '<button class="button button-small qlqr-bio-act qlqr-danger" data-action="trash" data-id="' + bioPage.id + ( '">' + __( 'Trash', 'plugnova-link-shortener-qr' ) + '</button>' );
			}

			const urlCell =
				'<div class="qlqr-short-url-cell">' +
					'<a href="' + esc( bioPage.public_url ) + '" target="_blank" rel="noopener">' + esc( bioPage.public_url ) + '</a>' +
					'<button type="button" class="qlqr-copy-inline qlqr-bio-act" data-action="copy" data-url="' + esc( bioPage.public_url ) + ( '" title="' + __( 'Copy public URL', 'plugnova-link-shortener-qr' ) + '">📋</button>' ) +
				'</div>';

			const emailsCell = bioPage.email_count
				? ( bioPage.email_count + ' <a href="' + QLQR.ajaxUrl + '?action=qlqr_export_bio_emails&nonce=' + encodeURIComponent( QLQR.nonce ) + '&id=' + bioPage.id + ( '" title="' + __( 'Download CSV', 'plugnova-link-shortener-qr' ) + '">📋</a>' ) )
				: '—';

			return (
				'<tr data-id="' + bioPage.id + '">' +
					'<td class="qlqr-col-serial">' + serial + '</td>' +
					'<td>' + esc( bioPage.title || '(untitled)' ) + '</td>' +
					'<td>' + urlCell + '</td>' +
					'<td>' + qrThumb + '</td>' +
					'<td>' + ( bioPage.links ? bioPage.links.length : 0 ) + '</td>' +
					'<td>' + ( bioPage.total_views || 0 ) + '</td>' +
					'<td>' + emailsCell + '</td>' +
					'<td><span class="qlqr-status-pill ' + statusClass + '">' + statusLabel + '</span></td>' +
					'<td>' + esc( bioPage.created_at ) + '</td>' +
					'<td class="qlqr-row-actions">' + actions + '</td>' +
				'</tr>'
			);
		}

		/**
		 * Fetch bio pages from the server according to the current filter/pagination state and redraw the table.
		 */
		function loadBioPages() {
			$( '#qlqr-bio-tbody' ).html( ( '<tr><td colspan="10">' + __( 'Loading…', 'plugnova-link-shortener-qr' ) + '</td></tr>' ) );

			qlqrAjax( 'qlqr_query_bio_pages', {
				search: bioState.search,
				status: bioState.status,
				paged: bioState.paged,
				per_page: bioState.perPage,
			} ).done( function ( response ) {
				if ( ! response.success ) {
					$( '#qlqr-bio-tbody' ).html( '<tr class="qlqr-empty-row"><td colspan="10">' + esc( QLQR.i18n.genericError ) + '</td></tr>' );
					return;
				}

				const items = response.data.items || [];
				if ( 0 === items.length ) {
					$( '#qlqr-bio-tbody' ).html( ( '<tr class="qlqr-empty-row"><td colspan="10">' + __( 'No bio pages found.', 'plugnova-link-shortener-qr' ) + '</td></tr>' ) );
				} else {
					const startSerial = ( bioState.paged - 1 ) * bioState.perPage + 1;
					const rowsHtml = items.map( function ( bioPage, index ) {
						return renderBioRow( bioPage, startSerial + index );
					} ).join( '' );
					$( '#qlqr-bio-tbody' ).html( rowsHtml );
				}

				renderBioPagination( response.data.total );
			} );
		}

		/**
		 * Render simple prev/next pagination controls for the bio pages table.
		 *
		 * @param {number} total Total matching records.
		 */
		function renderBioPagination( total ) {
			const totalPages = Math.max( 1, Math.ceil( total / bioState.perPage ) );
			let html = '';

			html += '<button class="button" id="qlqr-bio-prev-page" ' + ( bioState.paged <= 1 ? 'disabled' : '' ) + ( '>' + __( '&laquo; Prev', 'plugnova-link-shortener-qr' ) + '</button>' );
			html += '<span style="padding:0 10px;">Page ' + bioState.paged + __( ' of ', 'plugnova-link-shortener-qr' ) + totalPages + ' (' + total + ' total)</span>';
			html += '<button class="button" id="qlqr-bio-next-page" ' + ( bioState.paged >= totalPages ? 'disabled' : '' ) + ( '>' + __( 'Next &raquo;', 'plugnova-link-shortener-qr' ) + '</button>' );

			$( '#qlqr-bio-pagination' ).html( html );
		}

		/**
		 * Chart.js instance for the bio page analytics modal — kept so it can be destroyed and
		 * recreated cleanly each time the modal opens for a (possibly different) bio page.
		 */
		let bioAnalyticsChart = null;

		/**
		 * Render a small ranked table (button clicks / country / device breakdown) into a container.
		 *
		 * @param {string} selector jQuery selector for the target <table>.
		 * @param {Array}  rows     Array of objects with a `label` key and one numeric value key.
		 * @param {string} valueKey Name of the numeric key to display (e.g. "clicks" or "views").
		 */
		function renderBioAnalyticsTable( selector, rows, valueKey ) {
			if ( ! rows || 0 === rows.length ) {
				$( selector ).html( ( '<tr><td>' + __( 'No data yet.', 'plugnova-link-shortener-qr' ) + '</td></tr>' ) );
				return;
			}

			const html = rows.map( function ( row ) {
				return '<tr><td>' + esc( row.label ) + '</td><td style="text-align:right;">' + row[ valueKey ] + '</td></tr>';
			} ).join( '' );

			$( selector ).html( html );
		}

		/**
		 * Render the QR Scan vs. Direct split as a two-segment percentage bar (QR Analytics).
		 *
		 * @param {number} qrViews     Views that arrived via a scanned QR code.
		 * @param {number} directViews Views that arrived any other way.
		 */
		function renderBioQrSplitBar( qrViews, directViews ) {
			const total = qrViews + directViews;

			if ( 0 === total ) {
				$( '#qlqr-bio-analytics-qr-split' ).html( ( '<span class="qlqr-qr-split-empty">' + __( 'No views yet.', 'plugnova-link-shortener-qr' ) + '</span>' ) );
				return;
			}

			const qrPct = Math.round( ( qrViews / total ) * 100 );
			const directPct = 100 - qrPct;

			$( '#qlqr-bio-analytics-qr-split' ).html(
				'<div class="qlqr-qr-split-segment qlqr-qr-split-qr" style="width:' + qrPct + '%;" title="QR scan: ' + qrPct + '%">' + ( qrPct >= 12 ? qrPct + __( '% QR', 'plugnova-link-shortener-qr' ) : '' ) + '</div>' +
				'<div class="qlqr-qr-split-segment qlqr-qr-split-direct" style="width:' + directPct + '%;" title="Direct: ' + directPct + '%">' + ( directPct >= 12 ? directPct + __( '% Direct', 'plugnova-link-shortener-qr' ) : '' ) + '</div>'
			);
		}

		/**
		 * Bio page ID currently shown in the analytics modal — mirrors analyticsCurrentLinkId, for
		 * the same reason (the Date Range/Include Bot Traffic controls live inside the modal).
		 */
		let bioAnalyticsCurrentPageId = null;

		/**
		 * Fetch and display the analytics modal (views over time + breakdowns + button ranking)
		 * for a single bio page, honoring the modal's own Date Range / Include Bot Traffic controls.
		 *
		 * @param {number}  id            Bio page ID.
		 * @param {boolean} resetControls Whether to reset the Date Range/Bot Traffic controls back
		 *                                 to their defaults first — see openAnalyticsModal()'s
		 *                                 matching parameter for the link-side equivalent.
		 */
		function openBioAnalyticsModal( id, resetControls ) {
			bioAnalyticsCurrentPageId = id;

			if ( false !== resetControls ) {
				$( '#qlqr-bio-analytics-days' ).val( '30' );
				$( '#qlqr-bio-analytics-include-bots' ).prop( 'checked', false );
			}

			applyAnalyticsPlanLimit( $( '#qlqr-bio-analytics-days' ) );

			$( '#qlqr-bio-analytics-modal' ).show().attr( 'aria-hidden', 'false' );
			$( '#qlqr-bio-analytics-title' ).text( __( 'Loading…', 'plugnova-link-shortener-qr' ) );
			$( '#qlqr-bio-analytics-url' ).text( '' );
			$( '#qlqr-bio-analytics-total, #qlqr-bio-analytics-unique, #qlqr-bio-analytics-qr, #qlqr-bio-analytics-direct, #qlqr-bio-analytics-emails, #qlqr-bio-analytics-bots' ).text( '—' );
			$( '#qlqr-bio-analytics-buttons, #qlqr-bio-analytics-countries, #qlqr-bio-analytics-devices, #qlqr-bio-analytics-browsers, #qlqr-bio-analytics-os, #qlqr-bio-analytics-referrers, #qlqr-bio-analytics-utm' ).html( '' );
			$( '#qlqr-bio-analytics-qr-split' ).html( '' );

			const days = $( '#qlqr-bio-analytics-days' ).val();
			const includeBots = $( '#qlqr-bio-analytics-include-bots' ).is( ':checked' ) ? 1 : 0;

			qlqrAjax( 'qlqr_bio_analytics', { id: id, days: days, include_bots: includeBots } ).done( function ( response ) {
				if ( ! response.success ) {
					window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
					$( '#qlqr-bio-analytics-modal' ).hide().attr( 'aria-hidden', 'true' );
					return;
				}

				const data = response.data;

				$( '#qlqr-bio-analytics-title' ).text( data.title );
				$( '#qlqr-bio-analytics-url' ).text( data.public_url );
				$( '#qlqr-bio-analytics-total' ).text( data.total_views.toLocaleString() );
				$( '#qlqr-bio-analytics-unique' ).text( data.unique_views.toLocaleString() );
				$( '#qlqr-bio-analytics-qr' ).text( data.qr_views.toLocaleString() );
				$( '#qlqr-bio-analytics-direct' ).text( data.direct_views.toLocaleString() );
				$( '#qlqr-bio-analytics-emails' ).text( data.email_count.toLocaleString() );
				$( '#qlqr-bio-analytics-bots' ).text( ( data.bot_views || 0 ).toLocaleString() );
				renderBioQrSplitBar( data.qr_views, data.direct_views );

				renderBioAnalyticsTable( '#qlqr-bio-analytics-buttons', data.buttons, 'clicks' );
				renderBioAnalyticsTable( '#qlqr-bio-analytics-countries', data.by_country, 'views' );
				renderBioAnalyticsTable( '#qlqr-bio-analytics-devices', data.by_device, 'views' );
				renderBioAnalyticsTable( '#qlqr-bio-analytics-browsers', data.by_browser, 'views' );
				renderBioAnalyticsTable( '#qlqr-bio-analytics-os', data.by_os, 'views' );
				renderBioAnalyticsTable( '#qlqr-bio-analytics-referrers', data.by_referrer, 'views' );
				renderBioAnalyticsTable( '#qlqr-bio-analytics-utm', data.by_utm_campaign, 'views' );

				if ( 'undefined' !== typeof Chart ) {
					if ( bioAnalyticsChart ) {
						bioAnalyticsChart.destroy();
					}

					const ctx = document.getElementById( 'qlqr-bio-analytics-chart' ).getContext( '2d' );
					bioAnalyticsChart = new Chart( ctx, {
						type: 'line',
						data: {
							labels: data.views_by_day.map( function ( d ) { return d.date; } ),
							datasets: [ {
								label: __( 'Views', 'plugnova-link-shortener-qr' ),
								data: data.views_by_day.map( function ( d ) { return d.views; } ),
								backgroundColor: 'rgba(34, 113, 177, 0.5)',
								borderColor: 'rgba(34, 113, 177, 1)',
								borderWidth: 2,
								tension: 0.3,
								fill: true,
							} ],
						},
						options: {
							responsive: true,
							plugins: { legend: { display: false } },
							scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
						},
					} );
				}
			} ).fail( function () {
				window.alert( QLQR.i18n.genericError );
				$( '#qlqr-bio-analytics-modal' ).hide().attr( 'aria-hidden', 'true' );
			} );
		}

		$( '#qlqr-bio-analytics-days, #qlqr-bio-analytics-include-bots' ).on( 'change', function () {
			if ( null !== bioAnalyticsCurrentPageId ) {
				openBioAnalyticsModal( bioAnalyticsCurrentPageId, false );
			}
		} );

		$( '#qlqr-bio-analytics-export-csv' ).on( 'click', function () {
			if ( null === bioAnalyticsCurrentPageId ) {
				return;
			}

			const days = $( '#qlqr-bio-analytics-days' ).val();
			const includeBots = $( '#qlqr-bio-analytics-include-bots' ).is( ':checked' ) ? 1 : 0;
			const url = QLQR.ajaxUrl + '?action=qlqr_export_bio_analytics_csv' +
				'&nonce=' + encodeURIComponent( QLQR.nonce ) +
				'&id=' + encodeURIComponent( bioAnalyticsCurrentPageId ) +
				'&days=' + encodeURIComponent( days ) +
				'&include_bots=' + includeBots;

			window.location.href = url;
		} );

		loadBioPages();

		let bioSearchTimer = null;
		$( '#qlqr-bio-search' ).on( 'input', function () {
			clearTimeout( bioSearchTimer );
			const value = $( this ).val();
			bioSearchTimer = setTimeout( function () {
				bioState.search = value;
				bioState.paged = 1;
				loadBioPages();
			}, 350 );
		} );

		$( '#qlqr-bio-filter-status' ).on( 'change', function () {
			bioState.status = $( this ).val();
			bioState.paged = 1;
			loadBioPages();
		} );

		$( document ).on( 'click', '#qlqr-bio-prev-page', function () {
			if ( bioState.paged > 1 ) {
				bioState.paged -= 1;
				loadBioPages();
			}
		} );

		$( document ).on( 'click', '#qlqr-bio-next-page', function () {
			bioState.paged += 1;
			loadBioPages();
		} );

		/**
		 * Generate a short, unique-enough client-side identifier for a new Link Group, so its
		 * member links can reference it (as parent_group_key) before it's ever saved to the
		 * database — see BioLinksRepository::replace_for_page(), which relates rows by this string
		 * key rather than by numeric ID (every row in a save is deleted and reinserted together,
		 * so real IDs aren't known until after the fact).
		 *
		 * @return {string}
		 */
		function generateBioGroupKey() {
			return 'g_' + Math.random().toString( 36 ).slice( 2, 10 );
		}

		/**
		 * Rebuild every link row's "Group" <select> options from the group (accordion) header rows
		 * currently in the repeater, preserving each select's current choice where the group it
		 * pointed to still exists. Called after any add/remove/reorder of a group row, and after a
		 * group header's title is edited (so the dropdown label stays in sync).
		 *
		 * A row can carry a one-time "pendingGroup" jQuery data value (set by addBioLinkRow() when
		 * populating from saved/imported data) — the very first refresh after a row is inserted
		 * applies that value instead of the select's current (empty) selection, which is what lets
		 * a link row reference a group that gets inserted into the DOM later in the same batch
		 * (edit-mode population processes rows in their original, possibly-interleaved order).
		 */
		function refreshBioGroupOptions() {
			const groups = [];
			$( '#qlqr-bio-links-repeater .qlqr-biolink-row[data-item-type="group"]' ).each( function () {
				const $row = $( this );
				const key = $row.attr( 'data-group-key' );
				const label = $row.find( '.qlqr-biolink-label' ).val().trim() || __( '(untitled group)', 'plugnova-link-shortener-qr' );
				if ( key ) {
					groups.push( { key: key, label: label } );
				}
			} );

			const optionsHtml = ( '<option value="">' + __( 'No group (top-level)', 'plugnova-link-shortener-qr' ) + '</option>' ) +
				groups.map( function ( g ) {
					return '<option value="' + esc( g.key ) + '">' + esc( g.label ) + '</option>';
				} ).join( '' );

			$( '#qlqr-bio-links-repeater .qlqr-biolink-row[data-item-type="link"] .qlqr-biolink-group' ).each( function () {
				const $select = $( this );
				const pending = $select.data( 'pendingGroup' );
				const desired = undefined !== pending ? pending : $select.val();
				$select.html( optionsHtml ).val( desired || '' );
				$select.removeData( 'pendingGroup' );
			} );
		}

		/**
		 * Build one repeater row for the bio page's Links editor and append it — either a normal
		 * link button, or (when data.item_type is "group") an accordion header that member links
		 * can be assigned to via their own "Group" dropdown.
		 *
		 * @param {Object} data {label, url, icon, image_url, status, starts_at, ends_at, item_type,
		 *                       group_key, parent_group_key, visible_countries, visible_devices} —
		 *                       all optional, defaults to a blank active link row.
		 */
		function addBioLinkRow( data ) {
			data = data || {};

			const isGroup = 'group' === data.item_type;
			const label = data.label || '';
			const icon = data.icon || '';
			const checked = 'disabled' !== data.status ? 'checked' : '';

			if ( isGroup ) {
				const groupKey = data.group_key || generateBioGroupKey();

				const $row = $(
					'<div class="qlqr-biolink-row qlqr-biolink-row-group" data-item-type="group" data-group-key="' + esc( groupKey ) + '">' +
						'<div class="qlqr-biolink-main">' +
							( '<span class="qlqr-biolink-drag-handle" title="' + __( 'Drag to reorder', 'plugnova-link-shortener-qr' ) + '">⠿</span>' ) +
							( '<span class="qlqr-biolink-group-badge" title="' + __( 'Link Group — an accordion header for other links below', 'plugnova-link-shortener-qr' ) + '">📁</span>' ) +
							'<input type="text" class="qlqr-biolink-icon" maxlength="4" placeholder="📁" value="' + esc( icon ) + ( '" title="' + __( 'Optional emoji shown before the group title', 'plugnova-link-shortener-qr' ) + '" />' ) +
							( '<input type="text" class="qlqr-biolink-label" placeholder="' + __( 'Group title, e.g. More Links', 'plugnova-link-shortener-qr' ) + '" value="' ) + esc( label ) + '" style="flex:3;" />' +
							( '<label class="qlqr-biolink-status" title="' + __( 'Uncheck to temporarily hide this group and its links', 'plugnova-link-shortener-qr' ) + '"><input type="checkbox" class="qlqr-biolink-active" ' ) + checked + ( ' /> ' + __( 'Active', 'plugnova-link-shortener-qr' ) + '</label>' ) +
							( '<button type="button" class="qlqr-biolink-remove" title="' + __( 'Remove group (its links become top-level)', 'plugnova-link-shortener-qr' ) + '">' + __( '&times;', 'plugnova-link-shortener-qr' ) + '</button>' ) +
						'</div>' +
						'<p class="description qlqr-biolink-group-note">Assign links to this group using their own "Group" dropdown below.</p>' +
					'</div>'
				);

				$( '#qlqr-bio-links-repeater' ).append( $row );
				return;
			}

			const url = data.url || '';
			const imageUrl = data.image_url || '';
			const startsAt = data.starts_at || '';
			const endsAt = data.ends_at || '';
			const selectedCountries = ( data.visible_countries || '' ).split( ',' ).map( function ( c ) { return c.trim().toUpperCase(); } ).filter( Boolean );
			const selectedDevices = ( data.visible_devices || '' ).split( ',' ).map( function ( d ) { return d.trim().toLowerCase(); } ).filter( Boolean );

			const countryOptions = Object.keys( QLQR_COUNTRIES ).map( function ( code ) {
				return '<option value="' + code + '"' + ( -1 !== selectedCountries.indexOf( code ) ? __( ' selected', 'plugnova-link-shortener-qr' ) : '' ) + '>' + esc( QLQR_COUNTRIES[ code ] ) + '</option>';
			} ).join( '' );

			// A checkbox-panel dropdown (see the delegated .qlqr-country-ms-* handlers below) driving
			// a hidden <select multiple> — collectBioLinks() keeps reading that select's .val() as
			// before, so only the picking UI changes, not the data shape submitted to the server.
			const countryCheckboxes = Object.keys( QLQR_COUNTRIES ).map( function ( code ) {
				const isChecked = -1 !== selectedCountries.indexOf( code ) ? __( ' checked', 'plugnova-link-shortener-qr' ) : '';
				return '<label class="qlqr-country-ms-option"><input type="checkbox" class="qlqr-country-ms-checkbox" value="' + code + '"' + isChecked + ' /> ' + esc( QLQR_COUNTRIES[ code ] ) + '</label>';
			} ).join( '' );
			const countryLabel = qlqrCountryMultiselectLabel( selectedCountries );

			const deviceCheckbox = function ( value, label ) {
				const isChecked = -1 !== selectedDevices.indexOf( value ) ? 'checked' : '';
				return '<label><input type="checkbox" class="qlqr-biolink-device" value="' + value + '" ' + isChecked + ' /> ' + label + '</label>';
			};

			// Analytics Per Block: a read-only click-count badge, shown only when populating from
			// already-saved data (a brand-new row via "+ Add Link" has no data.clicks at all) — the
			// per-button counter Database\BioLinksRepository already tracks, surfaced right in the
			// editor so an admin doesn't need to open the full Analytics modal just to see which
			// buttons are actually getting clicked.
			const clicksBadge = undefined !== data.clicks
				? ( '<span class="qlqr-biolink-clicks-badge" title="' + __( 'Total clicks on this button', 'plugnova-link-shortener-qr' ) + '">👆 ' ) + data.clicks + '</span>'
				: '';

			const $row = $(
				'<div class="qlqr-biolink-row" data-item-type="link">' +
					'<div class="qlqr-biolink-main">' +
						( '<span class="qlqr-biolink-drag-handle" title="' + __( 'Drag to reorder', 'plugnova-link-shortener-qr' ) + '">⠿</span>' ) +
						clicksBadge +
						'<input type="text" class="qlqr-biolink-icon" maxlength="4" placeholder="🔗" value="' + esc( icon ) + ( '" title="' + __( 'Optional emoji, used when no product image is set', 'plugnova-link-shortener-qr' ) + '" />' ) +
						( '<input type="text" class="qlqr-biolink-label" placeholder="' + __( 'My Website', 'plugnova-link-shortener-qr' ) + '" value="' ) + esc( label ) + '" />' +
						( '<input type="url" class="qlqr-biolink-url" placeholder="' + __( 'https://example.com', 'plugnova-link-shortener-qr' ) + '" value="' ) + esc( url ) + '" />' +
						( '<label class="qlqr-biolink-status" title="' + __( 'Uncheck to temporarily hide this button', 'plugnova-link-shortener-qr' ) + '"><input type="checkbox" class="qlqr-biolink-active" ' ) + checked + ( ' /> ' + __( 'Active', 'plugnova-link-shortener-qr' ) + '</label>' ) +
						( '<button type="button" class="qlqr-biolink-remove" title="' + __( 'Remove', 'plugnova-link-shortener-qr' ) + '">' + __( '&times;', 'plugnova-link-shortener-qr' ) + '</button>' ) +
					'</div>' +
					'<div class="qlqr-biolink-product">' +
						( '<label>' + __( 'Product Image (optional)', 'plugnova-link-shortener-qr' ) + '</label><br />' ) +
						'<span class="qlqr-biolink-image-preview-wrap" style="display:none;">' +
							'<img class="qlqr-biolink-image-preview" src="" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:4px;border:1px solid #dcdcde;vertical-align:middle;margin-right:6px;" />' +
						'</span>' +
						( '<button type="button" class="button qlqr-biolink-image-select">' + __( 'Select from Media Library', 'plugnova-link-shortener-qr' ) + '</button>' ) +
						( '<button type="button" class="button qlqr-biolink-image-remove" style="display:none;">' + __( 'Remove', 'plugnova-link-shortener-qr' ) + '</button>' ) +
						'<br />' +
						( '<input type="url" class="qlqr-biolink-image" placeholder="' + __( 'https://example.com/photo.jpg', 'plugnova-link-shortener-qr' ) + '" value="' ) + esc( imageUrl ) + '" style="width:100%;box-sizing:border-box;margin-top:4px;" />' +
						( '<span class="description">' + __( 'If set, this button renders as a photo + text card that links to the URL above, instead of a plain text row. Pick an image already uploaded to this site, or paste any external image URL.', 'plugnova-link-shortener-qr' ) + '</span>' ) +
					'</div>' +
					'<div class="qlqr-biolink-schedule">' +
						( '<label>' + __( 'Show from', 'plugnova-link-shortener-qr' ) + ' ' + '<input type="datetime-local" class="qlqr-biolink-starts" value="' ) + esc( startsAt ) + '" /></label>' +
						( '<label>' + __( 'Until', 'plugnova-link-shortener-qr' ) + ' ' + '<input type="datetime-local" class="qlqr-biolink-ends" value="' ) + esc( endsAt ) + '" /></label>' +
						( '<span class="description">' + __( 'Leave both blank to always show this button.', 'plugnova-link-shortener-qr' ) + '</span>' ) +
					'</div>' +
					'<div class="qlqr-biolink-group-row">' +
						( '<label>' + __( 'Group', 'plugnova-link-shortener-qr' ) + ' ' + '<select class="qlqr-biolink-group"><option value="">' + __( 'No group (top-level)', 'plugnova-link-shortener-qr' ) + '</option></select></label>' ) +
						( '<span class="description">' + __( 'Optionally nest this link under one of the groups added below.', 'plugnova-link-shortener-qr' ) + '</span>' ) +
					'</div>' +
					'<div class="qlqr-biolink-visibility">' +
						'<label>Countries' +
							'<div class="qlqr-country-multiselect">' +
								'<button type="button" class="qlqr-country-ms-toggle">' + esc( countryLabel ) + '</button>' +
								'<select multiple class="qlqr-biolink-countries" style="display:none;">' + countryOptions + '</select>' +
								'<div class="qlqr-country-ms-panel" style="display:none;">' +
									( '<input type="text" class="qlqr-country-ms-search" placeholder="' + __( 'Search country…', 'plugnova-link-shortener-qr' ) + '" />' ) +
									'<div class="qlqr-country-ms-list">' + countryCheckboxes + '</div>' +
									( '<button type="button" class="qlqr-country-ms-clear">' + __( 'Clear selection', 'plugnova-link-shortener-qr' ) + '</button>' ) +
								'</div>' +
							'</div>' +
						'</label>' +
						'<span class="qlqr-biolink-devices">' +
							deviceCheckbox( 'mobile', __( 'Mobile', 'plugnova-link-shortener-qr' ) ) + deviceCheckbox( 'desktop', __( 'Desktop', 'plugnova-link-shortener-qr' ) ) + deviceCheckbox( 'tablet', __( 'Tablet', 'plugnova-link-shortener-qr' ) ) +
						'</span>' +
						( '<span class="description">' + __( 'Leave everything unset to show this link to everyone. Click Countries to search and pick several.', 'plugnova-link-shortener-qr' ) + '</span>' ) +
					'</div>' +
				'</div>'
			);

			// Remember the intended group membership so the very next refreshBioGroupOptions() call
			// can apply it even if the target group row hasn't been inserted into the DOM yet (see
			// that function's docblock).
			$row.find( '.qlqr-biolink-group' ).data( 'pendingGroup', data.parent_group_key || '' );

			$( '#qlqr-bio-links-repeater' ).append( $row );
			setBioLinkImagePreview( $row, imageUrl );
		}

		/**
		 * Show/hide the Bio Page avatar preview thumbnail, matching setQrLogoPreview() in the Links
		 * modal's own code (a separate, Links-page-only script scope — see the comment on the
		 * generic modal handlers near the top of this file). Unlike the QR logo, the avatar is
		 * stored as a plain URL (not an attachment ID) because templates/bio-page.php has always
		 * accepted any external image URL there — the Media Library picker below is an additional
		 * way to fill that same URL field, not a new format.
		 *
		 * @param {string} url Image URL, or '' to clear.
		 */
		function setBioAvatarPreview( url ) {
			$( '#qlqr-bio-f-avatar-preview' ).attr( 'src', url || '' );
			$( '#qlqr-bio-f-avatar-preview-wrap' ).toggle( !! url );
			$( '#qlqr-bio-f-avatar-remove' ).toggle( !! url );
		}

		let bioAvatarMediaFrame = null;

		$( '#qlqr-bio-f-avatar-select' ).on( 'click', function ( e ) {
			e.preventDefault();

			if ( 'undefined' === typeof wp || ! wp.media ) {
				return;
			}

			if ( bioAvatarMediaFrame ) {
				bioAvatarMediaFrame.open();
				return;
			}

			bioAvatarMediaFrame = wp.media( {
				title: __( 'Select Avatar Image', 'plugnova-link-shortener-qr' ),
				button: { text: __( 'Use this image', 'plugnova-link-shortener-qr' ) },
				library: { type: 'image' },
				multiple: false,
			} );

			bioAvatarMediaFrame.on( 'select', function () {
				const attachment = bioAvatarMediaFrame.state().get( 'selection' ).first().toJSON();
				const thumbUrl = ( attachment.sizes && ( attachment.sizes.thumbnail || attachment.sizes.medium ) )
					? ( attachment.sizes.thumbnail || attachment.sizes.medium ).url
					: attachment.url;

				// trigger('change') so the delegated '#qlqr-bio-modal input' listener that redraws
				// the live phone-frame preview (renderBioPreview()) picks this up automatically.
				$( '#qlqr-bio-f-avatar' ).val( attachment.url ).trigger( 'change' );
				setBioAvatarPreview( thumbUrl );
			} );

			bioAvatarMediaFrame.open();
		} );

		$( '#qlqr-bio-f-avatar-remove' ).on( 'click', function () {
			$( '#qlqr-bio-f-avatar' ).val( '' ).trigger( 'change' );
			setBioAvatarPreview( '' );
		} );

		/**
		 * Show/hide a bio-link repeater row's product-image preview thumbnail, matching
		 * setBioAvatarPreview() above. Scoped to $row (not a fixed selector) because this field
		 * repeats once per link row.
		 *
		 * @param {jQuery} $row One .qlqr-biolink-row.
		 * @param {string} url  Image URL, or '' to clear.
		 */
		function setBioLinkImagePreview( $row, url ) {
			$row.find( '.qlqr-biolink-image-preview' ).attr( 'src', url || '' );
			$row.find( '.qlqr-biolink-image-preview-wrap' ).toggle( !! url );
			$row.find( '.qlqr-biolink-image-remove' ).toggle( !! url );
		}

		/**
		 * Read all bio-link repeater rows into a plain array ready to submit as payload.links —
		 * both plain links and Link Group headers, in their current display order.
		 *
		 * @return {Array} Array of {item_type, label, url?, icon?, image_url?, status, starts_at?,
		 *                  ends_at?, group_key?, parent_group_key?, visible_countries?, visible_devices?}.
		 */
		function collectBioLinks() {
			const links = [];

			$( '#qlqr-bio-links-repeater .qlqr-biolink-row' ).each( function () {
				const $row = $( this );
				const isGroup = 'group' === $row.attr( 'data-item-type' );
				const label = $row.find( '.qlqr-biolink-label' ).val().trim();

				if ( '' === label ) {
					return; // skip incomplete rows rather than erroring the whole save.
				}

				if ( isGroup ) {
					links.push( {
						item_type: 'group',
						label: label,
						icon: $row.find( '.qlqr-biolink-icon' ).val().trim(),
						group_key: $row.attr( 'data-group-key' ),
						status: $row.find( '.qlqr-biolink-active' ).is( ':checked' ) ? 'active' : 'disabled',
					} );
					return;
				}

				const url = $row.find( '.qlqr-biolink-url' ).val().trim();
				if ( '' === url ) {
					return;
				}

				const countries = $row.find( '.qlqr-biolink-countries' ).val() || [];
				const devices = $row.find( '.qlqr-biolink-device:checked' ).map( function () {
					return $( this ).val();
				} ).get();

				links.push( {
					item_type: 'link',
					label: label,
					url: url,
					icon: $row.find( '.qlqr-biolink-icon' ).val().trim(),
					image_url: $row.find( '.qlqr-biolink-image' ).val().trim(),
					status: $row.find( '.qlqr-biolink-active' ).is( ':checked' ) ? 'active' : 'disabled',
					starts_at: $row.find( '.qlqr-biolink-starts' ).val(),
					ends_at: $row.find( '.qlqr-biolink-ends' ).val(),
					parent_group_key: $row.find( '.qlqr-biolink-group' ).val() || '',
					visible_countries: countries.join( ',' ),
					visible_devices: devices.join( ',' ),
				} );
			} );

			return links;
		}

		/**
		 * Placeholder text for a social-icon row's value field, tailored to what that platform
		 * actually expects — a plain phone number or email address, not a full URL.
		 *
		 * @param {string} platform One of BioSocial::PLATFORMS' keys.
		 * @return {string}
		 */
		function bioSocialPlaceholder( platform ) {
			if ( 'phone' === platform ) {
				return __( '+8801XXXXXXXXX', 'plugnova-link-shortener-qr' );
			}
			if ( 'email' === platform ) {
				return __( 'you@example.com', 'plugnova-link-shortener-qr' );
			}
			return __( 'https://…', 'plugnova-link-shortener-qr' );
		}

		/**
		 * Build one repeater row for the bio page's Social Icons editor and append it.
		 *
		 * The value field is type="text", not type="url": the Phone/Email platforms take a plain
		 * number or address, and the browser's native URL validation (requiring a scheme) blocked
		 * the form from submitting those. collectBioSocials() below adds the tel:/mailto: scheme
		 * automatically before the value is saved or previewed.
		 *
		 * @param {Object} data {platform, url, status, is_floating} — all optional, defaults to a
		 *                       blank active "website" row rendered inline (not floating).
		 */
		function addBioSocialRow( data ) {
			data = data || {};
			const platform = data.platform || 'website';
			// Strip the auto-added scheme back off for display, so editing an existing Phone/Email
			// row shows the plain number/address the admin actually typed, not "tel:"/"mailto:".
			const url = ( data.url || '' ).replace( /^(tel:|mailto:)/i, '' );
			const checked = 'disabled' !== data.status ? 'checked' : '';
			const floatingChecked = data.is_floating ? 'checked' : '';
			const platforms = [ 'website', 'facebook', 'instagram', 'twitter', 'youtube', 'tiktok', 'linkedin', 'whatsapp', 'telegram', 'phone', 'email' ];

			const options = platforms.map( function ( p ) {
				return '<option value="' + p + '"' + ( p === platform ? __( ' selected', 'plugnova-link-shortener-qr' ) : '' ) + '>' + p.charAt( 0 ).toUpperCase() + p.slice( 1 ) + '</option>';
			} ).join( '' );

			const $row = $(
				'<div class="qlqr-biosocial-row">' +
					( '<span class="qlqr-biosocial-drag-handle" title="' + __( 'Drag to reorder', 'plugnova-link-shortener-qr' ) + '">⠿</span>' ) +
					'<select class="qlqr-biosocial-platform">' + options + '</select>' +
					( '<input type="text" class="qlqr-biosocial-url" placeholder="' + esc( bioSocialPlaceholder( platform ) ) + '" value="' ) + esc( url ) + '" />' +
					'<label class="qlqr-biosocial-status"><input type="checkbox" class="qlqr-biosocial-active" ' + checked + ( ' /> ' + __( 'Active', 'plugnova-link-shortener-qr' ) + '</label>' ) +
					( '<label class="qlqr-biosocial-status" title="' + __( 'Show as a fixed floating button instead of an inline icon', 'plugnova-link-shortener-qr' ) + '">' ) +
						'<input type="checkbox" class="qlqr-biosocial-floating" ' + floatingChecked + ( ' /> ' + __( 'Floating', 'plugnova-link-shortener-qr' ) + '</label>' ) +
					( '<button type="button" class="qlqr-biosocial-remove" title="' + __( 'Remove', 'plugnova-link-shortener-qr' ) + '">' + __( '&times;', 'plugnova-link-shortener-qr' ) + '</button>' ) +
				'</div>'
			);

			$( '#qlqr-bio-socials-repeater' ).append( $row );
		}

		/**
		 * Add the tel:/mailto: scheme a Phone/Email row's plain value needs before it can be saved
		 * or used as an href — the field itself just takes a plain number/address (see
		 * bioSocialPlaceholder() and addBioSocialRow() above).
		 *
		 * @param {string} platform One of BioSocial::PLATFORMS' keys.
		 * @param {string} rawValue The row's trimmed value field content.
		 * @return {string}
		 */
		function bioSocialNormalizeUrl( platform, rawValue ) {
			if ( 'phone' === platform ) {
				return /^tel:/i.test( rawValue ) ? rawValue : 'tel:' + rawValue.replace( /\s+/g, '' );
			}
			if ( 'email' === platform ) {
				return /^mailto:/i.test( rawValue ) ? rawValue : 'mailto:' + rawValue;
			}
			return rawValue;
		}

		/**
		 * Read all social-icon repeater rows into a plain array ready to submit as payload.social_links.
		 *
		 * @return {Array} Array of {platform, url, status, is_floating}.
		 */
		function collectBioSocials() {
			const socials = [];

			$( '#qlqr-bio-socials-repeater .qlqr-biosocial-row' ).each( function () {
				const $row = $( this );
				const platform = $row.find( '.qlqr-biosocial-platform' ).val();
				const rawValue = $row.find( '.qlqr-biosocial-url' ).val().trim();

				if ( '' === rawValue ) {
					return;
				}

				socials.push( {
					platform: platform,
					url: bioSocialNormalizeUrl( platform, rawValue ),
					status: $row.find( '.qlqr-biosocial-active' ).is( ':checked' ) ? 'active' : 'disabled',
					is_floating: $row.find( '.qlqr-biosocial-floating' ).is( ':checked' ) ? 1 : 0,
				} );
			} );

			return socials;
		}

		/**
		 * Visual variables per theme preset for the live preview pane — a simplified JS mirror of
		 * templates/bio-page.php's PHP $theme match array (card background/border/muted text
		 * color differ enough between presets that a shared lookup is simpler than four near-
		 * duplicate render branches). body_bg for 'light' and 'gradient' also depends on the
		 * chosen theme_color, so it's computed in renderBioPreview() rather than stored here.
		 */
		const BIO_THEME_PRESETS = {
			dark: {
				text: '#f5f5f7', muted: '#a1a1aa', cardBg: '#1c1f26', cardBorder: '#2e323c',
				bodyBg: 'radial-gradient(circle at top, #1f2937 0%, #0b0f14 340px)',
			},
			gradient: {
				text: '#ffffff', muted: 'rgba(255,255,255,.78)', cardBg: 'rgba(255,255,255,.14)', cardBorder: 'rgba(255,255,255,.32)',
			},
			minimal: {
				text: '#1d2327', muted: '#646970', cardBg: '#ffffff', cardBorder: '#d0d0d0',
				bodyBg: '#ffffff',
			},
			light: {
				text: '#1d2327', muted: '#50575e', cardBg: '#ffffff', cardBorder: '#e2e2e2',
			},
		};

		/**
		 * Social platform icons for the live preview — mirrors Models\BioSocial::PLATFORMS exactly
		 * (same monochrome inline SVGs, so the modal's preview matches the real public page).
		 */
		const BIO_SOCIAL_GLYPHS = {
			website: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
			facebook: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>',
			instagram: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>',
			twitter: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="4" x2="20" y2="20"/><line x1="20" y1="4" x2="4" y2="20"/></svg>',
			youtube: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="4"/><polygon points="10 9 16 12 10 15"/></svg>',
			tiktok: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
			linkedin: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>',
			whatsapp: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
			email: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
			phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
			telegram: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
		};

		/**
		 * Arrange a flat list of {item_type, ...} entries (as returned by collectBioLinks()) into
		 * the same nested shape Controllers\BioController::group_buttons() builds server-side, for
		 * the live preview pane. An empty group (no active children) is omitted, matching the
		 * public page's behavior.
		 *
		 * @param {Array} items Flat, already-active-filtered link/group entries.
		 * @return {Array} Array of {type: 'link'|'group', item, children?}.
		 */
		function groupBioLinksForPreview( items ) {
			const headerKeys = {};
			items.forEach( function ( it ) {
				if ( 'group' === it.item_type && it.group_key ) {
					headerKeys[ it.group_key ] = true;
				}
			} );

			const childrenByGroup = {};
			items.forEach( function ( it ) {
				if ( 'group' !== it.item_type && it.parent_group_key && headerKeys[ it.parent_group_key ] ) {
					childrenByGroup[ it.parent_group_key ] = childrenByGroup[ it.parent_group_key ] || [];
					childrenByGroup[ it.parent_group_key ].push( it );
				}
			} );

			const grouped = [];
			items.forEach( function ( it ) {
				if ( 'group' === it.item_type ) {
					if ( it.group_key && childrenByGroup[ it.group_key ] && childrenByGroup[ it.group_key ].length ) {
						grouped.push( { type: 'group', item: it, children: childrenByGroup[ it.group_key ] } );
					}
					return;
				}

				if ( it.parent_group_key && headerKeys[ it.parent_group_key ] ) {
					return; // Already rendered as a child of its group above.
				}

				grouped.push( { type: 'link', item: it } );
			} );

			return grouped;
		}

		/**
		 * Re-render the phone-frame live preview in the Create/Edit Bio Page modal from the
		 * form's current field values, so edits are reflected immediately without needing to
		 * save and visit the public page. Best-effort: the Floating Contact Buttons and a ticking
		 * Countdown Timer aren't meaningfully previewable inside a small static phone frame, so
		 * floating icons are simply omitted here (they still work on the real page) and the
		 * countdown shows a static "00:00:00:00" placeholder instead of counting down.
		 */
		function renderBioPreview() {
			const title = $( '#qlqr-bio-f-title' ).val() || __( 'Your Name', 'plugnova-link-shortener-qr' );
			const bioText = $( '#qlqr-bio-f-bio-text' ).val() || '';
			const avatarUrl = $( '#qlqr-bio-f-avatar' ).val() || '';
			const themeColor = $( '#qlqr-bio-f-theme-color' ).val() || '#2271b1';
			const themePreset = $( '#qlqr-bio-f-theme-preset' ).val() || 'light';
			const buttonStyle = $( '#qlqr-bio-f-button-style' ).val() || 'rounded';
			const emailCaptureEnabled = $( '#qlqr-bio-f-email-capture' ).is( ':checked' );
			const emailHeading = $( '#qlqr-bio-f-email-heading' ).val();

			const radius = 'square' === buttonStyle ? '4px' : ( 'pill' === buttonStyle ? '999px' : '14px' );
			const preset = BIO_THEME_PRESETS[ themePreset ] || BIO_THEME_PRESETS.light;
			const bodyBg = preset.bodyBg || (
				'gradient' === themePreset
					? 'linear-gradient(160deg, ' + themeColor + ' 0%, #1d2327 100%)'
					: 'linear-gradient(180deg, ' + themeColor + '1a 0%, #f6f7f7 60%)'
			);

			const socials = collectBioSocials().filter( function ( s ) { return 'active' === s.status && ! s.is_floating; } );
			const groupedItems = groupBioLinksForPreview( collectBioLinks().filter( function ( l ) { return 'active' === l.status; } ) );

			const announcementEnabled = $( '#qlqr-bio-f-announcement-enabled' ).is( ':checked' );
			const announcementText = $( '#qlqr-bio-f-announcement-text' ).val() || '';
			const announcementHtml = ( announcementEnabled && announcementText )
				? '<div class="qlqr-bio-preview-announcement" style="background:' + esc( $( '#qlqr-bio-f-announcement-bg' ).val() || '#2271b1' ) + ';color:' + esc( $( '#qlqr-bio-f-announcement-text-color' ).val() || '#ffffff' ) + ';">' + esc( announcementText ) + '</div>'
				: '';

			const countdownEnabled = $( '#qlqr-bio-f-countdown-enabled' ).is( ':checked' );
			const countdownLabel = $( '#qlqr-bio-f-countdown-label' ).val() || '';
			const countdownHtml = countdownEnabled
				? '<div class="qlqr-bio-preview-countdown">' +
					( countdownLabel ? '<p class="qlqr-bio-preview-countdown-label" style="color:' + preset.muted + ';">' + esc( countdownLabel ) + '</p>' : '' ) +
					'<div class="qlqr-bio-preview-countdown-units" style="color:' + esc( themeColor ) + ';">00 : 00 : 00 : 00</div>' +
				'</div>'
				: '';

			const avatarHtml = avatarUrl
				? '<img class="qlqr-bio-preview-avatar" src="' + esc( avatarUrl ) + '" alt="" />'
				: '<div class="qlqr-bio-preview-avatar-fallback" style="background:' + esc( themeColor ) + ';">' + esc( ( title.trim().charAt( 0 ) || '?' ).toUpperCase() ) + '</div>';

			const socialsHtml = socials.length
				? '<div class="qlqr-bio-preview-socials">' + socials.map( function ( s ) {
					// BIO_SOCIAL_GLYPHS values are fixed, developer-authored SVG constants (never built
					// from user input), so — like BioSocial::icon_svg() server-side — they are inserted
					// as-is rather than through esc(), which would print the markup as literal text.
					return '<span class="qlqr-bio-preview-social" style="background:' + preset.cardBg + ';border-color:' + preset.cardBorder + ';color:' + preset.text + ';">' + ( BIO_SOCIAL_GLYPHS[ s.platform ] || BIO_SOCIAL_GLYPHS.website ) + '</span>';
				} ).join( '' ) + '</div>'
				: '';

			const renderLinkEntry = function ( l ) {
				const label = esc( l.label || __( 'Untitled', 'plugnova-link-shortener-qr' ) );
				if ( l.image_url ) {
					return '<div class="qlqr-bio-preview-link qlqr-bio-preview-link-card" style="background:' + preset.cardBg + ';border-color:' + preset.cardBorder + ';border-radius:' + radius + ';">' +
						'<img class="qlqr-bio-preview-thumb" src="' + esc( l.image_url ) + '" alt="" style="border-radius: calc(' + radius + ' - 4px);" />' +
						'<span style="color:' + preset.text + ';">' + label + '</span>' +
					'</div>';
				}
				return '<div class="qlqr-bio-preview-link" style="background:' + preset.cardBg + ';border-color:' + preset.cardBorder + ';border-radius:' + radius + ';color:' + preset.text + ';">' +
					( l.icon ? '<span class="qlqr-bio-preview-icon">' + esc( l.icon ) + '</span>' : '' ) + label +
				'</div>';
			};

			let bodyHtml;
			if ( emailCaptureEnabled ) {
				bodyHtml = '<div class="qlqr-bio-preview-gate">' +
					'<p style="color:' + preset.text + ';font-weight:600;font-size:12px;margin:0;">' + esc( emailHeading || __( 'Enter your email to see the links', 'plugnova-link-shortener-qr' ) ) + '</p>' +
					'<div class="qlqr-bio-preview-gate-input" style="border-color:' + preset.cardBorder + ';background:' + preset.cardBg + ';"></div>' +
					'<div class="qlqr-bio-preview-gate-btn" style="background:' + esc( themeColor ) + ( ';">' + __( 'Continue', 'plugnova-link-shortener-qr' ) + '</div>' ) +
				'</div>';
			} else if ( 0 === groupedItems.length ) {
				bodyHtml = '<p class="qlqr-bio-preview-empty" style="color:' + preset.muted + ( ';">' + __( 'No links added yet.', 'plugnova-link-shortener-qr' ) + '</p>' );
			} else {
				bodyHtml = groupedItems.map( function ( entry ) {
					if ( 'group' === entry.type ) {
						const childrenHtml = entry.children.map( renderLinkEntry ).join( '' );
						return '<div class="qlqr-bio-preview-group" style="border-color:' + preset.cardBorder + ';border-radius:' + radius + ';">' +
							'<div class="qlqr-bio-preview-group-header" style="color:' + preset.text + ';">' +
								( entry.item.icon ? '<span class="qlqr-bio-preview-icon">' + esc( entry.item.icon ) + '</span>' : '' ) +
								esc( entry.item.label || __( 'Group', 'plugnova-link-shortener-qr' ) ) +
								'<span class="qlqr-bio-preview-chevron">▾</span>' +
							'</div>' +
							'<div class="qlqr-bio-preview-group-children">' + childrenHtml + '</div>' +
						'</div>';
					}
					return renderLinkEntry( entry.item );
				} ).join( '' );
			}

			const html =
				announcementHtml +
				'<div class="qlqr-bio-preview-inner" style="background:' + bodyBg + ';">' +
					avatarHtml +
					'<h4 class="qlqr-bio-preview-title" style="color:' + preset.text + ';">' + esc( title ) + '</h4>' +
					( bioText ? '<p class="qlqr-bio-preview-bio" style="color:' + preset.muted + ';">' + esc( bioText ) + '</p>' : '' ) +
					socialsHtml +
					countdownHtml +
					'<div class="qlqr-bio-preview-links">' + bodyHtml + '</div>' +
				'</div>';

			$( '#qlqr-bio-preview' ).html( html );
		}

		// A single delegated listener on any form control inside the modal keeps the preview in
		// sync with every field, including dynamically added/removed repeater rows, without
		// needing a bespoke binding per input.
		$( document ).on( 'input change', '#qlqr-bio-modal input, #qlqr-bio-modal textarea, #qlqr-bio-modal select', renderBioPreview );

		// Swap the value field's placeholder (URL vs. phone number vs. email address) whenever a
		// social-icon row's platform is changed, so it always matches what that platform expects.
		$( document ).on( 'change', '.qlqr-biosocial-platform', function () {
			$( this ).siblings( '.qlqr-biosocial-url' ).attr( 'placeholder', bioSocialPlaceholder( $( this ).val() ) );
		} );

		$( '#qlqr-bio-add-social' ).on( 'click', function () {
			addBioSocialRow( {} );
			renderBioPreview();
		} );

		$( document ).on( 'click', '.qlqr-biosocial-remove', function () {
			$( this ).closest( '.qlqr-biosocial-row' ).remove();
			renderBioPreview();
		} );

		if ( $( '#qlqr-bio-socials-repeater' ).length && $.fn.sortable ) {
			$( '#qlqr-bio-socials-repeater' ).sortable( {
				handle: '.qlqr-biosocial-drag-handle',
				axis: 'y',
			} );
		}

		$( '#qlqr-bio-f-email-capture' ).on( 'change', function () {
			$( '#qlqr-bio-f-email-heading-wrap' ).toggle( $( this ).is( ':checked' ) );
		} );

		$( '#qlqr-bio-add-link' ).on( 'click', function () {
			addBioLinkRow( {} );
			refreshBioGroupOptions();
			renderBioPreview();
		} );

		$( '#qlqr-bio-add-group' ).on( 'click', function () {
			addBioLinkRow( { item_type: 'group' } );
			refreshBioGroupOptions();
			renderBioPreview();
		} );

		// Quick-fill preset: a normal link row, just pre-populated with a money-bag icon and a
		// "Donate" label so a common use case (a link to Stripe/PayPal/Patreon/Buy Me a Coffee)
		// doesn't need to be typed from scratch. Nothing about it is a distinct button "type" on
		// the backend — the admin can still edit every field afterward like any other link.
		$( '#qlqr-bio-add-donation' ).on( 'click', function () {
			addBioLinkRow( { icon: '💰', label: __( 'Donate', 'plugnova-link-shortener-qr' ) } );
			refreshBioGroupOptions();
			renderBioPreview();
			$( '#qlqr-bio-links-repeater .qlqr-biolink-row[data-item-type="link"]' ).last().find( '.qlqr-biolink-url' ).trigger( 'focus' );
		} );

		$( document ).on( 'click', '.qlqr-biolink-remove', function () {
			$( this ).closest( '.qlqr-biolink-row' ).remove();
			refreshBioGroupOptions();
			renderBioPreview();
		} );

		/**
		 * One reusable media frame for every "Select from Media Library" button in the links
		 * repeater. Its 'select' handler is rebound on each click (rather than bound once) so it
		 * always writes into the row that was actually clicked, since the repeater can hold any
		 * number of these buttons at once.
		 */
		let bioLinkImageMediaFrame = null;

		$( document ).on( 'click', '.qlqr-biolink-image-select', function ( e ) {
			e.preventDefault();

			if ( 'undefined' === typeof wp || ! wp.media ) {
				return;
			}

			const $row = $( this ).closest( '.qlqr-biolink-row' );

			if ( ! bioLinkImageMediaFrame ) {
				bioLinkImageMediaFrame = wp.media( {
					title: __( 'Select Product Image', 'plugnova-link-shortener-qr' ),
					button: { text: __( 'Use this image', 'plugnova-link-shortener-qr' ) },
					library: { type: 'image' },
					multiple: false,
				} );
			}

			bioLinkImageMediaFrame.off( 'select' ).on( 'select', function () {
				const attachment = bioLinkImageMediaFrame.state().get( 'selection' ).first().toJSON();
				$row.find( '.qlqr-biolink-image' ).val( attachment.url ).trigger( 'change' );
				setBioLinkImagePreview( $row, attachment.url );
			} );

			bioLinkImageMediaFrame.open();
		} );

		$( document ).on( 'click', '.qlqr-biolink-image-remove', function () {
			const $row = $( this ).closest( '.qlqr-biolink-row' );
			$row.find( '.qlqr-biolink-image' ).val( '' ).trigger( 'change' );
			setBioLinkImagePreview( $row, '' );
		} );

		// Keep every link's "Group" dropdown label in sync while an admin renames a group header.
		$( document ).on( 'input', '.qlqr-biolink-row[data-item-type="group"] .qlqr-biolink-label', refreshBioGroupOptions );

		/**
		 * Refresh a country multi-select's closed-state toggle button text from its current
		 * checkbox selection. Called after every checkbox change and after "Clear selection".
		 *
		 * @param {jQuery} $widget The .qlqr-country-multiselect wrapper.
		 */
		function refreshCountryMultiselectLabel( $widget ) {
			const codes = $widget.find( '.qlqr-country-ms-checkbox:checked' ).map( function () {
				return $( this ).val();
			} ).get();

			$widget.find( '.qlqr-country-ms-toggle' ).text( qlqrCountryMultiselectLabel( codes ) );
		}

		// Open/close a country dropdown panel. Delegated (rows are added/removed dynamically), and
		// closes any other open panel first so only one is ever visible at a time.
		$( document ).on( 'click', '.qlqr-country-ms-toggle', function ( e ) {
			e.stopPropagation();
			const $panel = $( this ).closest( '.qlqr-country-multiselect' ).find( '.qlqr-country-ms-panel' );
			const isOpen = $panel.is( ':visible' );

			$( '.qlqr-country-ms-panel' ).hide();
			$panel.toggle( ! isOpen );

			if ( ! isOpen ) {
				$panel.find( '.qlqr-country-ms-search' ).val( '' ).trigger( 'input' ).trigger( 'focus' );
			}
		} );

		// Clicking anywhere outside an open panel closes it — the same pattern used for the
		// column-visibility/bulk menus elsewhere in this file.
		$( document ).on( 'click', function ( e ) {
			if ( ! $( e.target ).closest( '.qlqr-country-multiselect' ).length ) {
				$( '.qlqr-country-ms-panel' ).hide();
			}
		} );

		// Filter the checkbox list as the admin types — with ~195 countries, a scrollable checkbox
		// list alone would be just as unwieldy as the native multi-select it replaces.
		$( document ).on( 'input', '.qlqr-country-ms-search', function () {
			const term = $( this ).val().toLowerCase();
			$( this ).closest( '.qlqr-country-ms-panel' ).find( '.qlqr-country-ms-option' ).each( function () {
				$( this ).toggle( -1 !== $( this ).text().toLowerCase().indexOf( term ) );
			} );
		} );

		// Keep the hidden <select multiple> (what collectBioLinks() actually reads) in sync with
		// the checkbox the admin just clicked, and refresh the toggle button's summary text. Also
		// re-renders the live preview explicitly: the generic "#qlqr-bio-modal input" change
		// listener registered above (which normally drives the preview) fires on this same
		// checkbox first, before the hidden <select> below has been updated, so without this
		// explicit call the preview would lag one click behind the actual selection.
		$( document ).on( 'change', '.qlqr-country-ms-checkbox', function () {
			const $widget = $( this ).closest( '.qlqr-country-multiselect' );
			const code = $( this ).val();
			const checked = $( this ).is( ':checked' );

			$widget.find( '.qlqr-biolink-countries option[value="' + code + '"]' ).prop( 'selected', checked );
			refreshCountryMultiselectLabel( $widget );
			renderBioPreview();
		} );

		$( document ).on( 'click', '.qlqr-country-ms-clear', function () {
			const $widget = $( this ).closest( '.qlqr-country-multiselect' );
			$widget.find( '.qlqr-country-ms-checkbox' ).prop( 'checked', false );
			$widget.find( '.qlqr-biolink-countries option' ).prop( 'selected', false );
			refreshCountryMultiselectLabel( $widget );
			renderBioPreview();
		} );

		if ( $( '#qlqr-bio-links-repeater' ).length && $.fn.sortable ) {
			$( '#qlqr-bio-links-repeater' ).sortable( {
				handle: '.qlqr-biolink-drag-handle',
				axis: 'y',
			} );
		}

		/**
		 * Reset the bio page modal back to "Create Bio Page" mode.
		 */
		function resetBioModalToCreateMode() {
			$( '#qlqr-bio-form' )[ 0 ].reset();
			$( '#qlqr-bio-f-edit-id' ).val( '' );
			$( '#qlqr-bio-modal-title' ).text( __( 'Create Bio Page', 'plugnova-link-shortener-qr' ) );
			$( '#qlqr-bio-form-submit-btn' ).text( __( 'Create Bio Page', 'plugnova-link-shortener-qr' ) );
			$( '#qlqr-bio-f-slug' ).prop( 'disabled', false );
			$( '#qlqr-bio-f-slug-locked-note' ).hide();
			$( '#qlqr-bio-form-error' ).hide().text( '' );
			$( '#qlqr-bio-links-repeater' ).empty();
			$( '#qlqr-bio-socials-repeater' ).empty();
			$( '#qlqr-bio-f-theme-color' ).val( '#2271b1' );
			$( '#qlqr-bio-f-theme-preset' ).val( 'light' );
			$( '#qlqr-bio-f-email-heading-wrap' ).hide();
			$( '#qlqr-bio-f-password-note, #qlqr-bio-f-clear-password-wrap' ).hide();
			$( '#qlqr-bio-f-starts-at, #qlqr-bio-f-ends-at' ).val( '' );
			$( '#qlqr-bio-f-announcement-enabled' ).prop( 'checked', false );
			$( '#qlqr-bio-f-announcement-fields-wrap' ).hide();
			$( '#qlqr-bio-f-announcement-text, #qlqr-bio-f-announcement-url' ).val( '' );
			$( '#qlqr-bio-f-announcement-bg' ).val( '#2271b1' );
			$( '#qlqr-bio-f-announcement-text-color' ).val( '#ffffff' );
			$( '#qlqr-bio-f-countdown-enabled' ).prop( 'checked', false );
			$( '#qlqr-bio-f-countdown-fields-wrap' ).hide();
			$( '#qlqr-bio-f-countdown-label, #qlqr-bio-f-countdown-target' ).val( '' );
			$( '#qlqr-bio-template-picker-wrap' ).show();
			setBioAvatarPreview( '' );
			renderBioPreview();
			$( '#qlqr-bio-modal' ).data( 'dirty', false );
		}

		/**
		 * Fetch a bio page's full details and populate the modal in "Edit" mode.
		 *
		 * @param {number} id Bio page ID.
		 */
		function openBioEditModal( id ) {
			qlqrAjax( 'qlqr_get_bio_page', { id: id } ).done( function ( response ) {
				if ( ! response.success ) {
					window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
					return;
				}

				const bioPage = response.data.bio_page;

				resetBioModalToCreateMode();

				$( '#qlqr-bio-f-edit-id' ).val( bioPage.id );
				$( '#qlqr-bio-modal-title' ).text( __( 'Edit Bio Page', 'plugnova-link-shortener-qr' ) );
				$( '#qlqr-bio-form-submit-btn' ).text( __( 'Save Changes', 'plugnova-link-shortener-qr' ) );

				$( '#qlqr-bio-f-slug' ).val( bioPage.slug ).prop( 'disabled', true );
				$( '#qlqr-bio-f-slug-locked-note' ).show();

				$( '#qlqr-bio-f-title' ).val( bioPage.title );
				$( '#qlqr-bio-f-bio-text' ).val( bioPage.bio_text || '' );
				$( '#qlqr-bio-f-avatar' ).val( bioPage.avatar_url || '' );
				setBioAvatarPreview( bioPage.avatar_url || '' );
				$( '#qlqr-bio-f-theme-color' ).val( bioPage.theme_color );
				$( '#qlqr-bio-f-theme-preset' ).val( bioPage.theme_preset || 'light' );
				$( '#qlqr-bio-f-button-style' ).val( bioPage.button_style );
				$( '#qlqr-bio-f-status' ).val( bioPage.status );
				$( '#qlqr-bio-f-email-capture' ).prop( 'checked', !! bioPage.email_capture_enabled );
				$( '#qlqr-bio-f-email-heading' ).val( bioPage.email_capture_heading || '' );
				$( '#qlqr-bio-f-email-heading-wrap' ).toggle( !! bioPage.email_capture_enabled );

				$( '#qlqr-bio-f-starts-at' ).val( bioPage.starts_at || '' );
				$( '#qlqr-bio-f-ends-at' ).val( bioPage.ends_at || '' );

				// Never populate the password field with anything real — just show a note that
				// blank means "keep current", plus an explicit checkbox to remove protection.
				// Mirrors the Links page's Create/Edit form exactly (see openEditModal() there).
				$( '#qlqr-bio-f-password' ).val( '' );
				if ( bioPage.has_password ) {
					$( '#qlqr-bio-f-password-note, #qlqr-bio-f-clear-password-wrap' ).show();
				}

				$( '#qlqr-bio-f-announcement-enabled' ).prop( 'checked', !! bioPage.announcement_enabled );
				$( '#qlqr-bio-f-announcement-text' ).val( bioPage.announcement_text || '' );
				$( '#qlqr-bio-f-announcement-url' ).val( bioPage.announcement_url || '' );
				$( '#qlqr-bio-f-announcement-bg' ).val( bioPage.announcement_bg_color || '#2271b1' );
				$( '#qlqr-bio-f-announcement-text-color' ).val( bioPage.announcement_text_color || '#ffffff' );
				$( '#qlqr-bio-f-announcement-fields-wrap' ).toggle( !! bioPage.announcement_enabled );

				$( '#qlqr-bio-f-countdown-enabled' ).prop( 'checked', !! bioPage.countdown_enabled );
				$( '#qlqr-bio-f-countdown-label' ).val( bioPage.countdown_label || '' );
				$( '#qlqr-bio-f-countdown-target' ).val( bioPage.countdown_target_at || '' );
				$( '#qlqr-bio-f-countdown-fields-wrap' ).toggle( !! bioPage.countdown_enabled );

				$( '#qlqr-bio-template-picker-wrap' ).hide();

				$( '#qlqr-bio-links-repeater' ).empty();
				( bioPage.links || [] ).forEach( function ( link ) {
					addBioLinkRow( link );
				} );
				refreshBioGroupOptions();

				$( '#qlqr-bio-socials-repeater' ).empty();
				( bioPage.social_links || [] ).forEach( function ( social ) {
					addBioSocialRow( social );
				} );

				renderBioPreview();
				$( '#qlqr-bio-modal' ).show().attr( 'aria-hidden', 'false' );
			} );
		}

		/**
		 * A handful of ready-made design starting points (Multiple Ready Templates) — each just
		 * sets a few fields on the already-open Create/Edit form, so an admin can still change
		 * anything afterward. Deliberately design-only (no links/socials of their own), since
		 * asking a new user to also delete a stranger's sample links would be more friction than
		 * help.
		 */
		const BIO_TEMPLATES = {
			creator: { label: __( 'Creator', 'plugnova-link-shortener-qr' ), theme_color: '#8c30f5', theme_preset: 'gradient', button_style: 'pill' },
			business: { label: __( 'Business', 'plugnova-link-shortener-qr' ), theme_color: '#2271b1', theme_preset: 'light', button_style: 'square' },
			minimalist: { label: __( 'Minimalist', 'plugnova-link-shortener-qr' ), theme_color: '#1d2327', theme_preset: 'minimal', button_style: 'rounded' },
			night: { label: __( 'Night', 'plugnova-link-shortener-qr' ), theme_color: '#1a7f37', theme_preset: 'dark', button_style: 'pill' },
		};

		/**
		 * Render the template-picker button strip from BIO_TEMPLATES.
		 */
		function renderBioTemplatePicker() {
			const html = Object.keys( BIO_TEMPLATES ).map( function ( key ) {
				const t = BIO_TEMPLATES[ key ];
				return '<button type="button" class="qlqr-bio-template-btn" data-template="' + key + '" style="border-color:' + t.theme_color + ';">' +
					'<span class="qlqr-bio-template-swatch" style="background:' + t.theme_color + ';"></span>' + esc( t.label ) +
				'</button>';
			} ).join( '' );

			$( '#qlqr-bio-template-picker' ).html( html );
		}

		/**
		 * Apply a template/export object's fields onto the currently-open form — shared by the
		 * built-in template picker (One-Click Template Import/Export's "apply a preset" half) and
		 * imported JSON files (its "load a file" half), so both paths stay in sync.
		 *
		 * @param {Object} obj Plain object; every key is optional and unrecognized keys are ignored.
		 */
		function applyBioTemplateObject( obj ) {
			if ( ! obj || 'object' !== typeof obj ) {
				return;
			}

			if ( obj.theme_color ) { $( '#qlqr-bio-f-theme-color' ).val( obj.theme_color ); }
			if ( obj.theme_preset ) { $( '#qlqr-bio-f-theme-preset' ).val( obj.theme_preset ); }
			if ( obj.button_style ) { $( '#qlqr-bio-f-button-style' ).val( obj.button_style ); }
			if ( obj.bio_text ) { $( '#qlqr-bio-f-bio-text' ).val( obj.bio_text ); }
			if ( obj.avatar_url ) {
				$( '#qlqr-bio-f-avatar' ).val( obj.avatar_url );
				setBioAvatarPreview( obj.avatar_url );
			}

			if ( Array.isArray( obj.social_links ) ) {
				$( '#qlqr-bio-socials-repeater' ).empty();
				obj.social_links.forEach( function ( s ) { addBioSocialRow( s ); } );
			}

			if ( Array.isArray( obj.links ) ) {
				$( '#qlqr-bio-links-repeater' ).empty();
				obj.links.forEach( function ( l ) { addBioLinkRow( l ); } );
				refreshBioGroupOptions();
			}

			renderBioPreview();
		}

		/**
		 * Gather the current form's design + link/social structure into a plain object suitable
		 * for JSON export (One-Click Template Import/Export's "save a file" half). Per-button click
		 * counts and IDs are page-specific and intentionally left out — a template is a reusable
		 * starting point, not a page clone.
		 *
		 * @return {Object}
		 */
		function collectBioTemplateExport() {
			return {
				theme_color: $( '#qlqr-bio-f-theme-color' ).val(),
				theme_preset: $( '#qlqr-bio-f-theme-preset' ).val(),
				button_style: $( '#qlqr-bio-f-button-style' ).val(),
				bio_text: $( '#qlqr-bio-f-bio-text' ).val(),
				avatar_url: $( '#qlqr-bio-f-avatar' ).val(),
				social_links: collectBioSocials(),
				links: collectBioLinks(),
			};
		}

		renderBioTemplatePicker();

		$( document ).on( 'click', '.qlqr-bio-template-btn', function () {
			applyBioTemplateObject( BIO_TEMPLATES[ $( this ).data( 'template' ) ] );
		} );

		$( '#qlqr-bio-export-template-btn' ).on( 'click', function () {
			const data = collectBioTemplateExport();
			const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
			const url = URL.createObjectURL( blob );

			const $link = $( '<a></a>' ).attr( 'href', url ).attr( 'download', 'bio-template-' + Date.now() + '.json' ).appendTo( 'body' );
			$link[ 0 ].click();
			$link.remove();
			URL.revokeObjectURL( url );
		} );

		$( '#qlqr-bio-import-template-btn' ).on( 'click', function () {
			$( '#qlqr-bio-import-template-file' ).val( '' ).trigger( 'click' );
		} );

		$( '#qlqr-bio-import-template-file' ).on( 'change', function ( e ) {
			const file = e.target.files && e.target.files[ 0 ];
			if ( ! file ) {
				return;
			}

			const reader = new FileReader();
			reader.onload = function () {
				let obj;
				try {
					obj = JSON.parse( reader.result );
				} catch ( err ) {
					window.alert( __( 'That file is not valid JSON.', 'plugnova-link-shortener-qr' ) );
					return;
				}

				resetBioModalToCreateMode();
				applyBioTemplateObject( obj );
				$( '#qlqr-bio-modal' ).show().attr( 'aria-hidden', 'false' );
			};
			reader.readAsText( file );
		} );

		$( '#qlqr-bio-open-create-modal' ).on( 'click', function () {
			resetBioModalToCreateMode();
			$( '#qlqr-bio-modal' ).show().attr( 'aria-hidden', 'false' );
		} );

		$( '#qlqr-bio-f-announcement-enabled' ).on( 'change', function () {
			$( '#qlqr-bio-f-announcement-fields-wrap' ).toggle( $( this ).is( ':checked' ) );
		} );

		$( '#qlqr-bio-f-countdown-enabled' ).on( 'change', function () {
			$( '#qlqr-bio-f-countdown-fields-wrap' ).toggle( $( this ).is( ':checked' ) );
		} );

		$( '#qlqr-bio-form' ).on( 'submit', function ( e ) {
			e.preventDefault();

			const $error = $( '#qlqr-bio-form-error' ).hide().text( '' );
			const editId = $( '#qlqr-bio-f-edit-id' ).val();
			const isEdit = '' !== editId;

			const payload = {
				title: $( '#qlqr-bio-f-title' ).val(),
				custom_slug: $( '#qlqr-bio-f-slug' ).val(),
				bio_text: $( '#qlqr-bio-f-bio-text' ).val(),
				avatar_url: $( '#qlqr-bio-f-avatar' ).val(),
				theme_color: $( '#qlqr-bio-f-theme-color' ).val(),
				theme_preset: $( '#qlqr-bio-f-theme-preset' ).val(),
				button_style: $( '#qlqr-bio-f-button-style' ).val(),
				status: $( '#qlqr-bio-f-status' ).val(),
				email_capture_enabled: $( '#qlqr-bio-f-email-capture' ).is( ':checked' ) ? 1 : 0,
				email_capture_heading: $( '#qlqr-bio-f-email-heading' ).val(),
				password: $( '#qlqr-bio-f-password' ).val(),
				starts_at: $( '#qlqr-bio-f-starts-at' ).val(),
				ends_at: $( '#qlqr-bio-f-ends-at' ).val(),
				announcement_enabled: $( '#qlqr-bio-f-announcement-enabled' ).is( ':checked' ) ? 1 : 0,
				announcement_text: $( '#qlqr-bio-f-announcement-text' ).val(),
				announcement_url: $( '#qlqr-bio-f-announcement-url' ).val(),
				announcement_bg_color: $( '#qlqr-bio-f-announcement-bg' ).val(),
				announcement_text_color: $( '#qlqr-bio-f-announcement-text-color' ).val(),
				countdown_enabled: $( '#qlqr-bio-f-countdown-enabled' ).is( ':checked' ) ? 1 : 0,
				countdown_label: $( '#qlqr-bio-f-countdown-label' ).val(),
				countdown_target_at: $( '#qlqr-bio-f-countdown-target' ).val(),
				links: collectBioLinks(),
				social_links: collectBioSocials(),
			};

			// Same empty-array caveat as the links form: $.param() drops empty arrays entirely, and
			// the server treats a missing "links"/"social_links" key as "leave unchanged". An empty
			// string keeps the key present so removing every button/icon actually deletes them.
			if ( 0 === payload.links.length ) {
				payload.links = '';
			}
			if ( 0 === payload.social_links.length ) {
				payload.social_links = '';
			}

			if ( isEdit ) {
				payload.clear_password = $( '#qlqr-bio-f-clear-password' ).is( ':checked' ) ? 1 : 0;
			}

			const ajaxAction = isEdit ? 'qlqr_update_bio_page' : 'qlqr_create_bio_page';
			const ajaxData = isEdit ? { id: editId, data: payload } : { data: payload };

			qlqrAjax( ajaxAction, ajaxData ).done( function ( response ) {
				if ( ! response.success ) {
					renderAjaxError( $error, response );
					$error.show();
					return;
				}

				resetBioModalToCreateMode();
				$( '#qlqr-bio-modal' ).hide().attr( 'aria-hidden', 'true' );
				loadBioPages();

				if ( response.data.qr_warning ) {
					const prefix = isEdit ? __( 'Bio page saved, but the QR code could not be regenerated:\n\n', 'plugnova-link-shortener-qr' ) : __( 'Bio page created, but the QR code could not be generated:\n\n', 'plugnova-link-shortener-qr' );
					window.alert( prefix + response.data.qr_warning );
				}
			} ).fail( function () {
				$error.text( QLQR.i18n.genericError ).show();
			} );
		} );

		$( document ).on( 'click', '.qlqr-bio-act', function () {
			const $btn = $( this );
			const action = $btn.data( 'action' );
			const id = $btn.data( 'id' );

			if ( 'copy' === action ) {
				navigator.clipboard.writeText( $btn.data( 'url' ) ).then( function () {
					window.alert( QLQR.i18n.copied );
				} );
				return;
			}

			if ( 'edit' === action ) {
				openBioEditModal( id );
				return;
			}

			if ( 'view-analytics' === action ) {
				openBioAnalyticsModal( id );
				return;
			}

			if ( 'trash' === action && ! window.confirm( __( 'Move this bio page to Trash?', 'plugnova-link-shortener-qr' ) ) ) {
				return;
			}

			if ( 'delete' === action && ! window.confirm( QLQR.i18n.confirmPermDel ) ) {
				return;
			}

			const actionMap = {
				trash: 'qlqr_trash_bio_page',
				restore: 'qlqr_restore_bio_page',
				delete: 'qlqr_delete_bio_page',
				'toggle-status': 'qlqr_toggle_bio_status',
			};

			const ajaxAction = actionMap[ action ];
			if ( ! ajaxAction ) {
				return;
			}

			qlqrAjax( ajaxAction, { id: id } ).done( function ( response ) {
				if ( ! response.success ) {
					window.alert( ( response.data && response.data.message ) || QLQR.i18n.genericError );
					return;
				}
				loadBioPages();
			} );
		} );
	} );

	// Activity Log admin page (separate ready handler — same reasoning as the Bio Links block above).
	$( function () {
		if ( 0 === $( '#qlqr-log-table' ).length ) {
			return;
		}

		const logState = {
			search: '',
			objectType: 'all',
			paged: 1,
			perPage: 20,
		};

		const OBJECT_TYPE_LABELS = {
			link: __( 'Link', 'plugnova-link-shortener-qr' ),
			bio_page: __( 'Bio Page', 'plugnova-link-shortener-qr' ),
			bulk: __( 'Bulk Action', 'plugnova-link-shortener-qr' ),
		};

		/**
		 * Render one row of the activity log table.
		 *
		 * @param {Object} entry Serialized activity log entry from the server.
		 * @return {string} HTML for a single <tr>.
		 */
		function renderLogRow( entry ) {
			const typeLabel = OBJECT_TYPE_LABELS[ entry.object_type ] || entry.object_type;

			return (
				'<tr>' +
					'<td>' + esc( entry.created_at ) + '</td>' +
					'<td>' + esc( entry.user_name || '—' ) + '</td>' +
					'<td>' + esc( typeLabel ) + '</td>' +
					'<td>' + esc( entry.action ) + '</td>' +
					'<td>' + esc( entry.description ) + '</td>' +
				'</tr>'
			);
		}

		/**
		 * Fetch activity log entries from the server and redraw the table.
		 */
		function loadActivityLog() {
			$( '#qlqr-log-tbody' ).html( ( '<tr><td colspan="5">' + __( 'Loading…', 'plugnova-link-shortener-qr' ) + '</td></tr>' ) );

			qlqrAjax( 'qlqr_query_activity_log', {
				object_type: logState.objectType,
				search: logState.search,
				paged: logState.paged,
				per_page: logState.perPage,
			} ).done( function ( response ) {
				if ( ! response.success ) {
					$( '#qlqr-log-tbody' ).html( '<tr class="qlqr-empty-row"><td colspan="5">' + esc( QLQR.i18n.genericError ) + '</td></tr>' );
					return;
				}

				const items = response.data.items || [];
				if ( 0 === items.length ) {
					$( '#qlqr-log-tbody' ).html( ( '<tr class="qlqr-empty-row"><td colspan="5">' + __( 'No activity recorded yet.', 'plugnova-link-shortener-qr' ) + '</td></tr>' ) );
				} else {
					$( '#qlqr-log-tbody' ).html( items.map( renderLogRow ).join( '' ) );
				}

				renderLogPagination( response.data.total );
			} );
		}

		/**
		 * Render simple prev/next pagination controls for the activity log table.
		 *
		 * @param {number} total Total matching records.
		 */
		function renderLogPagination( total ) {
			const totalPages = Math.max( 1, Math.ceil( total / logState.perPage ) );
			let html = '';

			html += '<button class="button" id="qlqr-log-prev-page" ' + ( logState.paged <= 1 ? 'disabled' : '' ) + ( '>' + __( '&laquo; Prev', 'plugnova-link-shortener-qr' ) + '</button>' );
			html += '<span style="padding:0 10px;">Page ' + logState.paged + __( ' of ', 'plugnova-link-shortener-qr' ) + totalPages + ' (' + total + ' total)</span>';
			html += '<button class="button" id="qlqr-log-next-page" ' + ( logState.paged >= totalPages ? 'disabled' : '' ) + ( '>' + __( 'Next &raquo;', 'plugnova-link-shortener-qr' ) + '</button>' );

			$( '#qlqr-log-pagination' ).html( html );
		}

		loadActivityLog();

		let logSearchTimer = null;
		$( '#qlqr-log-search' ).on( 'input', function () {
			clearTimeout( logSearchTimer );
			const value = $( this ).val();
			logSearchTimer = setTimeout( function () {
				logState.search = value;
				logState.paged = 1;
				loadActivityLog();
			}, 350 );
		} );

		$( '#qlqr-log-object-type' ).on( 'change', function () {
			logState.objectType = $( this ).val();
			logState.paged = 1;
			loadActivityLog();
		} );

		$( document ).on( 'click', '#qlqr-log-prev-page', function () {
			if ( logState.paged > 1 ) {
				logState.paged -= 1;
				loadActivityLog();
			}
		} );

		$( document ).on( 'click', '#qlqr-log-next-page', function () {
			logState.paged += 1;
			loadActivityLog();
		} );
	} );

	/**
	 * Free-plan limit notices (Links/Bio Links screens): WP core's .is-dismissible only removes
	 * the notice from the current page's DOM, so without this the same notice would reappear on
	 * every page load for the rest of the browsing session. sessionStorage persists the dismissal
	 * for that session without a permanent per-user setting, since the notice should come back on
	 * its own once conditions change (e.g. next session, or after upgrading). Runs on every admin
	 * screen this script loads on; the selector simply matches nothing where no notice is rendered.
	 */
	$( function () {
		$( '.qlqr-limit-notice' ).each( function () {
			const $notice = $( this );
			const key = 'qlqr_dismissed_notice_' + $notice.data( 'qlqr-notice-key' );

			if ( sessionStorage.getItem( key ) ) {
				$notice.hide();
				return;
			}

			$notice.on( 'click', '.notice-dismiss', function () {
				sessionStorage.setItem( key, '1' );
			} );
		} );
	} );
}( jQuery ) );
