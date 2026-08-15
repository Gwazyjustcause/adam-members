/**
 * Install this as an on-edit trigger in the existing spreadsheet.
 * It changes only Responsável and Estado; all dropdowns and table structure
 * remain owned by the existing sheet.
 */
function onEdit(e) {
  if (!e || !e.range) return;
  const sheet = e.range.getSheet();
  if (sheet.getName() !== 'Gestão de Sócios' || e.range.getRow() < 2) return;

  const headers = sheet.getRange(1, 1, 1, 8).getValues()[0];
  const index = Object.fromEntries(headers.map((header, i) => [String(header).trim(), i]));
  const required = ['Responsável', 'Pagamento', 'ANA', 'Fatura', 'Estado'];
  if (required.some((header) => index[header] === undefined)) return;
  if (e.range.getColumn() < 1 || e.range.getColumn() > 8) return;

  const rowNumber = e.range.getRow();
  const row = sheet.getRange(rowNumber, 1, 1, 8).getValues()[0];
  const value = (header) => String(row[index[header]] || '').trim();
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

  sheet.getRange(rowNumber, index['Responsável'] + 1).setValue(responsible);
  sheet.getRange(rowNumber, index['Estado'] + 1).setValue(state);
}
