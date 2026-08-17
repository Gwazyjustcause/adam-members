<?php
/** Contract checks for independent admin operations and payment persistence. */

declare(strict_types=1);

function adam_admin_separation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$admin = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );
$sync = (string) file_get_contents( dirname( __DIR__ ) . '/src/GoogleSheets/GoogleSheetsSyncService.php' );

adam_admin_separation_assert( str_contains( $admin, 'adam_membership_sync_gestao_socios' ) && str_contains( $admin, 'render_gestao_socios_panel' ), 'Gestão de Sócios must have its own admin operation and card.' );
adam_admin_separation_assert( str_contains( $admin, "array_key_exists( 'google_sheets_spreadsheet_id', \$_POST )" ) && str_contains( $admin, "array_key_exists( 'google_sheets_enabled', \$_POST )" ), 'Omitting one integration form field must preserve the saved value.' );
adam_admin_separation_assert( str_contains( $admin, 'Google Sheets — Gestão Financeira' ) && str_contains( $admin, 'Repetir sincronização — Gestão Financeira' ), 'Financial operations must be labelled separately.' );
adam_admin_separation_assert( str_contains( $admin, "if ( 'apd' === \$type )" ) && str_contains( $admin, 'adam_membership_save_google_sheets_payment_apd_' ), 'APD payment editing must be available independently of approval.' );
$retry_start = strpos( $admin, 'public function handle_retry_google_sheets' );
$retry_end = strpos( $admin, '/** Synchronize only the operational', $retry_start );
$retry = false !== $retry_start && false !== $retry_end ? substr( $admin, $retry_start, $retry_end - $retry_start ) : '';
adam_admin_separation_assert( ! str_contains( $retry, 'membership_workflow->sync_' ), 'Financial retry must not also synchronize Gestão de Sócios.' );
adam_admin_separation_assert( str_contains( $sync, '$existing = $this->movements->find( $request->request_uuid() )' ), 'Approval recovery must preserve an existing renewal/APD financial movement.' );
adam_admin_separation_assert( str_contains( $admin, "'payment_date' => \$date" ) && str_contains( $admin, 'Dados de pagamento inválidos:' ), 'Payment save must use current POST values and identify invalid fields.' );

echo "Admin separation smoke tests passed.\n";
