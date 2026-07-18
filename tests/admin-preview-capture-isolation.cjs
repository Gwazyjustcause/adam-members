const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

const AUTH_FILE = path.join(__dirname, '..', 'playwright', '.auth', 'adam-user.json');
const OUTPUT_DIR = path.join(__dirname, '..', 'playwright', 'artifacts', 'admin-preview-capture-isolation');
const FRONT_URL = 'https://airsoftmondego.pt/socio/';
const REWARDS_URL = 'https://airsoftmondego.pt/wp-admin/admin.php?page=adam-membership-rewards';
const PREVIEW_SELECTOR = '[data-adam-card-preview-panel] .adam-digital-card';
const PATTERN_CASE = {
	key: 'dots-45',
	pattern: 'dots',
	color: '#2ecc71',
	scale: 32,
	density: 4,
	rotation: 45,
	spacing: 34,
	opacity: 58,
};
const SCREENSHOT_TIMEOUT_MS = 15000;

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

async function withTimeout(label, promiseFactory, timeoutMs = 20000) {
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
		await Promise.all(images.map((img) => {
			return new Promise((resolve) => {
				if (img.complete) {
					resolve();
					return;
				}

				img.addEventListener('load', resolve, { once: true });
				img.addEventListener('error', resolve, { once: true });
			});
		}));
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
			return (
				first &&
				second &&
				Math.abs(first.width - second.width) < 0.5 &&
				Math.abs(first.height - second.height) < 0.5 &&
				Math.abs(first.top - second.top) < 0.5 &&
				Math.abs(first.left - second.left) < 0.5
			);
		}

		let last = null;
		let stableCount = 0;
		const samples = [];
		const startedAt = Date.now();

		while (Date.now() - startedAt < 10000) {
			const element = document.querySelector(targetSelector);

			if (!element) {
				samples.push({ missing: true, at: Date.now() - startedAt });
			} else {
				const current = readBox(element);
				samples.push({ ...current, at: Date.now() - startedAt });

				if (
					current.width > 0 &&
					current.height > 0 &&
					current.display !== 'none' &&
					current.visibility !== 'hidden' &&
					current.opacity !== '0'
				) {
					stableCount = boxesMatch(last, current) ? stableCount + 1 : 1;

					if (stableCount >= requiredChecks) {
						return { stable: true, final: current, samples };
					}
				} else {
					stableCount = 0;
				}

				last = current;
			}

			await new Promise((resolve) => setTimeout(resolve, waitMs));
		}

		return { stable: false, final: last, samples };
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

async function collectPreviewDiagnostics(page) {
	return page.evaluate((targetSelector) => {
		const preview = document.querySelector(targetSelector);
		const pattern = preview ? preview.querySelector('.adam-digital-card__pattern') : null;

		return {
			previewPresent: Boolean(preview),
			children: preview ? preview.querySelectorAll('*').length : 0,
			pattern: pattern ? {
				transform: getComputedStyle(pattern).transform,
				width: getComputedStyle(pattern).width,
				height: getComputedStyle(pattern).height,
				inset: getComputedStyle(pattern).inset,
				overflow: getComputedStyle(pattern).overflow,
				backgroundImage: getComputedStyle(pattern).backgroundImage,
			} : null,
		};
	}, PREVIEW_SELECTOR);
}

function attachErrorListeners(page, bucket) {
	page.on('pageerror', (error) => {
		bucket.push({
			type: 'pageerror',
			message: error.message,
			stack: error.stack || '',
		});
	});

	page.on('console', (message) => {
		if (message.type() === 'error') {
			bucket.push({
				type: 'console',
				text: message.text(),
				location: message.location(),
			});
		}
	});
}

async function prepareSavedPreview(page, rewardEditUrl) {
	await page.goto(rewardEditUrl, { waitUntil: 'networkidle' });
	await disablePageMotion(page);
	await waitForCardAssets(page, PREVIEW_SELECTOR);
	const stability = await waitForStableBoundingBox(page, PREVIEW_SELECTOR);
	const diagnostics = await collectPreviewDiagnostics(page);

	return { stability, diagnostics };
}

async function screenshotFullPage(page, filePath) {
	return withTimeout('full-page screenshot', () => page.screenshot({ path: filePath, fullPage: true, timeout: SCREENSHOT_TIMEOUT_MS }), SCREENSHOT_TIMEOUT_MS);
}

async function screenshotPreview(page, filePath) {
	const locator = page.locator(PREVIEW_SELECTOR).first();
	return withTimeout('preview screenshot', () => locator.screenshot({ path: filePath, timeout: SCREENSHOT_TIMEOUT_MS }), SCREENSHOT_TIMEOUT_MS);
}

