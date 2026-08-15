// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');

const selectorScript = fs.readFileSync(
	__dirname + '/../assets/js/admin-correction-fields.js',
	'utf8'
);

async function loadRenewalCorrectionSelector(page) {
	await page.setContent(`
		<div data-adam-correction-selector>
			<form>
				<div class="adam-correction-field-picker">
					<button type="button" data-adam-correction-open>Selecionar campos...</button>
					<div data-adam-correction-summary hidden><strong data-adam-correction-count></strong><div data-adam-correction-chips></div><button type="button" data-adam-correction-open>Alterar seleção</button></div>
				</div>
				<dialog data-adam-correction-dialog>
					<label><input type="checkbox" name="correction_fields[]" value="telefone" data-adam-correction-option data-label="Telemóvel">Telemóvel</label>
					<label><input type="checkbox" name="correction_fields[]" value="payment_receipt" data-adam-correction-option data-label="Comprovativo de pagamento">Comprovativo de pagamento</label>
					<button type="button" data-adam-correction-close>Cancelar</button>
					<button type="button" data-adam-correction-apply>Aplicar seleção</button>
				</dialog>
				<button type="submit">Pedir correção</button>
			</form>
		</div>
	`);
	await page.addScriptTag({ content: selectorScript });
	await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
}

test('renewal correction selector opens, selects, reopens and blocks empty submission', async ({ page }) => {
	await loadRenewalCorrectionSelector(page);

	const open = page.getByRole('button', { name: 'Selecionar campos...' });
	const dialog = page.locator('[data-adam-correction-dialog]');
	await open.click();
	await expect(dialog).toHaveAttribute('open', '');

	await page.getByRole('checkbox', { name: 'Telemóvel' }).check();
	await page.getByRole('button', { name: 'Aplicar seleção' }).click();
	await expect(page.locator('[data-adam-correction-summary]')).toBeVisible();
	await expect(page.locator('[data-adam-correction-count]')).toHaveText('1 campo selecionado');
	await expect(page.locator('.adam-correction-chip')).toHaveText('Telemóvel×');

	await page.getByRole('button', { name: 'Alterar seleção' }).click();
	await expect(dialog).toHaveAttribute('open', '');
	await expect(page.getByRole('checkbox', { name: 'Telemóvel' })).toBeChecked();
	await page.getByRole('button', { name: 'Cancelar' }).click();
	await expect(dialog).not.toHaveAttribute('open', '');

	await page.getByRole('button', { name: 'Alterar seleção' }).click();
	await page.getByRole('checkbox', { name: 'Telemóvel' }).uncheck();
	await page.getByRole('button', { name: 'Aplicar seleção' }).click();
	await page.getByRole('button', { name: 'Pedir correção' }).click();
	await expect(page.locator('.adam-correction-selection-error')).toBeVisible();
});
