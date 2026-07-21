<?php
/**
 * Optional ADAM UI integration.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

/**
 * Connects ADAM SÃ³cios to the shared visual system when it is available.
 *
 * Integration contract:
 * - ADAM UI is the only active source of theme values.
 * - ADAM SÃ³cios CSS keeps layout and structure plus standalone fallbacks.
 * - WordPress admin theming is enabled only on ADAM SÃ³cios screens.
 * - Missing or disabled ADAM UI never blocks plugin functionality.
 */
final class UIIntegration {
	/** @var string[] */
	private const COMPONENTS = array( 'admin-layout', 'card', 'button', 'forms', 'table', 'tabs', 'modal', 'notice', 'badge', 'empty-state', 'loading', 'pagination', 'toolbar', 'search', 'confirmation', 'stat-card', 'section-header' );

	/**
	 * Register integration hooks without creating a hard dependency.
	 */
	public function register(): void {
		$this->register_with_ui();
		add_action( 'wp_enqueue_scripts', array( $this, 'ensure_frontend_theme' ), 20 );
		add_action( 'login_enqueue_scripts', array( $this, 'ensure_frontend_theme' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enable_admin_theme' ), 1 );
	}

	/** Registers version and component requirements when ADAM UI is active. */
	private function register_with_ui(): void {
		if ( function_exists( 'adam_ui_register_plugin' ) ) {
			adam_ui_register_plugin(
				'adam-socios',
				'ADAM SÃ³cios',
				array(
					'version'     => defined( 'ADAM_MEMBERSHIP_VERSION' ) ? ADAM_MEMBERSHIP_VERSION : '',
					'requires_ui' => '1.0.0',
					'components'  => self::COMPONENTS,
					'plugin_file' => defined( 'ADAM_MEMBERSHIP_FILE' ) ? plugin_basename( ADAM_MEMBERSHIP_FILE ) : '',
				)
			);
		}
	}

	/** Requests the shared families used by ADAM SÃ³cios. */
	private function enqueue_components(): void {
		if ( ! function_exists( 'adam_ui' ) ) {
			return;
		}

		foreach ( self::COMPONENTS as $component ) {
			adam_ui()->enqueue_component( $component );
		}
	}

	/**
	 * Whether the ADAM UI public contract is available.
	 */
	public static function is_available(): bool {
		return function_exists( 'adam_ui_get_theme_manager' );
	}

	/**
	 * Ensure shared assets are present on public and login requests.
	 */
	public function ensure_frontend_theme(): void {
		if ( ! self::is_available() ) {
			return;
		}

		adam_ui_get_theme_manager()->enqueue_core_assets();
		$this->enqueue_components();
	}

	/**
	 * Opt an ADAM SÃ³cios admin page into the shared Theme Manager.
	 *
	 * @param string $hook_suffix Current WordPress admin hook.
	 */
	public function enable_admin_theme( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'adam-membership' ) || ! self::is_available() ) {
			return;
		}

		if ( function_exists( 'adam_ui_enable_admin_theme' ) ) {
			adam_ui_enable_admin_theme();
			$this->enqueue_components();
		}
	}
}
