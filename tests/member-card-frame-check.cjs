// Verify decorations cannot paint over the frame in browser or PNG exports.
const { chromium, webkit } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '..');
const output = path.join(root, 'playwright/artifacts/member-frame');
fs.mkdirSync(output, { recursive: true });
const html = execFileSync('php', [path.join(__dirname, 'member-mobile-fixture.php')], { encoding: 'utf8' });

async function compare(page, before, after, thickness) {
  return page.evaluate(async ({ before, after, thickness }) => {
    async function pixels(source) {
      const image = new Image(); image.src = source; await image.decode();
      const canvas = document.createElement('canvas');
      canvas.width = image.width; canvas.height = image.height;
      const ctx = canvas.getContext('2d'); ctx.drawImage(image, 0, 0);
      return { width: image.width, height: image.height, data: ctx.getImageData(0, 0, image.width, image.height).data };
    }
    const a = await pixels(before), b = await pixels(after);
    const scale = a.width / 1024;
    let borderDifference = 0, interiorDifference = 0;
    function difference(x, y) {
      const i = (Math.floor(y) * a.width + Math.floor(x)) * 4;
      return Math.max(...[0, 1, 2, 3].map(c => Math.abs(a.data[i + c] - b.data[i + c])));
    }
    const edge = thickness * scale / 2;
    for (let x = Math.ceil(60 * scale); x < a.width - 60 * scale; x++) {
      borderDifference = Math.max(borderDifference, difference(x, edge), difference(x, a.height - edge));
    }
    for (let y = Math.ceil(60 * scale); y < a.height - 60 * scale; y++) {
      borderDifference = Math.max(borderDifference, difference(edge, y), difference(a.width - edge, y));
    }
    for (let x = Math.ceil(40 * scale); x < a.width - 40 * scale; x++) {
      interiorDifference = Math.max(interiorDifference, difference(x, 150 * scale));
    }
    return { borderDifference, interiorDifference };
  }, { before: 'data:image/png;base64,' + before.toString('base64'), after: 'data:image/png;base64,' + after.toString('base64'), thickness });
}

async function checkInnerCorners(page, png, thickness) {
  const corners = await page.evaluate(async ({ source, thickness }) => {
    const image = new Image(); image.src = source; await image.decode();
    const canvas = document.createElement('canvas'); canvas.width = image.width; canvas.height = image.height;
    const ctx = canvas.getContext('2d'); ctx.drawImage(image, 0, 0);
    const scale = image.width / 1024;
    // This point lies outside the rounded inner surface but inside the outer curve.
    // Four rectangular strips leave a square notch here instead of frame color.
    const inset = Math.round((thickness + (28 - thickness) * 0.2) * scale);
    return [[inset, inset], [image.width - inset - 1, inset],
      [inset, image.height - inset - 1], [image.width - inset - 1, image.height - inset - 1]]
      .map(([x, y]) => [...ctx.getImageData(x, y, 1, 1).data]);
  }, { source: 'data:image/png;base64,' + png.toString('base64'), thickness });
  for (const pixel of corners) {
    assert(pixel[0] > 225 && pixel[1] > 225 && pixel[2] < 30 && pixel[3] > 225,
      `Inner corner should continue the yellow frame: ${pixel}`);
  }
}

(async () => {
  for (const type of [chromium, webkit]) {
    const browser = await type.launch();
    try {
      for (const width of [390, 1440]) {
        const page = await browser.newPage({ viewport: { width, height: 1000 }, acceptDownloads: true });
        await page.route('https://member.test/', route => route.fulfill({ contentType: 'text/html', body: html }));
        await page.goto('https://member.test/');
        await page.addStyleTag({ path: path.join(root, 'assets/css/member-area.css') });
        await page.addScriptTag({ path: path.join(root, 'assets/vendor/html2canvas/html2canvas.min.js') });
        await page.addScriptTag({ path: path.join(root, 'assets/js/member-card-download.js') });
        for (const [frame, thickness, rotation] of [['simple', 4, 0], ['simple', 16, 45], ['metallic', 16, 135], ['gradient', 16, 315]]) {
          await page.evaluate(({ frame, thickness, rotation }) => {
            const card = document.querySelector('.adam-digital-card');
            card.classList.remove('adam-digital-card--frame-simple', 'adam-digital-card--frame-metallic', 'adam-digital-card--frame-gradient');
            card.classList.add('adam-digital-card--has-frame', 'adam-digital-card--frame-' + frame);
            Object.entries({ '--adam-frame-width': thickness + 'px', '--adam-frame-color': '#ffff00',
              '--adam-frame-highlight-color': '#ffffff', '--adam-frame-gradient-color-1': '#ffff00',
              '--adam-frame-gradient-color-2': '#ff8800', '--adam-frame-gradient-color-3': '#00ffff',
              '--adam-card-pattern-opacity': '1', '--adam-card-pattern-color': '#ff00ff',
              '--adam-card-pattern-rotation': rotation + 'deg', '--adam-card-shine': '#ff00ff' })
              .forEach(([key, value]) => card.style.setProperty(key, value));
            document.querySelector('.adam-digital-card__decoration').style.visibility = 'hidden';
          }, { frame, thickness, rotation });
          const card = page.locator('.adam-digital-card-viewport');
          const before = await card.screenshot();
          if (frame === 'simple' && width === 1440) await checkInnerCorners(page, before, thickness);
          await page.locator('.adam-digital-card__decoration').evaluate(el => el.style.visibility = 'visible');
          const after = await card.screenshot();
          const result = await compare(page, before, after, thickness);
          assert.equal(result.borderDifference, 0, `${type.name()} ${width} ${frame}: decoration covers border`);
          assert(result.interiorDifference > 30, 'Pattern must still be visible inside card');
          // The same clipping must be respected by the download renderer.
          async function download() {
            const pending = page.waitForEvent('download');
            await page.locator('[data-adam-card-download]').click();
            const item = await pending;
            return fs.readFileSync(await item.path());
          }
          const exported = await download();
          await page.locator('.adam-digital-card__decoration').evaluate(el => el.style.visibility = 'hidden');
          const plain = await download();
          if (frame === 'simple') await checkInnerCorners(page, plain, thickness);
          const png = await compare(page, plain, exported, thickness);
          assert.equal(png.borderDifference, 0, `${type.name()} ${width} ${frame}: exported decoration covers border`);
          if (width === 390 && frame === 'simple' && thickness === 16) fs.writeFileSync(path.join(output, `${type.name()}-mobile.png`), after);
          console.log(`PASS ${type.name()} ${width}px ${frame} ${thickness}px: screen and PNG frame unaffected by decorations`);
        }
        await page.close();
      }
    } finally { await browser.close(); }
  }
})().catch(error => { console.error(error); process.exitCode = 1; });

