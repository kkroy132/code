/**
 * Personal Project Tracker — Tasks module admin JS.
 *
 * Task quick actions (complete/reopen/archive/restore/delete) reuse the
 * same REST + fetch pattern as the Projects module. Subtasks are a small
 * in-place checklist app: toggle/add/delete/reorder never reload the page,
 * since that is the most-used surface in this module and reloading on
 * every checkbox click would be poor UX.
 */
( function () {
	'use strict';

	if ( typeof ptpAdmin === 'undefined' || typeof ptpTasks === 'undefined' ) {
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
						throw new Error( ( data && data.message ) || ptpTasks.errorGeneric );
					}

					return data;
				} );
		} );
	}

	/**
	 * Task-level quick actions (list page rows and the detail page header).
	 */
	function bindTaskQuickAction( selector, method, pathSuffix, confirmMessage ) {
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
				button.textContent = ptpTasks.loadingText;

				restRequest( '/tasks/' + id + pathSuffix, method )
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
	 * Subtasks checklist: toggle/edit/delete/reorder without page reloads.
	 */
	function initSubtasksApp() {
		var app = document.getElementById( 'ptp-subtasks-app' );

		if ( ! app ) {
			return;
		}

		var taskId    = app.getAttribute( 'data-task-id' );
		var list      = document.getElementById( 'ptp-subtask-list' );
		var emptyText = document.getElementById( 'ptp-subtask-empty' );
		var addForm   = document.getElementById( 'ptp-subtask-add-form' );
		var addInput  = document.getElementById( 'ptp-new-subtask-title' );

		function updateCompletion() {
			var boxes     = list.querySelectorAll( '.ptp-js-subtask-toggle' );
			var total     = boxes.length;
			var completed = list.querySelectorAll( '.ptp-js-subtask-toggle:checked' ).length;
			var label     = document.getElementById( 'ptp-subtask-completion' );
			var bar       = document.querySelector( '#ptp-subtask-progress-bar .ptp-progress-bar' );

			if ( emptyText ) {
				emptyText.style.display = total > 0 ? 'none' : '';
			}

			if ( total === 0 ) {
				if ( label ) {
					label.textContent = '';
				}
				return;
			}

			var pct = Math.round( ( completed / total ) * 100 );

			if ( label ) {
				label.textContent = completed + ' of ' + total + ' complete (' + pct + '%)';
			}

			if ( bar ) {
				bar.style.width = pct + '%';
			}
		}

		function bindRow( row ) {
			var id = row.getAttribute( 'data-id' );

			row.querySelector( '.ptp-js-subtask-toggle' ).addEventListener( 'change', function ( e ) {
				var checkbox = e.target;
				var action   = checkbox.checked ? 'complete' : 'reopen';

				checkbox.disabled = true;

				restRequest( '/subtasks/' + id + '/' + action, 'POST' )
					.then( function () {
						checkbox.disabled = false;
						updateCompletion();
					} )
					.catch( function ( error ) {
						checkbox.checked  = ! checkbox.checked;
						checkbox.disabled = false;
						window.alert( error.message );
					} );
			} );

			var titleInput = row.querySelector( '.ptp-js-subtask-title' );
			var originalTitle = titleInput.value;

			titleInput.addEventListener( 'change', function () {
				var newTitle = titleInput.value.trim();

				if ( '' === newTitle ) {
					titleInput.value = originalTitle;
					return;
				}

				titleInput.disabled = true;

				restRequest( '/subtasks/' + id, 'POST', {
					title: newTitle,
					status: row.getAttribute( 'data-status' ) || 'todo',
					priority: row.getAttribute( 'data-priority' ) || 'medium',
					due_date: row.getAttribute( 'data-due-date' ) || ''
				} )
					.then( function () {
						titleInput.disabled = false;
						originalTitle = newTitle;
					} )
					.catch( function ( error ) {
						titleInput.disabled = false;
						titleInput.value = originalTitle;
						window.alert( error.message );
					} );
			} );

			row.querySelector( '.ptp-js-subtask-delete' ).addEventListener( 'click', function () {
				if ( ! window.confirm( ptpTasks.confirmSubtaskDelete ) ) {
					return;
				}

				restRequest( '/subtasks/' + id, 'DELETE' )
					.then( function () {
						row.remove();
						updateCompletion();
					} )
					.catch( function ( error ) {
						window.alert( error.message );
					} );
			} );

			row.querySelector( '.ptp-js-subtask-up' ).addEventListener( 'click', function () {
				var prev = row.previousElementSibling;
				if ( prev ) {
					list.insertBefore( row, prev );
					persistOrder();
				}
			} );

			row.querySelector( '.ptp-js-subtask-down' ).addEventListener( 'click', function () {
				var next = row.nextElementSibling;
				if ( next ) {
					list.insertBefore( next, row );
					persistOrder();
				}
			} );
		}

		function persistOrder() {
			var order = Array.prototype.map.call( list.querySelectorAll( '.ptp-subtask-row' ), function ( row ) {
				return parseInt( row.getAttribute( 'data-id' ), 10 );
			} );

			restRequest( '/subtasks/reorder', 'POST', { task_id: parseInt( taskId, 10 ), order: order } ).catch( function ( error ) {
				window.alert( error.message );
			} );
		}

		list.querySelectorAll( '.ptp-subtask-row' ).forEach( bindRow );

		if ( addForm ) {
			addForm.addEventListener( 'submit', function ( e ) {
				e.preventDefault();

				var title = addInput.value.trim();

				if ( '' === title ) {
					return;
				}

				addInput.disabled = true;

				restRequest( '/subtasks', 'POST', { task_id: parseInt( taskId, 10 ), title: title } )
					.then( function ( subtask ) {
						addInput.disabled = false;
						addInput.value    = '';

						var row = document.createElement( 'li' );
						row.className = 'ptp-subtask-row';
						row.setAttribute( 'data-id', subtask.id );
						row.setAttribute( 'data-status', subtask.status );
						row.setAttribute( 'data-priority', subtask.priority );
						row.setAttribute( 'data-due-date', subtask.due_date || '' );
						row.innerHTML =
							'<input type="checkbox" class="ptp-js-subtask-toggle" data-id="' + subtask.id + '" />' +
							'<input type="text" class="ptp-subtask-title-input ptp-js-subtask-title" data-id="' + subtask.id + '" value="" />' +
							'<span class="ptp-subtask-controls">' +
							'<button type="button" class="button-link ptp-js-subtask-up" data-id="' + subtask.id + '">↑</button>' +
							'<button type="button" class="button-link ptp-js-subtask-down" data-id="' + subtask.id + '">↓</button>' +
							'<button type="button" class="button-link-delete ptp-js-subtask-delete" data-id="' + subtask.id + '">×</button>' +
							'</span>';
						row.querySelector( '.ptp-js-subtask-title' ).value = subtask.title;

						list.appendChild( row );
						bindRow( row );
						updateCompletion();
					} )
					.catch( function ( error ) {
						addInput.disabled = false;
						window.alert( error.message );
					} );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindTaskQuickAction( '.ptp-js-task-complete', 'POST', '/complete', '' );
		bindTaskQuickAction( '.ptp-js-task-reopen', 'POST', '/reopen', '' );
		bindTaskQuickAction( '.ptp-js-task-archive', 'POST', '/archive', ptpTasks.confirmArchive );
		bindTaskQuickAction( '.ptp-js-task-restore', 'POST', '/restore', '' );
		bindTaskQuickAction( '.ptp-js-task-delete', 'DELETE', '', ptpTasks.confirmDelete );

		initSubtasksApp();
	} );
} )();
