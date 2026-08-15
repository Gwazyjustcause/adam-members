<?php
/** Regression checks for retry controls across all FinancialMovement sources. */

declare(strict_types=1);

function adam_retry_ui_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$admin = (string) file_get_contents( __DIR__ . '/../src/Admin/AdminController.php' );
$sync  = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );

$retry_start = strpos( $admin, 'public function handle_retry_google_sheets' );
$retry_end   = strpos( $admin, '/** Save payment data required', $retry_start );
$retry_code  = false !== $retry_start && false !== $retry_end ? substr( $admin, $retry_start, $retry_end - $retry_start ) : '';

adam_retry_ui_assert( '' !== $retry_code, 'Canonical retry handler is present.' );
foreach ( array( 'registration', 'renewal', 'apd', 'manual' ) as $source ) {
	adam_retry_ui_assert( str_contains( $retry_code, "'{$source}'" ), "{$source} retry is routed by source." );
}
adam_retry_ui_assert( 4 === substr_count( $retry_code, 'sync_persisted_movement' ), 'All four sources prefer the persisted movement synchronization path.' );
adam_retry_ui_assert( str_contains( $retry_code, 'find( $movement_id )' ) && str_contains( $retry_code, 'find( $request->request_uuid() )' ) && str_contains( $retry_code, 'find( $apd_request->request_uuid() )' ), 'Registration, renewal and APD retries resolve the existing canonical movement ID.' );
adam_retry_ui_assert( str_contains( $sync, 'public function sync_persisted_movement' ) && str_contains( $sync, 'return $this->sync_persisted_movement' ), 'Persisted retries do not need to rerun the originating workflow.' );
adam_retry_ui_assert( ! str_contains( $retry_code, 'approve(' ) && ! str_contains( $retry_code, 'send_' ), 'Retry does not repeat approval or email side effects.' );

adam_retry_ui_assert( str_contains( $admin, "'pending' => 'Pendente'" ) && str_contains( $admin, "'failed' => 'Falhou'" ), 'Pending and failed states are represented in the panels.' );
adam_retry_ui_assert( str_contains( $admin, 'null !== $persisted_movement || in_array( $sync_state, array( \'pending\', \'failed\' ), true )' ) && str_contains( $admin, 'Repetir sincronização' ), 'A pending/failed movement panel renders the retry action even before workflow approval.' );
adam_retry_ui_assert( str_contains( $admin, "'movement_id'" ) || str_contains( $sync, "'movement_id'" ), 'Retry preserves the canonical movement ID.' );

echo "Financial movement retry UI smoke tests passed.\n";
