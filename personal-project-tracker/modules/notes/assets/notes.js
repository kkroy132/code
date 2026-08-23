/**
 * Personal Project Tracker — Notes module admin JS.
 *
 * Quick actions (pin/unpin/archive/restore/delete) reuse the same REST +
 * fetch pattern as every other module.
 */
( function () {
	'use strict';

	if ( typeof ptpAdmin === 'undefined' || typeof ptpNotes === 'undefined' ) {
		return;
	}

	function restRequest( path, method ) {
		return fetch( ptpAdmin.restUrl.replace( /\/$/, '' ) + path, {
			method: method,
			headers: { 'X-WP-Nonce': ptpAdmin.nonce },
			credentials: 'same-origin'
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( ptpNotes.errorGeneric );
			}
			return response.json().catch( function () {
				return {};
			} );
		} );
	}

	function bindQuickAction( selector, method, pathSuffix, confirmMessage, reload ) {
		document.querySelectorAll( selector ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( confirmMessage && ! window.confirm( confirmMessage ) ) {
					return;
				}

				var id           = button.getAttribute( 'data-id' );
				var redirectTo   = button.getAttribute( 'data-redirect' );
				var originalText = button.textContent;

				button.disabled = true;
				button.classList.add( 'ptp-is-loading' );
				button.textContent = ptpNotes.loadingText;

				restRequest( '/notes/' + id + pathSuffix, method )
					.then( function () {
						if ( reload ) {
							window.location.href = redirectTo || window.location.href;
						}
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
		bindQuickAction( '.ptp-js-note-pin', 'POST', '/pin', '', true );
		bindQuickAction( '.ptp-js-note-unpin', 'POST', '/unpin', '', true );
		bindQuickAction( '.ptp-js-note-archive', 'POST', '/archive', '', true );
		bindQuickAction( '.ptp-js-note-restore', 'POST', '/restore', '', true );
		bindQuickAction( '.ptp-js-note-delete', 'DELETE', '', ptpNotes.confirmDelete, true );
	} );
} )();
