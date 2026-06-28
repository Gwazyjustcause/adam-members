<?php
/**
 * Events admin controller.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Event\Event;
use AdamMembership\Event\EventRegistration;
use AdamMembership\Event\EventService;

/**
 * Manages the admin-side Events module.
 */
final class EventController {
	private const CAPABILITY      = 'manage_options';
	private const MENU_SLUG       = 'adam-membership-events';
	private const EDIT_PAGE_SLUG  = 'adam-membership-event-edit';

	private EventService $events;

	public function __construct( EventService $events ) {
		$this->events = $events;
	}

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_adam_membership_save_event', array( $this, 'handle_save' ) );
		add_action( 'admin_post_adam_membership_delete_event', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_adam_membership_update_event_registration', array( $this, 'handle_registration_status' ) );
		add_action( 'admin_post_adam_membership_export_event_csv', array( $this, 'handle_export' ) );
	}

	/**
	 * Register menu pages.
	 */
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
				<div class="adam-admin-empty"><?php esc_html_e( 'Ainda nao existem eventos.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Titulo', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Data', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Local', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Acesso', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Jogadores', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Acoes', 'adam-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $events as $event ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $event->title() ); ?></strong><br><small><?php echo esc_html( $event->slug() ); ?></small></td>
								<td><?php echo esc_html( $this->format_date( $event->event_date() ) ); ?></td>
								<td><?php echo esc_html( $event->location() ); ?></td>
								<td><?php echo esc_html( $this->access_mode_label( $event->access_mode() ) ); ?></td>
								<td><?php echo esc_html( $this->events->confirmed_count( $event ) . ' / ' . ( $event->max_players() > 0 ? (string) $event->max_players() : __( 'Sem limite', 'adam-membership' ) ) ); ?></td>
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
					<?php wp_nonce_field( 'adam_membership_save_event' ); ?>
					<div class="adam-admin-edit-grid">
						<label><span><?php esc_html_e( 'Titulo', 'adam-membership' ); ?></span><input type="text" name="title" required value="<?php echo esc_attr( null !== $event ? $event->title() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Data do evento', 'adam-membership' ); ?></span><input type="date" name="event_date" required value="<?php echo esc_attr( null !== $event ? $event->event_date() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Hora inicio', 'adam-membership' ); ?></span><input type="time" name="start_time" value="<?php echo esc_attr( null !== $event ? $event->start_time() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Hora fim', 'adam-membership' ); ?></span><input type="time" name="end_time" value="<?php echo esc_attr( null !== $event ? $event->end_time() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Local', 'adam-membership' ); ?></span><input type="text" name="location" value="<?php echo esc_attr( null !== $event ? $event->location() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Link mapa', 'adam-membership' ); ?></span><input type="url" name="map_link" value="<?php echo esc_attr( null !== $event ? $event->map_link() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Imagem de capa', 'adam-membership' ); ?></span><input type="url" name="cover_image" value="<?php echo esc_attr( null !== $event ? $event->cover_image() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Modo de acesso', 'adam-membership' ); ?></span>
							<select name="access_mode">
								<?php foreach ( Event::access_modes() as $mode ) : ?>
									<?php $this->render_select_option( $mode, $this->access_mode_label( $mode ), null !== $event ? $event->access_mode() : Event::ACCESS_MEMBERS_ONLY ); ?>
								<?php endforeach; ?>
							</select>
						</label>
						<label><span><?php esc_html_e( 'Maximo de jogadores', 'adam-membership' ); ?></span><input type="number" min="0" name="max_players" value="<?php echo esc_attr( null !== $event ? (string) $event->max_players() : '30' ); ?>"></label>
						<label><span><?php esc_html_e( 'Limite lista de espera', 'adam-membership' ); ?></span><input type="number" min="0" name="waiting_list_limit" value="<?php echo esc_attr( null !== $event ? (string) $event->waiting_list_limit() : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Fim das inscricoes', 'adam-membership' ); ?></span><input type="datetime-local" name="registration_deadline" value="<?php echo esc_attr( null !== $event ? $this->datetime_local_value( $event->registration_deadline() ) : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Fim da prioridade a socios', 'adam-membership' ); ?></span><input type="datetime-local" name="priority_deadline" value="<?php echo esc_attr( null !== $event ? $this->datetime_local_value( $event->priority_deadline() ) : '' ); ?>"></label>
						<label><span><?php esc_html_e( 'Estado', 'adam-membership' ); ?></span>
							<select name="status">
								<?php foreach ( Event::statuses() as $status ) : ?>
									<?php $this->render_select_option( $status, $this->status_label( $status ), null !== $event ? $event->status() : Event::STATUS_DRAFT ); ?>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<label class="adam-admin-checkbox-field"><input type="checkbox" name="waiting_list_enabled" value="1" <?php checked( null !== $event ? $event->waiting_list_enabled() : true ); ?>> <?php esc_html_e( 'Ativar lista de espera', 'adam-membership' ); ?></label>
					<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Descricao curta', 'adam-membership' ); ?></span><textarea name="short_description" rows="3"><?php echo esc_textarea( null !== $event ? $event->short_description() : '' ); ?></textarea></label>
					<label class="adam-admin-edit-field adam-admin-edit-field-full"><span><?php esc_html_e( 'Descricao completa', 'adam-membership' ); ?></span><textarea name="full_description" rows="8"><?php echo esc_textarea( null !== $event ? wp_strip_all_tags( $event->full_description() ) : '' ); ?></textarea></label>
					<div class="adam-admin-actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar evento', 'adam-membership' ); ?></button>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Voltar a lista', 'adam-membership' ); ?></a>
					</div>
				</form>
			</div>

			<?php if ( null !== $event ) : ?>
				<div class="adam-admin-panel">
					<div class="adam-admin-actions">
						<a class="button" href="<?php echo esc_url( $this->events->event_url( $event ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Abrir pagina do evento', 'adam-membership' ); ?></a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form">
							<input type="hidden" name="action" value="adam_membership_export_event_csv">
							<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>">
							<?php wp_nonce_field( 'adam_membership_export_event_csv_' . $event->id() ); ?>
							<button type="submit" class="button"><?php esc_html_e( 'Exportar CSV', 'adam-membership' ); ?></button>
						</form>
					</div>
				</div>
				<?php $this->render_registrations_panel( $event ); ?>
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

	public function handle_registration_status(): void {
		$this->ensure_can_manage();
		$registration_id = isset( $_POST['registration_id'] ) ? absint( wp_unslash( $_POST['registration_id'] ) ) : 0;
		$event_id        = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_update_event_registration_' . $registration_id );
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$result = $this->events->update_registration_status( $registration_id, $status );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'adam_error', $result->get_error_message(), $this->edit_url( $event_id ) );
		}

