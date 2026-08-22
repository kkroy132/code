/**
 * Personal Project Tracker — Milestones module admin JS.
 *
 * Quick actions (complete/reopen/archive/restore/delete) reuse the same
 * REST + fetch pattern as Projects/Tasks. The "Related Tasks" add/remove
 * controls on the milestone detail page never reload the page — they
 * update the in-place list, since this is the most-used interaction here.
 */
( function () {
	'use strict';

	if ( typeof ptpAdmin === 'undefined' || typeof ptpMilestones === 'undefined' ) {
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
				.then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( ( data && data.message ) || ptpMilestones.errorGeneric );
					}

					return data;
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
				button.textContent = ptpMilestones.loadingText;

				restRequest( '/milestones/' + id + pathSuffix, method )
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

	function bindTaskRemove( row ) {
		var button = row.querySelector( '.ptp-js-milestone-task-remove' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			if ( ! window.confirm( ptpMilestones.confirmRemoveTask ) ) {
				return;
			}

			var app         = document.getElementById( 'ptp-milestone-tasks-app' );
			var milestoneId = app.getAttribute( 'data-milestone-id' );
			var taskId      = button.getAttribute( 'data-id' );

			restRequest( '/milestones/' + milestoneId + '/tasks/' + taskId, 'DELETE' )
				.then( function () {
					row.remove();
					var list  = document.getElementById( 'ptp-milestone-task-list' );
					var empty = document.getElementById( 'ptp-milestone-tasks-empty' );
					if ( empty && 0 === list.querySelectorAll( 'li' ).length ) {
						empty.style.display = '';
					}
				} )
				.catch( function ( error ) {
					window.alert( error.message );
				} );
		} );
	}

	function initMilestoneTasksApp() {
		var app = document.getElementById( 'ptp-milestone-tasks-app' );

		if ( ! app ) {
			return;
		}

		var milestoneId = app.getAttribute( 'data-milestone-id' );
		var list        = document.getElementById( 'ptp-milestone-task-list' );
		var empty       = document.getElementById( 'ptp-milestone-tasks-empty' );
		var addForm     = document.getElementById( 'ptp-milestone-task-add-form' );
		var select       = document.getElementById( 'ptp-milestone-task-select' );

		list.querySelectorAll( 'li' ).forEach( bindTaskRemove );

		if ( addForm ) {
			addForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();

				var taskId = select.value;

				if ( ! taskId ) {
					return;
				}

				var submitButton = addForm.querySelector( 'button[type="submit"]' );
				submitButton.disabled = true;

				restRequest( '/milestones/' + milestoneId + '/tasks', 'POST', { task_id: parseInt( taskId, 10 ) } )
					.then( function () {
						window.location.reload();
					} )
					.catch( function ( error ) {
						submitButton.disabled = false;
						window.alert( error.message );
					} );
			} );
		}

		if ( empty && list.querySelectorAll( 'li' ).length > 0 ) {
			empty.style.display = 'none';
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindQuickAction( '.ptp-js-milestone-complete', 'POST', '/complete', '' );
		bindQuickAction( '.ptp-js-milestone-reopen', 'POST', '/reopen', '' );
		bindQuickAction( '.ptp-js-milestone-archive', 'POST', '/archive', ptpMilestones.confirmArchive );
		bindQuickAction( '.ptp-js-milestone-restore', 'POST', '/restore', '' );
		bindQuickAction( '.ptp-js-milestone-delete', 'DELETE', '', ptpMilestones.confirmDelete );

		initMilestoneTasksApp();
	} );
} )();
