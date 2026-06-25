<?php
/**
 * Core plugin bootstrap.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

use AdamMembership\Forminator\RegistrationFormConfig;
use AdamMembership\Forminator\UserRegistration;
use AdamMembership\Helpers\Logger;

/**
 * Coordinates plugin services.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether the plugin has booted.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot registered plugin modules.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->register_modules();
	}

	/**
	 * Register plugin modules.
	 */
	private function register_modules(): void {
		$logger = new Logger();
		$config = new RegistrationFormConfig();

		( new UserRegistration( $config, $logger ) )->register();
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup(): void {
		_doing_it_wrong( __METHOD__, esc_html__( 'Unserializing the plugin bootstrap is not allowed.', 'adam-membership' ), '0.1.0' );
	}
}
