const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

const AUTH_FILE = path.join(__dirname, '..', 'playwright', '.auth', 'adam-user.json');
const OUTPUT_DIR = path.join(__dirname, '..', 'playwright', 'artifacts', 'pattern-acceptance');
const FRONT_URL = 'https://airsoftmondego.pt/socio/';
const REWARDS_URL = 'https://airsoftmondego.pt/wp-admin/admin.php?page=adam-membership-rewards';
const PREVIEW_SELECTOR = '[data-adam-card-preview-panel] .adam-digital-card';
const LIVE_SELECTOR = '.adam-digital-card';

const CASES = [
	{ key: 'dots-0', pattern: 'dots', color: '#2ecc71', scale: 32, density: 4, rotation: 0, spacing: 34, opacity: 58 },
	{ key: 'dots-45', pattern: 'dots', color: '#2ecc71', scale: 32, density: 4, rotation: 45, spacing: 34, opacity: 58 },
	{ key: 'dots-90', pattern: 'dots', color: '#2ecc71', scale: 32, density: 4, rotation: 90, spacing: 34, opacity: 58, rotation_only: true },
	{ key: 'dots-135', pattern: 'dots', color: '#2ecc71', scale: 32, density: 4, rotation: 135, spacing: 34, opacity: 58 },
	{ key: 'dots-315', pattern: 'dots', color: '#2ecc71', scale: 32, density: 4, rotation: 315, spacing: 34, opacity: 58, rotation_only: true },
	{ key: 'diagonal-default', pattern: 'diagonal', color: '#c7f9d4', scale: 24, density: 4, rotation: 45, spacing: 24, opacity: 38 },
	{ key: 'diagonal-tight', pattern: 'diagonal', color: '#c7f9d4', scale: 18, density: 5, rotation: 32, spacing: 18, opacity: 42 },
	{ key: 'diagonal-rotated', pattern: 'diagonal', color: '#f6d365', scale: 28, density: 3, rotation: 315, spacing: 30, opacity: 34 },
	{ key: 'carbon-default', pattern: 'carbon', color: '#9ca3af', scale: 22, density: 3, rotation: 0, spacing: 24, opacity: 38 },
	{ key: 'carbon-tight', pattern: 'carbon', color: '#9ca3af', scale: 14, density: 4, rotation: 0, spacing: 16, opacity: 44 },
	{ key: 'carbon-rotated', pattern: 'carbon', color: '#86efac', scale: 28, density: 2, rotation: 315, spacing: 32, opacity: 22 },
	{ key: 'opacity-low', pattern: 'dots', color: '#2ecc71', scale: 32, density: 4, rotation: 45, spacing: 34, opacity: 10, control: 'opacity' },
	{ key: 'opacity-high', pattern: 'dots', color: '#2ecc71', scale: 32, density: 4, rotation: 45, spacing: 34, opacity: 90, control: 'opacity' },
	{ key: 'scale-small', pattern: 'diagonal', color: '#c7f9d4', scale: 10, density: 4, rotation: 45, spacing: 20, opacity: 42, control: 'scale' },
	{ key: 'scale-large', pattern: 'diagonal', color: '#c7f9d4', scale: 48, density: 4, rotation: 45, spacing: 32, opacity: 42, control: 'scale' },
	{ key: 'color-alt', pattern: 'dots', color: '#ff00ff', scale: 32, density: 4, rotation: 45, spacing: 34, opacity: 58, control: 'color' },
	{ key: 'density-low', pattern: 'dots', color: '#2ecc71', scale: 32, density: 1, rotation: 45, spacing: 34, opacity: 58, control: 'density' },
	{ key: 'density-high', pattern: 'dots', color: '#2ecc71', scale: 32, density: 6, rotation: 45, spacing: 34, opacity: 58, control: 'density' },
	{ key: 'spacing-tight', pattern: 'dots', color: '#2ecc71', scale: 32, density: 4, rotation: 45, spacing: 12, opacity: 58, control: 'spacing' },
	{ key: 'spacing-wide', pattern: 'dots', color: '#2ecc71', scale: 32, density: 4, rotation: 45, spacing: 56, opacity: 58, control: 'spacing' },
];

