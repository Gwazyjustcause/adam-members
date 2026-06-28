<?php
/**
 * WordPress admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Helpers\Logger;
use AdamMembership\Member\ApprovalService;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Member\RenewalRepository;
use AdamMembership\Member\RenewalRequest;
use AdamMembership\Member\RenewalService;
use WP_Error;

/**
 * Registers and renders ADAM Membership admin pages.
 */
final class AdminController {
	private const CAPABILITY          = 'manage_options';
	private const MENU_SLUG           = 'adam-membership';
	private const MEMBER_PAGE_SLUG    = 'adam-membership-member';
	private const ACTION_APPROVE      = 'approve';
	private const ACTION_REJECT       = 'reject';
	private const ACTION_RENEW        = 'renew_quota';
	private const ACTION_CHANGE_QUOTA = 'change_quota_validity';
	private const ACTION_RESEND_EMAIL = 'resend_approval_email';
	private const ACTION_SAVE_MEMBER  = 'save_member';
	private const ACTION_APPROVE_RENEWAL = 'approve_renewal';
	private const ACTION_REJECT_RENEWAL  = 'reject_renewal';
	private const RENEWAL_PAGE_SLUG      = 'adam-membership-renewal-request';

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
	 * Create the admin controller.
	 *
	 * @param MemberRepository   $members          Member repository.
	 * @param ApprovalService    $approval_service Approval service.
	 * @param SettingsRepository $settings         Settings repository.
	 * @param Logger             $logger           Logger helper.
	 * @param RenewalRepository  $renewals         Renewal repository.
	 * @param RenewalService     $renewal_service  Renewal service.
	 */
	public function __construct( MemberRepository $members, ApprovalService $approval_service, SettingsRepository $settings, Logger $logger, RenewalRepository $renewals, RenewalService $renewal_service ) {
		$this->members          = $members;
		$this->approval_service = $approval_service;
		$this->settings         = $settings;
		$this->logger           = $logger;
		$this->renewal_repository = $renewals;
		$this->renewal_service    = $renewal_service;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_adam_membership_approve_member', array( $this, 'handle_approve_member' ) );
		add_action( 'admin_post_adam_membership_reject_member', array( $this, 'handle_reject_member' ) );
		add_action( 'admin_post_adam_membership_member_action', array( $this, 'handle_member_admin_action' ) );
		add_action( 'admin_post_adam_membership_renewal_action', array( $this, 'handle_renewal_admin_action' ) );
		add_action( 'admin_post_adam_membership_save_settings', array( $this, 'handle_save_settings' ) );
	}

