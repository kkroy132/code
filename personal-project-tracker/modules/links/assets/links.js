/**
 * Personal Project Tracker — Links module admin JS.
 *
 * Delete quick action reuses the same REST + fetch pattern as every other
 * module.
 */
( function () {
	'use strict';

	function initDeleteAction() {
		if ( typeof ptpAdmin === 'undefined' || typeof ptpLinks === 'undefined' ) {
			return;
		}

		document.querySelectorAll( '.ptp-js-link-delete' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! window.confirm( ptpLinks.confirmDelete ) ) {
					return;
				}

				var id           = button.getAttribute( 'data-id' );
				var redirectTo   = button.getAttribute( 'data-redirect' );
				var originalText = button.textContent;

				button.disabled = true;
				button.classList.add( 'ptp-is-loading' );
				button.textContent = ptpLinks.loadingText;

				fetch( ptpAdmin.restUrl.replace( /\/$/, '' ) + '/links/' + id, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': ptpAdmin.nonce },
					credentials: 'same-origin'
				} )
					.then( function ( response ) {
						if ( ! response.ok ) {
							throw new Error( ptpLinks.errorGeneric );
						}
						window.location.href = redirectTo || window.location.href;
					} )
					.catch( function ( error ) {
						button.disabled = false;
						button.classList.remove( 'ptp-is-loading' );
						button.textContent = originalText;
						window.alert( error.message );
					} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', initDeleteAction );
} )();
