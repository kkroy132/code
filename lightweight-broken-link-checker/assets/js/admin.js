/* global jQuery, lwblcAdmin */
( function ( $ ) {
	'use strict';

	if ( typeof lwblcAdmin === 'undefined' ) {
		return;
	}

	var pollTimer = null;

	function request( action, data ) {
		return $.post(
			lwblcAdmin.ajaxUrl,
			$.extend( { action: action, nonce: lwblcAdmin.nonce }, data || {} )
		);
	}

	function render( progress ) {
		if ( ! progress ) {
			return;
		}

		$( '#lwblc-progress-bar' ).css( 'width', ( progress.percent || 0 ) + '%' );
		$( '#lwblc-progress-text' ).text( progress.text || '' );

		var $scan = $( '#lwblc-scan-button' );
		var $cancel = $( '#lwblc-cancel-button' );

		if ( progress.running ) {
			$scan.prop( 'disabled', true ).text( lwblcAdmin.i18n.scanning );
			$cancel.show();
			startPolling();
		} else {
			$scan.prop( 'disabled', false ).text( lwblcAdmin.i18n.scanNow );
			$cancel.hide();
			stopPolling();
		}

		$.each( progress.counts || {}, function ( status, count ) {
			$( '#lwblc-count-' + status ).text( count );
		} );

		if ( typeof progress.total_links !== 'undefined' ) {
			$( '#lwblc-count-total' ).text( progress.total_links );
		}

		if ( progress.next_check ) {
			$( '#lwblc-next-check' ).text( progress.next_check );
		}
	}

	function poll() {
		request( 'lwblc_scan_progress' )
			.done( function ( response ) {
				if ( response && response.success ) {
					render( response.data );
				}
			} )
			.fail( stopPolling );
	}

	function startPolling() {
		if ( pollTimer ) {
			return;
		}

		pollTimer = window.setInterval( poll, lwblcAdmin.pollInterval || 3000 );
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	function busy( isBusy ) {
		$( '#lwblc-spinner' ).toggleClass( 'is-active', !! isBusy );
	}

	$( function () {
		render( lwblcAdmin.progress );

		$( '#lwblc-scan-button' ).on( 'click', function () {
			if ( ! window.confirm( lwblcAdmin.i18n.confirm ) ) {
				return;
			}

			busy( true );

			request( 'lwblc_start_scan' )
				.done( function ( response ) {
					if ( response && response.success ) {
						render( response.data.progress );
					} else if ( response && response.data && response.data.message ) {
						window.alert( response.data.message );
						render( response.data.progress );
					}
				} )
				.fail( function () {
					window.alert( lwblcAdmin.i18n.error );
				} )
				.always( function () {
					busy( false );
				} );
		} );

		$( '#lwblc-cancel-button' ).on( 'click', function () {
			busy( true );

			request( 'lwblc_cancel_scan' )
				.done( function ( response ) {
					if ( response && response.success ) {
						render( response.data.progress );
					}
				} )
				.always( function () {
					busy( false );
				} );
		} );

		// Row level "Recheck now" links.
		$( document ).on( 'click', '.lwblc-recheck', function ( event ) {
			event.preventDefault();

			var $link = $( this );
			var linkId = $link.data( 'link-id' );

			if ( ! linkId || $link.hasClass( 'lwblc-busy' ) ) {
				return;
			}

			$link.addClass( 'lwblc-busy' );

			request( 'lwblc_recheck_link', { link_id: linkId } )
				.done( function ( response ) {
					if ( response && response.success ) {
						window.location.reload();
					} else {
						window.alert( ( response && response.data && response.data.message ) || lwblcAdmin.i18n.error );
					}
				} )
				.fail( function () {
					window.alert( lwblcAdmin.i18n.error );
				} )
				.always( function () {
					$link.removeClass( 'lwblc-busy' );
				} );
		} );
	} );
}( jQuery ) );
