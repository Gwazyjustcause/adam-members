<?php
/**
 * Optional ADAM Interface integration.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

/**
 * Connects ADAM Sócios to the shared visual system when it is available.
 *
 * Integration contract:
 * - ADAM Interface is the only active source of theme values.
 * - ADAM Sócios CSS keeps layout and structure plus standalone fallbacks.
 * - WordPress admin theming is enabled only on ADAM Sócios screens.
 * - Missing or disabled ADAM Interface never blocks plugin functionality.
 */
final class InterfaceIntegration {
	/**
	 * Register integration hooks without creating a hard dependency.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'ensure_frontend_theme' ), 20 );
		add_action( 'login_enqueue_scripts', array( $this, 'ensure_frontend_theme' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enable_admin_theme' ), 1 );
	}

	/**
	 * Whether the ADAM Interface public contract is available.
	 */
	public static function is_available(): bool {
		return function_exists( 'adam_interface_get_theme_manager' );
	}

	/**
	 * Ensure shared assets are present on public and login requests.
	 */
	public function ensure_frontend_theme(): void {
		if ( ! self::is_available() ) {
			return;
		}

		$manager = adam_interface_get_theme_manager();

		if ( is_object( $manager ) && method_exists( $manager, 'enqueue_assets' ) ) {
			$manager->enqueue_assets();
		}
	}

	/**
	 * Opt an ADAM Sócios admin page into the shared Theme Manager.
	 *
	 * @param string $hook_suffix Current WordPress admin hook.
	 */
	public function enable_admin_theme( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'adam-membership' ) || ! self::is_available() ) {
			return;
		}

		if ( function_exists( 'adam_interface_enable_admin_theme' ) ) {
			adam_interface_enable_admin_theme();
		}
	}
}
