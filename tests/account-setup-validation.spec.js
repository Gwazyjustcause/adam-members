// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');

const validationScript = fs.readFileSync(
	__dirname + '/../assets/js/password-strength.js',
	'utf8'
);


async function loadPasswordForm( page, activation = true ) {
	const activationAttribute = activation ? ' data-adam-account-setup' : '';
	await page.setContent(`
		<form${activationAttribute}>
			<input id="adam_setup_username">
			<input id="new_password" type="password">
			<input id="confirm_password" type="password">
			<p id="password-strength-text"></p>
			<div id="adam-strength-bar"><span></span><span></span><span></span><span></span><span></span></div>
			<ul>
				<li id="rule-length"></li><li id="rule-lower"></li><li id="rule-upper"></li>
				<li id="rule-number"></li><li id="rule-symbol"></li>
			</ul>
			<p id="adam-account-setup-feedback"></p>
			<button type="submit">Concluir acesso</button>
		</form>
	`);
	await page.addScriptTag({ content: validationScript });
	await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
}

for ( const viewport of [ { width: 1280, height: 800 }, { width: 390, height: 844 } ] ) {
	test(`valid activation fields enable the button at ${viewport.width}px`, async ( { page } ) => {
		await page.setViewportSize( viewport );
		await loadPasswordForm( page );

		await page.locator('#adam_setup_username').fill('adam-test');
		await page.locator('#new_password').fill('ValidPass9!');
		await page.locator('#confirm_password').fill('ValidPass9!');

		await expect(page.getByRole('button', { name: 'Concluir acesso' })).toBeEnabled();
		await expect(page.locator('#adam-account-setup-feedback')).toHaveText(/Todos os dados visíveis estão válidos/);
	} );

	test(`invalid activation fields explain the blocker at ${viewport.width}px`, async ( { page } ) => {
		await page.setViewportSize( viewport );
		await loadPasswordForm( page );

		await page.locator('#adam_setup_username').fill('a');
		await page.locator('#new_password').fill('ValidPass9!');
		await page.locator('#confirm_password').fill('DifferentPass9!');

		await expect(page.getByRole('button', { name: 'Concluir acesso' })).toBeDisabled();
		await expect(page.locator('#adam-account-setup-feedback')).toHaveText(/pelo menos 4 caracteres/);
		await expect(page.locator('#adam-account-setup-feedback')).toHaveText(/não coincidem/);
	} );
}

test('password change/reset uses the displayed rules without a hidden score gate', async ( { page } ) => {
	await loadPasswordForm( page, false );

	await page.locator('#new_password').fill('ValidPass9!');
	await page.locator('#confirm_password').fill('ValidPass9!');

	await expect(page.getByRole('button', { name: 'Concluir acesso' })).toBeEnabled();
} );

test('password confirmation blocker is visible before the password is confirmed', async ( { page } ) => {
	await loadPasswordForm( page, false );

	await page.locator('#new_password').fill('ValidPass9!');

	await expect(page.getByRole('button', { name: 'Concluir acesso' })).toBeDisabled();
	await expect(page.locator('#adam-password-confirm-feedback')).toHaveText('Confirme a palavra-passe.');
} );
