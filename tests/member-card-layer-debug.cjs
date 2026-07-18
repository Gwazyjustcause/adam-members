const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

const AUTH_FILE = path.join(__dirname, '..', 'playwright', '.auth', 'adam-user.json');
const OUTPUT_DIR = path.join(__dirname, '..', 'playwright', 'artifacts', 'member-card-layer-debug');
const FRONT_URL = 'https://airsoftmondego.pt/socio/';
const REWARDS_URL = 'https://airsoftmondego.pt/wp-admin/admin.php?page=adam-membership-rewards';
const DEFAULT_CASE_KEYS = ['dots-45'];
const CASE_TIMEOUT_MS = 180000;
const STEP_TIMEOUT_MS = {
	adminSave: 45000,
	pageReload: 30000,
	previewCapture: 30000,
	savedPreviewCapture: 30000,
	memberPageLoad: 45000,
	liveCardCapture: 30000,
	downloadEvent: 45000,
	pngFileCreation: 30000,
	pngCompare: 20000,
};

const PATTERN_CASES = [
	{
		key: 'dots-0',
		pattern: 'dots',
		color: '#2ecc71',
		scale: 32,
		density: 4,
		rotation: 0,
		spacing: 34,
		opacity: 58,
	},
	{
		key: 'dots-45',
		pattern: 'dots',
		color: '#2ecc71',
		scale: 32,
		density: 4,
		rotation: 45,
		spacing: 34,
		opacity: 58,
	},
	{
		key: 'diagonal-tight',
		pattern: 'diagonal',
		color: '#c7f9d4',
		scale: 18,
		density: 5,
		rotation: 32,
		spacing: 18,
		opacity: 42,
	},
	{
		key: 'diagonal-wide',
		pattern: 'diagonal',
		color: '#f6d365',
		scale: 36,
		density: 2,
		rotation: 120,
		spacing: 44,
		opacity: 24,
	},
];

function ensureDir(dir) {
	fs.mkdirSync(dir, { recursive: true });
}

function fileExists(targetPath) {
	try {
		return fs.existsSync(targetPath);
	} catch (error) {
		return false;
	}
}

function createCaseDiagnostics(patternCase) {
	return {
		case: patternCase.key,
		startedAt: new Date().toISOString(),
		lastCompletedStep: null,
		failedStep: null,
		consoleErrors: [],
		steps: [],
		artifacts: {
			preview: null,
			savedCard: null,
			liveCard: null,
			png: null,
		},
		status: 'running',
	};
}

function markStep(diagnostics, step, extra = {}) {
	diagnostics.lastCompletedStep = step;
	diagnostics.steps.push({
		step,
		completedAt: new Date().toISOString(),
		...extra,
	});
	console.log('pattern step complete:', diagnostics.case, step, extra);
}

function saveCaseDiagnostics(diagnostics) {
	const outputPath = path.join(OUTPUT_DIR, diagnostics.case + '-diagnostics.json');
	fs.writeFileSync(outputPath, JSON.stringify(diagnostics, null, 2));
	return outputPath;
}

function toSerializableError(error) {
	if (!error) {
		return null;
	}

	return {
		name: error.name || 'Error',
		message: error.message || String(error),
		stack: error.stack || '',
	};
}

async function withStepTimeout(step, timeoutMs, diagnostics, task) {
	console.log('pattern step start:', diagnostics.case, step, { timeoutMs });

	let timeoutId = null;

	try {
		return await Promise.race([
			task(),
			new Promise((_, reject) => {
				timeoutId = setTimeout(() => {
					reject(new Error(step + ' timed out after ' + timeoutMs + 'ms'));
				}, timeoutMs);
			}),
		]);
	} catch (error) {
		diagnostics.failedStep = step;
		throw error;
	} finally {
		if (timeoutId) {
			clearTimeout(timeoutId);
		}
	}
}

async function acceptCookies(page) {
	const acceptButton = page.getByRole('button', { name: 'Aceitar tudo' });

	if (await acceptButton.count()) {
		await acceptButton.first().click().catch(() => {});
	}
}

