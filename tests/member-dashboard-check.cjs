const { chromium, webkit } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const assert = require('node:assert/strict');
const root = path.join(__dirname, '..');
const output = path.join(root, 'playwright/artifacts/dashboard');
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
          if (state === 'active') {
            await page.locator('.adam-dashboard-grid').evaluate((grid, card) => grid.insertAdjacentHTML('beforebegin', card), card);
            await page.addScriptTag({ path: path.join(root, 'assets/vendor/html2canvas/html2canvas.min.js') });
            await page.addScriptTag({ path: path.join(root, 'assets/js/member-card-download.js') });
            await page.evaluate(() => window.adamMemberCardDownloadApi.preparePreviews());
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
            await page.screenshot({ path: path.join(output, `active-${width}.png`), fullPage: true });
          }
          console.log(`PASS ${type.name()} ${state} ${width}px: compact header, correct status panels, preferences open/cancel/save`);
          await page.close();
        }
      }
    } finally { await browser.close(); }
  }
})().catch(error => { console.error(error); process.exitCode = 1; });

