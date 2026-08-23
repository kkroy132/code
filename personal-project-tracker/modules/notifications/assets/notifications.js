/**
 * Personal Project Tracker — Notifications admin JS.
 */
( function () {
	'use strict';

	if ( typeof ptpAdmin === 'undefined' || typeof ptpNotifications === 'undefined' ) {
		return;
	}

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
			return response.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( ( data && data.message ) ? data.message : ptpNotifications.errorGeneric );
				}
				return data;
			} );
		} );
	}

	function reload() {
		window.location.reload();
	}

	function resolveSnoozePayload( presetValue ) {
		if ( 'custom' === presetValue ) {
			var minutes = window.prompt( 'Snooze for how many minutes?', '60' );

			if ( null === minutes || '' === minutes.trim() ) {
				return null;
			}

			return { preset: 'custom', minutes: parseInt( minutes, 10 ) || 60 };
		}

		return { preset: presetValue, minutes: 0 };
	}

	function bindNotificationCenter() {
		var markAllButton = document.getElementById( 'ptp-js-mark-all-read' );

		if ( markAllButton ) {
			markAllButton.addEventListener( 'click', function () {
				restRequest( '/notifications/mark-all-read', 'POST' ).then( reload ).catch( function ( error ) {
					window.alert( error.message );
				} );
			} );
		}

		document.querySelectorAll( '.ptp-js-notification-read' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				restRequest( '/notifications/' + button.getAttribute( 'data-id' ) + '/read', 'POST' ).then( reload ).catch( function ( error ) {
					window.alert( error.message );
				} );
			} );
		} );

		document.querySelectorAll( '.ptp-js-notification-unread' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				restRequest( '/notifications/' + button.getAttribute( 'data-id' ) + '/unread', 'POST' ).then( reload ).catch( function ( error ) {
					window.alert( error.message );
				} );
			} );
		} );

		document.querySelectorAll( '.ptp-js-notification-delete' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! window.confirm( ptpNotifications.confirmDelete ) ) {
					return;
				}
				restRequest( '/notifications/' + button.getAttribute( 'data-id' ), 'DELETE' ).then( reload ).catch( function ( error ) {
					window.alert( error.message );
				} );
			} );
		} );

		document.querySelectorAll( '.ptp-js-notification-snooze' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				if ( ! select.value ) {
					return;
				}
				var payload = resolveSnoozePayload( select.value );
				select.value = '';

				if ( ! payload ) {
					return;
				}

				restRequest( '/notifications/' + select.getAttribute( 'data-id' ) + '/snooze', 'POST', payload ).then( reload ).catch( function ( error ) {
					window.alert( error.message );
				} );
			} );
		} );
	}

	function bindReminders() {
		document.querySelectorAll( '.ptp-js-reminder-delete' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! window.confirm( ptpNotifications.confirmDelete ) ) {
					return;
				}
				restRequest( '/reminders/' + button.getAttribute( 'data-id' ), 'DELETE' ).then( reload ).catch( function ( error ) {
					window.alert( error.message );
				} );
			} );
		} );

		document.querySelectorAll( '.ptp-js-reminder-snooze' ).forEach( function ( select ) {
			select.addEventListener( 'change', function () {
				if ( ! select.value ) {
					return;
				}
				var payload = resolveSnoozePayload( select.value );
				select.value = '';

				if ( ! payload ) {
					return;
				}

				restRequest( '/reminders/' + select.getAttribute( 'data-id' ) + '/snooze', 'POST', payload ).then( reload ).catch( function ( error ) {
					window.alert( error.message );
				} );
			} );
		} );
	}

	function bindReminderForm() {
		var form = document.getElementById( 'ptp-reminder-form' );

		if ( ! form ) {
			return;
		}

		var typeSelect   = document.getElementById( 'ptp-related-type' );
		var hiddenId     = document.getElementById( 'ptp-related-id' );
		var typeFields   = form.querySelectorAll( '.ptp-js-related-id-field' );
		var typeSelects  = form.querySelectorAll( '.ptp-js-related-id' );

		function showFieldFor( type ) {
			typeFields.forEach( function ( field ) {
				field.style.display = field.getAttribute( 'data-type' ) === type ? '' : 'none';
			} );
		}

		function syncHiddenId() {
			if ( ! typeSelect.value ) {
				hiddenId.value = '';
				return;
			}

			var activeSelect = form.querySelector( '.ptp-js-related-id[data-type="' + typeSelect.value + '"]' );
			hiddenId.value = activeSelect ? activeSelect.value : '';
		}

		if ( typeSelect ) {
			typeSelect.addEventListener( 'change', function () {
				showFieldFor( typeSelect.value );
				syncHiddenId();
			} );
			syncHiddenId();
		}

		typeSelects.forEach( function ( select ) {
			select.addEventListener( 'change', syncHiddenId );
		} );

		var recurrenceSelect = document.getElementById( 'ptp-recurrence' );
		var intervalField    = document.getElementById( 'ptp-recurrence-interval-field' );

		if ( recurrenceSelect && intervalField ) {
			recurrenceSelect.addEventListener( 'change', function () {
				intervalField.style.display = 'custom' === recurrenceSelect.value ? '' : 'none';
			} );
		}

		form.addEventListener( 'submit', syncHiddenId );
	}

	function bindPreferencesForm() {
		var quietEnabled = document.getElementById( 'ptp-quiet-hours-enabled' );
		var quietFields   = document.getElementById( 'ptp-quiet-hours-fields' );

		if ( quietEnabled && quietFields ) {
			quietEnabled.addEventListener( 'change', function () {
				quietFields.style.display = quietEnabled.checked ? '' : 'none';
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindNotificationCenter();
		bindReminders();
		bindReminderForm();
		bindPreferencesForm();
	} );
} )();
