/**
 * Personal Project Tracker — admin bootstrap.
 *
 * Feature modules add their own scripts in later phases. This file only
 * confirms the asset pipeline (enqueue, REST URL, nonce localization) is
 * wired correctly.
 */
( function () {
	'use strict';

	if ( typeof window.ptpAdmin === 'undefined' ) {
		return;
	}

	// eslint-disable-next-line no-console
	console.log( 'Personal Project Tracker admin assets loaded.' );
} )();
