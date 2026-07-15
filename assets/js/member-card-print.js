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
	let activeBlobUrl = null;

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

	const loadImageSource = (source, label) =>
		new Promise((resolve, reject) => {
			const image = new Image();

			image.onload = () => resolve(image);
			image.onerror = () =>
				reject(new Error('Generated PNG ' + label + ' could not be loaded'));
			image.src = source;
		});

	const validateDataUrl = (dataUrl) => {
		console.log('Generated card data URL:', {
			type: typeof dataUrl,
			length: dataUrl?.length,
			prefix: dataUrl?.slice(0, 50),
		});

		if (
			typeof dataUrl !== 'string' ||
			!dataUrl.startsWith('data:image/png') ||
			dataUrl.length < 1000
		) {
			throw new Error('html-to-image returned an invalid PNG data URL');
		}
	};

	const createBlobUrlFromDataUrl = async (dataUrl) => {
		const response = await fetch(dataUrl);
		const blob = await response.blob();
		return URL.createObjectURL(blob);
	};

	const replaceWithPrintableImage = async (sourceUrl, isBlobUrl) => {
		const printRoot = document.createElement('main');
		printRoot.className = 'adam-card-image-print';

		const image = document.createElement('img');
		image.alt = config.imageAlt;
		image.src = sourceUrl;

		printRoot.appendChild(image);
		document.body.replaceChildren(printRoot);

		await loadImageSource(sourceUrl, 'in the print page');

		if (isBlobUrl) {
			activeBlobUrl = sourceUrl;
			window.addEventListener(
				'afterprint',
				() => {
					if (activeBlobUrl) {
						URL.revokeObjectURL(activeBlobUrl);
						activeBlobUrl = null;
					}
				},
				{ once: true }
			);
		}

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

		console.log('html-to-image global check:', {
			exists: !!window.htmlToImage,
			toPngType: typeof window.htmlToImage?.toPng,
		});

		if (!window.htmlToImage || typeof window.htmlToImage.toPng !== 'function') {
			throw new Error('html-to-image is not loaded.');
		}

		await document.fonts.ready;
		await waitForCardImages(card);
		await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

		console.log('Starting ADAM card PNG capture');

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
			validateDataUrl(dataUrl);

			let printableSource = dataUrl;
			let usesBlobUrl = false;

			try {
				await loadImageSource(dataUrl, 'as an in-memory data URL');
				console.log('Generated PNG in-memory image load succeeded');
			} catch (error) {
				console.warn('Data URL image load failed, trying Blob URL fallback:', error);
				console.warn('If the console shows a CSP error for data:, Blob fallback should avoid it.');
				printableSource = await createBlobUrlFromDataUrl(dataUrl);
				usesBlobUrl = true;

				console.log('Generated Blob URL for print image:', {
					prefix: printableSource.slice(0, 50),
				});

				await loadImageSource(printableSource, 'as an in-memory Blob URL');
				console.log('Generated PNG Blob URL in-memory image load succeeded');
			}

			await replaceWithPrintableImage(printableSource, usesBlobUrl);
		} catch (error) {
			console.error('ADAM card print failed:', error);
			console.error(error && error.stack ? error.stack : '');
			showError((error && error.message ? error.message : config.errorMessage));
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
