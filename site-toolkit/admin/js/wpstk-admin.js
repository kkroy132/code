/**
 * Site Toolkit admin script.
 *
 * Drives the batched audit runner. No external dependencies and no jQuery.
 */
( function () {
	'use strict';

	var config = window.wpstkData || {};
	var i18n = config.i18n || {};
	var running = false;
	var stopRequested = false;

	/**
	 * Sends a POST request to admin-ajax.php.
	 *
	 * @param {string} action AJAX action name.
	 * @return {Promise<Object>} Resolves with the decoded `data` payload.
	 */
	function request( action ) {
		var body = new window.FormData();

		body.append( 'action', action );
		body.append( 'nonce', config.nonce || '' );

		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( i18n.failed || 'Request failed' );
			} ).then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					var message = payload && payload.data && payload.data.message
						? payload.data.message
						: ( i18n.failed || 'Request failed' );

					throw new Error( message );
				}

				return payload.data || {};
			} );
		} );
	}

	/**
	 * Formats the "Step x of y" label.
	 *
	 * @param {Object} progress Progress payload.
	 * @return {string} Status line.
	 */
	function formatStatus( progress ) {
		var label = progress.label || i18n.running || '';
		var template = i18n.step || '';
		var steps = '';

		if ( template && progress.total_steps ) {
			steps = template
				.replace( '%1$d', progress.step )
				.replace( '%2$d', progress.total_steps );
		}

		if ( progress.module ) {
			label = progress.module + ' — ' + label;
		}

		return steps ? steps + ': ' + label : label;
	}

	/**
	 * Wires up one runner block.
	 *
	 * @param {Element} root Runner container.
	 */
	function setupRunner( root ) {
		var startButton = root.querySelector( '[data-wpstk-start]' );
		var cancelButton = root.querySelector( '[data-wpstk-cancel]' );
		var progressBox = root.querySelector( '[data-wpstk-progress]' );
		var bar = root.querySelector( '[data-wpstk-bar]' );
		var status = root.querySelector( '[data-wpstk-status]' );
		var message = root.querySelector( '[data-wpstk-message]' );

		/**
		 * Shows a message under the runner.
		 *
		 * @param {string}  text    Message text.
		 * @param {boolean} isError Whether to style it as an error.
		 */
		function say( text, isError ) {
			if ( ! message ) {
				return;
			}

			if ( ! text ) {
				message.hidden = true;
				message.textContent = '';

				return;
			}

			message.hidden = false;
			message.textContent = text;
			message.classList.toggle( 'wpstk-runner__message--error', !! isError );
		}

		/**
		 * Reflects a progress payload in the UI.
		 *
		 * @param {Object} progress Progress payload.
		 */
		function render( progress ) {
			if ( progressBox ) {
				progressBox.hidden = false;
			}

			if ( bar ) {
				bar.style.width = ( progress.percent || 0 ) + '%';
			}

			if ( status ) {
				status.textContent = formatStatus( progress );
			}
		}

		/**
		 * Toggles the buttons.
		 *
		 * @param {boolean} isRunning Whether an audit is in progress.
		 */
		function setBusy( isRunning ) {
			running = isRunning;

			if ( startButton ) {
				startButton.disabled = isRunning;
			}

			if ( cancelButton ) {
				cancelButton.hidden = ! isRunning;
			}

			root.setAttribute( 'data-running', isRunning ? '1' : '0' );
		}

		/**
		 * Runs one batch and schedules the next.
		 */
		function step() {
			if ( stopRequested ) {
				return;
			}

			request( 'wpstk_scan_step' ).then( function ( progress ) {
				if ( stopRequested ) {
					return;
				}

				render( progress );

				if ( progress.done ) {
					setBusy( false );
					say( i18n.finished || '' , false );

					if ( progress.redirect ) {
						window.location.href = progress.redirect;
					} else {
						window.location.reload();
					}

					return;
				}

				window.setTimeout( step, 150 );
			} ).catch( function ( error ) {
				setBusy( false );
				say( error.message || i18n.networkFail, true );
			} );
		}

		if ( startButton ) {
			startButton.addEventListener( 'click', function () {
				if ( running ) {
					return;
				}

				stopRequested = false;
				setBusy( true );
				say( '', false );

				if ( status ) {
					status.textContent = i18n.starting || '';
				}

				if ( progressBox ) {
					progressBox.hidden = false;
				}

				request( 'wpstk_start_scan' ).then( function ( progress ) {
					render( progress );
					step();
				} ).catch( function ( error ) {
					setBusy( false );
					say( error.message || i18n.failed, true );
				} );
			} );
		}

		if ( cancelButton ) {
			cancelButton.addEventListener( 'click', function () {
				if ( ! window.confirm( i18n.confirm || 'Cancel?' ) ) {
					return;
				}

				stopRequested = true;

				request( 'wpstk_cancel_scan' ).then( function ( result ) {
					setBusy( false );
					say( result.message || i18n.cancelled, false );

					if ( bar ) {
						bar.style.width = '0%';
					}

					if ( status ) {
						status.textContent = '';
					}
				} ).catch( function ( error ) {
					setBusy( false );
					say( error.message, true );
				} );
			} );
		}

		// Resume reporting on a scan that was already running when the page loaded.
		if ( '1' === root.getAttribute( 'data-running' ) ) {
			setBusy( true );
			step();
		}
	}

	/**
	 * Initialises the page.
	 */
	function init() {
		var runners = document.querySelectorAll( '[data-wpstk-runner]' );
		var i;

		for ( i = 0; i < runners.length; i++ ) {
			setupRunner( runners[ i ] );
		}

		var printButtons = document.querySelectorAll( '[data-wpstk-print]' );

		for ( i = 0; i < printButtons.length; i++ ) {
			printButtons[ i ].addEventListener( 'click', function () {
				window.print();
			} );
		}

		document.addEventListener( 'click', function ( event ) {
			var target = event.target.closest ? event.target.closest( '[data-wpstk-confirm]' ) : null;

			if ( ! target ) {
				return;
			}

			if ( ! window.confirm( target.getAttribute( 'data-wpstk-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
