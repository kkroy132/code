/**
 * Personal Project Tracker — AI Prompt Studio admin JS.
 *
 * Generate calls the plugin's own REST endpoint (which assembles text from
 * this plugin's own data — never an external AI API) and only ever fills
 * the editable content box; nothing is persisted until Save is submitted.
 * Copy/Share operate purely client-side on whatever text is currently in
 * the content box.
 */
( function () {
	'use strict';

	if ( typeof ptpAdmin === 'undefined' || typeof ptpPrompts === 'undefined' ) {
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
			return response.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( ( data && data.message ) ? data.message : ptpPrompts.errorGeneric );
				}
				return data;
			} );
		} );
	}

	function withLoading( button, loadingText, fn ) {
		var originalText = button.textContent;
		button.disabled = true;
		button.classList.add( 'ptp-is-loading' );
		button.textContent = loadingText;

		return fn().finally( function () {
			button.disabled = false;
			button.classList.remove( 'ptp-is-loading' );
			button.textContent = originalText;
		} );
	}

	function bindQuickAction( selector, restPathFn, method, confirmMessage, onSuccess ) {
		document.querySelectorAll( selector ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				if ( confirmMessage && ! window.confirm( confirmMessage ) ) {
					return;
				}

				var id = button.getAttribute( 'data-id' );

				withLoading( button, ptpPrompts.loadingText, function () {
					return restRequest( restPathFn( id ), method ).then( function ( data ) {
						onSuccess( button, data, id );
					} );
				} ).catch( function ( error ) {
					window.alert( error.message );
				} );
			} );
		} );
	}

	function reload() {
		window.location.reload();
	}

	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var helper = document.createElement( 'textarea' );
			helper.value = text;
			helper.style.position = 'fixed';
			helper.style.opacity = '0';
			document.body.appendChild( helper );
			helper.focus();
			helper.select();

			try {
				if ( document.execCommand( 'copy' ) ) {
					resolve();
				} else {
					reject( new Error( ptpPrompts.copyFailed ) );
				}
			} catch ( err ) {
				reject( new Error( ptpPrompts.copyFailed ) );
			} finally {
				document.body.removeChild( helper );
			}
		} );
	}

	function bindCopyShare() {
		var contentField = document.getElementById( 'ptp-content' );
		var copyButton   = document.getElementById( 'ptp-js-copy' );
		var shareButton  = document.getElementById( 'ptp-js-share' );

		if ( copyButton && contentField ) {
			copyButton.addEventListener( 'click', function () {
				copyText( contentField.value ).then( function () {
					window.alert( ptpPrompts.copiedText );
				} ).catch( function ( error ) {
					window.alert( error.message );
				} );
			} );
		}

		if ( shareButton && contentField ) {
			shareButton.addEventListener( 'click', function () {
				if ( navigator.share ) {
					navigator.share( { title: ptpPrompts.shareTitle, text: contentField.value } ).catch( function () {
						/* user cancelled or share failed silently */
					} );
					return;
				}

				copyText( contentField.value ).then( function () {
					window.alert( ptpPrompts.copiedText );
				} ).catch( function ( error ) {
					window.alert( error.message );
				} );
			} );
		}
	}

	function toggleCustomField( selectId, fieldId, customValue ) {
		var select = document.getElementById( selectId );
		var field  = document.getElementById( fieldId );

		if ( ! select || ! field ) {
			return;
		}

		function update() {
			field.style.display = select.value === customValue ? '' : 'none';
		}

		select.addEventListener( 'change', update );
		update();
	}

	function bindProjectFilter() {
		var projectSelect = document.getElementById( 'ptp-project' );

		if ( ! projectSelect ) {
			return;
		}

		function applyFilter() {
			var projectId = projectSelect.value;

			document.querySelectorAll( '#ptp-task-checklist .ptp-checkbox-list-item, #ptp-milestone-checklist .ptp-checkbox-list-item' ).forEach( function ( item ) {
				var itemProject = item.getAttribute( 'data-project' );
				item.style.display = ( ! projectId || itemProject === projectId ) ? '' : 'none';
			} );
		}

		projectSelect.addEventListener( 'change', applyFilter );
		applyFilter();
	}

	function collectFormConfig( form ) {
		var checked = function ( name ) {
			var field = form.querySelector( '[name="' + name + '"]' );
			return !! ( field && field.checked );
		};

		var values = function ( name ) {
			return Array.prototype.slice.call( form.querySelectorAll( '[name="' + name + '"]:checked' ) ).map( function ( el ) {
				return el.value;
			} );
		};

		var value = function ( name ) {
			var field = form.querySelector( '[name="' + name + '"]' );
			return field ? field.value : '';
		};

		return {
			role: value( 'role' ),
			custom_role_label: value( 'custom_role_label' ),
			goal: value( 'goal' ),
			output_format: value( 'output_format' ),
			custom_output_label: value( 'custom_output_label' ),
			project_id: value( 'project_id' ),
			task_ids: values( 'task_ids[]' ),
			milestone_ids: values( 'milestone_ids[]' ),
			include_time: checked( 'include_time' ),
			include_notes: checked( 'include_notes' ),
			include_links: checked( 'include_links' ),
			include_files: checked( 'include_files' ),
			include_finance: checked( 'include_finance' ),
			include_reports: checked( 'include_reports' ),
			include_activity: checked( 'include_activity' ),
			requirements: value( 'requirements' ),
			constraints: value( 'constraints' )
		};
	}

	function bindGenerate() {
		var generateButton = document.getElementById( 'ptp-js-generate' );
		var form            = document.getElementById( 'ptp-prompt-form' );
		var contentField    = document.getElementById( 'ptp-content' );
		var warnings         = document.getElementById( 'ptp-generate-warnings' );

		if ( ! generateButton || ! form || ! contentField ) {
			return;
		}

		generateButton.addEventListener( 'click', function () {
			var goalField = form.querySelector( '[name="goal"]' );

			if ( goalField && '' === goalField.value.trim() ) {
				window.alert( ptpPrompts.errorGeneric );
				goalField.focus();
				return;
			}

			withLoading( generateButton, ptpPrompts.generatingText, function () {
				return restRequest( '/prompts/generate', 'POST', collectFormConfig( form ) ).then( function ( data ) {
					contentField.value = data.content || '';

					if ( warnings ) {
						warnings.textContent = ( data.warnings && data.warnings.length ) ? data.warnings.join( ' ' ) : '';
					}
				} );
			} ).catch( function ( error ) {
				window.alert( error.message );
			} );
		} );
	}

	function bindTemplateApply() {
		var select   = document.getElementById( 'ptp-template-apply' );
		var dataHolder = document.getElementById( 'ptp-template-data' );
		var form     = document.getElementById( 'ptp-prompt-form' );

		if ( ! select || ! dataHolder || ! form ) {
			return;
		}

		var templates = [];

		try {
			templates = JSON.parse( dataHolder.getAttribute( 'data-templates' ) || '[]' );
		} catch ( err ) {
			templates = [];
		}

		select.addEventListener( 'change', function () {
			var template = templates.filter( function ( t ) {
				return String( t.id ) === select.value;
			} )[ 0 ];

			if ( ! template ) {
				return;
			}

			var setValue = function ( name, value ) {
				var field = form.querySelector( '[name="' + name + '"]' );
				if ( field && undefined !== value ) {
					field.value = value;
					field.dispatchEvent( new Event( 'change' ) );
				}
			};

			setValue( 'role', template.role );
			setValue( 'custom_role_label', template.custom_role_label );
			setValue( 'output_format', template.output_format );
			setValue( 'custom_output_label', template.custom_output_label );
			setValue( 'requirements', template.requirements );
			setValue( 'constraints', template.constraints );

			var goalField = form.querySelector( '[name="goal"]' );

			if ( goalField && '' === goalField.value.trim() && template.goal_placeholder ) {
				goalField.value = template.goal_placeholder;
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindCopyShare();
		bindGenerate();
		bindTemplateApply();
		bindProjectFilter();
		toggleCustomField( 'ptp-role', 'ptp-custom-role-field', 'custom' );
		toggleCustomField( 'ptp-output-format', 'ptp-custom-output-field', 'custom' );

		bindQuickAction( '.ptp-js-prompt-favorite', function ( id ) { return '/prompts/' + id + '/favorite'; }, 'POST', null, reload );
		bindQuickAction( '.ptp-js-prompt-unfavorite', function ( id ) { return '/prompts/' + id + '/unfavorite'; }, 'POST', null, reload );
		bindQuickAction( '.ptp-js-prompt-delete', function ( id ) { return '/prompts/' + id; }, 'DELETE', ptpPrompts.confirmDelete, function ( button ) {
			var redirect = button.getAttribute( 'data-redirect' );
			window.location.href = redirect || window.location.href;
		} );
		bindQuickAction( '.ptp-js-prompt-duplicate', function ( id ) { return '/prompts/' + id + '/duplicate'; }, 'POST', null, function ( button, data ) {
			var newId = data && data.id ? data.id : null;

			if ( ! newId ) {
				reload();
				return;
			}

			window.location.href = window.location.pathname + '?page=ptp-ai-prompts&action=view&id=' + newId;
		} );
		bindQuickAction( '.ptp-js-template-delete', function ( id ) { return '/prompts/templates/' + id; }, 'DELETE', ptpPrompts.confirmDelete, reload );
	} );
} )();
