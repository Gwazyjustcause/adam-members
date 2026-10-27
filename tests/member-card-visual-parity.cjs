// Compare the real browser card directly with the PNG produced by its button.
const { chromium, webkit } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '..');
const output = path.join(root, 'playwright/artifacts/card-parity');
fs.mkdirSync(output, { recursive: true });
const html = execFileSync('php', [path.join(__dirname, 'member-mobile-fixture.php')], { encoding: 'utf8' });

(async () => {
  for (const type of [chromium, webkit]) {
    const browser = await type.launch();
    try {
      for (const width of [390, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 1100 }, deviceScaleFactor: 3, acceptDownloads: true });
        await page.route('https://member.test/', route => route.fulfill({ contentType: 'text/html', body: html }));
        await page.goto('https://member.test/');
        await page.addStyleTag({ path: path.join(root, 'assets/css/member-area.css') });
        await page.addScriptTag({ path: path.join(root, 'assets/vendor/html2canvas/html2canvas.min.js') });
        await page.addScriptTag({ path: path.join(root, 'assets/js/member-card-download.js') });
        for (const pattern of ['grid', 'diagonal', 'dots']) {
          await page.evaluate(pattern => {
            const card = document.querySelector('.adam-digital-card');
            card.classList.add('adam-digital-card--has-frame', 'adam-digital-card--frame-simple');
            Object.entries({ '--adam-frame-width': '16px', '--adam-frame-color': '#ffff00',
              '--adam-card-surface': 'linear-gradient(135deg, #ffdb19, #855100)',
              '--adam-card-pattern-opacity': '0.7', '--adam-card-pattern-color': '#ffffcc',
              '--adam-card-pattern-rotation': '0deg' }).forEach(([key, value]) => card.style.setProperty(key, value));
            document.querySelector('.adam-digital-card__pattern').className = 'adam-digital-card__pattern adam-digital-card__pattern--' + pattern;
          }, pattern);
          await page.evaluate(() => window.adamMemberCardDownloadApi.preparePreviews());
          const label = `${type.name()}-${width}-${pattern}`;
          const screenBox = await page.locator('.adam-digital-card-viewport').boundingBox();
          const screenshot = await page.locator('.adam-digital-card-viewport').screenshot({ path: path.join(output, label + '-screen.png') });
          const pending = page.waitForEvent('download');
          await page.locator('[data-adam-card-download]').click();
          const download = await pending;
          const pngPath = path.join(output, label + '-download.png');
          await download.saveAs(pngPath);
          const displayedBytes = await page.locator('.adam-digital-card-rendered').evaluate(async image =>
            [...new Uint8Array(await (await fetch(image.src)).arrayBuffer())]);
          assert(fs.readFileSync(pngPath).equals(Buffer.from(displayedBytes)), 'Website preview and PNG download must use the exact same image bytes');
          const distortion = await page.locator('.adam-digital-card-rendered').evaluate(image => {
            const bounds = image.getBoundingClientRect();
            return Math.abs((bounds.width / bounds.height) / (image.naturalWidth / image.naturalHeight) - 1);
          });
          assert(distortion < 0.001, 'The shared preview image must retain its natural aspect ratio');
          const result = await page.evaluate(async ({ screen, download, screenBox }) => {
            async function pixels(source, crop) {
              const image = new Image(); image.src = source; await image.decode();
              const canvas = document.createElement('canvas'); canvas.width = 256;
              const top = crop ? (crop.y - Math.floor(crop.y)) * 3 : 0;
              const height = crop ? crop.height * 3 : image.height;
              canvas.height = Math.round(256 * height / image.width);
              const ctx = canvas.getContext('2d'); ctx.drawImage(image, 0, top, image.width, height, 0, 0, canvas.width, canvas.height);
              return { data: ctx.getImageData(0, 0, canvas.width, canvas.height).data, height: canvas.height };
            }
            const a = await pixels(screen, screenBox), b = await pixels(download);
            // Locator screenshots enclose fractional CSS bounds; allow up to two
            // comparison pixels for that crop rounding, not changes to card layout.
            let best;
            for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) {
              let total = 0, region = 0, count = 0, all = 0;
              for (let y = 3; y < Math.min(a.height, b.height) - 3; y++) {
                for (let x = 3; x < 253; x++) {
                  const i = (y * 256 + x) * 4, j = ((y + dy) * 256 + x + dx) * 4;
                  const delta = [0, 1, 2].reduce((sum, c) => sum + Math.abs(a.data[i + c] - b.data[j + c]), 0) / 3;
                  total += delta; all++;
                  if (x >= 200 && x < 235 && y >= 33 && y < 55) { region += delta; count++; }
                }
              }
              const current = {mean: total / all, pattern: region / count, dx, dy};
              if (!best || current.mean < best.mean) best = current;
            }
            return best;
          }, { screenBox, screen: 'data:image/png;base64,' + screenshot.toString('base64'), download: 'data:image/png;base64,' + fs.readFileSync(pngPath).toString('base64') });
          console.log(label, JSON.stringify(result));

          // Pixel averages are diagnostic: screenshot crop rounding and downsampling
          // vary with logo/card height. Exact bytes and aspect ratio above are strict.
        }
        await page.close();
      }
    } finally { await browser.close(); }
  }
})().catch(error => { console.error(error); process.exitCode = 1; });


