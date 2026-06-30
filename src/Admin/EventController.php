<?php
/**
 * Events admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Event\Event;
use AdamMembership\Event\EventCheckIn;
use AdamMembership\Event\EventService;
use AdamMembership\Member\Member;

/**
 * Manages the admin-side Events module.
 */
final class EventController {
	private const CAPABILITY     = 'manage_options';
	private const MENU_SLUG      = 'adam-membership-events';
	private const EDIT_PAGE_SLUG = 'adam-membership-event-edit';

	private EventService $events;

	public function __construct( EventService $events ) {
		$this->events = $events;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_adam_membership_save_event', array( $this, 'handle_save' ) );
		add_action( 'admin_post_adam_membership_delete_event', array( $this, 'handle_delete' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'adam-membership',
			esc_html__( 'Eventos', 'adam-membership' ),
			esc_html__( 'Eventos', 'adam-membership' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			'adam-membership',
			esc_html__( 'Editar Evento', 'adam-membership' ),
			esc_html__( 'Editar Evento', 'adam-membership' ),
			self::CAPABILITY,
			self::EDIT_PAGE_SLUG,
			array( $this, 'render_edit_page' )
		);
	}

	public function render_list_page(): void {
		$this->ensure_can_manage();
		$filters = $this->current_filters();
		$events  = $this->events->admin_events( $filters );
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar">
				<h1><?php esc_html_e( 'Eventos', 'adam-membership' ); ?></h1>
			</div>
			<?php $this->render_notices(); ?>
			<div class="adam-admin-actions">
				<a class="button button-primary" href="<?php echo esc_url( $this->edit_url() ); ?>"><?php esc_html_e( 'Novo evento', 'adam-membership' ); ?></a>
			</div>
			<form method="get" class="adam-admin-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<label>
					<span><?php esc_html_e( 'Pesquisar', 'adam-membership' ); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
					<select name="status">
						<?php $this->render_select_option( '', __( 'Todos', 'adam-membership' ), $filters['status'] ); ?>
						<?php foreach ( Event::statuses() as $status ) : ?>
							<?php $this->render_select_option( $status, $this->status_label( $status ), $filters['status'] ); ?>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Filtrar', 'adam-membership' ); ?></button>
			</form>
			<?php if ( array() === $events ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Ainda não existem eventos.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Título', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Data', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Local', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Fornecedor externo', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Check-ins', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Ações', 'adam-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $event ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $event->title() ); ?></strong><br><small><?php echo esc_html( $event->slug() ); ?></small></td>
								<td><?php echo esc_html( $this->format_date( $event->event_date() ) ); ?></td>
								<td><?php echo esc_html( $event->location() ); ?></td>
								<td><?php echo esc_html( $event->external_provider_name() ); ?></td>
								<td><?php echo esc_html( (string) $this->events->checked_in_count( $event ) ); ?></td>
								<td><?php $this->render_badge( $this->status_label( $event->status() ), 'event-status-' . $event->status() ); ?></td>
								<td class="adam-admin-row-actions">
									<a class="button button-small" href="<?php echo esc_url( $this->edit_url( $event->id() ) ); ?>"><?php esc_html_e( 'Editar', 'adam-membership' ); ?></a>
									<a class="button button-small" href="<?php echo esc_url( $this->events->event_url( $event ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ver', 'adam-membership' ); ?></a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Eliminar este evento?', 'adam-membership' ) ); ?>');">
										<input type="hidden" name="action" value="adam_membership_delete_event">
										<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>">
										<?php wp_nonce_field( 'adam_membership_delete_event_' . $event->id() ); ?>
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Eliminar', 'adam-membership' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_edit_page(): void {
		$this->ensure_can_manage();
		$event = $this->current_event();
		$title = null === $event ? __( 'Novo evento', 'adam-membership' ) : __( 'Editar evento', 'adam-membership' );
		?>
		<div class="wrap adam-admin-wrap">
			<div class="adam-admin-titlebar">
				<h1><?php echo esc_html( $title ); ?></h1>
			</div>
			<?php $this->render_notices(); ?>
			<div class="adam-admin-panel">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-edit-form">
					<input type="hidden" name="action" value="adam_membership_save_event">
					<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) ( null !== $event ? $event->id() : 0 ) ); ?>">
					<?php if ( null !== $event ) : ?>
						<input type="hidden" name="checkin_token" value="<?php echo esc_attr( $event->checkin_token() ); ?>">
					<?php endif; ?>
					<?php wp_nonce_field( 'adam_membership_save_event' ); ?>
					<div class="adam-admin-edit-grid">
						<label><span><?php esc_html_e( 'Título', 'adam-membership' ); ?></span><input type="text" name="title" required value="<?php echo esc_attr( null !== $event ? $event->title() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Data do evento', 'adam-membership' ); ?></span><input type="date" name="event_date" required value="<?php echo esc_attr( null !== $event ? $event->event_date() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Hora de início', 'adam-membership' ); ?></span><input type="time" name="start_time" value="<?php echo esc_attr( null !== $event ? $event->start_time() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Hora de fim', 'adam-membership' ); ?></span><input type="time" name="end_time" value="<?php echo esc_attr( null !== $event ? $event->end_time() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Local', 'adam-membership' ); ?></span><input type="text" name="location" value="<?php echo esc_attr( null !== $event ? $event->location() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Ligação do mapa', 'adam-membership' ); ?></span><input type="url" name="map_link" value="<?php echo esc_attr( null !== $event ? $event->map_link() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Imagem de capa', 'adam-membership' ); ?></span><input type="url" name="cover_image" value="<?php echo esc_attr( null !== $event ? $event->cover_image() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Fornecedor externo', 'adam-membership' ); ?></span><input type="text" name="external_provider_name" value="<?php echo esc_attr( null !== $event ? $event->external_provider_name() : 'Jogar Airsoft' ); ?>"></label>
						<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'URL externa de inscrição', 'adam-membership' ); ?></span><input type="url" name="external_registration_url" value="<?php echo esc_attr( null !== $event ? $event->external_registration_url() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Limite de jogadores', 'adam-membership' ); ?></span><input type="number" min="0" name="player_limit" value="<?php echo esc_attr( null !== $event ? (string) $event->player_limit() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Preço', 'adam-membership' ); ?></span><input type="text" name="price" value="<?php echo esc_attr( null !== $event ? $event->price() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
							<select name="status">
								<?php foreach ( Event::statuses() as $status ) : ?>
									<?php $this->render_select_option( $status, $this->status_label( $status ), null !== $event ? $event->status() : Event::STATUS_DRAFT ); ?>
								<?php endforeach; ?>
							</select>
						</label>
						<label><span><?php esc_html_e( 'Pontos atribuídos no check-in', 'adam-membership' ); ?></span><input type="number" min="0" name="checkin_points" value="<?php echo esc_attr( null !== $event ? (string) $event->checkin_points() : '1' ); ?>"></label>
						<label><span><?php esc_html_e( 'Abertura do check-in', 'adam-membership' ); ?></span><input type="datetime-local" name="checkin_open_at" value="<?php echo esc_attr( null !== $event ? $this->datetime_local_value( $event->checkin_open_at() ) : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Fecho do check-in', 'adam-membership' ); ?></span><input type="datetime-local" name="checkin_close_at" value="<?php echo esc_attr( null !== $event ? $this->datetime_local_value( $event->checkin_close_at() ) : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Posição de disparo do bónus', 'adam-membership' ); ?></span><input type="number" min="0" name="checkin_bonus_trigger_position" value="<?php echo esc_attr( null !== $event ? (string) $event->checkin_bonus_trigger_position() : '0' ); ?>"></label>
						<label><span><?php esc_html_e( 'Pontos do bónus', 'adam-membership' ); ?></span><input type="number" min="0" name="checkin_bonus_points" value="<?php echo esc_attr( null !== $event ? (string) $event->checkin_bonus_points() : '0' ); ?>"></label>
						<label><span><?php esc_html_e( 'Mensagem de parabéns', 'adam-membership' ); ?></span>
							<select name="checkin_bonus_template">
								<?php foreach ( $this->events->bonus_message_templates() as $template => $label ) : ?>
									<?php $this->render_select_option( $template, $label, null !== $event ? $event->checkin_bonus_template() : 'bonus_unlocked' ); ?>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<label class="adam-admin-checkbox-field"><input type="checkbox" name="is_paid" value="1" <?php checked( null !== $event ? $event->is_paid() : false ); ?>> <?php esc_html_e( 'Evento pago', 'adam-membership' ); ?></label>
					<label class="adam-admin-checkbox-field"><input type="checkbox" name="checkin_enabled" value="1" <?php checked( null !== $event ? $event->checkin_enabled() : false ); ?>> <?php esc_html_e( 'Ativar check-in com QR', 'adam-membership' ); ?></label>
					<label class="adam-admin-checkbox-field"><input type="checkbox" name="checkin_bonus_enabled" value="1" <?php checked( null !== $event ? $event->checkin_bonus_enabled() : false ); ?>> <?php esc_html_e( 'Ativar bónus surpresa no check-in', 'adam-membership' ); ?></label>
					<label class="adam-admin-checkbox-field"><input type="checkbox" name="checkin_bonus_count_manual" value="1" <?php checked( null !== $event ? $event->checkin_bonus_count_manual() : false ); ?>> <?php esc_html_e( 'Contar check-ins manuais para o disparo do bónus', 'adam-membership' ); ?></label>
					<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Descrição curta', 'adam-membership' ); ?></span><textarea name="short_description" rows="3"><?php echo esc_textarea( null !== $event ? $event->short_description() : '' ); ?></textarea></label>
					<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Descrição completa', 'adam-membership' ); ?></span><textarea name="full_description" rows="8"><?php echo esc_textarea( null !== $event ? wp_strip_all_tags( $event->full_description() ) : '' ); ?></textarea></label>
					<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Notas', 'adam-membership' ); ?></span><textarea name="notes" rows="4"><?php echo esc_textarea( null !== $event ? $event->notes() : '' ); ?></textarea></label>
					<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Mensagem personalizada do bónus', 'adam-membership' ); ?></span><textarea name="checkin_bonus_custom_message" rows="4"><?php echo esc_textarea( null !== $event ? $event->checkin_bonus_custom_message() : '' ); ?></textarea></label>
					<label class="adam-admin-checkbox-field"><input type="checkbox" name="image_video_notice_disabled" value="1" <?php checked( null !== $event ? $event->image_video_notice_disabled() : false ); ?>> <?php esc_html_e( 'Desativar aviso de imagem e video nesta pagina de evento', 'adam-membership' ); ?></label>
					<div class="adam-admin-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar evento', 'adam-membership' ); ?></button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Voltar à lista', 'adam-membership' ); ?></a>
					</div>
				</form>
			</div>

			<?php if ( null !== $event ) : ?>
				<div class="adam-admin-panel">
					<div class="adam-admin-actions">
						<a class="button" href="<?php echo esc_url( $this->events->event_url( $event ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir página do evento', 'adam-membership' ); ?></a>
					</div>
				</div>
				<?php $this->render_checkin_panel( $event ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_save(): void {
		$this->ensure_can_manage();
		check_admin_referer( 'adam_membership_save_event' );
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$result   = $this->events->save_event( $_POST, $event_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), $this->edit_url( $event_id ) );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Evento guardado com sucesso.', 'adam-membership' ), $this->edit_url( $result->id() ) );
	}

	public function handle_delete(): void {
		$this->ensure_can_manage();
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_delete_event_' . $event_id );
		$this->events->delete_event( $event_id );
		$this->redirect_with_notice( 'adam_message', __( 'Evento eliminado.', 'adam-membership' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	private function render_checkin_panel( Event $event ): void {
		$checkins = $this->events->event_checkins( $event->id() );
		$bonus    = $this->events->points()->bonus_entry_for_event( $event );
		$winner   = null !== $bonus ? Member::load( $bonus->member_id() ) : null;
		?>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'QR de check-in', 'adam-membership' ); ?></h2>
			<div class="adam-admin-edit-grid">
				<div>
					<p><strong><?php esc_html_e( 'URL de check-in', 'adam-membership' ); ?></strong></p>
					<input type="url" class="large-text" readonly value="<?php echo esc_attr( $this->events->checkin_url( $event ) ); ?>">
					<p><strong><?php esc_html_e( 'Check-ins registados', 'adam-membership' ); ?>:</strong> <?php echo esc_html( (string) count( $checkins ) ); ?></p>
				</div>
				<div>
					<img src="<?php echo esc_url( $this->events->checkin_qr_image_url( $event ) ); ?>" alt="<?php esc_attr_e( 'QR code de check-in do evento', 'adam-membership' ); ?>" style="max-width:220px;height:auto;">
				</div>
			</div>
			<div class="adam-admin-actions">
				<a class="button" href="<?php echo esc_url( $this->events->checkin_qr_image_url( $event ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir / descarregar QR', 'adam-membership' ); ?></a>
				<a class="button" href="<?php echo esc_url( $this->events->checkin_url( $event ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir página de check-in', 'adam-membership' ); ?></a>
			</div>
		</div>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Bónus surpresa do check-in', 'adam-membership' ); ?></h2>
			<div class="adam-admin-edit-grid">
				<div><p><strong><?php esc_html_e( 'Ativado', 'adam-membership' ); ?>:</strong> <?php echo esc_html( $event->checkin_bonus_enabled() ? __( 'Sim', 'adam-membership' ) : __( 'Não', 'adam-membership' ) ); ?></p></div>
				<div><p><strong><?php esc_html_e( 'Posição de disparo', 'adam-membership' ); ?>:</strong> <?php echo esc_html( (string) $event->checkin_bonus_trigger_position() ); ?></p></div>
				<div><p><strong><?php esc_html_e( 'Pontos do bónus', 'adam-membership' ); ?>:</strong> <?php echo esc_html( '+' . $event->checkin_bonus_points() ); ?></p></div>
				<div><p><strong><?php esc_html_e( 'Check-ins manuais contam', 'adam-membership' ); ?>:</strong> <?php echo esc_html( $event->checkin_bonus_count_manual() ? __( 'Sim', 'adam-membership' ) : __( 'Não', 'adam-membership' ) ); ?></p></div>
			</div>
			<p><strong><?php esc_html_e( 'Mensagem configurada', 'adam-membership' ); ?>:</strong></p>
			<div class="adam-admin-empty"><?php echo esc_html( $this->events->bonus_congratulations_message( $event ) ); ?></div>
			<p><strong><?php esc_html_e( 'Estado do bónus', 'adam-membership' ); ?>:</strong> <?php echo esc_html( null !== $bonus ? __( 'Já atribuído', 'adam-membership' ) : __( 'Por atribuir', 'adam-membership' ) ); ?></p>
			<?php if ( null !== $bonus ) : ?>
				<p><strong><?php esc_html_e( 'Sócio premiado', 'adam-membership' ); ?>:</strong> <?php echo esc_html( null !== $winner ? $winner->full_name() : __( 'Sócio removido', 'adam-membership' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Data de atribuição', 'adam-membership' ); ?>:</strong> <?php echo esc_html( $this->format_datetime( $bonus->created_at() ) ); ?></p>
			<?php endif; ?>
		</div>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Sócios com check-in', 'adam-membership' ); ?></h2>
			<?php if ( array() === $checkins ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Ainda não existem check-ins registados para este evento.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Sócio', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'N.º de sócio', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Pontos', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Data do check-in', 'adam-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $checkins as $checkin ) : ?>
							<?php $this->render_checkin_row( $checkin ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_checkin_row( EventCheckIn $checkin ): void {
		$item = Member::load( $checkin->member_id() );
		?>
		<tr>
			<td><?php echo esc_html( null !== $item ? $item->full_name() : __( 'Sócio removido', 'adam-membership' ) ); ?></td>
			<td><?php echo esc_html( null !== $item ? (string) $item->field( 'numero_socio' ) : ''); ?></td>
			<td><?php echo esc_html( '+' . $checkin->points_awarded() ); ?></td>
			<td><?php echo esc_html( $this->format_datetime( $checkin->checked_in_at() ) ); ?></td>
		</tr>
		<?php
	}

	private function current_event(): ?Event {
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;

		return $event_id > 0 ? $this->events->repository()->find_event( $event_id ) : null;
	}

	/**
	 * @return array<string, string>
	 */
	private function current_filters(): array {
		return array(
			'search' => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
		);
	}

	private function ensure_can_manage(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Não tem permissão para gerir eventos ADAM.', 'adam-membership' ) );
		}
	}

	private function edit_url( int $event_id = 0 ): string {
		$args = array( 'page' => self::EDIT_PAGE_SLUG );

		if ( $event_id > 0 ) {
			$args['event_id'] = $event_id;
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

	private function render_select_option( string $value, string $label, string $current ): void {
		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}

	private function render_badge( string $label, string $class ): void {
		printf( '<span class="adam-admin-badge %1$s">%2$s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	private function redirect_with_notice( string $key, string $message, string $redirect ): void {
		wp_safe_redirect( add_query_arg( $key, $message, $redirect ) );
		exit;
	}

	private function status_label( string $status ): string {
		return match ( $status ) {
			Event::STATUS_PUBLISHED => __( 'Publicado', 'adam-membership' ),
			Event::STATUS_CANCELLED => __( 'Cancelado', 'adam-membership' ),
			Event::STATUS_COMPLETED => __( 'Concluído', 'adam-membership' ),
			default                 => __( 'Rascunho', 'adam-membership' ),
		};
	}

	private function format_date( string $date ): string {
		$timestamp = strtotime( $date . ' 00:00:00' );

		return false === $timestamp ? $date : wp_date( get_option( 'date_format' ), $timestamp );
	}

	private function format_datetime( string $datetime ): string {
		$timestamp = strtotime( $datetime );

		return false === $timestamp ? $datetime : wp_date( get_option( 'date_format' ) . ' H:i', $timestamp );
	}

	private function datetime_local_value( string $value ): string {
		return '' !== $value ? str_replace( ' ', 'T', $value ) : '';
	}
}