async function disablePageMotion(page) {
	await page.addStyleTag({
		content: `
			*,
			*::before,
			*::after {
				animation: none !important;
				transition: none !important;
				scroll-behavior: auto !important;
				view-transition-name: none !important;
			}
			::view-transition,
			::view-transition-group(*),
			::view-transition-old(*),
			::view-transition-new(*) {
				animation: none !important;
			}
		`,
	});
}

async function waitForCardAssets(page, selector = '.adam-digital-card') {
	await page.waitForSelector(selector, { state: 'visible', timeout: 30000 });
	await page.evaluate(async (targetSelector) => {
		const root = document.querySelector(targetSelector);

		if (!root) {
			throw new Error('Card root not found for selector ' + targetSelector);
		}

		if (document.fonts && document.fonts.ready) {
			await document.fonts.ready;
		}

		const images = Array.from(root.querySelectorAll('img'));
		await Promise.all(images.map((img) => {
			return new Promise((resolve) => {
				if (img.complete) {
					resolve();
					return;
				}

				img.addEventListener('load', () => resolve(), { once: true });
				img.addEventListener('error', () => resolve(), { once: true });
			});
		}));
	}, selector);
}

async function compareImages(page, screenshotBuffer, downloadBuffer) {
	const screenshotDataUrl = 'data:image/png;base64,' + screenshotBuffer.toString('base64');
	const downloadDataUrl = 'data:image/png;base64,' + downloadBuffer.toString('base64');

	return page.evaluate(async ({ screenshotDataUrl, downloadDataUrl }) => {
		function loadImage(src) {
			return new Promise((resolve, reject) => {
				const image = new Image();
				image.onload = () => resolve(image);
				image.onerror = () => reject(new Error('Failed to load image for comparison'));
				image.src = src;
			});
		}

		const [first, second] = await Promise.all([
			loadImage(screenshotDataUrl),
			loadImage(downloadDataUrl),
		]);

		const width = Math.min(first.naturalWidth, second.naturalWidth, 420);
		const height = Math.min(
			Math.round((width / first.naturalWidth) * first.naturalHeight),
			Math.round((width / second.naturalWidth) * second.naturalHeight)
		);

		const firstCanvas = document.createElement('canvas');
		firstCanvas.width = width;
		firstCanvas.height = height;
		firstCanvas.getContext('2d').drawImage(first, 0, 0, width, height);

		const secondCanvas = document.createElement('canvas');
		secondCanvas.width = width;
		secondCanvas.height = height;
		secondCanvas.getContext('2d').drawImage(second, 0, 0, width, height);

		const firstPixels = firstCanvas.getContext('2d').getImageData(0, 0, width, height).data;
		const secondPixels = secondCanvas.getContext('2d').getImageData(0, 0, width, height).data;

		let totalDiff = 0;
		let maxDiff = 0;

		for (let index = 0; index < firstPixels.length; index += 4) {
			const pixelDiff =
				Math.abs(firstPixels[index] - secondPixels[index]) +
				Math.abs(firstPixels[index + 1] - secondPixels[index + 1]) +
				Math.abs(firstPixels[index + 2] - secondPixels[index + 2]) +
				Math.abs(firstPixels[index + 3] - secondPixels[index + 3]);

			totalDiff += pixelDiff;
			maxDiff = Math.max(maxDiff, pixelDiff);
		}

		return {
			first: { width: first.naturalWidth, height: first.naturalHeight },
			second: { width: second.naturalWidth, height: second.naturalHeight },
			diff: {
				sampleWidth: width,
				sampleHeight: height,
				averagePerPixel: totalDiff / (width * height),
				maxPerPixel: maxDiff,
			},
		};
	}, { screenshotDataUrl, downloadDataUrl });
}

