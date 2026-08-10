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
		node.querySelectorAll( 'input, select, textarea, button' ).forEach( function ( control ) {
			control.disabled = ! visible;
		} );
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
		// A remote availability check is only an advisory duplicate lookup. It
		// must never leave the submit control disabled while the request is
		// pending or when the endpoint is unavailable; the server performs the
		// authoritative local checksum/duplicate validation.
		var blocked = [ 'invalid', 'duplicate', 'error' ].includes( status );

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
		var submitAfterCheck = false;

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
				if ( ( 'available' === status || 'local_valid' === status ) && submitAfterCheck ) {
					submitAfterCheck = false;
					if ( form.requestSubmit ) { form.requestSubmit(); } else { form.submit(); }
				}
			} ).catch( function ( error ) {
				if ( 'AbortError' === error.name || input.value.trim() !== value ) {
					return;
				}

				// The checksum is authoritative and is checked again on the server.
				// Availability AJAX is only an early duplicate hint; an unavailable
				// endpoint must never block a structurally valid registration.
				setNifState( form, input, feedback, 'local_valid', '' );
				if ( submitAfterCheck ) {
					submitAfterCheck = false;
					if ( form.requestSubmit ) { form.requestSubmit(); } else { form.submit(); }
				}
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
			if ( 'available' === input.dataset.adamNifStatus || 'local_valid' === input.dataset.adamNifStatus ) {
				return;
			}

			event.preventDefault();

			if ( showLocalResult( true ) ) {
				submitAfterCheck = true;
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

	function normalizeSearchValue( value ) {
		return value
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.toLocaleLowerCase( 'pt-PT' )
			.trim();
	}

	function initializeSearchableSelect( container ) {
		var search = container.querySelector( '[data-adam-select-search]' );
		var select = container.querySelector( '[data-adam-select-options]' );
		var empty = container.querySelector( '[data-adam-select-empty]' );
		var trigger = container.querySelector( '[data-adam-select-trigger]' );
		var selectedValue = container.querySelector( '[data-adam-select-value]' );
		var panel = container.querySelector( '[data-adam-select-panel]' );
		var results = container.querySelector( '[data-adam-select-results]' );

		if ( ! search || ! select || ! trigger || ! selectedValue || ! panel || ! results ) {
			return;
		}

		var options = Array.from( select.options ).filter( function ( option ) {
			return '' !== option.value;
		} );

		function updateSelectedValue() {
			var option = select.options[ select.selectedIndex ];

			selectedValue.textContent = option && option.value ? option.textContent : select.options[0].textContent;
		}

		function renderOptions() {
			var query = normalizeSearchValue( search.value );
			var matches = 0;

			results.replaceChildren();

			options.forEach( function ( option ) {
				if ( '' !== query && ! normalizeSearchValue( option.textContent || '' ).includes( query ) ) {
					return;
				}

				var item = document.createElement( 'button' );

				item.type = 'button';
				item.className = 'adam-searchable-select__option';
				item.textContent = option.textContent || '';
				item.setAttribute( 'role', 'option' );
				item.setAttribute( 'aria-selected', option.value === select.value ? 'true' : 'false' );
				item.addEventListener( 'click', function () {
					select.value = option.value;
					select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					closePanel();
					trigger.focus();
				} );
				results.appendChild( item );
				matches += 1;
			} );

			if ( empty ) {
				empty.hidden = matches > 0;
			}
		}

		function openPanel() {
			panel.hidden = false;
			trigger.setAttribute( 'aria-expanded', 'true' );
			renderOptions();
			search.focus();
		}

		function closePanel() {
			panel.hidden = true;
			trigger.setAttribute( 'aria-expanded', 'false' );
			search.value = '';
		}

		container.classList.add( 'adam-searchable-select--enhanced' );
		trigger.hidden = false;
		updateSelectedValue();

		trigger.addEventListener( 'click', function () {
			if ( panel.hidden ) {
				openPanel();
			} else {
				closePanel();
			}
		} );
		search.addEventListener( 'input', renderOptions );
		select.addEventListener( 'change', updateSelectedValue );
		container.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! panel.hidden ) {
				closePanel();
				trigger.focus();
			}
		} );
		document.addEventListener( 'click', function ( event ) {
			if ( ! panel.hidden && event.target instanceof Node && ! container.contains( event.target ) ) {
				closePanel();
			}
		} );
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

	document.querySelectorAll( '[data-adam-searchable-select]' ).forEach( initializeSearchableSelect );

	// Safari/mobile browsers may restore a submitted form from the back-forward
	// cache with its submit control still disabled. A fresh page state must
	// always allow the member to correct errors or retry the submission.
	window.addEventListener( 'pageshow', function () {
		document.querySelectorAll( '.adam-membership-native-form button[type="submit"]' ).forEach( function ( button ) {
			button.disabled = false;
		} );
	} );
}() );
