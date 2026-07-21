( function () {
	'use strict';

	function initialize() {
		var sendEmail = document.querySelector( '[data-adam-announcement-send-email]' );
		var settings = document.querySelector( '[data-adam-announcement-email-settings]' );

		if ( ! sendEmail || ! settings ) {
			return;
		}

		var team = settings.querySelector( '[data-adam-announcement-email-team]' );
		var members = settings.querySelector( '[data-adam-announcement-email-members]' );

		function selectedAudience() {
			var selected = settings.querySelector( 'input[name="email_audience"]:checked' );
			return selected ? selected.value : '';
		}

		function update() {
			var audience = selectedAudience();
			settings.hidden = ! sendEmail.checked;

			if ( team ) {
				team.hidden = audience !== 'specific_team';
			}

			if ( members ) {
				members.hidden = audience !== 'specific_members';
			}
		}

		sendEmail.addEventListener( 'change', update );
		settings.querySelectorAll( 'input[name="email_audience"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', update );
		} );

		update();
	}

	document.addEventListener( 'DOMContentLoaded', initialize );
}() );
