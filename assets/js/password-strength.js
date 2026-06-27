document.addEventListener('DOMContentLoaded', function () {

	const password = document.getElementById('new_password');

	if (!password) {
		return;
	}

	const strengthText = document.getElementById('password-strength-text');
	const strengthBar = document.getElementById('adam-strength-bar');

	const rules = {
		length: document.getElementById('rule-length'),
		lower: document.getElementById('rule-lower'),
		upper: document.getElementById('rule-upper'),
		number: document.getElementById('rule-number'),
		symbol: document.getElementById('rule-symbol')
	};

	function updateRule(element, valid, text) {

		if (!element) {
			return;
		}

		element.textContent = (valid ? '✓ ' : '✗ ') + text;
		element.style.color = valid ? '#2e7d32' : '#c62828';
	}

	password.addEventListener('input', function () {

		const value = password.value;

		updateRule(
			rules.length,
			value.length >= 8,
			'Pelo menos 8 caracteres'
		);

		updateRule(
			rules.lower,
			/[a-z]/.test(value),
			'Uma letra minúscula'
		);

		updateRule(
			rules.upper,
			/[A-Z]/.test(value),
			'Uma letra maiúscula'
		);

		updateRule(
			rules.number,
			/[0-9]/.test(value),
			'Um número'
		);

		updateRule(
			rules.symbol,
			/[^A-Za-z0-9]/.test(value),
			'Um símbolo'
		);

		let score = 0;

		if (
			typeof wp !== 'undefined' &&
			wp.passwordStrength &&
			wp.passwordStrength.meter
		) {

			score = wp.passwordStrength.meter(
				value,
				[],
				value
			);
		}

		const bars = strengthBar.querySelectorAll('span');

		bars.forEach(function (bar) {
			bar.style.background = '#d6d6d6';
		});

		for (let i = 0; i < Math.max(score, 0); i++) {
			if (bars[i]) {
				bars[i].style.background = '#2e7d32';
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

	});

});