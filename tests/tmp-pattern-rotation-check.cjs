const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const AUTH = path.join(process.cwd(), 'playwright', '.auth', 'adam-user.json');
const OUT = path.join(process.cwd(), 'playwright', 'artifacts', 'pattern-rotation-check');
const URL = 'https://airsoftmondego.pt/wp-admin/admin.php?page=adam-membership-reward-edit&reward_id=15';
const PREVIEW_SELECTOR = '[data-adam-card-preview-panel] .adam-digital-card';

const CASES = [
  { pattern: 'dots', angles: [0, 23, 45, 90] },
  { pattern: 'grid', angles: [0, 23, 45, 90] },
  { pattern: 'diagonal', angles: [0, 23, 45, 90] },
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ storageState: AUTH, viewport: { width: 1600, height: 1400 } });
  const page = await context.newPage();
  await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForSelector(PREVIEW_SELECTOR, { timeout: 60000 });

  async function setControl(selector, value) {
    const ok = await page.evaluate(({ selector, value }) => {
      const target = document.querySelector(selector);
      if (!target) return false;
      target.value = String(value);
      target.dispatchEvent(new Event('input', { bubbles: true }));
      target.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }, { selector, value });
    if (!ok) throw new Error('Control not found for ' + selector);
  }

  async function capture(label) {
    await page.waitForTimeout(500);
    const shot = path.join(OUT, label + '.png');
    await page.locator(PREVIEW_SELECTOR).screenshot({ path: shot });
    const computed = await page.evaluate(() => {
      const card = document.querySelector('[data-adam-card-preview-panel] .adam-digital-card');
      const pattern = document.querySelector('.adam-digital-card__pattern');
      const style = pattern ? getComputedStyle(pattern) : null;
      const cardRect = card ? card.getBoundingClientRect() : null;
      const rect = pattern ? pattern.getBoundingClientRect() : null;
      return {
        patternClass: pattern ? pattern.className : null,
        backgroundPosition: style ? style.backgroundPosition : null,
        transform: style ? style.transform : null,
        width: style ? style.width : null,
        height: style ? style.height : null,
        cardRect: cardRect ? cardRect.toJSON() : null,
        patternRect: rect ? rect.toJSON() : null,
      };
    });
    return { shot, computed };
  }

  const results = [];
  for (const c of CASES) {
    await setControl('select[name="visual_style[pattern]"]', c.pattern);
    await setControl('input[name="visual_style[pattern_color]"]', '#ffffff');
    await setControl('input[name="visual_style[pattern_scale]"]', 24);
    await setControl('input[name="visual_style[pattern_density]"]', c.pattern === 'diagonal' ? 3 : 2);
    await setControl('input[name="visual_style[pattern_spacing]"]', 24);
    await setControl('input[name="visual_style[pattern_opacity]"]', 100);
    for (const angle of c.angles) {
      await setControl('input[name="visual_style[pattern_rotation]"]', angle);
      results.push({ pattern: c.pattern, angle, ...(await capture(`${c.pattern}-${angle}`)) });
    }
  }

  fs.writeFileSync(path.join(OUT, 'rotation-results.json'), JSON.stringify(results, null, 2));
  console.log(JSON.stringify({ out: OUT, count: results.length }, null, 2));
  await browser.close();
})();
