<?php
/**
 * Core plugin bootstrap.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

use AdamMembership\Admin\AnnouncementController;
use AdamMembership\Admin\DocumentController;
use AdamMembership\Admin\EventController;
use AdamMembership\Admin\PointsController;
use AdamMembership\Admin\RewardController;
use AdamMembership\Announcement\AnnouncementRepository;
use AdamMembership\Announcement\AnnouncementService;
use AdamMembership\Admin\AdminController;
use AdamMembership\Document\DocumentRepository;
use AdamMembership\Document\DocumentService;
use AdamMembership\Emails\EmailService;
use AdamMembership\Forminator\RegistrationFormConfig;
use AdamMembership\Forminator\RenewalSubmission;
use AdamMembership\Forminator\UserRegistration;
use AdamMembership\Helpers\Logger;
use AdamMembership\Event\EventFrontend;
use AdamMembership\Event\EventRepository;
use AdamMembership\Event\EventService;
use AdamMembership\Member\Account;
use AdamMembership\Member\ApprovalService;
use AdamMembership\Member\CardService;
use AdamMembership\Member\EmailChangeConfirmation;
use AdamMembership\Member\EmailConfirmation;
use AdamMembership\Member\HistoryRepository;
use AdamMembership\Member\HistoryService;
use AdamMembership\Member\MemberArea;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Member\PasswordRecovery;
use AdamMembership\Member\PasswordReset;
use AdamMembership\Member\RenewalRepository;
use AdamMembership\Member\RenewalService;
use AdamMembership\Points\PointsRepository;
use AdamMembership\Points\PointsService;
use AdamMembership\Reward\RewardRepository;
use AdamMembership\Reward\RewardService;

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
		$logger             = new Logger();
		$settings           = new SettingsRepository();
		$members            = new MemberRepository();
		$renewal_repository = new RenewalRepository();
		$history_repository = new HistoryRepository();
		$history            = new HistoryService( $history_repository, $members );
		$email              = new EmailService( $settings, $logger );
		$announcement_repository = new AnnouncementRepository();
		$announcements      = new AnnouncementService( $announcement_repository, $members, $email, $logger );
		$document_repository = new DocumentRepository();
		$documents          = new DocumentService( $document_repository, $members, $logger, $history_repository );
		$event_repository   = new EventRepository();
		$points_repository  = new PointsRepository();
		$points             = new PointsService( $points_repository, $members, $history_repository, $logger );
		$reward_repository  = new RewardRepository();
		$rewards            = new RewardService( $reward_repository, $points, $members, $history_repository, $logger );
		$events             = new EventService( $event_repository, $members, $logger, $history_repository, $points );
		$approval           = new ApprovalService( $members, $settings, $email, $logger, $history );
		$renewals           = new RenewalService( $members, $renewal_repository, $email, $logger, $history );
		$maintenance        = new MaintenanceService( $members, $renewal_repository, $renewals, $logger, $history );
		$cards              = new CardService( $members, $settings, $logger );
		$config             = new RegistrationFormConfig();
		$member_area        = new MemberArea( $members, $renewals, $settings, $cards, $announcements, $documents, $points, $rewards );
		$account            = new Account( $email, $members, $history );
		$password_recovery  = new PasswordRecovery( $email, $members, $history );
		$password_reset     = new PasswordReset( $members, $history );
		$email_change       = new EmailChangeConfirmation( $members, $history );
		$email_confirmation = new EmailConfirmation( $email_change );
		$registration       = new UserRegistration( $config, $logger, $history );
		$renewal_submission = new RenewalSubmission( $renewals, $logger );
		$events_frontend    = new EventFrontend( $events, $members, $logger );
		$admin              = new AdminController(
			$members,
			$approval,
			$settings,
			$logger,
			$renewal_repository,
			$renewals,
			$maintenance,
			$cards,
			$history_repository,
			$announcements,
			$documents,
			$events,
			$rewards
		);
		$announcement_admin = new AnnouncementController( $announcements );
		$document_admin     = new DocumentController( $documents );
		$event_admin        = new EventController( $events );
		$points_admin       = new PointsController( $points, $members, $events );
		$reward_admin       = new RewardController( $rewards, $members );

		$registration->register();
		$renewal_submission->register();
		$admin->register();
		$announcement_admin->register();
		$document_admin->register();
		$event_admin->register();
		$points_admin->register();
		$reward_admin->register();
		$maintenance->register();
		$cards->register();
		$history->register();
		$documents->register();
		$events_frontend->register();

		$member_area->register();
		$password_recovery->register();
		$password_reset->register();
		$account->register();
		$email_change->register();
		$email_confirmation->register();
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
