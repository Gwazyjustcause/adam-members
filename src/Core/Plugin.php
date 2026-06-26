<?php
/**
 * Core plugin bootstrap.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

use AdamMembership\Admin\AdminController;
use AdamMembership\Emails\EmailService;
use AdamMembership\Forminator\RegistrationFormConfig;
use AdamMembership\Forminator\UserRegistration;
use AdamMembership\Helpers\Logger;
use AdamMembership\Member\ApprovalService;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Member\MemberArea;
use AdamMembership\Member\Account;
use AdamMembership\Member\PasswordRecovery;

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
		$logger    = new Logger();
		$settings  = new SettingsRepository();
		$members   = new MemberRepository();
		$email     = new EmailService( $settings, $logger );
		$approval  = new ApprovalService( $members, $settings, $email, $logger );
		$config    = new RegistrationFormConfig();
		$memberArea = new MemberArea( $members );
		$account = new Account();
		$passwordRecovery = new PasswordRecovery();

		( new UserRegistration( $config, $logger ) )->register();
		( new AdminController( $members, $approval, $settings, $logger ) )->register();

		$memberArea->register();
		$passwordRecovery->register();
		$account->register();
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
