const { chromium, webkit } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '..');
const output = path.join(root, 'playwright/artifacts/dashboard');
const dark = process.argv.includes('--dark');
const productionTheme = process.argv.includes('--production-theme');
fs.mkdirSync(output, { recursive: true });
const cardFixture = execFileSync('php', [path.join(__dirname, 'member-mobile-fixture.php')], { encoding: 'utf8' });
const card = cardFixture.match(/<section class="adam-card adam-digital-card-section"[\s\S]*?<\/section>/)[0];

(async () => {
  for (const type of [chromium, webkit]) {
    const browser = await type.launch();
    try {
      for (const state of ['active', 'pending', 'expired']) {
        const html = execFileSync('php', [path.join(__dirname, 'member-dashboard-fixture.php'), state], { encoding: 'utf8' });
        for (const width of (state === 'expired' ? [390, 1440] : [320, 390, 768, 1440])) {
          const page = await browser.newPage({ viewport: { width, height: 1000 } });
          await page.route('https://member.test/socio/', route => route.fulfill({ contentType: 'text/html', body: html }));
          const saves = [];
          await page.route('https://member.test/ajax', route => {
            saves.push(route.request().postData());
            return route.fulfill({ json: { success: true, data: { message: 'Preferências guardadas.' } } });
          });
          await page.goto('https://member.test/socio/');
          await page.addStyleTag({ path: path.join(root, 'assets/css/member-area.css') });
          if (dark) {
            await page.evaluate(() => {
              document.body.classList.add('adam-theme-dark');
              document.querySelector('.adam-member-hero').setAttribute('data-adam-night-component', 'hero');
            });
            await page.addStyleTag({ path: path.join(__dirname, 'member-dashboard-dark-theme.css') });
            if (productionTheme) {
              for (const filename of ['variables', 'ui', 'components', 'inline-theme']) {
                await page.addStyleTag({ path: path.join(root, `playwright/artifacts/production-${filename}.css`) });
              }
            }
            await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; animation: none !important; }' });
            const contrast = await page.evaluate(() => {
              function rgb(css) { return css.match(/[\d.]+/g).slice(0, 3).map(Number); }
              function luminance(color) { return color.map(v => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0); }
              function ratio(fg, bg) { const a = luminance(rgb(fg)), b = luminance(rgb(bg)); return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05); }
              const hero = getComputedStyle(document.querySelector('.adam-member-hero'));
              const heading = getComputedStyle(document.querySelector('.adam-member-hero__content h2'));
              const tools = document.querySelector('.adam-member-hero__tools');
              return { navBackground: getComputedStyle(tools).backgroundColor, heading: ratio(heading.color, hero.backgroundColor),
                buttons: [...tools.querySelectorAll('.adam-hero-tool')].map(button => {
                  const css = getComputedStyle(button); return ratio(css.color, css.backgroundColor);
                }) };
            });
            assert.equal(contrast.navBackground, 'rgba(0, 0, 0, 0)', 'Shortcut row must not inherit the site navigation background');
            assert(contrast.heading >= 7, `Dark heading contrast too low: ${contrast.heading}`);
            assert(contrast.buttons.every(value => value >= 4.5), `Dark button text contrast too low: ${contrast.buttons}`);
          }
          if (state === 'active') {
            await page.locator('.adam-card-workspace > .adam-digital-card-section').evaluate((section, card) => section.outerHTML = card, card);
            // The sample uses a fixed card theme, like a member's saved cosmetics.
            await page.locator('.adam-digital-card').evaluate(card => card.style.setProperty('--adam-card-surface', 'linear-gradient(135deg, #173f24, #102033)'));
            await page.addScriptTag({ path: path.join(root, 'assets/vendor/html2canvas/html2canvas.min.js') });
            await page.addScriptTag({ path: path.join(root, 'assets/js/member-card-download.js') });
            await page.evaluate(() => window.adamMemberCardDownloadApi.preparePreviews());
            const layout = await page.evaluate(() => {
              const card = document.querySelector('.adam-card-workspace > .adam-digital-card-section').getBoundingClientRect();
              const controls = document.querySelector('.adam-card-workspace > .adam-card-personalization-section').getBoundingClientRect();
              return { card: card.toJSON(), controls: controls.toJSON() };
            });
            if (width > 900) {
              assert(Math.abs(layout.card.top - layout.controls.top) < 1, 'Desktop card and personalization must share a row');
              assert(layout.controls.left >= layout.card.right, 'Personalization must sit to the right of the card');
              assert(Math.abs(layout.card.width - layout.controls.width) < 1, 'Both columns must have equal width');
              const pending = page.waitForEvent('download');
              await page.locator('[data-adam-card-download]').click();
              const download = await pending;
              const pngPath = path.join(output, `workspace-${type.name()}-${dark ? 'dark' : 'light'}.png`);
              await download.saveAs(pngPath);
              assert.equal(fs.readFileSync(pngPath).readUInt32BE(16), 3072, 'Compact preview must still download at full resolution');
              const background = await page.evaluate(async source => {
                const image = new Image(); image.src = source; await image.decode();
                const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
                const context = canvas.getContext('2d');
                context.drawImage(image, 3000, 1200, 1, 1, 0, 0, 1, 1);
                return [...context.getImageData(0, 0, 1, 1).data];
              }, 'data:image/png;base64,' + fs.readFileSync(pngPath).toString('base64'));
              assert(background[3] === 255 && background[0] < 80 && background[1] < 100 && background[2] < 110,
                `The compact card must retain its dark gradient surface: ${background}`);
            } else {
              assert(layout.controls.top >= layout.card.bottom, 'Phones must stack personalization below the card');
            }
            assert.equal(await page.locator('#adam-personalizacao select').count(), 3);
            await page.locator('select[name="active_card_theme"]').selectOption('theme-moss');
            assert.equal(await page.locator('select[name="active_card_theme"]').inputValue(), 'theme-moss');
          }
          await page.addScriptTag({ content: "window.adamCommunicationPreferences={ajaxUrl:'https://member.test/ajax',nonce:'fixture'};" });
          await page.addScriptTag({ path: path.join(root, 'assets/js/member-communication-preferences.js') });
          await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
          assert.equal(await page.locator('.adam-status-card').count(), state === 'active' ? 0 : 1);
          assert.equal(await page.locator('.adam-notifications-card').count(), state === 'active' ? 0 : 1);
          if (state === 'expired') assert((await page.getByRole('link', { name: 'Renovar quota', exact: true }).getAttribute('href')).includes('/renovar/'));
          assert.equal(await page.locator('[data-adam-communication-preferences-dialog]').count(), 1);
          assert.equal(await page.locator('.adam-member-hero .adam-hero-tool__count').textContent(), '2');
          assert((await page.getByRole('link', { name: /Centro de Avisos/ }).getAttribute('href')).includes('view=avisos'));
          const overflow = await page.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth,
            outside: [...document.querySelectorAll('body *')].filter(el => el.getBoundingClientRect().right > innerWidth + 1)
              .map(el => ({ tag: el.tagName, cls: el.className, right: el.getBoundingClientRect().right })).slice(0, 12) }));
          assert(overflow.scroll <= overflow.width, JSON.stringify(overflow));
          const open = page.getByRole('button', { name: 'Preferências de Comunicação', exact: true });
          await open.click();
          const dialog = page.getByRole('dialog');
          assert(await dialog.isVisible(), 'Header shortcut must open the settings dialog');
          const events = dialog.getByRole('checkbox', { name: 'Eventos', exact: true });
          await events.uncheck();
          await page.keyboard.press('Escape');
          await dialog.waitFor({ state: 'hidden', timeout: 5000 });
          assert(await open.evaluate(el => el === document.activeElement), 'Escape returns focus to the shortcut');
          await open.click();
          assert(await events.isChecked(), 'Cancel must discard unsaved changes');
          await events.uncheck();
          await dialog.getByRole('button', { name: 'Guardar Preferências' }).click();
          await dialog.getByRole('status').getByText('Preferências guardadas.').waitFor();
          assert.equal(saves.length, 1);
          assert(saves[0].includes('fixture') && saves[0].includes('adam_membership_save_communication_preferences'));
          await dialog.getByRole('button', { name: 'Cancelar', exact: true }).click();
          await open.click();
          assert.equal(await events.isChecked(), false, 'Saved preferences must persist when reopening the dialog');
          await page.keyboard.press('Escape');
          if (type === chromium && state === 'active' && [390, 1440].includes(width)) {
            await page.locator('.adam-member-hero__content h2').click();
            await page.screenshot({ path: path.join(output, `active-${dark ? 'dark-' : ''}${width}.png`), fullPage: true });
          }
          console.log(`PASS ${type.name()} ${state} ${width}px: compact header, correct status panels, preferences open/cancel/save`);
          await page.close();
        }
      }
    } finally { await browser.close(); }
  }
})().catch(error => { console.error(error); process.exitCode = 1; });