	/**
	 * Register admin menu pages.
	 */
	public function register_menu(): void {
		add_menu_page(
			esc_html__( 'ADAM Membership', 'adam-membership' ),
			esc_html__( 'ADAM Membership', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-groups',
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Dashboard', 'adam-membership' ),
			esc_html__( 'Dashboard', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Pending Members', 'adam-membership' ),
			esc_html__( 'Pending Members', 'adam-membership' ),
			self::CAPABILITY,
			'adam-membership-pending',
			array( $this, 'render_pending_members_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Members', 'adam-membership' ),
			esc_html__( 'Members', 'adam-membership' ),
			self::CAPABILITY,
			'adam-membership-members',
			array( $this, 'render_members_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Renewal Requests', 'adam-membership' ),
			esc_html__( 'Renewal Requests', 'adam-membership' ),
			self::CAPABILITY,
			'adam-membership-renewals',
			array( $this, 'render_renewals_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Settings', 'adam-membership' ),
			esc_html__( 'Settings', 'adam-membership' ),
			self::CAPABILITY,
			'adam-membership-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			null,
			esc_html__( 'Member Details', 'adam-membership' ),
			esc_html__( 'Member Details', 'adam-membership' ),
			self::CAPABILITY,
			self::MEMBER_PAGE_SLUG,
			array( $this, 'render_member_page' )
		);

		add_submenu_page(
			null,
			esc_html__( 'Renewal Request', 'adam-membership' ),
			esc_html__( 'Renewal Request', 'adam-membership' ),
			self::CAPABILITY,
			self::RENEWAL_PAGE_SLUG,
			array( $this, 'render_renewal_page' )
		);
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

		$counts = $this->members->dashboard_counts();

		$this->render_header( __( 'ADAM Membership Dashboard', 'adam-membership' ) );
		$this->render_notices();
		$this->render_dashboard_cards( $counts );
		$this->render_dashboard_shortcuts();
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

		$this->render_header( __( 'Pending Members', 'adam-membership' ) );
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

		$this->render_header( __( 'Members', 'adam-membership' ) );
		$this->render_notices();
		$this->render_member_filters( $filters, false );
		$this->render_members_table( $members, false, $filters );
		$this->render_footer();
	}

	/**
	 * Render renewal requests page.
	 */
	public function render_renewals_page(): void {
		$this->ensure_can_manage();

		$filters  = $this->current_renewal_filters();
		$requests = $this->renewal_repository->admin_requests( $filters );

		$this->render_header( __( 'Renewal Requests', 'adam-membership' ) );
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

		$this->render_header( __( 'Renewal Review', 'adam-membership' ) );
		$this->render_notices();

		if ( null === $request ) {
			$this->render_empty_state( __( 'Renewal request not found.', 'adam-membership' ) );
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

		$this->render_header( __( 'Member Details', 'adam-membership' ) );
		$this->render_notices();

		if ( null === $member ) {
			$this->render_empty_state( __( 'Member not found.', 'adam-membership' ) );
			$this->render_footer();
			return;
		}

		$this->render_member_detail( $member );
		$this->render_footer();
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		$this->ensure_can_manage();

		$this->render_header( __( 'ADAM Settings', 'adam-membership' ) );
		$this->render_notices();
		?>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Membership numbering', 'adam-membership' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adam_membership_save_settings">
				<?php wp_nonce_field( 'adam_membership_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Last assigned member number', 'adam-membership' ); ?></th>
						<td><code><?php echo esc_html( (string) $this->settings->last_assigned_member_number() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Next member number', 'adam-membership' ); ?></th>
						<td><code><?php echo esc_html( $this->settings->preview_next_member_number() ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Member area URL', 'adam-membership' ); ?></th>
						<td><a href="<?php echo esc_url( $this->settings->member_area_url() ); ?>"><?php echo esc_html( $this->settings->member_area_url() ); ?></a></td>
					</tr>
					<tr>
						<th scope="row"><label for="adam_renewal_page_url"><?php esc_html_e( 'Renewal page URL', 'adam-membership' ); ?></label></th>
						<td><input type="url" id="adam_renewal_page_url" name="renewal_page_url" class="regular-text" value="<?php echo esc_attr( $this->settings->renewal_page_url() ); ?>"></td>
					</tr>
				</table>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'adam-membership' ); ?></button>
			</form>
		</div>
		<?php
		$this->render_footer();
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
			self::ACTION_APPROVE_RENEWAL => $this->renewal_service->approve( $request_id ),
			self::ACTION_REJECT_RENEWAL  => $this->renewal_service->reject( $request_id, $this->posted_rejection_reason() ),
			default                      => new WP_Error( 'adam_membership_invalid_renewal_action', __( 'Invalid renewal action.', 'adam-membership' ) ),
		};

		if ( $result instanceof WP_Error ) {
			$this->redirect_with_error( $result->get_error_message() );
		}

		$this->redirect_with_message( __( 'Renewal request updated successfully.', 'adam-membership' ) );
	}

	/**
	 * Save plugin settings.
	 */
	public function handle_save_settings(): void {
		$this->ensure_can_manage();
		check_admin_referer( 'adam_membership_save_settings' );

		$url = isset( $_POST['renewal_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['renewal_page_url'] ) ) : '';
		$this->settings->save_renewal_page_url( $url );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'adam-membership-settings',
					'adam_message' => __( 'Settings saved successfully.', 'adam-membership' ),
				),
				admin_url( 'admin.php' )
			)
		);
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
				<span><?php esc_html_e( 'Status', 'adam-membership' ); ?></span>
				<select name="status">
					<?php $this->render_select_option( '', __( 'All requests', 'adam-membership' ), $filters['status'] ?? '' ); ?>
					<?php $this->render_select_option( RenewalRequest::STATUS_PENDING, __( 'Pending Review', 'adam-membership' ), $filters['status'] ?? '' ); ?>
					<?php $this->render_select_option( RenewalRequest::STATUS_APPROVED, __( 'Approved', 'adam-membership' ), $filters['status'] ?? '' ); ?>
					<?php $this->render_select_option( RenewalRequest::STATUS_REJECTED, __( 'Rejected', 'adam-membership' ), $filters['status'] ?? '' ); ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Order', 'adam-membership' ); ?></span>
				<select name="order">
					<?php $this->render_select_option( 'desc', __( 'Newest first', 'adam-membership' ), $filters['order'] ?? 'desc' ); ?>
					<?php $this->render_select_option( 'asc', __( 'Oldest first', 'adam-membership' ), $filters['order'] ?? 'desc' ); ?>
				</select>
			</label>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply filters', 'adam-membership' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-renewals' ) ); ?>"><?php esc_html_e( 'Reset', 'adam-membership' ); ?></a>
		</form>
		<?php
	}

