const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const root = process.cwd();
  const authPath = path.join(root, 'playwright', '.auth', 'adam-user.json');
  const outDir = path.join(root, 'playwright', 'artifacts', 'pattern-minimal-diagnostic');
  fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    storageState: authPath,
    viewport: { width: 1600, height: 1400 }
  });
  const page = await context.newPage();
  const url = 'https://airsoftmondego.pt/wp-admin/admin.php?page=adam-membership-reward-edit&reward_id=15';
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });

  const preview = page.locator('.adam-reward-editor__preview-stage');
  await preview.waitFor({ state: 'visible', timeout: 60000 });

  // Test 1: background-position: 0 0
  await page.addStyleTag({ content: '.adam-digital-card__pattern { background-position: 0 0 !important; }' });
  await page.waitForTimeout(800);
  const shot1 = path.join(outDir, '01-background-position-0-0.png');
  await preview.screenshot({ path: shot1 });

  // Reset page to remove temporary override.
  await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
  const preview2 = page.locator('.adam-reward-editor__preview-stage');
  await preview2.waitFor({ state: 'visible', timeout: 60000 });

  // Test 2: width/height 2000px
  await page.addStyleTag({ content: '.adam-digital-card__pattern { width: 2000px !important; height: 2000px !important; }' });
  await page.waitForTimeout(800);
  const shot2 = path.join(outDir, '02-pattern-size-2000.png');
  await preview2.screenshot({ path: shot2 });

  console.log(JSON.stringify({ url: page.url(), shot1, shot2 }, null, 2));
  await browser.close();
})();
