<?php
/** Contract checks for the temporary production-safe connection-test trace. */

declare(strict_types=1);

function adam_connection_trace_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$admin  = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );
$plugin = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Plugin.php' );

adam_connection_trace_assert( str_contains( $plugin, "'action' => sanitize_key( (string) ( \$_REQUEST['action'] ?? '' ) )" ), 'Admin-post action receipt must be logged without request data beyond the action name.' );
adam_connection_trace_assert( str_contains( $plugin, "'quotas_test_handler'" ) && str_contains( $plugin, "'gestao_test_handler'" ), 'Both test handlers must be logged as registered.' );
foreach ( array( 'quotas' => 'handle_test_google_sheets', 'gestao' => 'handle_test_gestao_google_sheets' ) as $destination => $handler_name ) {
	$start = strpos( $admin, 'public function ' . $handler_name );
	$end   = strpos( $admin, "\n\t/**", $start + 1 );
	$code  = false !== $start && false !== $end ? substr( $admin, $start, $end - $start ) : '';
	adam_connection_trace_assert( '' !== $code, "{$destination} handler must be available for trace auditing." );
	foreach ( array( 'handler_entered', 'before_capability_validation', 'capability_check_passed', 'before_nonce_validation', 'nonce_check_passed', 'before_google_client', 'after_google_client', 'before_redirect' ) as $stage ) {
		adam_connection_trace_assert( str_contains( $code, "'{$stage}'" ), "{$destination} handler must trace {$stage}." );
	}
}

adam_connection_trace_assert( str_contains( $admin, 'method="post" action="<?php echo esc_url( admin_url( \'admin-post.php\' ) ); ?>"' ), 'The settings forms must submit POST requests to admin-post.php.' );
adam_connection_trace_assert( str_contains( $admin, 'value="adam_membership_test_google_sheets"' ) && str_contains( $admin, 'value="adam_membership_test_gestao_google_sheets"' ), 'Both forms must post their exact registered actions.' );
adam_connection_trace_assert( str_contains( $admin, "wp_nonce_field( 'adam_membership_test_google_sheets' )" ) && str_contains( $admin, "wp_nonce_field( 'adam_membership_test_gestao_google_sheets' )" ), 'Both forms must emit the nonce action verified by their handler.' );
adam_connection_trace_assert( str_contains( $admin, "'redirect_attempted'" ) && str_contains( $admin, 'redirect_returned' ) && str_contains( $admin, 'wp_parse_url( $redirect_url, PHP_URL_PATH )' ), 'The shared redirect path must log its path and wp_safe_redirect return value.' );

$settings_start = strpos( $admin, '<h2><?php esc_html_e( \'Integração Google Sheets\'' );
$settings_end   = strpos( $admin, '<div class="adam-admin-panel adam-card">', $settings_start + 1 );
$settings_code  = false !== $settings_start && false !== $settings_end ? substr( $admin, $settings_start, $settings_end - $settings_start ) : '';
adam_connection_trace_assert( 3 === substr_count( $settings_code, '<form method="post"' ) && 3 === substr_count( $settings_code, '</form>' ), 'The Google settings panel must not contain nested or unbalanced forms around the two test buttons.' );

echo "Google Sheets connection trace smoke tests passed.\n";
