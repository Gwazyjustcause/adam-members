const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

const AUTH_FILE = path.join(__dirname, '..', 'playwright', '.auth', 'adam-user.json');
const OUTPUT_DIR = path.join(__dirname, '..', 'playwright', 'artifacts', 'member-card-png-check');
const CARD_CSS_FILE = path.join(__dirname, '..', 'assets', 'css', 'member-area.css');
const CARD_JS_FILE = path.join(__dirname, '..', 'assets', 'js', 'member-card-download.js');
const SYMBOL_CASES = [
	{ key: 'star', label: '⭐', symbol: '⭐' },
	{ key: 'trophy', label: '🏆', symbol: '🏆' },
	{ key: 'shield', label: '🛡️', symbol: '🛡️' },
	{ key: 'none', label: 'Sem símbolo', symbol: '' },
];

function ensureDir(dir) {
	fs.mkdirSync(dir, { recursive: true });
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

		const [screenshotImage, downloadImage] = await Promise.all([
			loadImage(screenshotDataUrl),
			loadImage(downloadDataUrl),
		]);

		const width = Math.min(screenshotImage.naturalWidth, downloadImage.naturalWidth, 360);
		const height = Math.min(
			Math.round((width / screenshotImage.naturalWidth) * screenshotImage.naturalHeight),
			Math.round((width / downloadImage.naturalWidth) * downloadImage.naturalHeight)
		);

		const screenshotCanvas = document.createElement('canvas');
		screenshotCanvas.width = width;
		screenshotCanvas.height = height;
		const screenshotContext = screenshotCanvas.getContext('2d');
		screenshotContext.drawImage(screenshotImage, 0, 0, width, height);

		const downloadCanvas = document.createElement('canvas');
		downloadCanvas.width = width;
		downloadCanvas.height = height;
		const downloadContext = downloadCanvas.getContext('2d');
		downloadContext.drawImage(downloadImage, 0, 0, width, height);

		const screenshotPixels = screenshotContext.getImageData(0, 0, width, height).data;
		const downloadPixels = downloadContext.getImageData(0, 0, width, height).data;

		let totalDiff = 0;
		let maxDiff = 0;

		for (let index = 0; index < screenshotPixels.length; index += 4) {
			const pixelDiff =
				Math.abs(screenshotPixels[index] - downloadPixels[index]) +
				Math.abs(screenshotPixels[index + 1] - downloadPixels[index + 1]) +
				Math.abs(screenshotPixels[index + 2] - downloadPixels[index + 2]) +
				Math.abs(screenshotPixels[index + 3] - downloadPixels[index + 3]);

			totalDiff += pixelDiff;
			maxDiff = Math.max(maxDiff, pixelDiff);
		}

		return {
			screenshot: {
				width: screenshotImage.naturalWidth,
				height: screenshotImage.naturalHeight,
			},
			download: {
				width: downloadImage.naturalWidth,
				height: downloadImage.naturalHeight,
			},
			diff: {
				sampleWidth: width,
				sampleHeight: height,
				averagePerPixel: totalDiff / (width * height),
				maxPerPixel: maxDiff,
			},
		};
	}, { screenshotDataUrl, downloadDataUrl });
}

