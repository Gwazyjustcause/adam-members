const { chromium } = require('playwright');

async function run() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ viewport: { width: 1728, height: 1100 } });

  await page.goto('http://adam.local/wp-login.php', { waitUntil: 'networkidle' });
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'codex1234!');
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');

  await page.goto('http://adam.local/wp-admin/admin.php?page=adam-membership-rewards', { waitUntil: 'networkidle' });

  const rewards = await page.evaluate(() =>
    Array.from(document.querySelectorAll('a[href*="page=adam-membership-reward-edit"]')).map((link) => ({
      href: link.href,
      text: link.textContent.trim(),
      row: link.closest('tr') ? link.closest('tr').innerText : '',
    }))
  );

  console.log(JSON.stringify(rewards, null, 2));
  await browser.close();
}

run().catch((error) => {
  console.error(error);
  process.exit(1);
});