		$this->redirect_with_notice( 'adam_message', __( 'Inscricao atualizada.', 'adam-membership' ), $this->edit_url( $event_id ) );
	}

	public function handle_export(): void {
		$this->ensure_can_manage();
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		check_admin_referer( 'adam_membership_export_event_csv_' . $event_id );
		$event = $this->events->repository()->find_event( $event_id );

		if ( null === $event ) {
			$this->redirect_with_notice( 'adam_error', __( 'Evento nao encontrado.', 'adam-membership' ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		}

		$filename = 'adam-event-' . $event->slug() . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$handle = fopen( 'php://output', 'w' );

		if ( false !== $handle ) {
			fputcsv( $handle, array( 'Name', 'Email', 'Phone', 'Team', 'Status', 'Member ID', 'Created At' ) );

			foreach ( $this->events->registrations_for_event( $event->id() ) as $registration ) {
				fputcsv(
					$handle,
					array(
						$registration->name(),
						$registration->email(),
						$registration->phone(),
						$registration->team(),
						$registration->status(),
						(string) $registration->member_id(),
						$registration->created_at(),
					)
				);
			}

			fclose( $handle );
		}

		exit;
	}

	/**
	 * Render registrations panel.
	 */
	private function render_registrations_panel( Event $event ): void {
		$registrations = $this->events->registrations_for_event( $event->id() );
		?>
		<div class="adam-admin-panel">
			<h2><?php esc_html_e( 'Inscricoes', 'adam-membership' ); ?></h2>
			<?php if ( array() === $registrations ) : ?>
				<div class="adam-admin-empty"><?php esc_html_e( 'Ainda nao existem inscricoes.', 'adam-membership' ); ?></div>
			<?php else : ?>
				<table class="widefat striped adam-admin-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nome', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Email', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Telefone', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Equipa', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Data', 'adam-membership' ); ?></th>
							<th><?php esc_html_e( 'Acoes', 'adam-membership' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $registrations as $registration ) : ?>
							<tr>
								<td><?php echo esc_html( $registration->name() ); ?><?php if ( $registration->member_id() > 0 ) : ?><br><small><?php echo esc_html( 'Member #' . $registration->member_id() ); ?></small><?php endif; ?></td>
								<td><?php echo esc_html( $registration->email() ); ?></td>
								<td><?php echo esc_html( $registration->phone() ); ?></td>
								<td><?php echo esc_html( $registration->team() ); ?></td>
								<td><?php $this->render_badge( $this->registration_status_label( $registration->status() ), 'registration-status-' . $registration->status() ); ?></td>
								<td><?php echo esc_html( $this->format_datetime( $registration->created_at() ) ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-admin-inline-form adam-admin-row-actions">
										<input type="hidden" name="action" value="adam_membership_update_event_registration">
										<input type="hidden" name="registration_id" value="<?php echo esc_attr( (string) $registration->id() ); ?>">
										<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>">
										<?php wp_nonce_field( 'adam_membership_update_event_registration_' . $registration->id() ); ?>
										<select name="status">
											<?php foreach ( EventRegistration::statuses() as $status ) : ?>
												<?php $this->render_select_option( $status, $this->registration_status_label( $status ), $registration->status() ); ?>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Atualizar', 'adam-membership' ); ?></button>
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

	private function current_event(): ?Event {
		$event_id = isset( $_GET['event_id'] ) ? absint( wp_unslash( $_GET['event_id'] ) ) : 0;

		return $event_id > 0 ? $this->events->repository()->find_event( $event_id ) : null;
	}

	/**
	 * Read current filters.
	 *
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
			wp_die( esc_html__( 'Nao tem permissao para gerir eventos ADAM.', 'adam-membership' ) );
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
			Event::STATUS_COMPLETED => __( 'Concluido', 'adam-membership' ),
			default                 => __( 'Rascunho', 'adam-membership' ),
		};
	}

	private function access_mode_label( string $mode ): string {
		return match ( $mode ) {
			Event::ACCESS_OPEN            => __( 'Aberto a todos', 'adam-membership' ),
			Event::ACCESS_MEMBER_PRIORITY => __( 'Prioridade a socios', 'adam-membership' ),
			default                       => __( 'Socios ADAM', 'adam-membership' ),
		};
	}

	private function registration_status_label( string $status ): string {
		return match ( $status ) {
			EventRegistration::STATUS_CONFIRMED    => __( 'Confirmado', 'adam-membership' ),
			EventRegistration::STATUS_PENDING      => __( 'Pendente', 'adam-membership' ),
			EventRegistration::STATUS_WAITING_LIST => __( 'Lista de espera', 'adam-membership' ),
			EventRegistration::STATUS_CANCELLED    => __( 'Cancelado', 'adam-membership' ),
			default                                => __( 'Rejeitado', 'adam-membership' ),
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

	private function datetime_local_value( string $datetime ): string {
		return '' === $datetime ? '' : str_replace( ' ', 'T', $datetime );
	}
}
