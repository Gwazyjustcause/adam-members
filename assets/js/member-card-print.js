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

	const sanitizeCaptureClone = (captureCard) => {
		captureCard.style.transform = 'none';
		captureCard.style.maxWidth = 'none';
		captureCard.style.margin = '0';

		captureCard.querySelectorAll('.adam-digital-card__backdrop').forEach((element) => {
			element.style.mixBlendMode = 'normal';
			element.style.opacity = '0.16';
		});

		captureCard.querySelectorAll('.adam-digital-card__pattern').forEach((element) => {
			element.style.transform = 'none';
			element.style.backgroundBlendMode = 'normal';
		});

		captureCard.querySelectorAll('.adam-digital-card__art img').forEach((element) => {
			element.style.filter = 'none';
		});

		captureCard.querySelectorAll('.adam-digital-card__details div').forEach((element) => {
			element.style.backdropFilter = 'none';
		});

		captureCard.querySelectorAll('.adam-digital-card__title-mark').forEach((element) => {
			element.style.boxShadow = 'none';
			element.style.background =
				'radial-gradient(circle at 30% 30%, rgba(255,255,255,0.92), rgba(255,255,255,0.3) 34%, rgba(47,75,59,1))';
		});

		captureCard
			.querySelectorAll('.adam-digital-card__frame-layer--outer, .adam-digital-card__frame-layer--inner')
			.forEach((element) => {
				element.style.webkitMask = 'none';
				element.style.mask = 'none';
				element.style.webkitMaskComposite = 'source-over';
				element.style.maskComposite = 'add';
			});
	};

	const buildCaptureRoot = async (card) => {
		const cardRect = card.getBoundingClientRect();
		const captureRoot = document.createElement('div');
		const captureCard = card.cloneNode(true);

		captureRoot.className = 'adam-card-print-capture-root';
		captureRoot.setAttribute('aria-hidden', 'true');
		captureRoot.style.position = 'fixed';
		captureRoot.style.left = '-20000px';
		captureRoot.style.top = '0';
		captureRoot.style.width = cardRect.width + 'px';
		captureRoot.style.height = cardRect.height + 'px';
		captureRoot.style.pointerEvents = 'none';
		captureRoot.style.opacity = '1';
		captureRoot.style.zIndex = '-1';
		captureRoot.style.overflow = 'hidden';

		captureCard.style.width = cardRect.width + 'px';
		captureCard.style.height = cardRect.height + 'px';
		captureCard.style.minHeight = cardRect.height + 'px';

		captureRoot.appendChild(captureCard);
		document.body.appendChild(captureRoot);

		sanitizeCaptureClone(captureCard);
		await waitForCardImages(captureCard);

		return { captureRoot, captureCard, cardRect };
	};

	const loadImageSource = (source, label) =>
		new Promise((resolve, reject) => {
			const image = new Image();

			image.onload = () => resolve(image);
			image.onerror = () =>
				reject(new Error('Generated PNG ' + label + ' could not be loaded'));
			image.src = source;
		});

	const logLibraryDiagnostics = () => {
		console.log('htmlToImage object:', window.htmlToImage);
		console.log('toPng function:', window.htmlToImage?.toPng);
		console.log('toPng.length:', window.htmlToImage?.toPng?.length);
		console.log(
			'toPng source:',
			typeof window.htmlToImage?.toPng === 'function'
				? window.htmlToImage.toPng.toString().slice(0, 300)
				: 'unavailable'
		);
	};

	const runMinimalLibraryTest = async () => {
		const testElement = document.createElement('div');
		testElement.textContent = 'ADAM TEST';
		testElement.style.cssText =
			'position:fixed;left:-20000px;top:0;width:300px;height:150px;background:#14532d;color:#ffffff;display:flex;align-items:center;justify-content:center;font:700 24px Arial,sans-serif;';

		document.body.appendChild(testElement);

		try {
			const testResult = await window.htmlToImage.toPng(testElement, {
				pixelRatio: 2,
				cacheBust: true,
				backgroundColor: '#14532d',
			});

			console.log('html-to-image minimal test result:', {
				type: typeof testResult,
				length: testResult?.length,
				value: testResult,
				prefix:
					typeof testResult === 'string'
						? testResult.slice(0, 50)
						: null,
			});

			return testResult;
		} finally {
			testElement.remove();
		}
	};

	const validateDataUrl = (dataUrl) => {
		const diagnostic = {
			type: typeof dataUrl,
			length: dataUrl?.length,
			prefix: dataUrl?.slice(0, 50),
		};

		console.log('Generated card data URL:', {
			type: diagnostic.type,
			length: diagnostic.length,
			prefix: diagnostic.prefix,
		});

		if (
			typeof dataUrl !== 'string' ||
			!dataUrl.startsWith('data:image/png') ||
			dataUrl.length < 1000
		) {
			throw new Error(
				'html-to-image returned an invalid PNG data URL (' +
					diagnostic.type +
					', ' +
					(diagnostic.length ?? 0) +
					', ' +
					(diagnostic.prefix ?? 'no-prefix') +
					')'
			);
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
		const { card } = getElements();

		if (!card || !card.offsetWidth || !card.offsetHeight) {
			throw new Error('Full card root not available for capture.');
		}

		console.log('html-to-image global check:', {
			exists: !!window.htmlToImage,
			toPngType: typeof window.htmlToImage?.toPng,
		});
		logLibraryDiagnostics();

		if (!window.htmlToImage || typeof window.htmlToImage.toPng !== 'function') {
			throw new Error('html-to-image is not loaded.');
		}

		const minimalResult = await runMinimalLibraryTest();
		validateDataUrl(minimalResult);

		await document.fonts.ready;
		await waitForCardImages(card);
		await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

		console.log('Starting ADAM card PNG capture');
		const { captureRoot, captureCard, cardRect } = await buildCaptureRoot(card);

		try {
			return await window.htmlToImage.toPng(captureCard, {
				pixelRatio: 3,
				cacheBust: true,
				backgroundColor: null,
				width: Math.round(cardRect.width),
				height: Math.round(cardRect.height),
				canvasWidth: Math.round(cardRect.width),
				canvasHeight: Math.round(cardRect.height),
				skipAutoScale: true,
			});
		} finally {
			captureRoot.remove();
		}
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
