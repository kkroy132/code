/**
 * Personal Project Tracker — Finance module admin JS.
 *
 * Delete quick actions for Expenses and Revenue reuse the same REST +
 * fetch pattern as every other module.
 */
( function () {
	'use strict';

	if ( typeof ptpAdmin === 'undefined' || typeof ptpFinance === 'undefined' ) {
		return;
	}

	function restRequest( path, method ) {
		return fetch( ptpAdmin.restUrl.replace( /\/$/, '' ) + path, {
			method: method,
			headers: { 'X-WP-Nonce': ptpAdmin.nonce },
			credentials: 'same-origin'
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( ptpFinance.errorGeneric );
			}
			return response.json().catch( function () {
				return {};
			} );
		} );
	}

	function bindDelete( selector, restBase, confirmMessage ) {
		document.querySelectorAll( selector ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! window.confirm( confirmMessage ) ) {
					return;
				}

				var id           = button.getAttribute( 'data-id' );
				var redirectTo   = button.getAttribute( 'data-redirect' );
				var originalText = button.textContent;

				button.disabled = true;
				button.classList.add( 'ptp-is-loading' );
				button.textContent = ptpFinance.loadingText;

				restRequest( restBase + '/' + id, 'DELETE' )
					.then( function () {
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
		bindDelete( '.ptp-js-expense-delete', '/finance/expenses', ptpFinance.confirmDeleteExpense );
		bindDelete( '.ptp-js-revenue-delete', '/finance/revenue', ptpFinance.confirmDeleteRevenue );
	} );
} )();
