/**
 * Personal Project Tracker — Global Search admin JS.
 *
 * Purely progressive-enhancement UI: the filter form works with plain GET
 * submission with no JS at all (mobile included); this only saves an
 * extra tap by auto-submitting when a filter dropdown changes, the same
 * courtesy the Backup tab's scope toggling already gives other forms.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.querySelector( '.ptp-search-filter-bar' );

		if ( ! form ) {
			return;
		}

		form.querySelectorAll( 'select' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				form.submit();
			} );
		} );
	} );
} )();
