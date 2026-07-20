const { chromium } = require('playwright');

async function run() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1728, height: 1100 } });
  const consoleMessages = [];
  const pageErrors = [];

  page.on('console', (message) => {
    consoleMessages.push({ type: message.type(), text: message.text() });
  });

  page.on('pageerror', (error) => {
    pageErrors.push({ message: error.message, stack: error.stack });
  });

  await page.goto('http://adam.local/wp-login.php', { waitUntil: 'networkidle' });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'codex1234!');
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
  await page.goto('http://adam.local/wp-admin/admin.php?page=adam-membership-reward-edit&reward_id=15', {
    waitUntil: 'networkidle',
  });

  const diagnostics = await page.evaluate(() => {
    const block = document.querySelector('input[name="catalog_visible"]')?.closest('.adam-admin-checkbox-field--catalog');
    const grid = document.querySelector('.adam-admin-edit-grid');
    const fieldLabels = Array.from(document.querySelectorAll('.adam-admin-edit-grid > label')).map((label) => ({
      text: label.querySelector('span') ? label.querySelector('span').textContent.trim() : label.textContent.trim(),
      className: label.className,
      hidden: label.classList.contains('is-hidden'),
      display: getComputedStyle(label).display,
    }));

    return {
      url: location.href,
      title: document.title,
      blockInsideGrid: !!(block && grid && block.parentElement === grid),
      styleControlsVisible: Array.from(document.querySelectorAll('[data-adam-style-controls]')).map((el) => ({
        hiddenClass: el.classList.contains('is-hidden'),
        display: getComputedStyle(el).display,
      })),
      backgroundControlsVisible: Array.from(document.querySelectorAll('[data-adam-background-controls]')).map((el) => ({
        hiddenClass: el.classList.contains('is-hidden'),
        display: getComputedStyle(el).display,
      })),
      titleControlsVisible: Array.from(document.querySelectorAll('[data-adam-title-badge-controls]')).map((el) => ({
        hiddenClass: el.classList.contains('is-hidden'),
        display: getComputedStyle(el).display,
      })),
      fieldLabels,
      jqueryPresent: typeof window.jQuery !== 'undefined',
      editorScriptPresent: !!Array.from(document.scripts).find((script) => script.src.includes('admin-reward-editor.js')),
      styleSectionHtml: document.querySelector('[data-adam-style-controls]')?.outerHTML?.slice(0, 4000) || null,
      backgroundSectionHtml: document.querySelector('[data-adam-background-controls]')?.outerHTML?.slice(0, 4000) || null,
      titleBadgeSectionHtml: document.querySelector('[data-adam-title-badge-controls]')?.outerHTML?.slice(0, 4000) || null,
      styleSheetHrefs: Array.from(document.styleSheets).map((sheet) => sheet.href).filter(Boolean),
      hiddenRulePresent: Array.from(document.styleSheets).some((sheet) => {
        try {
          return Array.from(sheet.cssRules || []).some((rule) => String(rule.cssText || '').includes('.adam-reward-editor .is-hidden'));
        } catch (error) {
          return false;
        }
      }),
      adminCssHiddenRules: Array.from(document.styleSheets).flatMap((sheet) => {
        try {
          if (!sheet.href || !sheet.href.includes('assets/css/admin.css')) {
            return [];
          }
          return Array.from(sheet.cssRules || [])
            .filter((rule) => String(rule.cssText || '').includes('is-hidden'))
            .map((rule) => rule.cssText);
        } catch (error) {
          return [];
        }
      }),
      styleSectionInsideEditor: !!document.querySelector('[data-adam-style-controls]')?.closest('.adam-reward-editor'),
      titleSectionInsideEditor: !!document.querySelector('[data-adam-title-badge-controls]')?.closest('.adam-reward-editor'),
      backgroundSectionInsideEditor: !!document.querySelector('[data-adam-background-controls]')?.closest('.adam-reward-editor'),
    };
  });

  await page.screenshot({
    path: 'playwright/artifacts/local-catalog-layout-check/reward-editor-local-debug.png',
    fullPage: true,
  });

  console.log(JSON.stringify({ diagnostics, consoleMessages, pageErrors }, null, 2));
  await browser.close();
}

run().catch((error) => {
  console.error(error);
  process.exit(1);
});
