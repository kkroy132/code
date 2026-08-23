/**
 * Personal Project Tracker — Backup/Export/Import/Restore admin JS.
 *
 * Purely progressive-enhancement UI (show/hide the right scope fields,
 * confirmation dialogs) — every actual security check (nonce, capability,
 * the literal "REPLACE" confirmation) is enforced server-side regardless
 * of what happens here.
 */
( function () {
	'use strict';

	function toggleScopeFields( form ) {
		var scopeSelect = form.querySelector( '.ptp-js-export-scope' );

		if ( ! scopeSelect ) {
			return;
		}

		function update() {
			var scope = scopeSelect.value;
			form.querySelectorAll( '.ptp-js-export-project' ).forEach( function ( el ) {
				el.style.display = 'project' === scope ? '' : 'none';
			} );
			form.querySelectorAll( '.ptp-js-export-date' ).forEach( function ( el ) {
				el.style.display = 'date_range' === scope ? '' : 'none';
			} );
			form.querySelectorAll( '.ptp-js-export-module' ).forEach( function ( el ) {
				el.style.display = 'module' === scope ? '' : 'none';
			} );
		}

		scopeSelect.addEventListener( 'change', update );
		update();
	}

	function bindRestoreForms() {
		document.querySelectorAll( '.ptp-restore-form' ).forEach( function ( form ) {
			var modeSelect  = form.querySelector( '.ptp-js-restore-mode' );
			var confirmField = form.querySelector( '.ptp-js-restore-confirm' );

			if ( ! modeSelect || ! confirmField ) {
				return;
			}

			modeSelect.addEventListener( 'change', function () {
				confirmField.style.display = 'replace' === modeSelect.value ? '' : 'none';
			} );

			form.addEventListener( 'submit', function ( event ) {
				if ( 'merge' === modeSelect.value ) {
					if ( ! window.confirm( 'Restore this backup, merging into existing data (nothing will be overwritten)?' ) ) {
						event.preventDefault();
					}
					return;
				}

				if ( 'REPLACE' !== confirmField.value ) {
					window.alert( 'Type REPLACE exactly (all caps) to confirm.' );
					event.preventDefault();
					return;
				}

				if ( ! window.confirm( 'This permanently deletes all current Projects, Tasks, Milestones, Calendar, Time, Notes, Links, Finance, Prompts, Templates, and Reminders, then replaces them with this backup. This cannot be undone. Continue?' ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	function bindDeleteBackupForms() {
		document.querySelectorAll( '.ptp-js-delete-backup-form' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( 'Delete this backup file permanently? This cannot be undone.' ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( 'form' ).forEach( toggleScopeFields );
		bindRestoreForms();
		bindDeleteBackupForms();
	} );
} )();
