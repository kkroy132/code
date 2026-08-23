/**
 * Personal Project Tracker — Reports module admin JS.
 *
 * Progressive enhancement only: auto-submits the filter form when the
 * report-type select changes, so switching reports doesn't require an
 * extra click on "Filter". The form works fine without JS too.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var reportSelect = document.getElementById( 'ptp-report-type' );

		if ( reportSelect ) {
			reportSelect.addEventListener( 'change', function () {
				reportSelect.form.submit();
			} );
		}
	} );
} )();
