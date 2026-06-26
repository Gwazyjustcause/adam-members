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
use WP_Error;

/**
 * Registers and renders ADAM Membership admin pages.
 */
final class AdminController {
	private const CAPABILITY = 'manage_options';
	private const MENU_SLUG  = 'adam-membership';

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
	 * Create the admin controller.
	 *
	 * @param MemberRepository   $members          Member repository.
	 * @param ApprovalService    $approval_service Approval service.
	 * @param SettingsRepository $settings         Settings repository.
	 * @param Logger             $logger           Logger helper.
	 */
	public function __construct( MemberRepository $members, ApprovalService $approval_service, SettingsRepository $settings, Logger $logger ) {
		$this->members          = $members;
		$this->approval_service = $approval_service;
		$this->settings         = $settings;
		$this->logger           = $logger;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_adam_membership_approve_member', array( $this, 'handle_approve_member' ) );
		add_action( 'admin_post_adam_membership_reject_member', array( $this, 'handle_reject_member' ) );
	}

	/**
	 * Register admin menu pages.
	 */
	public function register_menu(): void {
		add_menu_page(
			esc_html__( 'ADAM', 'adam-membership' ),
			esc_html__( 'ADAM', 'adam-membership' ),
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
			esc_html__( 'Membros Pendentes', 'adam-membership' ),
			esc_html__( 'Membros Pendentes', 'adam-membership' ),
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
			esc_html__( 'Configurações', 'adam-membership' ),
			esc_html__( 'Configurações', 'adam-membership' ),
			self::CAPABILITY,
			'adam-membership-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the dashboard page.
	 */
	public function render_dashboard_page(): void {
		$this->render_header( __( 'Painel ADAM', 'adam-membership' ) );
		$this->render_notices();
		?>
		<p><?php esc_html_e( 'Use the membership pages to review pending applications and manage member records.', 'adam-membership' ); ?></p>
		<?php
		$this->render_footer();
	}

	/**
	 * Render the pending members page.
	 */
	public function render_pending_members_page(): void {
		$members = $this->members->pending_members();

		$this->render_header( __( 'Membros Pendentes', 'adam-membership' ) );
		$this->render_notices();
		$this->render_members_table( $members, true );
		$this->render_footer();
	}

	/**
	 * Render the members page.
	 */
	public function render_members_page(): void {
		$members = $this->members->all_members();

		$this->render_header( __( 'Membros', 'adam-membership' ) );
		$this->render_notices();
		$this->render_members_table( $members, false );
		$this->render_footer();
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page(): void {
		$this->render_header( __( 'Configurações da ADAM', 'adam-membership' ) );
		$this->render_notices();
		?>
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
				<th scope="row"><?php esc_html_e( 'URL da Área de Sócio', 'adam-membership' ); ?></th>
				<td><a href="<?php echo esc_url( $this->settings->member_area_url() ); ?>"><?php echo esc_html( $this->settings->member_area_url() ); ?></a></td>
			</tr>
		</table>
		<?php
		$this->render_footer();
	}

	/**
	 * Handle member approval requests.
	 */
	public function handle_approve_member(): void {
		$this->handle_member_action( 'approve' );
	}

	/**
	 * Handle member rejection requests.
	 */
	public function handle_reject_member(): void {
		$this->handle_member_action( 'reject' );
	}

	/**
	 * Render a member table.
	 *
	 * @param array<int, Member> $members      Members to render.
	 * @param bool               $show_actions Whether to show approval actions.
	 */
	private function render_members_table( array $members, bool $show_actions ): void {
		if ( array() === $members ) {
			?>
			<p><?php esc_html_e( 'Não foram encontrados sócios.', 'adam-membership' ); ?></p>
			<?php
			return;
		}

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Foto', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Nome', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Email', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Telemovel', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Equipa', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Data de Registo', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'N.º de Sócio', 'adam-membership' ); ?></th>
					<th><?php esc_html_e( 'Comprovativo', 'adam-membership' ); ?></th>
					<?php if ( $show_actions ) : ?>
						<th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $members as $member ) : ?>
					<tr>
						<td><?php $this->render_profile_photo( $member ); ?></td>
						<td><?php echo esc_html( $member->full_name() ); ?></td>
						<td><a href="mailto:<?php echo esc_attr( $member->email() ); ?>"><?php echo esc_html( $member->email() ); ?></a></td>
						<td><?php echo esc_html( (string) $member->field( 'telefone' ) ); ?></td>
						<td><?php echo esc_html( (string) $member->field( 'equipa' ) ); ?></td>
						<td><?php echo esc_html( $member->registration_date() ); ?></td>
						<td><?php echo esc_html( $member->status() ); ?></td>
						<td><?php echo esc_html( (string) $member->field( 'numero_socio' ) ); ?></td>
						<td><?php $this->render_payment_receipt( $member ); ?></td>
						<?php if ( $show_actions ) : ?>
							<td><?php $this->render_pending_actions( $member ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Handle an approval workflow action.
	 *
	 * @param string $action Approval workflow action.
	 */
	private function handle_member_action( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Não tem permissões para gerir os sócios da ADAM.', 'adam-membership' ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;

		check_admin_referer( 'adam_membership_member_action_' . $user_id );

		if ( 0 === $user_id ) {
			$this->redirect_with_error( __( 'Sócio inválido.', 'adam-membership' ) );
		}

		$result = 'approve' === $action ? $this->approval_service->approve( $user_id ) : $this->approval_service->reject( $user_id );

		if ( $result instanceof WP_Error ) {
			$this->logger->error( 'Admin member action failed.', array( 'error' => $result->get_error_message() ) );
			$this->redirect_with_error( $result->get_error_message() );
		}

		$message = 'approve' === $action ? __( 'Sócio aprovado com sucesso.', 'adam-membership' ) : __( 'Sócio rejeitado com sucesso.', 'adam-membership' );
		$this->redirect_with_message( $message );
	}

	/**
	 * Render a member profile photo.
	 *
	 * @param Member $member Member model.
	 */
	private function render_profile_photo( Member $member ): void {
		$photo_url = $member->media_url( 'profile_photo' );

		if ( '' === $photo_url ) {
			echo '&mdash;';
			return;
		}

		printf(
			'<img src="%1$s" alt="%2$s" style="width:48px;height:48px;object-fit:cover;border-radius:4px;" />',
			esc_url( $photo_url ),
			esc_attr( $member->full_name() )
		);
	}

	/**
	 * Render the payment receipt link.
	 *
	 * @param Member $member Member model.
	 */
	private function render_payment_receipt( Member $member ): void {
		$receipt_url = $member->media_url( 'payment_receipt' );

		if ( '' === $receipt_url ) {
			echo '&mdash;';
			return;
		}

		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $receipt_url ),
			esc_html__( 'Download', 'adam-membership' )
		);
	}

	/**
	 * Render pending member actions.
	 *
	 * @param Member $member Member model.
	 */
	private function render_pending_actions( Member $member ): void {
		$user_id = $member->user_id();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;">
			<input type="hidden" name="action" value="adam_membership_approve_member" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" />
			<?php wp_nonce_field( 'adam_membership_member_action_' . $user_id ); ?>
			<?php submit_button( __( 'Aprovar', 'adam-membership' ), 'primary small', 'submit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
			<input type="hidden" name="action" value="adam_membership_reject_member" />
			<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user_id ); ?>" />
			<?php wp_nonce_field( 'adam_membership_member_action_' . $user_id ); ?>
			<?php submit_button( __( 'Rejeitar', 'adam-membership' ), 'delete small', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render page header markup.
	 *
	 * @param string $title Page title.
	 */
	private function render_header( string $title ): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $title ); ?></h1>
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
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}

		if ( '' !== $error ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $error ) );
		}
	}

	/**
	 * Redirect to the pending members page with a success message.
	 *
	 * @param string $message Success message.
	 */
	private function redirect_with_message( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'adam-membership-pending',
					'adam_message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect to the pending members page with an error message.
	 *
	 * @param string $message Error message.
	 */
	private function redirect_with_error( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'adam-membership-pending',
					'adam_error' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