async function screenshotLocator(locator, filePath) {
	console.log('screenshot step: scroll');
	await locator.evaluate((element) => {
		element.scrollIntoView({ block: 'center', inline: 'center' });
	});

	console.log('screenshot step: bounds');
	const box = await locator.boundingBox();
	const page = locator.page();

	if (!box) {
		throw new Error('Could not determine screenshot bounds for locator');
	}

	if (filePath.includes('-member-live.png')) {
		console.log('screenshot step: html2canvas live');
		const dataUrl = await page.evaluate(async () => {
			const card = document.querySelector('.adam-digital-card');

			if (!card) {
				throw new Error('Live member card not found');
			}

			if (typeof window.html2canvas !== 'function') {
				throw new Error('html2canvas is not available on the live member page');
			}

			const canvas = await window.html2canvas(card, {
				scale: 2,
				useCORS: true,
				allowTaint: false,
				backgroundColor: null,
				logging: false,
				imageTimeout: 15000,
			});

			return canvas.toDataURL('image/png');
		});
		const buffer = Buffer.from(dataUrl.replace(/^data:image\/png;base64,/, ''), 'base64');
		fs.writeFileSync(filePath, buffer);
		console.log('screenshot step: html2canvas written');
		return buffer;
	}

	console.log('screenshot step: cdp');
	const client = await page.context().newCDPSession(page);
	console.log('screenshot step: capture', {
		x: Math.round(box.x),
		y: Math.round(box.y),
		width: Math.round(box.width),
		height: Math.round(box.height),
		path: filePath,
	});
	const screenshot = await client.send('Page.captureScreenshot', {
		format: 'png',
		fromSurface: true,
		clip: {
			x: Math.round(box.x),
			y: Math.round(box.y),
			width: Math.round(box.width),
			height: Math.round(box.height),
			scale: 1,
		},
	});
	console.log('screenshot step: captured');

	const buffer = Buffer.from(screenshot.data, 'base64');
	fs.writeFileSync(filePath, buffer);
	console.log('screenshot step: written');

	return buffer;
}

async function waitForStableBoundingBox(page, selector, checks = 2, delayMs = 250) {
	return page.evaluate(async ({ targetSelector, requiredChecks, waitMs }) => {
		function snapshot(element) {
			const rect = element.getBoundingClientRect();
			const style = getComputedStyle(element);

			return {
				width: rect.width,
				height: rect.height,
				top: rect.top,
				left: rect.left,
				display: style.display,
				visibility: style.visibility,
				opacity: style.opacity,
			};
		}

		function sameBox(first, second) {
			if (!first || !second) {
				return false;
			}

			return (
				Math.abs(first.width - second.width) < 0.5 &&
				Math.abs(first.height - second.height) < 0.5 &&
				Math.abs(first.top - second.top) < 0.5 &&
				Math.abs(first.left - second.left) < 0.5
			);
		}

		const samples = [];
		let consecutiveStable = 0;
		let previous = null;
		const startedAt = Date.now();

		while (Date.now() - startedAt < 10000) {
			const element = document.querySelector(targetSelector);

			if (!element) {
				samples.push({ missing: true, at: Date.now() - startedAt });
			} else {
				const current = snapshot(element);
				samples.push({ ...current, at: Date.now() - startedAt });

				if (
					current.width > 0 &&
					current.height > 0 &&
					current.display !== 'none' &&
					current.visibility !== 'hidden' &&
					current.opacity !== '0'
				) {
					if (sameBox(previous, current)) {
						consecutiveStable += 1;
					} else {
						consecutiveStable = 1;
					}
				} else {
					consecutiveStable = 0;
				}

				previous = current;

				if (consecutiveStable >= requiredChecks) {
					return {
						stable: true,
						final: current,
						samples,
					};
				}
			}

			await new Promise((resolve) => setTimeout(resolve, waitMs));
		}

		return {
			stable: false,
			final: previous,
			samples,
		};
	}, { targetSelector: selector, requiredChecks: checks, waitMs: delayMs });
}

