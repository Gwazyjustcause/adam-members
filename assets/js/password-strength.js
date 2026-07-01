document.addEventListener('DOMContentLoaded', function () {
	const password = document.getElementById('new_password')
		|| document.getElementById('password1')
		|| document.getElementById('adam_setup_password');

	if (!password) {
		return;
	}

	const confirmPassword = document.getElementById('confirm_password')
		|| document.getElementById('password2')
		|| document.getElementById('adam_setup_password_confirm');
	const strengthText = document.getElementById('password-strength-text');
	const strengthBar = document.getElementById('adam-strength-bar');
	const form = password.closest('form');
	const submitButton = form ? form.querySelector('button[type="submit"]') : null;

	if (!strengthText || !strengthBar) {
		return;
	}

	const rules = {
		length: document.getElementById('rule-length'),
		lower: document.getElementById('rule-lower'),
		upper: document.getElementById('rule-upper'),
		number: document.getElementById('rule-number'),
		symbol: document.getElementById('rule-symbol')
	};

	let confirmFeedback = document.getElementById('adam-password-confirm-feedback');

	if (!confirmFeedback && confirmPassword) {
		confirmFeedback = document.createElement('p');
		confirmFeedback.id = 'adam-password-confirm-feedback';
		confirmFeedback.className = 'adam-strength-text adam-password-confirm-feedback';
		confirmPassword.insertAdjacentElement('afterend', confirmFeedback);
	}

	function updateRule(element, valid, text) {
		if (!element) {
			return;
		}

		element.textContent = (valid ? '✓ ' : '• ') + text;
		element.classList.toggle('is-valid', valid);
	}

	function updateState() {
		const value = password.value;
		const checks = {
			length: value.length >= 8,
			lower: /[a-z]/.test(value),
			upper: /[A-Z]/.test(value),
			number: /[0-9]/.test(value),
			symbol: /[^A-Za-z0-9]/.test(value)
		};

		updateRule(rules.length, checks.length, 'Pelo menos 8 caracteres');
		updateRule(rules.lower, checks.lower, 'Uma letra minúscula');
		updateRule(rules.upper, checks.upper, 'Uma letra maiúscula');
		updateRule(rules.number, checks.number, 'Um número');
		updateRule(rules.symbol, checks.symbol, 'Um símbolo');

		let score = 0;

		if (
			typeof wp !== 'undefined'
			&& wp.passwordStrength
			&& wp.passwordStrength.meter
		) {
			score = wp.passwordStrength.meter(value, [], value);
		}

		const bars = strengthBar.querySelectorAll('span');

		bars.forEach(function (bar) {
			bar.className = '';
		});

		for (let i = 0; i < Math.max(score, 0); i += 1) {
			if (bars[i]) {
				bars[i].className = 'is-active';
			}
		}

		const labels = [
			'Muito fraca',
			'Fraca',
			'Média',
			'Forte',
			'Muito forte'
		];

		strengthText.textContent = labels[Math.max(score, 0)] || 'Muito fraca';

		const isStrongEnough = score >= 3
			&& checks.length
			&& checks.lower
			&& checks.upper
			&& checks.number
			&& checks.symbol;

		let confirmValid = true;

		if (confirmPassword) {
			confirmValid = confirmPassword.value !== '' && confirmPassword.value === value;

			if (confirmPassword.value === '') {
				confirmPassword.setCustomValidity('');

				if (confirmFeedback) {
					confirmFeedback.textContent = '';
				}
			} else if (!confirmValid) {
				confirmPassword.setCustomValidity('As palavras-passe não coincidem.');

				if (confirmFeedback) {
					confirmFeedback.textContent = 'As palavras-passe não coincidem.';
				}
			} else {
				confirmPassword.setCustomValidity('');

				if (confirmFeedback) {
					confirmFeedback.textContent = 'Palavras-passe coincidem.';
				}
			}

			confirmPassword.setAttribute('aria-invalid', confirmValid ? 'false' : 'true');
		}

		password.setCustomValidity(
			isStrongEnough ? '' : 'A palavra-passe deve cumprir todos os requisitos e ser suficientemente forte.'
		);

		if (submitButton) {
			submitButton.disabled = !isStrongEnough || (confirmPassword && !confirmValid);
			submitButton.setAttribute('aria-disabled', submitButton.disabled ? 'true' : 'false');
		}
	}

	password.addEventListener('input', updateState);

	if (confirmPassword) {
		confirmPassword.addEventListener('input', updateState);
	}

	updateState();
});
