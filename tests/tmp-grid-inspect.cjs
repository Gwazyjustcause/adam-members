const path = require('path');
const { chromium } = require('@playwright/test');

const AUTH_FILE = path.join(__dirname, '..', 'playwright', '.auth', 'adam-user.json');
const REWARD_URL = 'https://airsoftmondego.pt/wp-admin/admin.php?page=adam-membership-reward-edit&reward_id=15';
const ARTIFACT = path.join(__dirname, '..', 'playwright', 'artifacts', 'tmp-grid-inspect-debug.png');

function ensureDir(targetPath) {
	require('fs').mkdirSync(path.dirname(targetPath), { recursive: true });
}

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
		const readStyles = (element, pseudo = null) => {
			const pseudoStyle = window.getComputedStyle(element, pseudo);

			return {
				pseudo,
				position: pseudoStyle.position,
				display: pseudoStyle.display,
				content: pseudoStyle.content,
				top: pseudoStyle.top,
				right: pseudoStyle.right,
				bottom: pseudoStyle.bottom,
				left: pseudoStyle.left,
				inset: pseudoStyle.inset,
				width: pseudoStyle.width,
				height: pseudoStyle.height,
				transform: pseudoStyle.transform,
				transformOrigin: pseudoStyle.transformOrigin,
				backgroundImage: pseudoStyle.backgroundImage,
				backgroundSize: pseudoStyle.backgroundSize,
				backgroundRepeat: pseudoStyle.backgroundRepeat,
				backgroundPosition: pseudoStyle.backgroundPosition,
				overflow: pseudoStyle.overflow,
				clipPath: pseudoStyle.clipPath,
				maskImage: pseudoStyle.maskImage,
			};
		};
		const parent = pattern ? pattern.parentElement : null;

		return {
			element: pattern ? pattern.outerHTML : null,
			parentClassName: parent ? parent.className : null,
			grandparentClassName: parent && parent.parentElement ? parent.parentElement.className : null,
			cardOuterHtmlSnippet: card.outerHTML.slice(0, 2000),
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
			rects: {
				card: card.getBoundingClientRect().toJSON(),
				pattern: pattern ? pattern.getBoundingClientRect().toJSON() : null,
				parent: parent ? parent.getBoundingClientRect().toJSON() : null,
			},
			computed: {
				main: readStyles(pattern),
				before: readStyles(pattern, '::before'),
				after: readStyles(pattern, '::after'),
			},
		};
	});

	ensureDir(ARTIFACT);
	await page.locator('[data-adam-card-preview-panel] .adam-digital-card').screenshot({ path: ARTIFACT });

	console.log(JSON.stringify({ artifact: ARTIFACT, data }, null, 2));

	await browser.close();
})().catch((error) => {
	console.error(error);
	process.exit(1);
});