async function applyBadgeSymbol(page, symbol) {
	return page.evaluate((nextSymbol) => {
		const badge = document.querySelector('.adam-digital-card [data-adam-card-title], .adam-digital-card .adam-digital-card__title');

		if (!badge) {
			throw new Error('Title badge not found on live member card');
		}

		let label = badge.querySelector('.adam-title-badge__label');

		if (!label) {
			label = badge.querySelector('[data-adam-card-title-text]');
		}

		if (!label) {
			throw new Error('Title badge label not found');
		}

		let symbolNode = badge.querySelector('.adam-title-badge__symbol');

		if (nextSymbol) {
			if (!symbolNode) {
				symbolNode = document.createElement('span');
				symbolNode.className = 'adam-title-badge__symbol';
				symbolNode.setAttribute('aria-hidden', 'true');
				badge.insertBefore(symbolNode, label);
			}

			symbolNode.textContent = nextSymbol;
		} else if (symbolNode) {
			symbolNode.remove();
		}

		return {
			symbolVisible: !!badge.querySelector('.adam-title-badge__symbol'),
			symbolText: badge.querySelector('.adam-title-badge__symbol') ? badge.querySelector('.adam-title-badge__symbol').textContent : '',
			badgeText: label.textContent || '',
		};
	}, symbol);
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
		viewport: { width: 1440, height: 1400 },
		deviceScaleFactor: 1,
	});

	const page = await context.newPage();

	try {
		await page.goto('https://airsoftmondego.pt/socio/', { waitUntil: 'networkidle' });
		await page.waitForSelector('.adam-digital-card', { state: 'visible', timeout: 30000 });

		const acceptCookies = page.getByRole('button', { name: 'Aceitar tudo' });

		if (await acceptCookies.count()) {
			await acceptCookies.first().click().catch(() => {});
		}

		await page.evaluate(async () => {
			if (document.fonts && document.fonts.ready) {
				await document.fonts.ready;
			}
		});

		await page.addStyleTag({ path: CARD_CSS_FILE });
		await page.addScriptTag({ path: CARD_JS_FILE });
		await page.waitForFunction(() => !!window.adamMemberCardDownloadApi);

		const diagnostics = await page.evaluate(() => {
			const card = document.querySelector('.adam-digital-card');
			const frame = card ? card.querySelector('.adam-digital-card__frame') : null;
			const outer = frame ? frame.querySelector('.adam-digital-card__frame-layer--outer') : null;
			const inner = frame ? frame.querySelector('.adam-digital-card__frame-layer--inner') : null;

			function pick(style, keys) {
				const output = {};

				keys.forEach((key) => {
					output[key] = style ? style.getPropertyValue(key) || style[key] || '' : '';
				});

				return output;
			}

			const cardStyle = card ? window.getComputedStyle(card) : null;
			const frameStyle = frame ? window.getComputedStyle(frame) : null;
			const outerStyle = outer ? window.getComputedStyle(outer) : null;
			const innerStyle = inner ? window.getComputedStyle(inner) : null;

			return {
				cardClasses: card ? card.className : '',
				frameChildCount: frame ? frame.children.length : 0,
				cardVars: pick(cardStyle, [
					'--adam-frame-width',
					'--adam-frame-color',
					'--adam-frame-highlight-color',
					'--adam-frame-gradient-color-1',
					'--adam-frame-gradient-color-2',
					'--adam-frame-gradient-color-3',
				]),
				cardStyles: pick(cardStyle, [
					'background',
					'backgroundImage',
					'backgroundColor',
					'borderTopWidth',
					'borderTopColor',
					'borderRightColor',
					'borderBottomColor',
					'borderLeftColor',
					'boxShadow',
				]),
				frameStyles: pick(frameStyle, [
					'display',
					'opacity',
					'visibility',
					'background',
					'boxShadow',
				]),
				outerStyles: pick(outerStyle, [
					'display',
					'opacity',
					'visibility',
					'background',
					'boxShadow',
					'border',
				]),
				innerStyles: pick(innerStyle, [
					'display',
					'opacity',
					'visibility',
					'background',
					'boxShadow',
					'border',
				]),
			};
		});

		const card = page.locator('.adam-digital-card').first();
		const caseResults = [];

		for (const symbolCase of SYMBOL_CASES) {
			const screenshotFile = path.join(OUTPUT_DIR, 'card-screenshot-' + symbolCase.key + '.png');
			const downloadFile = path.join(OUTPUT_DIR, 'card-download-' + symbolCase.key + '.png');
			const domState = await applyBadgeSymbol(page, symbolCase.symbol);
			const screenshotBuffer = await card.screenshot({ path: screenshotFile });

			const [download] = await Promise.all([
				page.waitForEvent('download', { timeout: 30000 }),
				page.evaluate(() => {
					const trigger = document.querySelector('[data-adam-card-download="png"]');

					if (!trigger || !window.adamMemberCardDownloadApi) {
						throw new Error('PNG download API or trigger not found');
					}

					return window.adamMemberCardDownloadApi.downloadFromButton(trigger);
				}),
			]);

			await download.saveAs(downloadFile);
			const downloadBuffer = fs.readFileSync(downloadFile);
			const comparison = await compareImages(page, screenshotBuffer, downloadBuffer);

			caseResults.push({
				case: symbolCase.label,
				key: symbolCase.key,
				domState,
				screenshotFile,
				downloadFile,
				comparison,
			});
		}

		console.log(JSON.stringify({
			url: page.url(),
			diagnostics,
			cases: caseResults,
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
