<?php
/**
 * WordPress admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Announcement\Announcement;
use AdamMembership\Announcement\AnnouncementService;
use AdamMembership\Core\SettingsRepository;
use AdamMembership\Core\DisplayLabels;
use AdamMembership\Core\CorrectionFieldCatalog;
use AdamMembership\Core\ManagedPages;
use AdamMembership\Core\MaintenanceService;
use AdamMembership\Core\Plugin;
use AdamMembership\Document\DocumentService;
use AdamMembership\Document\MemberDocumentHistoryService;
use AdamMembership\Document\PrivateDocumentRepository;
use AdamMembership\Document\PrivateDocumentStorage;
use AdamMembership\Emails\EmailService;
use AdamMembership\Event\Event;
use AdamMembership\Event\EventCheckIn;
use AdamMembership\Event\EventService;
use AdamMembership\Export\CompleteMemberExportService;
use AdamMembership\Form\SharedFieldValidator;
use AdamMembership\Form\IdentificationValidator;
use AdamMembership\GoogleSheets\GoogleSheetsClient;
use AdamMembership\GoogleSheets\GoogleSheetsMembershipWorkflowService;
use AdamMembership\GoogleSheets\GoogleSheetsSyncService;
use AdamMembership\Helpers\Logger;
use AdamMembership\Member\ApprovalService;
use AdamMembership\Member\ApdAssociationService;
use AdamMembership\Member\ApdAssociationRequest;
use AdamMembership\Finance\FinancialMovement;
use AdamMembership\Finance\FinancialMovementRepository;
use AdamMembership\Member\CardService;
use AdamMembership\Member\HistoryEntry;
use AdamMembership\Member\HistoryRepository;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberDeletionService;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Member\MemberChangeService;
use AdamMembership\Member\MemberChangeRequest;
use AdamMembership\Member\RecognitionService;
use AdamMembership\Member\RenewalRepository;
use AdamMembership\Member\RenewalRequest;
use AdamMembership\Member\RenewalService;
use AdamMembership\Reward\RewardRedemption;
use AdamMembership\Reward\RewardService;
use AdamMembership\Team\Team;
use AdamMembership\Team\TeamRepository;
use WP_Error;

if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	error_log( '[ADAM Membership] financial-movement-trace-v3 controller_loaded' );
}

/**
 * Registers and renders ADAM Membership admin pages.
 */
final class AdminController {
	private const CAPABILITY          = 'manage_options';
	private const MENU_SLUG           = 'adam-membership';
	private const HISTORY_PAGE_SLUG   = 'adam-membership-history';
	private const MEMBER_PAGE_SLUG    = 'adam-membership-member';
	private const MEMBER_DOCUMENT_HISTORY_PAGE_SLUG = 'adam-membership-member-document-history';
	private const ACTION_APPROVE      = 'approve';
	private const ACTION_CONFIRM_ANA  = 'confirm_ana';
	private const ACTION_REMOVE_ANA   = 'remove_ana';
	private const ACTION_REJECT       = 'reject';
	private const ACTION_REQUEST_CORRECTION = 'request_correction';
	private const ACTION_REQUEST_RENEWAL_CORRECTION = 'request_renewal_correction';
	private const ACTION_RENEW        = 'renew_quota';
	private const ACTION_CHANGE_QUOTA = 'change_quota_validity';
	private const ACTION_RESEND_EMAIL = 'resend_approval_email';
	private const ACTION_RESEND_RENEWAL_EMAIL = 'resend_renewal_approval_email';
	private const ACTION_SEND_PRIVATE_DOCUMENT = 'send_private_document';
	private const ACTION_SAVE_MEMBER  = 'save_member';
	private const ACTION_REGENERATE_CARD_TOKEN = 'regenerate_card_token';
	private const ACTION_REPLACE_DOCUMENT = 'replace_document';
	private const ACTION_REMOVE_DOCUMENT = 'remove_document';
	private const ACTION_REPLACE_RENEWAL_DOCUMENT = 'replace_renewal_document';
	private const ACTION_REMOVE_RENEWAL_DOCUMENT  = 'remove_renewal_document';
	private const ACTION_PRIVATE_DOCUMENT_UPLOAD  = 'upload_private_document';
	private const ACTION_PRIVATE_DOCUMENT_REMOVE  = 'remove_private_document';
	private const ACTION_APPROVE_RENEWAL = 'approve_renewal';
	private const ACTION_CONFIRM_ANA_RENEWAL = 'confirm_ana_renewal';
	private const ACTION_REJECT_RENEWAL  = 'reject_renewal';
	private const RENEWAL_PAGE_SLUG      = 'adam-membership-renewal-request';
	private const APD_PAGE_SLUG          = 'adam-membership-apd-requests';
	private const MEMBER_CHANGES_PAGE_SLUG = 'adam-membership-member-changes';
	private const DIAGNOSTICS_PAGE_SLUG  = 'adam-membership-diagnostics';
	private const FOUNDERS_PAGE_SLUG     = 'adam-membership-founders';
	private const FORMS_PAGE_SLUG        = 'adam-membership-forms';
	private const EMAILS_PAGE_SLUG       = 'adam-membership-emails';
	private const TEAMS_PAGE_SLUG        = 'adam-membership-teams';
	private const TEAM_PAGE_SLUG         = 'adam-membership-team';
	private const ACTION_SAVE_TEAM       = 'save_team';
	private const ACTION_DELETE_TEAM     = 'delete_team';

	/**
	 * Member details page hook suffix.
	 *
	 * @var string
	 */
	private string $member_page_hook = '';

	/**
	 * Renewal review page hook suffix.
	 *
	 * @var string
	 */
	private string $renewal_page_hook = '';

	/**
	 * Team details page hook suffix.
	 *
	 * @var string
	 */
	private string $team_page_hook = '';

	/**
	 * Member repository.
	 *
	 * @var MemberRepository
	 */
	private MemberRepository $members;

	/**
	 * Approval service.
	 *
	 * @var ApprovalService
	 */
	private ApprovalService $approval_service;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Logger helper.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Renewal request repository.
	 *
	 * @var RenewalRepository
	 */
	private RenewalRepository $renewal_repository;

	/**
	 * Renewal service.
	 *
	 * @var RenewalService
	 */
	private RenewalService $renewal_service;

	/**
	 * Maintenance service.
	 *
	 * @var MaintenanceService
	 */
	private MaintenanceService $maintenance;

	/**
	 * Digital card service.
	 *
	 * @var CardService
	 */
	private CardService $cards;

	/**
	 * Member history repository.
	 *
	 * @var HistoryRepository
	 */
	private HistoryRepository $history_repository;

	/**
	 * Announcement service.
	 *
	 * @var AnnouncementService
	 */
	private AnnouncementService $announcements;

	/**
	 * Document service.
	 *
	 * @var DocumentService
	 */
	private DocumentService $documents;

	/**
	 * Event service.
	 *
	 * @var EventService
	 */
	private EventService $events;

	/**
	 * Reward service.
	 *
	 * @var RewardService
	 */
	private RewardService $rewards;
	private RecognitionService $recognition;
	private EmailService $email;

	/**
	 * Team repository.
	 *
	 * @var TeamRepository
	 */
	private TeamRepository $teams;

	/**
	 * Permanent member deletion service.
	 *
	 * @var MemberDeletionService
	 */
	private MemberDeletionService $member_deletion;

	/**
	 * Complete member archive exporter.
	 *
	 * @var CompleteMemberExportService
	 */
	private CompleteMemberExportService $complete_export;
	private ApdAssociationService $apd_association;
	private MemberChangeService $member_changes;
	private GoogleSheetsClient $google_sheets;
	private GoogleSheetsMembershipWorkflowService $membership_workflow;
	private GoogleSheetsSyncService $google_sheets_sync;
	private FinancialMovementRepository $financial_movements;
	private PrivateDocumentRepository $private_documents;
	private PrivateDocumentStorage $private_document_storage;
	private MemberDocumentHistoryService $member_document_history;

	/**
	 * Create the admin controller.
	 *
	 * @param MemberRepository   $members          Member repository.
	 * @param ApprovalService    $approval_service Approval service.
	 * @param SettingsRepository $settings         Settings repository.
	 * @param Logger             $logger           Logger helper.
	 * @param RenewalRepository  $renewals         Renewal repository.
	 * @param RenewalService     $renewal_service  Renewal service.
	 * @param MaintenanceService $maintenance      Maintenance service.
	 * @param CardService        $cards            Digital card service.
	 * @param HistoryRepository  $history          Member history repository.
	 * @param AnnouncementService $announcements   Announcement service.
	 * @param DocumentService     $documents       Document service.
	 * @param EventService        $events          Event service.
	 * @param RewardService       $rewards         Reward service.
	 * @param RecognitionService  $recognition     Recognition service.
	 * @param EmailService        $email           Email service.
	 * @param TeamRepository      $teams           Team repository.
	 * @param MemberDeletionService       $member_deletion Permanent member deletion service.
	 * @param CompleteMemberExportService $complete_export Complete member archive exporter.
	 */
	public function __construct( MemberRepository $members, ApprovalService $approval_service, SettingsRepository $settings, Logger $logger, RenewalRepository $renewals, RenewalService $renewal_service, MaintenanceService $maintenance, CardService $cards, HistoryRepository $history, AnnouncementService $announcements, DocumentService $documents, EventService $events, RewardService $rewards, RecognitionService $recognition, EmailService $email, TeamRepository $teams, MemberDeletionService $member_deletion, CompleteMemberExportService $complete_export, ApdAssociationService $apd_association, MemberChangeService $member_changes, GoogleSheetsClient $google_sheets, GoogleSheetsMembershipWorkflowService $membership_workflow, GoogleSheetsSyncService $google_sheets_sync, FinancialMovementRepository $financial_movements, PrivateDocumentRepository $private_documents, PrivateDocumentStorage $private_document_storage, MemberDocumentHistoryService $member_document_history ) {
		$this->members            = $members;
		$this->approval_service   = $approval_service;
		$this->settings           = $settings;
		$this->logger             = $logger;
		$this->renewal_repository = $renewals;
		$this->renewal_service    = $renewal_service;
		$this->maintenance         = $maintenance;
		$this->cards               = $cards;
		$this->history_repository  = $history;
		$this->announcements       = $announcements;
		$this->documents           = $documents;
		$this->events              = $events;
		$this->rewards             = $rewards;
		$this->recognition         = $recognition;
		$this->email               = $email;
		$this->teams               = $teams;
		$this->member_deletion     = $member_deletion;
		$this->complete_export     = $complete_export;
		$this->apd_association     = $apd_association;
		$this->member_changes      = $member_changes;
		$this->google_sheets      = $google_sheets;
		$this->membership_workflow = $membership_workflow;
		$this->google_sheets_sync = $google_sheets_sync;
		$this->financial_movements = $financial_movements;
		$this->private_documents = $private_documents;
		$this->private_document_storage = $private_document_storage;
		$this->member_document_history = $member_document_history;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'current_screen', array( $this, 'prepare_hidden_screen_context' ) );
		add_filter( 'parent_file', array( $this, 'filter_hidden_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'filter_hidden_submenu_file' ) );
		add_action( 'admin_post_adam_membership_approve_member', array( $this, 'handle_approve_member' ) );
		add_action( 'admin_post_adam_membership_reject_member', array( $this, 'handle_reject_member' ) );
		add_action( 'admin_post_adam_membership_member_action', array( $this, 'handle_member_admin_action' ) );
		add_action( 'admin_post_adam_membership_delete_member_permanently', array( $this, 'handle_permanent_member_deletion' ) );
		add_action( 'admin_post_adam_membership_renewal_action', array( $this, 'handle_renewal_admin_action' ) );
		add_action( 'admin_post_adam_membership_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_adam_membership_test_google_sheets', array( $this, 'handle_test_google_sheets' ) );
		add_action( 'admin_post_adam_membership_retry_google_sheets', array( $this, 'handle_retry_google_sheets' ) );
		add_action( 'admin_post_adam_membership_save_google_sheets_payment', array( $this, 'handle_save_google_sheets_payment' ) );
		add_action( 'admin_post_adam_membership_delete_financial_movement', array( $this, 'handle_delete_financial_movement' ) );
		add_action( 'admin_post_adam_membership_save_forms_settings', array( $this, 'handle_save_forms_settings' ) );
		add_action( 'admin_post_adam_membership_save_email_settings', array( $this, 'handle_save_email_settings' ) );
		add_action( 'admin_post_adam_membership_send_test_email', array( $this, 'handle_send_test_email' ) );
		add_action( 'admin_post_adam_membership_run_maintenance', array( $this, 'handle_run_maintenance' ) );
		add_action( 'admin_post_adam_membership_export_members_csv', array( $this, 'handle_export_members_csv' ) );
		add_action( 'admin_post_adam_membership_export_complete_zip', array( $this, 'handle_export_complete_zip' ) );
		add_action( 'admin_post_adam_membership_apd_action', array( $this, 'handle_apd_action' ) );
		add_action( 'admin_post_adam_membership_member_change_action', array( $this, 'handle_member_change_action' ) );
		add_action( 'admin_post_adam_membership_team_action', array( $this, 'handle_team_admin_action' ) );
		add_action( 'admin_post_adam_membership_private_document_action', array( $this, 'handle_private_document_action' ) );
		add_action( 'admin_post_adam_membership_archive_document_history', array( $this, 'handle_archive_document_history' ) );
		add_action( 'admin_post_adam_membership_delete_document_history', array( $this, 'handle_delete_document_history' ) );
	}

	/**
	 * Register admin menu pages.
	 */
	public function register_menu(): void {
		add_menu_page(
			esc_html__( 'ADAM Sócios', 'adam-membership' ),
			esc_html__( 'ADAM Sócios', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-groups',
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Painel', 'adam-membership' ),
			esc_html__( 'Painel', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		$approval_count = $this->pending_approval_count();
		$approval_label = $approval_count > 0 ? sprintf( __( 'Aprovações (%d)', 'adam-membership' ), $approval_count ) : __( 'Aprovações', 'adam-membership' );
		add_submenu_page(
			self::MENU_SLUG,
			esc_html( $approval_label ),
			esc_html( $approval_label ),
			self::CAPABILITY,
			'adam-membership-pending',
			array( $this, 'render_approvals_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Sócios', 'adam-membership' ),
			esc_html__( 'Sócios', 'adam-membership' ),
			self::CAPABILITY,
			'adam-membership-members',
			array( $this, 'render_members_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Histórico', 'adam-membership' ),
			esc_html__( 'Histórico', 'adam-membership' ),
			self::CAPABILITY,
			self::HISTORY_PAGE_SLUG,
			array( $this, 'render_history_page' )
		);


		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Equipas', 'adam-membership' ),
			esc_html__( 'Equipas', 'adam-membership' ),
			self::CAPABILITY,
			self::TEAMS_PAGE_SLUG,
			array( $this, 'render_teams_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Formulários', 'adam-membership' ),
			esc_html__( 'Formulários', 'adam-membership' ),
			self::CAPABILITY,
			self::FORMS_PAGE_SLUG,
			array( $this, 'render_forms_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Emails', 'adam-membership' ),
			esc_html__( 'Emails', 'adam-membership' ),
			self::CAPABILITY,
			self::EMAILS_PAGE_SLUG,
			array( $this, 'render_emails_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Configurações', 'adam-membership' ),
			esc_html__( 'Configurações', 'adam-membership' ),
			self::CAPABILITY,
			'adam-membership-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Diagnósticos', 'adam-membership' ),
			esc_html__( 'Diagnósticos', 'adam-membership' ),
			self::CAPABILITY,
			self::DIAGNOSTICS_PAGE_SLUG,
			array( $this, 'render_diagnostics_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Fundadores', 'adam-membership' ),
			esc_html__( 'Fundadores', 'adam-membership' ),
			self::CAPABILITY,
			self::FOUNDERS_PAGE_SLUG,
			array( $this, 'render_founders_page' )
		);

		$this->member_page_hook = (string) add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Detalhes do Sócio', 'adam-membership' ),
			esc_html__( 'Detalhes do Sócio', 'adam-membership' ),
			self::CAPABILITY,
			self::MEMBER_PAGE_SLUG,
			array( $this, 'render_member_page' )
		);

		$this->renewal_page_hook = (string) add_submenu_page(
			null,
			esc_html__( 'Pedido de Renovação', 'adam-membership' ),
			esc_html__( 'Pedido de Renovação', 'adam-membership' ),
			self::CAPABILITY,
			self::RENEWAL_PAGE_SLUG,
			array( $this, 'render_renewal_page' )
		);

		$this->team_page_hook = (string) add_submenu_page(
			null,
			esc_html__( 'Detalhes da Equipa', 'adam-membership' ),
			esc_html__( 'Detalhes da Equipa', 'adam-membership' ),
			self::CAPABILITY,
			self::TEAM_PAGE_SLUG,
			array( $this, 'render_team_page' )
		);
		add_submenu_page( null, esc_html__( 'Histórico de documentos', 'adam-membership' ), esc_html__( 'Histórico de documentos', 'adam-membership' ), self::CAPABILITY, self::MEMBER_DOCUMENT_HISTORY_PAGE_SLUG, array( $this, 'render_member_document_history_page' ) );
		add_submenu_page( null, 'Pedidos APD/ANA', 'Pedidos APD/ANA', self::CAPABILITY, self::APD_PAGE_SLUG, array( $this, 'render_apd_requests_page' ) );
		add_submenu_page( null, 'Alterações de dados', 'Alterações de dados', self::CAPABILITY, self::MEMBER_CHANGES_PAGE_SLUG, array( $this, 'render_member_changes_page' ) );

		if ( '' !== $this->member_page_hook ) {
			add_action( 'load-' . $this->member_page_hook, array( $this, 'prepare_member_page_screen' ) );
		}

		if ( '' !== $this->renewal_page_hook ) {
			add_action( 'load-' . $this->renewal_page_hook, array( $this, 'prepare_renewal_page_screen' ) );
		}

		if ( '' !== $this->team_page_hook ) {
			add_action( 'load-' . $this->team_page_hook, array( $this, 'prepare_team_page_screen' ) );
		}
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Admin hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, 'adam-membership' ) ) {
			return;
		}

		$asset_path = ADAM_MEMBERSHIP_PATH . 'assets/css/admin.css';

		wp_enqueue_style(
			'adam-membership-admin',
			ADAM_MEMBERSHIP_URL . 'assets/css/admin.css',
			array(),
			file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : ADAM_MEMBERSHIP_VERSION
		);

		if ( $hook_suffix === $this->member_page_hook ) {
			$script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/admin-member-delete.js';

			wp_enqueue_script(
				'adam-membership-admin-member-delete',
				ADAM_MEMBERSHIP_URL . 'assets/js/admin-member-delete.js',
				array(),
				file_exists( $script_path ) ? (string) filemtime( $script_path ) : ADAM_MEMBERSHIP_VERSION,
				true
			);
		}

		if ( str_contains( $hook_suffix, 'adam-membership' ) ) {
			$correction_script = ADAM_MEMBERSHIP_PATH . 'assets/js/admin-correction-fields.js';
			wp_enqueue_script( 'adam-membership-admin-correction-fields', ADAM_MEMBERSHIP_URL . 'assets/js/admin-correction-fields.js', array(), file_exists( $correction_script ) ? (string) filemtime( $correction_script ) : ADAM_MEMBERSHIP_VERSION, true );
		}
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard_page(): void {
		$this->ensure_can_manage();

		$counts          = $this->members->dashboard_counts();
		$team_statistics = $this->teams->statistics();
		$team_directory  = $this->teams->public_directory();
		$context         = $this->dashboard_context( $counts );

		$team_statistics['teams_with_active_members'] = count(
			array_filter(
				$team_directory,
				static fn ( array $team ): bool => $team['active_member_count'] > 0
			)
		);
		$context['team_statistics']                   = $team_statistics;

		$this->render_header( __( 'Painel ADAM Sócios', 'adam-membership' ) );
		$this->render_notices();
		$this->render_dashboard_cards( $counts, $team_statistics );
		$this->render_dashboard_shortcuts( $context );
		$this->render_dashboard_widgets( $context );
		$this->render_footer();
	}

	/**
	 * Render diagnostics page.
	 */
	public function render_diagnostics_page(): void {
		$this->ensure_can_manage();

		$counts            = $this->members->dashboard_counts();
		$next_maintenance  = wp_next_scheduled( MaintenanceService::CRON_HOOK );
		$all_announcements = $this->announcements->admin_list();
		$all_documents     = $this->documents->admin_list();
		$all_events        = $this->events->admin_events();
		$all_checkins      = $this->events->repository()->query_checkins();
		$pending_rewards   = $this->rewards->admin_redemptions( array( 'status' => RewardRedemption::STATUS_PENDING ) );
		$history_entries   = $this->history_repository->query( array( 'limit' => 10 ) );

		$this->render_header( __( 'Diagnósticos ADAM', 'adam-membership' ) );
		$this->render_notices();
		?>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Diagnóstico do plugin', 'adam-membership' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr><th scope="row">Build</th><td><code><?php echo esc_html( Plugin::build_id() ); ?></code></td></tr>
					<tr><th scope="row">Admin-post handlers</th><td><?php esc_html_e( 'Registados', 'adam-membership' ); ?></td></tr>
					<tr><th scope="row">Member action handler</th><td><?php echo false !== has_action( 'admin_post_adam_membership_member_action' ) ? esc_html__( 'Registado', 'adam-membership' ) : esc_html__( 'Não registado', 'adam-membership' ); ?></td></tr>
					<tr><th scope="row">Renewal action handler</th><td><?php echo false !== has_action( 'admin_post_adam_membership_renewal_action' ) ? esc_html__( 'Registado', 'adam-membership' ) : esc_html__( 'Não registado', 'adam-membership' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<div class="adam-admin-cards">
			<div class="adam-admin-card adam-card"><span><?php esc_html_e( 'Sócios', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( $counts['total'] ?? 0 ) ); ?></strong></div>
			<div class="adam-admin-card adam-card"><span><?php esc_html_e( 'Avisos', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $all_announcements ) ) ); ?></strong></div>
			<div class="adam-admin-card adam-card"><span><?php esc_html_e( 'Documentos', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $all_documents ) ) ); ?></strong></div>
			<div class="adam-admin-card adam-card"><span><?php esc_html_e( 'Eventos', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $all_events ) ) ); ?></strong></div>
			<div class="adam-admin-card adam-card"><span><?php esc_html_e( 'Check-ins', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $all_checkins ) ) ); ?></strong></div>
			<div class="adam-admin-card adam-card"><span><?php esc_html_e( 'Pedidos de recompensa', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $pending_rewards ) ) ); ?></strong></div>
		</div>

		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Estado do sistema', 'adam-membership' ); ?></h2>
			<div class="adam-admin-detail-grid">
				<?php $this->render_detail_item( __( 'Próxima manutenção agendada', 'adam-membership' ), false !== $next_maintenance ? wp_date( 'd/m/Y H:i', $next_maintenance ) : __( 'Sem agendamento ativo', 'adam-membership' ) ); ?>
				<?php $this->render_detail_item( __( 'URL da Área do Sócio', 'adam-membership' ), $this->settings->member_area_url() ); ?>
				<?php $this->render_detail_item( __( 'URL da página de renovação', 'adam-membership' ), $this->settings->renewal_page_url() ); ?>
				<?php $this->render_detail_item( __( 'Último lote de atividade carregado', 'adam-membership' ), count( $history_entries ) > 0 ? $this->format_datetime( $history_entries[0]->created_at() ) : __( 'Sem atividade recente', 'adam-membership' ) ); ?>
			</div>
			<div class="adam-admin-actions" style="margin-top:16px;">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-settings' ) ); ?>"><?php esc_html_e( 'Abrir configurações', 'adam-membership' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::HISTORY_PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Abrir histórico', 'adam-membership' ); ?></a>
			</div>
		</div>
		<?php
		$this->render_footer();
	}

	/** Render a safe diagnostic for private document storage. */
	private function render_private_document_storage_status(): void {
		$status  = $this->private_document_storage->configuration_status();
		$classes = array( 'adam-private-document-storage-status', 'adam-private-document-storage-status--' . sanitize_html_class( $status['state'] ) );
		?>
		<div class="adam-admin-panel adam-card <?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<h2><?php esc_html_e( 'Armazenamento de documentos privados', 'adam-membership' ); ?></h2>
			<p><strong><?php echo esc_html( $status['message'] ); ?></strong></p>
			<?php if ( 'not_configured' === $status['state'] ) : ?>
				<p class="description"><?php esc_html_e( 'Defina ADAM_PRIVATE_DOCUMENTS_PATH no wp-config.php com um caminho absoluto fora da webroot. O plugin não usa a Media Library nem uploads públicos.', 'adam-membership' ); ?></p>
			<?php elseif ( 'directory_missing' === $status['state'] ) : ?>
				<p class="description"><?php esc_html_e( 'O diretório será criado apenas porque o diretório pai configurado é válido e está fora da webroot.', 'adam-membership' ); ?></p>
			<?php elseif ( 'operational' !== $status['state'] ) : ?>
				<p class="description"><?php esc_html_e( 'Corrija o caminho ou as permissões no servidor. Nenhum documento será guardado enquanto esta verificação falhar.', 'adam-membership' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the founders page.
	 */
	public function render_founders_page(): void {
		$this->ensure_can_manage();

		$founders = $this->members->founding_members();

		$this->render_header( __( 'Fundadores ADAM', 'adam-membership' ) );
		$this->render_notices();
		?>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Lista de membros fundadores', 'adam-membership' ); ?></h2>
			<p><?php esc_html_e( 'Primeiros sócios aprovados que mantêm o reconhecimento permanente de Fundador ADAM.', 'adam-membership' ); ?></p>
			<?php if ( array() === $founders ) : ?>
				<?php $this->render_empty_state( __( 'Ainda não existem membros fundadores registados.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<table class="widefat striped adam-admin-table adam-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'N.º Fundador', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Sócio', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'N.º de sócio', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Data de adesão', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $founders as $founder ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $founder->founder_number() ); ?></td>
								<td><?php echo esc_html( $founder->full_name() ); ?></td>
								<td><?php echo esc_html( $this->member_number_label( $founder ) ); ?></td>
								<td><?php echo esc_html( $this->format_date( $founder->field( 'data_adesao' ) ) ); ?></td>
								<td><?php echo esc_html( DisplayLabels::status( (string) $founder->effective_status() ) ); ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $this->member_url( $founder ) ); ?>"><?php esc_html_e( 'Ver sócio', 'adam-membership' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		$this->render_footer();
	}

	/**
	 * Render the pending members page.
	 */
	/* public function render_approvals_page(): void {
		$this->ensure_can_manage();
		$selected = sanitize_key( (string) ( $_GET['approval_type'] ?? 'all' ) );
		$rows = $this->approval_rows();
		$counts = array( 'all' => count( $rows ), 'registrations' => 0, 'renewals' => 0, 'changes' => 0, 'apd' => 0 );
		foreach ( $rows as $row ) { ++$counts[ $row['type'] ]; }
		$this->render_header( 'Aprovações' );
		$this->render_notices();
		?><div class="adam-admin-panel adam-card"><nav class="adam-admin-tabs" aria-label="Tipos de aprovação"><?php foreach ( array( 'all' => 'Todos', 'registrations' => 'Inscrições', 'renewals' => 'Renovações', 'changes' => 'Alterações de dados', 'apd' => 'APD / ANA' ) as $key => $label ) : ?><a class="<?php echo $selected === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'adam-membership-pending', 'approval_type' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?> <span class="count"><?php echo esc_html( (string) $counts[ $key ] ); ?></span></a><?php endforeach; ?></nav></div><div class="adam-admin-panel adam-card"><table class="widefat striped"><thead><tr><th>Tipo</th><th>Sócio / candidato</th><th>N.º de Sócio</th><th>Data</th><th>Estado</th><th>Ação</th></tr></thead><tbody><?php foreach ( $rows as $row ) : if ( 'all' !== $selected && $row['type'] !== $selected ) { continue; } ?><tr><td><strong><?php echo esc_html( $row['label'] ); ?></strong></td><td><?php echo esc_html( $row['member_name'] ); ?></td><td><?php echo esc_html( $row['member_number'] ?: '—' ); ?></td><td><?php echo esc_html( $row['date'] ); ?></td><td><span class="adam-admin-badge pending"><?php echo esc_html( $row['status'] ); ?></span></td><td><a class="button button-small button-primary" href="<?php echo esc_url( $row['url'] ); ?>">Rever</a></td></tr><?php endforeach; ?></tbody></table><?php if ( 0 === $counts['all'] ) : ?><p>Não existem pedidos pendentes.</p><?php endif; ?></div><?php");
		$this->render_footer();
	} */

	public function render_approvals_page(): void {
		$this->ensure_can_manage();
		$review_type = sanitize_key( (string) ( $_GET['review_type'] ?? '' ) );
		if ( 'registration' === $review_type ) { $this->render_member_page(); return; }
		if ( 'renewal' === $review_type ) { $this->render_renewal_page(); return; }
		if ( 'changes' === $review_type ) { $this->render_member_changes_page(); return; }
		if ( 'apd' === $review_type ) { $this->render_apd_review_or_list(); return; }
		$categories = $this->approval_categories();
		$selected   = $this->normalize_approval_type( (string) ( $_GET['approval_category'] ?? $_GET['approval_type'] ?? 'all' ) );
		if ( ! array_key_exists( $selected, $categories ) ) { $selected = 'all'; }
		$rows  = $this->approval_rows();
		$counts = array_fill_keys( array_keys( $categories ), 0 );
		foreach ( $rows as $row ) {
			$type = $this->normalize_approval_type( (string) ( $row['type'] ?? '' ) );
			if ( isset( $counts[ $type ] ) ) { ++$counts[ $type ]; }
		}
		$counts['all'] = count( $rows );
		$visible_rows = array_values( array_filter( $rows, function ( array $row ) use ( $selected ): bool {
			return 'all' === $selected || $this->normalize_approval_type( (string) ( $row['type'] ?? '' ) ) === $selected;
		} ) );
		$this->render_header( 'Aprovações' );
		$this->render_notices();
		echo '<div class="adam-admin-panel adam-card"><h2>Aprovações</h2><p>Selecione uma categoria para rever os pedidos pendentes.</p><nav class="adam-approval-filters" aria-label="Categorias de aprovação">';
		foreach ( $categories as $key => $label ) {
			$url   = add_query_arg( array( 'page' => 'adam-membership-pending', 'approval_category' => $key ), admin_url( 'admin.php' ) );
			$class = 'button' . ( $selected === $key ? ' button-primary is-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" aria-current="' . ( $selected === $key ? 'page' : 'false' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . ' <span class="count">' . esc_html( (string) ( $counts[ $key ] ?? 0 ) ) . '</span></a> ';
		}
		echo '</nav></div><div class="adam-admin-panel adam-card"><table class="widefat striped"><thead><tr><th>Tipo</th><th>Sócio</th><th>N.º</th><th>Data</th><th>Estado</th><th>Ação</th></tr></thead><tbody>';
		foreach ( $visible_rows as $row ) { echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td>' . esc_html( $row['member_name'] ) . '</td><td>' . esc_html( $row['member_number'] ?: '—' ) . '</td><td>' . esc_html( $row['date'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td><a class="button button-small button-primary" href="' . esc_url( $row['url'] ) . '">Rever</a></td></tr>'; }
		echo '</tbody></table>';
		if ( 0 === count( $visible_rows ) ) { echo '<p class="adam-admin-empty-state">' . esc_html( 'all' === $selected ? 'Não existem pedidos pendentes.' : sprintf( 'Não existem pedidos pendentes na categoria “%s”.', $categories[ $selected ] ) ) . '</p>'; }
		echo '</div>';
		$this->render_footer();
	}

	/** @return array<string,string> */
	private function approval_categories(): array {
		return array( 'all' => 'Todos', 'registrations' => 'Inscrições', 'renewals' => 'Renovações', 'changes' => 'Alterações de dados', 'apd' => 'APD / ANA' );
	}

	/** Normalize current and known legacy request-type values to filter keys. */
	private function normalize_approval_type( string $type ): string {
		$key = strtolower( remove_accents( trim( $type ) ) );
		$key = str_replace( array( '-', '_', '/', ' ' ), '', $key );
		return array(
			'all' => 'all', 'todos' => 'all',
			'registration' => 'registrations', 'registrations' => 'registrations', 'inscricao' => 'registrations', 'inscricoes' => 'registrations', 'memberregistration' => 'registrations', 'registrationrequest' => 'registrations',
			'renewal' => 'renewals', 'renewals' => 'renewals', 'renovacao' => 'renewals', 'renovacoes' => 'renewals', 'renewalrequest' => 'renewals',
			'change' => 'changes', 'changes' => 'changes', 'memberchange' => 'changes', 'memberchanges' => 'changes', 'memberchangerequest' => 'changes', 'datachange' => 'changes', 'dataupdate' => 'changes', 'memberdata' => 'changes', 'alteracaodedados' => 'changes', 'alteracoesdedados' => 'changes', 'alteracaodados' => 'changes',
			'apd' => 'apd', 'ana' => 'apd', 'apdana' => 'apd', 'apdassociation' => 'apd', 'apdassociationrequest' => 'apd', 'associationapd' => 'apd',
		)[ $key ] ?? $type;
	}

	/** @return array<int,array<string,string>> */
	private function approval_rows(): array {
		$rows = array();
		foreach ( $this->members->pending_members() as $member ) { $correction_received = 'correction_submitted' === (string) $member->field( 'adam_correction_status' ); $rows[] = array( 'type' => 'registrations', 'label' => 'Inscrição', 'member_name' => $member->full_name(), 'member_number' => (string) $member->field( 'numero_socio' ), 'date' => $member->registration_date(), 'status' => $correction_received ? 'Correção recebida' : 'Pendente', 'url' => add_query_arg( array( 'page' => 'adam-membership-pending', 'review_type' => 'registration', 'approval_category' => 'registrations', 'member_id' => $member->user_id() ), admin_url( 'admin.php' ) ) ); }
		foreach ( $this->renewal_repository->admin_requests( array( 'status' => array( RenewalRequest::STATUS_PENDING, RenewalRequest::STATUS_CORRECTION_SUBMITTED ) ) ) as $request ) { $member = $this->members->find( $request->user_id() ); if ( null !== $member ) { $rows[] = array( 'type' => 'renewals', 'label' => 'Renovação', 'member_name' => $member->full_name(), 'member_number' => (string) $member->field( 'numero_socio' ), 'date' => $request->submitted_at(), 'status' => $request->status(), 'url' => add_query_arg( array( 'page' => 'adam-membership-pending', 'review_type' => 'renewal', 'approval_category' => 'renewals', 'request_id' => $request->id() ), admin_url( 'admin.php' ) ) ); } }
		foreach ( $this->member_changes->repository()->all() as $request ) { if ( ! in_array( $request->status(), array( MemberChangeRequest::STATUS_PENDING, MemberChangeRequest::STATUS_CORRECTION_SUBMITTED ), true ) ) { continue; } $member = $this->members->find( $request->user_id() ); if ( null !== $member ) { $rows[] = array( 'type' => 'changes', 'label' => 'Alteração de dados', 'member_name' => $member->full_name(), 'member_number' => (string) $member->field( 'numero_socio' ), 'date' => $request->submitted_at(), 'status' => DisplayLabels::status( $request->status() ), 'url' => add_query_arg( array( 'page' => 'adam-membership-pending', 'review_type' => 'changes', 'approval_category' => 'changes', 'request_id' => $request->id() ), admin_url( 'admin.php' ) ) ); } }
		foreach ( $this->apd_association->repository()->all() as $request ) { if ( in_array( $request->status(), array( ApdAssociationRequest::STATUS_CONFIRMED, ApdAssociationRequest::STATUS_REJECTED ), true ) ) { continue; } $member = $this->members->find( $request->user_id() ); if ( null !== $member ) { $rows[] = array( 'type' => 'apd', 'label' => 'APD / ANA', 'member_name' => $member->full_name(), 'member_number' => (string) $member->field( 'numero_socio' ), 'date' => $request->requested_at(), 'status' => $request->status(), 'url' => add_query_arg( array( 'page' => 'adam-membership-pending', 'review_type' => 'apd', 'approval_category' => 'apd', 'request_id' => $request->id() ), admin_url( 'admin.php' ) ) ); } }
		foreach ( $rows as &$row ) {
			$row['status'] = DisplayLabels::status( (string) $row['status'] );
		}
		unset( $row );
		usort( $rows, static fn( array $a, array $b ): int => strcmp( $b['date'], $a['date'] ) );
		return $rows;
	}

	private function pending_approval_count(): int { return count( $this->approval_rows() ); }

	public function render_pending_members_page(): void {
		$this->ensure_can_manage();

		$filters           = $this->current_member_filters();
		$filters['status'] = Member::STATUS_PENDING;
		$members           = $this->members->admin_members( $filters );

		$this->render_header( __( 'Sócios Pendentes', 'adam-membership' ) );
		$this->render_notices();
		$this->render_member_filters( $filters, true );
		$this->render_complete_export_controls( $members, true );
		$this->render_members_table( $members, true, $filters );
		$this->render_footer();
	}

	/**
	 * Render the members page.
	 */
	public function render_members_page(): void {
		$this->ensure_can_manage();

		$filters = $this->current_member_filters();
		$members = $this->members->admin_members( $filters );

		$this->render_header( __( 'Sócios', 'adam-membership' ) );
		$this->render_notices();
		$this->render_member_filters( $filters, false );
		$this->render_complete_export_controls( $members, false );
		$this->render_members_table( $members, false, $filters );
		$this->render_footer();
	}

	/**
	 * Render the member history page.
	 */
	public function render_history_page(): void {
		$this->ensure_can_manage();

		$filters = $this->current_history_filters();
		$entries = $this->history_repository->query( $filters );

		$this->render_header( __( 'Histórico do Sócio', 'adam-membership' ) );
		$this->render_notices();
		$this->render_history_filters( $filters );
		$this->render_financial_history( (int) ( $filters['member_id'] ?? 0 ) );
		$this->render_history_timeline( $entries );
		$this->render_footer();
	}

	/** Render the read-only document archive for one member. */
	public function render_member_document_history_page(): void {
		$this->ensure_can_manage();
		$member_id = absint( $_GET['member_id'] ?? 0 );
		$member    = $member_id > 0 ? $this->members->find( $member_id ) : null;
		$this->render_header( __( 'Histórico de documentos', 'adam-membership' ) );
		$this->render_notices();
		if ( null === $member ) {
			$this->render_empty_state( __( 'Sócio não encontrado.', 'adam-membership' ) );
			$this->render_footer();
			return;
		}

		$groups = MemberDocumentHistoryService::group_items( $this->member_document_history->for_member( $member ) );
		printf( '<p><a class="button" href="%s">← %s</a></p>', esc_url( $this->member_url( $member ) ), esc_html__( 'Voltar aos detalhes do sócio', 'adam-membership' ) );
		printf( '<div class="adam-admin-panel adam-card"><h2>%s</h2><p>%s</p></div>', esc_html( $member->full_name() ), esc_html__( 'Arquivo histórico agregado dos documentos existentes. Os ficheiros não são copiados nem alterados nesta página.', 'adam-membership' ) );
		if ( array() === $groups ) {
			$this->render_empty_state( __( 'Ainda não existem documentos históricos para este sócio.', 'adam-membership' ) );
		} else {
			foreach ( $groups as $group ) {
				printf( '<div class="adam-admin-panel adam-card adam-document-history-group"><h2>%s — %s</h2><div class="adam-admin-document-list">', esc_html( (string) $group['year'] ), esc_html( (string) $group['request_label'] ) );
				foreach ( (array) $group['items'] as $item ) {
					$status = (string) ( $item['status'] ?? '' );
					$status_label = ! empty( $item['private'] ) ? $this->private_document_status_label( $status ) : __( 'Submetido', 'adam-membership' );
					$sent = ! empty( $item['private'] ) ? ( ! empty( $item['sent'] ) ? __( 'Enviado ao sócio', 'adam-membership' ) : __( 'Ainda não enviado', 'adam-membership' ) ) : '';
					$history_key = (string) ( $item['history_key'] ?? '' );
					$nonce_action = 'adam_membership_archive_document_history_' . $member->user_id() . '_' . $history_key;
					$remove_form = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return window.confirm(\'' . esc_js( __( 'Remover esta entrada apenas do histórico? O pedido e o ficheiro original serão preservados.', 'adam-membership' ) ) . '\');">';
					$remove_form .= '<input type="hidden" name="action" value="adam_membership_archive_document_history"><input type="hidden" name="member_id" value="' . esc_attr( (string) $member->user_id() ) . '"><input type="hidden" name="history_key" value="' . esc_attr( $history_key ) . '"><input type="hidden" name="redirect_to" value="' . esc_url( $this->member_document_history_url( $member ) ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $nonce_action ) ) . '"><button type="submit" class="button button-small">' . esc_html__( 'Remover do histórico', 'adam-membership' ) . '</button></form>';
					$delete_nonce_action = 'adam_membership_delete_document_history_' . $member->user_id() . '_' . $history_key;
					$delete_form = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline" onsubmit="return window.confirm(\'' . esc_js( __( 'Esta ação elimina permanentemente o ficheiro e não pode ser anulada. Confirma?', 'adam-membership' ) ) . '\');">';
					$delete_form .= '<input type="hidden" name="action" value="adam_membership_delete_document_history"><input type="hidden" name="member_id" value="' . esc_attr( (string) $member->user_id() ) . '"><input type="hidden" name="history_key" value="' . esc_attr( $history_key ) . '"><input type="hidden" name="redirect_to" value="' . esc_url( $this->member_document_history_url( $member ) ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( $delete_nonce_action ) ) . '"><button type="submit" class="button button-small button-link-delete">' . esc_html__( 'Eliminar ficheiro permanentemente', 'adam-membership' ) . '</button></form>';
					$actions = ( '' !== (string) ( $item['download_url'] ?? '' ) ? '<a class="button button-small" href="' . esc_url( (string) $item['download_url'] ) . '">' . esc_html__( 'Descarregar', 'adam-membership' ) . '</a> ' : '— ' ) . $remove_form . ' ' . $delete_form;
					printf( '<div class="adam-admin-document-row"><div class="adam-admin-document-cell"><strong>%s</strong><span class="adam-admin-document-filename">%s</span></div><div class="adam-admin-document-cell">%s</div><div class="adam-admin-document-cell">%s</div><div class="adam-admin-document-cell">%s</div><div class="adam-admin-document-cell">%s</div></div>', esc_html( (string) $item['document_type'] ), esc_html( (string) $item['filename'] ), esc_html( (string) $item['date'] ?: '—' ), esc_html( (string) $item['origin'] ), esc_html( trim( $status_label . ( '' !== $sent ? ' · ' . $sent : '' ) ) ), $actions );
				}
				echo '</div></div>';
			}
		}
		$this->render_footer();
	}

	/**
	 * Render renewal requests page.
	 */
	public function render_renewals_page(): void {
		$this->ensure_can_manage();

		$filters  = $this->current_renewal_filters();
		$requests = $this->renewal_repository->admin_requests( $filters );

		$this->render_header( __( 'Pedidos de Renovação', 'adam-membership' ) );
		$this->render_notices();
		$this->render_renewal_filters( $filters );
		$this->render_renewals_table( $requests );
		$this->render_footer();
	}

	/**
	 * Render the team administration list.
	 */
	public function render_teams_page(): void {
		$this->ensure_can_manage();

		$filters = $this->current_team_filters();
		$teams   = $this->teams->admin_list( $filters );

		$this->render_header( __( 'Lista de Equipas', 'adam-membership' ) );
		$this->render_notices();
		$this->render_team_filters( $filters );
		$this->render_teams_table( $teams, $filters );
		$this->render_footer();
	}

	/**
	 * Render one team administration page.
	 */
	public function render_team_page(): void {
		$this->ensure_can_manage();

		$team_id = isset( $_GET['team_id'] ) ? absint( wp_unslash( $_GET['team_id'] ) ) : 0;
		$team    = $this->teams->find( $team_id );

		$this->render_header( __( 'Detalhes da Equipa', 'adam-membership' ) );
		$this->render_notices();

		if ( null === $team ) {
			$this->render_empty_state( __( 'Equipa não encontrada.', 'adam-membership' ) );
			$this->render_footer();
			return;
		}

		$this->render_team_detail( $team );
		$this->render_footer();
	}

	/**
	 * Render a single renewal request review page.
	 */
	public function render_renewal_page(): void {
		$this->ensure_can_manage();

		$request_id = isset( $_GET['request_id'] ) ? absint( wp_unslash( $_GET['request_id'] ) ) : 0;
		$request    = $this->renewal_repository->find( $request_id );

		$this->render_header( __( 'Revisão da Renovação', 'adam-membership' ) );
		$this->render_notices();

		if ( null === $request ) {
			$this->render_empty_state( __( 'Pedido de renovação não encontrado.', 'adam-membership' ) );
			$this->render_footer();
			return;
		}

		$this->render_renewal_detail( $request );
		$this->render_footer();
	}

	/**
	 * Render a single member page.
	 */
	public function render_member_page(): void {
		$this->ensure_can_manage();

		$user_id = isset( $_GET['member_id'] ) ? absint( wp_unslash( $_GET['member_id'] ) ) : 0;
		$member  = $this->members->find( $user_id );

		$this->render_header( __( 'Detalhes do Sócio', 'adam-membership' ) );
		$this->render_notices();

		if ( null === $member ) {
			$this->render_empty_state( __( 'Sócio não encontrado.', 'adam-membership' ) );
			$this->render_footer();
			return;
		}

		$this->render_member_detail( $member );
		$this->render_footer();
	}

	/**
	 * Ensure the hidden member page always has a valid admin title.
	 */
	public function prepare_member_page_screen(): void {
		$user_id = isset( $_GET['member_id'] ) ? absint( wp_unslash( $_GET['member_id'] ) ) : 0;
		$member  = $user_id > 0 ? $this->members->find( $user_id ) : null;
		$title   = __( 'Detalhes do Sócio', 'adam-membership' );

		if ( null !== $member ) {
			$title = sprintf(
				/* translators: %s: member full name. */
				__( 'Detalhes do Sócio: %s', 'adam-membership' ),
				$member->full_name()
			);
		}

		$this->prime_admin_page_title( $title );
	}

	/**
	 * Ensure the hidden renewal page always has a valid admin title.
	 */
	public function prepare_renewal_page_screen(): void {
		$this->prime_admin_page_title( __( 'Pedido de Renovação', 'adam-membership' ) );
	}

	/**
	 * Ensure the hidden team page always has a valid admin title.
	 */
	public function prepare_team_page_screen(): void {
		$team_id = isset( $_GET['team_id'] ) ? absint( wp_unslash( $_GET['team_id'] ) ) : 0;
		$team    = $team_id > 0 ? $this->teams->find( $team_id ) : null;
		$title   = null !== $team
			? sprintf(
				/* translators: %s: team name. */
				__( 'Detalhes da Equipa: %s', 'adam-membership' ),
				$team->name()
			)
			: __( 'Detalhes da Equipa', 'adam-membership' );

		$this->prime_admin_page_title( $title );
	}

	/**
	 * Prepare context for hidden ADAM admin screens before the header renders.
	 *
	 * @param mixed $screen Current screen object when available.
	 */
	public function prepare_hidden_screen_context( mixed $screen ): void {
		if ( ! $this->is_member_page_request() && ! $this->is_renewal_page_request() && ! $this->is_team_page_request() && ! $this->is_apd_page_request() && ! $this->is_member_changes_page_request() ) {
			return;
		}

		if ( $this->is_member_page_request() ) {
			$this->prepare_member_page_screen();
			return;
		}

		if ( $this->is_renewal_page_request() ) {
			$this->prepare_renewal_page_screen();
			return;
		}
		if ( $this->is_apd_page_request() || $this->is_member_changes_page_request() ) {
			$this->prime_admin_page_title( 'Aprovações' );
			return;
		}

		$this->prepare_team_page_screen();
	}

	/**
	 * Keep hidden ADAM screens attached to the correct parent menu.
	 *
	 * @param string|null $parent_file Current parent file.
	 */
	public function filter_hidden_parent_file( ?string $parent_file ): ?string {
		if ( $this->is_member_page_request() || $this->is_renewal_page_request() || $this->is_team_page_request() ) {
			return self::MENU_SLUG;
		}

		return $parent_file;
	}

	/**
	 * Keep hidden ADAM screens attached to a valid submenu entry.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 */
	public function filter_hidden_submenu_file( ?string $submenu_file ): ?string {
		if ( $this->is_team_page_request() ) {
			return self::TEAMS_PAGE_SLUG;
		}

		if ( null === $submenu_file ) {
			return null;
		}

		if ( $this->is_member_page_request() ) {
			return self::MEMBER_PAGE_SLUG;
		}

		if ( $this->is_renewal_page_request() ) {
			return self::RENEWAL_PAGE_SLUG;
		}

		return $submenu_file;
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		$this->ensure_can_manage();

		$this->render_header( __( 'Configurações ADAM', 'adam-membership' ) );
		$this->render_notices();
		$this->render_private_document_storage_status();
		?>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Numeração de Sócios', 'adam-membership' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_save_settings">
				<?php wp_nonce_field( 'adam_membership_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Último número de sócio atribuído', 'adam-membership' ); ?></th>
						<td>
							<label for="adam_last_assigned_member_number" class="screen-reader-text"><?php esc_html_e( 'Último número de sócio atribuído', 'adam-membership' ); ?></label>
							<input type="number" id="adam_last_assigned_member_number" name="last_assigned_member_number" class="small-text" min="0" step="1" required value="<?php echo esc_attr( (string) $this->settings->last_assigned_member_number() ); ?>">
							<p class="description"><?php esc_html_e( 'Alterar este valor afeta apenas o próximo número a atribuir. Os números dos sócios existentes não serão alterados.', 'adam-membership' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Próximo número de sócio', 'adam-membership' ); ?></th>
						<td><code id="adam-next-member-number-preview"><?php echo esc_html( $this->settings->preview_next_member_number() ); ?></code></td>
					</tr>
				</table>
				<script>
				(function () {
					const input = document.getElementById('adam_last_assigned_member_number');
					const preview = document.getElementById('adam-next-member-number-preview');
					if (!input || !preview) return;
					input.addEventListener('input', function () {
						const value = Number.parseInt(input.value, 10);
						if (!Number.isInteger(value) || value < 0) return;
						preview.textContent = 'ADAM-' + String(value + 1).padStart(4, '0');
					});
				}());
				</script>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'URL da Área do Sócio', 'adam-membership' ); ?></th>
						<td><a href="<?php echo esc_url( $this->settings->member_area_url() ); ?>"><?php echo esc_html( $this->settings->member_area_url() ); ?></a></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_registration_page_url"><?php esc_html_e( 'URL da página de inscrição', 'adam-membership' ); ?></label></th>
						<td><input type="url" id="adam_registration_page_url" name="registration_page_url" class="regular-text" value="<?php echo esc_attr( $this->settings->registration_page_url() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_renewal_page_url"><?php esc_html_e( 'URL da página de renovação', 'adam-membership' ); ?></label></th>
						<td><input type="url" id="adam_renewal_page_url" name="renewal_page_url" class="regular-text" value="<?php echo esc_attr( $this->settings->renewal_page_url() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_email_from_name"><?php esc_html_e( 'Nome do remetente de email', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_email_from_name" name="email_from_name" class="regular-text" value="<?php echo esc_attr( $this->settings->email_from_name() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_email_from_address"><?php esc_html_e( 'Endereço de email do remetente', 'adam-membership' ); ?></label></th>
						<td><input type="email" id="adam_email_from_address" name="email_from_address" class="regular-text" value="<?php echo esc_attr( $this->settings->email_from_address() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_association_name"><?php esc_html_e( 'Nome da associação', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_association_name" name="association_name" class="regular-text" value="<?php echo esc_attr( $this->settings->association_name() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_association_logo"><?php esc_html_e( 'URL do logótipo da associação', 'adam-membership' ); ?></label></th>
						<td><input type="url" id="adam_association_logo" name="association_logo" class="regular-text" value="<?php echo esc_attr( $this->settings->association_logo_url() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_privacy_policy_url"><?php esc_html_e( 'URL da Politica de Privacidade', 'adam-membership' ); ?></label></th>
						<td><input type="url" id="adam_privacy_policy_url" name="privacy_policy_url" class="regular-text" value="<?php echo esc_attr( $this->settings->privacy_policy_url() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_cookie_policy_url"><?php esc_html_e( 'URL da Politica de Cookies', 'adam-membership' ); ?></label></th>
						<td><input type="url" id="adam_cookie_policy_url" name="cookie_policy_url" class="regular-text" value="<?php echo esc_attr( $this->settings->cookie_policy_url() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_membership_terms_url"><?php esc_html_e( 'URL dos Termos de Socio', 'adam-membership' ); ?></label></th>
						<td><input type="url" id="adam_membership_terms_url" name="membership_terms_url" class="regular-text" value="<?php echo esc_attr( $this->settings->membership_terms_url() ); ?>"></td>
					</tr>
				</table>
				<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Guardar configurações', 'adam-membership' ); ?></button>
			</form>
		</div>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Integração Google Sheets', 'adam-membership' ); ?></h2>
			<p><?php esc_html_e( 'A integração usa a API Google Sheets no servidor. As credenciais nunca são guardadas no WordPress nem mostradas nesta página.', 'adam-membership' ); ?></p>
			<?php $google_sheets = $this->settings->google_sheets_settings(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_save_settings">
				<?php wp_nonce_field( 'adam_membership_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Ativar integração', 'adam-membership' ); ?></th>
						<td><input type="hidden" name="google_sheets_enabled" value="0"><label><input type="checkbox" name="google_sheets_enabled" value="1" <?php checked( $google_sheets['enabled'] ); ?>> <?php esc_html_e( 'Ativada', 'adam-membership' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_google_sheets_spreadsheet_id"><?php esc_html_e( 'Spreadsheet ID', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_google_sheets_spreadsheet_id" name="google_sheets_spreadsheet_id" class="regular-text" value="<?php echo esc_attr( $google_sheets['spreadsheet_id'] ); ?>" autocomplete="off"><p class="description"><?php esc_html_e( 'O identificador existente na URL da spreadsheet.', 'adam-membership' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_google_sheets_sheet_name"><?php esc_html_e( 'Nome da página', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_google_sheets_sheet_name" name="google_sheets_sheet_name" class="regular-text" value="<?php echo esc_attr( $google_sheets['sheet_name'] ); ?>"><p class="description"><?php esc_html_e( 'Por defeito: Quotas. Nesta fase, o teste é exclusivamente de leitura.', 'adam-membership' ); ?></p></td>
					</tr>
				</table>
				<p><button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Guardar configurações', 'adam-membership' ); ?></button></p>
			</form>
			<hr>
			<p><strong><?php esc_html_e( 'Credenciais do servidor:', 'adam-membership' ); ?></strong> <code>ADAM_GOOGLE_SERVICE_ACCOUNT_JSON</code></p>
			<p class="description"><?php esc_html_e( 'Configure esta constante ou variável de ambiente no servidor com o caminho absoluto para o JSON da conta de serviço. O conteúdo do JSON não é apresentado.', 'adam-membership' ); ?></p>
			<p><strong><?php esc_html_e( 'Estado:', 'adam-membership' ); ?></strong> <?php echo esc_html( $this->google_sheets_status_label( $google_sheets['status'] ) ); ?><?php if ( '' !== $google_sheets['last_test_at'] ) : ?> — <?php echo esc_html( sprintf( __( 'último teste: %s', 'adam-membership' ), $google_sheets['last_test_at'] ) ); ?><?php endif; ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_test_google_sheets">
				<?php wp_nonce_field( 'adam_membership_test_google_sheets' ); ?>
				<button type="submit" class="button button-secondary adam-button adam-button--secondary"><?php esc_html_e( 'Testar ligação (read-only)', 'adam-membership' ); ?></button>
			</form>
		</div>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Manutenção agendada', 'adam-membership' ); ?></h2>
			<p><?php esc_html_e( 'A manutenção de sócios é executada diariamente através do WP-Cron. Utilize este botão para executar o mesmo processo imediatamente para testes.', 'adam-membership' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_run_maintenance">
				<?php wp_nonce_field( 'adam_membership_run_maintenance' ); ?>
				<button type="submit" class="button button-secondary adam-button adam-button--secondary"><?php esc_html_e( 'Executar manutenção agora', 'adam-membership' ); ?></button>
			</form>
		</div>
		<?php
		$this->render_footer();
	}

	/**
	 * Render the native forms admin page.
	 */
	public function render_forms_page(): void {
		$this->ensure_can_manage();
		$settings = $this->settings->membership_form_settings();
		$selected = isset( $_GET['form_view'] ) ? sanitize_key( wp_unslash( $_GET['form_view'] ) ) : 'registration';
		$allowed  = array( 'registration', 'renewal', 'update', 'apd', 'correction' );
		if ( ! in_array( $selected, $allowed, true ) ) {
			$selected = 'registration';
		}

		$this->render_header( 'Formulários ADAM' );
		$this->render_notices();
		$tabs = array(
			'registration' => 'Inscrição',
			'renewal'      => 'Renovação',
			'update'       => 'Atualizar dados',
			'apd'          => 'Associar APD',
			'correction'   => 'Corrigir pedido',
		);
		?><div class="adam-admin-panel adam-card"><nav class="adam-admin-tabs" aria-label="Formulários ADAM"><?php foreach ( $tabs as $key => $label ) : ?><a class="<?php echo $selected === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::FORMS_PAGE_SLUG, 'form_view' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav></div><?php

		if ( in_array( $selected, array( 'update', 'apd', 'correction' ), true ) ) {
			$this->render_special_form_manager( $selected, $settings );
			$this->render_footer();
			return;
		}

		$this->render_native_form_manager( $selected, $settings );
		$this->render_footer();
	}

	/** Render one native form configuration view while retaining existing settings. */
	private function render_native_form_manager( string $selected, array $settings ): void {
		$this->render_simple_native_form_manager( $selected, $settings );
		return;
		/* Legacy technical builder retained below for compatibility. */
		$group = 'registration' === $selected ? 'registration_fields' : 'renewal_fields';
		$form_config = (array) ( $settings['forms'][ $selected ] ?? array() );
		?><div class="adam-admin-panel adam-card"><h2><?php echo esc_html( 'registration' === $selected ? 'Inscrição' : 'Renovação' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adam_membership_save_forms_settings"><input type="hidden" name="form_view" value="<?php echo esc_attr( $selected ); ?>"><?php wp_nonce_field( 'adam_membership_save_forms_settings' ); ?>
		<table class="form-table" role="presentation"><tr><th>Estado</th><td><label><input type="hidden" name="membership_forms[forms][<?php echo esc_attr( $selected ); ?>][enabled]" value="0"><input type="checkbox" name="membership_forms[forms][<?php echo esc_attr( $selected ); ?>][enabled]" value="1" <?php checked( ! empty( $form_config['enabled'] ) ); ?>> Ativado</label></td></tr><tr><th><label for="adam_<?php echo esc_attr( $selected ); ?>_page_url">Página atribuída</label></th><td><input type="url" id="adam_<?php echo esc_attr( $selected ); ?>_page_url" name="membership_forms[forms][<?php echo esc_attr( $selected ); ?>][page_url]" class="regular-text" value="<?php echo esc_attr( (string) ( $form_config['page_url'] ?? ( 'registration' === $selected ? $this->settings->registration_page_url() : $this->settings->renewal_page_url() ) ) ); ?>"></td></tr></table>
		<?php if ( 'registration' === $selected ) : ?><p><strong>Shortcode:</strong> <code>[adam_registration_form]</code></p><p><strong>Bloco genérico:</strong> <code>[adam_membership_form type="registration"]</code></p><?php else : ?><p><strong>Shortcode:</strong> <code>[adam_renewal_form]</code></p><p><strong>Bloco genérico:</strong> <code>[adam_membership_form type="renewal"]</code></p><?php endif; ?>
		<?php if ( 'renewal' === $selected ) : ?><h3>Quotas e pagamento</h3><table class="form-table"><tr><th>Quota ADAM + ANA</th><td><input type="text" name="membership_forms[fees][primary]" class="small-text" value="<?php echo esc_attr( (string) $settings['fees']['primary'] ); ?>"> €</td></tr><tr><th>Quota apenas ADAM</th><td><input type="text" name="membership_forms[fees][secondary]" class="small-text" value="<?php echo esc_attr( (string) $settings['fees']['secondary'] ); ?>"> €</td></tr><tr><th>Instruções de pagamento</th><td><textarea name="membership_forms[payment][instructions]" class="large-text" rows="4"><?php echo esc_textarea( (string) $settings['payment']['instructions'] ); ?></textarea></td></tr></table><?php endif; ?>
		<h3>Campos utilizados</h3><?php $this->render_membership_form_fields_table( $group, (array) $settings[ $group ] ); ?><p><button type="submit" class="button button-primary adam-button">Guardar formulário</button></p></form></div><?php
		$this->render_membership_form_builder_script();
	}

	/** Render a simplified administrator-facing native form editor. */
	private function render_simple_native_form_manager( string $selected, array $settings ): void {
		$group = 'registration' === $selected ? 'registration_fields' : 'renewal_fields';
		$form_config = (array) ( $settings['forms'][ $selected ] ?? array() );
		$fields = (array) ( $settings[ $group ] ?? array() );
		?><div class="adam-admin-panel adam-card"><h2><?php echo esc_html( 'registration' === $selected ? 'Inscrição' : 'Renovação' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adam_membership_save_forms_settings"><?php wp_nonce_field( 'adam_membership_save_forms_settings' ); ?><table class="form-table"><tr><th>Estado</th><td><input type="hidden" name="membership_forms[forms][<?php echo esc_attr( $selected ); ?>][enabled]" value="0"><label><input type="checkbox" name="membership_forms[forms][<?php echo esc_attr( $selected ); ?>][enabled]" value="1" <?php checked( ! empty( $form_config['enabled'] ) ); ?>> Ativado</label></td></tr><tr><th>Página atribuída</th><td><input type="url" class="regular-text" name="membership_forms[forms][<?php echo esc_attr( $selected ); ?>][page_url]" value="<?php echo esc_attr( (string) ( $form_config['page_url'] ?? ( 'registration' === $selected ? $this->settings->registration_page_url() : $this->settings->renewal_page_url() ) ) ); ?>"></td></tr></table><p><strong>Shortcode:</strong> <code>[adam_<?php echo esc_html( 'registration' === $selected ? 'registration' : 'renewal' ); ?>_form]</code></p><?php if ( 'renewal' === $selected ) : ?><h3>Quotas e pagamento</h3><table class="form-table"><tr><th>Quota ADAM + ANA</th><td><input type="text" class="small-text" name="membership_forms[fees][primary]" value="<?php echo esc_attr( (string) $settings['fees']['primary'] ); ?>"> €</td></tr><tr><th>Quota apenas ADAM</th><td><input type="text" class="small-text" name="membership_forms[fees][secondary]" value="<?php echo esc_attr( (string) $settings['fees']['secondary'] ); ?>"> €</td></tr><tr><th>Instruções de pagamento</th><td><textarea class="large-text" rows="4" name="membership_forms[payment][instructions]"><?php echo esc_textarea( (string) $settings['payment']['instructions'] ); ?></textarea></td></tr></table><?php endif; ?><h3>Campos do formulário</h3><p>Selecione os campos e ajuste a ordem e obrigatoriedade sem alterar a estrutura interna dos dados.</p><table class="widefat striped adam-simple-form-fields"><thead><tr><th>Usar</th><th>Campo</th><th>Obrigatório</th><th>Ordem</th></tr></thead><tbody><?php $index = 0; foreach ( $fields as $key => $field ) : ?><tr><td><input type="hidden" name="membership_forms[<?php echo esc_attr( $group ); ?>][row_<?php echo esc_attr( (string) $index ); ?>][field_key]" value="<?php echo esc_attr( $key ); ?>"><input type="hidden" name="membership_forms[<?php echo esc_attr( $group ); ?>][row_<?php echo esc_attr( (string) $index ); ?>][enabled]" value="0"><input type="checkbox" name="membership_forms[<?php echo esc_attr( $group ); ?>][row_<?php echo esc_attr( (string) $index ); ?>][enabled]" value="1" <?php checked( ! empty( $field['enabled'] ) ); ?>></td><td><label for="adam-field-label-<?php echo esc_attr( $selected . '-' . $index ); ?>"><?php echo esc_html( $field['label'] ?? $key ); ?></label><input type="hidden" id="adam-field-label-<?php echo esc_attr( $selected . '-' . $index ); ?>" name="membership_forms[<?php echo esc_attr( $group ); ?>][row_<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( (string) ( $field['label'] ?? $key ) ); ?>"></td><td><input type="checkbox" name="membership_forms[<?php echo esc_attr( $group ); ?>][row_<?php echo esc_attr( (string) $index ); ?>][required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?>></td><td><input type="number" class="small-text" min="0" step="1" name="membership_forms[<?php echo esc_attr( $group ); ?>][row_<?php echo esc_attr( (string) $index ); ?>][order]" value="<?php echo esc_attr( (string) ( $field['order'] ?? $index ) ); ?>"></td></tr><?php $index++; endforeach; ?></tbody></table><p><button type="submit" class="button button-primary adam-button">Guardar formulário</button></p></form></div><?php
	}

	/** Render a simplified administrator-facing update/APD editor. */
	private function render_simple_special_form_manager( string $form, array $settings ): void {
		$config = (array) ( $settings['forms'][ $form ] ?? array() );
		if ( 'correction' === $form ) {
			?><div class="adam-admin-panel adam-card"><h2>Corrigir pedido</h2><p>Este formulário é utilizado apenas a partir de um pedido rejeitado ao qual a ADAM tenha solicitado correções. A estrutura e os campos são herdados do pedido original.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adam_membership_save_forms_settings"><?php wp_nonce_field( 'adam_membership_save_forms_settings' ); ?><table class="form-table"><tr><th>Estado</th><td><input type="hidden" name="membership_forms[forms][correction][enabled]" value="0"><label><input type="checkbox" name="membership_forms[forms][correction][enabled]" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>> Ativado</label></td></tr><tr><th>Página atribuída</th><td><input type="url" class="regular-text" name="membership_forms[forms][correction][page_url]" value="<?php echo esc_attr( (string) ( $config['page_url'] ?? '' ) ); ?>"></td></tr><tr><th>Ajuda / instruções</th><td><textarea class="large-text" rows="3" name="membership_forms[forms][correction][help]"><?php echo esc_textarea( (string) ( $config['help'] ?? '' ) ); ?></textarea></td></tr></table><p>Os campos não são definidos aqui: o formulário apresenta o instantâneo do pedido original e apenas fica disponível quando a ADAM pede uma correção.</p><p><button type="submit" class="button button-primary adam-button">Guardar formulário</button></p></form></div><?php
			return;
		}
		$fields = (array) $settings['registration_fields'];
		?><div class="adam-admin-panel adam-card"><h2><?php echo esc_html( 'update' === $form ? 'Atualizar dados' : 'Associar APD através da ADAM' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adam_membership_save_forms_settings"><?php wp_nonce_field( 'adam_membership_save_forms_settings' ); ?><table class="form-table"><tr><th>Estado</th><td><input type="hidden" name="membership_forms[forms][<?php echo esc_attr( $form ); ?>][enabled]" value="0"><label><input type="checkbox" name="membership_forms[forms][<?php echo esc_attr( $form ); ?>][enabled]" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>> Ativado</label></td></tr><tr><th>Página atribuída</th><td><input type="url" class="regular-text" name="membership_forms[forms][<?php echo esc_attr( $form ); ?>][page_url]" value="<?php echo esc_attr( (string) ( $config['page_url'] ?? '' ) ); ?>"></td></tr><tr><th>Ajuda / instruções</th><td><textarea class="large-text" rows="3" name="membership_forms[forms][<?php echo esc_attr( $form ); ?>][help]"><?php echo esc_textarea( (string) ( $config['help'] ?? '' ) ); ?></textarea></td></tr></table><h3>Campos do formulário</h3><p>Selecione apenas a informação necessária para este fluxo.</p><table class="widefat striped adam-simple-form-fields"><thead><tr><th>Usar</th><th>Campo</th><th>Ordem</th></tr></thead><tbody><?php foreach ( $fields as $key => $field ) : ?><tr><td><input type="checkbox" name="membership_forms[forms][<?php echo esc_attr( $form ); ?>][fields][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $config['fields'], true ) ); ?>></td><td><?php echo esc_html( $field['label'] ?? $key ); ?></td><td><?php echo esc_html( (string) ( $field['order'] ?? '' ) ); ?></td></tr><?php endforeach; ?></tbody></table><?php if ( 'apd' === $form ) : ?><h3>Valores para associar APD através da ADAM</h3><p>O valor depende do tempo decorrido desde o início do período atual do sócio.</p><table class="form-table"><tr><th>0–3 meses</th><td><input type="text" class="small-text" name="membership_forms[apd_association_fees][0_3]" value="<?php echo esc_attr( (string) $settings['apd_association_fees']['0_3'] ); ?>"> €</td></tr><tr><th>4–6 meses</th><td><input type="text" class="small-text" name="membership_forms[apd_association_fees][4_6]" value="<?php echo esc_attr( (string) $settings['apd_association_fees']['4_6'] ); ?>"> €</td></tr><tr><th>7–9 meses</th><td><input type="text" class="small-text" name="membership_forms[apd_association_fees][7_9]" value="<?php echo esc_attr( (string) $settings['apd_association_fees']['7_9'] ); ?>"> €</td></tr><tr><th>10 meses ou mais</th><td><input type="text" class="small-text" name="membership_forms[apd_association_fees][10_plus]" value="<?php echo esc_attr( (string) $settings['apd_association_fees']['10_plus'] ); ?>"> €</td></tr><tr><th>Instruções de pagamento</th><td><textarea class="large-text" rows="4" name="membership_forms[payment][instructions]"><?php echo esc_textarea( (string) $settings['payment']['instructions'] ); ?></textarea></td></tr></table><?php endif; ?><p><button type="submit" class="button button-primary adam-button">Guardar formulário</button></p></form></div><?php
	}

	/** Render update/APD views using the shared registration field definitions. */
	private function render_special_form_manager( string $form, array $settings ): void {
		$this->render_simple_special_form_manager( $form, $settings );
		return;
		/* Legacy technical builder retained below for compatibility. */
		$config = (array) $settings['forms'][ $form ];
		$fields = (array) $settings['registration_fields'];
		?><div class="adam-admin-panel adam-card"><h2><?php echo esc_html( 'update' === $form ? 'Atualizar dados' : 'Associar APD através da ADAM' ); ?></h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adam_membership_save_forms_settings"><input type="hidden" name="form_view" value="<?php echo esc_attr( $form ); ?>"><?php wp_nonce_field( 'adam_membership_save_forms_settings' ); ?><table class="form-table"><tr><th>Estado</th><td><label><input type="hidden" name="membership_forms[forms][<?php echo esc_attr( $form ); ?>][enabled]" value="0"><input type="checkbox" name="membership_forms[forms][<?php echo esc_attr( $form ); ?>][enabled]" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>> Ativado</label></td></tr><tr><th>Página atribuída</th><td><input type="url" class="regular-text" name="membership_forms[forms][<?php echo esc_attr( $form ); ?>][page_url]" value="<?php echo esc_attr( (string) ( $config['page_url'] ?? '' ) ); ?>"></td></tr><tr><th>Ajuda / instruções</th><td><textarea class="large-text" rows="3" name="membership_forms[forms][<?php echo esc_attr( $form ); ?>][help]"><?php echo esc_textarea( (string) ( $config['help'] ?? '' ) ); ?></textarea></td></tr></table><h3>Campos partilhados</h3><p>As definições, tipos e opções são herdadas do registo comum de campos.</p><table class="widefat striped"><thead><tr><th>Usar</th><th>Campo</th><th>Tipo</th><th>Comportamento</th></tr></thead><tbody><?php foreach ( $fields as $key => $field ) : ?><tr><td><input type="checkbox" name="membership_forms[forms][<?php echo esc_attr( $form ); ?>][fields][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $config['fields'], true ) ); ?>></td><td><?php echo esc_html( $field['label'] ?? $key ); ?></td><td><?php echo esc_html( $field['type'] ?? 'text' ); ?></td><td><?php echo esc_html( 'apd' === $form ? 'Pré-preenchido; editável quando autorizado' : 'Editável pelo sócio' ); ?></td></tr><?php endforeach; ?></tbody></table><p><button type="submit" class="button button-primary adam-button">Guardar formulário</button></p></form></div><?php
	}

	/** Show the canonical field registry and where each field is used. */
	private function render_shared_fields_manager( array $settings ): void {
		?><div class="adam-admin-panel adam-card"><h2>Campos</h2><p>Os campos abaixo são definidos uma vez e reutilizados pelos formulários. As chaves de armazenamento existentes são preservadas.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adam_membership_save_forms_settings"><?php wp_nonce_field( 'adam_membership_save_forms_settings' ); ?><h3>Registo comum de campos</h3><?php $this->render_membership_form_fields_table( 'registration_fields', (array) $settings['registration_fields'] ); ?><p><button type="submit" class="button button-primary adam-button">Guardar campos</button></p></form><p class="description">Os mesmos campos e opções são reutilizados em Inscrição, Atualizar dados e Associar APD. As chaves de armazenamento não são alteradas.</p></div><?php
		$this->render_membership_form_builder_script();
	}

	public function render_legacy_forms_page(): void {
		$this->ensure_can_manage();

		$settings = $this->settings->membership_form_settings();

		$this->render_header( __( 'Formulários ADAM', 'adam-membership' ) );
		$this->render_notices();
		?>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Inscrição e renovação nativas', 'adam-membership' ); ?></h2>
			<p><?php esc_html_e( 'Gerir os formulários públicos /inscricao/ e /renovar-quota/, incluindo estados, páginas atribuídas, campos, quotas, instruções de pagamento e textos legais.', 'adam-membership' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_save_forms_settings">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( admin_url( 'admin.php?page=' . self::FORMS_PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( 'adam_membership_save_forms_settings' ); ?>

				<h3><?php esc_html_e( 'Estado e publicação', 'adam-membership' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Formulário de inscrição', 'adam-membership' ); ?></th>
						<td>
							<label><input type="checkbox" name="membership_forms[forms][registration][enabled]" value="1" <?php checked( ! empty( $settings['forms']['registration']['enabled'] ) ); ?>> <?php esc_html_e( 'Ativado', 'adam-membership' ); ?></label>
							<p><strong><?php esc_html_e( 'Página atribuída:', 'adam-membership' ); ?></strong> <input type="url" name="registration_page_url" class="regular-text" value="<?php echo esc_attr( $this->settings->registration_page_url() ); ?>"></p>
							<p><strong><?php esc_html_e( 'Shortcode:', 'adam-membership' ); ?></strong> <code>[adam_registration_form]</code></p>
							<p><strong><?php esc_html_e( 'Bloco/atalho genérico:', 'adam-membership' ); ?></strong> <code>[adam_membership_form type="registration"]</code></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Formulário de renovação', 'adam-membership' ); ?></th>
						<td>
							<label><input type="checkbox" name="membership_forms[forms][renewal][enabled]" value="1" <?php checked( ! empty( $settings['forms']['renewal']['enabled'] ) ); ?>> <?php esc_html_e( 'Ativado', 'adam-membership' ); ?></label>
							<p><strong><?php esc_html_e( 'Página atribuída:', 'adam-membership' ); ?></strong> <input type="url" name="renewal_page_url" class="regular-text" value="<?php echo esc_attr( $this->settings->renewal_page_url() ); ?>"></p>
							<p><strong><?php esc_html_e( 'Shortcode:', 'adam-membership' ); ?></strong> <code>[adam_renewal_form]</code></p>
							<p><strong><?php esc_html_e( 'Bloco/atalho genérico:', 'adam-membership' ); ?></strong> <code>[adam_membership_form type="renewal"]</code></p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Quotas e pagamento', 'adam-membership' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adam_fee_primary"><?php esc_html_e( 'Quota anual ADAM principal', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_fee_primary" name="membership_forms[fees][primary]" class="small-text" value="<?php echo esc_attr( (string) $settings['fees']['primary'] ); ?>"> €</td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_fee_secondary"><?php esc_html_e( 'Quota anual outra associação', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_fee_secondary" name="membership_forms[fees][secondary]" class="small-text" value="<?php echo esc_attr( (string) $settings['fees']['secondary'] ); ?>"> €</td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_payment_mbway"><?php esc_html_e( 'MB Way', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_payment_mbway" name="membership_forms[payment][mbway]" class="regular-text" value="<?php echo esc_attr( (string) $settings['payment']['mbway'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_payment_iban"><?php esc_html_e( 'IBAN', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_payment_iban" name="membership_forms[payment][iban]" class="regular-text" value="<?php echo esc_attr( (string) $settings['payment']['iban'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_payment_instructions"><?php esc_html_e( 'Instruções de pagamento', 'adam-membership' ); ?></label></th>
						<td><textarea id="adam_payment_instructions" name="membership_forms[payment][instructions]" class="large-text" rows="4"><?php echo esc_textarea( (string) $settings['payment']['instructions'] ); ?></textarea></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Textos legais e ajuda', 'adam-membership' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adam_registration_help"><?php esc_html_e( 'Ajuda da inscrição', 'adam-membership' ); ?></label></th>
						<td><textarea id="adam_registration_help" name="membership_forms[legal][registration_help]" class="large-text" rows="3"><?php echo esc_textarea( (string) $settings['legal']['registration_help'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_renewal_help"><?php esc_html_e( 'Ajuda da renovação', 'adam-membership' ); ?></label></th>
						<td><textarea id="adam_renewal_help" name="membership_forms[legal][renewal_help]" class="large-text" rows="3"><?php echo esc_textarea( (string) $settings['legal']['renewal_help'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_registration_privacy_text"><?php esc_html_e( 'Texto de privacidade da inscrição', 'adam-membership' ); ?></label></th>
						<td><textarea id="adam_registration_privacy_text" name="membership_forms[legal][registration_privacy_text]" class="large-text" rows="2"><?php echo esc_textarea( (string) $settings['legal']['registration_privacy_text'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_renewal_privacy_text"><?php esc_html_e( 'Texto de privacidade da renovação', 'adam-membership' ); ?></label></th>
						<td><textarea id="adam_renewal_privacy_text" name="membership_forms[legal][renewal_privacy_text]" class="large-text" rows="2"><?php echo esc_textarea( (string) $settings['legal']['renewal_privacy_text'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_forms_privacy_policy_url"><?php esc_html_e( 'Ligação da Política de Privacidade', 'adam-membership' ); ?></label></th>
						<td><input type="url" id="adam_forms_privacy_policy_url" name="privacy_policy_url" class="regular-text" value="<?php echo esc_attr( $this->settings->privacy_policy_url() ); ?>"></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Campos da inscrição', 'adam-membership' ); ?></h3>
				<p class="adam-admin-panel-copy"><?php esc_html_e( 'Crie campos personalizados, reorganize a ordem e ajuste as condições de visibilidade sem alterar código.', 'adam-membership' ); ?></p>
				<?php $this->render_membership_form_fields_table( 'registration_fields', (array) $settings['registration_fields'] ); ?>

				<h3><?php esc_html_e( 'Campos da renovação', 'adam-membership' ); ?></h3>
				<p class="adam-admin-panel-copy"><?php esc_html_e( 'Os campos podem surgir sempre, apenas quando o sócio altera dados ou apenas quando renova através de outra associação.', 'adam-membership' ); ?></p>
				<?php $this->render_membership_form_fields_table( 'renewal_fields', (array) $settings['renewal_fields'] ); ?>

				<p><button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Guardar formulários', 'adam-membership' ); ?></button></p>
			</form>
		</div>
		<?php $this->render_membership_form_builder_script(); ?>
		<?php
		$this->render_footer();
	}

	/**
	 * Render the automatic emails admin page.
	 */
	public function render_emails_page(): void {
		$this->ensure_can_manage();

		$templates = $this->email->admin_templates();
		$settings  = $this->settings->email_template_settings();
		$user      = wp_get_current_user();
		$test_to   = $user instanceof \WP_User ? $user->user_email : '';

		$this->render_header( __( 'Emails ADAM', 'adam-membership' ) );
		$this->render_notices();
		?>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Emails automáticos do plugin', 'adam-membership' ); ?></h2>
			<p><?php esc_html_e( 'Gerir assunto, conteúdo, estado, pré-visualização e envio de teste dos emails automáticos da plataforma ADAM.', 'adam-membership' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_save_email_settings">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( admin_url( 'admin.php?page=' . self::EMAILS_PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( 'adam_membership_save_email_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adam_email_from_name"><?php esc_html_e( 'Nome do remetente', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_email_from_name" name="email_from_name" class="regular-text" value="<?php echo esc_attr( $this->settings->email_from_name() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_email_from_address"><?php esc_html_e( 'Email do remetente', 'adam-membership' ); ?></label></th>
						<td><input type="email" id="adam_email_from_address" name="email_from_address" class="regular-text" value="<?php echo esc_attr( $this->settings->email_from_address() ); ?>"></td>
					</tr>
				</table>

				<?php foreach ( $templates as $template_key => $template_meta ) : ?>
					<?php $template_config = is_array( $settings[ $template_key ] ?? null ) ? $settings[ $template_key ] : array(); ?>
					<?php $preview = $this->email->preview_email_template( $template_key ); ?>
					<div class="adam-admin-panel adam-card" style="margin-top:20px;">
						<h3><?php echo esc_html( (string) $template_meta['label'] ); ?></h3>
						<p><?php echo esc_html( (string) $template_meta['description'] ); ?></p>
						<p><label><input type="checkbox" name="email_templates[<?php echo esc_attr( $template_key ); ?>][enabled]" value="1" <?php checked( ! empty( $template_config['enabled'] ) ); ?>> <?php esc_html_e( 'Email ativado', 'adam-membership' ); ?></label></p>
						<p>
							<label><?php esc_html_e( 'Assunto', 'adam-membership' ); ?></label><br>
							<input type="text" class="large-text" name="email_templates[<?php echo esc_attr( $template_key ); ?>][subject]" value="<?php echo esc_attr( (string) ( $template_config['subject'] ?? '' ) ); ?>">
						</p>
						<p>
							<label><?php esc_html_e( 'Conteúdo', 'adam-membership' ); ?></label><br>
							<textarea class="large-text" rows="8" name="email_templates[<?php echo esc_attr( $template_key ); ?>][body]"><?php echo esc_textarea( (string) ( $template_config['body'] ?? '' ) ); ?></textarea>
						</p>
						<p><strong><?php esc_html_e( 'Placeholders disponíveis:', 'adam-membership' ); ?></strong>
							<?php foreach ( (array) $template_meta['placeholders'] as $placeholder ) : ?>
								<code>{{<?php echo esc_html( (string) $placeholder ); ?>}}</code>
							<?php endforeach; ?>
						</p>
						<?php if ( is_array( $preview ) ) : ?>
							<div style="border:1px solid var(--adam-border, #d9e4dc);border-radius:var(--adam-radius, 12px);background:var(--adam-surface, #fff);padding:16px;margin-top:16px;">
								<p><strong><?php esc_html_e( 'Pré-visualização do assunto:', 'adam-membership' ); ?></strong> <?php echo esc_html( $preview['subject'] ); ?></p>
								<div><?php echo wp_kses_post( $preview['html'] ); ?></div>
							</div>
						<?php endif; ?>
						<p style="margin-top:16px;">
							<button type="submit" class="button button-secondary adam-button adam-button--secondary" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=adam_membership_send_test_email' ) ); ?>" formmethod="post" name="template_key" value="<?php echo esc_attr( $template_key ); ?>"><?php echo esc_html( sprintf( __( 'Enviar teste para %s', 'adam-membership' ), $test_to ) ); ?></button>
						</p>
					</div>
				<?php endforeach; ?>

				<p><button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Guardar emails', 'adam-membership' ); ?></button></p>
			</form>
		</div>
		<?php
		$this->render_footer();
	}

	/**
	 * Render native membership form settings panel.
	 */
	private function render_membership_forms_settings_panel(): void {
		$settings = $this->settings->membership_form_settings();
		?>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Formulários Nativos de Inscrição e Renovação', 'adam-membership' ); ?></h2>
			<p><?php esc_html_e( 'Utilize os shortcodes [adam_registration_form] e [adam_renewal_form] nas páginas públicas. As opções abaixo controlam campos, valores e textos legais dos formulários nativos.', 'adam-membership' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_save_settings">
				<?php wp_nonce_field( 'adam_membership_save_settings' ); ?>

				<h3><?php esc_html_e( 'Quotas e pagamento', 'adam-membership' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adam_fee_primary"><?php esc_html_e( 'Quota anual ADAM principal', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_fee_primary" name="membership_forms[fees][primary]" class="small-text" value="<?php echo esc_attr( (string) $settings['fees']['primary'] ); ?>"> €</td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_fee_secondary"><?php esc_html_e( 'Quota anual associação externa', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_fee_secondary" name="membership_forms[fees][secondary]" class="small-text" value="<?php echo esc_attr( (string) $settings['fees']['secondary'] ); ?>"> €</td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_payment_mbway"><?php esc_html_e( 'MB Way', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_payment_mbway" name="membership_forms[payment][mbway]" class="regular-text" value="<?php echo esc_attr( (string) $settings['payment']['mbway'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_payment_iban"><?php esc_html_e( 'IBAN', 'adam-membership' ); ?></label></th>
						<td><input type="text" id="adam_payment_iban" name="membership_forms[payment][iban]" class="regular-text" value="<?php echo esc_attr( (string) $settings['payment']['iban'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_payment_instructions"><?php esc_html_e( 'Instruções de pagamento', 'adam-membership' ); ?></label></th>
						<td><textarea id="adam_payment_instructions" name="membership_forms[payment][instructions]" class="large-text" rows="4"><?php echo esc_textarea( (string) $settings['payment']['instructions'] ); ?></textarea></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Textos legais e ajuda', 'adam-membership' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adam_registration_help"><?php esc_html_e( 'Ajuda da inscrição', 'adam-membership' ); ?></label></th>
						<td><textarea id="adam_registration_help" name="membership_forms[legal][registration_help]" class="large-text" rows="3"><?php echo esc_textarea( (string) $settings['legal']['registration_help'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_renewal_help"><?php esc_html_e( 'Ajuda da renovação', 'adam-membership' ); ?></label></th>
						<td><textarea id="adam_renewal_help" name="membership_forms[legal][renewal_help]" class="large-text" rows="3"><?php echo esc_textarea( (string) $settings['legal']['renewal_help'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_registration_privacy_text"><?php esc_html_e( 'Texto de privacidade da inscrição', 'adam-membership' ); ?></label></th>
						<td><textarea id="adam_registration_privacy_text" name="membership_forms[legal][registration_privacy_text]" class="large-text" rows="2"><?php echo esc_textarea( (string) $settings['legal']['registration_privacy_text'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_renewal_privacy_text"><?php esc_html_e( 'Texto de privacidade da renovação', 'adam-membership' ); ?></label></th>
						<td><textarea id="adam_renewal_privacy_text" name="membership_forms[legal][renewal_privacy_text]" class="large-text" rows="2"><?php echo esc_textarea( (string) $settings['legal']['renewal_privacy_text'] ); ?></textarea></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Campos da inscrição', 'adam-membership' ); ?></h3>
				<?php $this->render_membership_form_fields_table( 'registration_fields', (array) $settings['registration_fields'] ); ?>

				<h3><?php esc_html_e( 'Campos da renovação', 'adam-membership' ); ?></h3>
				<?php $this->render_membership_form_fields_table( 'renewal_fields', (array) $settings['renewal_fields'] ); ?>

				<p><button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Guardar formulários', 'adam-membership' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a form field configuration table.
	 *
	 * @param string               $group Group key.
	 * @param array<string, mixed> $fields Fields.
	 */
	private function render_membership_form_fields_table( string $group, array $fields ): void {
		$condition_options = $this->membership_form_condition_options( $group );
		$type_options      = $this->membership_form_type_options();
		$row_index         = 0;
		?>
		<div class="adam-form-builder" data-adam-form-builder="<?php echo esc_attr( $group ); ?>" data-condition-options="<?php echo esc_attr( wp_json_encode( $condition_options ) ?: '[]' ); ?>" data-type-options="<?php echo esc_attr( wp_json_encode( $type_options ) ?: '[]' ); ?>">
			<p><button type="button" class="button button-secondary adam-button adam-button--secondary" data-adam-add-field><?php esc_html_e( 'Adicionar novo campo', 'adam-membership' ); ?></button></p>
			<table class="widefat striped adam-admin-table adam-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Ordem', 'adam-membership' ); ?></th>
						<th><?php esc_html_e( 'Campo', 'adam-membership' ); ?></th>
						<th><?php esc_html_e( 'Tipo', 'adam-membership' ); ?></th>
						<th><?php esc_html_e( 'Ativo', 'adam-membership' ); ?></th>
						<th><?php esc_html_e( 'Obrigatório', 'adam-membership' ); ?></th>
						<th><?php esc_html_e( 'Condicional', 'adam-membership' ); ?></th>
						<th><?php esc_html_e( 'Rótulo', 'adam-membership' ); ?></th>
						<th><?php esc_html_e( 'Texto de ajuda', 'adam-membership' ); ?></th>
						<th><?php esc_html_e( 'Opções', 'adam-membership' ); ?></th>
						<th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th>
					</tr>
				</thead>
				<tbody data-adam-form-builder-body>
					<?php foreach ( $fields as $field_key => $config ) : ?>
						<?php $row_name = 'membership_forms[' . $group . '][row_' . $row_index . ']'; ?>
						<tr data-adam-form-row="<?php echo esc_attr( (string) $field_key ); ?>" data-system-field="<?php echo ! empty( $config['locked'] ) ? '1' : '0'; ?>">
							<td><input type="number" min="1" class="small-text" data-adam-order-input name="<?php echo esc_attr( $row_name ); ?>[order]" value="<?php echo esc_attr( (string) ( $config['order'] ?? ( $row_index + 1 ) ) ); ?>"></td>
							<td>
								<input type="text" class="regular-text" name="<?php echo esc_attr( $row_name ); ?>[field_key]" value="<?php echo esc_attr( (string) $field_key ); ?>" <?php echo ! empty( $config['locked'] ) ? 'readonly' : ''; ?>>
								<?php if ( ! empty( $config['locked'] ) ) : ?>
									<small><?php esc_html_e( 'Campo protegido do sistema', 'adam-membership' ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<select name="<?php echo esc_attr( $row_name ); ?>[type]" <?php echo ! empty( $config['locked'] ) ? 'disabled' : ''; ?>>
									<?php foreach ( $type_options as $type_key => $type_label ) : ?>
										<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( (string) ( $config['type'] ?? 'text' ), $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( ! empty( $config['locked'] ) ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $row_name ); ?>[type]" value="<?php echo esc_attr( (string) ( $config['type'] ?? 'text' ) ); ?>">
								<?php endif; ?>
							</td>
							<td><label><input type="hidden" name="<?php echo esc_attr( $row_name ); ?>[enabled]" value="0"><input type="checkbox" name="<?php echo esc_attr( $row_name ); ?>[enabled]" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>></label></td>
							<td><label><input type="hidden" name="<?php echo esc_attr( $row_name ); ?>[required]" value="0"><input type="checkbox" name="<?php echo esc_attr( $row_name ); ?>[required]" value="1" <?php checked( ! empty( $config['required'] ) ); ?>></label></td>
							<td>
								<select name="<?php echo esc_attr( $row_name ); ?>[conditional]">
									<?php foreach ( $condition_options as $condition_key => $condition_label ) : ?>
										<option value="<?php echo esc_attr( $condition_key ); ?>" <?php selected( (string) ( $config['conditional'] ?? 'always' ), $condition_key ); ?>><?php echo esc_html( $condition_label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $row_name ); ?>[label]" value="<?php echo esc_attr( (string) ( $config['label'] ?? '' ) ); ?>"></td>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( $row_name ); ?>[help]" value="<?php echo esc_attr( (string) ( $config['help'] ?? '' ) ); ?>"></td>
							<td><textarea class="large-text" rows="3" name="<?php echo esc_attr( $row_name ); ?>[options]" placeholder="<?php echo esc_attr__( 'Uma opção por linha ou valor|rótulo', 'adam-membership' ); ?>"><?php echo esc_textarea( (string) ( $config['options'] ?? '' ) ); ?></textarea></td>
							<td class="adam-admin-row-actions">
								<button type="button" class="button" data-adam-move-up><?php esc_html_e( 'Subir', 'adam-membership' ); ?></button>
								<button type="button" class="button" data-adam-move-down><?php esc_html_e( 'Descer', 'adam-membership' ); ?></button>
								<?php if ( empty( $config['locked'] ) ) : ?>
									<button type="button" class="button button-link-delete adam-button adam-button--danger" data-adam-remove-field><?php esc_html_e( 'Remover', 'adam-membership' ); ?></button>
								<?php else : ?>
									<span class="adam-admin-badge"><?php esc_html_e( 'Protegido', 'adam-membership' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php ++$row_index; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Handle member approval requests.
	 */
	public function handle_approve_member(): void {
		$this->handle_member_action( self::ACTION_APPROVE );
	}

	/**
	 * Handle member rejection requests.
	 */
	public function handle_reject_member(): void {
		$this->handle_member_action( self::ACTION_REJECT );
	}

	/**
	 * Handle member detail page actions.
	 */
	public function handle_member_admin_action(): void {
		$action = isset( $_POST['member_action'] ) ? sanitize_key( wp_unslash( $_POST['member_action'] ) ) : '';
		$this->logger->info( 'Private document send trace v1: member admin action received.', array( 'action' => $action, 'user_id' => absint( $_POST['user_id'] ?? 0 ) ) );

		$this->handle_member_action( $action );
	}

	/**
	 * Permanently delete a member after explicit confirmation.
	 */
	public function handle_permanent_member_deletion(): void {
		$this->ensure_can_manage();

		$user_id      = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$confirmation = isset( $_POST['delete_confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['delete_confirmation'] ) ) : '';

		$this->verify_admin_nonce( 'adam_membership_permanent_delete_' . $user_id );

		if ( 'DELETE' !== $confirmation ) {
			$this->redirect_with_error( __( 'Type DELETE exactly to confirm permanent member deletion.', 'adam-membership' ) );
		}

		$result = $this->member_deletion->delete( $user_id );

		if ( $result instanceof WP_Error ) {
			$this->logger->error( 'Permanent member deletion failed.', array( 'error' => $result->get_error_message() ) );
			$this->redirect_with_error( $result->get_error_message() );
		}

		wp_safe_redirect(
			add_query_arg(
				'adam_message',
				__( 'The member has been permanently deleted.', 'adam-membership' ),
				admin_url( 'admin.php?page=adam-membership-members' )
			)
		);
		exit;
	}

	/**
	 * Handle renewal request admin actions.
	 */
	public function handle_renewal_admin_action(): void {
		$this->ensure_can_manage();

		$request_id = isset( $_POST['request_id'] ) ? absint( wp_unslash( $_POST['request_id'] ) ) : 0;
		$action     = isset( $_POST['renewal_action'] ) ? sanitize_key( wp_unslash( $_POST['renewal_action'] ) ) : '';
		$this->logger->info( 'Private document send trace v1: renewal admin action received.', array( 'action' => $action, 'request_id' => $request_id ) );

		$this->verify_admin_nonce( 'adam_membership_renewal_action_' . $request_id );

		$result = match ( $action ) {
			self::ACTION_APPROVE_RENEWAL          => $this->renewal_service->approve( $request_id ),
			self::ACTION_CONFIRM_ANA_RENEWAL     => $this->renewal_service->confirm_ana_and_approve( $request_id, sanitize_text_field( wp_unslash( $_POST['confirmation_date'] ?? '' ) ) ),
			self::ACTION_REQUEST_RENEWAL_CORRECTION => $this->renewal_service->request_correction( $request_id, $this->posted_correction_reason(), $this->posted_correction_note(), isset( $_POST['correction_fields'] ) && is_array( $_POST['correction_fields'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['correction_fields'] ) ) : array() ),
			self::ACTION_REJECT_RENEWAL           => $this->renewal_service->reject( $request_id, $this->posted_rejection_reason() ),
			self::ACTION_RESEND_RENEWAL_EMAIL     => $this->renewal_service->resend_approval_email( $request_id ),
			self::ACTION_SEND_PRIVATE_DOCUMENT    => $this->renewal_service->send_private_document( $request_id ),
			self::ACTION_REPLACE_RENEWAL_DOCUMENT => $this->replace_renewal_document( $request_id ),
			self::ACTION_REMOVE_RENEWAL_DOCUMENT  => $this->remove_renewal_document( $request_id ),
			default                               => new WP_Error( 'adam_membership_invalid_renewal_action', __( 'Ação de renovação inválida.', 'adam-membership' ) ),
		};

		if ( $result instanceof WP_Error ) {
			$this->redirect_with_error( $result->get_error_message() );
		}

		$this->redirect_with_message( __( 'Pedido de renovação atualizado com sucesso.', 'adam-membership' ) );
	}

	public function handle_delete_financial_movement(): void {
		$this->ensure_can_manage();
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			$this->redirect_with_error( 'A eliminação de movimentos financeiros requer um pedido POST.' );
		}
		$movement_id = sanitize_text_field( wp_unslash( (string) ( $_POST['movement_id'] ?? '' ) ) );
		$this->verify_admin_nonce( 'adam_membership_delete_financial_movement_' . $movement_id );
		$movement = $this->financial_movements->find( $movement_id );
		if ( null === $movement ) {
			$this->redirect_with_error( 'Movimento financeiro não encontrado.' );
		}
		$google = $this->google_sheets->delete_table_row( $movement_id );
		if ( is_wp_error( $google ) ) {
			$this->google_sheets->log_failure( $movement_id, 'delete', $google );
			$this->redirect_with_error( $google->get_error_message() );
		}
		if ( ! $this->financial_movements->suppress( $movement_id ) ) {
			$this->redirect_with_error( 'O registo Google foi tratado, mas não foi possível registar a proteção contra recriação automática. O movimento local foi preservado.' );
		}
		if ( ! $this->financial_movements->delete( $movement ) ) {
			$this->redirect_with_error( 'A proteção contra recriação foi registada, mas não foi possível eliminar o movimento local. Verifique a consistência antes de repetir.' );
		}
		$this->redirect_with_message( 'Movimento financeiro eliminado. Os pedidos de membro e respetivos estados não foram alterados.' );
	}

	/** Archive one history entry without deleting its source record or file. */
	public function handle_archive_document_history(): void {
		$this->ensure_can_manage();
		$member_id = absint( $_POST['member_id'] ?? 0 );
		$history_key = sanitize_text_field( wp_unslash( $_POST['history_key'] ?? '' ) );
		$this->verify_admin_nonce( 'adam_membership_archive_document_history_' . $member_id . '_' . $history_key );
		$member = $member_id > 0 ? $this->members->find( $member_id ) : null;
		if ( null === $member || '' === $history_key ) {
			$this->redirect_with_error( __( 'Não foi possível remover a entrada do histórico.', 'adam-membership' ) );
		}
		$result = $this->member_document_history->archive_for_member( $member, $history_key );
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( __( 'Não foi possível remover a entrada do histórico.', 'adam-membership' ) );
		}
		$this->redirect_with_message( __( 'Entrada removida do histórico. O pedido e o ficheiro original foram preservados.', 'adam-membership' ) );
	}

	/** Permanently delete one audited history file. */
	public function handle_delete_document_history(): void {
		$member_id = absint( $_POST['member_id'] ?? 0 );
		$history_key = sanitize_text_field( wp_unslash( $_POST['history_key'] ?? '' ) );
		$redirect = $member_id > 0 ? add_query_arg( array( 'page' => self::MEMBER_DOCUMENT_HISTORY_PAGE_SLUG, 'member_id' => $member_id ), admin_url( 'admin.php' ) ) : admin_url( 'admin.php?page=' . self::HISTORY_PAGE_SLUG );
		try {
			$this->ensure_can_manage();
			$this->logger->info( 'Private document history deletion trace: handler received.', array( 'stage' => 'handler.received', 'member_id' => $member_id, 'history_key_fingerprint' => hash( 'sha256', $history_key ) ) );
			$this->verify_admin_nonce( 'adam_membership_delete_document_history_' . $member_id . '_' . $history_key );
			$this->logger->info( 'Private document history deletion trace: nonce passed.', array( 'stage' => 'handler.nonce_passed', 'member_id' => $member_id ) );
			$member = $member_id > 0 ? $this->members->find( $member_id ) : null;
			$this->logger->info( 'Private document history deletion trace: member lookup completed.', array( 'stage' => 'handler.member_lookup', 'member_found' => null !== $member ) );
			if ( null === $member || '' === $history_key ) {
				$this->logger->error( 'Private document history deletion refused.', array( 'stage' => 'handler.validation', 'error_code' => 'adam_membership_history_entry_not_found' ) );
				$this->redirect_document_history_error( $redirect, __( 'Não foi possível eliminar o ficheiro.', 'adam-membership' ) );
			}
			$result = $this->member_document_history->permanently_delete_for_member( $member, $history_key );
			$this->logger->info( 'Private document history deletion trace: service returned.', array( 'stage' => 'service.return', 'result' => is_wp_error( $result ) ? 'error' : 'success', 'error_code' => is_wp_error( $result ) ? $result->get_error_code() : '' ) );
			if ( is_wp_error( $result ) ) {
				// Logger has no warning() API; use the debug.log-backed error channel.
				$this->logger->error( 'Permanent document history deletion refused or failed.', array( 'stage' => 'service.refused', 'member_id' => $member_id, 'error_code' => $result->get_error_code() ) );
				$this->redirect_document_history_error( $redirect, $result->get_error_message() );
			}
			$this->logger->info( 'Permanent document history deletion completed.', array( 'stage' => 'service.completed', 'member_id' => $member_id, 'history_key_fingerprint' => hash( 'sha256', $history_key ) ) );
			$this->record_admin_member_history( $member, 'document_history_permanently_deleted', __( 'Ficheiro eliminado permanentemente', 'adam-membership' ), __( 'Um administrador eliminou permanentemente um ficheiro do histórico de documentos.', 'adam-membership' ), array( 'history_key_fingerprint' => hash( 'sha256', $history_key ) ) );
			$this->redirect_document_history_message( $redirect, __( 'Ficheiro eliminado permanentemente.', 'adam-membership' ) );
		} catch ( \Throwable $exception ) {
			$this->logger->error( 'Private document history deletion throwable caught.', array( 'stage' => 'handler.catch', 'exception_class' => get_class( $exception ), 'exception_file' => basename( $exception->getFile() ), 'exception_line' => $exception->getLine(), 'member_id' => $member_id, 'history_key_fingerprint' => hash( 'sha256', $history_key ) ) );
			$this->redirect_document_history_error( $redirect, __( 'Não foi possível eliminar o ficheiro. Nenhuma alteração insegura foi efetuada.', 'adam-membership' ) );
		}
	}

	public function handle_private_document_action(): void {
		$this->ensure_can_manage();
		$type = sanitize_key( (string) ( $_POST['document_type'] ?? '' ) );
		$id   = absint( $_POST['request_id'] ?? 0 );
		$this->logger->info( 'Private document replacement trace v1: POST replacement handler received.', array(
			'stage'          => 'admin.handler.received',
			'document_type'  => $type,
			'request_id'     => $id,
			'action_present' => isset( $_POST['private_document_action'] ),
			'file_present'   => isset( $_FILES['private_document_file'] ) && is_array( $_FILES['private_document_file'] ),
			'upload_error'   => (int) ( $_FILES['private_document_file']['error'] ?? UPLOAD_ERR_NO_FILE ),
		) );
		$this->verify_admin_nonce( 'adam_membership_private_document_' . $type . '_' . $id );

		$action = sanitize_key( (string) ( $_POST['private_document_action'] ?? '' ) );
		$reference = $this->private_document_reference( $type, $id );
		if ( is_wp_error( $reference ) ) {
			$this->redirect_with_error( $reference->get_error_message() );
		}

		try {
			$result = match ( $action ) {
				self::ACTION_PRIVATE_DOCUMENT_UPLOAD => $this->upload_private_document( $reference, $type ),
				self::ACTION_PRIVATE_DOCUMENT_REMOVE => $this->remove_private_document( $reference ),
				default => new WP_Error( 'adam_membership_invalid_private_document_action', __( 'Ação de documento inválida.', 'adam-membership' ) ),
			};
		} catch ( \Throwable $exception ) {
			$this->logger->error( 'Private document replacement trace v2: throwable caught.', array(
				'stage'             => 'admin.private_document_action.catch',
				'exception_class'    => get_class( $exception ),
				'exception_file'     => basename( $exception->getFile() ),
				'exception_line'     => $exception->getLine(),
				'exception_code'     => (string) $exception->getCode(),
				'cleanup_attempted'  => false,
				'rollback_attempted' => false,
			) );
			$this->redirect_with_error( __( 'Não foi possível concluir a operação do documento privado. Tente novamente.', 'adam-membership' ) );
		}

		if ( $result instanceof WP_Error ) {
			$this->redirect_with_error( $result->get_error_message() );
		}

		$this->redirect_with_message( __( 'Documento privado atualizado com sucesso.', 'adam-membership' ) );
	}

	/** @return true|WP_Error */
	private function upload_private_document( string $reference, string $type ): true|WP_Error {
		if ( ! isset( $_FILES['private_document_file'] ) || ! is_array( $_FILES['private_document_file'] ) ) {
			$this->logger->info( 'Private document replacement trace v1: upload missing.', array( 'stage' => 'admin.upload_received', 'document_type' => $type ) );
			return new WP_Error( 'adam_private_document_missing', __( 'Selecione um PDF.', 'adam-membership' ) );
		}
		$this->logger->info( 'Private document replacement trace v1: upload received.', array(
			'stage'        => 'admin.upload_received',
			'document_type' => $type,
			'upload_error' => (int) ( $_FILES['private_document_file']['error'] ?? UPLOAD_ERR_NO_FILE ),
			'upload_size'  => absint( $_FILES['private_document_file']['size'] ?? 0 ),
		) );
		$data = array(
			'request_reference' => $reference,
			'request_type'      => $type,
			'uploaded_by'       => get_current_user_id(),
		);
		$current = $this->private_documents->find_active( $reference );
		$this->logger->info( 'Private document replacement trace v1: replacement mode selected.', array( 'stage' => 'admin.mode_selected', 'has_current_document' => null !== $current ) );
		$result  = null === $current
			? $this->private_documents->create_from_upload( $data, $_FILES['private_document_file'], $this->private_document_storage )
			: $this->private_documents->replace_from_upload( $data, $_FILES['private_document_file'], $this->private_document_storage );
		if ( ! is_wp_error( $result ) ) {
			$this->logger->info(
				null === $current ? 'Private document uploaded.' : 'Private document replaced.',
				array(
					'document_id'      => $result->id(),
					'request_reference' => $result->request_reference(),
					'sha256'           => $result->sha256(),
					'uploaded_by'      => get_current_user_id(),
				)
			);
		}

		return is_wp_error( $result ) ? $result : true;
	}

	/** @return true|WP_Error */
	private function remove_private_document( string $reference ): true|WP_Error {
		$current = $this->private_documents->find_active( $reference );
		if ( null === $current ) {
			return true;
		}

		$result = $this->private_documents->mark_orphaned( $current );
		if ( ! is_wp_error( $result ) ) {
			$this->logger->info(
				'Private document association removed.',
				array(
					'document_id'       => $current->id(),
					'request_reference' => $current->request_reference(),
					'sha256'            => $current->sha256(),
					'admin_id'          => get_current_user_id(),
				)
			);
		}

		return is_wp_error( $result ) ? $result : true;
	}

	/** @return string|WP_Error */
	private function private_document_reference( string $type, int $id ): string|WP_Error {
		if ( 'registration' === $type ) {
			$member = $this->members->find( $id );
			if ( null === $member ) {
				return new WP_Error( 'adam_private_document_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) );
			}
			$reference = (string) get_user_meta( $id, 'adam_membership_registration_request_uuid', true );

			return str_starts_with( $reference, 'registration:' ) ? $reference : 'registration:legacy-' . $id;
		}
		if ( 'renewal' === $type ) {
			$request = $this->renewal_repository->find( $id );

			if ( null === $request ) {
				return new WP_Error( 'adam_private_document_renewal_not_found', __( 'Pedido de renovação não encontrado.', 'adam-membership' ) );
			}
			$reference = $request->request_uuid();

			return str_starts_with( $reference, 'renewal:' ) ? $reference : 'renewal:legacy-' . $id;
		}

		return new WP_Error( 'adam_private_document_invalid_type', __( 'Tipo de pedido inválido.', 'adam-membership' ) );
	}

	/**
	 * Handle team administration actions.
	 */
	public function handle_team_admin_action(): void {
		$this->ensure_can_manage();

		$team_id = isset( $_POST['team_id'] ) ? absint( wp_unslash( $_POST['team_id'] ) ) : 0;
		$action  = isset( $_POST['team_action'] ) ? sanitize_key( wp_unslash( $_POST['team_action'] ) ) : '';

		$this->verify_admin_nonce( 'adam_membership_team_action_' . $team_id );

		$team = $this->teams->find( $team_id );

		if ( null === $team ) {
			$this->redirect_with_error( __( 'Equipa não encontrada.', 'adam-membership' ) );
			return;
		}

		if ( self::ACTION_SAVE_TEAM === $action ) {
			$name   = isset( $_POST['team_name'] ) ? sanitize_text_field( wp_unslash( $_POST['team_name'] ) ) : '';
			$type   = isset( $_POST['team_type'] ) ? sanitize_key( wp_unslash( $_POST['team_type'] ) ) : $team->type();
			$result = $this->teams->update_details( $team_id, $name, $type );

			if ( $result instanceof WP_Error ) {
				$this->redirect_with_error( $result->get_error_message() );
				return;
			}

			$this->redirect_with_message( __( 'Equipa atualizada com sucesso.', 'adam-membership' ) );
			return;
		}

		if ( self::ACTION_DELETE_TEAM === $action ) {
			if ( $this->teams->member_count( $team_id ) > 0 ) {
				$this->redirect_with_error( __( 'Não é possível eliminar uma equipa que possui sócios associados.', 'adam-membership' ) );
				return;
			}

			if ( ! $this->teams->delete( $team_id ) ) {
				$this->redirect_with_error( __( 'Não foi possível eliminar a equipa.', 'adam-membership' ) );
				return;
			}

			$this->redirect_with_message( __( 'Equipa eliminada com sucesso.', 'adam-membership' ) );
			return;
		}

		$this->redirect_with_error( __( 'Ação de equipa inválida.', 'adam-membership' ) );
	}

	/**
	 * Save plugin settings.
	 */
	public function handle_save_settings(): void {
		$this->ensure_can_manage();
		$this->verify_admin_nonce( 'adam_membership_save_settings' );

		if ( isset( $_POST['last_assigned_member_number'] ) ) {
			$raw_counter = trim( (string) wp_unslash( $_POST['last_assigned_member_number'] ) );
			if ( '' === $raw_counter || ! ctype_digit( $raw_counter ) ) {
				$this->redirect_with_error( 'O último número de sócio deve ser um número inteiro igual ou superior a zero.' );
				return;
			}

			$counter = filter_var( $raw_counter, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
			if ( false === $counter || $counter >= PHP_INT_MAX ) {
				$this->redirect_with_error( 'O último número de sócio indicado não é válido.' );
				return;
			}

			$next_number = $counter + 1;
			foreach ( $this->members->all_members() as $member ) {
				$existing_number = trim( (string) $member->field( 'numero_socio' ) );
				if ( '' !== $existing_number && Member::member_number_numeric_value( $existing_number ) === $next_number ) {
					$this->redirect_with_error( 'Não é possível guardar este contador: o próximo número de sócio já está atribuído a um sócio existente.' );
					return;
				}
			}

			$this->settings->save_last_assigned_member_number( $counter );
		}

		$url = isset( $_POST['renewal_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['renewal_page_url'] ) ) : $this->settings->renewal_page_url();
		$registration_url = isset( $_POST['registration_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['registration_page_url'] ) ) : $this->settings->registration_page_url();
		$this->settings->save_registration_page_url( $registration_url );
		$this->settings->save_renewal_page_url( $url );
		$from_name    = isset( $_POST['email_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['email_from_name'] ) ) : $this->settings->email_from_name();
		$from_address = isset( $_POST['email_from_address'] ) ? sanitize_email( wp_unslash( $_POST['email_from_address'] ) ) : $this->settings->email_from_address();
		$this->settings->save_email_sender( $from_name, $from_address );
		$association_name = isset( $_POST['association_name'] ) ? sanitize_text_field( wp_unslash( $_POST['association_name'] ) ) : $this->settings->association_name();
		$association_logo = isset( $_POST['association_logo'] ) ? esc_url_raw( wp_unslash( $_POST['association_logo'] ) ) : $this->settings->association_logo_url();
		$this->settings->save_association_settings( $association_name, $association_logo );
		$privacy_policy_url = isset( $_POST['privacy_policy_url'] ) ? esc_url_raw( wp_unslash( $_POST['privacy_policy_url'] ) ) : $this->settings->privacy_policy_url();
		$cookie_policy_url  = isset( $_POST['cookie_policy_url'] ) ? esc_url_raw( wp_unslash( $_POST['cookie_policy_url'] ) ) : $this->settings->cookie_policy_url();
		$membership_terms_url = isset( $_POST['membership_terms_url'] ) ? esc_url_raw( wp_unslash( $_POST['membership_terms_url'] ) ) : $this->settings->membership_terms_url();
		$this->settings->save_compliance_pages( $privacy_policy_url, $cookie_policy_url, $membership_terms_url );
		$membership_forms = isset( $_POST['membership_forms'] ) && is_array( $_POST['membership_forms'] ) ? wp_unslash( $_POST['membership_forms'] ) : $this->settings->membership_form_settings();
		$this->settings->save_membership_form_settings( $membership_forms );
		$this->settings->save_google_sheets_settings(
			! empty( $_POST['google_sheets_enabled'] ),
			sanitize_text_field( wp_unslash( $_POST['google_sheets_spreadsheet_id'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['google_sheets_sheet_name'] ?? 'Quotas' ) )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'adam-membership-settings',
					'adam_message' => __( 'Configurações guardadas com sucesso.', 'adam-membership' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Retry one manually selected Google Sheets movement without changing approval state. */
	public function handle_retry_google_sheets(): void {
		$type = '';
		$id = 0;
		$request_key = '';
		$member = null;
		$request = null;
		$apd_request = null;
		try {
		$this->ensure_can_manage();
		$type = sanitize_key( (string) ( $_POST['sync_type'] ?? '' ) );
		$id   = absint( $_POST['request_id'] ?? 0 );
		$request_key = sanitize_text_field( wp_unslash( (string) ( $_POST['request_id'] ?? '' ) ) );
		$this->verify_admin_nonce( 'adam_membership_retry_google_sheets_' . $type . '_' . ( 'manual' === $type ? $request_key : $id ) );

		if ( 'registration' === $type ) {
			$member = $this->members->find( $id );
			if ( null !== $member ) {
				$workflow_result = $this->membership_workflow->sync_registration( $member );
				if ( is_wp_error( $workflow_result ) ) {
					$this->redirect_with_error( $workflow_result->get_error_message() );
					return;
				}
			}
			$movement_id = null !== $member ? (string) get_user_meta( $member->user_id(), 'adam_membership_registration_request_uuid', true ) : '';
			$movement = '' !== $movement_id ? $this->financial_movements->find( $movement_id ) : null;
			$result = null !== $movement && null !== $member ? $this->google_sheets_sync->sync_persisted_movement( $movement, $member ) : ( null !== $member ? $this->google_sheets_sync->sync_registration( $member ) : new WP_Error( 'adam_google_sheets_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) ) );
		} elseif ( 'renewal' === $type ) {
			$request = $this->renewal_repository->find( $id );
			$member  = null !== $request ? $this->members->find( $request->user_id() ) : null;
			$movement = null !== $request ? $this->financial_movements->find( $request->request_uuid() ) : null;
			$result  = null !== $movement && null !== $member ? $this->google_sheets_sync->sync_persisted_movement( $movement, $member ) : ( null !== $request && null !== $member ? $this->google_sheets_sync->sync_renewal( $request, $member ) : new WP_Error( 'adam_google_sheets_renewal_not_found', __( 'Pedido de renovação não encontrado.', 'adam-membership' ) ) );
		} elseif ( 'apd' === $type ) {
			$apd_request = $this->apd_association->repository()->find( $id );
			$member = null !== $apd_request ? $this->members->find( $apd_request->user_id() ) : null;
			$movement = null !== $apd_request ? $this->financial_movements->find( $apd_request->request_uuid() ) : null;
			$result = null !== $movement && null !== $member ? $this->google_sheets_sync->sync_persisted_movement( $movement, $member ) : ( null !== $apd_request && null !== $member ? $this->google_sheets_sync->sync_apd_association( $apd_request, $member ) : new WP_Error( 'adam_google_sheets_apd_not_found', __( 'Pedido APD não encontrado.', 'adam-membership' ) ) );
		} elseif ( 'manual' === $type ) {
			$movement = $this->financial_movements->find( $request_key );
			$member = null !== $movement ? $this->members->find( $movement->member_id() ) : null;
			$result = null !== $movement && null !== $member ? $this->google_sheets_sync->sync_persisted_movement( $movement, $member ) : new WP_Error( 'adam_google_sheets_manual_not_found', 'Movimento manual não encontrado.' );
		} else {
			$result = new WP_Error( 'adam_google_sheets_invalid_retry', __( 'Tipo de sincronização inválido.', 'adam-membership' ) );
		}

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_error( $result->get_error_message() );
			return;
		}
		$this->redirect_with_message( __( 'Sincronização Google Sheets concluída.', 'adam-membership' ) );
		} catch ( \Throwable $exception ) {
			$request_id = '';
			if ( 'registration' === $type && null !== $member ) {
				$request_id = (string) get_user_meta( $member->user_id(), 'adam_membership_registration_request_uuid', true );
			} elseif ( 'renewal' === $type && null !== $request ) {
				$request_id = (string) $request->request_uuid();
			} elseif ( 'apd' === $type && null !== $apd_request ) {
				$request_id = (string) $apd_request->request_uuid();
			} elseif ( 'manual' === $type ) {
				$request_id = $request_key;
			}
			$this->google_sheets->log_exception( $request_id, 'retry_handler', $exception );
			$this->redirect_with_error( 'A Google Sheets synchronization failed. You can retry the operation.' );
		}
	}

	/** Save payment data required for a quota movement and leave approval unchanged. */
	public function handle_save_google_sheets_payment(): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ADAM Membership] financial-movement-trace-v3 save_handler_entered' );
		}
		$this->ensure_can_manage();
		$type = sanitize_key( (string) ( $_POST['sync_type'] ?? '' ) );
		$id   = absint( $_POST['request_id'] ?? 0 );
		$request_key = sanitize_text_field( wp_unslash( (string) ( $_POST['request_id'] ?? '' ) ) );
		$this->verify_admin_nonce( 'adam_membership_save_google_sheets_payment_' . $type . '_' . ( 'manual' === $type ? $request_key : $id ) );
		$year   = absint( $_POST['membership_year'] ?? 0 );
		$amount = str_replace( ',', '.', sanitize_text_field( wp_unslash( (string) ( $_POST['payment_amount'] ?? '' ) ) ) );
		$date   = sanitize_text_field( wp_unslash( (string) ( $_POST['payment_date'] ?? '' ) ) );
		$method = sanitize_text_field( wp_unslash( (string) ( $_POST['payment_method'] ?? '' ) ) );
		$quota_type = sanitize_text_field( wp_unslash( (string) ( $_POST['quota_type'] ?? '' ) ) );
		$allowed_quota_types = array( 'Inscrição ADAM', 'Inscrição ADAM/ANA', 'Renovação ADAM', 'Renovação ADAM/ANA', 'Associar APD/ANA' );
		if ( ! in_array( $quota_type, $allowed_quota_types, true ) ) {
			$this->redirect_with_error( 'Selecione um Tipo de quota válido.' );
			return;
		}
		$this->financial_save_trace_identifiers( 'post_received', $request_key, '', $id, 'registration' , $year, $method, $amount, $date, 'pending', 'AdminController::handle_save_google_sheets_payment' );
		$payment_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( $year < 2000 || $year > 2100 || ! is_numeric( $amount ) || (float) $amount <= 0 || false === $payment_date || $payment_date->format( 'Y-m-d' ) !== $date || ! in_array( $method, GoogleSheetsSyncService::PAYMENT_METHODS, true ) ) {
			$this->redirect_with_error( __( 'Indique um ano, valor pago, data e método de pagamento válidos.', 'adam-membership' ) );
			return;
		}
		if ( 'registration' === $type ) {
			$member = $this->members->find( $id );
			if ( null === $member ) {
				$this->redirect_with_error( __( 'Sócio não encontrado.', 'adam-membership' ) );
				return;
			}
			$sync_data = (array) get_user_meta( $member->user_id(), 'adam_membership_google_sheets_sync', true );
			$stored_year = absint( $sync_data['membership_year'] ?? get_user_meta( $member->user_id(), 'adam_membership_year', true ) );
			if ( absint( $sync_data['row_number'] ?? 0 ) > 0 && $stored_year !== $year ) {
				$this->redirect_with_error( 'O ano de uma transação já sincronizada não pode ser alterado. Crie uma renovação para um novo ano.' );
				return;
			}
			if ( $quota_type !== $this->google_sheets_quota_type( $member ) ) {
				$result = $this->create_manual_financial_movement( $member, $quota_type, $year, $amount, $date, $method );
				is_wp_error( $result ) ? $this->redirect_with_error( $result->get_error_message() ) : $this->redirect_with_message( 'Novo movimento financeiro criado como Pago. Pode sincronizar quando desejar.' );
				return;
			}
			$financial = array( 'membership_year' => $year, 'amount' => number_format( (float) $amount, 2, '.', '' ), 'payment_date' => $date, 'payment_method' => $method );
			$movement_id = sanitize_text_field( (string) get_user_meta( $member->user_id(), 'adam_membership_registration_request_uuid', true ) );
			$movement = '' !== $movement_id ? $this->financial_movements->find( $movement_id ) : null;
			if ( null !== $movement ) {
				$movement = $this->update_financial_movement_payment( $movement, array_merge( $financial, array( 'financial_status' => 'paid' ) ) );
			} else {
				update_user_meta( $member->user_id(), 'adam_membership_year', (string) $year );
				update_user_meta( $member->user_id(), 'adam_membership_payment_amount', $financial['amount'] );
				update_user_meta( $member->user_id(), 'adam_membership_payment_date', $date );
				update_user_meta( $member->user_id(), 'adam_membership_payment_method', $method );
				$movement = $this->google_sheets_sync->ensure_registration_movement( $member );
			}
			if ( is_wp_error( $movement ) ) { $this->redirect_with_error( $movement->get_error_message() ); return; }
			$this->redirect_with_message( __( 'Dados de pagamento guardados como Pago. Pode sincronizar quando desejar.', 'adam-membership' ) );
			return;
		}
		if ( 'renewal' === $type ) {
			$request = $this->renewal_repository->find( $id );
			if ( null === $request ) {
				$this->redirect_with_error( __( 'Pedido de renovação não encontrado.', 'adam-membership' ) );
				return;
			}
			$request_data = $request->data();
			$sync_data = (array) ( $request_data['google_sheets_sync'] ?? array() );
			$stored_year = absint( $sync_data['membership_year'] ?? ( $request_data['membership_year'] ?? 0 ) );
			if ( absint( $sync_data['row_number'] ?? 0 ) > 0 && $stored_year !== $year ) {
				$this->redirect_with_error( 'O ano de uma transação já sincronizada não pode ser alterado. Crie uma nova renovação para um novo ano.' );
				return;
			}
			$member = $this->members->find( $request->user_id() );
			if ( null === $member ) { $this->redirect_with_error( 'Sócio não encontrado.' ); return; }
			if ( $quota_type !== $this->google_sheets_quota_type( $member, $request ) ) {
				$result = $this->create_manual_financial_movement( $member, $quota_type, $year, $amount, $date, $method );
				is_wp_error( $result ) ? $this->redirect_with_error( $result->get_error_message() ) : $this->redirect_with_message( 'Novo movimento financeiro criado como Pago. Pode sincronizar quando desejar.' );
				return;
			}
			$financial = array( 'membership_year' => $year, 'amount' => number_format( (float) $amount, 2, '.', '' ), 'payment_date' => $date, 'payment_method' => $method );
			$movement = $this->financial_movements->find( $request->request_uuid() );
			if ( null !== $movement ) {
				$movement = $this->update_financial_movement_payment( $movement, array_merge( $financial, array( 'financial_status' => 'paid' ) ) );
			} else {
				$updated_request = $this->renewal_repository->update( $request, array( 'membership_year' => $year, 'payment_amount' => $financial['amount'], 'payment_date' => $date, 'payment_method' => $method ) );
				if ( false === $updated_request ) { $this->redirect_with_error( 'Não foi possível guardar os dados do pedido de renovação.' ); return; }
				$request = $this->renewal_repository->find( $request->id() ) ?? $request;
				$movement = $this->google_sheets_sync->ensure_renewal_movement( $request, $member );
			}
			if ( is_wp_error( $movement ) ) { $this->redirect_with_error( $movement->get_error_message() ); return; }
			$this->redirect_with_message( __( 'Dados de pagamento guardados como Pago. Pode sincronizar quando desejar.', 'adam-membership' ) );
			return;
		}
		if ( 'manual' === $type ) {
			$movement = $this->financial_movements->find( $request_key );
			$member = null !== $movement ? $this->members->find( $movement->member_id() ) : null;
			if ( null === $movement || null === $member ) { $this->redirect_with_error( 'Movimento manual não encontrado.' ); return; }
			if ( $quota_type !== $movement->quota_type() ) {
				$result = $this->create_manual_financial_movement( $member, $quota_type, $year, $amount, $date, $method );
				is_wp_error( $result ) ? $this->redirect_with_error( $result->get_error_message() ) : $this->redirect_with_message( 'Novo movimento financeiro criado. O movimento anterior foi preservado.' );
				return;
			}
			$financial = array( 'membership_year' => $year, 'amount' => number_format( (float) $amount, 2, '.', '' ), 'payment_date' => $date, 'payment_method' => $method, 'financial_status' => 'paid' );
			if ( ! $this->financial_movements->update( $movement, $financial ) ) {
				$this->redirect_with_error( 'Não foi possível guardar os dados do movimento financeiro.' );
				return;
			}
			$updated = $this->financial_movements->find( $movement->movement_id() );
			if ( null === $updated || $updated->membership_year() !== $year || number_format( (float) $updated->amount(), 2, '.', '' ) !== $financial['amount'] || $updated->payment_date() !== $date || $updated->payment_method() !== $method || 'paid' !== $updated->financial_status() ) {
				$this->redirect_with_error( 'Os dados do movimento não puderam ser confirmados após a gravação.' );
				return;
			}
			$this->redirect_with_message( 'Dados de pagamento guardados como Pago. Pode sincronizar quando desejar.' );
			return;
		}
		$this->redirect_with_error( __( 'Tipo de sincronização inválido.', 'adam-membership' ) );
	}

	/** Test the configured Google Sheets connection without writing spreadsheet data. */
	public function handle_test_google_sheets(): void {
		$this->ensure_can_manage();
		$this->verify_admin_nonce( 'adam_membership_test_google_sheets' );
		$result = $this->google_sheets->test_connection();

		if ( is_wp_error( $result ) ) {
			$this->settings->save_google_sheets_test_result( 'failed' );
			$this->redirect_with_error( $result->get_error_message() );
			return;
		}

		$this->settings->save_google_sheets_test_result( 'connected', wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) );
		$this->redirect_with_message( __( 'A ligação Google Sheets foi confirmada. Nenhum dado foi alterado.', 'adam-membership' ) );
	}

	/**
	 * Save native forms settings.
	 */
	public function handle_save_forms_settings(): void {
		$this->ensure_can_manage();
		$this->verify_admin_nonce( 'adam_membership_save_forms_settings' );

		$registration_url = isset( $_POST['registration_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['registration_page_url'] ) ) : $this->settings->registration_page_url();
		$renewal_url      = isset( $_POST['renewal_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['renewal_page_url'] ) ) : $this->settings->renewal_page_url();
		$privacy_url      = isset( $_POST['privacy_policy_url'] ) ? esc_url_raw( wp_unslash( $_POST['privacy_policy_url'] ) ) : $this->settings->privacy_policy_url();
		$form_settings    = isset( $_POST['membership_forms'] ) && is_array( $_POST['membership_forms'] ) ? wp_unslash( $_POST['membership_forms'] ) : $this->settings->membership_form_settings();
		foreach ( array( 'update', 'apd' ) as $managed_form ) {
			if ( isset( $form_settings['forms'][ $managed_form ] ) && ! isset( $form_settings['forms'][ $managed_form ]['fields'] ) ) {
				$form_settings['forms'][ $managed_form ]['fields'] = array();
			}
		}
		$form_settings    = array_replace_recursive( $this->settings->membership_form_settings(), $form_settings );
		if ( isset( $form_settings['forms']['registration']['page_url'] ) ) {
			$registration_url = esc_url_raw( (string) $form_settings['forms']['registration']['page_url'] );
		}
		if ( isset( $form_settings['forms']['renewal']['page_url'] ) ) {
			$renewal_url = esc_url_raw( (string) $form_settings['forms']['renewal']['page_url'] );
		}

		$this->settings->save_registration_page_url( $registration_url );
		$this->settings->save_renewal_page_url( $renewal_url );
		$this->settings->save_compliance_pages( $privacy_url, $this->settings->cookie_policy_url(), $this->settings->membership_terms_url() );
		$this->settings->save_membership_form_settings( $form_settings );

		$this->redirect_with_message( __( 'Formulários guardados com sucesso.', 'adam-membership' ) );
	}

	/**
	 * Save automatic email settings.
	 */
	public function handle_save_email_settings(): void {
		$this->ensure_can_manage();
		$this->verify_admin_nonce( 'adam_membership_save_email_settings' );

		if ( isset( $_POST['template_key'] ) ) {
			$this->handle_send_test_email();
		}

		$from_name      = isset( $_POST['email_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['email_from_name'] ) ) : $this->settings->email_from_name();
		$from_address   = isset( $_POST['email_from_address'] ) ? sanitize_email( wp_unslash( $_POST['email_from_address'] ) ) : $this->settings->email_from_address();
		$email_settings = isset( $_POST['email_templates'] ) && is_array( $_POST['email_templates'] ) ? wp_unslash( $_POST['email_templates'] ) : $this->settings->email_template_settings();

		$this->settings->save_email_sender( $from_name, $from_address );
		$this->settings->save_email_template_settings( $email_settings );

		$this->redirect_with_message( __( 'Emails guardados com sucesso.', 'adam-membership' ) );
	}

	/**
	 * Send a test message for a configured email template.
	 */
	public function handle_send_test_email(): void {
		$this->ensure_can_manage();
		$this->verify_admin_nonce( 'adam_membership_save_email_settings' );

		$template_key = isset( $_POST['template_key'] ) ? sanitize_key( wp_unslash( $_POST['template_key'] ) ) : '';
		$user         = wp_get_current_user();
		$recipient    = $user instanceof \WP_User ? sanitize_email( $user->user_email ) : '';

		if ( '' === $template_key || ! is_email( $recipient ) ) {
			$this->redirect_with_error( __( 'Não foi possível enviar o email de teste.', 'adam-membership' ) );
		}

		if ( $this->email->send_test_email_template( $template_key, $recipient ) ) {
			$this->redirect_with_message( sprintf( __( 'Email de teste enviado para %s.', 'adam-membership' ), $recipient ) );
		}

		$this->redirect_with_error( __( 'Não foi possível enviar o email de teste.', 'adam-membership' ) );
	}

	/**
	 * Run scheduled maintenance immediately from the admin.
	 */
	public function handle_run_maintenance(): void {
		$this->ensure_can_manage();
		$this->verify_admin_nonce( 'adam_membership_run_maintenance' );

		$this->logger->info(
			'Manutenção manual de sócios solicitada.',
			array(
				'admin_id' => get_current_user_id(),
			)
		);

		$this->maintenance->run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'adam-membership-settings',
					'adam_message' => __( 'Manutenção de sócios concluída.', 'adam-membership' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Export members as CSV.
	 */
	public function handle_export_members_csv(): void {
		$this->ensure_can_manage();
		$this->verify_admin_nonce( 'adam_membership_export_members_csv' );

		$filename = 'adam-socios-' . wp_date( 'Ymd-His', current_time( 'timestamp' ) ) . '.csv';
		$members  = $this->members->admin_members( array( 'orderby' => 'registered', 'order' => 'desc' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );

		if ( false === $output ) {
			wp_die( esc_html__( 'Não foi possível gerar o ficheiro CSV.', 'adam-membership' ) );
		}

		fputcsv( $output, array( 'ID', 'Numero de socio', 'Nome', 'Email', 'Estado', 'Quota', 'Validade quota', 'Data adesao' ) );

		foreach ( $members as $member ) {
			fputcsv(
				$output,
				array(
					$member->user_id(),
					(string) $member->field( 'numero_socio' ),
					$member->full_name(),
					$member->email(),
					$member->effective_status(),
					$member->quota_status(),
					(string) $member->field( 'validade_quota' ),
					(string) $member->field( 'data_adesao' ),
				)
			);
		}

		fclose( $output );
		exit;
	}

	/**
	 * Export complete selected, pending, or approved member records as ZIP.
	 */
	public function handle_export_complete_zip(): void {
		$this->ensure_can_manage();
		$this->verify_admin_nonce( 'adam_membership_export_complete_zip' );

		$scope   = isset( $_REQUEST['export_scope'] ) ? sanitize_key( wp_unslash( $_REQUEST['export_scope'] ) ) : 'selected';
		$members = array();

		if ( 'pending' === $scope ) {
			$members = $this->members->pending_members();
		} elseif ( 'approved' === $scope ) {
			$members = array_values(
				array_filter(
					$this->members->all_members(),
					static fn ( Member $member ): bool => ! $member->isPending()
				)
			);
		} else {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every value is sanitized on the next line.
			$requested_ids = isset( $_REQUEST['member_ids'] ) ? (array) wp_unslash( $_REQUEST['member_ids'] ) : array();
			$requested_ids = array_map( 'sanitize_text_field', $requested_ids );
			$requested_ids = array_values( array_unique( array_filter( array_map( 'absint', $requested_ids ) ) ) );
			$available     = array();

			foreach ( $this->members->all_members() as $member ) {
				$available[ $member->user_id() ] = $member;
			}

			foreach ( $requested_ids as $member_id ) {
				if ( isset( $available[ $member_id ] ) ) {
					$members[] = $available[ $member_id ];
				}
			}
		}

		if ( array() === $members ) {
			wp_die( esc_html__( 'Selecione pelo menos um registo válido para exportar.', 'adam-membership' ), '', array( 'response' => 400 ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$archive = $this->complete_export->create_archive( $members );
		if ( is_wp_error( $archive ) ) {
			wp_die( esc_html( $archive->get_error_message() ), '', array( 'response' => 500 ) );
		}

		$path     = $archive['path'];
		$filename = $archive['filename'];

		while ( 0 < ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$size = filesize( $path );
		if ( false !== $size ) {
			header( 'Content-Length: ' . (string) $size );
		}
		header( 'X-Content-Type-Options: nosniff' );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a local generated download.
			readfile( $path );
		} finally {
			wp_delete_file( $path );
		}

		exit;
	}

	/**
	 * Render renewal request filters.
	 *
	 * @param array<string, string> $filters Filters.
	 */
	private function render_renewal_filters( array $filters ): void {
		?>
		<form method="get" class="adam-admin-filters">
			<input type="hidden" name="page" value="adam-membership-renewals">
			<label>
				<span><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
				<select name="status">
					<?php $this->render_select_option( '', __( 'Todos os pedidos', 'adam-membership' ), $filters['status'] ?? '' ); ?>
					<?php $this->render_select_option( RenewalRequest::STATUS_PENDING, __( 'Pendente de revisão', 'adam-membership' ), $filters['status'] ?? '' ); ?>
					<?php $this->render_select_option( RenewalRequest::STATUS_APPROVED, __( 'Aprovado', 'adam-membership' ), $filters['status'] ?? '' ); ?>
					<?php $this->render_select_option( RenewalRequest::STATUS_REJECTED, __( 'Rejeitado', 'adam-membership' ), $filters['status'] ?? '' ); ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Ordem', 'adam-membership' ); ?></span>
				<select name="order">
					<?php $this->render_select_option( 'desc', __( 'Mais recentes primeiro', 'adam-membership' ), $filters['order'] ?? 'desc' ); ?>
					<?php $this->render_select_option( 'asc', __( 'Mais antigos primeiro', 'adam-membership' ), $filters['order'] ?? 'desc' ); ?>
				</select>
			</label>
			<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Aplicar filtros', 'adam-membership' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-renewals' ) ); ?>"><?php esc_html_e( 'Repor', 'adam-membership' ); ?></a>
		</form>
		<?php
		?>
	}

	/**
	 * Get the available field types for the builder.
	 *
	 * @return array<string, string>
	 */
	private function membership_form_type_options(): array {
		return array(
			'text'     => __( 'Texto', 'adam-membership' ),
			'email'    => __( 'Email', 'adam-membership' ),
			'phone'    => __( 'Telefone', 'adam-membership' ),
			'number'   => __( 'Número', 'adam-membership' ),
			'date'     => __( 'Data', 'adam-membership' ),
			'select'   => __( 'Lista suspensa', 'adam-membership' ),
			'radio'    => __( 'Botões de escolha', 'adam-membership' ),
			'checkbox' => __( 'Caixa de verificação', 'adam-membership' ),
			'file'     => __( 'Upload de ficheiro', 'adam-membership' ),
			'textarea' => __( 'Área de texto', 'adam-membership' ),
		);
	}

	/**
	 * Get the available conditional rules for a form group.
	 *
	 * @param string $group Form group key.
	 * @return array<string, string>
	 */
	private function membership_form_condition_options( string $group ): array {
		if ( 'renewal_fields' === $group ) {
			return array(
				'always'           => __( 'Sempre visível', 'adam-membership' ),
				'renewal_profile'  => __( 'Quando o sócio indica alterações de dados', 'adam-membership' ),
				'renewal_external' => __( 'Quando a renovação é feita através de outra associação', 'adam-membership' ),
			);
		}

		return array(
			'always'                => __( 'Sempre visível', 'adam-membership' ),
			'registration_external' => __( 'Quando o candidato indica outra associação', 'adam-membership' ),
		);
	}

	/**
	 * Render the admin-side form builder script.
	 */
	private function render_membership_form_builder_script(): void {
		?>
		<script>
		( function () {
			function optionMarkup(options) {
				return Object.keys(options).map(function (key) {
					return '<option value="' + key + '">' + options[key] + '</option>';
				}).join('');
			}

			function refreshOrder(container) {
				var rows = container.querySelectorAll('[data-adam-form-row]');
				rows.forEach(function (row, index) {
					var input = row.querySelector('[data-adam-order-input]');
					if (input) {
						input.value = String(index + 1);
					}
				});
			}

			function buildRow(container, index) {
				var conditionOptions = JSON.parse(container.dataset.conditionOptions || '{}');
				var typeOptions = JSON.parse(container.dataset.typeOptions || '{}');
				var group = container.dataset.adamFormBuilder || '';
				var uniqueId = 'custom_' + Date.now() + '_' + index;
				var rowName = 'membership_forms[' + group + '][' + uniqueId + ']';

				return [
					'<tr data-adam-form-row="' + uniqueId + '" data-system-field="0">',
						'<td><input type="number" min="1" class="small-text" data-adam-order-input name="' + rowName + '[order]" value="' + ( index + 1 ) + '"></td>',
						'<td><input type="text" class="regular-text" name="' + rowName + '[field_key]" value="" placeholder="campo_personalizado"></td>',
						'<td><select name="' + rowName + '[type]">' + optionMarkup(typeOptions) + '</select></td>',
						'<td><label><input type="hidden" name="' + rowName + '[enabled]" value="0"><input type="checkbox" name="' + rowName + '[enabled]" value="1" checked></label></td>',
						'<td><label><input type="hidden" name="' + rowName + '[required]" value="0"><input type="checkbox" name="' + rowName + '[required]" value="1"></label></td>',
						'<td><select name="' + rowName + '[conditional]">' + optionMarkup(conditionOptions) + '</select></td>',
						'<td><input type="text" class="regular-text" name="' + rowName + '[label]" value="" placeholder="Novo campo"></td>',
						'<td><input type="text" class="regular-text" name="' + rowName + '[help]" value=""></td>',
						'<td><textarea class="large-text" rows="3" name="' + rowName + '[options]" placeholder="Uma opção por linha ou valor|rótulo"></textarea></td>',
						'<td class="adam-admin-row-actions"><button type="button" class="button" data-adam-move-up>Subir</button> <button type="button" class="button" data-adam-move-down>Descer</button> <button type="button" class="button button-link-delete adam-button adam-button--danger" data-adam-remove-field>Remover</button></td>',
					'</tr>'
				].join('');
			}

			document.querySelectorAll('[data-adam-form-builder]').forEach(function (container) {
				var body = container.querySelector('[data-adam-form-builder-body]');
				var addButton = container.querySelector('[data-adam-add-field]');

				if (!body || !addButton) {
					return;
				}

				addButton.addEventListener('click', function () {
					var row = document.createElement('tbody');
					row.innerHTML = buildRow(container, body.querySelectorAll('[data-adam-form-row]').length);
					if (row.firstElementChild) {
						body.appendChild(row.firstElementChild);
						refreshOrder(container);
					}
				});

				container.addEventListener('click', function (event) {
					var target = event.target;
					if (!(target instanceof HTMLElement)) {
						return;
					}

					var row = target.closest('[data-adam-form-row]');
					if (!row) {
						return;
					}

					if (target.matches('[data-adam-remove-field]')) {
						row.remove();
						refreshOrder(container);
						return;
					}

					if (target.matches('[data-adam-move-up]') && row.previousElementSibling) {
						row.parentNode.insertBefore(row, row.previousElementSibling);
						refreshOrder(container);
						return;
					}

					if (target.matches('[data-adam-move-down]') && row.nextElementSibling) {
						row.parentNode.insertBefore(row.nextElementSibling, row);
						refreshOrder(container);
					}
				});

				refreshOrder(container);
			});
		}() );
		</script>
		<?php
	}

	/**
	 * Render renewal requests table.
	 *
	 * @param array<int, RenewalRequest> $requests Requests.
	 */
	private function render_renewals_table( array $requests ): void {
		if ( array() === $requests ) {
			$this->render_empty_state( __( 'Não foram encontrados pedidos de renovação.', 'adam-membership' ) );
			return;
		}
		?>
		<table class="widefat striped adam-admin-table adam-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'N.º de Sócio', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Nome do Sócio', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Email', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Validade atual da quota', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Submission Date', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Estado da Renovação', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $requests as $request ) : ?>
					<?php $member = $this->members->find( $request->user_id() ); ?>
					<tr>
						<td><?php echo esc_html( null !== $member ? $this->member_number_label( $member ) : '—' ); ?></td>
						<td><?php echo esc_html( null !== $member ? $member->full_name() : __( 'Sócio não encontrado', 'adam-membership' ) ); ?></td>
						<td><?php echo esc_html( null !== $member ? $member->email() : '—' ); ?></td>
						<td><?php echo esc_html( $this->format_date( $request->current_quota_expiry() ) ?: '—' ); ?></td>
						<td><?php echo esc_html( $request->submitted_at() ); ?></td>
						<td><?php echo esc_html( $this->renewal_status_label( $request->status() ) ); ?></td>
						<td class="adam-admin-row-actions">
							<a class="button button-small" href="<?php echo esc_url( $this->renewal_url( $request ) ); ?>"><?php esc_html_e( 'Rever', 'adam-membership' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( $this->renewal_service->forminator_submission_url( $request ) ); ?>"><?php esc_html_e( 'Forminator Submission', 'adam-membership' ); ?></a>
							<?php if ( '' !== $this->renewal_service->proof_url( $request ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $this->renewal_service->proof_url( $request ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Comprovativo', 'adam-membership' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render renewal request detail.
	 *
	 * @param RenewalRequest $request Request.
	 */
	private function render_renewal_detail( RenewalRequest $request ): void {
		$member  = $this->members->find( $request->user_id() );
		$changes = null !== $member ? $this->renewal_service->changed_fields( $request, $member ) : array();
		$document_rows = $this->renewal_document_rows( $request );
		$document_warnings = $this->missing_renewal_document_warnings( $request );
		?>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Pedido de Renovação', 'adam-membership' ); ?></h2>
			<div class="adam-admin-detail-grid">
				<?php $this->render_detail_item( __( 'Estado', 'adam-membership' ), $this->renewal_status_label( $request->status() ) ); ?>
				<?php $this->render_detail_item( __( 'Submission ID', 'adam-membership' ), (string) $request->submission_id() ); ?>
				<?php $this->render_detail_item( __( 'Submission date', 'adam-membership' ), $request->submitted_at() ); ?>
				<?php $this->render_detail_item( __( 'Captured quota expiry', 'adam-membership' ), $this->format_date( $request->current_quota_expiry() ) ); ?>
			</div>
			<div class="adam-admin-actions">
				<a class="button" href="<?php echo esc_url( $this->renewal_service->forminator_submission_url( $request ) ); ?>"><?php esc_html_e( 'Ver submissão original do Forminator', 'adam-membership' ); ?></a>
				<?php if ( '' !== $this->renewal_service->proof_url( $request ) ) : ?>
					<a class="button" href="<?php echo esc_url( $this->renewal_service->proof_url( $request ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ver comprovativo de pagamento', 'adam-membership' ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<?php $this->render_document_warning_panel( $document_warnings, __( 'Documentos obrigatórios em falta nesta renovação.', 'adam-membership' ) ); ?>
		<div class="adam-admin-financial-document-stack">
			<?php $this->render_private_document_panel( 'renewal', $request->id(), $request->request_uuid(), $this->renewal_url( $request ) ); ?>
			<?php if ( null !== $member ) : ?><?php $this->render_google_sheets_payment_panel( $member, $request ); ?><?php endif; ?>
		</div>
		<?php $this->render_documents_panel( __( 'Documentos submetidos', 'adam-membership' ), $document_rows, null, $request, true ); ?>

		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Submitted changes', 'adam-membership' ); ?></h2>
			<?php if ( array() === $changes ) : ?>
				<?php $this->render_empty_state( __( 'Não foram submetidas alterações ao perfil.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<table class="widefat striped adam-admin-table adam-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Field', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Valor Atual', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Valor Submetido', 'adam-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $changes as $field => $change ) : ?>
							<tr>
								<td><?php echo esc_html( $field ); ?></td>
								<td><?php echo esc_html( $change['old'] ); ?></td>
								<td><?php echo esc_html( $change['new'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<?php if ( in_array( $request->status(), array( RenewalRequest::STATUS_PENDING, RenewalRequest::STATUS_CORRECTION_SUBMITTED ), true ) ) : ?>
			<div class="adam-admin-panel adam-card">
				<h2><?php esc_html_e( 'Decisão de revisão', 'adam-membership' ); ?></h2>
				<div class="adam-admin-actions">
					<?php $this->render_renewal_action_form( $request, self::ACTION_APPROVE_RENEWAL, __( 'Aprovar renovação', 'adam-membership' ), 'button-primary' ); ?>
				<?php $submitted = $request->data()['submitted_data'] ?? array(); if ( 'adam_primary' === (string) ( $submitted['adam_membership_origin'] ?? '' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adam_membership_renewal_action"><input type="hidden" name="renewal_action" value="<?php echo esc_attr( self::ACTION_CONFIRM_ANA_RENEWAL ); ?>"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>"><?php wp_nonce_field( 'adam_membership_renewal_action_' . $request->id() ); ?><label>Data de confirmação ANA <input type="date" name="confirmation_date" required></label><button class="button button-primary">Confirmar ANA e concluir</button></form><?php endif; ?>
				</div>
				<?php $this->render_renewal_correction_selector( $request ); ?>
				<?php $this->render_renewal_rejection_form( $request ); ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render dashboard cards.
	 *
	 * @param array<string, int> $counts          Dashboard counts.
	 * @param array<string, int> $team_statistics Team statistics.
	 */
	private function render_dashboard_cards( array $counts, array $team_statistics ): void {
		$cards = array(
			array( 'label' => __( 'Total de Sócios', 'adam-membership' ), 'value' => $counts['total'] ?? 0 ),
			array( 'label' => __( 'Sócios Ativos', 'adam-membership' ), 'value' => $counts['active'] ?? 0 ),
			array( 'label' => __( 'Sócios Pendentes', 'adam-membership' ), 'value' => $counts['pending'] ?? 0 ),
			array( 'label' => __( 'Renovações Pendentes', 'adam-membership' ), 'value' => $counts['renewal_pending'] ?? 0 ),
			array( 'label' => __( 'Sócios Rejeitados', 'adam-membership' ), 'value' => $counts['rejected'] ?? 0 ),
			array( 'label' => __( 'Inscrições Expiradas', 'adam-membership' ), 'value' => $counts['expired'] ?? 0 ),
			array( 'label' => __( 'A expirar em 30 dias', 'adam-membership' ), 'value' => $counts['expiring_soon'] ?? 0 ),
		);
		?>
		<div class="adam-admin-cards">
			<?php foreach ( $cards as $card ) : ?>
				<div class="adam-admin-card adam-card">
					<span><?php echo esc_html( $card['label'] ); ?></span>
					<strong><?php echo esc_html( (string) $card['value'] ); ?></strong>
				</div>
			<?php endforeach; ?>
			<div class="adam-admin-card adam-admin-card--teams adam-card">
				<span><?php esc_html_e( 'Equipas', 'adam-membership' ); ?></span>
				<div class="adam-admin-team-card-metrics">
					<div>
						<small><?php esc_html_e( 'Total de Equipas', 'adam-membership' ); ?></small>
						<strong><?php echo esc_html( number_format_i18n( $team_statistics['teams'] ) ); ?></strong>
					</div>
					<div>
						<small><?php esc_html_e( 'Equipas Associadas', 'adam-membership' ); ?></small>
						<strong><?php echo esc_html( number_format_i18n( $team_statistics['associated_teams'] ) ); ?></strong>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render dashboard shortcut links.
	 */
	private function render_dashboard_shortcuts_legacy(): void {
		?>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Ações rápidas', 'adam-membership' ); ?></h2>
			<div class="adam-admin-actions">
				<a class="button button-primary adam-button" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-pending' ) ); ?>"><?php esc_html_e( 'Rever sócios pendentes', 'adam-membership' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-members' ) ); ?>"><?php esc_html_e( 'Pesquisar sócios', 'adam-membership' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-members&quota_status=expiring_soon' ) ); ?>"><?php esc_html_e( 'Verificar renovações', 'adam-membership' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render dashboard shortcut sections.
	 *
	 * @param array<string, mixed> $context Dashboard context.
	 */
	private function render_dashboard_shortcuts( array $context ): void {
		$team_statistics = $context['team_statistics'] ?? array();
		$team_count      = (int) ( $team_statistics['teams'] ?? 0 );

		$sections = array(
			array(
				'title' => __( 'Gestão de Sócios', 'adam-membership' ),
				'items' => array(
					array(
						'icon'        => 'groups',
						'title'       => __( 'Sócios pendentes', 'adam-membership' ),
						'description' => __( 'Rever novas inscrições e validar pedidos em espera.', 'adam-membership' ),
						'button'      => __( 'Abrir pendentes', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-pending' ),
						'badge'       => (int) ( $context['counts']['pending'] ?? 0 ),
					),
					array(
						'icon'        => 'id-alt',
						'title'       => __( 'Lista de sócios', 'adam-membership' ),
						'description' => __( 'Pesquisar, filtrar e gerir todos os sócios ADAM.', 'adam-membership' ),
						'button'      => __( 'Abrir sócios', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-members' ),
						'badge'       => (int) ( $context['counts']['total'] ?? 0 ),
					),
					array(
						'icon'        => 'groups',
						'title'       => __( 'Equipas', 'adam-membership' ),
						'description' => __( 'Gerir equipas, consultar membros e estado das equipas.', 'adam-membership' ),
						'button'      => __( 'Abrir Equipas', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=' . self::TEAMS_PAGE_SLUG ),
						'badge'       => $team_count,
						'details'     => array(
							sprintf(
								/* translators: %s: number of teams. */
								_n( '%s Equipa', '%s Equipas', $team_count, 'adam-membership' ),
								number_format_i18n( $team_count )
							),
							sprintf(
								/* translators: %s: number of teams with active members. */
								__( '%s com sócios ativos', 'adam-membership' ),
								number_format_i18n( (int) ( $team_statistics['teams_with_active_members'] ?? 0 ) )
							),
							sprintf(
								/* translators: %s: number of associated teams. */
								_n( '%s Equipa Associada', '%s Equipas Associadas', (int) ( $team_statistics['associated_teams'] ?? 0 ), 'adam-membership' ),
								number_format_i18n( (int) ( $team_statistics['associated_teams'] ?? 0 ) )
							),
						),
					),
					array(
						'icon'        => 'update',
						'title'       => __( 'Pedidos de renovação', 'adam-membership' ),
						'description' => __( 'Acompanhar renovações submetidas e decisões pendentes.', 'adam-membership' ),
						'button'      => __( 'Abrir renovações', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-renewals' ),
						'badge'       => count( $context['pending_renewals_all'] ?? array() ),
					),
					array(
						'icon'        => 'backup',
						'title'       => __( 'Histórico de sócios', 'adam-membership' ),
						'description' => __( 'Consultar atividade, alterações e auditoria dos sócios.', 'adam-membership' ),
						'button'      => __( 'Abrir histórico', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=' . self::HISTORY_PAGE_SLUG ),
					),
				),
			),
			array(
				'title' => __( 'Comunicação e Documentos', 'adam-membership' ),
				'items' => array(
					array(
						'icon'        => 'megaphone',
						'title'       => __( 'Centro de Avisos', 'adam-membership' ),
						'description' => __( 'Gerir avisos, prioridades e ações ligadas a documentos.', 'adam-membership' ),
						'button'      => __( 'Abrir avisos', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-notices' ),
						'badge'       => count( $context['announcements_all'] ?? array() ),
					),
					array(
						'icon'        => 'media-document',
						'title'       => __( 'Documentos', 'adam-membership' ),
						'description' => __( 'Organizar ficheiros oficiais, versões e visibilidade por público.', 'adam-membership' ),
						'button'      => __( 'Abrir documentos', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-documents' ),
						'badge'       => count( $context['documents_all'] ?? array() ),
					),
					array(
						'icon'        => 'edit',
						'title'       => __( 'Criar novo aviso', 'adam-membership' ),
						'description' => __( 'Publicar comunicações rápidas para a área do sócio.', 'adam-membership' ),
						'button'      => __( 'Criar aviso', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-notice-edit' ),
					),
					array(
						'icon'        => 'upload',
						'title'       => __( 'Adicionar documento', 'adam-membership' ),
						'description' => __( 'Carregar um novo documento oficial para os sócios.', 'adam-membership' ),
						'button'      => __( 'Adicionar documento', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-document-edit' ),
					),
				),
			),
			array(
				'title' => __( 'Eventos e Pontos', 'adam-membership' ),
				'items' => array(
					array(
						'icon'        => 'calendar-alt',
						'title'       => __( 'Eventos', 'adam-membership' ),
						'description' => __( 'Gerir eventos, páginas públicas e QR codes de check-in.', 'adam-membership' ),
						'button'      => __( 'Abrir eventos', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=' . ( function_exists( '\adam_comunidade_events' ) ? 'adam-comunidade-events' : 'adam-membership-events' ) ),
						'badge'       => count( $context['events_all'] ?? array() ),
					),
					array(
						'icon'        => 'plus-alt2',
						'title'       => __( 'Criar novo evento', 'adam-membership' ),
						'description' => __( 'Criar rapidamente um novo evento ADAM.', 'adam-membership' ),
						'button'      => __( 'Criar evento', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=' . ( function_exists( '\adam_comunidade_events' ) ? 'adam-comunidade-event-add' : 'adam-membership-event-edit' ) ),
					),
					array(
						'icon'        => 'star-half',
						'title'       => __( 'Pontos', 'adam-membership' ),
						'description' => __( 'Ver movimentos, rankings e ajustes manuais de pontos.', 'adam-membership' ),
						'button'      => __( 'Abrir pontos', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-points' ),
					),
					array(
						'icon'        => 'awards',
						'title'       => __( 'Recompensas', 'adam-membership' ),
						'description' => __( 'Gerir catálogo, pedidos de resgate e entregas.', 'adam-membership' ),
						'button'      => __( 'Abrir recompensas', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-rewards' ),
						'badge'       => count( $context['pending_reward_redemptions'] ?? array() ),
					),
				),
			),
			array(
				'title' => __( 'Ferramentas', 'adam-membership' ),
				'items' => array(
					array(
						'icon'        => 'admin-generic',
						'title'       => __( 'Configurações', 'adam-membership' ),
						'description' => __( 'Ajustar URLs, identidade da associação e manutenção.', 'adam-membership' ),
						'button'      => __( 'Abrir configurações', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-settings' ),
					),
					array(
						'icon'        => 'search',
						'title'       => __( 'Diagnósticos', 'adam-membership' ),
						'description' => __( 'Ver estado do sistema, manutenção e dados principais do plugin.', 'adam-membership' ),
						'button'      => __( 'Abrir diagnósticos', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=' . self::DIAGNOSTICS_PAGE_SLUG ),
					),
					array(
						'icon'        => 'download',
						'title'       => __( 'Exportar CSV', 'adam-membership' ),
						'description' => __( 'Exportar a lista completa de sócios para análise externa.', 'adam-membership' ),
						'button'      => __( 'Exportar CSV', 'adam-membership' ),
						'url'         => wp_nonce_url( admin_url( 'admin-post.php?action=adam_membership_export_members_csv' ), 'adam_membership_export_members_csv' ),
					),
					array(
						'icon'        => 'list-view',
						'title'       => __( 'Ver logs', 'adam-membership' ),
						'description' => __( 'Consultar o histórico operacional e a atividade recente.', 'adam-membership' ),
						'button'      => __( 'Abrir logs', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=' . self::HISTORY_PAGE_SLUG ),
					),
				),
			),
		);
		?>
		<div class="adam-admin-dashboard-sections">
			<?php foreach ( $sections as $section ) : ?>
				<section class="adam-admin-panel adam-admin-shortcut-panel adam-card">
					<div class="adam-admin-dashboard-heading">
						<h2><?php echo esc_html( $section['title'] ); ?></h2>
					</div>
					<div class="adam-admin-shortcut-grid">
						<?php foreach ( $section['items'] as $item ) : ?>
							<?php $this->render_dashboard_shortcut_card( $item ); ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Build dashboard context.
	 *
	 * @param array<string, int> $counts Dashboard counts.
	 * @return array<string, mixed>
	 */
	private function dashboard_context( array $counts ): array {
		$all_announcements        = $this->announcements->admin_list();
		$all_documents            = $this->documents->admin_list();
		$all_events               = $this->events->admin_events();
		$all_checkins             = $this->events->repository()->query_checkins();
		$pending_renewals_all     = $this->renewal_repository->admin_requests( array( 'status' => RenewalRequest::STATUS_PENDING ) );
		$pending_reward_requests  = $this->rewards->admin_redemptions( array( 'status' => RewardRedemption::STATUS_PENDING ) );
		$upcoming_events          = array_values(
			array_filter(
				$all_events,
				static function ( Event $event ): bool {
					return Event::STATUS_DRAFT !== $event->status() && $event->starts_at_timestamp() >= current_time( 'timestamp' );
				}
			)
		);

		return array(
			'counts'                    => $counts,
			'latest_members'            => array_slice( $this->members->admin_members( array( 'orderby' => 'registered', 'order' => 'desc' ) ), 0, 5 ),
			'pending_renewals'          => array_slice( $pending_renewals_all, 0, 5 ),
			'pending_renewals_all'      => $pending_renewals_all,
			'upcoming_events'           => array_slice( $upcoming_events, 0, 5 ),
			'announcements_recent'      => array_slice( $all_announcements, 0, 5 ),
			'announcements_all'         => $all_announcements,
			'documents_all'             => $all_documents,
			'events_all'                => $all_events,
			'recent_checkins'           => array_slice( $all_checkins, 0, 5 ),
			'recent_history'            => $this->history_repository->query( array( 'limit' => 6 ) ),
			'pending_reward_redemptions' => $pending_reward_requests,
		);
	}

	/**
	 * Render dashboard widget grid.
	 *
	 * @param array<string, mixed> $context Dashboard context.
	 */
	private function render_dashboard_widgets( array $context ): void {
		?>
		<div class="adam-admin-dashboard-widgets">
			<?php $this->render_dashboard_widget_latest_members( $context['latest_members'] ?? array() ); ?>
			<?php $this->render_dashboard_widget_pending_renewals( $context['pending_renewals'] ?? array() ); ?>
			<?php $this->render_dashboard_widget_upcoming_events( $context['upcoming_events'] ?? array() ); ?>
			<?php $this->render_dashboard_widget_recent_announcements( $context['announcements_recent'] ?? array() ); ?>
			<?php $this->render_dashboard_widget_recent_checkins( $context['recent_checkins'] ?? array() ); ?>
			<?php $this->render_dashboard_widget_recent_activity( $context['recent_history'] ?? array() ); ?>
		</div>
		<?php
	}

	/**
	 * Render one dashboard shortcut card.
	 *
	 * @param array<string, mixed> $item Shortcut data.
	 */
	private function render_dashboard_shortcut_card( array $item ): void {
		$badge   = isset( $item['badge'] ) ? (int) $item['badge'] : null;
		$details = isset( $item['details'] ) && is_array( $item['details'] ) ? $item['details'] : array();
		?>
		<article class="adam-admin-shortcut-card">
			<div class="adam-admin-shortcut-card__top">
				<span class="dashicons dashicons-<?php echo esc_attr( (string) $item['icon'] ); ?>" aria-hidden="true"></span>
				<?php if ( null !== $badge ) : ?>
					<span class="adam-admin-badge"><?php echo esc_html( number_format_i18n( $badge ) ); ?></span>
				<?php endif; ?>
			</div>
			<h3><?php echo esc_html( (string) $item['title'] ); ?></h3>
			<p><?php echo esc_html( (string) $item['description'] ); ?></p>
			<?php if ( array() !== $details ) : ?>
				<ul class="adam-admin-shortcut-card__details">
					<?php foreach ( $details as $detail ) : ?>
						<li><?php echo esc_html( (string) $detail ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="adam-admin-shortcut-card__footer">
				<a class="button button-secondary adam-button adam-button--secondary" href="<?php echo esc_url( (string) $item['url'] ); ?>"><?php echo esc_html( (string) $item['button'] ); ?></a>
			</div>
		</article>
		<?php
	}

	/**
	 * Render recent members widget.
	 *
	 * @param array<int, Member> $members Members.
	 */
	private function render_dashboard_widget_latest_members( array $members ): void {
		?>
		<section class="adam-admin-panel adam-admin-widget-panel adam-card">
			<div class="adam-admin-dashboard-heading">
				<h2><?php esc_html_e( 'Últimos sócios registados', 'adam-membership' ); ?></h2>
			</div>
			<?php if ( array() === $members ) : ?>
				<?php $this->render_empty_state( __( 'Ainda não existem sócios registados.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<div class="adam-admin-widget-list">
					<?php foreach ( $members as $member ) : ?>
						<?php $user = $member->user(); ?>
						<div class="adam-admin-widget-item">
							<div>
								<strong><?php echo esc_html( $member->full_name() ); ?></strong>
								<small><?php echo esc_html( $member->email() ); ?></small>
							</div>
							<div class="adam-admin-widget-item__meta">
								<?php $this->render_status_badge( $member->effective_status() ); ?>
								<span><?php echo esc_html( $this->format_date( (string) $member->field( 'data_adesao' ) ) ?: $this->format_datetime( $user instanceof \WP_User ? (string) $user->user_registered : '' ) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<int, RenewalRequest> $requests Renewal requests.
	 */
	private function render_dashboard_widget_pending_renewals( array $requests ): void {
		?>
		<section class="adam-admin-panel adam-admin-widget-panel adam-card">
			<div class="adam-admin-dashboard-heading">
				<h2><?php esc_html_e( 'Renovações pendentes', 'adam-membership' ); ?></h2>
			</div>
			<?php if ( array() === $requests ) : ?>
				<?php $this->render_empty_state( __( 'Não existem renovações pendentes neste momento.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<div class="adam-admin-widget-list">
					<?php foreach ( $requests as $request ) : ?>
						<?php $member = $this->members->find( $request->user_id() ); ?>
						<div class="adam-admin-widget-item">
							<div>
								<strong><?php echo esc_html( null !== $member ? $member->full_name() : __( 'Sócio indisponível', 'adam-membership' ) ); ?></strong>
								<small><?php echo esc_html( $this->format_datetime( $request->submitted_at() ) ); ?></small>
							</div>
							<div class="adam-admin-widget-item__meta">
								<a class="button button-small" href="<?php echo esc_url( $this->renewal_url( $request ) ); ?>"><?php esc_html_e( 'Abrir', 'adam-membership' ); ?></a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<int, Event> $events Events.
	 */
	private function render_dashboard_widget_upcoming_events( array $events ): void {
		?>
		<section class="adam-admin-panel adam-admin-widget-panel adam-card">
			<div class="adam-admin-dashboard-heading">
				<h2><?php esc_html_e( 'Próximos eventos', 'adam-membership' ); ?></h2>
			</div>
			<?php if ( array() === $events ) : ?>
				<?php $this->render_empty_state( __( 'Não existem próximos eventos agendados.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<div class="adam-admin-widget-list">
					<?php foreach ( $events as $event ) : ?>
						<div class="adam-admin-widget-item">
							<div>
								<strong><?php echo esc_html( $event->title() ); ?></strong>
								<small><?php echo esc_html( $this->format_date( $event->event_date() ) . ( '' !== $event->start_time() ? ' ' . $event->start_time() : '' ) ); ?></small>
							</div>
							<div class="adam-admin-widget-item__meta">
								<span><?php echo esc_html( $event->location() ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<int, Announcement> $announcements Announcements.
	 */
	private function render_dashboard_widget_recent_announcements( array $announcements ): void {
		?>
		<section class="adam-admin-panel adam-admin-widget-panel adam-card">
			<div class="adam-admin-dashboard-heading">
				<h2><?php esc_html_e( 'Avisos recentes', 'adam-membership' ); ?></h2>
			</div>
			<?php if ( array() === $announcements ) : ?>
				<?php $this->render_empty_state( __( 'Ainda não existem avisos criados.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<div class="adam-admin-widget-list">
					<?php foreach ( $announcements as $announcement ) : ?>
						<div class="adam-admin-widget-item">
							<div>
								<strong><?php echo esc_html( $announcement->title() ); ?></strong>
								<small><?php echo esc_html( $announcement->category() ); ?></small>
							</div>
							<div class="adam-admin-widget-item__meta">
								<span><?php echo esc_html( $this->format_date( $announcement->publish_date() ) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<int, EventCheckIn> $checkins Check-ins.
	 */
	private function render_dashboard_widget_recent_checkins( array $checkins ): void {
		?>
		<section class="adam-admin-panel adam-admin-widget-panel adam-card">
			<div class="adam-admin-dashboard-heading">
				<h2><?php esc_html_e( 'Últimos check-ins', 'adam-membership' ); ?></h2>
			</div>
			<?php if ( array() === $checkins ) : ?>
				<?php $this->render_empty_state( __( 'Ainda não existem check-ins registados.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<div class="adam-admin-widget-list">
					<?php foreach ( $checkins as $checkin ) : ?>
						<?php
						$member = $this->members->find( $checkin->member_id() );
						$event  = $this->events->repository()->find_event( $checkin->event_id() );
						?>
						<div class="adam-admin-widget-item">
							<div>
								<strong><?php echo esc_html( null !== $member ? $member->full_name() : __( 'Sócio indisponível', 'adam-membership' ) ); ?></strong>
								<small><?php echo esc_html( null !== $event ? $event->title() : __( 'Evento indisponível', 'adam-membership' ) ); ?></small>
							</div>
							<div class="adam-admin-widget-item__meta">
								<span><?php echo esc_html( $this->format_datetime( $checkin->checked_in_at() ) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * @param array<int, HistoryEntry> $entries History entries.
	 */
	private function render_dashboard_widget_recent_activity( array $entries ): void {
		?>
		<section class="adam-admin-panel adam-admin-widget-panel adam-card">
			<div class="adam-admin-dashboard-heading">
				<h2><?php esc_html_e( 'Atividade recente', 'adam-membership' ); ?></h2>
			</div>
			<?php if ( array() === $entries ) : ?>
				<?php $this->render_empty_state( __( 'Ainda não existe atividade recente para mostrar.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<div class="adam-admin-widget-list">
					<?php foreach ( $entries as $entry ) : ?>
						<div class="adam-admin-widget-item">
							<div>
								<strong><?php echo esc_html( $entry->action_label() ); ?></strong>
								<small><?php echo esc_html( $entry->description() ); ?></small>
							</div>
							<div class="adam-admin-widget-item__meta">
								<span><?php echo esc_html( $this->format_datetime( $entry->created_at() ) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render team search and ordering controls.
	 *
	 * @param array<string, string> $filters Current filters.
	 */
	private function render_team_filters( array $filters ): void {
		?>
		<form method="get" class="adam-admin-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::TEAMS_PAGE_SLUG ); ?>">
			<label>
				<span><?php esc_html_e( 'Pesquisar', 'adam-membership' ); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Nome ou parte do nome', 'adam-membership' ); ?>">
			</label>
			<label>
				<span><?php esc_html_e( 'Ordenar por', 'adam-membership' ); ?></span>
				<select name="orderby">
					<?php $this->render_select_option( 'name', __( 'Nome', 'adam-membership' ), $filters['orderby'] ?? 'name' ); ?>
					<?php $this->render_select_option( 'members', __( 'Número de sócios ativos', 'adam-membership' ), $filters['orderby'] ?? 'name' ); ?>
					<?php $this->render_select_option( 'created_at', __( 'Data de criação', 'adam-membership' ), $filters['orderby'] ?? 'name' ); ?>
					<?php $this->render_select_option( 'updated_at', __( 'Última atualização', 'adam-membership' ), $filters['orderby'] ?? 'name' ); ?>
					<?php $this->render_select_option( 'type', __( 'Estado', 'adam-membership' ), $filters['orderby'] ?? 'name' ); ?>
					<?php $this->render_select_option( 'eligible', __( 'Elegibilidade', 'adam-membership' ), $filters['orderby'] ?? 'name' ); ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Direção', 'adam-membership' ); ?></span>
				<select name="order">
					<?php $this->render_select_option( 'asc', __( 'Ascendente', 'adam-membership' ), $filters['order'] ?? 'asc' ); ?>
					<?php $this->render_select_option( 'desc', __( 'Descendente', 'adam-membership' ), $filters['order'] ?? 'asc' ); ?>
				</select>
			</label>
			<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Aplicar', 'adam-membership' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::TEAMS_PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Repor', 'adam-membership' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Render the team administration table.
	 *
	 * @param array<int, array{team:Team,active_members:int,total_members:int,eligible:bool}> $rows Team rows.
	 * @param array<string, string>                                                           $filters Current filters.
	 */
	private function render_teams_table( array $rows, array $filters ): void {
		if ( array() === $rows ) {
			$this->render_empty_state( __( 'Não foram encontradas equipas para os filtros atuais.', 'adam-membership' ) );
			return;
		}
		?>
		<table class="widefat striped adam-admin-table adam-table">
			<thead>
				<tr>
					<th><?php echo wp_kses_post( $this->team_sort_link( __( 'Nome da equipa', 'adam-membership' ), 'name', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->team_sort_link( __( 'N.º de sócios ativos', 'adam-membership' ), 'members', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->team_sort_link( __( 'Estado', 'adam-membership' ), 'type', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->team_sort_link( __( 'Elegível', 'adam-membership' ), 'eligible', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->team_sort_link( __( 'Data de criação', 'adam-membership' ), 'created_at', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->team_sort_link( __( 'Última atualização', 'adam-membership' ), 'updated_at', $filters ) ); ?></th>
					<th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $team = $row['team']; ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( $this->team_url( $team ) ); ?>"><?php echo esc_html( $team->name() ); ?></a></strong></td>
						<td><?php echo esc_html( number_format_i18n( $row['active_members'] ) ); ?></td>
						<td>
							<?php $this->render_team_type_badge( $team ); ?>
							<?php if ( Team::TYPE_ASSOCIATED === $team->type() && ! $row['eligible'] ) : ?>
								<?php
								/* translators: %d: current number of active team members. */
								$eligibility_warning = sprintf( __( 'Rever: atualmente apenas %d sócios ativos.', 'adam-membership' ), $row['active_members'] );
								?>
								<small class="adam-admin-warning-text"><?php echo esc_html( $eligibility_warning ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php $this->render_team_eligibility_badge( $row['eligible'] ); ?></td>
						<td><?php echo esc_html( $this->format_datetime( $team->created_at() ) ); ?></td>
						<td><?php echo esc_html( $this->format_datetime( $team->updated_at() ) ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( $this->team_url( $team ) ); ?>"><?php esc_html_e( 'Ver', 'adam-membership' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a team detail and editing screen.
	 *
	 * @param Team $team Team.
	 */
	private function render_team_detail( Team $team ): void {
		$members      = $this->teams->members_for_team( $team->id() );
		$summary      = $this->teams->summary( $team );
		$minimum      = $this->teams->associated_minimum_active_members();
		$active_count = $summary['active_member_count'] ?? 0;
		$total_count  = $summary['member_count'] ?? 0;
		$eligible     = ! empty( $summary['eligible'] );
		$below_limit  = ! $eligible;
		/* translators: %d: current number of active team members. */
		$eligibility_warning = sprintf( __( 'Esta equipa possui atualmente apenas %d sócios ativos. Já não cumpre os requisitos mínimos para ser Equipa Associada. Reveja esta situação.', 'adam-membership' ), $active_count );
		/* translators: %d: minimum number of active team members. */
		$minimum_label = sprintf( __( '%d sócios', 'adam-membership' ), $minimum );
		?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::TEAMS_PAGE_SLUG ) ); ?>">&larr; <?php esc_html_e( 'Voltar à lista de equipas', 'adam-membership' ); ?></a></p>

		<?php if ( Team::TYPE_ASSOCIATED === $team->type() && $below_limit ) : ?>
			<div class="notice notice-warning inline adam-notice adam-notice--warning"><p><?php echo esc_html( $eligibility_warning ); ?></p></div>
		<?php endif; ?>

		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Informação', 'adam-membership' ); ?></h2>
			<div class="adam-admin-detail-grid">
				<?php $this->render_detail_item( __( 'Nome', 'adam-membership' ), $team->name() ); ?>
				<?php $this->render_detail_item( __( 'Slug', 'adam-membership' ), $team->slug() ); ?>
				<?php $this->render_detail_item( __( 'Estado', 'adam-membership' ), $this->team_type_label( $team ) ); ?>
				<?php $this->render_detail_item( __( 'Data de criação', 'adam-membership' ), $this->format_datetime( $team->created_at() ) ); ?>
				<?php $this->render_detail_item( __( 'Última atualização', 'adam-membership' ), $this->format_datetime( $team->updated_at() ) ); ?>
				<?php $this->render_detail_item( __( 'Sócios associados', 'adam-membership' ), (string) $total_count ); ?>
			</div>
		</div>

		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Estado da Associação', 'adam-membership' ); ?></h2>
			<div class="adam-admin-detail-grid">
				<?php $this->render_detail_item( __( 'Estado atual', 'adam-membership' ), $this->team_type_label( $team ) ); ?>
				<?php $this->render_detail_item( __( 'Sócios ativos', 'adam-membership' ), (string) $active_count ); ?>
				<?php $this->render_detail_item( __( 'Requisito mínimo', 'adam-membership' ), $minimum_label ); ?>
				<?php $this->render_detail_item( __( 'Elegível', 'adam-membership' ), $eligible ? __( 'Sim', 'adam-membership' ) : __( 'Não', 'adam-membership' ) ); ?>
			</div>
		</div>

		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Editar equipa', 'adam-membership' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_team_action">
				<input type="hidden" name="team_action" value="<?php echo esc_attr( self::ACTION_SAVE_TEAM ); ?>">
				<input type="hidden" name="team_id" value="<?php echo esc_attr( (string) $team->id() ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->team_url( $team ) ); ?>">
				<?php wp_nonce_field( 'adam_membership_team_action_' . $team->id() ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adam-team-name"><?php esc_html_e( 'Nome', 'adam-membership' ); ?></label></th>
						<td><input id="adam-team-name" type="text" name="team_name" class="regular-text" maxlength="191" required value="<?php echo esc_attr( $team->name() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tipo', 'adam-membership' ); ?></th>
						<td>
							<label><input type="radio" name="team_type" value="<?php echo esc_attr( Team::TYPE_TEAM ); ?>" <?php checked( Team::TYPE_TEAM, $team->type() ); ?>> <?php esc_html_e( 'Equipa', 'adam-membership' ); ?></label><br>
							<label><input type="radio" name="team_type" value="<?php echo esc_attr( Team::TYPE_ASSOCIATED ); ?>" <?php checked( Team::TYPE_ASSOCIATED, $team->type() ); ?> <?php disabled( $below_limit && Team::TYPE_ASSOCIATED !== $team->type() ); ?>> <?php esc_html_e( 'Equipa Associada', 'adam-membership' ); ?></label>
							<?php if ( $below_limit && Team::TYPE_ASSOCIATED !== $team->type() ) : ?>
								<p class="description"><?php esc_html_e( 'Esta equipa necessita de pelo menos 5 sócios ativos para poder ser marcada como Equipa Associada.', 'adam-membership' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Guardar equipa', 'adam-membership' ) ); ?>
			</form>
		</div>

		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Sócios', 'adam-membership' ); ?></h2>
			<?php $this->render_team_members_table( $members ); ?>
		</div>

		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Benefícios', 'adam-membership' ); ?></h2>
			<fieldset class="adam-team-benefit-options" disabled aria-disabled="true">
				<label><input type="checkbox" disabled> <?php esc_html_e( 'Desconto nas quotas', 'adam-membership' ); ?></label>
				<label><input type="checkbox" disabled> <?php esc_html_e( 'Prioridade em eventos', 'adam-membership' ); ?></label>
				<label><input type="checkbox" disabled> <?php esc_html_e( 'Destaque no website', 'adam-membership' ); ?></label>
				<label><input type="checkbox" disabled> <?php esc_html_e( 'Benefícios de parceiros', 'adam-membership' ); ?></label>
				<label><input type="checkbox" disabled> <?php esc_html_e( 'Outros benefícios futuros', 'adam-membership' ); ?></label>
			</fieldset>
			<p class="description"><?php esc_html_e( 'Estas opções são apenas uma preparação visual e não alteram o comportamento do plugin.', 'adam-membership' ); ?></p>
		</div>

		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Eliminar equipa', 'adam-membership' ); ?></h2>
			<?php if ( $total_count > 0 ) : ?>
				<p><?php esc_html_e( 'Não é possível eliminar uma equipa que possui sócios associados.', 'adam-membership' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Tem a certeza de que pretende eliminar esta equipa?', 'adam-membership' ) ); ?>');">
					<input type="hidden" name="action" value="adam_membership_team_action">
					<input type="hidden" name="team_action" value="<?php echo esc_attr( self::ACTION_DELETE_TEAM ); ?>">
					<input type="hidden" name="team_id" value="<?php echo esc_attr( (string) $team->id() ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( admin_url( 'admin.php?page=' . self::TEAMS_PAGE_SLUG ) ); ?>">
					<?php wp_nonce_field( 'adam_membership_team_action_' . $team->id() ); ?>
					<button type="submit" class="button button-link-delete adam-button adam-button--danger"><?php esc_html_e( 'Eliminar equipa', 'adam-membership' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render members currently associated with a team.
	 *
	 * @param array<int, Member> $members Team members.
	 */
	private function render_team_members_table( array $members ): void {
		if ( array() === $members ) {
			$this->render_empty_state( __( 'Esta equipa ainda não possui sócios associados.', 'adam-membership' ) );
			return;
		}
		?>
		<table class="widefat striped adam-admin-table adam-table">
			<thead><tr>
				<th><?php esc_html_e( 'N.º ADAM', 'adam-membership' ); ?></th>
				<th><?php esc_html_e( 'Nome', 'adam-membership' ); ?></th>
				<th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th>
				<th><?php esc_html_e( 'Expiração', 'adam-membership' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $members as $member ) : ?>
					<tr>
						<td><?php echo esc_html( $this->member_number_label( $member ) ); ?></td>
						<td><a href="<?php echo esc_url( $this->member_url( $member ) ); ?>"><?php echo esc_html( $member->full_name() ); ?></a></td>
						<td><?php $this->render_status_badge( $member->effective_status() ); ?></td>
						<td><?php echo esc_html( $this->format_date( $member->field( 'validade_quota' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render member filters.
	 *
	 * @param array<string, string> $filters      Current filters.
	 * @param bool                  $force_pending Whether status is fixed to pending.
	 */
	private function render_member_filters( array $filters, bool $force_pending ): void {
		?>
		<form method="get" class="adam-admin-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( $force_pending ? 'adam-membership-pending' : 'adam-membership-members' ); ?>">
			<label>
				<span><?php esc_html_e( 'Pesquisar', 'adam-membership' ); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Nome, email, número de sócio', 'adam-membership' ); ?>">
			</label>

			<?php if ( ! $force_pending ) : ?>
				<label>
					<span><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
					<select name="status">
						<?php $this->render_select_option( '', __( 'Todos os estados', 'adam-membership' ), $filters['status'] ?? '' ); ?>
						<?php $this->render_select_option( Member::STATUS_ACTIVE, __( 'Ativo', 'adam-membership' ), $filters['status'] ?? '' ); ?>
						<?php $this->render_select_option( Member::STATUS_PENDING, __( 'Pendente', 'adam-membership' ), $filters['status'] ?? '' ); ?>
						<?php $this->render_select_option( Member::STATUS_RENEWAL_PENDING, __( 'Renovação pendente', 'adam-membership' ), $filters['status'] ?? '' ); ?>
						<?php $this->render_select_option( Member::STATUS_EXPIRED, __( 'Expirado', 'adam-membership' ), $filters['status'] ?? '' ); ?>
						<?php $this->render_select_option( Member::STATUS_REJECTED, __( 'Rejeitado', 'adam-membership' ), $filters['status'] ?? '' ); ?>
					</select>
				</label>
			<?php endif; ?>

			<label>
				<span><?php esc_html_e( 'Quota', 'adam-membership' ); ?></span>
				<select name="quota_status">
					<?php $this->render_select_option( '', __( 'Todas as quotas', 'adam-membership' ), $filters['quota_status'] ?? '' ); ?>
					<?php $this->render_select_option( Member::QUOTA_ACTIVE, __( 'Ativa', 'adam-membership' ), $filters['quota_status'] ?? '' ); ?>
					<?php $this->render_select_option( Member::QUOTA_EXPIRED, __( 'Expirada', 'adam-membership' ), $filters['quota_status'] ?? '' ); ?>
					<?php $this->render_select_option( Member::QUOTA_EXPIRING_SOON, __( 'A expirar brevemente', 'adam-membership' ), $filters['quota_status'] ?? '' ); ?>
				</select>
			</label>

			<label>
				<span><?php esc_html_e( 'Sort', 'adam-membership' ); ?></span>
				<select name="member_number_sort">
					<?php $this->render_select_option( '', __( 'Predefinição', 'adam-membership' ), $filters['member_number_sort'] ?? '' ); ?>
					<?php $this->render_select_option( 'asc', __( 'Número de sócio: do menor para o maior', 'adam-membership' ), $filters['member_number_sort'] ?? '' ); ?>
					<?php $this->render_select_option( 'desc', __( 'Número de sócio: do maior para o menor', 'adam-membership' ), $filters['member_number_sort'] ?? '' ); ?>
				</select>
			</label>

			<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Aplicar filtros', 'adam-membership' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ( $force_pending ? 'adam-membership-pending' : 'adam-membership-members' ) ) ); ?>"><?php esc_html_e( 'Repor', 'adam-membership' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Render complete-record export controls for a member list.
	 *
	 * @param array<int, Member> $members Current visible members.
	 * @param bool               $pending Whether this is the pending list.
	 */
	private function render_complete_export_controls( array $members, bool $pending ): void {
		$all_scope = $pending ? 'pending' : 'approved';
		$all_label = $pending ? __( 'Exportar todos os registos pendentes', 'adam-membership' ) : __( 'Exportar todos os sócios', 'adam-membership' );
		$all_url   = wp_nonce_url(
			add_query_arg(
				array(
					'action'       => 'adam_membership_export_complete_zip',
					'export_scope' => $all_scope,
				),
				admin_url( 'admin-post.php' )
			),
			'adam_membership_export_complete_zip'
		);
		?>
		<div class="adam-admin-panel adam-card adam-complete-export-controls">
			<div>
				<h2><?php esc_html_e( 'Exportar Registos Completos (ZIP)', 'adam-membership' ); ?></h2>
				<p><?php esc_html_e( 'Inclui um ficheiro Informacao.xlsx e todos os documentos carregados, organizados numa pasta por sócio.', 'adam-membership' ); ?></p>
			</div>
			<div class="adam-admin-row-actions">
				<form id="adam-complete-export-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="adam_membership_export_complete_zip">
					<input type="hidden" name="export_scope" value="selected">
					<?php wp_nonce_field( 'adam_membership_export_complete_zip' ); ?>
					<button type="submit" class="button button-primary" <?php disabled( array() === $members ); ?>><?php esc_html_e( 'Exportar selecionados', 'adam-membership' ); ?></button>
				</form>
				<a class="button" href="<?php echo esc_url( $all_url ); ?>"><?php echo esc_html( $all_label ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Build a nonce-protected complete export URL for one member.
	 *
	 * @param Member $member Member record.
	 */
	private function complete_export_member_url( Member $member ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'       => 'adam_membership_export_complete_zip',
					'export_scope' => 'selected',
					'member_ids'   => array( $member->user_id() ),
				),
				admin_url( 'admin-post.php' )
			),
			'adam_membership_export_complete_zip'
		);
	}

	/**
	 * Render a member table.
	 *
	 * @param array<int, Member>    $members      Members to render.
	 * @param bool                  $show_actions Whether to show approval actions.
	 * @param array<string, string> $filters      Current filters.
	 */
	private function render_members_table( array $members, bool $show_actions, array $filters ): void {
		if ( array() === $members ) {
			$this->render_empty_state( __( 'Não foram encontrados sócios para os filtros atuais.', 'adam-membership' ) );
			return;
		}
		?>
		<table class="widefat striped adam-admin-table adam-table">
			<thead>
				<tr>
					<th class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Selecionar', 'adam-membership' ); ?></span></th>
					<th><?php esc_html_e( 'Fotografia', 'adam-membership' ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Nome', 'adam-membership' ), 'name', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Email', 'adam-membership' ), 'email', $filters ) ); ?></th>
					<th><?php esc_html_e( 'Telefone', 'adam-membership' ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Registado', 'adam-membership' ), 'registered', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Estado', 'adam-membership' ), 'status', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'N.º de sócio', 'adam-membership' ), 'member_number', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Quota', 'adam-membership' ), 'quota', $filters ) ); ?></th>
					<th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $members as $member ) : ?>
					<tr>
						<td class="check-column"><input type="checkbox" name="member_ids[]" value="<?php echo esc_attr( (string) $member->user_id() ); ?>" form="adam-complete-export-form" aria-label="<?php echo esc_attr( __( 'Selecionar', 'adam-membership' ) . ': ' . $member->full_name() ); ?>"></td>
						<td><?php $this->render_profile_photo( $member ); ?></td>
						<td><strong><?php echo esc_html( $member->full_name() ); ?></strong></td>
						<td><a href="mailto:<?php echo esc_attr( $member->email() ); ?>"><?php echo esc_html( $member->email() ); ?></a></td>
						<td><?php echo esc_html( (string) $member->field( 'telefone' ) ?: '—' ); ?></td>
						<td><?php echo esc_html( $member->registration_date() ); ?></td>
						<td><?php $this->render_status_badge( $member->effective_status() ); ?></td>
						<td><?php echo esc_html( $this->member_number_label( $member ) ); ?></td>
						<td><?php $this->render_quota_badge( $member ); ?></td>
						<td class="adam-admin-row-actions">
							<a class="button button-small" href="<?php echo esc_url( $this->member_url( $member ) ); ?>"><?php esc_html_e( 'Ver', 'adam-membership' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( $this->complete_export_member_url( $member ) ); ?>"><?php esc_html_e( 'Exportar ZIP', 'adam-membership' ); ?></a>
							<?php if ( $show_actions ) : ?>
								<?php $this->render_inline_action_form( $member, self::ACTION_APPROVE, __( 'Aprovar', 'adam-membership' ), 'button-primary' ); ?>
								<?php $this->render_inline_rejection_form( $member ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $show_actions ) : ?>
						<tr class="adam-admin-documents-row">
							<td colspan="10">
								<?php $this->render_document_warning_panel( $this->approval_service->missing_registration_documents( $member ), __( 'Documentos obrigatórios em falta antes da aprovação.', 'adam-membership' ) ); ?>
								<?php $this->render_documents_panel( __( 'Documentos submetidos', 'adam-membership' ), $this->member_document_rows( $member, false ) ); ?>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a single member detail page.
	 *
	 * @param Member $member Member.
	 */
	private function render_registration_correction_review( Member $member ): void {
		if ( 'correction_submitted' !== (string) $member->field( 'adam_correction_status' ) ) { return; }
		$history = is_array( $member->field( 'adam_correction_history' ) ) ? $member->field( 'adam_correction_history' ) : array();
		$round = null;
		foreach ( array_reverse( $history ) as $candidate ) { if ( is_array( $candidate ) && 'correction_submitted' === (string) ( $candidate['status'] ?? '' ) ) { $round = $candidate; break; } }
		if ( null === $round ) { return; }
		$values = is_array( $round['values'] ?? null ) ? $round['values'] : array();
		$previous = is_array( $round['previous_values'] ?? null ) ? $round['previous_values'] : array();
		$map = array( 'full_name' => 'nome', 'birth_date' => 'data_nascimento', 'marital_status' => 'estado_civil', 'gender' => 'genero', 'profession' => 'profissao', 'birthplace' => 'naturalidade', 'nationality' => 'nacionalidade', 'email' => 'email', 'phone' => 'telefone', 'telephone' => 'telefone_fixo', 'address_line_1' => 'morada', 'address_line_2' => 'morada_linha_2', 'postcode' => 'codigo_postal', 'city' => 'cidade', 'municipality' => 'municipio', 'country' => 'pais', 'citizen_card' => 'cartao_cidadao', 'document_expiry_date' => 'documento_validade', 'document_issuing_place' => 'documento_local_emissao', 'nif' => 'nif', 'team' => 'equipa', 'profile_photo' => 'profile_photo', 'payment_receipt' => 'payment_receipt', 'external_association_proof' => 'adam_external_association_proof' );
		?><div class="adam-admin-panel adam-card adam-registration-correction-review"><h2>CORREÇÃO RECEBIDA</h2><p><?php echo esc_html( (string) ( $round['submitted_at'] ?? '' ) ); ?></p><table class="widefat striped"><thead><tr><th>Campo</th><th>Valor anterior</th><th>Valor corrigido</th></tr></thead><tbody><?php foreach ( (array) ( $round['fields'] ?? array() ) as $field ) : $old = $previous[ $field ] ?? ''; $new = $values[ $map[ $field ] ?? ( 'adam_custom_' . sanitize_key( (string) $field ) ) ] ?? $values[ $field ] ?? ''; $is_file = in_array( $field, array( 'profile_photo', 'payment_receipt', 'external_association_proof' ), true ) || ( is_string( $new ) && str_starts_with( $new, 'private:' ) ); ?><tr><td><?php echo esc_html( DisplayLabels::field( (string) $field ) ); ?></td><td><?php echo esc_html( is_scalar( $old ) ? (string) $old : '—' ); ?></td><td><?php if ( $is_file ) : ?><?php $url = $this->media_reference_url( $new ); ?><?php if ( $url ) : ?><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">Abrir documento</a><?php else : ?>—<?php endif; ?><?php else : ?><?php echo esc_html( is_scalar( $new ) ? (string) $new : '—' ); ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php
	}

	private function render_member_detail( Member $member ): void {
		$document_rows      = $this->member_document_rows( $member, true );
		$document_warnings  = $this->approval_service->missing_registration_documents( $member );
		?>
		<div class="adam-admin-member-layout">
			<div class="adam-admin-panel adam-card">
				<div class="adam-admin-member-heading">
					<?php $this->render_profile_photo( $member ); ?>
					<div>
						<h2><?php echo esc_html( $member->full_name() ); ?></h2>
						<p><?php echo esc_html( $member->email() ); ?></p>
					</div>
				</div>

				<?php $this->render_admin_safety_notice( $member ); ?>
				<?php $this->render_member_status_consistency_notice( $member ); ?>
				<?php $this->render_registration_correction_review( $member ); ?>

				<div class="adam-admin-detail-grid">
					<?php $this->render_detail_item( __( 'APD / Associação', 'adam-membership' ), (string) $member->field( 'adam_external_association_name' ) ); ?>
					<?php $this->render_detail_item( __( 'N.º de sócio APD', 'adam-membership' ), (string) $member->field( 'adam_external_member_number' ) ); ?>
					<?php $this->render_detail_item( __( 'Estado da inscrição', 'adam-membership' ), DisplayLabels::status( (string) $member->effective_status() ) ); ?>
					<?php $this->render_detail_item( __( 'Estado guardado', 'adam-membership' ), DisplayLabels::status( (string) $member->status() ) ); ?>
					<?php $this->render_detail_item( __( 'N.º de sócio', 'adam-membership' ), $this->member_number_label( $member ) ); ?>
					<?php $this->render_detail_item( __( 'Membro Fundador', 'adam-membership' ), $member->is_founder() ? __( 'Sim', 'adam-membership' ) : __( 'Não', 'adam-membership' ) ); ?>
					<?php $this->render_detail_item( __( 'N.º Fundador', 'adam-membership' ), $member->is_founder() ? (string) $member->founder_number() : '—' ); ?>
					<?php $this->render_detail_item( __( 'Quota válida até', 'adam-membership' ), $this->format_date( $member->field( 'validade_quota' ) ) ); ?>
					<?php $this->render_detail_item( __( 'Data de adesão', 'adam-membership' ), $this->format_date( $member->field( 'data_adesao' ) ) ); ?>
					<?php $this->render_detail_item( __( 'Telemóvel', 'adam-membership' ), (string) $member->field( 'telefone' ) ); ?>
					<?php $this->render_detail_item( __( 'Telefone', 'adam-membership' ), (string) $member->field( 'telefone_fixo' ) ); ?>
					<?php $this->render_detail_item( __( 'Equipa', 'adam-membership' ), (string) $member->field( 'equipa' ) ); ?>
					<?php $this->render_detail_item( __( 'NIF', 'adam-membership' ), (string) $member->field( 'nif' ) ); ?>
					<?php $this->render_detail_item( __( 'BI / Cartão de Cidadão', 'adam-membership' ), (string) $member->field( 'cartao_cidadao' ) ); ?>
					<?php $this->render_detail_item( __( 'Validade do documento', 'adam-membership' ), $this->format_date( $member->field( 'documento_validade' ) ) ); ?>
					<?php $this->render_detail_item( __( 'Local de emissão', 'adam-membership' ), (string) $member->field( 'documento_local_emissao' ) ); ?>
					<?php $this->render_detail_item( __( 'Data de nascimento', 'adam-membership' ), $this->format_date( $member->field( 'data_nascimento' ) ) ); ?>
					<?php $this->render_detail_item( __( 'Estado civil', 'adam-membership' ), (string) $member->field( 'estado_civil' ) ); ?>
					<?php $this->render_detail_item( __( 'Género', 'adam-membership' ), (string) $member->field( 'genero' ) ); ?>
					<?php $this->render_detail_item( __( 'Profissão', 'adam-membership' ), (string) $member->field( 'profissao' ) ); ?>
					<?php $this->render_detail_item( __( 'Naturalidade', 'adam-membership' ), (string) $member->field( 'naturalidade' ) ); ?>
					<?php $this->render_detail_item( __( 'Nacionalidade', 'adam-membership' ), (string) $member->field( 'nacionalidade' ) ); ?>
					<?php $this->render_detail_item( __( 'Morada completa', 'adam-membership' ), (string) $member->field( 'morada' ) ); ?>
					<?php $this->render_detail_item( __( 'Complemento de morada', 'adam-membership' ), (string) $member->field( 'morada_linha_2' ) ); ?>
					<?php $this->render_detail_item( __( 'Código postal', 'adam-membership' ), (string) $member->field( 'codigo_postal' ) ); ?>
					<?php $this->render_detail_item( __( 'Localidade', 'adam-membership' ), (string) $member->field( 'cidade' ) ); ?>
					<?php $this->render_detail_item( __( 'Município', 'adam-membership' ), (string) $member->field( 'municipio' ) ); ?>
					<?php $this->render_detail_item( __( 'País', 'adam-membership' ), (string) $member->field( 'pais' ) ); ?>
					<?php $this->render_detail_item( __( 'Motivo de rejeição', 'adam-membership' ), (string) $member->field( 'motivo_rejeicao' ) ); ?>
					<?php $this->render_detail_item( __( 'Nota privada de rejeição', 'adam-membership' ), (string) $member->field( 'nota_rejeicao_admin' ) ); ?>
				</div>
			</div>

			<?php $this->render_document_warning_panel( $document_warnings, __( 'Existem documentos obrigatórios em falta para aprovar este sócio.', 'adam-membership' ) ); ?>
			<?php $this->render_documents_panel( __( 'Documentos submetidos', 'adam-membership' ), $document_rows, $member, null, true ); ?>
			<div class="adam-admin-financial-document-stack">
				<?php $this->render_private_document_panel( 'registration', $member->user_id(), (string) get_user_meta( $member->user_id(), 'adam_membership_registration_request_uuid', true ) ?: 'registration:legacy-' . $member->user_id(), $this->member_url( $member ) ); ?>
				<div class="adam-admin-panel adam-card"><a class="button" href="<?php echo esc_url( $this->member_document_history_url( $member ) ); ?>"><?php esc_html_e( 'Ver histórico de documentos', 'adam-membership' ); ?></a></div>
				<?php $this->render_current_financial_movement_panel( $member ); ?>
			</div>

			<?php $this->render_member_edit_form( $member ); ?>

			<div class="adam-admin-panel adam-card">
				<h2><?php esc_html_e( 'Ações de administração', 'adam-membership' ); ?></h2>
				<div class="adam-admin-action-stack">
					<?php $this->render_action_form( $member, self::ACTION_APPROVE, __( 'Aprovar sócio', 'adam-membership' ), 'button-primary' ); ?>
					<?php $this->render_rejection_form( $member ); ?>
					<?php if ( in_array( $member->status(), array( Member::STATUS_PENDING, Member::STATUS_REJECTED ), true ) ) : ?><?php $this->render_registration_correction_selector( $member ); ?><?php endif; ?>
					<?php $this->render_action_form( $member, self::ACTION_RENEW, __( 'Renovar quota por um ano', 'adam-membership' ), 'button-secondary' ); ?>
					<?php $this->render_action_form( $member, self::ACTION_RESEND_EMAIL, __( 'Reenviar email de aprovação', 'adam-membership' ), 'button-secondary' ); ?>
					<?php $this->render_action_form( $member, self::ACTION_REGENERATE_CARD_TOKEN, __( 'Regenerar token de validação do cartão', 'adam-membership' ), 'button-secondary' ); ?>
				<?php if ( 'adam_primary' === (string) $member->field( 'adam_membership_origin' ) && '' === (string) $member->field( 'adam_apd_ana_confirmation_date' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adam_membership_member_action"><input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_CONFIRM_ANA ); ?>"><input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>"><?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?><label>Data de confirmação ANA <input type="date" name="ana_confirmation_date" required></label><button class="button button-primary">Confirmar ANA e aprovar</button></form><?php endif; ?>
				<?php if ( Member::APD_MANAGED === (string) $member->field( 'adam_apd_management_status' ) || 'ANA' === (string) $member->field( 'adam_external_association_name' ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('Tem a certeza de que pretende remover a associação ANA deste sócio?');"><input type="hidden" name="action" value="adam_membership_member_action"><input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REMOVE_ANA ); ?>"><input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>"><?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?><button class="button">Remover ANA</button></form><?php endif; ?>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-quota-form">
					<input type="hidden" name="action" value="adam_membership_member_action">
					<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_CHANGE_QUOTA ); ?>">
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
					<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
					<label for="adam_quota_validity"><?php esc_html_e( 'Alterar validade da quota', 'adam-membership' ); ?></label>
					<input type="date" id="adam_quota_validity" name="quota_validity" value="<?php echo esc_attr( $this->date_input_value( $member->field( 'validade_quota' ) ) ); ?>">
					<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Guardar validade', 'adam-membership' ); ?></button>
				</form>

				<div class="adam-admin-safe-view">
					<h3><?php esc_html_e( 'Ver como sócio', 'adam-membership' ); ?></h3>
					<?php if ( get_current_user_id() === $member->user_id() ) : ?>
						<a class="button" href="<?php echo esc_url( ManagedPages::url( 'member_area' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir Área do Sócio', 'adam-membership' ); ?></a>
					<?php else : ?>
						<p><?php esc_html_e( 'A impersonação não está ativa por motivos de segurança. Utilize o perfil de utilizador do WordPress para rever a conta.', 'adam-membership' ); ?></p>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( get_edit_user_link( $member->user_id() ) ); ?>"><?php esc_html_e( 'Abrir perfil do WordPress', 'adam-membership' ); ?></a>
				</div>
			</div>

			<?php $this->render_member_deletion_panel( $member ); ?>

			<div class="adam-admin-panel adam-admin-history-panel adam-card">
				<h2><?php esc_html_e( 'Histórico do Sócio', 'adam-membership' ); ?></h2>
				<p><?php esc_html_e( 'Esta cronologia apresenta os principais eventos de inscrição, conta e administração deste sócio.', 'adam-membership' ); ?></p>
				<?php
				$this->render_history_timeline(
					$this->history_repository->for_member( $member->user_id(), 20 ),
					$member
				);
				?>
				<p class="adam-admin-history-link">
					<a class="button" href="<?php echo esc_url( $this->history_url( array( 'member_id' => (string) $member->user_id() ) ) ); ?>"><?php esc_html_e( 'Ver histórico completo', 'adam-membership' ); ?></a>
				</p>
			</div>

			<?php $this->render_member_diagnostics( $member ); ?>
		</div>
		<?php
	}

	/** Render editable payment data and the manual retry action. */
	private function render_apd_google_sheets_panel( Member $member, ApdAssociationRequest $request ): void {
		if ( ApdAssociationRequest::STATUS_CONFIRMED !== $request->status() ) { return; }
		$sync = (array) ( $request->data()['google_sheets_sync'] ?? array() );
		$quota_type = $request->quota_type();
		$request_id = $request->request_uuid();
		if ( '' !== $request_id && $this->financial_movements->is_suppressed( $request_id ) ) {
			echo '<div class="adam-admin-panel adam-card adam-google-sheets-payment-panel"><h2>Google Sheets — movimento financeiro</h2><p>Estado: Eliminado do histórico financeiro</p><p>ID: ' . esc_html( $request_id ) . '</p><p>O movimento eliminado não será recriado a partir dos dados legados. O pedido APD permanece intacto.</p></div>';
			return;
		}
		echo '<div class="adam-admin-panel adam-card adam-google-sheets-payment-panel"><h2>Google Sheets — movimento financeiro</h2><p>Estado: ' . esc_html( (string) ( $sync['state'] ?? 'pending' ) ) . '</p><p>Tipo de quota: ' . esc_html( '' !== $quota_type ? $quota_type : 'Não resolvido' ) . '</p><p>ID: ' . esc_html( '' !== $request_id ? $request_id : 'Não resolvido' ) . '</p><div class="adam-google-sheets-payment-actions"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adam_membership_retry_google_sheets_apd_' . $request->id() );
		echo '<input type="hidden" name="action" value="adam_membership_retry_google_sheets"><input type="hidden" name="sync_type" value="apd"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request->id() ) . '"><button type="submit" class="button">Repetir sincronização</button></form></div></div>';
	}

	/** Render the established field-picker interaction for a renewal correction. */
	private function render_renewal_correction_selector( RenewalRequest $request ): void {
		$definitions = CorrectionFieldCatalog::definitions( $this->settings->membership_form_settings() );
		$fields = array_keys( $definitions );
		$submitted_fields = array_map( array( CorrectionFieldCatalog::class, 'canonical_key' ), array_keys( $request->submitted_data() ) );
		$fields = array_merge( $fields, array_values( array_intersect( $submitted_fields, array_keys( CorrectionFieldCatalog::labels() ) ) ) );
		if ( '' !== (string) $request->proof_of_payment() ) { $fields[] = 'payment_receipt'; }
		$fields = array_values( array_unique( array_map( 'sanitize_key', $fields ) ) );
		if ( array() === $fields ) { return; }
		$labels = array_merge( CorrectionFieldCatalog::labels(), array( 'payment_receipt' => 'Comprovativo de pagamento', 'adam_membership_origin' => 'Tipo de renovação', 'team_id' => 'Equipa' ) );
		?>
		<div class="adam-admin-rejection-form adam-admin-correction-form" data-adam-correction-selector>
			<h3><?php esc_html_e( 'Pedir correção', 'adam-membership' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_renewal_action"><input type="hidden" name="renewal_action" value="<?php echo esc_attr( self::ACTION_REQUEST_RENEWAL_CORRECTION ); ?>"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>"><input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->renewal_url( $request ) ); ?>"><?php wp_nonce_field( 'adam_membership_renewal_action_' . $request->id() ); ?>
				<label><span><?php esc_html_e( 'Motivo da correção', 'adam-membership' ); ?></span><select name="correction_reason" required><option value="">Selecionar</option><?php foreach ( $this->correction_reasons() as $reason ) : ?><option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option><?php endforeach; ?></select></label>
				<div class="adam-correction-field-picker"><span>Campos a corrigir</span><button type="button" class="button adam-correction-field-picker__trigger" data-adam-correction-open>Selecionar campos...</button><div class="adam-correction-field-picker__summary" data-adam-correction-summary hidden><strong data-adam-correction-count></strong><div data-adam-correction-chips></div><button type="button" class="button-link" data-adam-correction-open>Alterar seleção</button></div></div>
				<dialog class="adam-admin-correction-dialog" data-adam-correction-dialog><div class="adam-admin-correction-dialog__header"><h2>Campos a corrigir</h2><button type="button" class="button-link" data-adam-correction-close aria-label="Fechar">&times;</button></div><p>Selecione apenas a informação ou o documento que deve ser corrigido.</p><div class="adam-admin-correction-dialog__groups"><?php foreach ( CorrectionFieldCatalog::groups() as $group_label => $group_fields ) : ?><fieldset><legend><?php echo esc_html( $group_label ); ?></legend><?php foreach ( $group_fields as $field ) : if ( ! in_array( $field, $fields, true ) ) { continue; } ?><label class="adam-admin-correction-option"><input type="checkbox" name="correction_fields[]" value="<?php echo esc_attr( $field ); ?>" data-adam-correction-option data-label="<?php echo esc_attr( $labels[ $field ] ?? CorrectionFieldCatalog::label( $field ) ); ?>"><span><?php echo esc_html( $labels[ $field ] ?? CorrectionFieldCatalog::label( $field ) ); ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?><fieldset><legend>Pedido de renovação</legend><?php foreach ( array_diff( $fields, array_keys( $definitions ) ) as $field ) : ?><label class="adam-admin-correction-option"><input type="checkbox" name="correction_fields[]" value="<?php echo esc_attr( $field ); ?>" data-adam-correction-option data-label="<?php echo esc_attr( $labels[ $field ] ?? CorrectionFieldCatalog::label( $field ) ); ?>"><span><?php echo esc_html( $labels[ $field ] ?? CorrectionFieldCatalog::label( $field ) ); ?></span></label><?php endforeach; ?></fieldset></div><div class="adam-admin-correction-dialog__actions"><button type="button" class="button" data-adam-correction-close>Cancelar</button><button type="button" class="button button-primary" data-adam-correction-apply>Aplicar seleção</button></div></dialog>
				<label><span>O que precisa de corrigir</span><textarea name="correction_note" rows="4"></textarea></label><button type="submit" class="button button-primary adam-button">Pedir correção</button>
			</form>
		</div>
		<?php
	}

	private function render_google_sheets_payment_panel( Member $member, ?RenewalRequest $request = null ): void {
		$type = null === $request ? 'registration' : 'renewal';
		$id = null === $request ? $member->user_id() : $request->id();
		$data = null === $request ? array( 'membership_year' => get_user_meta( $member->user_id(), 'adam_membership_year', true ), 'payment_amount' => get_user_meta( $member->user_id(), 'adam_membership_payment_amount', true ), 'payment_date' => get_user_meta( $member->user_id(), 'adam_membership_payment_date', true ), 'payment_method' => get_user_meta( $member->user_id(), 'adam_membership_payment_method', true ) ) : $request->data();
		$sync = null === $request ? get_user_meta( $member->user_id(), 'adam_membership_google_sheets_sync', true ) : ( $data['google_sheets_sync'] ?? array() );
		$sync = is_array( $sync ) ? $sync : array();
		$sync_labels = array( 'pending' => 'Pendente', 'synchronized' => 'Sincronizado', 'failed' => 'Falhou', 'inactive' => 'Não ativa — sincronização não necessária' );
		$sync_state = (string) ( $sync['state'] ?? 'pending' );
		$quota_type = $this->google_sheets_quota_type( $member, $request );
		$request_id = null === $request ? (string) get_user_meta( $member->user_id(), 'adam_membership_registration_request_uuid', true ) : $request->request_uuid();
		if ( '' !== $request_id && $this->financial_movements->is_suppressed( $request_id ) ) {
			echo '<div class="adam-admin-panel adam-card adam-google-sheets-payment-panel"><h2>Google Sheets — movimento financeiro</h2><p>Estado: Eliminado do histórico financeiro</p><p>ID: ' . esc_html( $request_id ) . '</p><p>O movimento eliminado não será recriado a partir dos dados legados. Os dados do pedido/membro permanecem intactos.</p></div>';
			return;
		}
		$persisted_movement = '' !== $request_id ? $this->financial_movements->find( $request_id ) : null;
		if ( null !== $persisted_movement ) {
			$this->financial_save_trace( 'panel_load_render', $persisted_movement->movement_id(), $persisted_movement->membership_year(), $persisted_movement->payment_method(), $persisted_movement->amount(), $persisted_movement->payment_date(), $persisted_movement->financial_status(), 'AdminController::render_google_sheets_payment_panel' );
		}
		if ( null !== $persisted_movement ) {
			$data['membership_year'] = $persisted_movement->membership_year();
			$data['payment_amount'] = $persisted_movement->amount();
			$data['payment_date'] = $persisted_movement->payment_date();
			$data['payment_method'] = $persisted_movement->payment_method();
			$quota_type = $persisted_movement->quota_type();
		}
		$financial_labels = array( 'paid' => 'Pago', 'pending' => 'Pendente', 'failed' => 'Falhou' );
		?>
		<div class="adam-admin-panel adam-card adam-google-sheets-payment-panel">
			<h2>Google Sheets — movimento financeiro</h2>
			<p>Estado: <?php echo esc_html( null !== $persisted_movement ? ( $financial_labels[ $persisted_movement->financial_status() ] ?? $persisted_movement->financial_status() ) : 'Dados de pagamento por preencher' ); ?></p>
			<p>Google Sheets: <?php echo esc_html( null !== $persisted_movement ? ( $sync_labels[ $persisted_movement->google_state() ] ?? $persisted_movement->google_state() ) : ( $sync_labels[ $sync_state ] ?? $sync_state ) ); ?></p>
			<p>Tipo de quota: <?php echo esc_html( $quota_type ); ?></p>
			<p>ID: <?php echo esc_html( '' !== $request_id ? $request_id : 'Não resolvido' ); ?></p>
			<?php if ( ! empty( $sync['missing_fields'] ) && is_array( $sync['missing_fields'] ) ) : ?><p><strong>Dados em falta:</strong> <?php echo esc_html( implode( ', ', array_map( 'strval', $sync['missing_fields'] ) ) ); ?></p><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-google-sheets-payment-form">
				<input type="hidden" name="action" value="adam_membership_save_google_sheets_payment"><input type="hidden" name="sync_type" value="<?php echo esc_attr( $type ); ?>"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $id ); ?>"><input type="hidden" name="redirect_to" value="<?php echo esc_url( null === $request ? $this->member_url( $member ) : $this->renewal_url( $request ) ); ?>"><?php wp_nonce_field( 'adam_membership_save_google_sheets_payment_' . $type . '_' . $id ); ?>
				<div class="adam-google-sheets-quota-field"><label>Tipo de quota <select name="quota_type" required><?php foreach ( array( 'Inscrição ADAM', 'Inscrição ADAM/ANA', 'Renovação ADAM', 'Renovação ADAM/ANA', 'Associar APD/ANA' ) as $option ) : ?><option value="<?php echo esc_attr( $option ); ?>" <?php selected( $option, $quota_type ); ?>><?php echo esc_html( $option ); ?></option><?php endforeach; ?></select></label><p class="description">Selecionar outro tipo cria um novo movimento manual e preserva este registo histórico.</p></div>
				<div class="adam-google-sheets-payment-fields">
					<label>Ano <input type="number" name="membership_year" min="2000" max="2100" required value="<?php echo esc_attr( (string) ( $data['membership_year'] ?? '' ) ); ?>"></label>
					<label>Valor pago <input type="number" name="payment_amount" min="0.01" step="0.01" required value="<?php echo esc_attr( (string) ( $data['payment_amount'] ?? '' ) ); ?>"></label>
					<label>Data de pagamento <input type="date" name="payment_date" required value="<?php echo esc_attr( (string) ( $data['payment_date'] ?? '' ) ); ?>"></label>
					<label>Método <select name="payment_method" required><option value="">Selecionar</option><?php foreach ( GoogleSheetsSyncService::PAYMENT_METHODS as $method ) : ?><option value="<?php echo esc_attr( $method ); ?>" <?php selected( $method, (string) ( $data['payment_method'] ?? '' ) ); ?>><?php echo esc_html( $method ); ?></option><?php endforeach; ?></select></label>
				</div>
				<button type="submit" class="button button-primary">Guardar dados de pagamento</button>
			</form>
			<?php if ( null !== $persisted_movement || in_array( $sync_state, array( 'pending', 'failed' ), true ) || ( null === $request && Member::STATUS_ACTIVE === $member->status() ) || ( null !== $request && RenewalRequest::STATUS_APPROVED === $request->status() ) ) : ?>
			<div class="adam-google-sheets-payment-actions"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-actions"><input type="hidden" name="action" value="adam_membership_retry_google_sheets"><input type="hidden" name="sync_type" value="<?php echo esc_attr( $type ); ?>"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $id ); ?>"><input type="hidden" name="redirect_to" value="<?php echo esc_url( null === $request ? $this->member_url( $member ) : $this->renewal_url( $request ) ); ?>"><?php wp_nonce_field( 'adam_membership_retry_google_sheets_' . $type . '_' . $id ); ?><button type="submit" class="button">Repetir sincronização</button></form></div>
			<?php endif; ?>
			<?php if ( null === $request ) : ?><p class="adam-google-sheets-history-link"><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::HISTORY_PAGE_SLUG, 'member_id' => $member->user_id() ), admin_url( 'admin.php' ) ) ); ?>">Ver histórico financeiro</a></p><?php endif; ?>
		</div>
		<?php
	}

	private function google_sheets_quota_type( Member $member, ?RenewalRequest $request = null ): string {
		if ( null === $request ) {
			$origin = (string) $member->field( 'adam_membership_origin' );
			return array( 'adam_primary' => 'Inscrição ADAM/ANA', 'external_association' => 'Inscrição ADAM' )[ $origin ] ?? 'Não resolvido';
		}
		$data = $request->data();
		$origin = (string) ( $data['submitted_data']['adam_membership_origin'] ?? '' );
		return array( 'adam_primary' => 'Renovação ADAM/ANA', 'external_association' => 'Renovação ADAM' )[ $origin ] ?? 'Não resolvido';
	}

	private function render_current_financial_movement_panel( Member $member ): void {
		$movement = $this->financial_movements->latest_for_member( $member->user_id() );
		if ( null !== $movement ) {
			$this->financial_save_trace( 'panel_load_render', $movement->movement_id(), $movement->membership_year(), $movement->payment_method(), $movement->amount(), $movement->payment_date(), $movement->financial_status(), 'AdminController::render_current_financial_movement_panel' );
		}
		if ( null === $movement ) {
			$this->render_google_sheets_payment_panel( $member );
			return;
		}
		$financial_labels = array( 'paid' => 'Pago', 'pending' => 'Pendente', 'failed' => 'Falhou' );
		$google_labels = array( 'pending' => 'Pendente', 'synchronized' => 'Sincronizado', 'failed' => 'Falhou', 'inactive' => 'Não ativa — sincronização não necessária' );
		$id = $movement->movement_id();
		echo '<div class="adam-admin-panel adam-card adam-google-sheets-payment-panel"><h2>Google Sheets — movimento financeiro</h2><p>Estado: ' . esc_html( $financial_labels[ $movement->financial_status() ] ?? $movement->financial_status() ) . '</p><p>Google Sheets: ' . esc_html( $google_labels[ $movement->google_state() ] ?? $movement->google_state() ) . '</p><p>Tipo de quota: ' . esc_html( $movement->quota_type() ?: 'Não resolvido' ) . '</p><p>ID: ' . esc_html( $id ?: 'Não resolvido' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="adam-google-sheets-payment-form">';
		wp_nonce_field( 'adam_membership_save_google_sheets_payment_manual_' . $id );
		echo '<input type="hidden" name="action" value="adam_membership_save_google_sheets_payment"><input type="hidden" name="sync_type" value="manual"><input type="hidden" name="request_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="redirect_to" value="' . esc_url( $this->member_url( $member ) ) . '"><div class="adam-google-sheets-quota-field"><label>Tipo de quota <select name="quota_type" required>';
		foreach ( array( 'Inscrição ADAM', 'Inscrição ADAM/ANA', 'Renovação ADAM', 'Renovação ADAM/ANA', 'Associar APD/ANA' ) as $option ) { echo '<option value="' . esc_attr( $option ) . '"' . selected( $option, $movement->quota_type(), false ) . '>' . esc_html( $option ) . '</option>'; }
		echo '</select></label></div><div class="adam-google-sheets-payment-fields"><label>Ano <input type="number" name="membership_year" min="2000" max="2100" required value="' . esc_attr( (string) $movement->membership_year() ) . '"></label><label>Valor pago <input type="number" name="payment_amount" min="0.01" step="0.01" required value="' . esc_attr( $movement->amount() ) . '"></label><label>Data de pagamento <input type="date" name="payment_date" required value="' . esc_attr( $movement->payment_date() ) . '"></label><label>Método <select name="payment_method" required><option value="">Selecionar</option>';
		foreach ( GoogleSheetsSyncService::PAYMENT_METHODS as $method ) { echo '<option value="' . esc_attr( $method ) . '"' . selected( $method, $movement->payment_method(), false ) . '>' . esc_html( $method ) . '</option>'; }
		echo '</select></label></div><button type="submit" class="button button-primary">Guardar dados de pagamento</button></form><div class="adam-google-sheets-payment-actions"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'adam_membership_retry_google_sheets_manual_' . $id );
		echo '<input type="hidden" name="action" value="adam_membership_retry_google_sheets"><input type="hidden" name="sync_type" value="manual"><input type="hidden" name="request_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="redirect_to" value="' . esc_url( $this->member_url( $member ) ) . '"><button type="submit" class="button">Repetir sincronização</button></form></div><p class="adam-google-sheets-history-link"><a href="' . esc_url( add_query_arg( array( 'page' => self::HISTORY_PAGE_SLUG, 'member_id' => $member->user_id() ), admin_url( 'admin.php' ) ) ) . '">Ver histórico financeiro</a></p></div>';
	}

	private function create_manual_financial_movement( Member $member, string $quota_type, int $year, string $amount, string $date, string $method ): FinancialMovement|\WP_Error {
		$movement = $this->financial_movements->create_manual( $member, array( 'quota_type' => $quota_type, 'membership_year' => $year, 'amount' => $amount, 'payment_date' => $date, 'payment_method' => $method ) );
		if ( is_wp_error( $movement ) ) { return $movement; }
		return $movement;
	}

	/** @param string $stage Trace stage. */
	private function financial_save_trace( string $stage, string $movement_id, int $year, string $method, string $amount, string $date, string $status, string $handler ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) { return; }
		$this->financial_save_trace_identifiers( $stage, '', $movement_id, 0, '' , $year, $method, $amount, $date, $status, $handler );
	}

	private function financial_save_trace_identifiers( string $stage, string $request_id, string $canonical_movement_id, int $member_id, string $source_type, int $year, string $method, string $amount, string $date, string $status, string $handler ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) { return; }
		error_log( '[ADAM Membership] financial_save_trace ' . wp_json_encode( array( 'stage' => $stage, 'handler' => $handler, 'request_id' => $request_id, 'canonical_movement_id' => $canonical_movement_id, 'member_id' => $member_id, 'source_type' => $source_type, 'membership_year' => $year, 'payment_method' => $method, 'amount' => $amount, 'payment_date' => $date, 'financial_status' => $status ) ) );
	}

	/**
	 * Persist payment data on the existing movement and verify the read-back.
	 *
	 * @param FinancialMovement $movement Existing movement.
	 * @param array<string, mixed> $financial Validated payment data.
	 */
	private function update_financial_movement_payment( FinancialMovement $movement, array $financial ): FinancialMovement|\WP_Error {
		$this->financial_save_trace( 'before_repository_update', $movement->movement_id(), absint( $financial['membership_year'] ?? 0 ), (string) ( $financial['payment_method'] ?? '' ), (string) ( $financial['amount'] ?? '' ), (string) ( $financial['payment_date'] ?? '' ), (string) ( $financial['financial_status'] ?? '' ), 'AdminController::update_financial_movement_payment' );
		if ( ! $this->financial_movements->update( $movement, $financial ) ) {
			return new \WP_Error( 'adam_financial_movement_store_failed', 'Não foi possível guardar os dados do movimento financeiro.' );
		}
		$this->financial_save_trace( 'after_repository_update', $movement->movement_id(), absint( $financial['membership_year'] ?? 0 ), (string) ( $financial['payment_method'] ?? '' ), (string) ( $financial['amount'] ?? '' ), (string) ( $financial['payment_date'] ?? '' ), (string) ( $financial['financial_status'] ?? '' ), 'AdminController::update_financial_movement_payment' );

		$updated = $this->financial_movements->find( $movement->movement_id() );
		if ( null !== $updated ) {
			$this->financial_save_trace( 'fresh_repository_readback', $updated->movement_id(), $updated->membership_year(), $updated->payment_method(), $updated->amount(), $updated->payment_date(), $updated->financial_status(), 'AdminController::update_financial_movement_payment' );
		} else {
			$this->financial_save_trace( 'fresh_repository_readback_missing', $movement->movement_id(), absint( $financial['membership_year'] ?? 0 ), (string) ( $financial['payment_method'] ?? '' ), (string) ( $financial['amount'] ?? '' ), (string) ( $financial['payment_date'] ?? '' ), (string) ( $financial['financial_status'] ?? '' ), 'AdminController::update_financial_movement_payment' );
		}
		if (
			null === $updated
			|| $updated->membership_year() !== absint( $financial['membership_year'] ?? 0 )
			|| number_format( (float) $updated->amount(), 2, '.', '' ) !== number_format( (float) ( $financial['amount'] ?? 0 ), 2, '.', '' )
			|| $updated->payment_date() !== (string) ( $financial['payment_date'] ?? '' )
			|| $updated->payment_method() !== (string) ( $financial['payment_method'] ?? '' )
			|| ( 'paid' === (string) ( $financial['financial_status'] ?? '' ) && 'paid' !== $updated->financial_status() )
		) {
			return new \WP_Error( 'adam_financial_movement_store_failed', 'Os dados do movimento não puderam ser confirmados após a gravação.' );
		}

		return $updated;
	}

	/**
	 * Render the isolated permanent-deletion danger zone.
	 *
	 * @param Member $member Member.
	 */
	private function render_member_deletion_panel( Member $member ): void {
		$dialog_id  = 'adam-member-delete-dialog-' . $member->user_id();
		$can_delete = current_user_can( 'delete_user', $member->user_id() ) && ( ! is_multisite() || is_super_admin( get_current_user_id() ) );
		?>
		<div class="adam-admin-panel adam-admin-member-danger-zone adam-card" data-adam-member-delete>
			<div>
				<h2><?php esc_html_e( 'Permanent Member Deletion', 'adam-membership' ); ?></h2>
				<p><?php esc_html_e( 'Reserved for exceptional situations such as duplicate records, test accounts, GDPR requests, or accidental registrations.', 'adam-membership' ); ?></p>
			</div>

			<?php if ( get_current_user_id() === $member->user_id() ) : ?>
				<p class="adam-admin-member-danger-zone__blocked"><?php esc_html_e( 'Safety rule: administrators cannot permanently delete their own account.', 'adam-membership' ); ?></p>
			<?php elseif ( ! $can_delete ) : ?>
				<p class="adam-admin-member-danger-zone__blocked"><?php esc_html_e( 'You do not have permission to permanently delete this member.', 'adam-membership' ); ?></p>
			<?php else : ?>
				<button type="button" class="button adam-button adam-button--danger" data-adam-member-delete-open aria-haspopup="dialog" aria-controls="<?php echo esc_attr( $dialog_id ); ?>">
					<?php esc_html_e( 'Delete Member', 'adam-membership' ); ?>
				</button>

				<dialog id="<?php echo esc_attr( $dialog_id ); ?>" class="adam-admin-member-delete-dialog" data-adam-member-delete-dialog aria-labelledby="<?php echo esc_attr( $dialog_id . '-title' ); ?>" aria-describedby="<?php echo esc_attr( $dialog_id . '-warning' ); ?>">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-adam-member-delete-form>
						<input type="hidden" name="action" value="adam_membership_delete_member_permanently">
						<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
						<?php wp_nonce_field( 'adam_membership_permanent_delete_' . $member->user_id() ); ?>

						<div class="adam-admin-member-delete-dialog__header">
							<h2 id="<?php echo esc_attr( $dialog_id . '-title' ); ?>"><?php esc_html_e( 'Permanently Delete Member?', 'adam-membership' ); ?></h2>
							<button type="button" class="adam-admin-member-delete-dialog__close" data-adam-member-delete-close aria-label="<?php esc_attr_e( 'Close', 'adam-membership' ); ?>">&times;</button>
						</div>

						<div class="adam-admin-member-delete-dialog__body" id="<?php echo esc_attr( $dialog_id . '-warning' ); ?>">
							<p><strong><?php esc_html_e( 'This action is permanent and cannot be undone.', 'adam-membership' ); ?></strong></p>
							<p><?php esc_html_e( 'The member record and all associated data will be permanently removed from the ADAM system.', 'adam-membership' ); ?></p>
							<p><?php esc_html_e( 'This includes membership information, profile details, uploaded documents, reward progress, attendance history, points, communication preferences, and any other data linked to this member.', 'adam-membership' ); ?></p>
							<p><?php esc_html_e( 'This action should only be used for duplicate records, test accounts, GDPR data deletion requests, or registrations created by mistake.', 'adam-membership' ); ?></p>

							<label class="adam-admin-member-delete-confirmation">
								<span><?php esc_html_e( 'Type DELETE to confirm', 'adam-membership' ); ?></span>
								<input type="text" name="delete_confirmation" value="" pattern="DELETE" required autocomplete="off" autocapitalize="characters" spellcheck="false" data-adam-member-delete-confirmation>
							</label>
						</div>

						<div class="adam-admin-member-delete-dialog__actions">
							<button type="button" class="button" data-adam-member-delete-close><?php esc_html_e( 'Cancel', 'adam-membership' ); ?></button>
							<button type="submit" class="button adam-button adam-button--danger" disabled data-adam-member-delete-submit><?php esc_html_e( 'Permanently Delete Member', 'adam-membership' ); ?></button>
						</div>
					</form>
				</dialog>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the editable admin member fields form.
	 *
	 * @param Member $member Member.
	 */
	private function render_member_edit_form( Member $member ): void {
		?>
		<div class="adam-admin-panel adam-admin-edit-panel adam-card">
			<h2><?php esc_html_e( 'Editar campos do sócio', 'adam-membership' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-edit-form">
				<input type="hidden" name="action" value="adam_membership_member_action">
				<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_SAVE_MEMBER ); ?>">
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
				<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>

				<div class="adam-admin-edit-grid">
					<div class="adam-admin-edit-section"><h3><?php esc_html_e( 'Informação pessoal e contacto', 'adam-membership' ); ?></h3><div class="adam-admin-edit-grid">
					<label><span><?php esc_html_e( 'Nome', 'adam-membership' ); ?></span><input type="text" name="member_first_name" value="<?php echo esc_attr( (string) get_user_meta( $member->user_id(), 'first_name', true ) ); ?>"></label><label><span><?php esc_html_e( 'Apelido', 'adam-membership' ); ?></span><input type="text" name="member_last_name" value="<?php echo esc_attr( (string) get_user_meta( $member->user_id(), 'last_name', true ) ); ?>"></label>
					<?php foreach ( array( 'email' => array( 'Email', $member->email() ), 'data_nascimento' => array( 'Data de nascimento', $member->field( 'data_nascimento' ) ), 'genero' => array( 'Género', $member->field( 'genero' ) ), 'estado_civil' => array( 'Estado civil', $member->field( 'estado_civil' ) ), 'nacionalidade' => array( 'Nacionalidade', $member->field( 'nacionalidade' ) ), 'naturalidade' => array( 'Naturalidade', $member->field( 'naturalidade' ) ), 'profissao' => array( 'Profissão', $member->field( 'profissao' ) ), 'telefone_fixo' => array( 'Telefone fixo', $member->field( 'telefone_fixo' ) ), 'morada' => array( 'Morada', $member->field( 'morada' ) ), 'morada_linha_2' => array( 'Morada (linha 2)', $member->field( 'morada_linha_2' ) ), 'codigo_postal' => array( 'Código postal', $member->field( 'codigo_postal' ) ), 'cidade' => array( 'Localidade', $member->field( 'cidade' ) ), 'municipio' => array( 'Município', $member->field( 'municipio' ) ), 'pais' => array( 'País', $member->field( 'pais' ) ), 'nif' => array( 'NIF', $member->field( 'nif' ) ), 'cartao_cidadao' => array( 'BI / Cartão de Cidadão', $member->field( 'cartao_cidadao' ) ), 'documento_validade' => array( 'Data de validade', $member->field( 'documento_validade' ) ), 'documento_local_emissao' => array( 'Local de emissão', $member->field( 'documento_local_emissao' ) ) ) as $key => $item ) : ?><label><span><?php echo esc_html( $item[0] ); ?></span><input type="<?php echo 'data_nascimento' === $key || 'documento_validade' === $key ? 'date' : 'text'; ?>" name="member_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $item[1] ); ?>"></label><?php endforeach; ?>
					</div></div>
					<div class="adam-admin-edit-section"><h3><?php esc_html_e( 'APD atual', 'adam-membership' ); ?></h3><div class="adam-admin-edit-grid"><label><span><?php esc_html_e( 'APD / Associação', 'adam-membership' ); ?></span><input type="text" name="member_adam_external_association_name" value="<?php echo esc_attr( (string) $member->field( 'adam_external_association_name' ) ); ?>"></label><label><span><?php esc_html_e( 'N.º de sócio APD', 'adam-membership' ); ?></span><input type="text" name="member_adam_external_member_number" value="<?php echo esc_attr( (string) $member->field( 'adam_external_member_number' ) ); ?>"></label></div></div>
					<label>
						<span><?php esc_html_e( 'N.º de sócio', 'adam-membership' ); ?></span>
						<input type="text" name="member_number" value="<?php echo esc_attr( (string) $member->field( 'numero_socio' ) ); ?>" placeholder="<?php esc_attr_e( 'Por atribuir', 'adam-membership' ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Data de validade da quota', 'adam-membership' ); ?></span>
						<input type="date" name="quota_validity" value="<?php echo esc_attr( $this->date_input_value( $member->field( 'validade_quota' ) ) ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Data de adesão', 'adam-membership' ); ?></span>
						<input type="date" name="registration_date" value="<?php echo esc_attr( $this->date_input_value( $member->field( 'data_adesao' ) ) ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Telefone', 'adam-membership' ); ?></span>
						<input type="text" name="phone" value="<?php echo esc_attr( (string) $member->field( 'telefone' ) ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Equipa', 'adam-membership' ); ?></span>
						<input type="text" name="team" value="<?php echo esc_attr( (string) $member->field( 'equipa' ) ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
						<select name="status">
							<?php $this->render_select_option( Member::STATUS_PENDING, __( 'Pendente', 'adam-membership' ), $member->status() ); ?>
							<?php $this->render_select_option( Member::STATUS_ACTIVE, __( 'Ativo', 'adam-membership' ), $member->status() ); ?>
							<?php $this->render_select_option( Member::STATUS_RENEWAL_PENDING, __( 'Renovação pendente', 'adam-membership' ), $member->status() ); ?>
							<?php $this->render_select_option( Member::STATUS_EXPIRED, __( 'Expirado', 'adam-membership' ), $member->status() ); ?>
							<?php if ( $member->isRejected() ) : ?>
								<?php $this->render_select_option( Member::STATUS_REJECTED, __( 'Rejeitado', 'adam-membership' ), $member->status() ); ?>
							<?php endif; ?>
						</select>
					</label>

					<label>
						<span><?php esc_html_e( 'Membro Fundador', 'adam-membership' ); ?></span>
						<select name="founder_status">
							<?php $this->render_select_option( '0', __( 'Não', 'adam-membership' ), $member->is_founder() ? '1' : '0' ); ?>
							<?php $this->render_select_option( '1', __( 'Sim', 'adam-membership' ), $member->is_founder() ? '1' : '0' ); ?>
						</select>
					</label>

				<label>
					<span><?php esc_html_e( 'N.º Fundador', 'adam-membership' ); ?></span>
					<input type="number" min="0" name="founder_number" value="<?php echo esc_attr( (string) $member->founder_number() ); ?>" placeholder="<?php esc_attr_e( 'Atribuição automática', 'adam-membership' ); ?>">
				</label>

				<?php
				$card_presentation = $this->cards->card_presentation( $member );
				$cosmetic_options  = $this->cards->member_cosmetic_options( $member );
				?>

				<label>
					<span><?php esc_html_e( 'Título ativo', 'adam-membership' ); ?></span>
					<select name="active_title_reward">
						<option value=""><?php esc_html_e( 'Sem título especial', 'adam-membership' ); ?></option>
						<?php foreach ( $cosmetic_options['titles'] ?? array() as $cosmetic ) : ?>
							<?php $this->render_member_cosmetic_option( $cosmetic, (string) ( $card_presentation['selected_values']['title'] ?? '' ) ); ?>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Fundo do cartão', 'adam-membership' ); ?></span>
					<select name="active_card_theme">
						<option value=""><?php esc_html_e( 'Design ADAM predefinido', 'adam-membership' ); ?></option>
						<?php foreach ( $cosmetic_options['themes'] ?? array() as $cosmetic ) : ?>
							<?php $this->render_member_cosmetic_option( $cosmetic, (string) ( $card_presentation['selected_values']['theme'] ?? '' ) ); ?>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span><?php esc_html_e( 'Acabamento do cartão', 'adam-membership' ); ?></span>
					<select name="active_card_frame">
						<option value=""><?php esc_html_e( 'Sem acabamento especial', 'adam-membership' ); ?></option>
						<?php foreach ( $cosmetic_options['frames'] ?? array() as $cosmetic ) : ?>
							<?php $this->render_member_cosmetic_option( $cosmetic, (string) ( $card_presentation['selected_values']['frame'] ?? '' ) ); ?>
						<?php endforeach; ?>
					</select>
				</label>
				</div>

				<p class="description"><?php esc_html_e( 'As alterações manuais de estado não enviam emails. O estado Ativo exige uma data de validade da quota igual ou posterior a hoje.', 'adam-membership' ); ?></p>
				<p class="description"><?php esc_html_e( 'Os títulos e cosméticos automáticos de Fundador/Fidelidade só se mantêm disponíveis enquanto o sócio conservar essa elegibilidade. Ao remover o estatuto de fundador, as recompensas exclusivas deixam de poder ser usadas.', 'adam-membership' ); ?></p>
				<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Guardar campos do sócio', 'adam-membership' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render administrator lockout protection notice when relevant.
	 *
	 * @param Member $member Member.
	 */
	private function render_admin_safety_notice( Member $member ): void {
		if ( ! $this->member_has_admin_access( $member ) ) {
			return;
		}
		?>
		<div class="adam-admin-safety-notice">
			<strong><?php esc_html_e( 'Proteção de administrador ativa', 'adam-membership' ); ?></strong>
			<p><?php esc_html_e( 'Este utilizador tem acesso de administrador do WordPress. As alterações ao estado da inscrição não removem o acesso ao wp-admin nem à administração ADAM Sócios.', 'adam-membership' ); ?></p>
			<?php if ( $this->is_current_admin_target( $member->user_id() ) ) : ?>
				<p><?php esc_html_e( 'Não pode rejeitar aqui a sua própria conta de administrador. Essa alteração deve ser revista por outro administrador.', 'adam-membership' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a notice when saved status and effective status diverge.
	 *
	 * @param Member $member Member.
	 */
	private function render_member_status_consistency_notice( Member $member ): void {
		if ( Member::STATUS_ACTIVE !== $member->status() || Member::STATUS_EXPIRED !== $member->effective_status() ) {
			return;
		}
		?>
		<div class="adam-admin-safety-notice">
			<strong><?php esc_html_e( 'O estado exige validade da quota', 'adam-membership' ); ?></strong>
			<p><?php esc_html_e( 'Este sócio está guardado como Ativo, mas o estado efetivo da inscrição é Expirado porque a data de validade da quota está vazia, é inválida ou já passou.', 'adam-membership' ); ?></p>
			<p><?php esc_html_e( 'Defina uma data de validade da quota igual ou posterior a hoje antes de guardar o estado Ativo.', 'adam-membership' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Handle an approval workflow action.
	 *
	 * @param string $action Approval workflow action.
	 */
	private function handle_member_action( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage ADAM members.', 'adam-membership' ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$this->verify_admin_nonce( 'adam_membership_member_action_' . $user_id );

		if ( 0 === $user_id ) {
			$this->redirect_with_error( __( 'Invalid member.', 'adam-membership' ) );
		}

		if ( self::ACTION_REJECT === $action && $this->is_current_admin_target( $user_id ) ) {
			$this->logger->error(
				'Self-rejection blocked for administrator.',
				array(
					'user_id' => $user_id,
				)
			);
			$this->redirect_with_error( __( 'Safety rule: administrators cannot reject their own account. Ask another administrator to review this account.', 'adam-membership' ) );
		}

		$result = match ( $action ) {
			self::ACTION_APPROVE      => $this->approval_service->approve( $user_id ),
			self::ACTION_CONFIRM_ANA  => $this->approval_service->confirm_ana_and_approve( $user_id, sanitize_text_field( wp_unslash( $_POST['ana_confirmation_date'] ?? '' ) ) ),
			self::ACTION_REMOVE_ANA   => $this->remove_ana_for_testing( $user_id ),
			self::ACTION_REJECT       => $this->approval_service->reject( $user_id, $this->posted_rejection_reason(), $this->posted_rejection_note() ),
			self::ACTION_REQUEST_CORRECTION => $this->approval_service->request_correction( $user_id, $this->posted_correction_reason(), $this->posted_correction_note(), isset( $_POST['correction_fields'] ) && is_array( $_POST['correction_fields'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['correction_fields'] ) ) : array() ),
			self::ACTION_RENEW        => $this->approval_service->renew_quota( $user_id ),
			self::ACTION_CHANGE_QUOTA => $this->approval_service->change_quota_validity( $user_id, $this->posted_quota_validity() ),
			self::ACTION_RESEND_EMAIL => $this->approval_service->resend_approval_email( $user_id ),
			self::ACTION_SEND_PRIVATE_DOCUMENT => $this->approval_service->send_private_document( $user_id ),
			self::ACTION_SAVE_MEMBER  => $this->save_member_fields( $user_id ),
			self::ACTION_REGENERATE_CARD_TOKEN => $this->regenerate_card_token( $user_id ),
			self::ACTION_REPLACE_DOCUMENT => $this->replace_member_document( $user_id ),
			self::ACTION_REMOVE_DOCUMENT => $this->remove_member_document( $user_id ),
			default                   => new WP_Error( 'adam_membership_invalid_action', __( 'Invalid member action.', 'adam-membership' ) ),
		};

		if ( $result instanceof WP_Error ) {
			$this->logger->error( 'Admin member action failed.', array( 'error' => $result->get_error_message() ) );
			$this->redirect_with_error( $result->get_error_message() );
		}

		$this->redirect_with_message( $this->action_success_message( $action ) );
	}

	/**
	 * Save manually editable member fields from the admin detail page.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private function remove_ana_for_testing( int $user_id ): true|WP_Error {
		$member = $this->members->find( $user_id );
		if ( null === $member ) { return new WP_Error( 'adam_membership_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) ); }
		$member->save( array( 'adam_apd_management_status' => Member::APD_EXTERNAL, 'adam_external_association_name' => '', 'adam_external_member_number' => '', 'adam_apd_ana_confirmation_date' => '' ) );
		$this->apd_association->repository()->reset_for_user( $user_id );
		return true;
	}

	private function save_member_fields( int $user_id ): true|WP_Error {
		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error(
				'adam_membership_member_not_found',
				__( 'Sócio não encontrado.', 'adam-membership' )
			);
		}

		$current_member_number = trim( (string) $member->field( 'numero_socio' ) );
		$member_number         = $this->posted_member_number();

		if ( $this->member_numbers_match( $member_number, $current_member_number ) ) {
			$member_number = $current_member_number;
		}

		if ( '' !== $member_number && $member_number !== $current_member_number && $this->members->member_number_exists( $member_number, $user_id ) ) {
			return new WP_Error(
				'adam_membership_duplicate_member_number',
				sprintf(
					/* translators: %s: member number */
					__( 'O número de sócio %s já está atribuído a outro sócio.', 'adam-membership' ),
					$member_number
				)
			);
		}

		$quota_validity    = $this->posted_date( 'quota_validity', __( 'Data de validade da quota inválida.', 'adam-membership' ) );
		$registration_date = $this->posted_date( 'registration_date', __( 'Invalid registration date.', 'adam-membership' ) );

		if ( $quota_validity instanceof WP_Error ) {
			return $quota_validity;
		}

		if ( $registration_date instanceof WP_Error ) {
			return $registration_date;
		}

		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : Member::STATUS_PENDING;

		if ( ! in_array( $status, $this->allowed_member_statuses(), true ) ) {
			return new WP_Error(
				'adam_membership_invalid_member_status',
				__( 'Invalid member status.', 'adam-membership' )
			);
		}

		if ( Member::STATUS_REJECTED === $status && $this->is_current_admin_target( $user_id ) ) {
			return new WP_Error(
				'adam_membership_self_admin_rejection_blocked',
				__( 'Safety rule: administrators cannot reject their own account. Ask another administrator to review this account.', 'adam-membership' )
			);
		}

		if ( Member::STATUS_REJECTED === $status && ! $member->isRejected() ) {
			return new WP_Error(
				'adam_membership_use_rejection_form',
				__( 'Please use the rejection form so a rejection reason is stored.', 'adam-membership' )
			);
		}

		if ( Member::STATUS_ACTIVE === $status && ! $this->quota_date_is_current( $quota_validity ) ) {
			return new WP_Error(
				'adam_membership_active_requires_current_quota',
				__( 'O estado Ativo exige uma data de validade da quota de hoje ou futura. Atualize a data da quota antes de guardar o estado Ativo.', 'adam-membership' )
			);
		}

		$updates = array(
			'numero_socio'   => $member_number,
			'validade_quota' => $quota_validity,
			'data_adesao'    => $registration_date,
			'telefone'       => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'equipa'         => isset( $_POST['team'] ) ? sanitize_text_field( wp_unslash( $_POST['team'] ) ) : '',
			'estado'         => $status,
		);
		foreach ( array( 'data_nascimento', 'genero', 'estado_civil', 'nacionalidade', 'naturalidade', 'profissao', 'telefone_fixo', 'morada', 'morada_linha_2', 'codigo_postal', 'cidade', 'municipio', 'pais', 'nif', 'cartao_cidadao', 'documento_validade', 'documento_local_emissao', 'adam_external_association_name', 'adam_external_member_number' ) as $field ) {
			$key = 'member_' . $field;
			if ( isset( $_POST[ $key ] ) ) {
				$updates[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				if ( 'cartao_cidadao' === $field ) { $updates[ $field ] = IdentificationValidator::normalize( $updates[ $field ] ); }
			}
		}
		$configs = (array) ( $this->settings->membership_form_settings()['registration_fields'] ?? array() );
		$config_keys = array( 'data_nascimento' => 'birth_date', 'genero' => 'gender', 'estado_civil' => 'marital_status', 'nacionalidade' => 'nationality', 'profissao' => 'profession', 'telefone_fixo' => 'telephone', 'morada' => 'address_line_1', 'morada_linha_2' => 'address_line_2', 'codigo_postal' => 'postcode', 'cidade' => 'city', 'municipio' => 'municipality', 'pais' => 'country', 'cartao_cidadao' => 'citizen_card', 'documento_validade' => 'document_expiry_date', 'documento_local_emissao' => 'document_issuing_place', 'nif' => 'nif', 'telefone' => 'phone', 'equipa' => 'team' );
		foreach ( $updates as $storage_key => $value ) {
			$form_key = $config_keys[ $storage_key ] ?? $storage_key;
			if ( ! isset( $configs[ $form_key ] ) || ! is_array( $configs[ $form_key ] ) ) { continue; }
			$check = SharedFieldValidator::validate( $form_key, $value, $configs[ $form_key ], false );
			if ( is_wp_error( $check ) ) { return $check; }
		}
		if ( isset( $_POST['member_email'] ) ) {
			$email = sanitize_email( wp_unslash( $_POST['member_email'] ) );
			if ( '' !== $email && $email !== $member->email() ) {
				$disable_email = static fn(): bool => false;
				add_filter( 'send_email_change_email', $disable_email );
				$email_result = wp_update_user( array( 'ID' => $user_id, 'user_email' => $email ) );
				remove_filter( 'send_email_change_email', $disable_email );
				if ( is_wp_error( $email_result ) ) { return $email_result; }
			}
		}
		if ( isset( $_POST['member_first_name'], $_POST['member_last_name'] ) ) {
			$first_name = sanitize_text_field( wp_unslash( $_POST['member_first_name'] ) );
			$last_name  = sanitize_text_field( wp_unslash( $_POST['member_last_name'] ) );
			$name_result = wp_update_user( array( 'ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name, 'display_name' => trim( $first_name . ' ' . $last_name ) ) );
			if ( is_wp_error( $name_result ) ) { return $name_result; }
		}

		$changes = $this->changed_member_fields( $member, $updates );
		$founder_status = isset( $_POST['founder_status'] ) ? sanitize_text_field( wp_unslash( $_POST['founder_status'] ) ) : '0';
		$founder_number = isset( $_POST['founder_number'] ) ? absint( wp_unslash( $_POST['founder_number'] ) ) : 0;
		$founder_changes = array();

		if ( '1' === $founder_status && $founder_number > 0 && $this->members->founder_number_exists( $founder_number, $user_id ) ) {
			return new WP_Error(
				'adam_membership_duplicate_founder_number',
				__( 'Este numero de fundador ja esta atribuido a outro socio.', 'adam-membership' )
			);
		}

		$current_founder_status = $member->is_founder() ? '1' : '0';

		if ( $current_founder_status !== $founder_status ) {
			$founder_changes['adam_founder_status'] = array(
				'old' => $current_founder_status,
				'new' => $founder_status,
			);
		}

		$current_founder_number = (string) $member->founder_number();
		$posted_founder_number  = (string) $founder_number;
		$current_cosmetics      = $this->cards->card_presentation( $member );
		$posted_cosmetics       = array(
			'title' => isset( $_POST['active_title_reward'] ) ? sanitize_key( wp_unslash( $_POST['active_title_reward'] ) ) : '',
			'theme' => isset( $_POST['active_card_theme'] ) ? sanitize_key( wp_unslash( $_POST['active_card_theme'] ) ) : '',
			'frame' => isset( $_POST['active_card_frame'] ) ? sanitize_key( wp_unslash( $_POST['active_card_frame'] ) ) : '',
		);
		$cosmetic_changed       = $posted_cosmetics !== array(
			'title' => sanitize_key( (string) ( $current_cosmetics['selected_values']['title'] ?? '' ) ),
			'theme' => sanitize_key( (string) ( $current_cosmetics['selected_values']['theme'] ?? '' ) ),
			'frame' => sanitize_key( (string) ( $current_cosmetics['selected_values']['frame'] ?? '' ) ),
		);

		if ( $current_founder_number !== $posted_founder_number ) {
			$founder_changes['adam_founder_number'] = array(
				'old' => $current_founder_number,
				'new' => $posted_founder_number,
			);
		}

		if ( array() === $changes && array() === $founder_changes && ! $cosmetic_changed ) {
			return true;
		}

		$member->save( $updates );

		if ( '' !== $member_number ) {
			$this->settings->ensure_member_number_floor( Member::member_number_numeric_value( $member_number ) );
		}

		$cosmetic_result = $this->cards->save_member_cosmetic_selection(
			$member,
			array(
				'active_title_reward' => isset( $_POST['active_title_reward'] ) ? sanitize_text_field( wp_unslash( $_POST['active_title_reward'] ) ) : '',
				'active_card_theme'   => isset( $_POST['active_card_theme'] ) ? sanitize_text_field( wp_unslash( $_POST['active_card_theme'] ) ) : '',
				'active_card_frame'   => isset( $_POST['active_card_frame'] ) ? sanitize_text_field( wp_unslash( $_POST['active_card_frame'] ) ) : '',
			)
		);

		if ( $cosmetic_result instanceof WP_Error ) {
			return $cosmetic_result;
		}

		if ( '1' === $founder_status && ! $member->is_founder() ) {
			$this->recognition->assign_founder( $member, $founder_number );
		} elseif ( '1' === $founder_status && $member->is_founder() && $founder_number > 0 && $founder_number !== $member->founder_number() ) {
			$member->save( array( 'adam_founder_number' => $founder_number ) );
		} elseif ( '1' !== $founder_status && $member->is_founder() ) {
			$this->recognition->revoke_founder( $member );
		}

		$this->recognition->sync_member( $member );
		$changes = array_merge( $changes, $founder_changes );
		$this->log_member_field_changes( $member, $changes );
		$this->record_admin_member_history(
			$member,
			'member_edited_by_admin',
			__( 'Sócio editado pela administração', 'adam-membership' ),
			__( 'Um administrador atualizou os dados do sócio a partir do perfil do sócio.', 'adam-membership' ),
			array(
				'changes' => $changes,
			)
		);

		return true;
	}

	/**
	 * Regenerate a member card validation token.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private function regenerate_card_token( int $user_id ): true|WP_Error {
		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error(
				'adam_membership_member_not_found',
				__( 'Sócio não encontrado.', 'adam-membership' )
			);
		}

		$this->cards->regenerate_token( $member );
		$this->record_admin_member_history(
			$member,
			'card_token_regenerated',
			__( 'Token do cartão regenerado', 'adam-membership' ),
			__( 'Um administrador regenerou o token de validação do cartão digital.', 'adam-membership' ),
			array()
		);

		return true;
	}

	/**
	 * Replace one member document from the admin profile.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private function replace_member_document( int $user_id ): true|WP_Error {
		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error( 'adam_membership_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) );
		}

		$document_field = isset( $_POST['document_field'] ) ? sanitize_key( wp_unslash( $_POST['document_field'] ) ) : '';

		if ( ! $this->is_allowed_member_document_field( $document_field ) ) {
			return new WP_Error( 'adam_membership_invalid_document_field', __( 'Documento inválido.', 'adam-membership' ) );
		}

		if ( ! isset( $_FILES['member_document_file'] ) || ! is_array( $_FILES['member_document_file'] ) || UPLOAD_ERR_NO_FILE === (int) ( $_FILES['member_document_file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error( 'adam_membership_document_missing', __( 'Selecione um ficheiro para carregar.', 'adam-membership' ) );
		}

		$previous_attachment_id = is_numeric( $member->field( $document_field ) ) ? absint( $member->field( $document_field ) ) : 0;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload(
			'member_document_file',
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
					'pdf'          => 'application/pdf',
				),
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_adam_membership_admin_document', '1' );
		$member->save( array( $document_field => $attachment_id ) );

		if ( $previous_attachment_id > 0 && $previous_attachment_id !== $attachment_id ) {
			$this->delete_attachment_if_unreferenced( $previous_attachment_id );
		}

		$this->record_admin_member_history(
			$member,
			'member_document_uploaded_by_admin',
			__( 'Documento carregado pela administração', 'adam-membership' ),
			__( 'Um administrador carregou ou substituiu um documento do sócio.', 'adam-membership' ),
			array(
				'document_field' => $document_field,
			)
		);

		return true;
	}

	/**
	 * Remove one member document from the admin profile.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private function remove_member_document( int $user_id ): true|WP_Error {
		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error( 'adam_membership_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) );
		}

		$document_field = isset( $_POST['document_field'] ) ? sanitize_key( wp_unslash( $_POST['document_field'] ) ) : '';

		if ( ! $this->is_allowed_member_document_field( $document_field ) ) {
			return new WP_Error( 'adam_membership_invalid_document_field', __( 'Documento inválido.', 'adam-membership' ) );
		}

		$attachment_id = is_numeric( $member->field( $document_field ) ) ? absint( $member->field( $document_field ) ) : 0;

		$member->save( array( $document_field => '' ) );

		if ( $attachment_id > 0 ) {
			$this->delete_attachment_if_unreferenced( $attachment_id );
		}

		$this->record_admin_member_history(
			$member,
			'member_document_removed_by_admin',
			__( 'Documento removido pela administração', 'adam-membership' ),
			__( 'Um administrador removeu um documento do sócio.', 'adam-membership' ),
			array(
				'document_field' => $document_field,
			)
		);

		return true;
	}

	/**
	 * Replace one renewal document from admin review screens.
	 *
	 * @param int $request_id Renewal request ID.
	 * @return true|WP_Error
	 */
	private function replace_renewal_document( int $request_id ): true|WP_Error {
		$request = $this->renewal_repository->find( $request_id );

		if ( null === $request ) {
			return new WP_Error( 'adam_membership_renewal_not_found', __( 'Pedido de renovação não encontrado.', 'adam-membership' ) );
		}

		$document_field = isset( $_POST['document_field'] ) ? sanitize_key( wp_unslash( $_POST['document_field'] ) ) : '';

		if ( ! $this->is_allowed_renewal_document_field( $document_field ) ) {
			return new WP_Error( 'adam_membership_invalid_document_field', __( 'Documento inválido.', 'adam-membership' ) );
		}

		if ( ! isset( $_FILES['member_document_file'] ) || ! is_array( $_FILES['member_document_file'] ) || UPLOAD_ERR_NO_FILE === (int) ( $_FILES['member_document_file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new WP_Error( 'adam_membership_document_missing', __( 'Selecione um ficheiro para carregar.', 'adam-membership' ) );
		}

		$submitted_data         = $request->submitted_data();
		$previous_value         = 'payment_receipt' === $document_field ? $request->proof_of_payment() : ( $submitted_data[ $document_field ] ?? '' );
		$previous_attachment_id = is_numeric( $previous_value ) ? absint( $previous_value ) : 0;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload(
			'member_document_file',
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
					'pdf'          => 'application/pdf',
				),
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( $attachment_id, '_adam_membership_admin_document', '1' );

		if ( 'payment_receipt' === $document_field ) {
			$this->renewal_repository->update(
				$request,
				array(
					'proof_of_payment' => $attachment_id,
				)
			);

			if ( $previous_attachment_id > 0 && $previous_attachment_id !== $attachment_id ) {
				$this->delete_attachment_if_unreferenced( $previous_attachment_id );
			}

			return true;
		}

		$submitted_data[ $document_field ] = $attachment_id;

		$this->renewal_repository->update(
			$request,
			array(
				'submitted_data' => $submitted_data,
			)
		);

		if ( $previous_attachment_id > 0 && $previous_attachment_id !== $attachment_id ) {
			$this->delete_attachment_if_unreferenced( $previous_attachment_id );
		}

		return true;
	}

	/**
	 * Remove one renewal document from admin review screens.
	 *
	 * @param int $request_id Renewal request ID.
	 * @return true|WP_Error
	 */
	private function remove_renewal_document( int $request_id ): true|WP_Error {
		$request = $this->renewal_repository->find( $request_id );

		if ( null === $request ) {
			return new WP_Error( 'adam_membership_renewal_not_found', __( 'Pedido de renovação não encontrado.', 'adam-membership' ) );
		}

		$document_field = isset( $_POST['document_field'] ) ? sanitize_key( wp_unslash( $_POST['document_field'] ) ) : '';

		if ( ! $this->is_allowed_renewal_document_field( $document_field ) ) {
			return new WP_Error( 'adam_membership_invalid_document_field', __( 'Documento inválido.', 'adam-membership' ) );
		}

		$submitted_data = $request->submitted_data();
		$previous_value = 'payment_receipt' === $document_field ? $request->proof_of_payment() : ( $submitted_data[ $document_field ] ?? '' );
		$attachment_id  = is_numeric( $previous_value ) ? absint( $previous_value ) : 0;

		if ( 'payment_receipt' === $document_field ) {
			$this->renewal_repository->update(
				$request,
				array(
					'proof_of_payment' => '',
				)
			);

			if ( $attachment_id > 0 ) {
				$this->delete_attachment_if_unreferenced( $attachment_id );
			}

			return true;
		}

		unset( $submitted_data[ $document_field ] );

		$this->renewal_repository->update(
			$request,
			array(
				'submitted_data' => $submitted_data,
			)
		);

		if ( $attachment_id > 0 ) {
			$this->delete_attachment_if_unreferenced( $attachment_id );
		}

		return true;
	}

	/**
	 * Determine whether a document meta key is admin-manageable.
	 *
	 * @param string $meta_key Meta key.
	 */
	private function is_allowed_member_document_field( string $meta_key ): bool {
		foreach ( array_merge( $this->document_field_definitions( 'registration' ), $this->document_field_definitions( 'renewal' ) ) as $definition ) {
			if ( $meta_key === (string) $definition['meta_key'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a renewal document key is admin-manageable.
	 *
	 * @param string $meta_key Meta key.
	 */
	private function is_allowed_renewal_document_field( string $meta_key ): bool {
		foreach ( $this->document_field_definitions( 'renewal' ) as $definition ) {
			if ( $meta_key === (string) $definition['meta_key'] || $meta_key === (string) $definition['field_key'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete an attachment only after every member and renewal reference is gone.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function delete_attachment_if_unreferenced( int $attachment_id ): void {
		if ( $attachment_id <= 0 || '1' !== (string) get_post_meta( $attachment_id, '_adam_membership_admin_document', true ) ) {
			return;
		}

		$definitions = array_merge( $this->document_field_definitions( 'registration' ), $this->document_field_definitions( 'renewal' ) );
		$meta_keys   = array_values(
			array_unique(
				array_map(
					static fn ( array $definition ): string => (string) ( $definition['meta_key'] ?? '' ),
					$definitions
				)
			)
		);

		foreach ( $this->members->all_members() as $member ) {
			foreach ( $meta_keys as $meta_key ) {
				$value = $member->field( $meta_key );

				if ( is_numeric( $value ) && absint( $value ) === $attachment_id ) {
					return;
				}
			}
		}

		foreach ( $this->renewal_repository->admin_requests() as $request ) {
			$references = array_merge( array( $request->proof_of_payment() ), array_values( $request->submitted_data() ) );

			foreach ( $references as $value ) {
				if ( is_numeric( $value ) && absint( $value ) === $attachment_id ) {
					return;
				}
			}
		}

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Get changed member fields.
	 *
	 * @param Member                $member  Member.
	 * @param array<string, string> $updates Sanitized updates.
	 * @return array<string, array{old:string,new:string}>
	 */
	private function changed_member_fields( Member $member, array $updates ): array {
		$changes = array();

		foreach ( $updates as $field => $new_value ) {
			$old_value = $this->scalar_field_value( $member->field( $field ) );

			if ( $old_value === $new_value ) {
				continue;
			}

			$changes[ $field ] = array(
				'old' => $old_value,
				'new' => $new_value,
			);
		}

		return $changes;
	}

	/**
	 * Log changed member fields to the plugin audit log.
	 *
	 * @param Member                                      $member  Member.
	 * @param array<string, array{old:string,new:string}> $changes Changed fields.
	 */
	private function log_member_field_changes( Member $member, array $changes ): void {
		$admin = wp_get_current_user();

		foreach ( $changes as $field => $change ) {
			$this->logger->info(
				'Member admin field changed.',
				array(
					'user_id'       => $member->user_id(),
					'field'         => $field,
					'old_value'     => $change['old'],
					'new_value'     => $change['new'],
					'admin_user_id' => get_current_user_id(),
					'admin_login'   => $admin->exists() ? $admin->user_login : '',
					'changed_at'    => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
				)
			);
		}
	}

	/**
	 * Get sanitized posted member number.
	 */
	private function posted_member_number(): string {
		$member_number = isset( $_POST['member_number'] ) ? sanitize_text_field( wp_unslash( $_POST['member_number'] ) ) : '';
		$member_number = trim( $member_number );

		if ( '' !== $member_number && preg_match( '/^\d+$/', $member_number ) ) {
			if ( absint( $member_number ) <= 0 ) {
				return '';
			}

			return $this->settings->format_member_number( absint( $member_number ) );
		}

		return $member_number;
	}

	/**
	 * Determine whether two member-number inputs refer to the same effective number.
	 *
	 * @param string $left  First value.
	 * @param string $right Second value.
	 */
	private function member_numbers_match( string $left, string $right ): bool {
		$left  = trim( $left );
		$right = trim( $right );

		if ( '' === $left || '' === $right ) {
			return $left === $right;
		}

		if ( strtolower( $left ) === strtolower( $right ) ) {
			return true;
		}

		return Member::member_number_numeric_value( $left ) === Member::member_number_numeric_value( $right );
	}

	/**
	 * Render one member cosmetic option for admin selects.
	 *
	 * @param array<string, mixed> $cosmetic Cosmetic data.
	 * @param string               $selected Selected key.
	 */
	private function render_member_cosmetic_option( array $cosmetic, string $selected ): void {
		$key   = isset( $cosmetic['key'] ) ? sanitize_key( (string) $cosmetic['key'] ) : '';
		$name  = isset( $cosmetic['name'] ) ? sanitize_text_field( (string) $cosmetic['name'] ) : $key;
		$rarity = isset( $cosmetic['rarity_label'] ) ? sanitize_text_field( (string) $cosmetic['rarity_label'] ) : '';
		$source = isset( $cosmetic['unlock_source_label'] ) ? sanitize_text_field( (string) $cosmetic['unlock_source_label'] ) : '';

		if ( '' === $key ) {
			return;
		}

		$label = $name;

		if ( '' !== $rarity ) {
			$label .= ' — ' . $rarity;
		}

		if ( '' !== $source && ! in_array( $source, array( 'Pontos ADAM', 'Evento especial' ), true ) ) {
			$label .= ' · ' . $source;
		}

		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $key ),
			selected( $selected, $key, false ),
			esc_html( $label )
		);
	}

	/**
	 * Get a validated posted date.
	 *
	 * @param string $key           POST key.
	 * @param string $error_message Error message.
	 * @return string|WP_Error
	 */
	private function posted_date( string $key, string $error_message ): string|WP_Error {
		$date = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$date = trim( $date );

		if ( '' === $date ) {
			return '';
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'adam_membership_invalid_date', $error_message );
		}

		$parts = array_map( 'absint', explode( '-', $date ) );

		if ( 3 !== count( $parts ) || ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
			return new WP_Error( 'adam_membership_invalid_date', $error_message );
		}

		return $date;
	}

	/**
	 * Check whether a quota date keeps a member current.
	 *
	 * @param string $date Date in Y-m-d format.
	 */
	private function quota_date_is_current( string $date ): bool {
		if ( '' === $date ) {
			return false;
		}

		$timestamp = strtotime( $date );
		$today     = strtotime( wp_date( 'Y-m-d', current_time( 'timestamp' ) ) );

		return false !== $timestamp && false !== $today && $timestamp >= $today;
	}

	/**
	 * Get allowed editable member statuses.
	 *
	 * @return array<int, string>
	 */
	private function allowed_member_statuses(): array {
		return array(
			Member::STATUS_PENDING,
			Member::STATUS_ACTIVE,
			Member::STATUS_RENEWAL_PENDING,
			Member::STATUS_EXPIRED,
			Member::STATUS_REJECTED,
		);
	}

	/**
	 * Normalize a scalar member field value for comparison and logging.
	 *
	 * @param mixed $value Field value.
	 */
	private function scalar_field_value( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Determine whether the action targets the current administrator account.
	 *
	 * @param int $user_id Target user ID.
	 */
	private function is_current_admin_target( int $user_id ): bool {
		return get_current_user_id() === $user_id && current_user_can( self::CAPABILITY );
	}

	/**
	 * Determine whether a member's WordPress account has administrator access.
	 *
	 * @param Member $member Member.
	 */
	private function member_has_admin_access( Member $member ): bool {
		$user = $member->user();

		return null !== $user && $user->has_cap( self::CAPABILITY );
	}

	/**
	 * Ensure the current user can manage ADAM membership data.
	 */
	private function ensure_can_manage(): void {
		/*
		 * Safety rule: WordPress administrators must always be allowed into
		 * wp-admin and ADAM Membership admin pages regardless of membership
		 * status, quota state, or missing member metadata.
		 */
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage ADAM members.', 'adam-membership' ) );
		}
	}

	/**
	 * Read current history filters from the query string.
	 *
	 * @return array<string, string|int>
	 */
	private function current_history_filters(): array {
		return array(
			'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'action_key' => isset( $_GET['action_key'] ) ? sanitize_key( wp_unslash( $_GET['action_key'] ) ) : '',
			'actor_type' => isset( $_GET['actor_type'] ) ? sanitize_key( wp_unslash( $_GET['actor_type'] ) ) : '',
			'date_from'  => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'    => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'member_id'  => isset( $_GET['member_id'] ) ? absint( wp_unslash( $_GET['member_id'] ) ) : 0,
		);
	}

	/**
	 * Render history filters.
	 *
	 * @param array<string, string|int> $filters Current filters.
	 */
	private function render_history_filters( array $filters ): void {
		$action_types = $this->history_repository->action_types();
		?>
		<form method="get" class="adam-admin-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::HISTORY_PAGE_SLUG ); ?>">
			<label>
				<span><?php esc_html_e( 'Pesquisar', 'adam-membership' ); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr( (string) ( $filters['search'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Member name, email, number, ID', 'adam-membership' ); ?>">
			</label>
			<label>
				<span><?php esc_html_e( 'Tipo de ação', 'adam-membership' ); ?></span>
				<select name="action_key">
					<?php $this->render_select_option( '', __( 'All actions', 'adam-membership' ), (string) ( $filters['action_key'] ?? '' ) ); ?>
					<?php foreach ( $action_types as $key => $label ) : ?>
						<?php $this->render_select_option( $key, $label, (string) ( $filters['action_key'] ?? '' ) ); ?>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Tipo de interveniente', 'adam-membership' ); ?></span>
				<select name="actor_type">
					<?php $this->render_select_option( '', __( 'All actors', 'adam-membership' ), (string) ( $filters['actor_type'] ?? '' ) ); ?>
					<?php $this->render_select_option( 'admin', __( 'Admin', 'adam-membership' ), (string) ( $filters['actor_type'] ?? '' ) ); ?>
					<?php $this->render_select_option( 'member', __( 'Member', 'adam-membership' ), (string) ( $filters['actor_type'] ?? '' ) ); ?>
					<?php $this->render_select_option( 'system', __( 'Sistema', 'adam-membership' ), (string) ( $filters['actor_type'] ?? '' ) ); ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'From date', 'adam-membership' ); ?></span>
				<input type="date" name="date_from" value="<?php echo esc_attr( (string) ( $filters['date_from'] ?? '' ) ); ?>">
			</label>
			<label>
				<span><?php esc_html_e( 'To date', 'adam-membership' ); ?></span>
				<input type="date" name="date_to" value="<?php echo esc_attr( (string) ( $filters['date_to'] ?? '' ) ); ?>">
			</label>
			<?php if ( ! empty( $filters['member_id'] ) ) : ?>
				<input type="hidden" name="member_id" value="<?php echo esc_attr( (string) $filters['member_id'] ); ?>">
			<?php endif; ?>
			<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Apply filters', 'adam-membership' ); ?></button>
			<a class="button" href="<?php echo esc_url( $this->history_url( ! empty( $filters['member_id'] ) ? array( 'member_id' => (string) $filters['member_id'] ) : array() ) ); ?>"><?php esc_html_e( 'Reset', 'adam-membership' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Render a history timeline.
	 *
	 * @param array<int, HistoryEntry> $entries History entries.
	 * @param Member|null              $member  Current member when scoped.
	 */
	private function render_history_timeline( array $entries, ?Member $member = null ): void {
		if ( array() === $entries ) {
			$this->render_empty_state(
				null !== $member
					? __( 'No history entries were found for this member yet.', 'adam-membership' )
					: __( 'No history entries match the current filters.', 'adam-membership' )
			);
			return;
		}
		?>
		<div class="adam-admin-history-list">
			<?php foreach ( $entries as $entry ) : ?>
				<article class="adam-admin-history-item">
					<div class="adam-admin-history-item__header">
						<div>
							<div class="adam-admin-history-item__meta">
								<span class="adam-admin-badge adam-admin-history-actor actor-<?php echo esc_attr( $entry->actor_type() ); ?>"><?php echo esc_html( $this->history_actor_label( $entry->actor_type() ) ); ?></span>
								<span class="adam-admin-history-date"><?php echo esc_html( $this->format_datetime( $entry->created_at() ) ); ?></span>
							</div>
							<h3><?php echo esc_html( $entry->action_label() ); ?></h3>
						</div>
						<?php if ( null === $member ) : ?>
							<div class="adam-admin-history-member">
								<strong><?php echo esc_html( '' !== $entry->member_name() ? $entry->member_name() : __( 'Unknown member', 'adam-membership' ) ); ?></strong>
								<span><?php echo esc_html( '' !== $entry->member_number() ? $entry->member_number() : __( 'No member number', 'adam-membership' ) ); ?></span>
							</div>
						<?php endif; ?>
					</div>
					<p class="adam-admin-history-description"><?php echo esc_html( $entry->description() ); ?></p>
					<div class="adam-admin-history-details">
						<div><strong><?php esc_html_e( 'Actor', 'adam-membership' ); ?>:</strong> <?php echo esc_html( $entry->actor_name() ); ?></div>
						<?php if ( null === $member ) : ?>
							<div><strong><?php esc_html_e( 'Email', 'adam-membership' ); ?>:</strong> <?php echo esc_html( $entry->member_email() ); ?></div>
						<?php endif; ?>
						<div><strong><?php esc_html_e( 'ID do sócio', 'adam-membership' ); ?>:</strong> <?php echo esc_html( (string) $entry->member_id() ); ?></div>
					</div>
					<?php $this->render_history_metadata( $entry ); ?>
				</article>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render history metadata.
	 *
	 * @param HistoryEntry $entry History entry.
	 */
	private function render_history_metadata( HistoryEntry $entry ): void {
		$details = $entry->details();

		if ( array() === $details ) {
			return;
		}
		?>
		<div class="adam-admin-history-metadata">
			<?php foreach ( $details as $key => $value ) : ?>
				<div class="adam-admin-history-meta-row">
					<span><?php echo esc_html( $this->history_meta_label( (string) $key ) ); ?></span>
					<strong><?php echo esc_html( $this->history_meta_value( $value ) ); ?></strong>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Get a history page URL.
	 *
	 * @param array<string, string> $args Query arguments.
	 */
	private function history_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::HISTORY_PAGE_SLUG,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Read current list filters from the query string.
	 *
	 * @return array<string, string>
	 */
	private function current_member_filters(): array {
		$member_number_sort = isset( $_GET['member_number_sort'] ) ? sanitize_key( wp_unslash( $_GET['member_number_sort'] ) ) : '';
		$orderby            = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'registered';
		$order              = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ? 'asc' : 'desc';

		if ( in_array( $member_number_sort, array( 'asc', 'desc' ), true ) ) {
			$orderby = 'member_number';
			$order   = $member_number_sort;
		}

		return array(
			'search'             => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status'             => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '',
			'quota_status'       => isset( $_GET['quota_status'] ) ? sanitize_key( wp_unslash( $_GET['quota_status'] ) ) : '',
			'orderby'            => $orderby,
			'order'              => $order,
			'member_number_sort' => $member_number_sort,
		);
	}

	/**
	 * Read current team list filters from the query string.
	 *
	 * @return array<string, string>
	 */
	private function current_team_filters(): array {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'name';
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ? 'desc' : 'asc';

		return array(
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby' => in_array( $orderby, array( 'name', 'members', 'created_at', 'updated_at', 'type', 'eligible' ), true ) ? $orderby : 'name',
			'order'   => $order,
		);
	}

	/**
	 * Read current renewal filters from query string.
	 *
	 * @return array<string, string>
	 */
	private function current_renewal_filters(): array {
		return array(
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'order'  => isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ? 'asc' : 'desc',
		);
	}

	/**
	 * Render a select option.
	 *
	 * @param string $value   Option value.
	 * @param string $label   Option label.
	 * @param string $current Current value.
	 */
	private function render_select_option( string $value, string $label, string $current ): void {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}

	/**
	 * Render a sortable column link.
	 *
	 * @param string                $label   Link label.
	 * @param string                $orderby Sort key.
	 * @param array<string, string> $filters Current filters.
	 */
	private function sort_link( string $label, string $orderby, array $filters ): string {
		$current_orderby = $filters['orderby'] ?? 'registered';
		$current_order   = $filters['order'] ?? 'desc';
		$next_order      = $current_orderby === $orderby && 'asc' === $current_order ? 'desc' : 'asc';

		$url = add_query_arg(
			array_filter(
				array(
					'page'         => isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'adam-membership-members',
					's'            => $filters['search'] ?? '',
					'status'       => $filters['status'] ?? '',
					'quota_status' => $filters['quota_status'] ?? '',
					'orderby'      => $orderby,
					'order'        => $next_order,
				)
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * Render a sortable team column link.
	 *
	 * @param string                $label   Link label.
	 * @param string                $orderby Sort key.
	 * @param array<string, string> $filters Current filters.
	 */
	private function team_sort_link( string $label, string $orderby, array $filters ): string {
		$current_orderby = $filters['orderby'] ?? 'name';
		$current_order   = $filters['order'] ?? 'asc';
		$next_order      = $current_orderby === $orderby && 'asc' === $current_order ? 'desc' : 'asc';

		$url = add_query_arg(
			array_filter(
				array(
					'page'    => self::TEAMS_PAGE_SLUG,
					's'       => $filters['search'] ?? '',
					'orderby' => $orderby,
					'order'   => $next_order,
				)
			),
			admin_url( 'admin.php' )
		);

		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $label ) );
	}

	/**
	 * Render a team-type badge.
	 *
	 * @param Team $team Team.
	 */
	private function render_team_type_badge( Team $team ): void {
		$class = Team::TYPE_ASSOCIATED === $team->type() ? 'status-active' : '';

		printf(
			'<span class="adam-admin-badge %1$s">%2$s</span>',
			esc_attr( $class ),
			esc_html( $this->team_type_label( $team ) )
		);
	}

	/**
	 * Render current team eligibility.
	 *
	 * @param bool $eligible Eligibility state.
	 */
	private function render_team_eligibility_badge( bool $eligible ): void {
		printf(
			'<span class="adam-admin-badge %1$s">%2$s</span>',
			esc_attr( $eligible ? 'status-active' : '' ),
			esc_html( $eligible ? __( 'Sim', 'adam-membership' ) : __( 'Não', 'adam-membership' ) )
		);
	}

	/**
	 * Get a team-type display label.
	 *
	 * @param Team $team Team.
	 */
	private function team_type_label( Team $team ): string {
		return Team::TYPE_ASSOCIATED === $team->type()
			? __( 'Equipa Associada', 'adam-membership' )
			: __( 'Equipa', 'adam-membership' );
	}

	/**
	 * Render a status badge.
	 *
	 * @param string $status Member status.
	 */
	private function render_status_badge( string $status ): void {
		printf(
			'<span class="adam-admin-badge %1$s">%2$s</span>',
			esc_attr( $this->status_badge_class( $status ) ),
			esc_html( DisplayLabels::status( $status ) )
		);
	}

	/**
	 * Get a safe status badge class.
	 *
	 * @param string $status Status.
	 */
	private function status_badge_class( string $status ): string {
		return match ( $status ) {
			Member::STATUS_ACTIVE          => 'status-active',
			Member::STATUS_PENDING         => 'status-pending',
			Member::STATUS_RENEWAL_PENDING => 'status-renewal-pending',
			Member::STATUS_EXPIRED         => 'status-expired',
			Member::STATUS_REJECTED        => 'status-rejected',
			default                        => 'status-unknown',
		};
	}

	/**
	 * Render a quota badge.
	 *
	 * @param Member $member Member.
	 */
	private function render_quota_badge( Member $member ): void {
		$status = $member->quota_status();
		$label  = match ( $status ) {
			Member::QUOTA_ACTIVE        => __( 'Ativa', 'adam-membership' ),
			Member::QUOTA_EXPIRING_SOON => __( 'A expirar brevemente', 'adam-membership' ),
			default                     => __( 'Expirada', 'adam-membership' ),
		};

		printf(
			'<span class="adam-admin-badge quota-%1$s">%2$s</span><small>%3$s</small>',
			esc_attr( $status ),
			esc_html( $label ),
			esc_html( $this->format_date( $member->field( 'validade_quota' ) ) ?: '—' )
		);
	}

	/**
	 * Build current-member document rows from stored member meta.
	 *
	 * @param Member $member Member.
	 * @param bool   $include_management Whether to include replace/remove controls.
	 * @return array<int, array<string, mixed>>
	 */
	private function member_document_rows( Member $member, bool $include_management ): array {
		$rows = array();

		foreach ( $this->document_field_definitions( 'registration' ) as $definition ) {
			$meta_key = (string) $definition['meta_key'];
			$value    = $member->field( $meta_key );
			$url      = $this->media_reference_url( $value );

			$rows[] = array(
				'field_key'    => (string) $definition['field_key'],
				'meta_key'     => $meta_key,
				'label'        => (string) $definition['label'],
				'file_name'    => $this->media_reference_filename( $value ),
				'workflow'     => __( 'Inscrição', 'adam-membership' ),
				'status'       => '' !== $url ? __( 'Submetido', 'adam-membership' ) : __( 'Em falta', 'adam-membership' ),
				'missing'      => '' === $url,
				'url'          => $url,
				'preview_html' => $this->document_preview_html( $member->full_name(), $value, $meta_key ),
				'uploaded_at'  => $this->media_reference_datetime( $value ),
				'manage'       => $include_management,
			);
		}

		return $rows;
	}

	/**
	 * Build renewal-submission document rows.
	 *
	 * @param RenewalRequest $request Renewal request.
	 * @return array<int, array<string, mixed>>
	 */
	private function renewal_document_rows( RenewalRequest $request ): array {
		$rows          = array();
		$submitted     = $request->submitted_data();
		$proof_value   = $request->proof_of_payment();

		foreach ( $this->document_field_definitions( 'renewal' ) as $definition ) {
			$field_key = (string) $definition['field_key'];
			$value     = 'payment_receipt' === $field_key ? $proof_value : ( $submitted[ (string) $definition['meta_key'] ] ?? '' );
			$url       = $this->media_reference_url( $value );

			$rows[] = array(
				'field_key'    => $field_key,
				'meta_key'     => (string) $definition['meta_key'],
				'label'        => (string) $definition['label'],
				'file_name'    => $this->media_reference_filename( $value ),
				'workflow'     => sprintf(
					/* translators: %s: renewal status label */
					__( 'Renovação (%s)', 'adam-membership' ),
					$this->renewal_status_label( $request->status() )
				),
				'status'       => '' !== $url ? __( 'Submetido', 'adam-membership' ) : __( 'Em falta', 'adam-membership' ),
				'missing'      => '' === $url,
				'url'          => $url,
				'preview_html' => $this->document_preview_html( (string) $definition['label'], $value, (string) $definition['meta_key'] ),
				'uploaded_at'  => $this->media_reference_datetime( $value, $request->submitted_at() ),
				'manage'       => true,
			);
		}

		return $rows;
	}

	/**
	 * Get file upload field definitions for a workflow.
	 *
	 * @param string $form Workflow key.
	 * @return array<int, array<string, mixed>>
	 */
	private function document_field_definitions( string $form ): array {
		$settings = $this->settings->membership_form_settings();
		$key      = 'renewal' === $form ? 'renewal_fields' : 'registration_fields';
		$fields   = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : array();
		$rows     = array();

		foreach ( $fields as $field_key => $config ) {
			if ( ! is_string( $field_key ) || ! is_array( $config ) || empty( $config['enabled'] ) || 'file' !== (string) ( $config['type'] ?? '' ) ) {
				continue;
			}

			$rows[] = array(
				'field_key'    => $field_key,
				'label'        => is_string( $config['label'] ?? null ) ? (string) $config['label'] : $field_key,
				'required'     => ! empty( $config['required'] ),
				'conditional'  => is_string( $config['conditional'] ?? null ) ? (string) $config['conditional'] : 'always',
				'meta_key'     => ! empty( $config['locked'] ) ? $this->document_meta_key( $field_key ) : 'adam_custom_' . sanitize_key( $field_key ),
				'order'        => absint( $config['order'] ?? 999 ),
			);
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return (int) ( $left['order'] ?? 999 ) <=> (int) ( $right['order'] ?? 999 );
			}
		);

		return $rows;
	}

	/**
	 * Render a documents panel.
	 *
	 * @param string      $title  Panel title.
	 * @param array<int, array<string, mixed>> $rows Document rows.
	 * @param Member|null         $member Member when management actions are available.
	 * @param RenewalRequest|null $request Renewal request when request-level actions are available.
	 * @param bool                $show_management Whether replace/remove controls should render.
	 */
	private function render_documents_panel( string $title, array $rows, ?Member $member = null, ?RenewalRequest $request = null, bool $show_management = false ): void {
		?>
		<div class="adam-admin-panel adam-card adam-admin-documents-panel">
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php if ( array() === $rows ) : ?>
				<?php $this->render_empty_state( __( 'Não existem documentos submetidos para mostrar.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<div class="adam-admin-document-list" role="table" aria-label="<?php echo esc_attr( $title ); ?>">
					<div class="adam-admin-document-list__header" role="row">
						<span role="columnheader"><?php esc_html_e( 'Documento', 'adam-membership' ); ?></span>
						<span role="columnheader"><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
						<span role="columnheader"><?php esc_html_e( 'Pré-visualização', 'adam-membership' ); ?></span>
						<span role="columnheader"><?php esc_html_e( 'Fluxo', 'adam-membership' ); ?></span>
						<span role="columnheader"><?php esc_html_e( 'Data de envio', 'adam-membership' ); ?></span>
						<span role="columnheader"><?php esc_html_e( 'Ações', 'adam-membership' ); ?></span>
					</div>
					<?php foreach ( $rows as $row ) : ?>
						<div class="adam-admin-document-row" role="row">
							<div class="adam-admin-document-cell adam-admin-document-cell--name" role="cell" data-label="<?php esc_attr_e( 'Documento', 'adam-membership' ); ?>">
								<strong><?php echo esc_html( (string) $row['label'] ); ?></strong>
								<span class="adam-admin-document-filename" title="<?php echo esc_attr( (string) ( $row['file_name'] ?: __( 'Sem ficheiro', 'adam-membership' ) ) ); ?>">
									<?php echo esc_html( (string) ( $row['file_name'] ?: __( 'Sem ficheiro', 'adam-membership' ) ) ); ?>
								</span>
							</div>
							<div class="adam-admin-document-cell" role="cell" data-label="<?php esc_attr_e( 'Estado', 'adam-membership' ); ?>">
									<span class="adam-admin-badge <?php echo ! empty( $row['missing'] ) ? 'quota-expired' : 'quota-active'; ?>">
										<?php echo esc_html( (string) $row['status'] ); ?>
									</span>
							</div>
							<div class="adam-admin-document-cell adam-admin-document-cell--preview" role="cell" data-label="<?php esc_attr_e( 'Pré-visualização', 'adam-membership' ); ?>">
								<?php echo wp_kses_post( (string) $row['preview_html'] ); ?>
							</div>
							<div class="adam-admin-document-cell" role="cell" data-label="<?php esc_attr_e( 'Fluxo', 'adam-membership' ); ?>">
								<?php echo esc_html( (string) $row['workflow'] ); ?>
							</div>
							<div class="adam-admin-document-cell" role="cell" data-label="<?php esc_attr_e( 'Data de envio', 'adam-membership' ); ?>">
								<?php echo esc_html( (string) ( $row['uploaded_at'] ?: '—' ) ); ?>
							</div>
							<div class="adam-admin-document-cell adam-admin-document-actions" role="cell" data-label="<?php esc_attr_e( 'Ações', 'adam-membership' ); ?>">
								<?php if ( '' !== (string) $row['url'] ) : ?>
									<div class="adam-admin-document-links">
										<a class="button button-small" href="<?php echo esc_url( (string) $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir', 'adam-membership' ); ?></a>
										<a class="button button-small" href="<?php echo esc_url( (string) $row['url'] ); ?>" download><?php esc_html_e( 'Descarregar', 'adam-membership' ); ?></a>
									</div>
								<?php endif; ?>
								<?php if ( $show_management && $member instanceof Member ) : ?>
									<?php $this->render_document_management_controls( $member, (string) $row['meta_key'], empty( $row['missing'] ) ); ?>
								<?php elseif ( $show_management && $request instanceof RenewalRequest ) : ?>
									<?php $this->render_renewal_document_management_controls( $request, (string) $row['meta_key'], empty( $row['missing'] ) ); ?>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_financial_history( int $member_id = 0 ): void {
		$movements = $member_id > 0 ? $this->financial_movements->for_member( $member_id ) : $this->financial_movements->all();
		$redirect = add_query_arg( array( 'page' => self::HISTORY_PAGE_SLUG, 'member_id' => $member_id ), admin_url( 'admin.php' ) );
		echo '<div class="adam-admin-panel adam-card"><h2>Histórico financeiro</h2><p>Os movimentos financeiros são registos históricos independentes dos pedidos de inscrição, renovação e APD.</p>';
		if ( array() === $movements ) {
			echo '<p>Não existem movimentos financeiros.</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Tipo de quota</th><th>Sócio</th><th>Ano</th><th>Valor</th><th>Data</th><th>Método</th><th>Estado financeiro</th><th>Google Sheets</th><th>ID do movimento</th><th>Ação</th></tr></thead><tbody>';
		foreach ( $movements as $movement ) {
			$financial_label = array( 'paid' => 'Pago', 'pending' => 'Pendente', 'failed' => 'Falhou' )[ $movement->financial_status() ] ?? $movement->financial_status();
			$google_label = array( 'pending' => 'Pendente', 'synchronized' => 'Sincronizado', 'failed' => 'Falhou', 'inactive' => 'Não ativa — sincronização não necessária' )[ $movement->google_state() ] ?? $movement->google_state();
			echo '<tr><td>' . esc_html( $movement->quota_type() ) . '</td><td>' . esc_html( $movement->member_number() . ( $movement->member_name() ? ' — ' . $movement->member_name() : '' ) ) . '</td><td>' . esc_html( (string) $movement->membership_year() ) . '</td><td>' . esc_html( number_format_i18n( (float) $movement->amount(), 2 ) ) . '</td><td>' . esc_html( $movement->payment_date() ) . '</td><td>' . esc_html( $movement->payment_method() ) . '</td><td>' . esc_html( $financial_label ) . '</td><td>' . esc_html( $google_label ) . '</td><td><code>' . esc_html( $movement->movement_id() ) . '</code></td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(\'Tem a certeza de que pretende eliminar este movimento financeiro? Esta ação remove também o respetivo registo do Google Sheets e não pode ser anulada.\');"><input type="hidden" name="action" value="adam_membership_delete_financial_movement"><input type="hidden" name="movement_id" value="' . esc_attr( $movement->movement_id() ) . '"><input type="hidden" name="redirect_to" value="' . esc_url( $redirect ) . '">';
			wp_nonce_field( 'adam_membership_delete_financial_movement_' . $movement->movement_id() );
			echo '<button type="submit" class="button button-link-delete adam-button adam-button--danger">Eliminar</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Render the optional private billing-document controls for one request. */
	private function render_private_document_panel( string $type, int $id, string $reference, string $redirect ): void {
		$document = $this->private_documents->find_active( $reference );
		$nonce    = 'adam_membership_private_document_' . $type . '_' . $id;
		?>
		<div class="adam-admin-panel adam-card adam-private-document-panel">
			<h2><?php esc_html_e( 'Documento de faturação/recibo (opcional)', 'adam-membership' ); ?></h2>
			<?php if ( 'registration' === $type ) : $registration_documents = $this->private_documents->for_references( array( $reference ) ); $registration_documents = array_values( array_filter( $registration_documents, static fn ( \AdamMembership\Document\PrivateDocument $item ): bool => 'active' === $item->document_status() ) ); ?>
				<?php if ( $registration_documents ) : ?><div class="adam-registration-private-documents"><p><strong><?php esc_html_e( 'Documentos privados submetidos:', 'adam-membership' ); ?></strong></p><?php foreach ( $registration_documents as $registration_document ) : ?><p><?php echo esc_html( $registration_document->original_name() ); ?> — <a href="<?php echo esc_url( $this->private_document_download_url( $registration_document->id() ) ); ?>"><?php esc_html_e( 'Descarregar', 'adam-membership' ); ?></a></p><?php endforeach; ?></div><?php endif; ?>
			<?php endif; ?>
			<?php if ( null === $document ) : ?>
				<p><?php esc_html_e( 'Sem documento — a aprovação será enviada sem anexo.', 'adam-membership' ); ?></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Documento associado:', 'adam-membership' ); ?></strong> <?php echo esc_html( $document->original_name() ); ?></p>
				<p><strong><?php esc_html_e( 'Documento:', 'adam-membership' ); ?></strong> <?php echo esc_html( $this->private_document_status_label( $document->document_status() ) ); ?></p>
				<p><strong><?php esc_html_e( 'Último envio:', 'adam-membership' ); ?></strong> <?php echo esc_html( $this->private_document_send_status_label( $document->send_status() ) ); ?></p>
				<p><a class="button button-small" href="<?php echo esc_url( $this->private_document_download_url( $document->id() ) ); ?>"><?php esc_html_e( 'Descarregar PDF', 'adam-membership' ); ?></a></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="adam_membership_private_document_action">
				<input type="hidden" name="private_document_action" value="<?php echo esc_attr( self::ACTION_PRIVATE_DOCUMENT_UPLOAD ); ?>">
				<input type="hidden" name="document_type" value="<?php echo esc_attr( $type ); ?>">
				<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $id ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
				<?php wp_nonce_field( $nonce ); ?>
				<label for="adam-private-document-<?php echo esc_attr( $type . '-' . $id ); ?>"><?php esc_html_e( 'Selecionar PDF', 'adam-membership' ); ?></label>
				<input id="adam-private-document-<?php echo esc_attr( $type . '-' . $id ); ?>" type="file" name="private_document_file" accept=".pdf,application/pdf" required>
				<button type="submit" class="button button-primary"><?php echo null === $document ? esc_html__( 'Carregar documento', 'adam-membership' ) : esc_html__( 'Substituir documento', 'adam-membership' ); ?></button>
			</form>
			<?php if ( 'renewal' === $type && null === $document ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
					<input type="hidden" name="action" value="adam_membership_renewal_action">
					<input type="hidden" name="renewal_action" value="<?php echo esc_attr( self::ACTION_RESEND_RENEWAL_EMAIL ); ?>">
					<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $id ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
					<?php wp_nonce_field( 'adam_membership_renewal_action_' . $id ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Reenviar email de confirmação', 'adam-membership' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( null !== $document ) : ?>
				<?php if ( 'registration' === $type ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
						<input type="hidden" name="action" value="adam_membership_member_action">
						<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_SEND_PRIVATE_DOCUMENT ); ?>">
						<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $id ); ?>">
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
						<?php wp_nonce_field( 'adam_membership_member_action_' . $id ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Enviar documento ao sócio', 'adam-membership' ); ?></button>
					</form>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
						<input type="hidden" name="action" value="adam_membership_renewal_action">
						<input type="hidden" name="renewal_action" value="<?php echo esc_attr( self::ACTION_SEND_PRIVATE_DOCUMENT ); ?>">
						<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $id ); ?>">
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
						<?php wp_nonce_field( 'adam_membership_renewal_action_' . $id ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Enviar documento ao sócio', 'adam-membership' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
						<input type="hidden" name="action" value="adam_membership_renewal_action">
						<input type="hidden" name="renewal_action" value="<?php echo esc_attr( self::ACTION_RESEND_RENEWAL_EMAIL ); ?>">
						<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $id ); ?>">
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
						<?php wp_nonce_field( 'adam_membership_renewal_action_' . $id ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Reenviar email de confirmação', 'adam-membership' ); ?></button>
					</form>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( null !== $document ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remover a associação deste documento? O histórico privado será preservado.', 'adam-membership' ) ); ?>');">
					<input type="hidden" name="action" value="adam_membership_private_document_action">
					<input type="hidden" name="private_document_action" value="<?php echo esc_attr( self::ACTION_PRIVATE_DOCUMENT_REMOVE ); ?>">
					<input type="hidden" name="document_type" value="<?php echo esc_attr( $type ); ?>">
					<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $id ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
					<?php wp_nonce_field( $nonce ); ?>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remover associação', 'adam-membership' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function private_document_download_url( int $document_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => 'adam_membership_download_private_document',
					'document_id' => $document_id,
				),
				admin_url( 'admin-post.php' )
			),
			'adam_membership_download_private_document_' . $document_id
		);
	}

	private function private_document_status_label( string $status ): string {
		return array(
			'active'     => __( 'Ativo', 'adam-membership' ),
			'archived'   => __( 'Arquivado', 'adam-membership' ),
			'orphaned'   => __( 'Órfão', 'adam-membership' ),
			'superseded' => __( 'Substituído', 'adam-membership' ),
		)[ $status ] ?? $status;
	}

	private function private_document_send_status_label( string $status ): string {
		return array(
			'not_sent' => __( 'Não enviado', 'adam-membership' ),
			'sent'     => __( 'Enviado', 'adam-membership' ),
			'failed'   => __( 'Falhou', 'adam-membership' ),
		)[ $status ] ?? $status;
	}

	/**
	 * Render approval-warning panel for missing documents.
	 *
	 * @param array<int, string> $warnings Warning messages.
	 * @param string             $intro Intro text.
	 */
	private function render_document_warning_panel( array $warnings, string $intro ): void {
		if ( array() === $warnings ) {
			return;
		}
		?>
		<div class="adam-admin-notice error adam-notice adam-notice--danger">
			<p><strong><?php echo esc_html( $intro ); ?></strong></p>
			<ul>
				<?php foreach ( $warnings as $warning ) : ?>
					<li><?php echo esc_html( $warning ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render replace/remove controls for a member document.
	 *
	 * @param Member $member Member.
	 * @param string $meta_key Meta key.
	 * @param bool   $has_document Whether the document currently exists.
	 */
	private function render_document_management_controls( Member $member, string $meta_key, bool $has_document ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="adam-admin-document-upload-form">
			<input type="hidden" name="action" value="adam_membership_member_action">
			<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REPLACE_DOCUMENT ); ?>">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
			<input type="hidden" name="document_field" value="<?php echo esc_attr( $meta_key ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
			<input type="file" name="member_document_file" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
			<button type="submit" class="button button-small button-primary"><?php echo esc_html( $has_document ? __( 'Substituir', 'adam-membership' ) : __( 'Carregar', 'adam-membership' ) ); ?></button>
		</form>
		<?php if ( $has_document ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remover este documento? Esta ação não pode ser anulada.', 'adam-membership' ) ); ?>');">
				<input type="hidden" name="action" value="adam_membership_member_action">
				<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REMOVE_DOCUMENT ); ?>">
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
				<input type="hidden" name="document_field" value="<?php echo esc_attr( $meta_key ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
				<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
				<button type="submit" class="button button-small button-link-delete adam-button adam-button--danger"><?php esc_html_e( 'Remover', 'adam-membership' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render replace/remove controls for a renewal document.
	 *
	 * @param RenewalRequest $request Renewal request.
	 * @param string         $meta_key Meta key.
	 * @param bool           $has_document Whether the document currently exists.
	 */
	private function render_renewal_document_management_controls( RenewalRequest $request, string $meta_key, bool $has_document ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="adam-admin-document-upload-form">
			<input type="hidden" name="action" value="adam_membership_renewal_action">
			<input type="hidden" name="renewal_action" value="<?php echo esc_attr( self::ACTION_REPLACE_RENEWAL_DOCUMENT ); ?>">
			<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>">
			<input type="hidden" name="document_field" value="<?php echo esc_attr( $meta_key ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->renewal_url( $request ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_renewal_action_' . $request->id() ); ?>
			<input type="file" name="member_document_file" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
			<button type="submit" class="button button-small button-primary"><?php echo esc_html( $has_document ? __( 'Substituir', 'adam-membership' ) : __( 'Carregar', 'adam-membership' ) ); ?></button>
		</form>
		<?php if ( $has_document ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Remover este documento? Esta ação não pode ser anulada.', 'adam-membership' ) ); ?>');">
			<input type="hidden" name="action" value="adam_membership_renewal_action">
			<input type="hidden" name="renewal_action" value="<?php echo esc_attr( self::ACTION_REMOVE_RENEWAL_DOCUMENT ); ?>">
			<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>">
			<input type="hidden" name="document_field" value="<?php echo esc_attr( $meta_key ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->renewal_url( $request ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_renewal_action_' . $request->id() ); ?>
			<button type="submit" class="button button-small button-link-delete adam-button adam-button--danger"><?php esc_html_e( 'Remover', 'adam-membership' ); ?></button>
		</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Build missing-document warnings for a renewal request.
	 *
	 * @param RenewalRequest $request Renewal request.
	 * @return array<int, string>
	 */
	private function missing_renewal_document_warnings( RenewalRequest $request ): array {
		$warnings      = array();
		$submitted     = $request->submitted_data();
		$renewal_mode  = isset( $submitted['adam_membership_origin'] ) && 'external_association' === (string) $submitted['adam_membership_origin'] ? 'external_association' : 'adam_primary';

		foreach ( $this->document_field_definitions( 'renewal' ) as $definition ) {
			if ( ! $this->document_condition_required( (string) $definition['conditional'], $renewal_mode, true ) || empty( $definition['required'] ) ) {
				continue;
			}

			$field_key = (string) $definition['field_key'];
			$value     = 'payment_receipt' === $field_key ? $request->proof_of_payment() : ( $submitted[ (string) $definition['meta_key'] ] ?? '' );

			if ( '' === $this->media_reference_url( $value ) ) {
				$warnings[] = sprintf(
					/* translators: %s: document label */
					__( '%s: em falta', 'adam-membership' ),
					(string) $definition['label']
				);
			}
		}

		return $warnings;
	}

	/**
	 * Resolve whether a conditional file field is required for the current flow.
	 *
	 * @param string $condition Condition key.
	 * @param string $association_mode Membership mode.
	 * @param bool   $profile_changed Renewal profile-change toggle.
	 */
	private function document_condition_required( string $condition, string $association_mode, bool $profile_changed ): bool {
		return match ( $condition ) {
			'registration_external', 'renewal_external' => 'external_association' === $association_mode,
			'renewal_profile'                            => $profile_changed,
			default                                      => true,
		};
	}

	/**
	 * Resolve the stored document key for one configured form field.
	 *
	 * @param string $field_key Form field key.
	 */
	private function document_meta_key( string $field_key ): string {
		return match ( $field_key ) {
			'external_association_proof' => 'adam_external_association_proof',
			default                      => $field_key,
		};
	}

	/**
	 * Resolve a media reference to a public URL.
	 *
	 * @param mixed $value Media reference.
	 */
	private function media_reference_url( mixed $value ): string {
		if ( is_string( $value ) && str_starts_with( $value, 'private:' ) ) {
			$id = absint( substr( $value, 8 ) );
			$document = $id > 0 ? $this->private_documents->find( $id ) : null;
			return null !== $document && 'active' === $document->document_status() ? $this->private_document_download_url( $id ) : '';
		}
		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_url( absint( $value ) );

			return false !== $url ? $url : '';
		}

		if ( is_string( $value ) && '' !== trim( $value ) && wp_http_validate_url( trim( $value ) ) ) {
			return trim( $value );
		}

		return '';
	}

	/**
	 * Resolve display datetime for a media reference.
	 *
	 * @param mixed       $value Media reference.
	 * @param string|null $fallback Fallback datetime.
	 */
	private function media_reference_datetime( mixed $value, ?string $fallback = null ): string {
		if ( is_string( $value ) && str_starts_with( $value, 'private:' ) ) {
			$id = absint( substr( $value, 8 ) );
			$document = $id > 0 ? $this->private_documents->find( $id ) : null;
			return null !== $document ? $this->format_datetime( $document->created_at() ) : '';
		}
		if ( is_numeric( $value ) ) {
			$post = get_post( absint( $value ) );

			if ( $post instanceof \WP_Post ) {
				return $this->format_datetime( (string) $post->post_date );
			}
		}

		return is_string( $fallback ) ? $this->format_datetime( $fallback ) : '';
	}

	/**
	 * Resolve a compact display filename for a media reference.
	 *
	 * @param mixed $value Media reference.
	 */
	private function media_reference_filename( mixed $value ): string {
		if ( is_string( $value ) && str_starts_with( $value, 'private:' ) ) {
			$id = absint( substr( $value, 8 ) );
			$document = $id > 0 ? $this->private_documents->find( $id ) : null;
			return null !== $document ? sanitize_file_name( $document->original_name() ) : '';
		}
		if ( is_numeric( $value ) ) {
			$file = get_attached_file( absint( $value ) );

			if ( is_string( $file ) && '' !== $file ) {
				return wp_basename( $file );
			}
		}

		$url = $this->media_reference_url( $value );

		if ( '' === $url ) {
			return '';
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		return is_string( $path ) ? sanitize_file_name( rawurldecode( wp_basename( $path ) ) ) : '';
	}

	/**
	 * Render a compact preview cell for one uploaded document.
	 *
	 * @param string $fallback_alt Fallback alt text.
	 * @param mixed  $value Media reference.
	 * @param string $meta_key Meta key.
	 */
	private function document_preview_html( string $fallback_alt, mixed $value, string $meta_key ): string {
		$url = $this->media_reference_url( $value );

		if ( '' === $url ) {
			return '<span class="adam-admin-document-preview adam-admin-document-preview--empty" aria-label="' . esc_attr__( 'Sem ficheiro', 'adam-membership' ) . '"><span class="dashicons dashicons-minus" aria-hidden="true"></span></span>';
		}

		if ( is_numeric( $value ) && wp_attachment_is_image( absint( $value ) ) ) {
			$image = wp_get_attachment_image( absint( $value ), array( 64, 64 ), false, array( 'class' => 'adam-admin-document-thumbnail', 'alt' => $fallback_alt ) );

			if ( is_string( $image ) && '' !== $image ) {
				return sprintf(
					'<a class="adam-admin-document-preview" href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s">%3$s</a>',
					esc_url( $url ),
					esc_attr__( 'Abrir imagem completa', 'adam-membership' ),
					$image
				);
			}
		}

		$extension = strtoupper( (string) pathinfo( $url, PATHINFO_EXTENSION ) );
		$is_pdf    = 'PDF' === $extension;
		$icon      = $is_pdf ? 'dashicons-pdf' : 'dashicons-media-default';
		$label     = '' !== $extension ? $extension : strtoupper( sanitize_text_field( $meta_key ) );

		return sprintf(
			'<a class="adam-admin-document-preview adam-admin-document-preview--file" href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s"><span class="dashicons %3$s" aria-hidden="true"></span><small>%4$s</small></a>',
			esc_url( $url ),
			esc_attr__( 'Abrir documento', 'adam-membership' ),
			esc_attr( $icon ),
			esc_html( substr( $label, 0, 4 ) )
		);
	}

	/**
	 * Render a member profile photo.
	 *
	 * @param Member $member Member model.
	 */
	private function render_profile_photo( Member $member ): void {
		$photo_url = $member->media_url( 'profile_photo' );

		if ( '' === $photo_url ) {
			echo '<span class="adam-admin-avatar-placeholder">AD</span>';
			return;
		}

		printf(
			'<img class="adam-admin-avatar" src="%1$s" alt="%2$s" />',
			esc_url( $photo_url ),
			esc_attr( $member->full_name() )
		);
	}

	/**
	 * Render an inline row action form.
	 *
	 * @param Member $member Member.
	 * @param string $action Action.
	 * @param string $label  Button label.
	 * @param string $class  Button class.
	 */
	private function render_inline_action_form( Member $member, string $action, string $label, string $class ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
			<input type="hidden" name="action" value="adam_membership_member_action">
			<input type="hidden" name="member_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
			<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
			<button type="submit" class="button button-small <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render an inline rejection form with a reason selector.
	 *
	 * @param Member $member Member.
	 */
	private function render_inline_rejection_form( Member $member ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form adam-admin-rejection-inline">
			<input type="hidden" name="action" value="adam_membership_member_action">
			<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REJECT ); ?>">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
			<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
			<label class="screen-reader-text" for="adam_rejection_reason_<?php echo esc_attr( (string) $member->user_id() ); ?>"><?php esc_html_e( 'Motivo da rejeição', 'adam-membership' ); ?></label>
			<select id="adam_rejection_reason_<?php echo esc_attr( (string) $member->user_id() ); ?>" name="rejection_reason" required>
				<option value=""><?php esc_html_e( 'Reason', 'adam-membership' ); ?></option>
				<?php foreach ( $this->rejection_reasons() as $reason ) : ?>
					<option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button button-small button-link-delete adam-button adam-button--danger"><?php esc_html_e( 'Reject', 'adam-membership' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render a full-width member action form.
	 *
	 * @param Member $member Member.
	 * @param string $action Action.
	 * @param string $label  Button label.
	 * @param string $class  Button class.
	 */
	private function render_action_form( Member $member, string $action, string $label, string $class ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adam_membership_member_action">
			<input type="hidden" name="member_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
			<button type="submit" class="button <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render a renewal action form.
	 *
	 * @param RenewalRequest $request Request.
	 * @param string         $action  Action.
	 * @param string         $label   Label.
	 * @param string         $class   Button class.
	 */
	private function render_renewal_action_form( RenewalRequest $request, string $action, string $label, string $class ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adam_membership_renewal_action">
			<input type="hidden" name="renewal_action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->renewal_url( $request ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_renewal_action_' . $request->id() ); ?>
			<button type="submit" class="button <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render renewal rejection form.
	 *
	 * @param RenewalRequest $request Request.
	 */
	private function render_renewal_rejection_form( RenewalRequest $request ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-rejection-form">
			<input type="hidden" name="action" value="adam_membership_renewal_action">
			<input type="hidden" name="renewal_action" value="<?php echo esc_attr( self::ACTION_REJECT_RENEWAL ); ?>">
			<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->renewal_url( $request ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_renewal_action_' . $request->id() ); ?>
			<label>
				<span><?php esc_html_e( 'Motivo da rejeição', 'adam-membership' ); ?></span>
				<select name="rejection_reason" required>
					<option value=""><?php esc_html_e( 'Select a reason', 'adam-membership' ); ?></option>
					<?php foreach ( $this->rejection_reasons() as $reason ) : ?>
						<option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button button-link-delete adam-button adam-button--danger"><?php esc_html_e( 'Rejeitar renovação', 'adam-membership' ); ?></button>
		</form>
		<?php
	}

	private function render_registration_correction_selector( Member $member ): void {
		$fields = CorrectionFieldCatalog::definitions( $this->settings->membership_form_settings() );
		$external = 'external_association' === (string) $member->field( 'adam_membership_origin' );
		$groups = array(
			'Informação pessoal' => array( 'full_name', 'birth_date', 'marital_status', 'gender', 'profession', 'birthplace', 'nationality' ),
			'Contacto e morada' => array( 'email', 'phone', 'telephone', 'address_line_1', 'address_line_2', 'city', 'municipality', 'postcode', 'country' ),
			'Identificação' => array( 'citizen_card', 'document_expiry_date', 'document_issuing_place', 'nif' ),
			'Associação / equipa' => array( 'team' ),
			'Documentos' => array( 'profile_photo', 'payment_receipt' ),
		);
		if ( $external ) { $groups['Associação / equipa'][] = 'external_association_name'; $groups['Associação / equipa'][] = 'external_member_number'; $groups['Documentos'][] = 'external_association_proof'; }
		$available = array();
		foreach ( $groups as $group_keys ) { foreach ( $group_keys as $key ) { if ( 'profile_photo' === $key || ( isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) && ! empty( $fields[ $key ]['enabled'] ) ) ) { $available[ $key ] = $fields[ $key ] ?? array( 'label' => DisplayLabels::field( $key ) ); } } }
		?>
		<div class="adam-admin-rejection-form adam-admin-correction-form" data-adam-correction-selector>
			<h3><?php esc_html_e( 'Pedir correção', 'adam-membership' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_member_action"><input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REQUEST_CORRECTION ); ?>"><input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>"><input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>"><?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
				<label><span><?php esc_html_e( 'Motivo da correção', 'adam-membership' ); ?></span><select name="correction_reason" required><option value=""><?php esc_html_e( 'Selecionar', 'adam-membership' ); ?></option><?php foreach ( $this->correction_reasons() as $reason ) : ?><option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option><?php endforeach; ?></select></label>
				<div class="adam-correction-field-picker"><span><?php esc_html_e( 'Campos a corrigir', 'adam-membership' ); ?></span><button type="button" class="button adam-correction-field-picker__trigger" data-adam-correction-open><?php esc_html_e( 'Selecionar campos...', 'adam-membership' ); ?></button><div class="adam-correction-field-picker__summary" data-adam-correction-summary hidden><strong data-adam-correction-count></strong><div data-adam-correction-chips></div><button type="button" class="button-link" data-adam-correction-open><?php esc_html_e( 'Alterar seleção', 'adam-membership' ); ?></button></div></div>
				<dialog class="adam-admin-correction-dialog" data-adam-correction-dialog><div class="adam-admin-correction-dialog__header"><h2><?php esc_html_e( 'Campos a corrigir', 'adam-membership' ); ?></h2><button type="button" class="button-link" data-adam-correction-close aria-label="Fechar">&times;</button></div><p><?php esc_html_e( 'Selecione apenas a informação ou os documentos que o candidato deve corrigir.', 'adam-membership' ); ?></p><div class="adam-admin-correction-dialog__groups">
				<?php foreach ( $groups as $group_label => $group_keys ) : ?><fieldset><legend><?php echo esc_html( $group_label ); ?></legend><?php foreach ( $group_keys as $key ) : if ( ! isset( $available[ $key ] ) ) { continue; } $label = (string) ( $available[ $key ]['label'] ?? DisplayLabels::field( $key ) ); ?><label class="adam-admin-correction-option"><input type="checkbox" name="correction_fields[]" value="<?php echo esc_attr( $key ); ?>" data-adam-correction-option data-label="<?php echo esc_attr( $label ); ?>"><span><?php echo esc_html( $label ); ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?></div><div class="adam-admin-correction-dialog__actions"><button type="button" class="button" data-adam-correction-close><?php esc_html_e( 'Cancelar', 'adam-membership' ); ?></button><button type="button" class="button button-primary" data-adam-correction-apply><?php esc_html_e( 'Aplicar seleção', 'adam-membership' ); ?></button></div></dialog>
				<label><span><?php esc_html_e( 'O que precisa de corrigir', 'adam-membership' ); ?></span><textarea name="correction_note" rows="4"></textarea></label><button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Pedir correção', 'adam-membership' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function render_registration_correction_form( Member $member ): void {
		$fields = (array) ( $this->settings->membership_form_settings()['registration_fields'] ?? array() );
		?>
		<div class="adam-admin-rejection-form adam-admin-correction-form">
			<h3><?php esc_html_e( 'Pedir correção', 'adam-membership' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_member_action">
				<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REQUEST_CORRECTION ); ?>">
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
				<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
				<label><span><?php esc_html_e( 'Motivo da correção', 'adam-membership' ); ?></span>
					<select name="correction_reason" required><option value=""><?php esc_html_e( 'Selecionar', 'adam-membership' ); ?></option>
					<?php foreach ( $this->correction_reasons() as $reason ) : ?><option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option><?php endforeach; ?>
					</select>
				</label>
				<fieldset><legend><?php esc_html_e( 'Campos a corrigir', 'adam-membership' ); ?></legend>
					<?php foreach ( $fields as $key => $field ) : if ( ! is_array( $field ) || empty( $field['enabled'] ) || in_array( $key, array( 'profile_photo', 'external_association_proof' ), true ) ) { continue; } ?><label><input type="checkbox" name="correction_fields[]" value="<?php echo esc_attr( $key ); ?>"> <?php echo esc_html( $field['label'] ?? DisplayLabels::field( (string) $key ) ); ?></label><?php endforeach; ?>
					<label><input type="checkbox" name="correction_fields[]" value="profile_photo"> <?php esc_html_e( 'Fotografia', 'adam-membership' ); ?></label>
					<label><input type="checkbox" name="correction_fields[]" value="external_association_proof"> <?php esc_html_e( 'Comprovativo de Associação', 'adam-membership' ); ?></label>
				</fieldset>
				<label><span><?php esc_html_e( 'O que precisa de corrigir', 'adam-membership' ); ?></span><textarea name="correction_note" rows="4"></textarea></label>
				<button type="submit" class="button button-primary adam-button"><?php esc_html_e( 'Pedir correção', 'adam-membership' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function render_correction_fields_form( Member $member ): void {
		$fields = (array) ( $this->settings->membership_form_settings()['registration_fields'] ?? array() );
		?><details class="adam-admin-rejection-form"><summary class="button">Selecionar campos a corrigir</summary><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="adam_membership_member_action"><input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REQUEST_CORRECTION ); ?>"><input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>"><input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>"><?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?><label>Motivo da correção<select name="rejection_reason" required><option value="">Selecionar</option><?php foreach ( $this->rejection_reasons() as $reason ) : ?><option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option><?php endforeach; ?></select></label><label>O que precisa de corrigir<textarea name="rejection_note" rows="3"></textarea></label><fieldset><legend>Campos a corrigir</legend><p><button type="button" class="button" onclick="this.closest('fieldset').querySelectorAll('input[type=checkbox]').forEach(function(x){x.checked=true})">Selecionar todos</button> <button type="button" class="button" onclick="this.closest('fieldset').querySelectorAll('input[type=checkbox]').forEach(function(x){x.checked=false})">Limpar seleção</button></p><?php foreach ( $fields as $key => $field ) : ?><label><input type="checkbox" name="correction_fields[]" value="<?php echo esc_attr( $key ); ?>"> <?php echo esc_html( $field['label'] ?? $key ); ?></label><br><?php endforeach; ?><label><input type="checkbox" name="correction_fields[]" value="profile_photo"> Fotografia</label></fieldset><button type="submit" class="button button-primary">Pedir correção</button></form></details><?php
	}

	private function render_correction_request_form( Member $member ): void {
		$form_fields = (array) ( $this->settings->membership_form_settings()['registration_fields'] ?? array() );
		?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-rejection-form"><input type="hidden" name="action" value="adam_membership_member_action"><input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REQUEST_CORRECTION ); ?>"><input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>"><input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>"><?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?><label><span>Motivo da correção</span><select name="rejection_reason" required><option value="">Selecionar</option><?php foreach ( $this->rejection_reasons() as $reason ) : ?><option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option><?php endforeach; ?></select></label><label><span>O que precisa de corrigir</span><textarea name="rejection_note" rows="3"></textarea></label><button type="submit" class="button button-primary adam-button">Pedir correção</button></form><?php
	}

	/**
	 * Render the detailed rejection form.
	 *
	 * @param Member $member Member.
	 */
	private function render_rejection_form( Member $member ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-rejection-form">
			<input type="hidden" name="action" value="adam_membership_member_action">
			<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REJECT ); ?>">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
			<label>
				<span><?php esc_html_e( 'Motivo da rejeição', 'adam-membership' ); ?></span>
				<select name="rejection_reason" required>
					<option value=""><?php esc_html_e( 'Select a reason', 'adam-membership' ); ?></option>
					<?php foreach ( $this->rejection_reasons() as $reason ) : ?>
						<option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Nota privada da administração', 'adam-membership' ); ?></span>
				<textarea name="rejection_note" rows="3"></textarea>
			</label>
			<button type="submit" class="button button-link-delete adam-button adam-button--danger"><?php esc_html_e( 'Rejeitar sócio', 'adam-membership' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render a detail item.
	 *
	 * @param string $label Detail label.
	 * @param string $value Detail value.
	 */
	private function render_detail_item( string $label, string $value ): void {
		?>
		<div class="adam-admin-detail-item">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( '' !== $value ? $value : '—' ); ?></strong>
		</div>
		<?php
	}

	/**
	 * Prime the current admin screen with a guaranteed non-empty page title.
	 *
	 * WordPress builds the header title before the page callback runs. Hidden
	 * submenu routes can still reach admin-header.php with a null global title,
	 * so we provide a safe fallback on the page load hook.
	 *
	 * @param string $title Fallback page title.
	 */
	private function prime_admin_page_title( string $title ): void {
		$safe_title = trim( $title );

		if ( '' === $safe_title ) {
			$safe_title = __( 'ADAM Membership', 'adam-membership' );
		}

		$GLOBALS['title'] = $safe_title;

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null !== $screen && property_exists( $screen, 'title' ) ) {
			$screen->title = $safe_title;
		}
	}

	/**
	 * Determine whether the current request is the hidden member details page.
	 */
	private function is_member_page_request(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return self::MEMBER_PAGE_SLUG === $page;
	}

	/**
	 * Determine whether the current request is the hidden renewal review page.
	 */
	private function is_renewal_page_request(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return self::RENEWAL_PAGE_SLUG === $page;
	}

	/**
	 * Determine whether the current request is the hidden team details page.
	 */
	private function is_team_page_request(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return self::TEAM_PAGE_SLUG === $page;
	}

	private function is_apd_page_request(): bool {
		return self::APD_PAGE_SLUG === (string) ( $_GET['page'] ?? '' );
	}

	private function is_member_changes_page_request(): bool {
		return self::MEMBER_CHANGES_PAGE_SLUG === (string) ( $_GET['page'] ?? '' );
	}

	/**
	 * Render admin-only member diagnostics for status integrity debugging.
	 *
	 * @param Member $member Member.
	 */
	private function render_member_diagnostics( Member $member ): void {
		$rows = $this->member_diagnostic_rows( $member );
		?>
		<div class="adam-admin-panel adam-card">
			<h2><?php esc_html_e( 'Diagnóstico de estado', 'adam-membership' ); ?></h2>
			<p><?php esc_html_e( 'Fonte única de verdade: o estado do sócio é guardado no campo "estado", a validade da quota é guardada em "validade_quota" e os ecrãs de frontend/admin devem ler o modelo Member para obter valores normalizados e o estado efetivo.', 'adam-membership' ); ?></p>
			<table class="widefat striped adam-admin-table adam-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Verificação', 'adam-membership' ); ?></th>
						<th><?php esc_html_e( 'Valor', 'adam-membership' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $label => $value ) : ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><code><?php echo esc_html( '' !== $value ? $value : '(empty)' ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Build member diagnostics for status and quota debugging.
	 *
	 * @param Member $member Member.
	 * @return array<string, string>
	 */
	private function member_diagnostic_rows( Member $member ): array {
		$user  = $member->user();
		$roles = $user instanceof \WP_User ? implode( ', ', array_map( 'strval', $user->roles ) ) : '';

		$rows = array(
			'stored status meta (estado)'              => $this->debug_user_meta_value( $member->user_id(), 'estado' ),
			'normalized member status'                 => $member->status(),
			'effective display status'                 => $member->effective_status(),
			'stored quota meta (validade_quota)'       => $this->debug_user_meta_value( $member->user_id(), 'validade_quota' ),
			'normalized quota date'                    => (string) $member->field( 'validade_quota' ),
			'quota lifecycle status'                   => $member->quota_status(),
			'quota expiry timestamp'                   => (string) $member->quota_expiry_timestamp(),
			'stored join date meta (data_adesao)'      => $this->debug_user_meta_value( $member->user_id(), 'data_adesao' ),
			'normalized join date'                     => (string) $member->field( 'data_adesao' ),
			'stored member number meta (numero_socio)' => $this->debug_user_meta_value( $member->user_id(), 'numero_socio' ),
			'stored rejection reason meta'             => $this->debug_user_meta_value( $member->user_id(), 'motivo_rejeicao' ),
			'stored rejection note meta'               => $this->debug_user_meta_value( $member->user_id(), 'nota_rejeicao_admin' ),
			'user roles'                               => $roles,
			'user can manage_options'                  => $this->member_has_admin_access( $member ) ? 'sim' : 'não',
			'condição de expiração automática na manutenção' => Member::STATUS_ACTIVE === $member->status() ? 'elegível quando a data da quota já passou' : 'não elegível a menos que o estado guardado seja Ativo',
			'caminho de leitura usado pelos ecrãs admin/área do sócio' => 'Member::effective_status() + Member::field()',
			'caminho de escrita usado pelo formulário de edição admin' => 'AdminController::save_member_fields() -> Member::save()',
		);

		foreach ( $this->diagnostic_meta_keys() as $meta_key ) {
			$rows[ 'raw meta: ' . $meta_key ] = $this->debug_user_meta_value( $member->user_id(), $meta_key );
		}

		return $rows;
	}

	/**
	 * Get the member meta keys that commonly drift in legacy status bugs.
	 *
	 * @return array<int, string>
	 */
	private function diagnostic_meta_keys(): array {
		return array(
			'status',
			'membership_status',
			'_membership_status',
			'adam_status',
			'_adam_status',
			'member_status',
			'quota_valid_until',
			'quota_expiry',
			'valid_until',
			'expires_at',
			'approval_status',
			'renewal_status',
		);
	}

	/**
	 * Get a raw user meta value for diagnostics.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key.
	 */
	private function debug_user_meta_value( int $user_id, string $meta_key ): string {
		$value = get_user_meta( $user_id, $meta_key, true );

		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value );

			return false !== $encoded ? $encoded : '[complex value]';
		}

		if ( null === $value ) {
			return '';
		}

		return trim( (string) $value );
	}

	/**
	 * Get a display label for a member number.
	 *
	 * @param Member $member Member.
	 */
	private function member_number_label( Member $member ): string {
		$member_number = trim( (string) $member->field( 'numero_socio' ) );

		return '' !== $member_number ? $member_number : __( 'Em falta / por atribuir', 'adam-membership' );
	}

	/**
	 * Render an empty state.
	 *
	 * @param string $message Empty state message.
	 */
	private function render_empty_state( string $message ): void {
		printf( '<div class="adam-admin-empty">%s</div>', esc_html( $message ) );
	}

	/**
	 * Render page header markup.
	 *
	 * @param string $title Page title.
	 */
	private function render_header( string $title ): void {
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar">
				<h1><?php echo esc_html( $title ); ?></h1>
			</div>
		<?php
	}

	/**
	 * Render page footer markup.
	 */
	private function render_footer(): void {
		?>
		</div>
		<?php
	}

	/**
	 * Render admin notices from redirects.
	 */
	private function render_notices(): void {
		$message = isset( $_GET['adam_message'] ) ? sanitize_text_field( wp_unslash( $_GET['adam_message'] ) ) : '';
		$error   = isset( $_GET['adam_error'] ) ? sanitize_text_field( wp_unslash( $_GET['adam_error'] ) ) : '';

		if ( '' !== $message ) {
			printf( '<div class="adam-admin-notice success adam-notice adam-notice--success"><p>%s</p></div>', esc_html( $message ) );
		}

		if ( '' !== $error ) {
			printf( '<div class="adam-admin-notice error adam-notice adam-notice--danger"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	/**
	 * Record an admin-side member history entry.
	 *
	 * @param Member               $member      Member.
	 * @param string               $action_key  Action key.
	 * @param string               $action_label Action label.
	 * @param string               $description Description.
	 * @param array<string, mixed> $details     Details payload.
	 */
	private function record_admin_member_history( Member $member, string $action_key, string $action_label, string $description, array $details ): void {
		$admin = wp_get_current_user();

		$this->history_repository->create(
			array(
				'member_id'     => $member->user_id(),
				'member_number' => sanitize_text_field( (string) $member->field( 'numero_socio' ) ),
				'member_name'   => sanitize_text_field( $member->full_name() ),
				'member_email'  => sanitize_email( $member->email() ),
				'action_key'    => sanitize_key( $action_key ),
				'action_label'  => sanitize_text_field( $action_label ),
				'actor_type'    => 'admin',
				'actor_id'      => get_current_user_id(),
				'actor_name'    => $admin->exists() ? sanitize_text_field( $admin->display_name ) : __( 'Administrador', 'adam-membership' ),
				'description'   => sanitize_text_field( $description ),
				'details'       => $this->sanitize_history_details( $details ),
				'created_at'    => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);
	}

	/**
	 * Build a member details URL.
	 *
	 * @param Member $member Member.
	 */
	private function member_url( Member $member ): string {
		return add_query_arg(
			array(
				'page'      => self::MEMBER_PAGE_SLUG,
				'member_id' => $member->user_id(),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a team details URL.
	 *
	 * @param Team $team Team.
	 */
	private function team_url( Team $team ): string {
		return add_query_arg(
			array(
				'page'    => self::TEAM_PAGE_SLUG,
				'team_id' => $team->id(),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a renewal request review URL.
	 *
	 * @param RenewalRequest $request Request.
	 */
	private function renewal_url( RenewalRequest $request ): string {
		return add_query_arg(
			array(
				'page'       => self::RENEWAL_PAGE_SLUG,
				'request_id' => $request->id(),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Get a human-readable renewal status label.
	 *
	 * @param string $status Status.
	 */
	private function renewal_status_label( string $status ): string {
		return match ( $status ) {
			RenewalRequest::STATUS_APPROVED => __( 'Aprovado', 'adam-membership' ),
			RenewalRequest::STATUS_REJECTED => __( 'Rejeitado', 'adam-membership' ),
			RenewalRequest::STATUS_CORRECTION_REQUESTED => __( 'Correção solicitada', 'adam-membership' ),
			RenewalRequest::STATUS_CORRECTION_SUBMITTED => __( 'Correção submetida', 'adam-membership' ),
			default                         => __( 'Pendente de revisão', 'adam-membership' ),
		};
	}

	/**
	 * Get a history actor label.
	 *
	 * @param string $actor_type Actor type key.
	 */
	private function history_actor_label( string $actor_type ): string {
		return match ( $actor_type ) {
			'admin'  => __( 'Admin', 'adam-membership' ),
			'member' => __( 'Member', 'adam-membership' ),
			default  => __( 'Sistema', 'adam-membership' ),
		};
	}

	/**
	 * Format history metadata labels.
	 *
	 * @param string $key Metadata key.
	 */
	private function history_meta_label( string $key ): string {
		$label = str_replace( '_', ' ', $key );

		return ucwords( trim( $label ) );
	}

	/**
	 * Format a history metadata value.
	 *
	 * @param mixed $value Metadata value.
	 */
	private function history_meta_value( mixed $value ): string {
		if ( is_array( $value ) ) {
			$parts = array();

			foreach ( $value as $key => $item ) {
				$parts[] = $this->history_meta_label( (string) $key ) . ': ' . $this->history_meta_value( $item );
			}

			return implode( ' | ', $parts );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'adam-membership' ) : __( 'No', 'adam-membership' );
		}

		return '' !== trim( (string) $value ) ? (string) $value : '—';
	}

	/**
	 * Sanitize structured history details.
	 *
	 * @param array<string, mixed> $details Detail payload.
	 * @return array<string, mixed>
	 */
	private function sanitize_history_details( array $details ): array {
		$sanitized = array();

		foreach ( $details as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_history_details( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			$sanitized[ $key ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Get posted quota validity.
	 */
	private function posted_quota_validity(): string {
		return isset( $_POST['quota_validity'] ) ? sanitize_text_field( wp_unslash( $_POST['quota_validity'] ) ) : '';
	}

	/**
	 * Get the posted rejection reason.
	 */
	private function posted_rejection_reason(): string {
		$reason = isset( $_POST['rejection_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['rejection_reason'] ) ) : '';

		return in_array( $reason, $this->rejection_reasons(), true ) ? $reason : '';
	}

	/**
	 * Get the posted private rejection note.
	 */
	private function posted_rejection_note(): string {
		return isset( $_POST['rejection_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rejection_note'] ) ) : '';
	}

	private function posted_correction_reason(): string {
		$reason = isset( $_POST['correction_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['correction_reason'] ) ) : '';

		return in_array( $reason, $this->correction_reasons(), true ) ? $reason : '';
	}

	private function posted_correction_note(): string {
		return isset( $_POST['correction_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['correction_note'] ) ) : '';
	}

	/**
	 * Get allowed rejection reasons.
	 *
	 * @return array<int, string>
	 */
	private function rejection_reasons(): array {
		return array(
			__( 'Dados incompletos', 'adam-membership' ),
			__( 'Não cumpre os requisitos da associação', 'adam-membership' ),
			__( 'Informação inconsistente', 'adam-membership' ),
			__( 'Pedido duplicado', 'adam-membership' ),
			__( 'Outro', 'adam-membership' ),
		);
	}

	/** @return array<int, string> */
	private function correction_reasons(): array {
		return array(
			__( 'Dados incompletos', 'adam-membership' ),
			__( 'Dados incorretos', 'adam-membership' ),
			__( 'Documento inválido/ilegível', 'adam-membership' ),
			__( 'Fotografia inadequada', 'adam-membership' ),
			__( 'Comprovativo em falta/incorreto', 'adam-membership' ),
			__( 'Outro', 'adam-membership' ),
		);
	}

	/**
	 * Get action success message.
	 *
	 * @param string $action Action.
	 */
	private function action_success_message( string $action ): string {
		return match ( $action ) {
			self::ACTION_APPROVE      => __( 'Sócio aprovado com sucesso.', 'adam-membership' ),
			self::ACTION_REJECT       => __( 'Sócio rejeitado com sucesso.', 'adam-membership' ),
			self::ACTION_RENEW        => __( 'Quota renewed successfully.', 'adam-membership' ),
			self::ACTION_CHANGE_QUOTA => __( 'Validade da quota atualizada com sucesso.', 'adam-membership' ),
			self::ACTION_RESEND_EMAIL => __( 'Email de aprovação reenviado com sucesso.', 'adam-membership' ),
			self::ACTION_SEND_PRIVATE_DOCUMENT => __( 'Documento enviado ao sócio com sucesso.', 'adam-membership' ),
			self::ACTION_SAVE_MEMBER  => __( 'Member fields updated successfully.', 'adam-membership' ),
			self::ACTION_REGENERATE_CARD_TOKEN => __( 'Digital card validation token regenerated successfully.', 'adam-membership' ),
			self::ACTION_REPLACE_DOCUMENT => __( 'Documento carregado com sucesso.', 'adam-membership' ),
			self::ACTION_REMOVE_DOCUMENT  => __( 'Documento removido com sucesso.', 'adam-membership' ),
			self::ACTION_REMOVE_ANA       => __( 'Associação ANA removida com sucesso.', 'adam-membership' ),
			self::ACTION_REQUEST_CORRECTION => __( 'Pedido de correção enviado com sucesso.', 'adam-membership' ),
			default                   => __( 'Member updated successfully.', 'adam-membership' ),
		};
	}

	/** Get a safe label for the Google Sheets connection state. */
	private function google_sheets_status_label( string $status ): string {
		return match ( $status ) {
			'connected' => __( 'Ligação confirmada', 'adam-membership' ),
			'failed'    => __( 'Falhou — consulte a mensagem do teste', 'adam-membership' ),
			default     => __( 'Ainda não testada', 'adam-membership' ),
		};
	}

	/**
	 * Redirect with a success message.
	 *
	 * @param string $message Success message.
	 */
	private function redirect_with_message( string $message ): void {
		$this->redirect_with_notice( 'adam_message', $message );
	}

	/** Validate an admin nonce and return to the ADAM admin screen on failure. */
	private function verify_admin_nonce( string $action ): void {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			$this->redirect_with_error( __( 'A ação expirou ou não é válida. Tente novamente.', 'adam-membership' ) );
		}
	}

	/**
	 * Redirect with an error message.
	 *
	 * @param string $message Error message.
	 */
	private function redirect_with_error( string $message ): void {
		$this->redirect_with_notice( 'adam_error', $message );
	}

	/**
	 * Redirect after an admin action.
	 *
	 * @param string $key     Query argument key.
	 * @param string $message Notice message.
	 */
	private function redirect_with_notice( string $key, string $message ): void {
		$redirect_to = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
		$fallback    = admin_url( 'admin.php?page=adam-membership-pending' );

		wp_safe_redirect(
			add_query_arg(
				array(
					$key => $message,
				),
				wp_validate_redirect( $redirect_to, $fallback )
			)
		);
		exit;
	}

	/** Always return a document-history action to its member history screen. */
	private function redirect_document_history_error( string $redirect, string $message ): void {
		wp_safe_redirect( add_query_arg( array( 'adam_error' => $message ), wp_validate_redirect( $redirect, admin_url( 'admin.php?page=' . self::HISTORY_PAGE_SLUG ) ) ) );
		exit;
	}

	private function redirect_document_history_message( string $redirect, string $message ): void {
		wp_safe_redirect( add_query_arg( array( 'adam_message' => $message ), wp_validate_redirect( $redirect, admin_url( 'admin.php?page=' . self::HISTORY_PAGE_SLUG ) ) ) );
		exit;
	}

	/**
	 * Format a stored date.
	 *
	 * @param mixed $date Stored date.
	 */
	private function format_date( mixed $date ): string {
		if ( ! is_scalar( $date ) ) {
			return '';
		}

		$date = trim( (string) $date );

		if ( '' === $date ) {
			return '';
		}

		if ( preg_match( '/^\d{8}$/', $date ) ) {
			return substr( $date, 6, 2 ) . '/' . substr( $date, 4, 2 ) . '/' . substr( $date, 0, 4 );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return substr( $date, 8, 2 ) . '/' . substr( $date, 5, 2 ) . '/' . substr( $date, 0, 4 );
		}

		return $date;
	}

	/**
	 * Format a stored datetime.
	 *
	 * @param string $datetime Datetime string.
	 */
	private function format_datetime( string $datetime ): string {
		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return $datetime;
		}

		return wp_date( 'd/m/Y H:i', $timestamp );
	}

	/**
	 * Convert stored date to HTML date input value.
	 *
	 * @param mixed $date Stored date.
	 */
	private function date_input_value( mixed $date ): string {
		if ( ! is_scalar( $date ) ) {
			return '';
		}

		$date = trim( (string) $date );

		if ( preg_match( '/^\d{8}$/', $date ) ) {
			return substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	public function render_member_changes_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Sem permissão.', 'adam-membership' ) ); }
		$this->render_member_changes_page_review();
		return;
	}

	private function render_member_changes_page_review(): void {
		$requests = $this->member_changes->repository()->all();
		$requested_id = absint( $_GET['request_id'] ?? 0 );
		if ( $requested_id > 0 ) {
			$requests = array_values( array_filter( $requests, static fn( MemberChangeRequest $request ): bool => $request->id() === $requested_id ) );
		}
		$this->render_header( 'Pedidos de alteração de dados' );
		$this->render_notices();
		echo '<div class="adam-admin-panel adam-card"><h2>Pedidos de alteração de dados</h2><table class="widefat striped"><thead><tr><th>Sócio</th><th>Data</th><th>Diferenças</th><th>Estado</th><th>Ações</th></tr></thead><tbody>';
		foreach ( $requests as $request ) {
			$member = $this->members->find( $request->user_id() ); if ( null === $member ) { continue; }
			echo '<tr><td>' . esc_html( $member->full_name() ) . '</td><td>' . esc_html( $request->submitted_at() ) . '</td><td><table>';
			foreach ( $request->changes() as $field => $change ) { echo '<tr><td>' . esc_html( DisplayLabels::field( (string) $field ) ) . '</td><td>' . esc_html( DisplayLabels::value( (string) $field, $change['old'] ?? '' ) ) . '</td><td>→ ' . esc_html( DisplayLabels::value( (string) $field, $change['new'] ?? '' ) ) . '</td></tr>'; }
			echo '</table></td><td>' . esc_html( DisplayLabels::status( (string) $request->status() ) ) . '</td><td>';
			if ( in_array( $request->status(), array( MemberChangeRequest::STATUS_PENDING, MemberChangeRequest::STATUS_CORRECTION_SUBMITTED ), true ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'adam_member_change_' . $request->id() ); echo '<input type="hidden" name="action" value="adam_membership_member_change_action"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request->id() ) . '"><button class="button button-primary" name="decision" value="approve">Aprovar</button></form><details><summary class="button">Rejeitar</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'adam_member_change_' . $request->id() ); echo '<input type="hidden" name="action" value="adam_membership_member_change_action"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request->id() ) . '"><label>Motivo da rejeição<select name="rejection_reason" required><option value="">Selecionar</option><option>Informação incorreta</option><option>Informação incompleta</option><option>Documento inválido ou ilegível</option><option>Fotografia não cumpre os requisitos</option><option>Dados não correspondem aos documentos</option><option>Alteração não pode ser validada</option><option>Pedido duplicado</option><option>Outro motivo</option></select></label><label>Mensagem / observações<textarea name="rejection_note"></textarea></label><button class="button" name="decision" value="reject">Rejeitar pedido</button></form></details>';
			}
			if ( MemberChangeRequest::STATUS_PENDING === $request->status() ) { $this->render_member_change_correction_selector( $request ); }
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		$this->render_footer();
	}

	private function render_member_changes_page_clean(): void {
		$requests = $this->member_changes->repository()->all();
		$requested_id = absint( $_GET['request_id'] ?? 0 );
		if ( $requested_id > 0 ) {
			$requests = array_values( array_filter( $requests, static fn( MemberChangeRequest $request ): bool => $request->id() === $requested_id ) );
		}
		?><div class="wrap"><h1>Pedidos de alteração de dados</h1><table class="widefat striped"><thead><tr><th>Sócio</th><th>Data</th><th>Diferenças</th><th>Estado</th><th>Ações</th></tr></thead><tbody><?php foreach ( $requests as $request ) : $member = $this->members->find( $request->user_id() ); if ( null === $member ) { continue; } ?><tr><td><?php echo esc_html( $member->full_name() . ( $member->member_number() ? ' — ' . $member->member_number() : '' ) ); ?></td><td><?php echo esc_html( $request->submitted_at() ); ?></td><td><table><?php foreach ( $request->changes() as $field => $change ) : ?><tr><td><?php echo esc_html( DisplayLabels::field( (string) $field ) ); ?></td><td><?php echo esc_html( DisplayLabels::value( (string) $field, $change['old'] ?? '' ) ); ?></td><td>→ <?php echo esc_html( DisplayLabels::value( (string) $field, $change['new'] ?? '' ) ); ?></td></tr><?php endforeach; ?></table></td><td><?php echo esc_html( DisplayLabels::status( (string) $request->status() ) ); ?></td><td><?php if ( MemberChangeRequest::STATUS_PENDING === $request->status() ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"><?php wp_nonce_field( 'adam_member_change_' . $request->id() ); ?><input type="hidden" name="action" value="adam_membership_member_change_action"><input type="hidden" name="request_id" value="<?php echo esc_attr( $request->id() ); ?>"><button class="button button-primary" name="decision" value="approve">Aprovar</button> <button class="button" name="decision" value="reject">Rejeitar</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php
	}

	private function member_document_history_url( Member $member ): string {
		return add_query_arg( array( 'page' => self::MEMBER_DOCUMENT_HISTORY_PAGE_SLUG, 'member_id' => $member->user_id() ), admin_url( 'admin.php' ) );
	}

	/** Render the shared full member-information correction selector. */
	private function render_member_change_correction_selector( MemberChangeRequest $request ): void {
		$definitions = CorrectionFieldCatalog::definitions( $this->settings->membership_form_settings() );
		?>
		<details><summary class="button">Pedir correção</summary>
		<div class="adam-admin-rejection-form adam-admin-correction-form" data-adam-correction-selector>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'adam_member_change_' . $request->id() ); ?><input type="hidden" name="action" value="adam_membership_member_change_action"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>">
				<label><span>Motivo da correção</span><select name="rejection_reason" required><option value="">Selecionar</option><?php foreach ( $this->correction_reasons() as $reason ) : ?><option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option><?php endforeach; ?></select></label>
				<div class="adam-correction-field-picker"><span>Campos a corrigir</span><button type="button" class="button adam-correction-field-picker__trigger" data-adam-correction-open>Selecionar campos...</button><div data-adam-correction-summary hidden><strong data-adam-correction-count></strong><div data-adam-correction-chips></div><button type="button" class="button-link" data-adam-correction-open>Alterar seleção</button></div></div>
				<dialog class="adam-admin-correction-dialog" data-adam-correction-dialog><div class="adam-admin-correction-dialog__header"><h2>Campos a corrigir</h2><button type="button" class="button-link" data-adam-correction-close aria-label="Fechar">&times;</button></div><p>Selecione a informação que o sócio deve confirmar ou corrigir.</p><div class="adam-admin-correction-dialog__groups"><?php foreach ( CorrectionFieldCatalog::groups() as $group_label => $group_fields ) : ?><fieldset><legend><?php echo esc_html( $group_label ); ?></legend><?php foreach ( $group_fields as $field ) : if ( ! isset( $definitions[ $field ] ) ) { continue; } ?><label class="adam-admin-correction-option"><input type="checkbox" name="correction_fields[]" value="<?php echo esc_attr( $field ); ?>" data-adam-correction-option data-label="<?php echo esc_attr( (string) $definitions[ $field ]['label'] ); ?>"><span><?php echo esc_html( (string) $definitions[ $field ]['label'] ); ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?></div><div class="adam-admin-correction-dialog__actions"><button type="button" class="button" data-adam-correction-close>Cancelar</button><button type="button" class="button button-primary" data-adam-correction-apply>Aplicar seleção</button></div></dialog>
				<label><span>Mensagem / observações</span><textarea name="rejection_note"></textarea></label><button class="button button-primary" name="decision" value="request_correction">Enviar pedido de correção</button>
			</form>
		</div></details>
		<?php
	}

	public function handle_member_change_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Sem permissão.', 'adam-membership' ) ); }
		$id = absint( $_POST['request_id'] ?? 0 );
		$this->verify_admin_nonce( 'adam_member_change_' . $id );
		$decision = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );
		$reason = sanitize_text_field( wp_unslash( $_POST['rejection_reason'] ?? '' ) );
		$note = sanitize_textarea_field( wp_unslash( $_POST['rejection_note'] ?? '' ) );
		$fields = isset( $_POST['correction_fields'] ) && is_array( $_POST['correction_fields'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['correction_fields'] ) ) : array();
		$result = 'approve' === $decision ? $this->member_changes->approve( $id ) : ( 'request_correction' === $decision ? $this->member_changes->request_correction( $id, $reason, $note, $fields ) : $this->member_changes->reject( $id, $reason, $note ) );
		$url = add_query_arg( array( 'page' => 'adam-membership-pending', 'approval_type' => 'changes', 'member_change_result' => is_wp_error( $result ) ? 'error' : 'ok' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function render_apd_correction_selector( ApdAssociationRequest $request ): void {
		$definitions = CorrectionFieldCatalog::definitions( $this->settings->membership_form_settings() );
		if ( ApdAssociationRequest::STATUS_CONFIRMED === $request->status() || ApdAssociationRequest::STATUS_REJECTED === $request->status() ) { return; }
		$has_proof = '' !== trim( (string) ( $request->data()['proof_of_payment'] ?? '' ) );
		$available_fields = array_keys( $definitions );
		if ( $has_proof ) { $available_fields[] = 'payment_receipt'; $definitions['payment_receipt'] = array( 'label' => 'Comprovativo de pagamento', 'type' => 'file', 'required' => true ); }
		$labels = array_merge( CorrectionFieldCatalog::labels(), array( 'payment_receipt' => 'Comprovativo de pagamento' ) );
		?>
		<div class="adam-admin-panel adam-card adam-apd-correction-panel" data-adam-correction-selector><h2>Pedir correção</h2><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'adam_membership_apd_action_' . $request->id() ); ?><input type="hidden" name="action" value="adam_membership_apd_action"><input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>"><input type="hidden" name="apd_action" value="request_correction">
		<label><span>Motivo da correção</span><select name="rejection_reason" required><option value="">Selecionar</option><?php foreach ( $this->correction_reasons() as $reason ) : ?><option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option><?php endforeach; ?></select></label>
		<div class="adam-correction-field-picker"><span>Campos a corrigir</span><button type="button" class="button adam-correction-field-picker__trigger" data-adam-correction-open>Selecionar campos...</button><div data-adam-correction-summary hidden><strong data-adam-correction-count></strong><div data-adam-correction-chips></div><button type="button" class="button-link" data-adam-correction-open>Alterar seleção</button></div></div>
		<dialog class="adam-admin-correction-dialog" data-adam-correction-dialog><div class="adam-admin-correction-dialog__header"><h2>Campos a corrigir</h2><button type="button" class="button-link" data-adam-correction-close aria-label="Fechar">&times;</button></div><p>Selecione a informação que o sócio deve confirmar ou corrigir.</p><div class="adam-admin-correction-dialog__groups"><?php foreach ( CorrectionFieldCatalog::groups() as $group_label => $group_fields ) : ?><fieldset><legend><?php echo esc_html( $group_label ); ?></legend><?php foreach ( $group_fields as $field ) : if ( ! isset( $definitions[ $field ] ) ) { continue; } ?><label class="adam-admin-correction-option"><input type="checkbox" name="correction_fields[]" value="<?php echo esc_attr( $field ); ?>" data-adam-correction-option data-label="<?php echo esc_attr( (string) $definitions[ $field ]['label'] ); ?>"><span><?php echo esc_html( (string) $definitions[ $field ]['label'] ); ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?></div><div class="adam-admin-correction-dialog__actions"><button type="button" class="button" data-adam-correction-close>Cancelar</button><button type="button" class="button button-primary" data-adam-correction-apply>Aplicar seleção</button></div></dialog>
		<label><span>Mensagem / observações</span><textarea name="rejection_note"></textarea></label><button type="submit" class="button button-primary">Enviar pedido de correção</button></form></div>
		<?php
	}

	private function render_apd_review_or_list(): void {
		$requested_id = absint( $_GET['request_id'] ?? 0 );
		$requests = $this->apd_association->repository()->all();
		$this->render_header( 'Pedidos APD/ANA' );
		$this->render_notices();
		if ( $requested_id > 0 ) {
			$request = null;
			foreach ( $requests as $candidate ) { if ( $candidate->id() === $requested_id ) { $request = $candidate; break; } }
			if ( null === $request ) { $this->render_empty_state( 'Pedido APD/ANA não encontrado.' ); $this->render_footer(); return; }
			$member = $this->members->find( $request->user_id() );
			if ( null === $member ) { $this->render_empty_state( 'Sócio não encontrado.' ); $this->render_footer(); return; }
			$data = (array) ( $request->data()['submitted_data'] ?? array() );
			$this->render_apd_correction_selector( $request );
			$this->render_apd_google_sheets_panel( $member, $request );
			$proof = (string) ( $request->data()['proof_of_payment'] ?? '' );
			$back = admin_url( 'admin.php?page=adam-membership-pending&approval_type=apd' );
			echo '<div class="adam-apd-review adam-admin-panel adam-card">';
			echo '<div class="adam-admin-panel adam-card"><p><a href="' . esc_url( $back ) . '">← Voltar a Aprovações / APD / ANA</a></p><h2>Rever pedido APD/ANA</h2><div class="adam-admin-detail-grid"><div><strong>Sócio</strong><span>' . esc_html( $member->full_name() ) . '</span></div><div><strong>N.º de sócio ADAM</strong><span>' . esc_html( (string) $member->field( 'numero_socio' ) ) . '</span></div><div><strong>Data do pedido</strong><span>' . esc_html( $this->format_datetime( $request->requested_at() ) ) . '</span></div><div><strong>Valor aplicável</strong><span>' . esc_html( (string) $request->amount() ) . ' €</span></div><div><strong>Estado do pagamento</strong><span>' . esc_html( DisplayLabels::status( (string) $request->payment_status() ) ) . '</span></div><div><strong>Estado do pedido</strong><span>' . esc_html( DisplayLabels::status( (string) $request->status() ) ) . '</span></div></div></div>';
			echo '<div class="adam-admin-panel adam-card"><h2>Informação para a ANA</h2><div class="adam-admin-detail-grid">';
			foreach ( $data as $field => $value ) { if ( is_scalar( $value ) && '' !== (string) $value ) { echo '<div><strong>' . esc_html( DisplayLabels::field( (string) $field ) ) . '</strong><span>' . esc_html( DisplayLabels::value( (string) $field, $value ) ) . '</span></div>'; } }
			$photo_id = absint( $data['profile_photo'] ?? $member->field( 'profile_photo' ) );
			$photo_url = $photo_id ? wp_get_attachment_url( $photo_id ) : '';
			if ( $photo_url ) { echo '<div class="adam-admin-panel adam-card"><h2>Fotografia</h2><img src="' . esc_url( (string) $photo_url ) . '" alt="Fotografia do sócio" style="max-width:220px;height:auto;border-radius:12px"></div>'; }
			echo '</div></div><div class="adam-admin-panel adam-card"><h2>Pagamento</h2><p><strong>Valor:</strong> ' . esc_html( (string) $request->amount() ) . ' €</p><p><strong>Comprovativo:</strong> ' . ( $proof ? '<a href="' . esc_url( $proof ) . '" target="_blank" rel="noopener">Abrir</a> · <a href="' . esc_url( $proof ) . '" download>Descarregar</a>' : 'Não disponível' ) . '</p><p><strong>Estado:</strong> ' . esc_html( DisplayLabels::status( (string) $request->payment_status() ) ) . '</p></div><div class="adam-admin-panel adam-card"><h2>Processamento ANA</h2><p>Pagamento confirmado <span aria-hidden="true">→</span> Submetido à ANA <span aria-hidden="true">→</span> Confirmado pela ANA</p>';
			if ( ApdAssociationRequest::STATUS_PENDING_PAYMENT === $request->status() ) {
				echo '<p class="adam-apd-stage-current"><strong>Estado atual:</strong> Pagamento por verificar</p>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'adam_membership_apd_action_' . $request->id() ); echo '<input type="hidden" name="action" value="adam_membership_apd_action"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request->id() ) . '"><input type="hidden" name="apd_action" value="payment_received"><button class="button">Pagamento confirmado</button></form>';
			} elseif ( ApdAssociationRequest::STATUS_AWAITING_ADAM === $request->status() ) {
				echo '<p class="adam-apd-stage-current"><strong>Estado atual:</strong> Pagamento confirmado</p>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'adam_membership_apd_action_' . $request->id() ); echo '<input type="hidden" name="action" value="adam_membership_apd_action"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request->id() ) . '"><input type="hidden" name="apd_action" value="submit_ana"><button class="button button-primary">Submeter à ANA</button></form>';
			} elseif ( ApdAssociationRequest::STATUS_SUBMITTED_ANA === $request->status() ) {
				echo '<p class="adam-apd-stage-current"><strong>Estado atual:</strong> A aguardar confirmação da ANA</p>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>A aguardar confirmação da ANA.</p>'; wp_nonce_field( 'adam_membership_apd_action_' . $request->id() ); echo '<input type="hidden" name="action" value="adam_membership_apd_action"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request->id() ) . '"><input type="hidden" name="apd_action" value="confirm"><label>Data de confirmação ANA <input type="date" name="confirmation_date" required></label><label>N.º ANA <input type="text" name="ana_member_number" required></label><button class="button button-primary">Aprovar pedido</button></form>';
			}
			if ( ApdAssociationRequest::STATUS_CONFIRMED !== $request->status() && ApdAssociationRequest::STATUS_REJECTED !== $request->status() ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'adam_membership_apd_action_' . $request->id() ); echo '<input type="hidden" name="action" value="adam_membership_apd_action"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request->id() ) . '"><input type="hidden" name="apd_action" value="reject"><label>Motivo da rejeição <select name="rejection_reason" required><option value="">Selecionar</option><option>Pagamento/comprovativo inválido</option><option>Informação incompleta</option><option>Informação incorreta</option><option>Documentação inválida</option><option>Fotografia não cumpre os requisitos</option><option>Pedido recusado pela ANA</option><option>Outro motivo</option></select></label><label>Mensagem / observações<textarea name="rejection_note"></textarea></label><button class="button">Rejeitar pedido</button></form><details><summary class="button">Pedir correção</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'adam_membership_apd_action_' . $request->id() ); echo '<input type="hidden" name="action" value="adam_membership_apd_action"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request->id() ) . '"><input type="hidden" name="apd_action" value="request_correction"><label>Motivo<select name="rejection_reason" required><option value="">Selecionar</option><option>Informação incompleta</option><option>Informação incorreta</option><option>Documentação inválida</option><option>Fotografia não cumpre os requisitos</option><option>Outro motivo</option></select></label><label>Mensagem / observações<textarea name="rejection_note"></textarea></label><button class="button">Enviar pedido de correção</button></form></details>';
			}
			echo '</div>';
			echo '</div>';
			$this->render_footer(); return;
		}
		echo '<div class="adam-admin-panel adam-card"><h2>Pedidos APD/ANA</h2><table class="widefat striped"><thead><tr><th>Sócio</th><th>N.º</th><th>Data</th><th>Valor</th><th>Pagamento</th><th>Estado</th><th>Ação</th></tr></thead><tbody>';
		foreach ( $requests as $request ) { if ( in_array( $request->status(), array( ApdAssociationRequest::STATUS_CONFIRMED, ApdAssociationRequest::STATUS_REJECTED ), true ) ) { continue; } $member = $this->members->find( $request->user_id() ); if ( null === $member ) { continue; } echo '<tr><td>' . esc_html( $member->full_name() ) . '</td><td>' . esc_html( (string) $member->field( 'numero_socio' ) ) . '</td><td>' . esc_html( $this->format_datetime( $request->requested_at() ) ) . '</td><td>' . esc_html( (string) $request->amount() ) . ' €</td><td>' . esc_html( DisplayLabels::status( (string) $request->payment_status() ) ) . '</td><td>' . esc_html( DisplayLabels::status( (string) $request->status() ) ) . '</td><td><a class="button button-small button-primary" href="' . esc_url( add_query_arg( array( 'page' => 'adam-membership-pending', 'review_type' => 'apd', 'request_id' => $request->id() ), admin_url( 'admin.php' ) ) ) . '">Rever</a></td></tr>'; }
		echo '</tbody></table></div>';
		$this->render_footer();
	}

	private function render_apd_requests_page_clean(): void {
		$requested_id = absint( $_GET['request_id'] ?? 0 );
		$this->render_header( 'Pedidos APD/ANA' );
		$this->render_notices();
		echo '<div class="adam-admin-panel adam-card"><h2>Pedidos APD/ANA</h2><table class="widefat striped"><thead><tr><th>Sócio</th><th>N.º</th><th>Data</th><th>Valor</th><th>Pagamento</th><th>Estado</th><th>Confirmação ANA</th><th>Ações</th></tr></thead><tbody>';
		foreach ( $this->apd_association->repository()->all() as $request ) {
			if ( $requested_id > 0 && $request->id() !== $requested_id ) { continue; }
			$member = $this->members->find( $request->user_id() );
			if ( null === $member ) { continue; }
			if ( $requested_id > 0 ) {
				echo '<tr><td colspan="8"><h3>Informação submetida para ANA</h3><table class="widefat striped">';
				foreach ( (array) ( $request->data()['submitted_data'] ?? array() ) as $field => $value ) {
					if ( is_scalar( $value ) && '' !== (string) $value ) { echo '<tr><th>' . esc_html( DisplayLabels::field( (string) $field ) ) . '</th><td>' . esc_html( DisplayLabels::value( (string) $field, $value ) ) . '</td></tr>'; }
				}
				echo '</table></td></tr>';
			}
			echo '<tr><td>' . esc_html( $member->full_name() ) . '</td><td>' . esc_html( (string) $member->field( 'numero_socio' ) ) . '</td><td>' . esc_html( $this->format_datetime( $request->requested_at() ) ) . '</td><td>' . esc_html( $request->amount() ) . ' €</td><td>' . esc_html( DisplayLabels::status( (string) $request->payment_status() ) ) . '</td><td>' . esc_html( DisplayLabels::status( (string) $request->status() ) ) . '</td><td>' . esc_html( $request->confirmation_date() ?: '—' ) . '</td><td>';
			if ( $request->status() !== ApdAssociationRequest::STATUS_CONFIRMED ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				wp_nonce_field( 'adam_membership_apd_action_' . $request->id() );
				echo '<input type="hidden" name="action" value="adam_membership_apd_action"><input type="hidden" name="request_id" value="' . esc_attr( (string) $request->id() ) . '"><input type="hidden" name="apd_action" value="confirm"><input type="date" name="confirmation_date" required><input type="text" name="ana_member_number" placeholder="N.º ANA" required><button class="button button-primary">Confirmar ANA</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		$this->render_footer();
	}

	public function render_apd_requests_page(): void {
		$this->ensure_can_manage();
		$this->render_apd_review_or_list();
		return;
		$requested_id = absint( $_GET['request_id'] ?? 0 );
		$this->render_header( __( 'Pedidos APD/ANA', 'adam-membership' ) );
		$fees = (array) ( $this->settings->membership_form_settings()['apd_association_fees'] ?? array() );
		echo '<div class="adam-admin-panel adam-card"><h2>Preços de associação APD</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="adam_membership_save_forms_settings">'; wp_nonce_field( 'adam_membership_save_forms_settings' ); echo '<input name="membership_forms[apd_association_fees][0_3]" value="' . esc_attr( $fees['0_3'] ?? '12.00' ) . '"> 0-3 meses <input name="membership_forms[apd_association_fees][4_6]" value="' . esc_attr( $fees['4_6'] ?? '14.00' ) . '"> 4-6 meses <input name="membership_forms[apd_association_fees][7_9]" value="' . esc_attr( $fees['7_9'] ?? '17.00' ) . '"> 7-9 meses <input name="membership_forms[apd_association_fees][10_plus]" value="' . esc_attr( $fees['10_plus'] ?? '22.00' ) . '"> 10+ meses <button class="button button-primary">Guardar preços</button></form></div>';
		echo '<div class="adam-admin-panel adam-card"><table class="widefat striped"><thead><tr><th>Membro</th><th>N.º</th><th>Data</th><th>Valor</th><th>Pagamento</th><th>Estado</th><th>Confirmação ANA</th><th>Ações</th></tr></thead><tbody>';
		foreach ( $this->apd_association->repository()->all() as $request ) {
			if ( $requested_id > 0 && $request->id() !== $requested_id ) { continue; }
			$member = $this->members->find( $request->user_id() );
			if ( null === $member ) { continue; }
			if ( $requested_id > 0 ) { echo '<div class="adam-admin-panel adam-card"><h2>Informação submetida para ANA</h2><table class="widefat striped">'; foreach ( (array) ( $request->data()['submitted_data'] ?? array() ) as $field => $value ) { if ( is_scalar( $value ) && '' !== (string) $value ) { echo '<tr><th>' . esc_html( (string) $field ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>'; } } echo '</table></div>'; }
			echo '<tr><td>' . esc_html( $member->full_name() ) . '</td><td>' . esc_html( (string) $member->field( 'numero_socio' ) ) . '</td><td>' . esc_html( $this->format_datetime( $request->requested_at() ) ) . '</td><td>' . esc_html( $request->amount() ) . ' €</td><td>' . esc_html( $request->payment_status() ) . '</td><td>' . esc_html( $request->status() ) . '</td><td>' . esc_html( $request->confirmation_date() ?: '—' ) . '</td><td>';
			if ( $request->status() !== ApdAssociationRequest::STATUS_CONFIRMED ) { echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="adam_membership_apd_action">'; wp_nonce_field( 'adam_membership_apd_action_' . $request->id() ); echo '<input type="hidden" name="request_id" value="' . esc_attr( (string) $request->id() ) . '"><input type="hidden" name="apd_action" value="confirm"><input type="date" name="confirmation_date" required><input type="text" name="ana_member_number" placeholder="N.º ANA" required><button class="button button-primary">Confirmar ANA</button></form>'; }
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public function handle_apd_action(): void {
		$this->ensure_can_manage();
		$id = absint( $_POST['request_id'] ?? 0 );
		$this->verify_admin_nonce( 'adam_membership_apd_action_' . $id );
		$action = sanitize_key( wp_unslash( $_POST['apd_action'] ?? '' ) );
		$reason = sanitize_text_field( wp_unslash( $_POST['rejection_reason'] ?? '' ) );
		$note = sanitize_textarea_field( wp_unslash( $_POST['rejection_note'] ?? '' ) );
		$result = match ( $action ) {
			'payment_received' => $this->apd_association->mark_payment_received( $id ),
			'submit_ana' => $this->apd_association->submit_to_ana( $id ),
			'confirm' => $this->apd_association->confirm( $id, sanitize_text_field( wp_unslash( $_POST['confirmation_date'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['ana_member_number'] ?? '' ) ) ),
			'reject' => $this->apd_association->reject( $id, $reason, $note ),
			'request_correction' => $this->apd_association->request_correction( $id, $reason, $note, $fields ),
			default => new WP_Error( 'adam_invalid_apd_action', __( 'Ação APD inválida.', 'adam-membership' ) ),
		};
		if ( $result instanceof WP_Error ) { $this->redirect_with_error( $result->get_error_message() ); }
		wp_safe_redirect( add_query_arg( array( 'page' => 'adam-membership-pending', 'approval_type' => 'apd', 'apd_result' => 'ok' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
