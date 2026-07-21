<?php
/**
 * Core plugin bootstrap.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

use AdamMembership\Admin\StatisticsController;
use AdamMembership\Analytics\StatisticsService;
use AdamMembership\Admin\AnnouncementController;
use AdamMembership\Admin\DocumentController;
use AdamMembership\Admin\EventController;
use AdamMembership\Admin\PointsController;
use AdamMembership\Admin\RewardController;
use AdamMembership\Announcement\AnnouncementRepository;
use AdamMembership\Announcement\AnnouncementService;
use AdamMembership\Admin\AdminController;
use AdamMembership\Communication\CommunicationCategoryRegistry;
use AdamMembership\Communication\CommunicationPreferences;
use AdamMembership\Communication\CommunicationPreferencesController;
use AdamMembership\Document\DocumentRepository;
use AdamMembership\Document\DocumentService;
use AdamMembership\Emails\EmailService;
use AdamMembership\Form\MembershipForms;
use AdamMembership\Form\RegistrationService;
use AdamMembership\Forminator\RegistrationFormConfig;
use AdamMembership\Forminator\RenewalSubmission;
use AdamMembership\Forminator\UserRegistration;
use AdamMembership\Helpers\Logger;
use AdamMembership\Event\EventFrontend;
use AdamMembership\Event\EventRepository;
use AdamMembership\Event\EventService;
use AdamMembership\Member\Account;
use AdamMembership\Member\AccountSetup;
use AdamMembership\Member\ApprovalService;
use AdamMembership\Member\CardService;
use AdamMembership\Member\CardCosmeticsService;
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
use AdamMembership\Member\RecognitionService;
use AdamMembership\Points\PointsRepository;
use AdamMembership\Points\PointsService;
use AdamMembership\Privacy\ConsentManager;
use AdamMembership\Reward\RewardRepository;
use AdamMembership\Reward\RewardQrFrontend;
use AdamMembership\Reward\RewardService;
use AdamMembership\Team\TeamRepository;

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
		( new UIIntegration() )->register();

		$logger                    = new Logger();
		$settings                  = new SettingsRepository();
		$members                   = new MemberRepository();
		$teams                     = new TeamRepository( $members );
		$renewal_repository        = new RenewalRepository();
		$history_repository        = new HistoryRepository();
		$history                   = new HistoryService( $history_repository, $members );
		$email                     = new EmailService( $settings, $logger );
		$communication_categories  = new CommunicationCategoryRegistry();
		$communication_preferences = new CommunicationPreferences( $communication_categories );
		$announcement_repository   = new AnnouncementRepository();
		$announcements             = new AnnouncementService( $announcement_repository, $members, $email, $logger, $communication_preferences, $teams );
		$document_repository       = new DocumentRepository();
		$documents                 = new DocumentService( $document_repository, $members, $logger, $history_repository );
		$event_repository          = new EventRepository();
		$points_repository         = new PointsRepository();
		$points                    = new PointsService( $points_repository, $members, $history_repository, $logger );
		$reward_repository         = new RewardRepository();
		$rewards                   = new RewardService( $reward_repository, $points, $members, $history_repository, $logger );
		$recognition               = new RecognitionService( $members, $rewards, $history_repository, $logger );
		$card_cosmetics            = new CardCosmeticsService( $rewards );
		$events                    = new EventService( $event_repository, $members, $logger, $history_repository, $points );
		$statistics                = new StatisticsService( $members, $renewal_repository, $announcements, $events, $points, $rewards );
		$approval                  = new ApprovalService( $members, $settings, $email, $logger, $history, $recognition );
		$renewals                  = new RenewalService( $members, $renewal_repository, $email, $logger, $history, $recognition, $teams );
		$maintenance               = new MaintenanceService( $members, $renewal_repository, $renewals, $logger, $history );
		$cards                     = new CardService( $members, $settings, $logger, $card_cosmetics, $rewards );
		$config                    = new RegistrationFormConfig();
		$account_setup             = new AccountSetup( $settings, $members, $history );
		$registration_service      = new RegistrationService( $logger, $history, $email, $account_setup, $teams );
		$registration              = new UserRegistration( $config, $logger, $registration_service );
		$renewal_submission        = new RenewalSubmission( $renewals, $logger );

		// Catalogue synchronization mutates plugin data and can touch translated
		// labels indirectly, so keep it out of bootstrap and run it after the
		// request lifecycle is fully initialized.
		add_action( 'wp_loaded', array( $rewards, 'ensure_initial_catalogue' ), 5 );

		$registration->register();
		$renewal_submission->register();
		( new EventFrontend( $events, $members, $logger, $settings ) )->register();
		$maintenance->register();
		$cards->register();
		$history->register();
		$documents->register();
		( new CommunicationPreferencesController( $communication_preferences, $members ) )->register();

		if ( is_admin() ) {
			$admin = new AdminController(
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
				$rewards,
				$recognition,
				$email,
				$teams
			);

			$admin->register();
			( new AnnouncementController( $announcements ) )->register();
			( new DocumentController( $documents ) )->register();
			( new EventController( $events ) )->register();
			( new PointsController( $points, $members, $events ) )->register();
			( new RewardController( $rewards, $members, $cards ) )->register();
			( new StatisticsController( $statistics, $events, $points ) )->register();

			return;
		}

		( new ConsentManager( $settings ) )->register();
		( new RewardQrFrontend( $rewards, $members ) )->register();
		( new MemberArea( $members, $renewals, $settings, $cards, $announcements, $documents, $points, $rewards, $account_setup, $recognition, $communication_preferences ) )->register();
		( new MembershipForms( $settings, $members, $registration_service, $renewals, $teams ) )->register();
		$account_setup->register();
		( new PasswordRecovery( $email, $members, $history ) )->register();
		( new PasswordReset( $members, $history ) )->register();
		( new Account( $email, $members, $history ) )->register();
		$email_change = new EmailChangeConfirmation( $members, $history );
		$email_change->register();
		( new EmailConfirmation( $email_change ) )->register();
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
		_doing_it_wrong( __METHOD__, 'Unserializing the plugin bootstrap is not allowed.', '0.1.0' );
	}
}