async function setRangeValue(page, selector, value) {
	await page.locator(selector).evaluate((element, nextValue) => {
		element.value = String(nextValue);
		element.dispatchEvent(new Event('input', { bubbles: true }));
		element.dispatchEvent(new Event('change', { bubbles: true }));
	}, value);
}

async function setFieldValue(page, selector, value) {
	await page.locator(selector).evaluate((element, nextValue) => {
		element.value = String(nextValue);
		element.dispatchEvent(new Event('input', { bubbles: true }));
		element.dispatchEvent(new Event('change', { bubbles: true }));
	}, value);
}

async function setRewardPatternForm(page, patternCase) {
	await setFieldValue(page, 'select[name="visual_style[card_subtype]"]', 'background').catch(() => {});
	await setFieldValue(page, 'select[name="visual_style[pattern]"]', patternCase.pattern);
	await setFieldValue(page, 'input[name="visual_style[pattern_color]"]', patternCase.color);
	await setRangeValue(page, 'input[name="visual_style[pattern_scale]"]', patternCase.scale);
	await setRangeValue(page, 'input[name="visual_style[pattern_density]"]', patternCase.density);
	await setRangeValue(page, 'input[name="visual_style[pattern_rotation]"]', patternCase.rotation);
	await setRangeValue(page, 'input[name="visual_style[pattern_spacing]"]', patternCase.spacing);
	await setRangeValue(page, 'input[name="visual_style[pattern_opacity]"]', patternCase.opacity);
}

async function readPatternForm(page) {
	return page.evaluate(() => {
		function valueOf(selector) {
			const field = document.querySelector(selector);
			return field ? field.value : '';
		}

		return {
			pattern: valueOf('select[name="visual_style[pattern]"]'),
			pattern_color: valueOf('input[name="visual_style[pattern_color]"]'),
			pattern_scale: Number(valueOf('input[name="visual_style[pattern_scale]"]')),
			pattern_density: Number(valueOf('input[name="visual_style[pattern_density]"]')),
			pattern_rotation: Number(valueOf('input[name="visual_style[pattern_rotation]"]')),
			pattern_spacing: Number(valueOf('input[name="visual_style[pattern_spacing]"]')),
			pattern_opacity: Number(valueOf('input[name="visual_style[pattern_opacity]"]')),
		};
	});
}

async function readPatternComputed(page, selector = '.adam-digital-card') {
	return page.evaluate((targetSelector) => {
		const card = document.querySelector(targetSelector);
		const pattern = card ? card.querySelector('.adam-digital-card__pattern') : null;
		const style = card ? getComputedStyle(card) : null;
		const patternStyle = pattern ? getComputedStyle(pattern) : null;

		if (!card || !pattern || !style || !patternStyle) {
			throw new Error('Pattern layer not found for selector ' + targetSelector);
		}

		return {
			present: Boolean(card),
			cardClass: card.className,
			patternClass: pattern.className,
			cardBox: {
				width: card.getBoundingClientRect().width,
				height: card.getBoundingClientRect().height,
			},
			vars: {
				color: style.getPropertyValue('--adam-card-pattern-color').trim(),
				strong: style.getPropertyValue('--adam-card-pattern-color-strong').trim(),
				soft: style.getPropertyValue('--adam-card-pattern-color-soft').trim(),
				opacity: style.getPropertyValue('--adam-card-pattern-opacity').trim(),
				size: style.getPropertyValue('--adam-card-pattern-size').trim(),
				spacing: style.getPropertyValue('--adam-card-pattern-spacing').trim(),
				density: style.getPropertyValue('--adam-card-pattern-density').trim(),
				rotation: style.getPropertyValue('--adam-card-pattern-rotation').trim(),
			},
			pattern: {
				present: Boolean(pattern),
				backgroundImage: patternStyle.backgroundImage,
				opacity: patternStyle.opacity,
				transform: patternStyle.transform,
				width: patternStyle.width,
				height: patternStyle.height,
				inset: patternStyle.inset,
				overflow: patternStyle.overflow,
				display: patternStyle.display,
				visibility: patternStyle.visibility,
			},
		};
	}, selector);
}

