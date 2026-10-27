// Offline regression: real PHP markup, CSS, html2canvas and download handler.
// Run: node tests/member-mobile-check.cjs (PHP and Playwright browsers required).
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const { execFileSync } = require('child_process');
const { chromium, webkit } = require('@playwright/test');
const root = path.join(__dirname, '..');
const output = path.join(root, 'playwright/artifacts/member-mobile');
fs.mkdirSync(output, { recursive: true });
const html = execFileSync('php', [path.join(__dirname, 'member-mobile-fixture.php')], { encoding: 'utf8' });

(async () => {
  for (const browserType of [chromium, webkit]) {
    const browser = await browserType.launch();
    let referenceLayout;
    let referencePngSize;
    let referencePixels;
    try {
      for (const width of [320, 375, 390, 430, 640, 768, 820, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 900 }, acceptDownloads: true });
        await page.route('https://member.test/', route => route.fulfill({ contentType: 'text/html', body: html }));
        await page.goto('https://member.test/');
        await page.addStyleTag({ path: path.join(root, 'assets/css/member-area.css') });
        await page.addScriptTag({ path: path.join(root, 'assets/vendor/html2canvas/html2canvas.min.js') });
        await page.addScriptTag({ path: path.join(root, 'assets/js/member-card-download.js') });
        const dialogs = [];
        page.on('dialog', async dialog => { dialogs.push(dialog.message()); await dialog.dismiss(); });
        const bounds = await page.evaluate(() => {
          const card = document.querySelector('.adam-digital-card');
          const rect = card.getBoundingClientRect();
          const clipped = [...card.querySelectorAll('strong, small, img, .adam-digital-card__title')]
            .filter(el => el.getBoundingClientRect().width > 0)
            .filter(el => { const b = el.getBoundingClientRect(); return b.left < rect.left - 1 || b.right > rect.right + 1; })
            .map(el => el.className || el.tagName);
          const scale = rect.width / card.offsetWidth;
          const layout = ['header', 'body', 'photo', 'identity', 'qr', 'details', 'footer'].map(part => {
            const b = card.querySelector('.adam-digital-card__' + part).getBoundingClientRect();
            return [b.left - rect.left, b.top - rect.top, b.width, b.height].map(n => Math.round(n / scale));
          });
          return { display: getComputedStyle(card).display, left: rect.left, right: rect.right, height: rect.height, layout,
            overflow: document.documentElement.scrollWidth > innerWidth, clipped,
            qr: document.querySelector('.adam-digital-card__qr img').getBoundingClientRect().width / scale };
        });
        assert.notEqual(bounds.display, 'none');
        assert(bounds.height > 0 && bounds.left >= 0 && bounds.right <= width, JSON.stringify(bounds));
        assert.equal(bounds.overflow, false, `${browserType.name()} ${width}: horizontal overflow`);
        assert.deepEqual(bounds.clipped, [], `${browserType.name()} ${width}: clipped card content`);
        assert(bounds.qr >= 130, 'QR keeps its original resolution before scaling');
        if (!referenceLayout) referenceLayout = bounds.layout;
        assert.deepEqual(bounds.layout, referenceLayout, 'Every card element must keep its position and size before uniform scaling');
        const downloadPromise = page.waitForEvent('download');
        await page.locator('[data-adam-card-download]').click();
        const download = await downloadPromise;
        assert.equal(download.suggestedFilename(), 'cartao-adam-test.png');
        const filename = path.join(output, `${browserType.name()}-${width}.png`);
        await download.saveAs(filename);
        const png = fs.readFileSync(filename);
        const pngSize = [png.readUInt32BE(16), png.readUInt32BE(20)];

        // A round target in a square source must remain round in the portrait crop.
        const photoShape = await page.evaluate(async source => {
          const image = new Image();
          image.src = source;
          await image.decode();
          const canvas = document.createElement('canvas');
          canvas.width = image.width;
          canvas.height = image.height;
          const context = canvas.getContext('2d');
          context.drawImage(image, 0, 0);
          const data = context.getImageData(0, 0, canvas.width, canvas.height).data;
          let left = Infinity, top = Infinity, right = -1, bottom = -1;
          let logoLeft = Infinity, logoTop = Infinity, logoRight = -1, logoBottom = -1;
          for (let y = 0; y < canvas.height; y++) {
            for (let x = 0; x < canvas.width; x++) {
              const i = (y * canvas.width + x) * 4;
              if (data[i] > 220 && data[i + 1] < 70 && data[i + 2] > 140) {
                left = Math.min(left, x); right = Math.max(right, x);
                top = Math.min(top, y); bottom = Math.max(bottom, y);
              }
              if (data[i] < 40 && data[i + 1] > 140 && data[i + 2] > 220) {
                logoLeft = Math.min(logoLeft, x); logoRight = Math.max(logoRight, x);
                logoTop = Math.min(logoTop, y); logoBottom = Math.max(logoBottom, y);
              }
            }
          }
          const card = document.querySelector('.adam-digital-card');
          const rootBox = card.getBoundingClientRect();
          const badge = card.querySelector('.adam-digital-card__header > .adam-badge').getBoundingClientRect();
          const factor = 3 * card.offsetWidth / rootBox.width;
          const bx = (badge.left - rootBox.left) * factor, by = (badge.top - rootBox.top) * factor;
          const bw = badge.width * factor, bh = badge.height * factor;
          let inkLeft = Infinity, inkTop = Infinity, inkRight = -1, inkBottom = -1;
          for (let y = Math.ceil(by + 3); y < by + bh - 3; y++) {
            for (let x = Math.ceil(bx + 3); x < bx + bw - 3; x++) {
              const i = (y * canvas.width + x) * 4;
              if (data[i] < 80 && data[i + 1] < 130 && data[i + 2] < 110) {
                inkLeft = Math.min(inkLeft, x); inkRight = Math.max(inkRight, x);
                inkTop = Math.min(inkTop, y); inkBottom = Math.max(inkBottom, y);
              }
            }
          }
          return { width: right - left + 1, height: bottom - top + 1,
            logoWidth: logoRight - logoLeft + 1, logoHeight: logoBottom - logoTop + 1,
            badgeDx: (inkLeft + inkRight + 1) / 2 - bx - bw / 2,
            badgeDy: (inkTop + inkBottom + 1) / 2 - by - bh / 2 };
        }, 'data:image/png;base64,' + png.toString('base64'));
        assert(photoShape.width > 100 && photoShape.height > 100, 'Export must include the portrait');
        assert(Math.abs(photoShape.width / photoShape.height - 1) < 0.025,
          `Portrait distorted: circular target became ${photoShape.width}×${photoShape.height}`);
        assert(photoShape.logoWidth > 40 && photoShape.logoHeight > 40, 'Export must include the logo');
        assert(Math.abs(photoShape.logoWidth / photoShape.logoHeight - 1) < 0.025,
          `Logo distorted inside its padded box: circle became ${photoShape.logoWidth}×${photoShape.logoHeight}`);
        assert(Math.abs(photoShape.badgeDx) < 2 && Math.abs(photoShape.badgeDy) < 2,
          `Status text is off-center: x=${photoShape.badgeDx}, y=${photoShape.badgeDy} export pixels`);
        assert.equal(pngSize[0], 3072, 'PNG always exports the full 1024px canvas at 3x');
        if (!referencePngSize) referencePngSize = pngSize;
        assert.deepEqual(pngSize, referencePngSize, 'PNG dimensions must not change with the screen width');
        const pixels = await page.evaluate(async source => {
          const image = new Image();
          image.src = source;
          await image.decode();
          const canvas = document.createElement('canvas');
          canvas.width = 128;
          canvas.height = Math.round(128 * image.height / image.width);
          const context = canvas.getContext('2d');
          context.drawImage(image, 0, 0, canvas.width, canvas.height);
          return [...context.getImageData(0, 0, canvas.width, canvas.height).data];
        }, 'data:image/png;base64,' + png.toString('base64'));
        if (!referencePixels) referencePixels = pixels;
        const pixelDifference = pixels.reduce((sum, value, index) => sum + Math.abs(value - referencePixels[index]), 0) / pixels.length;
        assert(pixelDifference < 2, `PNG appearance changed with viewport: average pixel difference ${pixelDifference}`);
        assert.deepEqual(dialogs, [], 'Download must succeed without an error alert');
        if ([390, 1440].includes(width)) {
          await page.screenshot({ path: path.join(output, `${browserType.name()}-${width}-page.png`), fullPage: true });
          await page.locator('.adam-digital-card-viewport').screenshot({ path: path.join(output, `${browserType.name()}-${width}-card.png`) });
        }
        if (width === 390) {
          const beforeResize = await page.locator('.adam-digital-card').boundingBox();
          await page.setViewportSize({ width: 844, height: 390 });
          await page.waitForFunction(() => {
            const viewport = document.querySelector('.adam-digital-card-viewport');
            return Math.abs(viewport.clientWidth - viewport.firstElementChild.getBoundingClientRect().width) < 1;
          });
          await page.setViewportSize({ width: 390, height: 844 });
          await page.waitForFunction(expected => Math.abs(document.querySelector('.adam-digital-card').getBoundingClientRect().width - expected) < 1, beforeResize.width);
        }
        console.log(`PASS ${browserType.name()} ${width}px: unchanged layout, no clipping, PNG ${pngSize.join('×')}`);
        await page.close();
      }
    } finally { await browser.close(); }
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
