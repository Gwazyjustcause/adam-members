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
	const isAccountSetup = !!(form && form.hasAttribute('data-adam-account-setup'));
	const accountUsername = isAccountSetup ? form.querySelector('#adam_setup_username') : null;
	const accountFeedback = isAccountSetup ? form.querySelector('#adam-account-setup-feedback') : null;
	const initiallyDisabled = !!(submitButton && submitButton.disabled);

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

		const meetsPasswordRules = checks.length
			&& checks.lower
			&& checks.upper
			&& checks.number
			&& checks.symbol;
		// The visible five rules are the shared password contract. The numeric
		// zxcvbn score is advisory only and is not a second hidden requirement.
		const isStrongEnough = meetsPasswordRules;

		let confirmValid = true;

		if (confirmPassword) {
			confirmValid = confirmPassword.value !== '' && confirmPassword.value === value;

			if (confirmPassword.value === '') {
				confirmPassword.setCustomValidity('');

				if (confirmFeedback) {
					confirmFeedback.textContent = 'Confirme a palavra-passe.';
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
			if (isAccountSetup) {
				const blockers = [];
				const username = accountUsername ? accountUsername.value.trim() : '';

				if ('' === username) {
					blockers.push('Introduza um nome de utilizador válido.');
				} else if (username.length < 4) {
					blockers.push('O nome de utilizador deve ter pelo menos 4 caracteres.');
				}

				if (!meetsPasswordRules) {
					blockers.push('A palavra-passe deve cumprir todos os requisitos indicados.');
				}

				if (!confirmPassword || '' === confirmPassword.value) {
					blockers.push('Confirme a palavra-passe.');
				} else if (!confirmValid) {
					blockers.push('As palavras-passe não coincidem.');
				}

				submitButton.disabled = initiallyDisabled || blockers.length > 0;

				if (accountFeedback) {
					accountFeedback.textContent = blockers.length
						? 'Para concluir, corrija: ' + blockers.join(' ')
						: 'Todos os dados visíveis estão válidos. Pode concluir o acesso.';
					accountFeedback.classList.toggle('is-valid', blockers.length === 0);
					accountFeedback.classList.toggle('has-errors', blockers.length > 0);
				}
			} else {
				submitButton.disabled = !isStrongEnough || (confirmPassword && !confirmValid);
			}
			submitButton.setAttribute('aria-disabled', submitButton.disabled ? 'true' : 'false');
		}
	}

	password.addEventListener('input', updateState);

	if (accountUsername) {
		accountUsername.addEventListener('input', updateState);
		accountUsername.addEventListener('change', updateState);
	}

	if (confirmPassword) {
		confirmPassword.addEventListener('input', updateState);
	}

	updateState();
});
