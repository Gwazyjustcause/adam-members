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
use AdamMembership\Admin\PrivateDocumentDownloadController;
use AdamMembership\Announcement\AnnouncementRepository;
use AdamMembership\Announcement\AnnouncementService;
use AdamMembership\Admin\AdminController;
use AdamMembership\Communication\CommunicationCategoryRegistry;
use AdamMembership\Communication\CommunicationPreferences;
use AdamMembership\Communication\CommunicationPreferencesController;
use AdamMembership\Document\DocumentRepository;
use AdamMembership\Document\DocumentService;
use AdamMembership\Document\PrivateDocumentRepository;
use AdamMembership\Document\PrivateDocumentStorage;
use AdamMembership\Emails\EmailService;
use AdamMembership\Form\MembershipForms;
use AdamMembership\Form\NifValidationController;
use AdamMembership\Form\RegistrationService;
use AdamMembership\GoogleSheets\GoogleSheetsClient;
use AdamMembership\GoogleSheets\GoogleSheetsSyncService;
use AdamMembership\Forminator\RegistrationFormConfig;
use AdamMembership\Forminator\RenewalSubmission;
use AdamMembership\Forminator\UserRegistration;
use AdamMembership\Helpers\Logger;
use AdamMembership\Event\EventFrontend;
use AdamMembership\Event\EventRepository;
use AdamMembership\Event\EventService;
use AdamMembership\Export\CompleteMemberExportService;
use AdamMembership\Member\Account;
use AdamMembership\Member\AccountSetup;
use AdamMembership\Member\ApprovalService;
use AdamMembership\Member\ApdAssociationRepository;
use AdamMembership\Member\ApdAssociationService;
use AdamMembership\Member\CardService;
use AdamMembership\Member\CardCosmeticsService;
use AdamMembership\Member\EmailChangeConfirmation;
use AdamMembership\Member\EmailConfirmation;
use AdamMembership\Member\HistoryRepository;
use AdamMembership\Member\HistoryService;
use AdamMembership\Member\MemberArea;
use AdamMembership\Member\MemberDeletionService;
use AdamMembership\Member\MemberChangeRepository;
use AdamMembership\Member\MemberChangeService;
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
		// Keep the WordPress toolbar available in wp-admin, but never expose it on
		// public pages (including logged-in member-area pages). Returning false
		// here also prevents WordPress from adding its admin-bar top offset.
		add_filter( 'show_admin_bar', static function ( bool $show ): bool {
			return is_admin() ? $show : false;
		}, 1000 );
		( new UIIntegration() )->register();

		$logger                    = new Logger();
		$settings                  = new SettingsRepository();
		$managed_pages             = new ManagedPages();
		$managed_pages->register();
		$members                   = new MemberRepository();
		$teams                     = new TeamRepository( $members );
		$renewal_repository        = new RenewalRepository();
		$history_repository        = new HistoryRepository();
		$history                   = new HistoryService( $history_repository, $members );
		$private_document_repository = new PrivateDocumentRepository();
		$private_document_storage    = new PrivateDocumentStorage();
		$email                     = new EmailService( $settings, $logger, $private_document_repository, $private_document_storage );
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
		$member_deletion           = new MemberDeletionService( $renewal_repository, $history_repository, $points_repository, $reward_repository, $event_repository, $announcement_repository, $logger );
		$member_deletion->register();
		$complete_export           = new CompleteMemberExportService( $settings );
		$recognition               = new RecognitionService( $members, $rewards, $history_repository, $logger );
		$card_cosmetics            = new CardCosmeticsService( $rewards );
		$events                    = new EventService( $event_repository, $members, $logger, $history_repository, $points );
		$statistics                = new StatisticsService( $members, $renewal_repository, $announcements, $events, $points, $rewards );
		$approval                  = new ApprovalService( $members, $settings, $email, $logger, $history, $recognition, $private_document_repository );
		$apd_repository            = new ApdAssociationRepository();
		$apd_association           = new ApdAssociationService( $apd_repository, $members, $settings, $email );
		$member_change_repository  = new MemberChangeRepository();
		$member_changes            = new MemberChangeService( $member_change_repository, $members, $email );
		$renewals                  = new RenewalService( $members, $renewal_repository, $email, $logger, $history, $recognition, $teams, $private_document_repository );
		$google_sheets_client      = new GoogleSheetsClient( $settings );
		$google_sheets_sync        = new GoogleSheetsSyncService( $google_sheets_client, $history_repository, $logger, $renewal_repository );
		add_action(
			'adam_membership_member_approved',
			static function ( \AdamMembership\Member\Member $member ) use ( $google_sheets_sync, $logger ): void {
				try {
					$google_sheets_sync->sync_registration( $member );
				} catch ( \Throwable $exception ) {
					$logger->error( 'Google Sheets registration synchronization threw an exception.', array( 'request_id' => (string) get_user_meta( $member->user_id(), 'adam_membership_registration_request_uuid', true ), 'error_code' => 'adam_google_sheets_exception' ) );
				}
			},
			10,
			1
		);
		add_action(
			'adam_membership_renewal_approved',
			static function ( \AdamMembership\Member\RenewalRequest $request, \AdamMembership\Member\Member $member ) use ( $google_sheets_sync, $logger ): void {
				try {
					$google_sheets_sync->sync_renewal( $request, $member );
				} catch ( \Throwable $exception ) {
					$logger->error( 'Google Sheets renewal synchronization threw an exception.', array( 'request_id' => $request->request_uuid(), 'error_code' => 'adam_google_sheets_exception' ) );
				}
			},
			10,
			2
		);
		$maintenance               = new MaintenanceService( $members, $renewal_repository, $renewals, $logger, $history );
		$cards                     = new CardService( $members, $settings, $logger, $card_cosmetics, $rewards );
		$config                    = new RegistrationFormConfig();
		$account_setup             = new AccountSetup( $settings, $members, $history );
		$registration_service      = new RegistrationService( $logger, $history, $email, $account_setup, $teams, $members );
		$registration              = new UserRegistration( $config, $logger, $registration_service );
		$renewal_submission        = new RenewalSubmission( $renewals, $logger );

		// Catalogue synchronization mutates plugin data and can touch translated
		// labels indirectly, so keep it out of bootstrap and run it after the
		// request lifecycle is fully initialized.
		add_action( 'wp_loaded', array( $rewards, 'ensure_initial_catalogue' ), 5 );

		$registration->register();
		( new NifValidationController( $registration_service ) )->register();
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
				$teams,
				$member_deletion,
				$complete_export,
				$apd_association,
				$member_changes,
				$google_sheets_client,
				$google_sheets_sync,
				$private_document_repository,
				$private_document_storage
			);

			$admin->register();
			( new AnnouncementController( $announcements ) )->register();
			( new DocumentController( $documents ) )->register();
			( new PrivateDocumentDownloadController( $private_document_repository, $private_document_storage ) )->register();
			if ( ! function_exists( '\adam_comunidade_events' ) ) {
				( new EventController( $events ) )->register();
			}
			( new PointsController( $points, $members, $events ) )->register();
			( new RewardController( $rewards, $members, $cards ) )->register();
			( new StatisticsController( $statistics, $events, $points ) )->register();

			return;
		}

		( new ConsentManager( $settings ) )->register();
		( new RewardQrFrontend( $rewards, $members ) )->register();
		( new MemberArea( $members, $renewals, $settings, $cards, $announcements, $documents, $points, $rewards, $account_setup, $recognition, $communication_preferences, $apd_association, $member_changes ) )->register();
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