async function saveReward(page) {
	await Promise.all([
		page.waitForURL((url) => url.toString().includes('adam_message='), { timeout: 30000 }),
		page.locator('form.adam-admin-edit-form').evaluate((form) => {
			form.submit();
		}),
	]);
}

async function saveMemberTheme(page, themeKey) {
	await page.selectOption('select[name="active_card_theme"]', themeKey);
	await Promise.all([
		page.waitForLoadState('networkidle'),
		page.locator('.adam-card-customizer').getByRole('button', { name: 'Guardar visual' }).click(),
	]);
}

async function getActiveTheme(page) {
	const selectedTheme = await page.locator('select[name="active_card_theme"]').inputValue();

	if (selectedTheme) {
		return selectedTheme;
	}

	const firstAvailable = await page.locator('select[name="active_card_theme"] option').evaluateAll((options) => {
		const match = options.find((option) => option.value);
		return match ? match.value : '';
	});

	if (!firstAvailable) {
		throw new Error('No card theme is available for the authenticated member');
	}

	await saveMemberTheme(page, firstAvailable);
	return firstAvailable;
}

async function findRewardEditUrlByValue(page, rewardValue) {
	await page.goto(REWARDS_URL, { waitUntil: 'networkidle' });
	const editLinks = await page.locator('a[href*="page=adam-membership-reward-edit"]').evaluateAll((links) => {
		return links.map((link) => link.href);
	});

	for (const href of editLinks) {
		await page.goto(href, { waitUntil: 'networkidle' });

		const value = await page.locator('[name="reward_value"]').inputValue().catch(() => '');
		if (value === rewardValue) {
			return href;
		}
	}

	throw new Error('Could not find reward edit page for value ' + rewardValue);
}

function attachPageConsoleListeners(page, errorBucket) {
	page.on('pageerror', (error) => {
		const serialised = toSerializableError(error);
		errorBucket.push({ type: 'pageerror', ...serialised, at: new Date().toISOString() });
		console.error('pageerror:', error);
	});
	page.on('console', (message) => {
		if (message.type() === 'error') {
			const entry = {
				type: 'console',
				text: message.text(),
				location: message.location(),
				at: new Date().toISOString(),
			};
			errorBucket.push(entry);
			console.error('console error:', entry);
		}
	});
}

