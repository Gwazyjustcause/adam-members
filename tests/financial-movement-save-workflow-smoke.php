<?php
/** Regression checks for save-only financial data and explicit Google sync. */

declare(strict_types=1);

function adam_save_workflow_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$admin = (string) file_get_contents( __DIR__ . '/../src/Admin/AdminController.php' );
$sync  = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$plugin = (string) file_get_contents( __DIR__ . '/../src/Core/Plugin.php' );
$start = strpos( $admin, 'public function handle_save_google_sheets_payment' );
$end   = strpos( $admin, '/** Test the configured Google Sheets connection', $start );
$save  = false !== $start && false !== $end ? substr( $admin, $start, $end - $start ) : '';

adam_save_workflow_assert( '' !== $save, 'Payment save handler exists.' );
adam_save_workflow_assert( str_contains( $save, 'ensure_registration_movement' ) && str_contains( $save, 'ensure_renewal_movement' ), 'Registration and renewal saves persist a paid movement.' );
adam_save_workflow_assert( str_contains( $save, '$financial = array' ) && str_contains( $sync, '$financial[\'membership_year\']' ) && str_contains( $sync, '$financial[\'payment_method\']' ), 'Validated POST values are passed directly into the persisted movement.' );
adam_save_workflow_assert( str_contains( $save, "'financial_status' => 'paid'" ), 'Manual edits mark financial data as paid.' );
adam_save_workflow_assert( ! str_contains( $save, 'sync_registration(' ) && ! str_contains( $save, 'sync_renewal(' ) && ! str_contains( $save, 'sync_apd_association(' ) && ! str_contains( $save, 'sync_manual(' ), 'Saving payment data does not automatically synchronize Google Sheets.' );
adam_save_workflow_assert( str_contains( $sync, "'financial_status' => 'paid'" ) && str_contains( $sync, "'google_state' => 'pending'" ), 'New saved movements start as Pago plus Google Pendente.' );
adam_save_workflow_assert( str_contains( $admin, 'Dados de pagamento por preencher' ) && ! str_contains( $admin, 'Pendente de confirmação financeira' ), 'The extra financial-confirmation state is absent from the admin UI.' );
adam_save_workflow_assert( str_contains( $admin, '$data[\'membership_year\'] = $persisted_movement->membership_year()' ) && str_contains( $admin, '$data[\'payment_method\'] = $persisted_movement->payment_method()' ), 'Reloaded forms use persisted FinancialMovement values as their source of truth.' );
adam_save_workflow_assert( str_contains( $admin, 'Repetir sincronização' ), 'Explicit synchronization remains available after save.' );
adam_save_workflow_assert( ! str_contains( $plugin, '$google_sheets_sync->sync_registration' ) && ! str_contains( $plugin, '$google_sheets_sync->sync_renewal' ) && ! str_contains( $plugin, '$google_sheets_sync->sync_apd_association' ), 'Approval hooks do not bypass the explicit synchronization action.' );

echo "Financial movement save workflow smoke tests passed.\n";
