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
foreach ( array( 'private_key', 'access_token', 'Authorization', 'client_secret' ) as $secret ) {
	adam_diagnostics_assert( ! str_contains( $block, $secret ), "Diagnostic context does not include {$secret}." );
}

echo "Google Sheets diagnostics smoke tests passed.\n";
