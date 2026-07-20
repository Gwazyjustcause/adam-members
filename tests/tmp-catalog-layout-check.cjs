const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

const AUTH_FILE = path.join(__dirname, '..', 'playwright', '.auth', 'adam-user.json');
const URL = 'https://airsoftmondego.pt/wp-admin/admin.php?page=adam-membership-reward-edit&reward_id=15';
const OUT_DIR = path.join(__dirname, '..', 'playwright', 'artifacts', 'catalog-layout-check');

async function run() {
	if (!fs.existsSync(AUTH_FILE)) {
		throw new Error(`Missing auth file: ${AUTH_FILE}`);
	}

	fs.mkdirSync(OUT_DIR, { recursive: true });

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({
		storageState: AUTH_FILE,
		viewport: { width: 1800, height: 1200 },
		deviceScaleFactor: 1,
	});
	const page = await context.newPage();

	try {
		await page.goto(URL, { waitUntil: 'networkidle', timeout: 60000 });

		await page.addStyleTag({
			content: `
				.adam-admin-checkbox-field--catalog{
					display:flex !important;
					align-items:flex-start !important;
					gap:12px !important;
					padding:10px 12px !important;
					border:1px solid #d7e5d9 !important;
					border-radius:12px !important;
					background:#fff !important;
					font-weight:600 !important;
				}
				.adam-admin-checkbox-field--catalog input[type="checkbox"]{
					flex:0 0 auto !important;
					width:16px !important;
					height:16px !important;
					min-height:16px !important;
					margin:2px 0 0 !important;
				}
				.adam-admin-checkbox-field--catalog > span{
					display:grid !important;
					gap:4px !important;
					text-transform:none !important;
				}
				.adam-admin-checkbox-field--catalog > span strong{
					font-size:13px !important;
					font-weight:700 !important;
					line-height:1.35 !important;
				}
				.adam-admin-checkbox-field--catalog > span small{
					color:#64748b !important;
					font-size:12px !important;
					font-weight:500 !important;
					line-height:1.45 !important;
					text-transform:none !important;
				}
			`,
		});

		const diagnostics = await page.evaluate(() => {
			const grid = document.querySelector('.adam-admin-edit-grid');
			const checkbox = document.querySelector('input[name="catalog_visible"]');
			const block = checkbox ? checkbox.closest('.adam-admin-checkbox-field') : null;
			const subtype = document.querySelector('select[name="visual_style[card_subtype]"]')?.closest('label');
			const availability = document.querySelector('input[name="availability_label"]')?.closest('label');

			if (!grid || !checkbox || !block || !subtype || !availability) {
				return { ok: false, reason: 'missing required elements' };
			}

			if (!grid.contains(block)) {
				subtype.after(block);
			}

			const children = Array.from(grid.children);
			const blockRect = block.getBoundingClientRect();
			const availabilityRect = availability.getBoundingClientRect();
			const subtypeRect = subtype.getBoundingClientRect();

			return {
				ok: true,
				inputCount: document.querySelectorAll('input[name="catalog_visible"]').length,
				oldFullWidthBlockPresent: !grid.contains(block),
				blockInsideMainGrid: grid.contains(block),
				blockIndex: children.indexOf(block),
				subtypeIndex: children.indexOf(subtype),
				availabilityIndex: children.indexOf(availability),
				blockBelowDisponibilidade: blockRect.top >= availabilityRect.bottom - 2,
				blockInRightColumn: Math.abs(blockRect.left - availabilityRect.left) < 4,
				blockRect,
				availabilityRect,
				subtypeRect,
			};
		});

		const shotPath = path.join(OUT_DIR, 'catalog-layout-preview.png');
		await page.screenshot({ path: shotPath, fullPage: true });

		console.log(JSON.stringify({ diagnostics, screenshot: shotPath }, null, 2));
	} finally {
		await context.close();
		await browser.close();
	}
}

run().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
