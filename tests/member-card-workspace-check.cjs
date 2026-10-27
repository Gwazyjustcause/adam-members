const { chromium, webkit } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '..');
const output = path.join(root, 'playwright/artifacts/dashboard');
const css = fs.readFileSync(path.join(root, 'assets/css/member-area.css'), 'utf8');
const card = execFileSync('php', [path.join(__dirname, 'member-mobile-fixture.php')], { encoding: 'utf8' })
  .match(/<section class="adam-card adam-digital-card-section"[\s\S]*?<\/section>/)[0];
const html = execFileSync('php', [path.join(__dirname, 'member-dashboard-fixture.php'), 'active'], { encoding: 'utf8' })
  .replace(/<section class="adam-card adam-digital-card-section"[\s\S]*?<\/section>/, card);
const script = path.join(root, 'assets/js/member-card-download.js');
const library = path.join(root, 'assets/vendor/html2canvas/html2canvas.min.js');
fs.mkdirSync(output, { recursive: true });

async function setup(page, dark) {
  page.setDefaultTimeout(10000);
  await page.setContent(html);
  await page.addStyleTag({ content: css });
  if (dark) {
    await page.evaluate(() => document.body.classList.add('adam-theme-dark'));
    await page.addStyleTag({ path: path.join(__dirname, 'member-dashboard-dark-theme.css') });
    for (const name of ['variables', 'ui', 'components', 'inline-theme']) {
      const file = path.join(root, `playwright/artifacts/production-${name}.css`);
      if (fs.existsSync(file)) await page.addStyleTag({ path: file });
    }
    // Exercise the production theme's automatic classification contract too.
    await page.locator('.adam-card-customizer').evaluate(el => el.setAttribute('data-adam-night-component', 'form'));
  }
  await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important}' });
  await page.locator('.adam-digital-card__identity > strong').evaluate(el => el.textContent = 'ADMINADAM');
}

async function settle(page) {
  await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

(async () => {
  for (const engine of [chromium, webkit]) {
    const browser = await engine.launch();
    try {
      for (const dark of [false, true]) {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        await setup(page, dark);
        assert.equal(await page.locator('.adam-digital-card').evaluate(el => getComputedStyle(el).visibility), 'hidden',
          'The temporary HTML card must be hidden before the renderer script even starts');
        await page.addScriptTag({ path: library });
        await page.evaluate(() => {
          const capture = window.html2canvas;
          const gate = new Promise(resolve => window.releaseCardCapture = resolve);
          window.html2canvas = async (...args) => { await gate; return capture(...args); };
        });
        await page.addScriptTag({ path: script });
        assert(await page.locator('.adam-digital-card-loading').isVisible());
        assert.equal(await page.locator('.adam-digital-card__header > .adam-badge').evaluate(el => getComputedStyle(el).visibility), 'hidden',
          'ATIVO must not appear in its intermediate position during slow capture');
        await page.evaluate(() => window.releaseCardCapture());
        await page.evaluate(() => window.adamMemberCardDownloadApi.preparePreviews());
        assert.equal(await page.locator('.adam-digital-card-viewport').getAttribute('aria-busy'), null);
        assert.equal(await page.locator('.adam-digital-card-loading').isVisible(), false);
        const preview = await page.locator('.adam-digital-card-rendered').getAttribute('src');
        const cases = [[320,100],[390,100],[768,100],[900,100],[901,100],[1024,100],[1280,100],
          [1440,80],[1440,100],[1440,125],[1440,150],[1440,200],[1920,80],[1920,100],[1920,150],[1920,200],[2560,100]];
        for (const [screen, zoom] of cases) {
          // Browser zoom changes the CSS viewport to physical width / zoom.
          const width = Math.round(screen * 100 / zoom);
          await page.setViewportSize({ width, height: Math.round(1000 * 100 / zoom) });
          await settle(page);
          const boxes = await page.evaluate(() => {
            const rect = selector => document.querySelector(selector).getBoundingClientRect().toJSON();
            return { stage: rect('.adam-digital-card-stage'), image: rect('.adam-digital-card-rendered'),
              card: rect('.adam-digital-card-section'), controls: rect('#adam-personalizacao'),
              overflow: document.documentElement.scrollWidth > innerWidth,
              form: getComputedStyle(document.querySelector('.adam-card-customizer')).borderTopWidth,
              labels: [...document.querySelectorAll('.adam-card-customizer label')].map(el => getComputedStyle(el).borderTopWidth) };
          });
          assert(!boxes.overflow, `Page overflow at ${screen}px / ${zoom}%`);
          assert(Math.abs((boxes.stage.left + boxes.stage.right) - (boxes.image.left + boxes.image.right)) < 2, 'Card must be horizontally centered');
          assert(Math.abs((boxes.stage.top + boxes.stage.bottom) - (boxes.image.top + boxes.image.bottom)) < 2, 'Card must be vertically centered in its stage');
          if (width > 900) {
            assert(Math.abs(boxes.card.top - boxes.controls.top) < 1);
            assert(Math.abs(boxes.card.height - boxes.controls.height) < 1);
          } else assert(boxes.controls.top >= boxes.card.bottom, 'Narrow and zoomed layouts must stack');
          assert.equal(boxes.form, '0px', 'Theme must not add a second box around the form');
          assert(boxes.labels.every(border => border === '0px'), 'Labels must not become extra bordered panels');
          assert.equal(await page.locator('.adam-digital-card-rendered').getAttribute('src'), preview, 'Resizing should scale the existing image without rerendering ATIVO');
        }
        for (const width of [390, 1440]) {
          await page.setViewportSize({ width, height: 1000 }); await settle(page);
          await page.locator('.adam-card-workspace').screenshot({ path: path.join(output, `workspace-layout-${engine.name()}-${dark ? 'dark' : 'light'}-${width}.png`) });
        }
        console.log(`PASS ${engine.name()} ${dark ? 'dark' : 'light'}: delayed first render, centered card, 17 desktop/mobile/zoom viewports`);
        await page.close();
      }
      const fallback = await browser.newPage();
      await setup(fallback, false);
      // A missing capture dependency must leave a usable scaled HTML fallback.
      await fallback.addScriptTag({ path: script });
      await fallback.waitForFunction(() => !document.querySelector('.adam-digital-card-viewport').classList.contains('is-pending'));
      assert.equal(await fallback.locator('.adam-digital-card').evaluate(el => getComputedStyle(el).visibility), 'visible');
      assert.equal(await fallback.locator('.adam-digital-card-viewport').getAttribute('aria-busy'), null);
      await fallback.close();
      const nojs = await browser.newPage({ javaScriptEnabled: false });
      nojs.setDefaultTimeout(10000);
      await nojs.setContent(html.replace('<body', `<style>${css}</style><body`), { waitUntil: 'domcontentloaded' });
      assert.equal(await nojs.locator('.adam-digital-card').evaluate(el => getComputedStyle(el).visibility), 'visible');
      await nojs.close();
      console.log(`PASS ${engine.name()}: capture failure and JavaScript-disabled fallbacks`);
    } finally { await browser.close(); }
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