async function run() {
	ensureDir(OUTPUT_DIR);

	if (!fs.existsSync(AUTH_FILE)) {
		throw new Error('Missing Playwright auth state: ' + AUTH_FILE);
	}

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({
		storageState: AUTH_FILE,
		acceptDownloads: true,
		viewport: { width: 1600, height: 1500 },
		deviceScaleFactor: 1,
	});

	const page = await context.newPage();
	const pageConsoleErrors = [];
	attachPageConsoleListeners(page, pageConsoleErrors);
	const results = [];
	let originalThemeKey = '';
	let activeThemeKey = '';
	let rewardEditUrl = '';
	let originalPatternState = null;

	try {
		await page.goto(FRONT_URL, { waitUntil: 'domcontentloaded' });
		await acceptCookies(page);
		await disablePageMotion(page);
		await waitForCardAssets(page);
		originalThemeKey = await page.locator('select[name="active_card_theme"]').inputValue();
		activeThemeKey = await getActiveTheme(page);
		rewardEditUrl = await findRewardEditUrlByValue(page, activeThemeKey);

		await page.goto(rewardEditUrl, { waitUntil: 'networkidle' });
		originalPatternState = await readPatternForm(page);

		const casesToRun = PATTERN_CASES.filter((patternCase) => DEFAULT_CASE_KEYS.includes(patternCase.key));

		for (const patternCase of casesToRun) {
			const diagnostics = createCaseDiagnostics(patternCase);
			pageConsoleErrors.length = 0;
			let caseResult = null;
			console.log('pattern case start:', patternCase.key);

			try {
				await withStepTimeout('case', CASE_TIMEOUT_MS, diagnostics, async () => {
					await page.goto(rewardEditUrl, { waitUntil: 'networkidle' });
					markStep(diagnostics, 'admin page opened');

					await setRewardPatternForm(page, patternCase);
					markStep(diagnostics, 'pattern form populated');

					const previewCard = page.locator('[data-adam-card-preview-panel] .adam-digital-card').first();
					await waitForCardAssets(page, '[data-adam-card-preview-panel] .adam-digital-card');
					markStep(diagnostics, 'preview assets ready');

					const previewBeforePath = path.join(OUTPUT_DIR, patternCase.key + '-preview-before-save.png');
					await withStepTimeout('preview capture', STEP_TIMEOUT_MS.previewCapture, diagnostics, async () => {
						await screenshotLocator(previewCard, previewBeforePath);
					});
					diagnostics.artifacts.preview = {
						path: previewBeforePath,
						exists: fileExists(previewBeforePath),
					};
					const previewComputedBefore = await readPatternComputed(page, '[data-adam-card-preview-panel] .adam-digital-card');
					markStep(diagnostics, 'preview captured');

					await withStepTimeout('admin save', STEP_TIMEOUT_MS.adminSave, diagnostics, async () => {
						await saveReward(page);
					});
					markStep(diagnostics, 'admin save');

					let verifyPage = page;
					let verifyPageCreated = false;
					await withStepTimeout('page reload', STEP_TIMEOUT_MS.pageReload, diagnostics, async () => {
						const savedRewardUrl = page.url();
						verifyPage = await context.newPage();
						verifyPageCreated = true;
						attachPageConsoleListeners(verifyPage, pageConsoleErrors);
						await verifyPage.goto(savedRewardUrl, { waitUntil: 'networkidle' });
						await disablePageMotion(verifyPage);
					});
					markStep(diagnostics, 'page reload');

					const previewStabilityAfter = await waitForStableBoundingBox(verifyPage, '[data-adam-card-preview-panel] .adam-digital-card');
					console.log('preview stability after reload:', JSON.stringify(previewStabilityAfter, null, 2));
					const previewComputedAfter = await readPatternComputed(verifyPage, '[data-adam-card-preview-panel] .adam-digital-card');
					console.log('preview computed after reload:', JSON.stringify(previewComputedAfter, null, 2));
					const savedPatternState = await readPatternForm(verifyPage);
					const previewAfterPath = path.join(OUTPUT_DIR, patternCase.key + '-preview-after-save.png');
					await withStepTimeout('saved preview capture', STEP_TIMEOUT_MS.savedPreviewCapture, diagnostics, async () => {
						await screenshotLocator(verifyPage.locator('[data-adam-card-preview-panel] .adam-digital-card').first(), previewAfterPath);
					});
					diagnostics.artifacts.savedCard = {
						path: previewAfterPath,
						exists: fileExists(previewAfterPath),
					};
					markStep(diagnostics, 'saved preview captured');
					if (verifyPageCreated) {
						await verifyPage.close();
					}

					await withStepTimeout('member-page load', STEP_TIMEOUT_MS.memberPageLoad, diagnostics, async () => {
						await page.goto(FRONT_URL, { waitUntil: 'domcontentloaded' });
						await acceptCookies(page);
						await waitForCardAssets(page);
					});
					markStep(diagnostics, 'member-page load');

					const memberCard = page.locator('.adam-digital-card').first();
					const livePath = path.join(OUTPUT_DIR, patternCase.key + '-member-live.png');
					const memberScreenshotBuffer = await withStepTimeout('live card capture', STEP_TIMEOUT_MS.liveCardCapture, diagnostics, async () => {
						return screenshotLocator(memberCard, livePath);
					});
					diagnostics.artifacts.liveCard = {
						path: livePath,
						exists: fileExists(livePath),
					};
					const memberComputed = await readPatternComputed(page, '.adam-digital-card');
					markStep(diagnostics, 'live card captured');

					const download = await withStepTimeout('download event', STEP_TIMEOUT_MS.downloadEvent, diagnostics, async () => {
						const [downloadResult] = await Promise.all([
							page.waitForEvent('download', { timeout: STEP_TIMEOUT_MS.downloadEvent }),
							page.evaluate(() => {
								const trigger = document.querySelector('[data-adam-card-download="png"]');

								if (!trigger || !window.adamMemberCardDownloadApi) {
									throw new Error('PNG download button or API not found');
								}

								return window.adamMemberCardDownloadApi.downloadFromButton(trigger);
							}),
						]);

						return downloadResult;
					});
					markStep(diagnostics, 'download event');

					const downloadPath = path.join(OUTPUT_DIR, patternCase.key + '-member-download.png');
					await withStepTimeout('PNG file creation', STEP_TIMEOUT_MS.pngFileCreation, diagnostics, async () => {
						await download.saveAs(downloadPath);
						if (!fileExists(downloadPath)) {
							throw new Error('Download did not create file at ' + downloadPath);
						}
					});
					diagnostics.artifacts.png = {
						path: downloadPath,
						exists: fileExists(downloadPath),
					};
					markStep(diagnostics, 'PNG file creation');

					const downloadBuffer = fs.readFileSync(downloadPath);
					const liveVsDownload = await withStepTimeout('png compare', STEP_TIMEOUT_MS.pngCompare, diagnostics, async () => {
						return compareImages(page, memberScreenshotBuffer, downloadBuffer);
					});
					markStep(diagnostics, 'png compared');

					caseResult = {
						savedPatternState,
						previewComputedBefore,
						previewComputedAfter,
						memberComputed,
						liveVsDownload,
					};
				});

				diagnostics.status = 'passed';
			} catch (error) {
				diagnostics.status = 'failed';
				diagnostics.error = toSerializableError(error);
				console.error('pattern case failed:', patternCase.key, error);
			} finally {
				diagnostics.consoleErrors = pageConsoleErrors.slice();
				diagnostics.finishedAt = new Date().toISOString();
				const diagnosticsPath = saveCaseDiagnostics(diagnostics);
				results.push({
					case: patternCase,
					status: diagnostics.status,
					lastCompletedStep: diagnostics.lastCompletedStep,
					failedStep: diagnostics.failedStep,
					consoleErrors: diagnostics.consoleErrors,
					artifacts: diagnostics.artifacts,
					diagnosticsPath,
					error: diagnostics.error || null,
					caseResult,
				});
			}
		}
	} finally {
		if (rewardEditUrl && originalPatternState) {
			try {
				await page.goto(rewardEditUrl, { waitUntil: 'networkidle' });
				await setRewardPatternForm(page, {
					pattern: originalPatternState.pattern,
					color: originalPatternState.pattern_color,
					scale: originalPatternState.pattern_scale,
					density: originalPatternState.pattern_density,
					rotation: originalPatternState.pattern_rotation,
					spacing: originalPatternState.pattern_spacing,
					opacity: originalPatternState.pattern_opacity,
				});
				await saveReward(page);
			} catch (error) {
				console.error('Failed to restore original reward pattern state:', error);
			}
		}

		if (originalThemeKey !== activeThemeKey) {
			try {
				await page.goto(FRONT_URL, { waitUntil: 'domcontentloaded' });
				await acceptCookies(page);
				await saveMemberTheme(page, originalThemeKey);
			} catch (error) {
				console.error('Failed to restore original active theme:', error);
			}
		}

		await context.close();
		await browser.close();
	}

	const output = {
		ranAt: new Date().toISOString(),
		activeThemeKey,
		rewardEditUrl,
		results,
	};

	fs.writeFileSync(path.join(OUTPUT_DIR, 'pattern-verification.json'), JSON.stringify(output, null, 2));
	console.log(JSON.stringify(output, null, 2));
}

run().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
