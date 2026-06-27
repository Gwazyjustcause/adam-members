document.addEventListener('DOMContentLoaded', function () {

	document.querySelectorAll('input[type="password"]').forEach(function (input) {

		const wrapper = document.createElement('div');
		wrapper.className = 'adam-password-wrapper';

		input.parentNode.insertBefore(wrapper, input);

		wrapper.appendChild(input);

		const button = document.createElement('button');

		button.type = 'button';
		button.className = 'adam-password-toggle';
		button.setAttribute('aria-label', 'Mostrar palavra-passe');

		button.innerHTML = '👁';

		wrapper.appendChild(button);

		button.addEventListener('click', function () {

			if (input.type === 'password') {

				input.type = 'text';
				button.innerHTML = '🙈';

			} else {

				input.type = 'password';
				button.innerHTML = '👁';

			}

		});

	});

});