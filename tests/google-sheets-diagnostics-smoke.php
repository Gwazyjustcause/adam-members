<?php
/** Isolated checks for safe Google Sheets failure diagnostics. */

declare(strict_types=1);

function adam_diagnostics_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$client = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsClient.php' );
$sync   = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$plugin = (string) file_get_contents( __DIR__ . '/../src/Core/Plugin.php' );
$admin  = (string) file_get_contents( __DIR__ . '/../src/Admin/AdminController.php' );
$start  = strpos( $client, 'private function log_diagnostic' );
$end    = strpos( $client, 'private function sanitize_diagnostic_message', $start );
$block  = false !== $start && false !== $end ? substr( $client, $start, $end - $start ) : '';

adam_diagnostics_assert( '' !== $block, 'Safe diagnostic logger exists.' );
foreach ( array( 'financial_sync', 'request_id', 'stage', 'exception_class', 'http_status', 'error_code', 'google_error_code', 'technical_message' ) as $field ) {
	adam_diagnostics_assert( str_contains( $block, "'{$field}'" ), "Diagnostic field {$field} is recorded." );
}
adam_diagnostics_assert( str_contains( $client, 'sanitize_diagnostic_message' ), 'Technical messages are sanitized before logging.' );
adam_diagnostics_assert( str_contains( $client, 'log_exception' ) && str_contains( $plugin, 'log_exception' ), 'Hook exceptions are recorded safely.' );
adam_diagnostics_assert( str_contains( $sync, "'read_values'" ) && str_contains( $sync, "'append_or_confirmation'" ), 'Synchronization failures identify their stage.' );
foreach ( array( 'sync_service_entered', 'read_values_started', 'read_values_completed', 'append_started', 'append_completed', 'metadata_read_started', 'metadata_read_completed', 'confirmation_started', 'confirmation_completed', 'Google Sheets diagnostics build loaded.', 'Google Sheets sheet-id-fix-v2 loaded.' ) as $marker ) {
	adam_diagnostics_assert( ! str_contains( $sync, $marker ) && ! str_contains( $client, $marker ) && ! str_contains( $plugin, $marker ), "Temporary trace {$marker} was removed." );
}
adam_diagnostics_assert( str_contains( $sync, 'catch ( \\Throwable $exception )' ), 'Unexpected sync exceptions are converted to retryable failures.' );
adam_diagnostics_assert( str_contains( $admin, 'catch ( \\Throwable $exception )' ) && str_contains( $admin, "'retry_handler'" ), 'Retry handler catches unexpected exceptions and redirects safely.' );
foreach ( array( 'financial_retry_post_received', 'financial_retry_handler_entered', 'financial_retry_validation_passed', 'financial_retry_sync_service_called', 'financial_retry_sync_service_returned' ) as $marker ) {
	adam_diagnostics_assert( str_contains( $admin, $marker ), "Retry marker {$marker} is present." );
}
adam_diagnostics_assert( str_contains( $admin, "admin_post_adam_membership_retry_google_sheets" ) && str_contains( $admin, 'adam_membership_retry_google_sheets_' ), 'Retry action and nonce remain connected to the canonical handler.' );
foreach ( array( 'private_key', 'access_token', 'Authorization', 'client_secret' ) as $secret ) {
	adam_diagnostics_assert( ! str_contains( $block, $secret ), "Diagnostic context does not include {$secret}." );
}

echo "Google Sheets diagnostics smoke tests passed.\n";
