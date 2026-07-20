const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

async function run() {
  const artifactDir = path.join(process.cwd(), 'playwright', 'artifacts', 'local-catalog-layout-check');
  fs.mkdirSync(artifactDir, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1728, height: 1100 } });

  await page.goto('http://adam.local/wp-login.php', { waitUntil: 'networkidle' });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'codex1234!');
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');

  await page.goto('http://adam.local/wp-admin/admin.php?page=adam-membership-reward-edit&reward_id=15', {
    waitUntil: 'networkidle',
  });

  await page.addStyleTag({
    content: `
      *, *::before, *::after {
        transition: none !important;
        animation: none !important;
      }
    `,
  });

  const pageState = {
    url: page.url(),
    title: await page.title(),
  };

  fs.writeFileSync(path.join(artifactDir, 'page-state.json'), JSON.stringify(pageState, null, 2));
  await page.screenshot({
    path: path.join(artifactDir, 'page-before-check.png'),
    fullPage: true,
  });

  await page.waitForSelector('input[name="catalog_visible"]', { state: 'visible', timeout: 15000 });
  await page.waitForSelector('.adam-admin-edit-grid', { state: 'visible', timeout: 15000 });

  const diagnostics = await page.evaluate(() => {
    const input = document.querySelector('input[name="catalog_visible"]');
    const block = input ? input.closest('.adam-admin-checkbox-field--catalog, .adam-admin-checkbox-field') : null;
    const grid = document.querySelector('.adam-admin-edit-grid');
    const availabilityField = Array.from(document.querySelectorAll('.adam-admin-field')).find((field) => {
      const label = field.querySelector('label');
      return label && label.textContent.toLowerCase().includes('disponibilidade');
    });
    const subtypeField = Array.from(document.querySelectorAll('.adam-admin-field')).find((field) => {
      const label = field.querySelector('label');
      return label && label.textContent.toLowerCase().includes('subtipo');
    });

    const fieldOrder = grid
      ? Array.from(grid.children).map((node, index) => ({
          index,
          tag: node.tagName,
          className: node.className,
          label: node.querySelector('label') ? node.querySelector('label').textContent.trim() : null,
          hasCatalog: !!node.querySelector('input[name="catalog_visible"]'),
        }))
      : [];

    return {
      inputCount: document.querySelectorAll('input[name="catalog_visible"]').length,
      blockInsideGrid: !!(block && grid && block.parentElement === grid),
      blockClass: block ? block.className : null,
      availabilityIndex: availabilityField && grid ? Array.from(grid.children).indexOf(availabilityField) : -1,
      subtypeIndex: subtypeField && grid ? Array.from(grid.children).indexOf(subtypeField) : -1,
      blockIndex: block && grid ? Array.from(grid.children).indexOf(block) : -1,
      oldFullWidthBlockPresent: !!document.querySelector('.adam-admin-editor__catalog-visibility'),
      fieldOrder,
    };
  });

  await page.screenshot({
    path: path.join(artifactDir, 'reward-editor-local.png'),
    fullPage: true,
  });

  fs.writeFileSync(path.join(artifactDir, 'diagnostics.json'), JSON.stringify(diagnostics, null, 2));

  console.log(JSON.stringify({ artifactDir, diagnostics }, null, 2));
  await browser.close();
}

run().catch((error) => {
  console.error(error);
  process.exit(1);
});
