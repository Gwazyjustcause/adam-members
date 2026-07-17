const { chromium } = require('@playwright/test');
const path = require('path');
(async () => {
  const root = path.join(__dirname, '..');
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    storageState: path.join(root, 'playwright', '.auth', 'adam-user.json'),
    viewport: { width: 1440, height: 1400 },
    acceptDownloads: true,
  });
  const page = await context.newPage();
  await page.goto('https://airsoftmondego.pt/socio/', { waitUntil: 'networkidle' });
  const accept = page.getByRole('button', { name: 'Aceitar tudo' });
  if (await accept.count()) await accept.first().click().catch(() => {});
  await page.waitForSelector('.adam-digital-card__title', { state: 'visible' });
  const info = await page.evaluate(() => {
    const badge = document.querySelector('.adam-digital-card__title');
    const style = getComputedStyle(badge);
    const before = getComputedStyle(badge, '::before');
    const after = getComputedStyle(badge, '::after');
    return {
      html: badge.outerHTML,
      rect: badge.getBoundingClientRect(),
      style: {
        background: style.background,
        backgroundImage: style.backgroundImage,
        backgroundColor: style.backgroundColor,
        borderRadius: style.borderRadius,
        boxShadow: style.boxShadow,
        backdropFilter: style.backdropFilter,
        mixBlendMode: style.mixBlendMode,
        isolation: style.isolation,
        opacity: style.opacity,
      },
      before: {
        content: before.content,
        display: before.display,
        background: before.background,
        opacity: before.opacity,
      },
      after: {
        content: after.content,
        display: after.display,
        background: after.background,
        opacity: after.opacity,
      }
    };
  });
  console.log(JSON.stringify(info, null, 2));
  await context.close();
  await browser.close();
})();