async function runStateCapture(page, stateKey) {
	const result = {
		state: stateKey,
		fullPage: { pass: false, error: null, artifact: null },
		preview: { pass: false, error: null, artifact: null },
		consoleErrors: [],
		stability: null,
		diagnostics: null,
	};

	try {
		const prepared = await prepareSavedPreview(page, page.url());
		result.stability = prepared.stability;
		result.diagnostics = prepared.diagnostics;

		const fullPagePath = path.join(OUTPUT_DIR, stateKey + '-fullpage.png');
		try {
			await screenshotFullPage(page, fullPagePath);
			result.fullPage.pass = true;
			result.fullPage.artifact = { path: fullPagePath, exists: fileExists(fullPagePath) };
		} catch (error) {
			result.fullPage.error = { message: error.message, stack: error.stack || '' };
			return result;
		}

		const previewPath = path.join(OUTPUT_DIR, stateKey + '-preview.png');
		try {
			await screenshotPreview(page, previewPath);
			result.preview.pass = true;
			result.preview.artifact = { path: previewPath, exists: fileExists(previewPath) };
		} catch (error) {
			result.preview.error = { message: error.message, stack: error.stack || '' };
		}

		return result;
	} finally {
		// no-op, caller owns page/context lifecycle
	}
}

async function createAuthenticatedContext(browser) {
	return browser.newContext({
		storageState: AUTH_FILE,
		acceptDownloads: true,
		viewport: { width: 1600, height: 1500 },
		deviceScaleFactor: 1,
	});
}

async function run() {
	ensureDir(OUTPUT_DIR);

	if (!fs.existsSync(AUTH_FILE)) {
		throw new Error('Missing Playwright auth state: ' + AUTH_FILE);
	}

	const browser = await chromium.launch({ headless: true });
	const baseContext = await createAuthenticatedContext(browser);
	const setupPage = await baseContext.newPage();
	const setupErrors = [];
	attachErrorListeners(setupPage, setupErrors);

	let rewardEditUrl = '';
	let savedRewardUrl = '';

	try {
		await setupPage.goto(FRONT_URL, { waitUntil: 'domcontentloaded' });
		await acceptCookies(setupPage);
		await disablePageMotion(setupPage);
		await waitForCardAssets(setupPage, '.adam-digital-card');
		const activeTheme = await getActiveTheme(setupPage);
		rewardEditUrl = await findRewardEditUrlByValue(setupPage, activeTheme);

		await setupPage.goto(rewardEditUrl, { waitUntil: 'networkidle' });
		await disablePageMotion(setupPage);
		await setRewardPatternForm(setupPage, PATTERN_CASE);
		await saveReward(setupPage);
		savedRewardUrl = setupPage.url();

		const matrix = [];

		const originalState = await runStateCapture(setupPage, 'original-page-after-save-reload');
		originalState.consoleErrors = setupErrors.slice();
		matrix.push(originalState);

		const freshPage = await baseContext.newPage();
		const freshPageErrors = [];
		attachErrorListeners(freshPage, freshPageErrors);
		await freshPage.goto(savedRewardUrl, { waitUntil: 'networkidle' });
		await disablePageMotion(freshPage);
		const sameContextState = await runStateCapture(freshPage, 'fresh-page-same-context');
		sameContextState.consoleErrors = freshPageErrors.slice();
		matrix.push(sameContextState);
		await freshPage.close();

		const freshContext = await createAuthenticatedContext(browser);
		const freshContextPage = await freshContext.newPage();
		const freshContextErrors = [];
		attachErrorListeners(freshContextPage, freshContextErrors);
		await freshContextPage.goto(savedRewardUrl, { waitUntil: 'networkidle' });
		await disablePageMotion(freshContextPage);
		const freshContextState = await runStateCapture(freshContextPage, 'fresh-browser-context');
		freshContextState.consoleErrors = freshContextErrors.slice();
		matrix.push(freshContextState);
		await freshContext.close();

		const output = {
			ranAt: new Date().toISOString(),
			patternCase: PATTERN_CASE,
			rewardEditUrl,
			savedRewardUrl,
			matrix,
		};

		fs.writeFileSync(path.join(OUTPUT_DIR, 'fresh-page-context-matrix.json'), JSON.stringify(output, null, 2));
		console.log(JSON.stringify(output, null, 2));
	} finally {
		await baseContext.close();
		await browser.close();
	}
}

run().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
