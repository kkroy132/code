/**
 * Personal Project Tracker — Time Tracking module admin JS.
 *
 * Three independent pieces, each safe to no-op if its markup isn't on the
 * page: the Active Timer widget (live tick + pause/resume/stop), the
 * Start Timer mini-form (and the same "Start Timer" button embedded on the
 * Task detail page), and the manual entry form's start/end -> duration
 * auto-calculation. All timer control goes through the same REST + fetch
 * pattern as every other module.
 */
( function () {
	'use strict';

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

		return fetch( ptpAdmin.restUrl.replace( /\/$/, '' ) + path, options ).then( function ( response ) {
			return response
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( ( data && data.message ) || ptpTime.errorGeneric );
					}

					return data;
				} );
		} );
	}

	function formatClock( totalSeconds ) {
		totalSeconds = Math.max( 0, Math.floor( totalSeconds ) );

		var hours   = Math.floor( totalSeconds / 3600 );
		var minutes = Math.floor( ( totalSeconds % 3600 ) / 60 );
		var seconds = totalSeconds % 60;

		function pad( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		}

		return pad( hours ) + ':' + pad( minutes ) + ':' + pad( seconds );
	}

	/**
	 * Filter a task <select>'s options to only those belonging to the
	 * selected project (options are tagged with data-project). Reused by
	 * both the Start Timer form and the manual entry form.
	 */
	function initProjectTaskCascade( projectSelectId, taskSelectId ) {
		var projectSelect = document.getElementById( projectSelectId );
		var taskSelect    = document.getElementById( taskSelectId );

		if ( ! projectSelect || ! taskSelect ) {
			return;
		}

		var options = Array.prototype.slice.call( taskSelect.options );

		function sync() {
			var projectId = projectSelect.value;

			options.forEach( function ( option ) {
				if ( '' === option.value ) {
					return;
				}

				var matches = '' === projectId || option.getAttribute( 'data-project' ) === projectId;
				option.hidden = ! matches;

				if ( ! matches && option.selected ) {
					taskSelect.value = '';
				}
			} );
		}

		projectSelect.addEventListener( 'change', sync );
		sync();
	}

	/**
	 * Active Timer widget: live tick while running, pause/resume/stop.
	 */
	function initTimerWidget() {
		var app = document.getElementById( 'ptp-timer-app' );

		if ( ! app || '1' !== app.getAttribute( 'data-active' ) ) {
			return;
		}

		var id      = app.getAttribute( 'data-id' );
		var status  = app.getAttribute( 'data-status' );
		var seconds = parseInt( app.getAttribute( 'data-seconds' ), 10 ) || 0;
		var display = document.getElementById( 'ptp-timer-display' );
		var loadedAt = Date.now();

		function render() {
			if ( ! display ) {
				return;
			}

			var elapsed = 'running' === status ? Math.floor( ( Date.now() - loadedAt ) / 1000 ) : 0;
			display.textContent = formatClock( seconds + elapsed );
		}

		render();

		if ( 'running' === status ) {
			window.setInterval( render, 1000 );
		}

		function runAction( action ) {
			return restRequest( '/time/' + id + '/' + action, 'POST' ).then( function () {
				window.location.reload();
			} );
		}

		var pauseButton  = app.querySelector( '.ptp-js-timer-pause' );
		var resumeButton = app.querySelector( '.ptp-js-timer-resume' );
		var stopButton   = app.querySelector( '.ptp-js-timer-stop' );

		if ( pauseButton ) {
			pauseButton.addEventListener( 'click', function () {
				pauseButton.disabled = true;
				runAction( 'pause' ).catch( function ( error ) {
					pauseButton.disabled = false;
					window.alert( error.message );
				} );
			} );
		}

		if ( resumeButton ) {
			resumeButton.addEventListener( 'click', function () {
				resumeButton.disabled = true;
				runAction( 'resume' ).catch( function ( error ) {
					resumeButton.disabled = false;
					window.alert( error.message );
				} );
			} );
		}

		if ( stopButton ) {
			stopButton.addEventListener( 'click', function () {
				stopButton.disabled = true;
				runAction( 'stop' ).catch( function ( error ) {
					stopButton.disabled = false;
					window.alert( error.message );
				} );
			} );
		}
	}

	/**
	 * Start Timer mini-form on the Time Tracking page (only rendered when
	 * there is no active timer).
	 */
	function initStartTimerForm() {
		var form = document.getElementById( 'ptp-start-timer-form' );

		if ( ! form ) {
			return;
		}

		initProjectTaskCascade( 'ptp-timer-project', 'ptp-timer-task' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var submitButton = form.querySelector( 'button[type="submit"]' );
			submitButton.disabled = true;

			restRequest( '/time/start', 'POST', {
				project_id: parseInt( document.getElementById( 'ptp-timer-project' ).value, 10 ) || 0,
				task_id: parseInt( document.getElementById( 'ptp-timer-task' ).value, 10 ) || 0,
				description: document.getElementById( 'ptp-timer-description' ).value
			} )
				.then( function () {
					window.location.reload();
				} )
				.catch( function ( error ) {
					submitButton.disabled = false;
					window.alert( error.message );
				} );
		} );
	}

	/**
	 * "Start Timer" button embedded on the Task detail page.
	 */
	function initTaskStartTimerButton() {
		var button = document.querySelector( '.ptp-js-start-task-timer' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			button.textContent = ptpTime.loadingText;

			restRequest( '/time/start', 'POST', {
				task_id: parseInt( button.getAttribute( 'data-task-id' ), 10 ) || 0,
				project_id: parseInt( button.getAttribute( 'data-project-id' ), 10 ) || 0
			} )
				.then( function () {
					window.location.href = ptpTime.timeTrackingUrl;
				} )
				.catch( function ( error ) {
					button.disabled = false;
					button.textContent = ptpTime.errorGeneric;
					window.alert( error.message );
				} );
		} );
	}

	/**
	 * Manual entry form: auto-fill duration (minutes) from start/end time,
	 * and cascade the Task select to the selected Project.
	 */
	function initManualEntryForm() {
		var form = document.getElementById( 'ptp-time-entry-form' );

		if ( ! form ) {
			return;
		}

		initProjectTaskCascade( 'ptp-project', 'ptp-task' );

		var startInput    = document.getElementById( 'ptp-start-time' );
		var endInput       = document.getElementById( 'ptp-end-time' );
		var durationInput = document.getElementById( 'ptp-duration' );

		function syncDuration() {
			if ( ! startInput.value || ! endInput.value ) {
				return;
			}

			var start = startInput.value.split( ':' );
			var end   = endInput.value.split( ':' );
			var startMinutes = parseInt( start[0], 10 ) * 60 + parseInt( start[1], 10 );
			var endMinutes   = parseInt( end[0], 10 ) * 60 + parseInt( end[1], 10 );

			if ( endMinutes > startMinutes ) {
				durationInput.value = endMinutes - startMinutes;
			}
		}

		startInput.addEventListener( 'change', syncDuration );
		endInput.addEventListener( 'change', syncDuration );
	}

	function initDeleteAction() {
		document.querySelectorAll( '.ptp-js-time-delete' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! window.confirm( ptpTime.confirmDelete ) ) {
					return;
				}

				var id = button.getAttribute( 'data-id' );
				var originalText = button.textContent;

				button.disabled = true;
				button.textContent = ptpTime.loadingText;

				restRequest( '/time/' + id, 'DELETE' )
					.then( function () {
						window.location.reload();
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
		if ( typeof ptpAdmin === 'undefined' || typeof ptpTime === 'undefined' ) {
			return;
		}

		initTimerWidget();
		initStartTimerForm();
		initTaskStartTimerButton();
		initManualEntryForm();
		initDeleteAction();
	} );
} )();
