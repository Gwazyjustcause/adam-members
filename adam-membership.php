<?php
/**
 * Plugin Name: ADAM Membership
 * Plugin URI:  https://github.com/Gwazyjustcause/adam-members
 * Description: Membership management for ADAM (Associacao Desportiva de Airsoft do Mondego).
 * Version:     0.1.0
 * Author:      ADAM
 * Text Domain: adam-membership
 * Domain Path: /languages
 * Requires at least: 6.8
 * Requires PHP: 8.2
 *
 * @package AdamMembership
 */

declare(strict_types=1);

namespace AdamMembership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ADAM_MEMBERSHIP_VERSION', '0.1.0' );
define( 'ADAM_MEMBERSHIP_FILE', __FILE__ );
define( 'ADAM_MEMBERSHIP_PATH', plugin_dir_path( __FILE__ ) );
define( 'ADAM_MEMBERSHIP_URL', plugin_dir_url( __FILE__ ) );

$adam_membership_autoloader = ADAM_MEMBERSHIP_PATH . 'vendor/autoload.php';

if ( file_exists( $adam_membership_autoloader ) ) {
	require_once $adam_membership_autoloader;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = __NAMESPACE__ . '\\';

			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}

			$relative_class = substr( $class_name, strlen( $prefix ) );
			$file           = ADAM_MEMBERSHIP_PATH . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( Core\MaintenanceService::class ) ) {
			Core\MaintenanceService::activate();
		}

		if ( class_exists( Event\EventFrontend::class ) ) {
			Event\EventFrontend::activate();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( Core\MaintenanceService::class ) ) {
			Core\MaintenanceService::deactivate();
		}

		if ( class_exists( Event\EventFrontend::class ) ) {
			Event\EventFrontend::deactivate();
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( Core\Plugin::class ) ) {
			return;
		}

		Core\Plugin::instance()->boot();
	}
);
