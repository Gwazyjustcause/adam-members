<?php
/**
 * System page administration and shared protection integration smoke test.
 *
 * @package AdamMembership\Tests
 */

$root       = dirname( __DIR__ );
$managed    = (string) file_get_contents( $root . '/src/Core/ManagedPages.php' );
$template   = (string) file_get_contents( $root . '/templates/admin-addresses.php' );
$bootstrap  = (string) file_get_contents( $root . '/src/Core/Plugin.php' );
$activation = (string) file_get_contents( $root . '/adam-membership.php' );
$ui         = (string) file_get_contents( $root . '/src/Core/UIIntegration.php' );

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

foreach ( array( 'registration', 'renewal', 'member_area', 'account_setup', 'password_recovery', 'password_reset', 'change_email', 'change_password', 'email_confirmation' ) as $key ) {
	$assert( str_contains( $managed, "'{$key}'" ), "Missing managed page definition: {$key}." );
}

$assert( str_contains( $managed, "adam_ui_register_system_pages( 'adam-membership'" ), 'ADAM Sócios does not register with the shared protection service.' );
$assert( str_contains( $managed, 'adam_ui_set_system_page_protected' ), 'Protection flags are not persisted through the shared service.' );
$assert( str_contains( $managed, "'account_setup'" ) && str_contains( $managed, "'password_reset'" ) && str_contains( $managed, "'email_confirmation'" ), 'Token-based protected journeys are not declared.' );
$assert( str_contains( $template, 'Página Protegida' ) && str_contains( $template, 'Recriar página' ) && str_contains( $template, 'Editar página' ), 'The Endereços table is incomplete.' );
$assert( str_contains( $bootstrap, 'new ManagedPages()' ), 'Managed pages are not booted.' );
$assert( str_contains( $activation, 'ManagedPages::activate()' ), 'Managed pages are not created during activation.' );
$assert( str_contains( $ui, "'requires_ui' => '5.1.0'" ), 'ADAM UI 5.1.0 is not declared for the shared protection API.' );

echo "System pages feature smoke tests passed.\n";
