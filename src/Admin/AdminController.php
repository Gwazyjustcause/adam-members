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
use AdamMembership\Core\MaintenanceService;
use AdamMembership\Document\DocumentService;
use AdamMembership\Emails\EmailService;
use AdamMembership\Event\Event;
use AdamMembership\Event\EventCheckIn;
use AdamMembership\Event\EventService;
use AdamMembership\Helpers\Logger;
use AdamMembership\Member\ApprovalService;
use AdamMembership\Member\CardService;
use AdamMembership\Member\HistoryEntry;
use AdamMembership\Member\HistoryRepository;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Member\RecognitionService;
use AdamMembership\Member\RenewalRepository;
use AdamMembership\Member\RenewalRequest;
use AdamMembership\Member\RenewalService;
use AdamMembership\Reward\RewardRedemption;
use AdamMembership\Reward\RewardService;
use WP_Error;

/**
 * Registers and renders ADAM Membership admin pages.
 */
final class AdminController {
	private const CAPABILITY          = 'manage_options';
	private const MENU_SLUG           = 'adam-membership';
	private const HISTORY_PAGE_SLUG   = 'adam-membership-history';
	private const MEMBER_PAGE_SLUG    = 'adam-membership-member';
	private const ACTION_APPROVE      = 'approve';
	private const ACTION_REJECT       = 'reject';
	private const ACTION_RENEW        = 'renew_quota';
	private const ACTION_CHANGE_QUOTA = 'change_quota_validity';
	private const ACTION_RESEND_EMAIL = 'resend_approval_email';
	private const ACTION_SAVE_MEMBER  = 'save_member';
	private const ACTION_REGENERATE_CARD_TOKEN = 'regenerate_card_token';
	private const ACTION_REPLACE_DOCUMENT = 'replace_document';
	private const ACTION_REMOVE_DOCUMENT = 'remove_document';
	private const ACTION_REPLACE_RENEWAL_DOCUMENT = 'replace_renewal_document';
	private const ACTION_REMOVE_RENEWAL_DOCUMENT  = 'remove_renewal_document';
	private const ACTION_APPROVE_RENEWAL = 'approve_renewal';
	private const ACTION_REJECT_RENEWAL  = 'reject_renewal';
	private const RENEWAL_PAGE_SLUG      = 'adam-membership-renewal-request';
	private const DIAGNOSTICS_PAGE_SLUG  = 'adam-membership-diagnostics';
	private const FOUNDERS_PAGE_SLUG     = 'adam-membership-founders';
	private const FORMS_PAGE_SLUG        = 'adam-membership-forms';
	private const EMAILS_PAGE_SLUG       = 'adam-membership-emails';

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
	 */
	public function __construct( MemberRepository $members, ApprovalService $approval_service, SettingsRepository $settings, Logger $logger, RenewalRepository $renewals, RenewalService $renewal_service, MaintenanceService $maintenance, CardService $cards, HistoryRepository $history, AnnouncementService $announcements, DocumentService $documents, EventService $events, RewardService $rewards, RecognitionService $recognition, EmailService $email ) {
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
		add_action( 'admin_post_adam_membership_renewal_action', array( $this, 'handle_renewal_admin_action' ) );
		add_action( 'admin_post_adam_membership_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_adam_membership_save_forms_settings', array( $this, 'handle_save_forms_settings' ) );
		add_action( 'admin_post_adam_membership_save_email_settings', array( $this, 'handle_save_email_settings' ) );
		add_action( 'admin_post_adam_membership_send_test_email', array( $this, 'handle_send_test_email' ) );
		add_action( 'admin_post_adam_membership_run_maintenance', array( $this, 'handle_run_maintenance' ) );
		add_action( 'admin_post_adam_membership_export_members_csv', array( $this, 'handle_export_members_csv' ) );
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

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Sócios Pendentes', 'adam-membership' ),
			esc_html__( 'Sócios Pendentes', 'adam-membership' ),
			self::CAPABILITY,
			'adam-membership-pending',
			array( $this, 'render_pending_members_page' )
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
			esc_html__( 'Pedidos de Renovação', 'adam-membership' ),
			esc_html__( 'Pedidos de Renovação', 'adam-membership' ),
			self::CAPABILITY,
			'adam-membership-renewals',
			array( $this, 'render_renewals_page' )
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
			self::MENU_SLUG,
			esc_html__( 'Pedido de Renovação', 'adam-membership' ),
			esc_html__( 'Pedido de Renovação', 'adam-membership' ),
			self::CAPABILITY,
			self::RENEWAL_PAGE_SLUG,
			array( $this, 'render_renewal_page' )
		);

