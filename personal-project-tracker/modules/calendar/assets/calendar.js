/**
 * Personal Project Tracker — Calendar module admin JS.
 *
 * Delete quick action reuses the same REST + fetch pattern as the other
 * modules. The "All day" checkbox progressively disables the time inputs —
 * the form still works correctly without JS, since an empty time input
 * simply falls back to a sensible default server-side.
 */
( function () {
	'use strict';

	function initAllDayToggle() {
		var checkbox = document.getElementById( 'ptp-all-day' );

		if ( ! checkbox ) {
			return;
		}

		var timeInputs = document.querySelectorAll( '.ptp-event-time-field input[type="time"]' );

		function sync() {
			timeInputs.forEach( function ( input ) {
				input.disabled = checkbox.checked;
			} );
		}

		checkbox.addEventListener( 'change', sync );
		sync();
	}

	function initDeleteAction() {
		if ( typeof ptpAdmin === 'undefined' || typeof ptpCalendar === 'undefined' ) {
			return;
		}

		document.querySelectorAll( '.ptp-js-event-delete' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! window.confirm( ptpCalendar.confirmDelete ) ) {
					return;
				}

				var id           = button.getAttribute( 'data-id' );
				var redirectTo   = button.getAttribute( 'data-redirect' );
				var originalText = button.textContent;

				button.disabled = true;
				button.classList.add( 'ptp-is-loading' );
				button.textContent = ptpCalendar.loadingText;

				fetch( ptpAdmin.restUrl.replace( /\/$/, '' ) + '/calendar/events/' + id, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': ptpAdmin.nonce },
					credentials: 'same-origin'
				} )
					.then( function ( response ) {
						if ( ! response.ok ) {
							throw new Error( ptpCalendar.errorGeneric );
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

	document.addEventListener( 'DOMContentLoaded', function () {
		initAllDayToggle();
		initDeleteAction();
	} );
} )();
