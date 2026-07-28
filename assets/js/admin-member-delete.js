( function () {
	'use strict';

	document.querySelectorAll( '[data-adam-member-delete]' ).forEach( function ( container ) {
		var dialog = container.querySelector( '[data-adam-member-delete-dialog]' );
		var openButton = container.querySelector( '[data-adam-member-delete-open]' );

		if ( ! dialog || ! openButton ) {
			return;
		}

		var form = dialog.querySelector( '[data-adam-member-delete-form]' );
		var input = dialog.querySelector( '[data-adam-member-delete-confirmation]' );
		var submit = dialog.querySelector( '[data-adam-member-delete-submit]' );
		var closeButtons = dialog.querySelectorAll( '[data-adam-member-delete-close]' );

		if ( ! form || ! input || ! submit ) {
			return;
		}

		var updateConfirmation = function () {
			submit.disabled = input.value.trim() !== 'DELETE';
		};

		var closeDialog = function () {
			if ( typeof dialog.close === 'function' ) {
				dialog.close();
			} else {
				dialog.removeAttribute( 'open' );
			}

			form.reset();
			updateConfirmation();
			openButton.focus();
		};

		openButton.addEventListener( 'click', function () {
			if ( typeof dialog.showModal === 'function' ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', '' );
			}

			input.focus();
		} );

		input.addEventListener( 'input', updateConfirmation );

		closeButtons.forEach( function ( button ) {
			button.addEventListener( 'click', closeDialog );
		} );

		dialog.addEventListener( 'cancel', function ( event ) {
			event.preventDefault();
			closeDialog();
		} );

		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				closeDialog();
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			if ( input.value.trim() !== 'DELETE' ) {
				event.preventDefault();
				updateConfirmation();
				input.focus();
			}
		} );

		updateConfirmation();
	} );
}() );
