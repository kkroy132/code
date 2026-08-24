/**
 * Personal Project Tracker — Projects module admin JS.
 *
 * Drives the archive/restore/delete quick actions and the Projects list's
 * drag-and-drop reorder via the REST API (ptp/v1/projects), using the
 * nonce/REST URL already localized as `ptpAdmin` by the shared admin
 * bootstrap. Create/Edit still use plain form submissions for reliability;
 * this file only covers single-click/drag actions where an inline loading
 * state (or an optimistic DOM update) makes sense.
 */
( function () {
	'use strict';

	if ( typeof ptpAdmin === 'undefined' || typeof ptpProjects === 'undefined' ) {
		return;
	}

	function restBase() {
		return ptpAdmin.restUrl.replace( /\/$/, '' );
	}

	function restRequest( path, method, body ) {
		var options = {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': ptpAdmin.nonce
			},
			credentials: 'same-origin'
		};

		if ( body ) {
			options.body = JSON.stringify( body );
		}

		return fetch( restBase() + path, options ).then( function ( response ) {
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

	/**
	 * Native HTML5 drag-and-drop reorder for the Projects list table.
	 * Only present in the DOM at all when the view is eligible for it
	 * (plain, unfiltered, first-page, sort_order-ascending — see
	 * $ptp_can_reorder in projects-list.php), so no extra guard is needed
	 * here beyond the table existing.
	 */
	function bindProjectsReorder() {
		var table = document.querySelector( '.ptp-js-projects-sortable' );

		if ( ! table ) {
			return;
		}

		var tbody   = table.querySelector( 'tbody' );
		var dragRow = null;

		function persistOrder() {
			var order = Array.prototype.map.call( tbody.querySelectorAll( '.ptp-js-project-row' ), function ( row ) {
				return parseInt( row.getAttribute( 'data-id' ), 10 );
			} );

			restRequest( '/projects/reorder', 'POST', { order: order } ).catch( function ( error ) {
				window.alert( error.message );
			} );
		}

		tbody.querySelectorAll( '.ptp-js-project-row' ).forEach( function ( row ) {
			row.addEventListener( 'dragstart', function () {
				dragRow = row;
				row.classList.add( 'ptp-is-dragging' );
			} );

			row.addEventListener( 'dragend', function () {
				row.classList.remove( 'ptp-is-dragging' );
				dragRow = null;
				persistOrder();
			} );

			row.addEventListener( 'dragover', function ( e ) {
				e.preventDefault();

				if ( ! dragRow || dragRow === row ) {
					return;
				}

				var rect     = row.getBoundingClientRect();
				var after    = ( e.clientY - rect.top ) > ( rect.height / 2 );

				tbody.insertBefore( dragRow, after ? row.nextSibling : row );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindQuickAction( '.ptp-js-archive', 'POST', '/archive', ptpProjects.confirmArchive );
		bindQuickAction( '.ptp-js-restore', 'POST', '/restore', '' );
		bindQuickAction( '.ptp-js-delete', 'DELETE', '', ptpProjects.confirmDelete );
		bindProjectsReorder();
	} );
} )();