const REQUESTED_CASE_KEYS = process.argv.slice(2);

function selectedCases() {
	if (!REQUESTED_CASE_KEYS.length) {
		return CASES;
	}

	return CASES.filter((patternCase) => REQUESTED_CASE_KEYS.includes(patternCase.key));
}

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

function normalizePatternState(state) {
	return {
		pattern: state.pattern,
		pattern_color: String(state.pattern_color).toLowerCase(),
		pattern_scale: Number(state.pattern_scale),
		pattern_density: Number(state.pattern_density),
		pattern_rotation: Number(state.pattern_rotation),
		pattern_spacing: Number(state.pattern_spacing),
		pattern_opacity: Number(state.pattern_opacity),
	};
}

function expectedPatternState(patternCase) {
	return {
		pattern: patternCase.pattern,
		pattern_color: String(patternCase.color).toLowerCase(),
		pattern_scale: Number(patternCase.scale),
		pattern_density: Number(patternCase.density),
		pattern_rotation: Number(patternCase.rotation),
		pattern_spacing: Number(patternCase.spacing),
		pattern_opacity: Number(patternCase.opacity),
	};
}

function statesMatch(first, second) {
	const left = normalizePatternState(first);
	const right = normalizePatternState(second);
	return JSON.stringify(left) === JSON.stringify(right);
}

async function withTimeout(label, promiseFactory, timeoutMs = 30000) {
	let timeoutId = null;
	try {
		return await Promise.race([
			promiseFactory(),
			new Promise((_, reject) => {
				timeoutId = setTimeout(() => reject(new Error(label + ' timed out after ' + timeoutMs + 'ms')), timeoutMs);
			}),
		]);
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

async function waitForCardAssets(page, selector) {
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
		await Promise.all(images.map((img) => new Promise((resolve) => {
			if (img.complete) {
				resolve();
				return;
			}
			img.addEventListener('load', resolve, { once: true });
			img.addEventListener('error', resolve, { once: true });
		})));
	}, selector);
}

