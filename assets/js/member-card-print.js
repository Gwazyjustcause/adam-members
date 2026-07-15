(function () {
	if (!window.adamCardPrintConfig) {
		return;
	}

	const config = window.adamCardPrintConfig;
	const selectors = {
		card: '.adam-card-print-page .adam-digital-card',
		error: '[data-adam-print-error]',
		trigger: '[data-adam-print-trigger]',
		retry: '[data-adam-print-retry]',
	};

	let inProgress = false;

	const getElements = () => ({
		card: document.querySelector(selectors.card),
		error: document.querySelector(selectors.error),
		trigger: document.querySelector(selectors.trigger),
		retry: document.querySelector(selectors.retry),
	});

	const setBusyState = (busy) => {
		const { trigger, retry } = getElements();

		inProgress = busy;

		if (trigger) {
			trigger.disabled = busy;
			trigger.setAttribute('aria-busy', busy ? 'true' : 'false');
		}

		if (retry) {
			retry.disabled = busy;
			retry.setAttribute('aria-busy', busy ? 'true' : 'false');
		}
	};

	const showError = (message) => {
		const { error } = getElements();

		if (!error) {
			return;
		}

		error.hidden = false;
		error.textContent = message;
	};

	const clearError = () => {
		const { error } = getElements();

		if (!error) {
			return;
		}

		error.hidden = true;
		error.textContent = '';
	};

	const waitForCardImages = async (card) => {
		const images = Array.from(card.querySelectorAll('img'));

		await Promise.all(
			images.map(
				(image) =>
					new Promise((resolve) => {
						if (image.complete) {
							resolve();
							return;
						}

						const finish = () => resolve();
						image.addEventListener('load', finish, { once: true });
						image.addEventListener('error', finish, { once: true });
					})
			)
		);
	};

	const replaceWithPrintableImage = async (dataUrl) => {
		document.body.innerHTML =
			'<main class="adam-card-image-print"><img alt="' +
			config.imageAlt +
			'" /></main>';

		const image = document.querySelector('.adam-card-image-print img');

		if (!image) {
			throw new Error('Printable image element was not created.');
		}

		await new Promise((resolve, reject) => {
			image.onload = () => resolve();
			image.onerror = () => reject(new Error('Generated PNG could not be loaded into the print view.'));
			image.src = dataUrl;
		});

		window.print();
	};

	const captureAndPrint = async () => {
		if (inProgress) {
			return;
		}

		const { card } = getElements();

		if (!card || !card.offsetWidth || !card.offsetHeight) {
			throw new Error('Full card root not available for capture.');
		}

		if (!window.htmlToImage || typeof window.htmlToImage.toPng !== 'function') {
			throw new Error('html-to-image is not loaded.');
		}

		await document.fonts.ready;
		await waitForCardImages(card);
		await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

		return window.htmlToImage.toPng(card, {
			pixelRatio: 3,
			cacheBust: true,
			backgroundColor: null,
		});
	};

	const run = async () => {
		setBusyState(true);
		clearError();

		try {
			const dataUrl = await captureAndPrint();
			await replaceWithPrintableImage(dataUrl);
		} catch (error) {
			console.error('ADAM card print failed:', error);
			console.error(error && error.stack ? error.stack : '');
			showError(config.errorMessage);
		} finally {
			setBusyState(false);
		}
	};

	window.addEventListener(
		'load',
		() => {
			const { trigger, retry } = getElements();

			if (trigger) {
				trigger.addEventListener('click', () => {
					void run();
				});
			}

			if (retry) {
				retry.addEventListener('click', () => {
					void run();
				});
			}

			void run();
		},
		{ once: true }
	);
})();