	/**
	 * Render renewal requests table.
	 *
	 * @param array<int, RenewalRequest> $requests Requests.
	 */
	private function render_renewals_table( array $requests ): void {
		if ( array() === $requests ) {
			$this->render_empty_state( __( 'No renewal requests found.', 'adam-membership' ) );
			return;
		}
		?>
		<table class="widefat striped adam-admin-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Member Number', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Member Name', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Email', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Current Quota Expiry', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Submission Date', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Renewal Status', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'adam-membership' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $requests as $request ) : ?>
					<?php $member = $this->members->find( $request->user_id() ); ?>
					<tr>
						<td><?php echo esc_html( null !== $member ? $this->member_number_label( $member ) : '—' ); ?></td>
						<td><?php echo esc_html( null !== $member ? $member->full_name() : __( 'Member not found', 'adam-membership' ) ); ?></td>
						<td><?php echo esc_html( null !== $member ? $member->email() : '—' ); ?></td>
						<td><?php echo esc_html( $this->format_date( $request->current_quota_expiry() ) ?: '—' ); ?></td>
						<td><?php echo esc_html( $request->submitted_at() ); ?></td>
						<td><?php echo esc_html( $this->renewal_status_label( $request->status() ) ); ?></td>
						<td class="adam-admin-row-actions">
							<a class="button button-small" href="<?php echo esc_url( $this->renewal_url( $request ) ); ?>"><?php esc_html_e( 'Review', 'adam-membership' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( $this->renewal_service->forminator_submission_url( $request ) ); ?>"><?php esc_html_e( 'Forminator Submission', 'adam-membership' ); ?></a>
							<?php if ( '' !== $this->renewal_service->proof_url( $request ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( $this->renewal_service->proof_url( $request ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Proof', 'adam-membership' ); ?></a>
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
		?>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Renewal request', 'adam-membership' ); ?></h2>
			<div class="adam-admin-detail-grid">
				<?php $this->render_detail_item( __( 'Status', 'adam-membership' ), $this->renewal_status_label( $request->status() ) ); ?>
				<?php $this->render_detail_item( __( 'Submission ID', 'adam-membership' ), (string) $request->submission_id() ); ?>
				<?php $this->render_detail_item( __( 'Submission date', 'adam-membership' ), $request->submitted_at() ); ?>
				<?php $this->render_detail_item( __( 'Captured quota expiry', 'adam-membership' ), $this->format_date( $request->current_quota_expiry() ) ); ?>
			</div>
			<div class="adam-admin-actions">
				<a class="button" href="<?php echo esc_url( $this->renewal_service->forminator_submission_url( $request ) ); ?>"><?php esc_html_e( 'View original Forminator submission', 'adam-membership' ); ?></a>
				<?php if ( '' !== $this->renewal_service->proof_url( $request ) ) : ?>
					<a class="button" href="<?php echo esc_url( $this->renewal_service->proof_url( $request ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View proof of payment', 'adam-membership' ); ?></a>
				<?php endif; ?>
			</div>
		</div>

		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Submitted changes', 'adam-membership' ); ?></h2>
			<?php if ( array() === $changes ) : ?>
				<?php $this->render_empty_state( __( 'No profile changes were submitted.', 'adam-membership' ) ); ?>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Field', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Current Value', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Submitted Value', 'adam-membership' ); ?></th>
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
				<h2><?php esc_html_e( 'Review decision', 'adam-membership' ); ?></h2>
				<div class="adam-admin-actions">
					<?php $this->render_renewal_action_form( $request, self::ACTION_APPROVE_RENEWAL, __( 'Approve renewal', 'adam-membership' ), 'button-primary' ); ?>
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
			array( 'label' => __( 'Total members', 'adam-membership' ), 'value' => $counts['total'] ?? 0 ),
			array( 'label' => __( 'Active members', 'adam-membership' ), 'value' => $counts['active'] ?? 0 ),
			array( 'label' => __( 'Pending members', 'adam-membership' ), 'value' => $counts['pending'] ?? 0 ),
			array( 'label' => __( 'Renewals pending', 'adam-membership' ), 'value' => $counts['renewal_pending'] ?? 0 ),
			array( 'label' => __( 'Rejected members', 'adam-membership' ), 'value' => $counts['rejected'] ?? 0 ),
			array( 'label' => __( 'Expired memberships', 'adam-membership' ), 'value' => $counts['expired'] ?? 0 ),
			array( 'label' => __( 'Expiring in 30 days', 'adam-membership' ), 'value' => $counts['expiring_soon'] ?? 0 ),
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
	private function render_dashboard_shortcuts(): void {
		?>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Quick actions', 'adam-membership' ); ?></h2>
			<div class="adam-admin-actions">
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-pending' ) ); ?>"><?php esc_html_e( 'Review pending members', 'adam-membership' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-members' ) ); ?>"><?php esc_html_e( 'Search members', 'adam-membership' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=adam-membership-members&quota_status=expiring_soon' ) ); ?>"><?php esc_html_e( 'Check renewals', 'adam-membership' ); ?></a>
			</div>
		</div>
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
				<span><?php esc_html_e( 'Search', 'adam-membership' ); ?></span>
				<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Name, email, member number', 'adam-membership' ); ?>">
			</label>

			<?php if ( ! $force_pending ) : ?>
				<label>
					<span><?php esc_html_e( 'Status', 'adam-membership' ); ?></span>
					<select name="status">
						<?php $this->render_select_option( '', __( 'All statuses', 'adam-membership' ), $filters['status'] ?? '' ); ?>
						<?php $this->render_select_option( Member::STATUS_ACTIVE, __( 'Active', 'adam-membership' ), $filters['status'] ?? '' ); ?>
						<?php $this->render_select_option( Member::STATUS_PENDING, __( 'Pending', 'adam-membership' ), $filters['status'] ?? '' ); ?>
						<?php $this->render_select_option( Member::STATUS_RENEWAL_PENDING, __( 'Renewal pending', 'adam-membership' ), $filters['status'] ?? '' ); ?>
						<?php $this->render_select_option( Member::STATUS_EXPIRED, __( 'Expired', 'adam-membership' ), $filters['status'] ?? '' ); ?>
						<?php $this->render_select_option( Member::STATUS_REJECTED, __( 'Rejected', 'adam-membership' ), $filters['status'] ?? '' ); ?>
					</select>
				</label>
			<?php endif; ?>

			<label>
				<span><?php esc_html_e( 'Quota', 'adam-membership' ); ?></span>
				<select name="quota_status">
					<?php $this->render_select_option( '', __( 'All quotas', 'adam-membership' ), $filters['quota_status'] ?? '' ); ?>
					<?php $this->render_select_option( Member::QUOTA_ACTIVE, __( 'Active', 'adam-membership' ), $filters['quota_status'] ?? '' ); ?>
					<?php $this->render_select_option( Member::QUOTA_EXPIRED, __( 'Expired', 'adam-membership' ), $filters['quota_status'] ?? '' ); ?>
					<?php $this->render_select_option( Member::QUOTA_EXPIRING_SOON, __( 'Expiring soon', 'adam-membership' ), $filters['quota_status'] ?? '' ); ?>
				</select>
			</label>

			<label>
				<span><?php esc_html_e( 'Sort', 'adam-membership' ); ?></span>
				<select name="member_number_sort">
					<?php $this->render_select_option( '', __( 'Default', 'adam-membership' ), $filters['member_number_sort'] ?? '' ); ?>
					<?php $this->render_select_option( 'asc', __( 'Member number: lowest to highest', 'adam-membership' ), $filters['member_number_sort'] ?? '' ); ?>
					<?php $this->render_select_option( 'desc', __( 'Member number: highest to lowest', 'adam-membership' ), $filters['member_number_sort'] ?? '' ); ?>
				</select>
			</label>

			<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply filters', 'adam-membership' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ( $force_pending ? 'adam-membership-pending' : 'adam-membership-members' ) ) ); ?>"><?php esc_html_e( 'Reset', 'adam-membership' ); ?></a>
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
			$this->render_empty_state( __( 'No members found for the current filters.', 'adam-membership' ) );
			return;
		}
		?>
		<table class="widefat striped adam-admin-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Photo', 'adam-membership' ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Name', 'adam-membership' ), 'name', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Email', 'adam-membership' ), 'email', $filters ) ); ?></th>
					<th><?php esc_html_e( 'Phone', 'adam-membership' ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Registered', 'adam-membership' ), 'registered', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Status', 'adam-membership' ), 'status', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Member no.', 'adam-membership' ), 'member_number', $filters ) ); ?></th>
					<th><?php echo wp_kses_post( $this->sort_link( __( 'Quota', 'adam-membership' ), 'quota', $filters ) ); ?></th>
					<th><?php esc_html_e( 'Actions', 'adam-membership' ); ?></th>
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
							<a class="button button-small" href="<?php echo esc_url( $this->member_url( $member ) ); ?>"><?php esc_html_e( 'View', 'adam-membership' ); ?></a>
							<?php if ( $show_actions ) : ?>
								<?php $this->render_inline_action_form( $member, self::ACTION_APPROVE, __( 'Approve', 'adam-membership' ), 'button-primary' ); ?>
								<?php $this->render_inline_rejection_form( $member ); ?>
							<?php endif; ?>
						</td>
					</tr>
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

				<div class="adam-admin-detail-grid">
					<?php $this->render_detail_item( __( 'Membership status', 'adam-membership' ), $member->effective_status() ); ?>
					<?php $this->render_detail_item( __( 'Member number', 'adam-membership' ), $this->member_number_label( $member ) ); ?>
					<?php $this->render_detail_item( __( 'Quota valid until', 'adam-membership' ), $this->format_date( $member->field( 'validade_quota' ) ) ); ?>
					<?php $this->render_detail_item( __( 'Joined on', 'adam-membership' ), $this->format_date( $member->field( 'data_adesao' ) ) ); ?>
					<?php $this->render_detail_item( __( 'Phone', 'adam-membership' ), (string) $member->field( 'telefone' ) ); ?>
					<?php $this->render_detail_item( __( 'Team', 'adam-membership' ), (string) $member->field( 'equipa' ) ); ?>
					<?php $this->render_detail_item( __( 'NIF', 'adam-membership' ), (string) $member->field( 'nif' ) ); ?>
					<?php $this->render_detail_item( __( 'Citizen card', 'adam-membership' ), (string) $member->field( 'cartao_cidadao' ) ); ?>
					<?php $this->render_detail_item( __( 'Birth date', 'adam-membership' ), $this->format_date( $member->field( 'data_nascimento' ) ) ); ?>
					<?php $this->render_detail_item( __( 'Address', 'adam-membership' ), (string) $member->field( 'morada' ) ); ?>
					<?php $this->render_detail_item( __( 'Rejection reason', 'adam-membership' ), (string) $member->field( 'motivo_rejeicao' ) ); ?>
					<?php $this->render_detail_item( __( 'Private rejection note', 'adam-membership' ), (string) $member->field( 'nota_rejeicao_admin' ) ); ?>
				</div>
			</div>

			<?php $this->render_member_edit_form( $member ); ?>

			<div class="adam-admin-panel">
				<h2><?php esc_html_e( 'Admin actions', 'adam-membership' ); ?></h2>
				<div class="adam-admin-action-stack">
					<?php $this->render_action_form( $member, self::ACTION_APPROVE, __( 'Approve member', 'adam-membership' ), 'button-primary' ); ?>
					<?php $this->render_rejection_form( $member ); ?>
					<?php $this->render_action_form( $member, self::ACTION_RENEW, __( 'Renew quota for one year', 'adam-membership' ), 'button-secondary' ); ?>
					<?php $this->render_action_form( $member, self::ACTION_RESEND_EMAIL, __( 'Resend approval email', 'adam-membership' ), 'button-secondary' ); ?>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-quota-form">
					<input type="hidden" name="action" value="adam_membership_member_action">
					<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_CHANGE_QUOTA ); ?>">
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
					<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>
					<label for="adam_quota_validity"><?php esc_html_e( 'Change quota validity', 'adam-membership' ); ?></label>
					<input type="date" id="adam_quota_validity" name="quota_validity" value="<?php echo esc_attr( $this->date_input_value( $member->field( 'validade_quota' ) ) ); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save validity', 'adam-membership' ); ?></button>
				</form>

				<div class="adam-admin-safe-view">
					<h3><?php esc_html_e( 'View as member', 'adam-membership' ); ?></h3>
					<?php if ( get_current_user_id() === $member->user_id() ) : ?>
						<a class="button" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open member area', 'adam-membership' ); ?></a>
					<?php else : ?>
						<p><?php esc_html_e( 'Impersonation is not enabled for safety. Use the WordPress user profile for account-level review.', 'adam-membership' ); ?></p>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( get_edit_user_link( $member->user_id() ) ); ?>"><?php esc_html_e( 'Open WordPress profile', 'adam-membership' ); ?></a>
				</div>
			</div>
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
			<h2><?php esc_html_e( 'Edit member fields', 'adam-membership' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-edit-form">
				<input type="hidden" name="action" value="adam_membership_member_action">
				<input type="hidden" name="member_action" value="<?php echo esc_attr( self::ACTION_SAVE_MEMBER ); ?>">
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $member->user_id() ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->member_url( $member ) ); ?>">
				<?php wp_nonce_field( 'adam_membership_member_action_' . $member->user_id() ); ?>

				<div class="adam-admin-edit-grid">
					<label>
						<span><?php esc_html_e( 'Member number', 'adam-membership' ); ?></span>
						<input type="text" name="member_number" value="<?php echo esc_attr( (string) $member->field( 'numero_socio' ) ); ?>" placeholder="<?php esc_attr_e( 'Unassigned', 'adam-membership' ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Quota validity date', 'adam-membership' ); ?></span>
						<input type="date" name="quota_validity" value="<?php echo esc_attr( $this->date_input_value( $member->field( 'validade_quota' ) ) ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Registration date', 'adam-membership' ); ?></span>
						<input type="date" name="registration_date" value="<?php echo esc_attr( $this->date_input_value( $member->field( 'data_adesao' ) ) ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Phone', 'adam-membership' ); ?></span>
						<input type="text" name="phone" value="<?php echo esc_attr( (string) $member->field( 'telefone' ) ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Team', 'adam-membership' ); ?></span>
						<input type="text" name="team" value="<?php echo esc_attr( (string) $member->field( 'equipa' ) ); ?>">
					</label>

					<label>
						<span><?php esc_html_e( 'Status', 'adam-membership' ); ?></span>
						<select name="status">
							<?php $this->render_select_option( Member::STATUS_PENDING, __( 'Pending', 'adam-membership' ), $member->status() ); ?>
							<?php $this->render_select_option( Member::STATUS_ACTIVE, __( 'Active', 'adam-membership' ), $member->status() ); ?>
							<?php $this->render_select_option( Member::STATUS_RENEWAL_PENDING, __( 'Renewal pending', 'adam-membership' ), $member->status() ); ?>
							<?php $this->render_select_option( Member::STATUS_EXPIRED, __( 'Expired', 'adam-membership' ), $member->status() ); ?>
							<?php if ( $member->isRejected() ) : ?>
								<?php $this->render_select_option( Member::STATUS_REJECTED, __( 'Rejected', 'adam-membership' ), $member->status() ); ?>
							<?php endif; ?>
						</select>
					</label>
				</div>

				<p class="description"><?php esc_html_e( 'Manual status edits do not send emails. Use the approval actions for the full approval workflow.', 'adam-membership' ); ?></p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save member fields', 'adam-membership' ); ?></button>
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
			<strong><?php esc_html_e( 'Administrator safeguard active', 'adam-membership' ); ?></strong>
			<p><?php esc_html_e( 'This user has WordPress administrator access. Membership status changes will not remove wp-admin or ADAM Membership admin access.', 'adam-membership' ); ?></p>
			<?php if ( $this->is_current_admin_target( $member->user_id() ) ) : ?>
				<p><?php esc_html_e( 'You cannot reject your own administrator account here. Another administrator must review that change.', 'adam-membership' ); ?></p>
			<?php endif; ?>
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
				__( 'Member not found.', 'adam-membership' )
			);
		}

		$member_number = $this->posted_member_number();

		if ( '' !== $member_number && $this->members->member_number_exists( $member_number, $user_id ) ) {
			return new WP_Error(
				'adam_membership_duplicate_member_number',
				__( 'This member number is already assigned to another member.', 'adam-membership' )
			);
		}

		$quota_validity    = $this->posted_date( 'quota_validity', __( 'Invalid quota validity date.', 'adam-membership' ) );
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

		$updates = array(
			'numero_socio'   => $member_number,
			'validade_quota' => $quota_validity,
			'data_adesao'    => $registration_date,
			'telefone'       => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'equipa'         => isset( $_POST['team'] ) ? sanitize_text_field( wp_unslash( $_POST['team'] ) ) : '',
			'estado'         => $status,
		);

		$changes = $this->changed_member_fields( $member, $updates );

		if ( array() === $changes ) {
			return true;
		}

		$member->save( $updates );
		$this->log_member_field_changes( $member, $changes );

		return true;
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
			return $this->settings->format_member_number( absint( $member_number ) );
		}

