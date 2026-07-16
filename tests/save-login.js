const { chromium } = require('@playwright/test');

(async () => {
    const browser = await chromium.launch({
        headless: false,
    });

    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto('https://airsoftmondego.pt/wp-login.php');

    console.log('Log in manually in the opened browser.');

    await page.waitForURL('**/wp-admin/**', {
        timeout: 5 * 60 * 1000,
    });

    await context.storageState({
        path: 'playwright/.auth/adam-user.json',
    });

    console.log('Login saved.');

    await browser.close();
})();