async function waitForStableBoundingBox(page, selector, checks = 2, delayMs = 250) {
	return page.evaluate(async ({ targetSelector, requiredChecks, waitMs }) => {
		function readBox(element) {
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
		function boxesMatch(first, second) {
			return first && second &&
				Math.abs(first.width - second.width) < 0.5 &&
				Math.abs(first.height - second.height) < 0.5 &&
				Math.abs(first.top - second.top) < 0.5 &&
				Math.abs(first.left - second.left) < 0.5;
		}
		let last = null;
		let stableCount = 0;
		const startedAt = Date.now();
		while (Date.now() - startedAt < 10000) {
			const element = document.querySelector(targetSelector);
			if (element) {
				const current = readBox(element);
				if (
					current.width > 0 &&
					current.height > 0 &&
					current.display !== 'none' &&
					current.visibility !== 'hidden' &&
					current.opacity !== '0'
				) {
					stableCount = boxesMatch(last, current) ? stableCount + 1 : 1;
					if (stableCount >= requiredChecks) {
						return { stable: true, final: current };
					}
				}
				last = current;
			}
			await new Promise((resolve) => setTimeout(resolve, waitMs));
		}
		return { stable: false, final: last };
	}, { targetSelector: selector, requiredChecks: checks, waitMs: delayMs });
}

async function setFieldValue(page, selector, value) {
	await page.locator(selector).evaluate((element, nextValue) => {
		element.value = String(nextValue);
		element.dispatchEvent(new Event('input', { bubbles: true }));
		element.dispatchEvent(new Event('change', { bubbles: true }));
	}, value);
}

async function setRangeValue(page, selector, value) {
	await setFieldValue(page, selector, value);
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

async function readPatternComputed(page, selector) {
	return page.evaluate((targetSelector) => {
		const card = document.querySelector(targetSelector);
		const pattern = card ? card.querySelector('.adam-digital-card__pattern') : null;
		const style = card ? getComputedStyle(card) : null;
		const patternStyle = pattern ? getComputedStyle(pattern) : null;
		if (!card || !pattern || !style || !patternStyle) {
			throw new Error('Pattern layer not found for selector ' + targetSelector);
		}
		const cardRect = card.getBoundingClientRect();
		const patternRect = pattern.getBoundingClientRect();
		return {
			cardBox: { left: cardRect.left, top: cardRect.top, right: cardRect.right, bottom: cardRect.bottom, width: cardRect.width, height: cardRect.height },
			patternBox: { left: patternRect.left, top: patternRect.top, right: patternRect.right, bottom: patternRect.bottom, width: patternRect.width, height: patternRect.height },
			vars: {
				color: style.getPropertyValue('--adam-card-pattern-color').trim().toLowerCase(),
				strong: style.getPropertyValue('--adam-card-pattern-color-strong').trim().toLowerCase(),
				soft: style.getPropertyValue('--adam-card-pattern-color-soft').trim().toLowerCase(),
				opacity: style.getPropertyValue('--adam-card-pattern-opacity').trim(),
				size: style.getPropertyValue('--adam-card-pattern-size').trim(),
				spacing: style.getPropertyValue('--adam-card-pattern-spacing').trim(),
				density: style.getPropertyValue('--adam-card-pattern-density').trim(),
				rotation: style.getPropertyValue('--adam-card-pattern-rotation').trim(),
			},
			pattern: {
				className: pattern.className,
				backgroundImage: patternStyle.backgroundImage,
				backgroundColor: patternStyle.backgroundColor,
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

async function screenshotPreview(locator, filePath) {
	return withTimeout('preview screenshot', () => locator.screenshot({ path: filePath, timeout: 20000 }), 20000);
}

async function screenshotLive(page, filePath) {
	return page.evaluate(async (targetPath) => {
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
	}, filePath).then((dataUrl) => {
		const buffer = Buffer.from(dataUrl.replace(/^data:image\/png;base64,/, ''), 'base64');
		fs.writeFileSync(filePath, buffer);
		return buffer;
	});
}

async function compareImages(page, firstBuffer, secondBuffer) {
	const firstDataUrl = 'data:image/png;base64,' + firstBuffer.toString('base64');
	const secondDataUrl = 'data:image/png;base64,' + secondBuffer.toString('base64');
	return page.evaluate(async ({ firstDataUrlInner, secondDataUrlInner }) => {
		function loadImage(src) {
			return new Promise((resolve, reject) => {
				const image = new Image();
				image.onload = () => resolve(image);
				image.onerror = () => reject(new Error('Failed to load image for comparison'));
				image.src = src;
			});
		}
		const [firstImage, secondImage] = await Promise.all([
			loadImage(firstDataUrlInner),
			loadImage(secondDataUrlInner),
		]);
		const width = Math.min(firstImage.naturalWidth, secondImage.naturalWidth, 420);
		const height = Math.min(
			Math.round((width / firstImage.naturalWidth) * firstImage.naturalHeight),
			Math.round((width / secondImage.naturalWidth) * secondImage.naturalHeight)
		);
		const firstCanvas = document.createElement('canvas');
		firstCanvas.width = width;
		firstCanvas.height = height;
		firstCanvas.getContext('2d').drawImage(firstImage, 0, 0, width, height);
		const secondCanvas = document.createElement('canvas');
		secondCanvas.width = width;
		secondCanvas.height = height;
		secondCanvas.getContext('2d').drawImage(secondImage, 0, 0, width, height);
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
			sampleWidth: width,
			sampleHeight: height,
			averagePerPixel: totalDiff / (width * height),
			maxPerPixel: maxDiff,
		};
	}, { firstDataUrlInner: firstDataUrl, secondDataUrlInner: secondDataUrl });
}

async function saveReward(page) {
	await Promise.all([
		page.waitForURL((url) => url.toString().includes('adam_message='), { timeout: 30000 }),
		page.locator('form.adam-admin-edit-form').evaluate((form) => {
			form.submit();
		}),
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
	return firstAvailable;
}

async function saveMemberTheme(page, themeKey) {
	await page.selectOption('select[name="active_card_theme"]', themeKey);
	await Promise.all([
		page.waitForLoadState('networkidle'),
		page.locator('.adam-card-customizer').getByRole('button', { name: 'Guardar visual' }).click(),
	]);
}

async function findRewardEditUrlByValue(page, rewardValue) {
	await page.goto(REWARDS_URL, { waitUntil: 'networkidle' });
	const editLinks = await page.locator('a[href*="page=adam-membership-reward-edit"]').evaluateAll((links) => links.map((link) => link.href));
	for (const href of editLinks) {
		await page.goto(href, { waitUntil: 'networkidle' });
		const value = await page.locator('[name="reward_value"]').inputValue().catch(() => '');
		if (value === rewardValue) {
			return href;
		}
	}
	throw new Error('Could not find reward edit page for value ' + rewardValue);
}

function attachPageConsoleListeners(page, bucket) {
	page.on('pageerror', (error) => {
		bucket.push({
			type: 'pageerror',
			message: error.message,
			stack: error.stack || '',
			at: new Date().toISOString(),
		});
	});
	page.on('console', (message) => {
		if (message.type() === 'error') {
			bucket.push({
				type: 'console',
				text: message.text(),
				location: message.location(),
				at: new Date().toISOString(),
			});
		}
	});
}

function cardCoveragePass(computed) {
	const epsilon = 1;
	return (
		computed.patternBox.left <= computed.cardBox.left + epsilon &&
		computed.patternBox.top <= computed.cardBox.top + epsilon &&
		computed.patternBox.right >= computed.cardBox.right - epsilon &&
		computed.patternBox.bottom >= computed.cardBox.bottom - epsilon
	);
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

	let originalThemeKey = '';
	let activeThemeKey = '';
	let rewardEditUrl = '';
	let originalPatternState = null;
	let noPatternBackgroundUi = null;
	const results = [];

	try {
		await page.goto(FRONT_URL, { waitUntil: 'domcontentloaded' });
		await acceptCookies(page);
		await disablePageMotion(page);
		await waitForCardAssets(page, LIVE_SELECTOR);
		originalThemeKey = await page.locator('select[name="active_card_theme"]').inputValue();
		activeThemeKey = await getActiveTheme(page);
		rewardEditUrl = await findRewardEditUrlByValue(page, activeThemeKey);

		await page.goto(rewardEditUrl, { waitUntil: 'networkidle' });
		await disablePageMotion(page);
		originalPatternState = await readPatternForm(page);
		noPatternBackgroundUi = await page.evaluate(() => {
			const field = document.querySelector('input[name="visual_style[pattern_background_color]"]');
			const text = document.body ? document.body.innerText : '';
			return {
				fieldPresent: Boolean(field),
				labelPresent: /Cor de base do padrao/i.test(text),
			};
		});

		for (const patternCase of selectedCases()) {
			const caseDir = path.join(OUTPUT_DIR, patternCase.key);
			ensureDir(caseDir);
			const caseConsoleErrors = [];
			const caseResult = {
				case: patternCase,
				status: 'running',
				errors: [],
				consoleErrors: caseConsoleErrors,
				artifacts: {},
				checks: {},
			};

			try {
				await page.goto(rewardEditUrl, { waitUntil: 'networkidle' });
				await disablePageMotion(page);
				await setRewardPatternForm(page, patternCase);
				await waitForCardAssets(page, PREVIEW_SELECTOR);
				await waitForStableBoundingBox(page, PREVIEW_SELECTOR);

				const previewBeforePath = path.join(caseDir, 'preview-before-save.png');
				const previewBeforeBuffer = await screenshotPreview(page.locator(PREVIEW_SELECTOR).first(), previewBeforePath);
				caseResult.artifacts.previewBefore = previewBeforePath;
				const previewComputedBefore = await readPatternComputed(page, PREVIEW_SELECTOR);
				const formBefore = await readPatternForm(page);

				await saveReward(page);
				const savedRewardUrl = page.url();

				const verifyPage = await context.newPage();
				attachPageConsoleListeners(verifyPage, caseConsoleErrors);
				await verifyPage.goto(savedRewardUrl, { waitUntil: 'networkidle' });
				await disablePageMotion(verifyPage);
				await waitForCardAssets(verifyPage, PREVIEW_SELECTOR);
				await waitForStableBoundingBox(verifyPage, PREVIEW_SELECTOR);

				const previewAfterPath = path.join(caseDir, 'preview-after-save.png');
				const previewAfterBuffer = await screenshotPreview(verifyPage.locator(PREVIEW_SELECTOR).first(), previewAfterPath);
				caseResult.artifacts.previewAfter = previewAfterPath;
				const previewComputedAfter = await readPatternComputed(verifyPage, PREVIEW_SELECTOR);
				const formAfter = await readPatternForm(verifyPage);
				await verifyPage.close();

				await page.goto(FRONT_URL, { waitUntil: 'domcontentloaded' });
				await acceptCookies(page);
				await disablePageMotion(page);
				await waitForCardAssets(page, LIVE_SELECTOR);

				const livePath = path.join(caseDir, 'member-live.png');
				const liveBuffer = await screenshotLive(page, livePath);
				caseResult.artifacts.live = livePath;
				const liveComputed = await readPatternComputed(page, LIVE_SELECTOR);

				const [download] = await Promise.all([
					page.waitForEvent('download', { timeout: 30000 }),
					page.evaluate(() => {
						const trigger = document.querySelector('[data-adam-card-download="png"]');
						if (!trigger || !window.adamMemberCardDownloadApi) {
							throw new Error('PNG download button or API not found');
						}
						return window.adamMemberCardDownloadApi.downloadFromButton(trigger);
					}),
				]);
				const pngPath = path.join(caseDir, 'member-download.png');
				await download.saveAs(pngPath);
				caseResult.artifacts.png = pngPath;
				const pngBuffer = fs.readFileSync(pngPath);

				const previewPersistenceDiff = await compareImages(page, previewBeforeBuffer, previewAfterBuffer);
				const liveVsDownload = await compareImages(page, liveBuffer, pngBuffer);

				caseResult.checks = {
					expectedState: expectedPatternState(patternCase),
					formBefore,
					formAfter,
					previewComputedBefore,
					previewComputedAfter,
					liveComputed,
					previewPersistenceDiff,
					liveVsDownload,
					persistencePass: statesMatch(formAfter, expectedPatternState(patternCase)) && previewPersistenceDiff.averagePerPixel < 8,
					liveMatchesPreviewPass:
						previewComputedAfter.vars.color === liveComputed.vars.color &&
						previewComputedAfter.vars.opacity === liveComputed.vars.opacity &&
						previewComputedAfter.vars.rotation === liveComputed.vars.rotation &&
						previewComputedAfter.vars.spacing === liveComputed.vars.spacing &&
						previewComputedAfter.vars.density === liveComputed.vars.density &&
						previewComputedAfter.vars.size === liveComputed.vars.size,
					pngMatchesLivePass: liveVsDownload.averagePerPixel < 12,
					noPatternBackgroundPass:
						previewComputedAfter.pattern.backgroundColor === 'rgba(0, 0, 0, 0)' &&
						!previewComputedAfter.pattern.backgroundImage.includes('rgb(255, 255, 255)'),
					coveragePass: cardCoveragePass(previewComputedAfter) && cardCoveragePass(liveComputed),
				};

				caseResult.status = 'passed';
			} catch (error) {
				caseResult.status = 'failed';
				caseResult.errors.push({
					message: error.message,
					stack: error.stack || '',
				});
			}

			results.push(caseResult);
		}
	} finally {
		if (rewardEditUrl && originalPatternState) {
			try {
				await page.goto(rewardEditUrl, { waitUntil: 'networkidle' });
				await disablePageMotion(page);
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
				// ignore restore failure in acceptance report
			}
		}

		if (originalThemeKey && originalThemeKey !== activeThemeKey) {
			try {
				await page.goto(FRONT_URL, { waitUntil: 'domcontentloaded' });
				await acceptCookies(page);
				await saveMemberTheme(page, originalThemeKey);
			} catch (error) {
				// ignore restore failure in acceptance report
			}
		}

		await context.close();
		await browser.close();
	}

	const output = {
		ranAt: new Date().toISOString(),
		noPatternBackgroundUi,
		results,
	};
	fs.writeFileSync(path.join(OUTPUT_DIR, 'pattern-acceptance.json'), JSON.stringify(output, null, 2));
	console.log(JSON.stringify(output, null, 2));
}

run().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
