document.addEventListener('DOMContentLoaded', function () {

	document.querySelectorAll('input[type="password"]').forEach(function (input) {

		const wrapper = document.createElement('div');
		wrapper.className = 'adam-password-wrapper';

		input.parentNode.insertBefore(wrapper, input);

		wrapper.appendChild(input);

		const button = document.createElement('button');

		button.type = 'button';
		button.className = 'adam-password-toggle';
		button.setAttribute(
			'aria-label',
			'Mostrar palavra-passe'
		);

		button.innerHTML =
			'<span class="dashicons dashicons-visibility"></span>';

		wrapper.appendChild(button);

		button.addEventListener('click', function () {

			if ( input.type === 'password' ) {

				input.type = 'text';

				button.innerHTML =
					'<span class="dashicons dashicons-hidden"></span>';

				button.setAttribute(
					'aria-label',
					'Esconder palavra-passe'
				);

			} else {

				input.type = 'password';

				button.innerHTML =
					'<span class="dashicons dashicons-visibility"></span>';

				button.setAttribute(
					'aria-label',
					'Mostrar palavra-passe'
				);

			}

		});

	});

});