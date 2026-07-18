const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const root = process.cwd();
  const authPath = path.join(root, 'playwright', '.auth', 'adam-user.json');
  const outDir = path.join(root, 'playwright', 'artifacts', 'pattern-branch-test');
  fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ storageState: authPath, viewport: { width: 1600, height: 1400 } });
  const page = await context.newPage();
  await page.goto('https://airsoftmondego.pt/wp-admin/admin.php?page=adam-membership-reward-edit&reward_id=15', { waitUntil: 'domcontentloaded', timeout: 60000 });
  const preview = page.locator('.adam-reward-editor__preview-stage');
  await preview.waitFor({ state: 'visible', timeout: 60000 });
  await page.addStyleTag({ content: '.adam-digital-card__pattern { background-image: radial-gradient(circle, red 2px, transparent 2px) !important; background-size: 40px 40px !important; background-repeat: repeat !important; }' });
  await page.waitForTimeout(1200);
  const outPath = path.join(outDir, 'simple-red-dots.png');
  await preview.screenshot({ path: outPath });
  const data = await page.evaluate(() => {
    const pattern = document.querySelector('.adam-digital-card__pattern');
    const style = pattern ? getComputedStyle(pattern) : null;
    return pattern && style ? {
      backgroundImage: style.backgroundImage,
      backgroundSize: style.backgroundSize,
      backgroundRepeat: style.backgroundRepeat,
      rect: pattern.getBoundingClientRect().toJSON()
    } : null;
  });
  console.log(JSON.stringify({ outPath, data }, null, 2));
  await browser.close();
})();
