/**
 * Personal Project Tracker — Projects module admin JS.
 *
 * Drives the archive/restore/delete quick actions via the REST API
 * (ptp/v1/projects) using the nonce/REST URL already localized as
 * `ptpAdmin` by the shared admin bootstrap. Create/Edit still use plain
 * form submissions for reliability; this file only covers single-click
 * actions where an inline loading state and a confirmation makes sense.
 */
( function () {
	'use strict';

	if ( typeof ptpAdmin === 'undefined' || typeof ptpProjects === 'undefined' ) {
		return;
	}

	function restBase() {
		return ptpAdmin.restUrl.replace( /\/$/, '' );
	}

	function restRequest( path, method ) {
		return fetch( restBase() + path, {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ptpAdmin.nonce
			},
			credentials: 'same-origin'
		} ).then( function ( response ) {
			return response
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( body ) {
					if ( ! response.ok ) {
						throw new Error( ( body && body.message ) || ptpProjects.errorGeneric );
					}

					return body;
				} );
		} );
	}

	function bindQuickAction( selector, method, pathSuffix, confirmMessage ) {
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
				button.textContent = ptpProjects.loadingText;

				restRequest( '/projects/' + id + pathSuffix, method )
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
		bindQuickAction( '.ptp-js-archive', 'POST', '/archive', ptpProjects.confirmArchive );
		bindQuickAction( '.ptp-js-restore', 'POST', '/restore', '' );
		bindQuickAction( '.ptp-js-delete', 'DELETE', '', ptpProjects.confirmDelete );
	} );
} )();