		return $member_number;
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
			Member::QUOTA_ACTIVE        => __( 'Active', 'adam-membership' ),
			Member::QUOTA_EXPIRING_SOON => __( 'Expiring soon', 'adam-membership' ),
			default                     => __( 'Expired', 'adam-membership' ),
		};

		printf(
			'<span class="adam-admin-badge quota-%1$s">%2$s</span><small>%3$s</small>',
			esc_attr( $status ),
			esc_html( $label ),
			esc_html( $this->format_date( $member->field( 'validade_quota' ) ) ?: '—' )
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
			<label class="screen-reader-text" for="adam_rejection_reason_<?php echo esc_attr( (string) $member->user_id() ); ?>"><?php esc_html_e( 'Rejection reason', 'adam-membership' ); ?></label>
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
				<span><?php esc_html_e( 'Rejection reason', 'adam-membership' ); ?></span>
				<select name="rejection_reason" required>
					<option value=""><?php esc_html_e( 'Select a reason', 'adam-membership' ); ?></option>
					<?php foreach ( $this->rejection_reasons() as $reason ) : ?>
						<option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Reject renewal', 'adam-membership' ); ?></button>
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
				<span><?php esc_html_e( 'Rejection reason', 'adam-membership' ); ?></span>
				<select name="rejection_reason" required>
					<option value=""><?php esc_html_e( 'Select a reason', 'adam-membership' ); ?></option>
					<?php foreach ( $this->rejection_reasons() as $reason ) : ?>
						<option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $reason ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Private admin note', 'adam-membership' ); ?></span>
				<textarea name="rejection_note" rows="3"></textarea>
			</label>
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Reject member', 'adam-membership' ); ?></button>
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
	 * Get a display label for a member number.
	 *
	 * @param Member $member Member.
	 */
	private function member_number_label( Member $member ): string {
		$member_number = trim( (string) $member->field( 'numero_socio' ) );

		return '' !== $member_number ? $member_number : __( 'Missing / unassigned', 'adam-membership' );
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
			RenewalRequest::STATUS_APPROVED => __( 'Approved', 'adam-membership' ),
			RenewalRequest::STATUS_REJECTED => __( 'Rejected', 'adam-membership' ),
			default                         => __( 'Pending Review', 'adam-membership' ),
		};
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
			self::ACTION_APPROVE      => __( 'Member approved successfully.', 'adam-membership' ),
			self::ACTION_REJECT       => __( 'Member rejected successfully.', 'adam-membership' ),
			self::ACTION_RENEW        => __( 'Quota renewed successfully.', 'adam-membership' ),
			self::ACTION_CHANGE_QUOTA => __( 'Quota validity updated successfully.', 'adam-membership' ),
			self::ACTION_RESEND_EMAIL => __( 'Approval email resent successfully.', 'adam-membership' ),
			self::ACTION_SAVE_MEMBER  => __( 'Member fields updated successfully.', 'adam-membership' ),
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
