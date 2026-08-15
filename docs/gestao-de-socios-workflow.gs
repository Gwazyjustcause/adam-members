/**
 * Standalone Apps Script for the existing ADAM operational queue.
 *
 * Paste this file into the standalone "gestãosócios" Apps Script project.
 * It does not create or modify sheets, tables, dropdowns, or formatting.
 */
const GESTAO_SOCIOS_SPREADSHEET_ID = '1mz-VjjljBVgeHXIdxgrvp9nO7HAHzxmFqNpewKa756Q';
const GESTAO_SOCIOS_SHEET_NAME = 'Gestão de Sócios';
const GESTAO_SOCIOS_HANDLER = 'handleGestaoSociosEdit';

/**
 * Run once manually from the Apps Script editor. Re-running is safe.
 * This creates only an installable Sheets On edit trigger—never a clock trigger.
 */
function installGestaoSociosTrigger() {
  const spreadsheet = SpreadsheetApp.openById(GESTAO_SOCIOS_SPREADSHEET_ID);
  const triggers = ScriptApp.getProjectTriggers();
  const alreadyInstalled = triggers.some((trigger) =>
    trigger.getHandlerFunction() === GESTAO_SOCIOS_HANDLER &&
    trigger.getEventType() === ScriptApp.EventType.ON_EDIT &&
    trigger.getTriggerSource() === ScriptApp.TriggerSource.SPREADSHEETS &&
    trigger.getTriggerSourceId() === GESTAO_SOCIOS_SPREADSHEET_ID
  );

  if (!alreadyInstalled) {
    ScriptApp.newTrigger(GESTAO_SOCIOS_HANDLER)
      .forSpreadsheet(spreadsheet)
      .onEdit()
      .create();
  }
}

/** Remove only the workflow's target edit triggers so installation can be rebuilt. */
function removeGestaoSociosTriggers() {
  ScriptApp.getProjectTriggers().forEach((trigger) => {
    if (
      trigger.getHandlerFunction() === GESTAO_SOCIOS_HANDLER &&
      trigger.getEventType() === ScriptApp.EventType.ON_EDIT &&
      trigger.getTriggerSource() === ScriptApp.TriggerSource.SPREADSHEETS &&
      trigger.getTriggerSourceId() === GESTAO_SOCIOS_SPREADSHEET_ID
    ) {
      ScriptApp.deleteTrigger(trigger);
    }
  });
}

/** Installable Sheets On edit entry point. */
function handleGestaoSociosEdit(e) {
  if (!e || !e.range) return;

  const spreadsheet = e.source || e.range.getSheet().getParent();
  if (!spreadsheet || spreadsheet.getId() !== GESTAO_SOCIOS_SPREADSHEET_ID) return;

  const sheet = e.range.getSheet();
  if (sheet.getName() !== GESTAO_SOCIOS_SHEET_NAME || e.range.getRow() < 2) return;

  const firstColumn = e.range.getColumn();
  const lastColumn = firstColumn + e.range.getNumColumns() - 1;
  if (lastColumn < 1 || firstColumn > 8) return;

  const headers = sheet.getRange(1, 1, 1, 8).getValues()[0];
  const index = Object.fromEntries(headers.map((header, i) => [String(header).trim(), i]));
  const requiredHeaders = ['Responsável', 'Tipo de quota', 'Sócio', 'Pagamento', 'ANA', 'Fatura', 'Estado'];
  if (requiredHeaders.some((header) => index[header] === undefined)) return;

  const rowNumber = e.range.getRow();
  const row = sheet.getRange(rowNumber, 1, 1, 8).getValues()[0];
  const value = (header) => String(row[index[header]] || '').trim();

  // Empty or partially cleared rows are not workflow requests. Leave them
  // untouched so deleting a test row cannot recreate default workflow data.
  if (value('Sócio') === '' || value('Tipo de quota') === '') return;

  const currentState = value('Estado');
  if (currentState === 'Concluído' || currentState === 'Rejeitado') return;

  const payment = value('Pagamento');
  const ana = value('ANA');
  const invoice = value('Fatura');
  const anaRequired = ana !== 'Não aplicável';
  let responsible = 'Tesoureiro';
  let state = 'Por iniciar';

  if (payment === 'Problema') {
    responsible = 'Tesoureiro';
    state = 'Em processamento';
  } else if (ana === 'Problema') {
    responsible = 'ANA';
    state = 'Em processamento';
  } else if (payment !== 'Confirmado') {
    responsible = 'Tesoureiro';
    state = 'Por iniciar';
  } else if (anaRequired && ana === 'Enviado') {
    responsible = 'ANA';
    state = 'A aguardar';
  } else if (payment === 'Confirmado' && (ana === 'Confirmado' || !anaRequired) && (invoice === 'Disponível' || invoice === 'Entregue')) {
    responsible = 'Administração';
    state = 'Pronto';
  } else if (anaRequired && ana !== 'Confirmado') {
    responsible = 'ANA';
    state = 'Em processamento';
  } else {
    responsible = 'Tesoureiro';
    state = 'Em processamento';
  }

  // Installable triggers do not re-fire from script writes. These are the
  // only two columns this automation owns.
  sheet.getRange(rowNumber, index['Responsável'] + 1).setValue(responsible);
  sheet.getRange(rowNumber, index['Estado'] + 1).setValue(state);
}
