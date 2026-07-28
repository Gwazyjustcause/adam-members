( function () {
	'use strict';

	var config = window.adamMembershipFormsConfig || {};

	function updateFee( form ) {
		if ( ! form ) {
			return;
		}

		var feeNode = form.querySelector( '[data-adam-fee-value]' );

		if ( ! feeNode ) {
			return;
		}

		var registrationMode = form.querySelector( 'input[name="membership_mode"]:checked' );
		var renewalMode = form.querySelector( 'input[name="renewal_mode"]:checked' );
		var mode = registrationMode ? registrationMode.value : ( renewalMode ? renewalMode.value : 'adam_primary' );

		feeNode.textContent = 'external_association' === mode ? ( config.secondaryFee || '' ) : ( config.primaryFee || '' );
	}

	function toggleConditional( form, selector, visible ) {
		var node = form.querySelector( selector );

		if ( ! node ) {
			return;
		}

		node.hidden = ! visible;
	}

	function syncFormState( form, updateAnaInformation ) {
		var registrationMode = form.querySelector( 'input[name="membership_mode"]:checked' );
		var renewalMode = form.querySelector( 'input[name="renewal_mode"]:checked' );
		var profileChanged = form.querySelector( 'input[name="profile_changed"]:checked' );

		toggleConditional(
			form,
			'[data-adam-conditional="registration-external"]',
			Boolean( registrationMode && 'external_association' === registrationMode.value )
		);
		toggleConditional(
			form,
			'[data-adam-conditional="renewal-external"]',
			Boolean( renewalMode && 'external_association' === renewalMode.value )
		);
		toggleConditional(
			form,
			'[data-adam-conditional="renewal-profile"]',
			Boolean( profileChanged && '1' === profileChanged.value )
		);
		if ( updateAnaInformation ) {
			toggleConditional(
				form,
				'[data-adam-ana-information]',
				Boolean( registrationMode && 'adam_primary' === registrationMode.value )
			);
		}
		updateFee( form );
	}

	function isValidPortugueseNif( value ) {
		if ( ! /^[1235689]\d{8}$/.test( value ) ) {
			return false;
		}

		var sum = 0;

		for ( var index = 0; index < 8; index += 1 ) {
			sum += Number( value.charAt( index ) ) * ( 9 - index );
		}

		var checkDigit = 11 - ( sum % 11 );

		if ( checkDigit >= 10 ) {
			checkDigit = 0;
		}

		return checkDigit === Number( value.charAt( 8 ) );
	}

	function setNifState( form, input, feedback, status, message ) {
		var blocked = [ 'invalid', 'duplicate', 'checking', 'error' ].includes( status );

		input.dataset.adamNifStatus = status;
		input.setCustomValidity(
			'invalid' === status || 'duplicate' === status || 'error' === status
				? message
				: ''
		);

		feedback.textContent = message;
		feedback.hidden = ! message;
		feedback.classList.toggle( 'adam-nif-feedback--error', 'invalid' === status || 'error' === status );
		feedback.classList.toggle( 'adam-nif-feedback--warning', 'duplicate' === status );

		form.querySelectorAll( 'button[type="submit"]' ).forEach( function ( button ) {
			button.disabled = blocked;
		} );
	}

	function initializeNifValidation( form ) {
		var input = form.querySelector( '[data-adam-nif-input]' );
		var feedback = form.querySelector( '[data-adam-nif-feedback]' );

		if ( ! input || ! feedback ) {
			return;
		}

		var debounceTimer = 0;
		var activeRequest = null;

		function stopPendingCheck() {
			window.clearTimeout( debounceTimer );

			if ( activeRequest ) {
				activeRequest.abort();
				activeRequest = null;
			}
		}

		function showLocalResult( showMessage ) {
			var value = input.value.trim();

			if ( ! isValidPortugueseNif( value ) ) {
				setNifState(
					form,
					input,
					feedback,
					showMessage ? 'invalid' : 'editing',
					showMessage ? ( config.nifInvalidMessage || '' ) : ''
				);
				return false;
			}

			return true;
		}

		function checkAvailability() {
			stopPendingCheck();

			if ( ! showLocalResult( true ) ) {
				return;
			}

			var value = input.value.trim();
			var controller = new AbortController();
			var body = new URLSearchParams();

			activeRequest = controller;
			body.set( 'action', 'adam_membership_validate_nif' );
			body.set( 'nonce', config.nifValidationNonce || '' );
			body.set( 'nif', value );
			setNifState( form, input, feedback, 'checking', '' );

			window.fetch(
				config.nifValidationUrl || '',
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
					},
					body: body.toString(),
					signal: controller.signal
				}
			).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'NIF validation request failed.' );
				}

				return response.json();
			} ).then( function ( response ) {
				if ( input.value.trim() !== value ) {
					return;
				}

				if ( ! response.success || ! response.data ) {
					throw new Error( 'Invalid NIF validation response.' );
				}

				var status = response.data.status || 'error';
				var message = response.data.message || '';

				if ( 'duplicate' === status && ! message ) {
					message = config.nifDuplicateMessage || '';
				}

				setNifState( form, input, feedback, status, message );
			} ).catch( function ( error ) {
				if ( 'AbortError' === error.name || input.value.trim() !== value ) {
					return;
				}

				setNifState(
					form,
					input,
					feedback,
					'error',
					config.nifCheckErrorMessage || ''
				);
			} ).finally( function () {
				if ( activeRequest === controller ) {
					activeRequest = null;
				}
			} );
		}

		input.addEventListener( 'input', function () {
			stopPendingCheck();
			input.value = input.value.replace( /\D/g, '' ).slice( 0, 9 );

			if ( ! showLocalResult( 9 === input.value.length ) ) {
				return;
			}

			setNifState( form, input, feedback, 'checking', '' );
			debounceTimer = window.setTimeout( checkAvailability, 350 );
		} );

		input.addEventListener( 'blur', checkAvailability );

		form.addEventListener( 'submit', function ( event ) {
			if ( 'available' === input.dataset.adamNifStatus ) {
				return;
			}

			event.preventDefault();

			if ( showLocalResult( true ) ) {
				checkAvailability();
			}

			input.reportValidity();
			input.focus();
		} );

		if ( '' === input.value.trim() ) {
			setNifState( form, input, feedback, 'idle', '' );
		} else if ( isValidPortugueseNif( input.value.trim() ) ) {
			checkAvailability();
		} else {
			showLocalResult( true );
		}
	}

	document.addEventListener( 'change', function ( event ) {
		var target = event.target;

		if ( !( target instanceof HTMLElement ) ) {
			return;
		}

		var form = target.closest( '.adam-membership-native-form' );

		if ( ! form ) {
			return;
		}

		syncFormState(
			form,
			target.matches( 'input[name="membership_mode"]' )
		);
	} );

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if (
			!( target instanceof HTMLElement ) ||
			! target.matches( 'input[name="membership_mode"]' )
		) {
			return;
		}

		var form = target.closest( '.adam-membership-native-form' );

		if ( form ) {
			syncFormState( form, true );
		}
	} );

	document.querySelectorAll( '.adam-membership-native-form' ).forEach( function ( form ) {
		syncFormState( form, false );
		initializeNifValidation( form );
	} );
}() );
