const path = require('path');
const { chromium } = require('@playwright/test');

const AUTH_FILE = path.join(__dirname, '..', 'playwright', '.auth', 'adam-user.json');
const REWARD_URL = 'https://airsoftmondego.pt/wp-admin/admin.php?page=adam-membership-reward-edit&reward_id=15';

(async () => {
	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({ storageState: AUTH_FILE });
	const page = await context.newPage();

	await page.goto(REWARD_URL, { waitUntil: 'domcontentloaded' });
	await page.evaluate(() => {
		const setValue = (selector, value) => {
			const field = document.querySelector(selector);

			if (!field) {
				throw new Error(`Missing field: ${selector}`);
			}

			field.value = value;
			field.dispatchEvent(new Event('input', { bubbles: true }));
			field.dispatchEvent(new Event('change', { bubbles: true }));
		};

		setValue('select[name="visual_style[pattern]"]', 'grid');
		setValue('input[name="visual_style[pattern_color]"]', '#d8b4fe');
		setValue('input[name="visual_style[pattern_scale]"]', '120');
		setValue('input[name="visual_style[pattern_density]"]', '12');
		setValue('input[name="visual_style[pattern_rotation]"]', '360');
		setValue('input[name="visual_style[pattern_spacing]"]', '120');
		setValue('input[name="visual_style[pattern_opacity]"]', '100');
	});

	await page.waitForTimeout(800);

	const data = await page.locator('[data-adam-card-preview-panel] .adam-digital-card').evaluate((card) => {
		const pattern = card.querySelector('.adam-digital-card__pattern');
		const style = window.getComputedStyle(pattern);
		const box = pattern.getBoundingClientRect();

		return {
			backgroundImage: style.backgroundImage,
			backgroundSize: style.backgroundSize,
			backgroundRepeat: style.backgroundRepeat,
			backgroundPosition: style.backgroundPosition,
			width: style.width,
			height: style.height,
			transform: style.transform,
			box: {
				width: box.width,
				height: box.height,
			},
		};
	});

	console.log(JSON.stringify(data, null, 2));

	await browser.close();
})().catch((error) => {
	console.error(error);
	process.exit(1);
});
