<?php
/** Contract checks for both read-only Google Sheets admin-post handlers. */

declare(strict_types=1);

function adam_connection_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$admin  = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );
$client = (string) file_get_contents( dirname( __DIR__ ) . '/src/GoogleSheets/GoogleSheetsClient.php' );

adam_connection_test_assert( str_contains( $admin, "admin_post_adam_membership_test_google_sheets" ) && str_contains( $admin, "admin_post_adam_membership_test_gestao_google_sheets" ), 'Both connection-test actions must be registered.' );

$quotas_start = strpos( $admin, 'public function handle_test_google_sheets' );
$quotas_end   = strpos( $admin, '/** Test the separate Gestão', $quotas_start );
$quotas       = false !== $quotas_start && false !== $quotas_end ? substr( $admin, $quotas_start, $quotas_end - $quotas_start ) : '';
$gestao_start = strpos( $admin, 'public function handle_test_gestao_google_sheets' );
$gestao_end   = strpos( $admin, "\n\t/**", $gestao_start + 1 );
$gestao       = false !== $gestao_start && false !== $gestao_end ? substr( $admin, $gestao_start, $gestao_end - $gestao_start ) : '';

foreach ( array( 'Quotas' => $quotas, 'Gestão' => $gestao ) as $label => $handler ) {
	adam_connection_test_assert( '' !== $handler, "{$label} connection handler must be present." );
	adam_connection_test_assert( str_contains( $handler, 'try {' ) && str_contains( $handler, 'catch ( \\Throwable $exception )' ), "{$label} handler must catch unexpected Throwables." );
	adam_connection_test_assert( str_contains( $handler, 'is_wp_error( $result )' ) && str_contains( $handler, 'redirect_with_error' ), "{$label} handler must preserve WP_Error messages and redirect." );
	adam_connection_test_assert( str_contains( $handler, 'log_exception' ) && str_contains( $handler, 'Não foi possível testar' ), "{$label} handler must log and redirect unexpected failures safely." );
}

adam_connection_test_assert( str_contains( $quotas, "verify_admin_nonce( 'adam_membership_test_google_sheets' )" ) && str_contains( $quotas, 'test_connection()' ), 'Quotas must retain its original nonce and client test call.' );
adam_connection_test_assert( str_contains( $gestao, "verify_admin_nonce( 'adam_membership_test_gestao_google_sheets' )" ) && str_contains( $gestao, 'test_gestao_connection()' ), 'Gestão must use its own nonce and client test call.' );
adam_connection_test_assert( str_contains( $client, "adam_google_sheets_spreadsheet_missing" ) && str_contains( $client, "adam_google_sheets_gestao_spreadsheet_missing" ), 'Both missing-destination cases must return WP_Error instead of throwing.' );
adam_connection_test_assert( str_contains( $client, 'public function test_connection(): true|WP_Error' ) && str_contains( $client, 'public function test_gestao_connection(): true|WP_Error' ), 'Both client test methods must retain explicit non-throwing return contracts.' );

$quotas_client_test = substr( $client, strpos( $client, 'public function test_connection' ), 3500 );
$gestao_client_test = substr( $client, strpos( $client, 'public function test_gestao_connection' ), 3500 );
adam_connection_test_assert( ! str_contains( $quotas_client_test, ':batchUpdate' ) && ! str_contains( $gestao_client_test, ':batchUpdate' ), 'Both connection tests must remain non-destructive.' );

echo "Google Sheets connection-test handler smoke tests passed.\n";