		if ( '' !== $this->member_page_hook ) {
			add_action( 'load-' . $this->member_page_hook, array( $this, 'prepare_member_page_screen' ) );
		}

		if ( '' !== $this->renewal_page_hook ) {
			add_action( 'load-' . $this->renewal_page_hook, array( $this, 'prepare_renewal_page_screen' ) );
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
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard_page(): void {
		$this->ensure_can_manage();

		$counts  = $this->members->dashboard_counts();
		$context = $this->dashboard_context( $counts );

		$this->render_header( __( 'Painel ADAM Sócios', 'adam-membership' ) );
		$this->render_notices();
		$this->render_dashboard_cards( $counts );
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
		<div class="adam-admin-cards">
			<div class="adam-admin-card"><span><?php esc_html_e( 'Sócios', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( $counts['total'] ?? 0 ) ); ?></strong></div>
			<div class="adam-admin-card"><span><?php esc_html_e( 'Avisos', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $all_announcements ) ) ); ?></strong></div>
			<div class="adam-admin-card"><span><?php esc_html_e( 'Documentos', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $all_documents ) ) ); ?></strong></div>
			<div class="adam-admin-card"><span><?php esc_html_e( 'Eventos', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $all_events ) ) ); ?></strong></div>
			<div class="adam-admin-card"><span><?php esc_html_e( 'Check-ins', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $all_checkins ) ) ); ?></strong></div>
			<div class="adam-admin-card"><span><?php esc_html_e( 'Pedidos de recompensa', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( count( $pending_rewards ) ) ); ?></strong></div>
		</div>

		<div class="adam-admin-panel">
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

	/**
	 * Render the founders page.
	 */
	public function render_founders_page(): void {
		$this->ensure_can_manage();

		$founders = $this->members->founding_members();

		$this->render_header( __( 'Fundadores ADAM', 'adam-membership' ) );
		$this->render_notices();
		?>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Lista de membros fundadores', 'adam-membership' ); ?></h2>
			<p><?php esc_html_e( 'Primeiros sócios aprovados que mantêm o reconhecimento permanente de Fundador ADAM.', 'adam-membership' ); ?></p>
			<?php if ( array() === $founders ) : ?>
				<?php $this->render_empty_state( __( 'Ainda não existem membros fundadores registados.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
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
								<td><?php echo esc_html( $founder->effective_status() ); ?></td>
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
	public function render_pending_members_page(): void {
		$this->ensure_can_manage();

		$filters           = $this->current_member_filters();
		$filters['status'] = Member::STATUS_PENDING;
		$members           = $this->members->admin_members( $filters );

		$this->render_header( __( 'Sócios Pendentes', 'adam-membership' ) );
		$this->render_notices();
		$this->render_member_filters( $filters, true );
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
		$this->render_history_timeline( $entries );
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
	 * Prepare context for hidden ADAM admin screens before the header renders.
	 *
	 * @param mixed $screen Current screen object when available.
	 */
	public function prepare_hidden_screen_context( mixed $screen ): void {
		if ( ! $this->is_member_page_request() && ! $this->is_renewal_page_request() ) {
			return;
		}

		if ( $this->is_member_page_request() ) {
			$this->prepare_member_page_screen();
			return;
		}

		$this->prepare_renewal_page_screen();
	}

	/**
	 * Keep hidden ADAM screens attached to the correct parent menu.
	 *
	 * @param string|null $parent_file Current parent file.
	 */
	public function filter_hidden_parent_file( ?string $parent_file ): ?string {
		if ( $this->is_member_page_request() || $this->is_renewal_page_request() ) {
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
		?>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Numeração de Sócios', 'adam-membership' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_save_settings">
				<?php wp_nonce_field( 'adam_membership_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Último número de sócio atribuído', 'adam-membership' ); ?></th>
						<td><code><?php echo esc_html( (string) $this->settings->last_assigned_member_number() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Próximo número de sócio', 'adam-membership' ); ?></th>
						<td><code><?php echo esc_html( $this->settings->preview_next_member_number() ); ?></code></td>
					</tr>
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
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar configurações', 'adam-membership' ); ?></button>
			</form>
		</div>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Manutenção agendada', 'adam-membership' ); ?></h2>
			<p><?php esc_html_e( 'A manutenção de sócios é executada diariamente através do WP-Cron. Utilize este botão para executar o mesmo processo imediatamente para testes.', 'adam-membership' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_run_maintenance">
				<?php wp_nonce_field( 'adam_membership_run_maintenance' ); ?>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Executar manutenção agora', 'adam-membership' ); ?></button>
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

		$this->render_header( __( 'Formulários ADAM', 'adam-membership' ) );
		$this->render_notices();
		?>
		<div class="adam-admin-panel">
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

				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar formulários', 'adam-membership' ); ?></button></p>
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
		<div class="adam-admin-panel">
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
					<div class="adam-admin-panel" style="margin-top:20px;">
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
							<div style="border:1px solid #d9e4dc;border-radius:12px;background:#fff;padding:16px;margin-top:16px;">
								<p><strong><?php esc_html_e( 'Pré-visualização do assunto:', 'adam-membership' ); ?></strong> <?php echo esc_html( $preview['subject'] ); ?></p>
								<div><?php echo wp_kses_post( $preview['html'] ); ?></div>
							</div>
						<?php endif; ?>
						<p style="margin-top:16px;">
							<button type="submit" class="button button-secondary" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=adam_membership_send_test_email' ) ); ?>" formmethod="post" name="template_key" value="<?php echo esc_attr( $template_key ); ?>"><?php echo esc_html( sprintf( __( 'Enviar teste para %s', 'adam-membership' ), $test_to ) ); ?></button>
						</p>
					</div>
				<?php endforeach; ?>

				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar emails', 'adam-membership' ); ?></button></p>
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
		<div class="adam-admin-panel">
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

				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar formulários', 'adam-membership' ); ?></button></p>
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
			<p><button type="button" class="button button-secondary" data-adam-add-field><?php esc_html_e( 'Adicionar novo campo', 'adam-membership' ); ?></button></p>
			<table class="widefat striped adam-admin-table">
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
									<button type="button" class="button button-link-delete" data-adam-remove-field><?php esc_html_e( 'Remover', 'adam-membership' ); ?></button>
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

		$this->handle_member_action( $action );
	}

	/**
	 * Handle renewal request admin actions.
	 */
	public function handle_renewal_admin_action(): void {
		$this->ensure_can_manage();

		$request_id = isset( $_POST['request_id'] ) ? absint( wp_unslash( $_POST['request_id'] ) ) : 0;
		$action     = isset( $_POST['renewal_action'] ) ? sanitize_key( wp_unslash( $_POST['renewal_action'] ) ) : '';

		check_admin_referer( 'adam_membership_renewal_action_' . $request_id );

		$result = match ( $action ) {
			self::ACTION_APPROVE_RENEWAL          => $this->renewal_service->approve( $request_id ),
			self::ACTION_REJECT_RENEWAL           => $this->renewal_service->reject( $request_id, $this->posted_rejection_reason() ),
			self::ACTION_REPLACE_RENEWAL_DOCUMENT => $this->replace_renewal_document( $request_id ),
			self::ACTION_REMOVE_RENEWAL_DOCUMENT  => $this->remove_renewal_document( $request_id ),
			default                               => new WP_Error( 'adam_membership_invalid_renewal_action', __( 'Ação de renovação inválida.', 'adam-membership' ) ),
		};

		if ( $result instanceof WP_Error ) {
			$this->redirect_with_error( $result->get_error_message() );
		}

		$this->redirect_with_message( __( 'Pedido de renovação atualizado com sucesso.', 'adam-membership' ) );
	}

	/**
	 * Save plugin settings.
	 */
	public function handle_save_settings(): void {
		$this->ensure_can_manage();
		check_admin_referer( 'adam_membership_save_settings' );

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

	/**
	 * Save native forms settings.
	 */
	public function handle_save_forms_settings(): void {
		$this->ensure_can_manage();
		check_admin_referer( 'adam_membership_save_forms_settings' );

		$registration_url = isset( $_POST['registration_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['registration_page_url'] ) ) : $this->settings->registration_page_url();
		$renewal_url      = isset( $_POST['renewal_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['renewal_page_url'] ) ) : $this->settings->renewal_page_url();
		$privacy_url      = isset( $_POST['privacy_policy_url'] ) ? esc_url_raw( wp_unslash( $_POST['privacy_policy_url'] ) ) : $this->settings->privacy_policy_url();
		$form_settings    = isset( $_POST['membership_forms'] ) && is_array( $_POST['membership_forms'] ) ? wp_unslash( $_POST['membership_forms'] ) : $this->settings->membership_form_settings();

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
		check_admin_referer( 'adam_membership_save_email_settings' );

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
		check_admin_referer( 'adam_membership_save_email_settings' );

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
		check_admin_referer( 'adam_membership_run_maintenance' );

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
		check_admin_referer( 'adam_membership_export_members_csv' );

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
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Aplicar filtros', 'adam-membership' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-renewals' ) ); ?>"><?php esc_html_e( 'Repor', 'adam-membership' ); ?></a>
		</form>
		<?php
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
						'<td class="adam-admin-row-actions"><button type="button" class="button" data-adam-move-up>Subir</button> <button type="button" class="button" data-adam-move-down>Descer</button> <button type="button" class="button button-link-delete" data-adam-remove-field>Remover</button></td>',
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
		<table class="widefat striped adam-admin-table">
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
		<div class="adam-admin-panel">
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
		<?php $this->render_documents_panel( __( 'Documentos submetidos', 'adam-membership' ), $document_rows, null, $request, true ); ?>

		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Submitted changes', 'adam-membership' ); ?></h2>
			<?php if ( array() === $changes ) : ?>
				<?php $this->render_empty_state( __( 'Não foram submetidas alterações ao perfil.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
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

		<?php if ( RenewalRequest::STATUS_PENDING === $request->status() ) : ?>
			<div class="adam-admin-panel">
				<h2><?php esc_html_e( 'Decisão de revisão', 'adam-membership' ); ?></h2>
				<div class="adam-admin-actions">
					<?php $this->render_renewal_action_form( $request, self::ACTION_APPROVE_RENEWAL, __( 'Aprovar renovação', 'adam-membership' ), 'button-primary' ); ?>
				</div>
				<?php $this->render_renewal_rejection_form( $request ); ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render dashboard cards.
	 *
	 * @param array<string, int> $counts Dashboard counts.
	 */
	private function render_dashboard_cards( array $counts ): void {
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
				<div class="adam-admin-card">
					<span><?php echo esc_html( $card['label'] ); ?></span>
					<strong><?php echo esc_html( (string) $card['value'] ); ?></strong>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render dashboard shortcut links.
	 */
	private function render_dashboard_shortcuts_legacy(): void {
		?>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Ações rápidas', 'adam-membership' ); ?></h2>
			<div class="adam-admin-actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-pending' ) ); ?>"><?php esc_html_e( 'Rever sócios pendentes', 'adam-membership' ); ?></a>
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
						'url'         => admin_url( 'admin.php?page=adam-membership-events' ),
						'badge'       => count( $context['events_all'] ?? array() ),
					),
					array(
						'icon'        => 'plus-alt2',
						'title'       => __( 'Criar novo evento', 'adam-membership' ),
						'description' => __( 'Criar rapidamente um novo evento ADAM.', 'adam-membership' ),
						'button'      => __( 'Criar evento', 'adam-membership' ),
						'url'         => admin_url( 'admin.php?page=adam-membership-event-edit' ),
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
				<section class="adam-admin-panel adam-admin-shortcut-panel">
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
		$badge = isset( $item['badge'] ) ? (int) $item['badge'] : null;
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
			<div class="adam-admin-shortcut-card__footer">
				<a class="button button-secondary" href="<?php echo esc_url( (string) $item['url'] ); ?>"><?php echo esc_html( (string) $item['button'] ); ?></a>
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
		<section class="adam-admin-panel adam-admin-widget-panel">
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
		<section class="adam-admin-panel adam-admin-widget-panel">
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
		<section class="adam-admin-panel adam-admin-widget-panel">
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
		<section class="adam-admin-panel adam-admin-widget-panel">
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
		<section class="adam-admin-panel adam-admin-widget-panel">
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
		<section class="adam-admin-panel adam-admin-widget-panel">
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

			<button type="submit" class="button button-primary"><?php esc_html_e( 'Aplicar filtros', 'adam-membership' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ( $force_pending ? 'adam-membership-pending' : 'adam-membership-members' ) ) ); ?>"><?php esc_html_e( 'Repor', 'adam-membership' ); ?></a>
		</form>
		<?php
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
		<table class="widefat striped adam-admin-table">
			<thead>
				<tr>
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
							<?php if ( $show_actions ) : ?>
								<?php $this->render_inline_action_form( $member, self::ACTION_APPROVE, __( 'Aprovar', 'adam-membership' ), 'button-primary' ); ?>
								<?php $this->render_inline_rejection_form( $member ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $show_actions ) : ?>
						<tr class="adam-admin-documents-row">
							<td colspan="9">
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
	private function render_member_detail( Member $member ): void {
		$member_requests    = $this->renewal_repository->for_user( $member->user_id() );
		$document_rows      = $this->member_document_rows( $member, true );
		$document_warnings  = $this->approval_service->missing_registration_documents( $member );
		?>
		<div class="adam-admin-member-layout">
			<div class="adam-admin-panel">
				<div class="adam-admin-member-heading">
					<?php $this->render_profile_photo( $member ); ?>
					<div>
						<h2><?php echo esc_html( $member->full_name() ); ?></h2>
						<p><?php echo esc_html( $member->email() ); ?></p>
					</div>
				</div>

				<?php $this->render_admin_safety_notice( $member ); ?>
				<?php $this->render_member_status_consistency_notice( $member ); ?>

				<div class="adam-admin-detail-grid">
					<?php $this->render_detail_item( __( 'Estado da inscrição', 'adam-membership' ), $member->effective_status() ); ?>
					<?php $this->render_detail_item( __( 'Estado guardado', 'adam-membership' ), $member->status() ); ?>
					<?php $this->render_detail_item( __( 'N.º de sócio', 'adam-membership' ), $this->member_number_label( $member ) ); ?>
					<?php $this->render_detail_item( __( 'Membro Fundador', 'adam-membership' ), $member->is_founder() ? __( 'Sim', 'adam-membership' ) : __( 'Não', 'adam-membership' ) ); ?>
					<?php $this->render_detail_item( __( 'N.º Fundador', 'adam-membership' ), $member->is_founder() ? (string) $member->founder_number() : '—' ); ?>
					<?php $this->render_detail_item( __( 'Quota válida até', 'adam-membership' ), $this->format_date( $member->field( 'validade_quota' ) ) ); ?>
					<?php $this->render_detail_item( __( 'Data de adesão', 'adam-membership' ), $this->format_date( $member->field( 'data_adesao' ) ) ); ?>
					<?php $this->render_detail_item( __( 'Telefone', 'adam-membership' ), (string) $member->field( 'telefone' ) ); ?>
					<?php $this->render_detail_item( __( 'Equipa', 'adam-membership' ), (string) $member->field( 'equipa' ) ); ?>
					<?php $this->render_detail_item( __( 'NIF', 'adam-membership' ), (string) $member->field( 'nif' ) ); ?>
					<?php $this->render_detail_item( __( 'Citizen card', 'adam-membership' ), (string) $member->field( 'cartao_cidadao' ) ); ?>
					<?php $this->render_detail_item( __( 'Data de nascimento', 'adam-membership' ), $this->format_date( $member->field( 'data_nascimento' ) ) ); ?>
					<?php $this->render_detail_item( __( 'Morada', 'adam-membership' ), (string) $member->field( 'morada' ) ); ?>
					<?php $this->render_detail_item( __( 'Motivo de rejeição', 'adam-membership' ), (string) $member->field( 'motivo_rejeicao' ) ); ?>
					<?php $this->render_detail_item( __( 'Nota privada de rejeição', 'adam-membership' ), (string) $member->field( 'nota_rejeicao_admin' ) ); ?>
				</div>
			</div>

			<?php $this->render_document_warning_panel( $document_warnings, __( 'Existem documentos obrigatórios em falta para aprovar este sócio.', 'adam-membership' ) ); ?>
			<?php $this->render_documents_panel( __( 'Documentos submetidos', 'adam-membership' ), $document_rows, $member, null, true ); ?>
			<?php foreach ( $member_requests as $request ) : ?>
				<?php $this->render_documents_panel( sprintf( __( 'Documentos da renovação #%d', 'adam-membership' ), $request->id() ), $this->renewal_document_rows( $request ), null, $request, true ); ?>
			<?php endforeach; ?>

			<?php $this->render_member_edit_form( $member ); ?>

			<div class="adam-admin-panel">
				<h2><?php esc_html_e( 'Ações de administração', 'adam-membership' ); ?></h2>
				<div class="adam-admin-action-stack">
					<?php $this->render_action_form( $member, self::ACTION_APPROVE, __( 'Aprovar sócio', 'adam-membership' ), 'button-primary' ); ?>
					<?php $this->render_rejection_form( $member ); ?>
					<?php $this->render_action_form( $member, self::ACTION_RENEW, __( 'Renovar quota por um ano', 'adam-membership' ), 'button-secondary' ); ?>
					<?php $this->render_action_form( $member, self::ACTION_RESEND_EMAIL, __( 'Reenviar email de aprovação', 'adam-membership' ), 'button-secondary' ); ?>
					<?php $this->render_action_form( $member, self::ACTION_REGENERATE_CARD_TOKEN, __( 'Regenerar token de validação do cartão', 'adam-membership' ), 'button-secondary' ); ?>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-quota-form">
					<input type="hidden" name="action" value="adam_membership_member_action">
					<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_CHANGE_QUOTA ); ?>">
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
					<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
					<label for="adam_quota_validity"><?php esc_html_e( 'Alterar validade da quota', 'adam-membership' ); ?></label>
					<input type="date" id="adam_quota_validity" name="quota_validity" value="<?php echo esc_attr( $this->date_input_value( $member->field( 'validade_quota' ) ) ); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar validade', 'adam-membership' ); ?></button>
				</form>

				<div class="adam-admin-safe-view">
					<h3><?php esc_html_e( 'Ver como sócio', 'adam-membership' ); ?></h3>
					<?php if ( get_current_user_id() === $member->user_id() ) : ?>
						<a class="button" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir Área do Sócio', 'adam-membership' ); ?></a>
					<?php else : ?>
						<p><?php esc_html_e( 'A impersonação não está ativa por motivos de segurança. Utilize o perfil de utilizador do WordPress para rever a conta.', 'adam-membership' ); ?></p>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( get_edit_user_link( $member->user_id() ) ); ?>"><?php esc_html_e( 'Abrir perfil do WordPress', 'adam-membership' ); ?></a>
				</div>
			</div>

			<div class="adam-admin-panel adam-admin-history-panel">
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

	/**
	 * Render the editable admin member fields form.
	 *
	 * @param Member $member Member.
	 */
	private function render_member_edit_form( Member $member ): void {
		?>
		<div class="adam-admin-panel adam-admin-edit-panel">
			<h2><?php esc_html_e( 'Editar campos do sócio', 'adam-membership' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-edit-form">
				<input type="hidden" name="action" value="adam_membership_member_action">
				<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_SAVE_MEMBER ); ?>">
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
				<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>

				<div class="adam-admin-edit-grid">
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
					<span><?php esc_html_e( 'Moldura do cartão', 'adam-membership' ); ?></span>
					<select name="active_card_frame">
						<option value=""><?php esc_html_e( 'Sem moldura especial', 'adam-membership' ); ?></option>
						<?php foreach ( $cosmetic_options['frames'] ?? array() as $cosmetic ) : ?>
							<?php $this->render_member_cosmetic_option( $cosmetic, (string) ( $card_presentation['selected_values']['frame'] ?? '' ) ); ?>
						<?php endforeach; ?>
					</select>
				</label>
				</div>

				<p class="description"><?php esc_html_e( 'As alterações manuais de estado não enviam emails. O estado Ativo exige uma data de validade da quota igual ou posterior a hoje.', 'adam-membership' ); ?></p>
				<p class="description"><?php esc_html_e( 'Os títulos e cosméticos automáticos de Fundador/Fidelidade só se mantêm disponíveis enquanto o sócio conservar essa elegibilidade. Ao remover o estatuto de fundador, as recompensas exclusivas deixam de poder ser usadas.', 'adam-membership' ); ?></p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar campos do sócio', 'adam-membership' ); ?></button>
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
		check_admin_referer( 'adam_membership_member_action_' . $user_id );

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
			self::ACTION_REJECT       => $this->approval_service->reject( $user_id, $this->posted_rejection_reason(), $this->posted_rejection_note() ),
			self::ACTION_RENEW        => $this->approval_service->renew_quota( $user_id ),
			self::ACTION_CHANGE_QUOTA => $this->approval_service->change_quota_validity( $user_id, $this->posted_quota_validity() ),
			self::ACTION_RESEND_EMAIL => $this->approval_service->resend_approval_email( $user_id ),
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
			return new WP_Error( 'adam_membership_document_missing', __( 'Selecione um ficheiro para substituir o documento.', 'adam-membership' ) );
		}

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

		$member->save( array( $document_field => $attachment_id ) );

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

		$member->save( array( $document_field => '' ) );

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
			return new WP_Error( 'adam_membership_document_missing', __( 'Selecione um ficheiro para substituir o documento.', 'adam-membership' ) );
		}

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

		if ( 'payment_receipt' === $document_field ) {
			$this->renewal_repository->update(
				$request,
				array(
					'proof_of_payment' => $attachment_id,
				)
			);

			return true;
		}

		$submitted_data                    = $request->submitted_data();
		$submitted_data[ $document_field ] = $attachment_id;

		$this->renewal_repository->update(
			$request,
			array(
				'submitted_data' => $submitted_data,
			)
		);

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

		if ( 'payment_receipt' === $document_field ) {
			$this->renewal_repository->update(
				$request,
				array(
					'proof_of_payment' => '',
				)
			);

			return true;
		}

		$submitted_data = $request->submitted_data();
		unset( $submitted_data[ $document_field ] );

		$this->renewal_repository->update(
			$request,
			array(
				'submitted_data' => $submitted_data,
			)
		);

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
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply filters', 'adam-membership' ); ?></button>
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
	 * Render a status badge.
	 *
	 * @param string $status Member status.
	 */
	private function render_status_badge( string $status ): void {
		printf(
			'<span class="adam-admin-badge %1$s">%2$s</span>',
			esc_attr( $this->status_badge_class( $status ) ),
			esc_html( $status )
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
		<div class="adam-admin-panel">
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php if ( array() === $rows ) : ?>
				<?php $this->render_empty_state( __( 'Não existem documentos submetidos para mostrar.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Documento', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Pré-visualização', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Fluxo', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Data de envio', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( (string) $row['label'] ); ?></strong></td>
								<td>
									<span class="adam-admin-badge <?php echo ! empty( $row['missing'] ) ? 'quota-expired' : 'quota-active'; ?>">
										<?php echo esc_html( (string) $row['status'] ); ?>
									</span>
								</td>
								<td><?php echo wp_kses_post( (string) $row['preview_html'] ); ?></td>
								<td><?php echo esc_html( (string) $row['workflow'] ); ?></td>
								<td><?php echo esc_html( (string) ( $row['uploaded_at'] ?: '—' ) ); ?></td>
								<td class="adam-admin-row-actions">
									<?php if ( '' !== (string) $row['url'] ) : ?>
										<a class="button button-small" href="<?php echo esc_url( (string) $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir', 'adam-membership' ); ?></a>
										<a class="button button-small" href="<?php echo esc_url( (string) $row['url'] ); ?>" download><?php esc_html_e( 'Descarregar', 'adam-membership' ); ?></a>
									<?php endif; ?>
									<?php if ( $show_management && $member instanceof Member ) : ?>
										<?php $this->render_document_management_controls( $member, (string) $row['meta_key'] ); ?>
									<?php elseif ( $show_management && $request instanceof RenewalRequest ) : ?>
										<?php $this->render_renewal_document_management_controls( $request, (string) $row['meta_key'] ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
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
		<div class="adam-admin-notice error">
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
	 */
	private function render_document_management_controls( Member $member, string $meta_key ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="adam-admin-inline-form">
			<input type="hidden" name="action" value="adam_membership_member_action">
			<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REPLACE_DOCUMENT ); ?>">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
			<input type="hidden" name="document_field" value="<?php echo esc_attr( $meta_key ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
			<input type="file" name="member_document_file" accept=".pdf,image/*">
			<button type="submit" class="button button-small"><?php esc_html_e( 'Substituir', 'adam-membership' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
			<input type="hidden" name="action" value="adam_membership_member_action">
			<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_REMOVE_DOCUMENT ); ?>">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
			<input type="hidden" name="document_field" value="<?php echo esc_attr( $meta_key ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
			<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Remover', 'adam-membership' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render replace/remove controls for a renewal document.
	 *
	 * @param RenewalRequest $request Renewal request.
	 * @param string         $meta_key Meta key.
	 */
	private function render_renewal_document_management_controls( RenewalRequest $request, string $meta_key ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="adam-admin-inline-form">
			<input type="hidden" name="action" value="adam_membership_renewal_action">
			<input type="hidden" name="renewal_action" value="<?php echo esc_attr( self::ACTION_REPLACE_RENEWAL_DOCUMENT ); ?>">
			<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>">
			<input type="hidden" name="document_field" value="<?php echo esc_attr( $meta_key ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->renewal_url( $request ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_renewal_action_' . $request->id() ); ?>
			<input type="file" name="member_document_file" accept=".pdf,image/*">
			<button type="submit" class="button button-small"><?php esc_html_e( 'Substituir', 'adam-membership' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
			<input type="hidden" name="action" value="adam_membership_renewal_action">
			<input type="hidden" name="renewal_action" value="<?php echo esc_attr( self::ACTION_REMOVE_RENEWAL_DOCUMENT ); ?>">
			<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request->id() ); ?>">
			<input type="hidden" name="document_field" value="<?php echo esc_attr( $meta_key ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->renewal_url( $request ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_renewal_action_' . $request->id() ); ?>
			<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Remover', 'adam-membership' ); ?></button>
		</form>
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
		if ( is_numeric( $value ) ) {
			$post = get_post( absint( $value ) );

			if ( $post instanceof \WP_Post ) {
				return $this->format_datetime( (string) $post->post_date );
			}
		}

		return is_string( $fallback ) ? $this->format_datetime( $fallback ) : '';
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
			return '<span class="adam-admin-empty">' . esc_html__( 'Sem ficheiro', 'adam-membership' ) . '</span>';
		}

		if ( is_numeric( $value ) && wp_attachment_is_image( absint( $value ) ) ) {
			$image = wp_get_attachment_image( absint( $value ), array( 88, 88 ), false, array( 'class' => 'adam-admin-avatar', 'alt' => $fallback_alt ) );

			if ( is_string( $image ) && '' !== $image ) {
				return $image;
			}
		}

		$extension = strtoupper( (string) pathinfo( $url, PATHINFO_EXTENSION ) );
		$label     = '' !== $extension ? $extension : strtoupper( sanitize_text_field( $meta_key ) );

		return '<span class="adam-admin-avatar-placeholder">' . esc_html( substr( $label, 0, 4 ) ) . '</span>';
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
			<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Reject', 'adam-membership' ); ?></button>
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
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Rejeitar renovação', 'adam-membership' ); ?></button>
		</form>
		<?php
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
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Rejeitar sócio', 'adam-membership' ); ?></button>
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
	 * Render admin-only member diagnostics for status integrity debugging.
	 *
	 * @param Member $member Member.
	 */
	private function render_member_diagnostics( Member $member ): void {
		$rows = $this->member_diagnostic_rows( $member );
		?>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Diagnóstico de estado', 'adam-membership' ); ?></h2>
			<p><?php esc_html_e( 'Fonte única de verdade: o estado do sócio é guardado no campo "estado", a validade da quota é guardada em "validade_quota" e os ecrãs de frontend/admin devem ler o modelo Member para obter valores normalizados e o estado efetivo.', 'adam-membership' ); ?></p>
			<table class="widefat striped adam-admin-table">
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
			printf( '<div class="adam-admin-notice success"><p>%s</p></div>', esc_html( $message ) );
		}

		if ( '' !== $error ) {
			printf( '<div class="adam-admin-notice error"><p>%s</p></div>', esc_html( $error ) );
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
			self::ACTION_SAVE_MEMBER  => __( 'Member fields updated successfully.', 'adam-membership' ),
			self::ACTION_REGENERATE_CARD_TOKEN => __( 'Digital card validation token regenerated successfully.', 'adam-membership' ),
			self::ACTION_REPLACE_DOCUMENT => __( 'Documento substituído com sucesso.', 'adam-membership' ),
			self::ACTION_REMOVE_DOCUMENT  => __( 'Documento removido com sucesso.', 'adam-membership' ),
			default                   => __( 'Member updated successfully.', 'adam-membership' ),
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
}
