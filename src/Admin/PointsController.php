<?php
/**
 * Points admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Event\Event;
use AdamMembership\Event\EventService;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Points\PointsEntry;
use AdamMembership\Points\PointsService;

/**
 * Manages the admin-side Points area.
 */
final class PointsController {
	private const CAPABILITY = 'manage_options';
	private const MENU_SLUG  = 'adam-membership-points';

	private PointsService $points;
	private MemberRepository $members;
	private EventService $events;

	public function __construct( PointsService $points, MemberRepository $members, EventService $events ) {
		$this->points  = $points;
		$this->members = $members;
		$this->events  = $events;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_adam_membership_adjust_points', array( $this, 'handle_adjust_points' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'adam-membership',
			esc_html__( 'Pontos', 'adam-membership' ),
			esc_html__( 'Pontos', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		$this->ensure_can_manage();

		$stats           = $this->points->dashboard_stats();
		$selected_member = $this->current_member();
		$search_term     = isset( $_GET['member_search'] ) ? sanitize_text_field( wp_unslash( $_GET['member_search'] ) ) : '';
		$search_results  = '' !== $search_term ? $this->members->admin_members( array( 'search' => $search_term ) ) : array();
		$member_history  = null !== $selected_member ? $this->points->member_history( $selected_member, array( 'limit' => 50 ) ) : array();
		$event_overview  = $this->points->event_overview( $this->events->admin_events() );
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar">
				<h1><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></h1>
			</div>
			<?php $this->render_notices(); ?>

			<div class="adam-admin-cards">
				<div class="adam-admin-card">
					<span><?php esc_html_e( 'Total de pontos atribuídos', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $stats['total_points_awarded'] ) ); ?></strong>
				</div>
				<div class="adam-admin-card">
					<span><?php esc_html_e( 'Eventos com pontos atribuídos', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $stats['total_events_that_awarded_points'] ) ); ?></strong>
				</div>
				<div class="adam-admin-card">
					<span><?php esc_html_e( 'Movimentos recentes', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( count( $stats['recent_activity'] ) ) ); ?></strong>
				</div>
			</div>

