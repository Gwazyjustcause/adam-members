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

	function syncFormState( form ) {
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
		updateFee( form );
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

		syncFormState( form );
	} );

	document.querySelectorAll( '.adam-membership-native-form' ).forEach( function ( form ) {
		syncFormState( form );
	} );
}() );
