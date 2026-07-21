( function () {
	'use strict';

	var config = window.adamCommunicationPreferences || {};

	function setDialogOpen( dialog, open ) {
		if ( open ) {
			if ( typeof dialog.showModal === 'function' ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', 'open' );
			}
			return;
		}

		if ( typeof dialog.close === 'function' ) {
			dialog.close();
		} else {
			dialog.removeAttribute( 'open' );
		}
	}

	function initialize( root ) {
		var dialog = root.querySelector( '[data-adam-communication-preferences-dialog]' );
		var openButton = root.querySelector( '[data-adam-communication-preferences-open]' );
		var form = root.querySelector( '[data-adam-communication-preferences-form]' );
		var status = root.querySelector( '[data-adam-communication-preferences-status]' );

		if ( ! dialog || ! openButton || ! form ) {
			return;
		}

		function closeAndReset() {
			form.reset();
			if ( status ) {
				status.textContent = '';
				status.classList.remove( 'is-error', 'is-success' );
			}
			setDialogOpen( dialog, false );
			openButton.focus();
		}

		openButton.addEventListener( 'click', function () {
			if ( status ) {
				status.textContent = '';
				status.classList.remove( 'is-error', 'is-success' );
			}
			setDialogOpen( dialog, true );
		} );

		root.querySelectorAll( '[data-adam-communication-preferences-cancel]' ).forEach( function ( button ) {
			button.addEventListener( 'click', closeAndReset );
		} );

		dialog.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
			closeAndReset();
		} );

		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				closeAndReset();
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var submit = form.querySelector( '[type="submit"]' );
			var payload = new FormData( form );
			payload.set( 'action', config.action || 'adam_membership_save_communication_preferences' );
			payload.set( 'nonce', config.nonce || '' );

			if ( submit ) {
				submit.disabled = true;
			}

			if ( status ) {
				status.textContent = config.messages && config.messages.saving ? config.messages.saving : 'A guardar…';
				status.classList.remove( 'is-error', 'is-success' );
			}

			window.fetch( config.ajaxUrl || window.ajaxurl || '', {
				method: 'POST',
				credentials: 'same-origin',
				body: payload,
				headers: {
					'X-Requested-With': 'XMLHttpRequest'
				}
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( result ) {
				if ( ! result || ! result.success ) {
					throw new Error( result && result.data && result.data.message ? result.data.message : '' );
				}

				form.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( input ) {
					input.defaultChecked = input.checked;
				} );

				if ( status ) {
					status.textContent = result.data && result.data.message ? result.data.message : ( config.messages && config.messages.saved ? config.messages.saved : 'Preferências guardadas.' );
					status.classList.add( 'is-success' );
				}
			} ).catch( function ( error ) {
				if ( status ) {
					status.textContent = error.message || ( config.messages && config.messages.error ? config.messages.error : 'Não foi possível guardar as preferências.' );
					status.classList.add( 'is-error' );
				}
			} ).finally( function () {
				if ( submit ) {
					submit.disabled = false;
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-adam-communication-preferences]' ).forEach( initialize );
	} );
}() );
