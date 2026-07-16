const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

const AUTH_FILE = path.join(__dirname, '..', 'playwright', '.auth', 'adam-user.json');
const OUTPUT_DIR = path.join(__dirname, '..', 'playwright', 'artifacts', 'member-card-layer-debug');
const CARD_CSS_FILE = path.join(__dirname, '..', 'assets', 'css', 'member-area.css');

async function saveCapture(page, filename, extraCss) {
	if (extraCss) {
		await page.addStyleTag({ content: extraCss });
	}

	const dataUrl = await page.evaluate(async () => {
		const card = document.querySelector('.adam-digital-card');
		if (document.fonts && document.fonts.ready) {
			await document.fonts.ready;
		}
		const images = Array.from(card.querySelectorAll('img'));
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
		const canvas = await window.html2canvas(card, {
			scale: 2,
			useCORS: true,
			allowTaint: false,
			backgroundColor: null,
			logging: false,
			imageTimeout: 15000
		});
		return canvas.toDataURL('image/png');
	});

	const buffer = Buffer.from(dataUrl.replace(/^data:image\/png;base64,/, ''), 'base64');
	fs.writeFileSync(path.join(OUTPUT_DIR, filename), buffer);
}

async function run() {
	fs.mkdirSync(OUTPUT_DIR, { recursive: true });

	const browser = await chromium.launch({ headless: true });
	const context = await browser.newContext({
		storageState: AUTH_FILE,
		acceptDownloads: true,
		viewport: { width: 1440, height: 1400 },
	});
	const page = await context.newPage();

	try {
		await page.goto('https://airsoftmondego.pt/socio/', { waitUntil: 'networkidle' });
		const acceptCookies = page.getByRole('button', { name: 'Aceitar tudo' });
		if (await acceptCookies.count()) {
			await acceptCookies.first().click().catch(() => {});
		}
		await page.addStyleTag({ path: CARD_CSS_FILE });
		await page.waitForSelector('.adam-digital-card', { state: 'visible' });

		const diagnostics = await page.evaluate(() => {
			const card = document.querySelector('.adam-digital-card');
			return {
				className: card.className,
				style: card.getAttribute('style'),
				children: Array.from(card.children).map((child) => ({
					className: child.className,
					tagName: child.tagName,
					zIndex: getComputedStyle(child).zIndex,
					position: getComputedStyle(child).position,
					display: getComputedStyle(child).display,
					opacity: getComputedStyle(child).opacity,
					background: getComputedStyle(child).background,
				}))
			};
		});

		console.log(JSON.stringify(diagnostics, null, 2));

		await saveCapture(page, 'normal.png', '');
		await saveCapture(page, 'no-frame.png', '.adam-digital-card__frame{display:none !important;}');
		await saveCapture(page, 'no-frame-no-shine.png', '.adam-digital-card__frame{display:none !important;}.adam-digital-card__shine{display:none !important;}');
		await saveCapture(page, 'no-pattern.png', '.adam-digital-card__pattern{display:none !important;}');
		await saveCapture(page, 'no-backdrop.png', '.adam-digital-card__backdrop{display:none !important;}');
		await saveCapture(page, 'no-art.png', '.adam-digital-card__art{display:none !important;}');
		await saveCapture(page, 'no-shapes.png', '.adam-digital-card__shapes{display:none !important;}');
		await saveCapture(page, 'no-pattern-no-backdrop.png', '.adam-digital-card__pattern{display:none !important;}.adam-digital-card__backdrop{display:none !important;}');
		await saveCapture(page, 'content-only.png', '.adam-digital-card__backdrop,.adam-digital-card__pattern,.adam-digital-card__art,.adam-digital-card__shapes,.adam-digital-card__shine,.adam-digital-card__frame{display:none !important;}');
		await saveCapture(page, 'no-isolation.png', '.adam-digital-card{isolation:auto !important;}');
		await saveCapture(page, 'no-overflow.png', '.adam-digital-card{overflow:visible !important;}');
		await saveCapture(page, 'no-grid.png', '.adam-digital-card{display:block !important;}');
		await saveCapture(page, 'no-border.png', '.adam-digital-card{border-width:0 !important;box-shadow:none !important;}');
		await saveCapture(page, 'root-inset-shadow-frame.png', '.adam-digital-card{border-width:0 !important;box-shadow:inset 0 0 0 16px #b7b7b8 !important;}.adam-digital-card__frame{display:none !important;}');
		await saveCapture(page, 'layer-inset-shadow-frame.png', '.adam-digital-card{border-width:0 !important;box-shadow:none !important;}.adam-digital-card__frame{display:block !important;}.adam-digital-card__frame-layer--outer{background:none !important;box-shadow:inset 0 0 0 16px #b7b7b8 !important;}.adam-digital-card__frame-layer--inner{display:none !important;}');
		await saveCapture(page, 'root-background-frame.png', '.adam-digital-card{border-width:0 !important;box-shadow:none !important;background:linear-gradient(90deg,#e6e6e6 0%,#919191 50%,#e0e0e0 100%) top/100% 16px no-repeat,linear-gradient(180deg,#e6e6e6 0%,#919191 50%,#e0e0e0 100%) right/16px 100% no-repeat,linear-gradient(90deg,#e0e0e0 0%,#919191 50%,#e6e6e6 100%) bottom/100% 16px no-repeat,linear-gradient(180deg,#e0e0e0 0%,#919191 50%,#e6e6e6 100%) left/16px 100% no-repeat,var(--adam-card-surface) !important;}.adam-digital-card__frame{display:none !important;}');
		await saveCapture(
			page,
			'frame-behind-content.png',
			'.adam-digital-card__frame{z-index:0 !important;}.adam-digital-card__header,.adam-digital-card__body,.adam-digital-card__details,.adam-digital-card__footer{z-index:2 !important;position:relative !important;}'
		);
		await saveCapture(
			page,
			'frame-between-background-and-content.png',
			'.adam-digital-card__frame{z-index:1 !important;}.adam-digital-card__header,.adam-digital-card__body,.adam-digital-card__details,.adam-digital-card__footer{z-index:2 !important;position:relative !important;}'
		);
	} finally {
		await context.close();
		await browser.close();
	}
}

run().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