			<div class="adam-admin-panel">
				<h2><?php esc_html_e( 'Sócios com mais pontos', 'adam-membership' ); ?></h2>
				<?php if ( array() === $stats['top_members'] ) : ?>
					<div class="adam-admin-empty"><?php esc_html_e( 'Ainda não existem pontos atribuídos.', 'adam-membership' ); ?></div>
				<?php else : ?>
					<table class="widefat striped adam-admin-table">
						<thead><tr><th><?php esc_html_e( 'Sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'N.º de sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Saldo atual', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Total acumulado', 'adam-membership' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $stats['top_members'] as $row ) : ?>
								<?php /** @var Member $member */ $member = $row['member']; ?>
								<tr>
									<td><a href="<?php echo esc_url( $this->member_url( $member ) ); ?>"><?php echo esc_html( $member->full_name() ); ?></a></td>
									<td><?php echo esc_html( (string) $member->field( 'numero_socio' ) ); ?></td>
									<td><?php echo esc_html( $this->format_signed_points( (int) $row['balance'] ) ); ?></td>
									<td><?php echo esc_html( '+' . (int) $row['total_earned'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="adam-admin-panel">
				<h2><?php esc_html_e( 'Pesquisar sócio', 'adam-membership' ); ?></h2>
				<form method="get" class="adam-admin-filters">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
					<label><span><?php esc_html_e( 'Nome ou n.º de sócio', 'adam-membership' ); ?></span><input type="search" name="member_search" value="<?php echo esc_attr( $search_term ); ?>" placeholder="<?php esc_attr_e( 'Pesquisar sócio', 'adam-membership' ); ?>"></label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Pesquisar', 'adam-membership' ); ?></button>
				</form>

				<?php if ( '' !== $search_term ) : ?>
					<?php if ( array() === $search_results ) : ?>
						<div class="adam-admin-empty"><?php esc_html_e( 'Nenhum sócio encontrado para esta pesquisa.', 'adam-membership' ); ?></div>
					<?php else : ?>
						<table class="widefat striped adam-admin-table">
							<thead><tr><th><?php esc_html_e( 'Sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'N.º de sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Saldo', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $search_results as $member ) : ?>
									<tr>
										<td><?php echo esc_html( $member->full_name() ); ?></td>
										<td><?php echo esc_html( (string) $member->field( 'numero_socio' ) ); ?></td>
										<td><?php echo esc_html( $member->effective_status() ); ?></td>
										<td><?php echo esc_html( $this->format_signed_points( $this->points->current_balance( $member ) ) ); ?></td>
										<td><a class="button button-small" href="<?php echo esc_url( $this->member_url( $member, $search_term ) ); ?>"><?php esc_html_e( 'Abrir', 'adam-membership' ); ?></a></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<?php if ( null !== $selected_member ) : ?>
				<div class="adam-admin-panel">
					<h2><?php echo esc_html( sprintf( __( 'Pontos do sócio: %s', 'adam-membership' ), $selected_member->full_name() ) ); ?></h2>
					<div class="adam-admin-cards">
						<div class="adam-admin-card"><span><?php esc_html_e( 'Saldo atual', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( $this->points->current_balance( $selected_member ) ) ); ?></strong></div>
						<div class="adam-admin-card"><span><?php esc_html_e( 'Total acumulado', 'adam-membership' ); ?></span><strong><?php echo esc_html( number_format_i18n( $this->points->total_earned( $selected_member ) ) ); ?></strong></div>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-edit-form">
						<input type="hidden" name="action" value="adam_membership_adjust_points">
						<input type="hidden" name="member_id" value="<?php echo esc_attr( (string) $selected_member->user_id() ); ?>">
						<input type="hidden" name="redirect_page" value="<?php echo esc_attr( $this->member_url( $selected_member, $search_term ) ); ?>">
						<?php wp_nonce_field( 'adam_membership_adjust_points_' . $selected_member->user_id() ); ?>
						<div class="adam-admin-edit-grid">
							<label><span><?php esc_html_e( 'Ajuste de pontos', 'adam-membership' ); ?></span><input type="number" name="points" required step="1" value=""></label>
							<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Motivo', 'adam-membership' ); ?></span><input type="text" name="reason" required value=""></label>
						</div>
						<div class="adam-admin-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar ajuste', 'adam-membership' ); ?></button></div>
					</form>
				</div>

				<div class="adam-admin-panel">
					<h2><?php esc_html_e( 'Histórico completo de pontos', 'adam-membership' ); ?></h2>
					<?php if ( array() === $member_history ) : ?>
						<div class="adam-admin-empty"><?php esc_html_e( 'Este sócio ainda não tem movimentos de pontos.', 'adam-membership' ); ?></div>
					<?php else : ?>
						<table class="widefat striped adam-admin-table">
							<thead><tr><th><?php esc_html_e( 'Data', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Origem', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Motivo', 'adam-membership' ); ?></th></tr></thead>
							<tbody><?php foreach ( $member_history as $entry ) : ?><?php $this->render_history_row( $entry ); ?><?php endforeach; ?></tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="adam-admin-panel">
				<h2><?php esc_html_e( 'Atividade recente', 'adam-membership' ); ?></h2>
				<?php if ( array() === $stats['recent_activity'] ) : ?>
					<div class="adam-admin-empty"><?php esc_html_e( 'Ainda não existem movimentos recentes.', 'adam-membership' ); ?></div>
				<?php else : ?>
					<table class="widefat striped adam-admin-table">
						<thead><tr><th><?php esc_html_e( 'Sócio', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Data', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Origem', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Motivo', 'adam-membership' ); ?></th></tr></thead>
						<tbody><?php foreach ( $stats['recent_activity'] as $entry ) : ?><?php $this->render_activity_row( $entry ); ?><?php endforeach; ?></tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="adam-admin-panel">
				<h2><?php esc_html_e( 'Visão por evento', 'adam-membership' ); ?></h2>
				<?php if ( array() === $event_overview ) : ?>
					<div class="adam-admin-empty"><?php esc_html_e( 'Ainda não existem eventos configurados.', 'adam-membership' ); ?></div>
				<?php else : ?>
					<table class="widefat striped adam-admin-table">
						<thead><tr><th><?php esc_html_e( 'Evento', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Pontos configurados', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Sócios com pontos', 'adam-membership' ); ?></th><th><?php esc_html_e( 'Total atribuído', 'adam-membership' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $event_overview as $row ) : ?>
								<?php /** @var Event $event */ $event = $row['event']; ?>
								<tr>
									<td><?php echo esc_html( $event->title() ); ?></td>
									<td><?php echo esc_html( $this->event_status_label( $event->status() ) ); ?></td>
									<td><?php echo esc_html( (string) $row['configured_points'] ); ?></td>
									<td><?php echo esc_html( (string) $row['recipients'] ); ?></td>
									<td><?php echo esc_html( (string) $row['total_awarded'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function handle_adjust_points(): void {
		$this->ensure_can_manage();

		$member_id = isset( $_POST['member_id'] ) ? absint( wp_unslash( $_POST['member_id'] ) ) : 0;
		$redirect  = isset( $_POST['redirect_page'] ) ? esc_url_raw( (string) wp_unslash( $_POST['redirect_page'] ) ) : admin_url( 'admin.php?page=' . self::MENU_SLUG );
		check_admin_referer( 'adam_membership_adjust_points_' . $member_id );

		$member = $this->members->find( $member_id );

		if ( null === $member ) {
			$this->redirect_with_notice( 'adam_error', __( 'Sócio não encontrado.', 'adam-membership' ), $redirect );
		}

		$points = isset( $_POST['points'] ) ? (int) wp_unslash( $_POST['points'] ) : 0;
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$result = $this->points->adjust_member_points( $member, $points, $reason, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), $redirect );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Ajuste de pontos guardado com sucesso.', 'adam-membership' ), $redirect );
	}

	private function ensure_can_manage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Não tem permissão para gerir os Pontos ADAM.', 'adam-membership' ) );
		}
	}

	private function current_member(): ?Member {
		$member_id = isset( $_GET['member_id'] ) ? absint( wp_unslash( $_GET['member_id'] ) ) : 0;

		return $member_id > 0 ? $this->members->find( $member_id ) : null;
	}

	private function member_url( Member $member, string $search_term = '' ): string {
		$args = array(
			'page'      => self::MENU_SLUG,
			'member_id' => $member->user_id(),
		);

		if ( '' !== $search_term ) {
			$args['member_search'] = $search_term;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

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

	private function redirect_with_notice( string $key, string $message, string $redirect ): void {
		wp_safe_redirect( add_query_arg( $key, $message, $redirect ) );
		exit;
	}

	private function render_history_row( PointsEntry $entry ): void {
		?>
		<tr>
			<td><?php echo esc_html( $this->format_datetime( $entry->created_at() ) ); ?></td>
			<td><?php echo esc_html( $this->format_signed_points( $entry->points() ) ); ?></td>
			<td><?php echo esc_html( $this->points->source_label( $entry->source_type() ) ); ?></td>
			<td><?php echo esc_html( $entry->reason() ); ?></td>
		</tr>
		<?php
	}

	private function render_activity_row( PointsEntry $entry ): void {
		$member = $this->members->find( $entry->member_id() );
		?>
		<tr>
			<td><?php echo esc_html( null !== $member ? $member->full_name() : __( 'Sócio removido', 'adam-membership' ) ); ?></td>
			<td><?php echo esc_html( $this->format_datetime( $entry->created_at() ) ); ?></td>
			<td><?php echo esc_html( $this->format_signed_points( $entry->points() ) ); ?></td>
			<td><?php echo esc_html( $this->points->source_label( $entry->source_type() ) ); ?></td>
			<td><?php echo esc_html( $entry->reason() ); ?></td>
		</tr>
		<?php
	}

	private function format_signed_points( int $points ): string {
		return $points > 0 ? '+' . $points : (string) $points;
	}

	private function format_datetime( string $datetime ): string {
		$timestamp = strtotime( $datetime );

		return false === $timestamp ? $datetime : wp_date( get_option( 'date_format' ) . ' H:i', $timestamp );
	}

	private function event_status_label( string $status ): string {
		return match ( $status ) {
			Event::STATUS_PUBLISHED => __( 'Publicado', 'adam-membership' ),
			Event::STATUS_CANCELLED => __( 'Cancelado', 'adam-membership' ),
			Event::STATUS_COMPLETED => __( 'Concluído', 'adam-membership' ),
			default                 => __( 'Rascunho', 'adam-membership' ),
		};
	}
}
