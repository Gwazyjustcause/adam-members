const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { chromium } = require('@playwright/test');

const AUTH_FILE = path.join(__dirname, '..', 'playwright', '.auth', 'adam-user.json');
const FRONT_URL = 'https://airsoftmondego.pt/socio/';
const REWARDS_URL = 'https://airsoftmondego.pt/wp-admin/admin.php?page=adam-membership-rewards';
const LIVE_SELECTOR = '.adam-digital-card';
const PREVIEW_SELECTOR = '[data-adam-card-preview-panel] .adam-digital-card';

function sha256(input) {
	return crypto.createHash('sha256').update(input).digest('hex');
}

async function acceptCookies(page) {
	const button = page.getByRole('button', { name: 'Aceitar tudo' });
	if (await button.count()) {
		await button.first().click().catch(() => {});
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
		`,
	});
}

async function waitForCardAssets(page, selector) {
	await page.locator(selector).waitFor({ state: 'visible', timeout: 30000 });
	await page.evaluate(async (cardSelector) => {
		await document.fonts.ready;
		const card = document.querySelector(cardSelector);
		if (!card) {
			throw new Error('Card not found for asset wait');
		}
		const images = Array.from(card.querySelectorAll('img'));
		await Promise.all(images.map((img) => new Promise((resolve) => {
			if (img.complete) {
				resolve();
				return;
			}
			const finish = () => resolve();
			img.addEventListener('load', finish, { once: true });
			img.addEventListener('error', finish, { once: true });
		})));
	}, selector);
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
		throw new Error('No active card theme found');
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
	throw new Error('Reward edit URL not found for ' + rewardValue);
}

async function inspectPattern(page, selector) {
	return page.evaluate((cardSelector) => {
		const card = document.querySelector(cardSelector);
		const pattern = card ? card.querySelector('.adam-digital-card__pattern') : null;
		if (!card || !pattern) {
			return null;
		}
		const cardRect = card.getBoundingClientRect();
		const patternRect = pattern.getBoundingClientRect();
		const style = window.getComputedStyle(pattern);
		return {
			cardBox: {
				width: cardRect.width,
				height: cardRect.height,
			},
			patternBox: {
				width: patternRect.width,
				height: patternRect.height,
			},
			pattern: {
				className: pattern.className,
				backgroundImage: style.backgroundImage,
				backgroundColor: style.backgroundColor,
				opacity: style.opacity,
				transform: style.transform,
				width: style.width,
				height: style.height,
				inset: style.inset,
				top: style.top,
				left: style.left,
				overflow: style.overflow,
				display: style.display,
				visibility: style.visibility,
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
		};
	}, selector);
}

async function fetchStylesheetHashes(page) {
	const urls = await page.evaluate(() => Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map((link) => link.href));
	const interesting = urls.filter((href) => /admin\.css|member-area\.css/.test(href));
	const output = [];

	for (const href of interesting) {
		const response = await page.request.get(href);
		const text = await response.text();
		output.push({
			url: href,
			status: response.status(),
			sha256: sha256(text),
			hasPatternBackgroundField: text.includes('pattern_background_color'),
			hasOverscan150: text.includes('width: 150%') || text.includes('width:150%'),
			hasOldInset12: text.includes('inset: 12px') || text.includes('inset:12px'),
		});
	}

	return output;
}

async function run() {
	if (!fs.existsSync(AUTH_FILE)) {
		throw new Error('Missing Playwright auth file: ' + AUTH_FILE);
	}

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({
		storageState: AUTH_FILE,
		viewport: { width: 1600, height: 1500 },
		deviceScaleFactor: 1,
	});
	const page = await context.newPage();

	try {
		await page.goto(FRONT_URL, { waitUntil: 'domcontentloaded' });
		await acceptCookies(page);
		await disablePageMotion(page);
		await waitForCardAssets(page, LIVE_SELECTOR);
		const activeThemeKey = await getActiveTheme(page);
		const rewardEditUrl = await findRewardEditUrlByValue(page, activeThemeKey);

		await page.goto(rewardEditUrl, { waitUntil: 'networkidle' });
		await disablePageMotion(page);
		await page.locator(PREVIEW_SELECTOR).waitFor({ state: 'visible', timeout: 30000 });

		const fieldPresent = await page.locator('input[name="visual_style[pattern_background_color]"]').count();
		const previewPattern = await inspectPattern(page, PREVIEW_SELECTOR);
		const adminStyles = await fetchStylesheetHashes(page);

		await page.goto(FRONT_URL, { waitUntil: 'networkidle' });
		await disablePageMotion(page);
		await waitForCardAssets(page, LIVE_SELECTOR);
		const livePattern = await inspectPattern(page, LIVE_SELECTOR);
		const memberStyles = await fetchStylesheetHashes(page);

		const localFiles = [
			{ name: 'RewardService.php', path: path.join(__dirname, '..', 'src', 'Reward', 'RewardService.php') },
			{ name: 'admin.css', path: path.join(__dirname, '..', 'assets', 'css', 'admin.css') },
			{ name: 'member-area.css', path: path.join(__dirname, '..', 'assets', 'css', 'member-area.css') },
		].map((file) => {
			const content = fs.readFileSync(file.path, 'utf8');
			const stat = fs.statSync(file.path);
			return {
				name: file.name,
				path: file.path,
				sha256: sha256(content),
				lastModifiedIso: stat.mtime.toISOString(),
				size: stat.size,
			};
		});

		console.log(JSON.stringify({
			rewardEditUrl,
			activeThemeKey,
			adminPage: {
				patternBackgroundFieldPresent: fieldPresent > 0,
				previewPattern,
				stylesheets: adminStyles,
			},
			memberPage: {
				livePattern,
				stylesheets: memberStyles,
			},
			localFiles,
		}, null, 2));
	} finally {
		await context.close();
		await browser.close();
	}
}

run().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
