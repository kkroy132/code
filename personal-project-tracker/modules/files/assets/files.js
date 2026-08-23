/**
 * Personal Project Tracker — Files module admin JS.
 *
 * Wraps the native WordPress Media Library picker (wp.media()) so choosing
 * or uploading a file never leaves this plugin's admin screens. Detach and
 * the quick "+ Attach File" buttons embedded on other modules' detail pages
 * use the same REST + fetch pattern as everywhere else; the attach form
 * itself is a plain POST (works without JS too, via the raw file input).
 */
( function () {
	'use strict';

	function restRequest( path, method, body ) {
		var options = {
			method: method,
			headers: { 'X-WP-Nonce': ptpAdmin.nonce },
			credentials: 'same-origin'
		};

		if ( body ) {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify( body );
		}

		return fetch( ptpAdmin.restUrl.replace( /\/$/, '' ) + path, options ).then( function ( response ) {
			return response
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( ( data && data.message ) || ptpFiles.errorGeneric );
					}
					return data;
				} );
		} );
	}

	function initMediaPicker() {
		var button = document.getElementById( 'ptp-choose-file-button' );

		if ( ! button || typeof wp === 'undefined' || ! wp.media ) {
			return;
		}

		var hiddenInput = document.getElementById( 'ptp-attachment-id' );
		var previewEl   = document.getElementById( 'ptp-chosen-file-name' );
		var frame;

		button.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			if ( frame ) {
				frame.open();
				return;
			}

			frame = wp.media( {
				title: ptpFiles.mediaTitle,
				button: { text: ptpFiles.mediaButton },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				hiddenInput.value = attachment.id;

				if ( previewEl ) {
					previewEl.textContent = attachment.filename || attachment.title || '';
				}
			} );

			frame.open();
		} );
	}

	function initQuickAttachButtons() {
		document.querySelectorAll( '.ptp-js-quick-attach' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				if ( typeof wp === 'undefined' || ! wp.media ) {
					return;
				}

				var frame = wp.media( {
					title: ptpFiles.mediaTitle,
					button: { text: ptpFiles.mediaButton },
					multiple: false
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();

					restRequest( '/files', 'POST', {
						attachment_id: attachment.id,
						project_id: parseInt( button.getAttribute( 'data-project' ) || '0', 10 ),
						task_id: parseInt( button.getAttribute( 'data-task' ) || '0', 10 ),
						milestone_id: parseInt( button.getAttribute( 'data-milestone' ) || '0', 10 ),
						note_id: parseInt( button.getAttribute( 'data-note' ) || '0', 10 )
					} )
						.then( function () {
							window.location.reload();
						} )
						.catch( function ( error ) {
							window.alert( error.message );
						} );
				} );

				frame.open();
			} );
		} );
	}

	function initDetachAction() {
		document.querySelectorAll( '.ptp-js-file-detach' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! window.confirm( ptpFiles.confirmDetach ) ) {
					return;
				}

				var id           = button.getAttribute( 'data-id' );
				var redirectTo   = button.getAttribute( 'data-redirect' );
				var originalText = button.textContent;

				button.disabled = true;
				button.textContent = ptpFiles.loadingText;

				restRequest( '/files/' + id, 'DELETE' )
					.then( function () {
						window.location.href = redirectTo || window.location.href;
					} )
					.catch( function ( error ) {
						button.disabled = false;
						button.textContent = originalText;
						window.alert( error.message );
					} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof ptpAdmin === 'undefined' || typeof ptpFiles === 'undefined' ) {
			return;
		}

		initMediaPicker();
		initQuickAttachButtons();
		initDetachAction();
	} );
} )();
