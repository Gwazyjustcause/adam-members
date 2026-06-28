<?php
/**
 * Frontend events controller.
 *
 * @package AdamMembership\Event
 */

declare(strict_types=1);

namespace AdamMembership\Event;

use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;

/**
 * Renders /eventos/ and handles public registrations.
 */
final class EventFrontend {
	private const REWRITE_OPTION = 'adam_membership_events_rewrite_version';

	private EventService $events;
	private MemberRepository $members;

	public function __construct( EventService $events, MemberRepository $members ) {
		$this->events  = $events;
		$this->members = $members;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render_route' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_adam_membership_event_register', array( $this, 'handle_register' ) );
		add_action( 'admin_post_nopriv_adam_membership_event_register', array( $this, 'handle_register' ) );
		add_action( 'admin_post_adam_membership_event_cancel_registration', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_nopriv_adam_membership_event_cancel_registration', array( $this, 'handle_cancel' ) );
	}

	/**
	 * Register rewrite rules.
	 */
	public function register_routes(): void {
		add_rewrite_rule( '^eventos/?$', 'index.php?adam_events=archive', 'top' );
		add_rewrite_rule( '^eventos/([^/]+)/?$', 'index.php?adam_events=detail&adam_event=$matches[1]', 'top' );
	}

	/**
	 * Flush rewrites once when this module is introduced or updated.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( ADAM_MEMBERSHIP_VERSION === (string) get_option( self::REWRITE_OPTION, '' ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, ADAM_MEMBERSHIP_VERSION, false );
	}

	/**
	 * Register custom query vars.
	 *
	 * @param array<int, string> $vars Query vars.
	 * @return array<int, string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'adam_events';
		$vars[] = 'adam_event';

		return $vars;
	}

	/**
	 * Activation helper.
	 */
	public static function activate(): void {
		$self = new self( new EventService( new EventRepository(), new MemberRepository(), new \AdamMembership\Helpers\Logger(), new \AdamMembership\Member\HistoryRepository() ), new MemberRepository() );
		$self->register_routes();
		flush_rewrite_rules();
		update_option( self::REWRITE_OPTION, ADAM_MEMBERSHIP_VERSION, false );
	}

	/**
	 * Deactivation helper.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
		delete_option( self::REWRITE_OPTION );
	}

	/**
	 * Enqueue frontend assets when needed.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_events_request() ) {
			return;
		}

		$asset_path = ADAM_MEMBERSHIP_PATH . 'assets/css/events.css';

		wp_enqueue_style(
			'adam-events',
			ADAM_MEMBERSHIP_URL . 'assets/css/events.css',
			array(),
			file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : ADAM_MEMBERSHIP_VERSION
		);
	}

	/**
	 * Render custom route.
	 */
	public function render_route(): void {
		if ( ! $this->is_events_request() ) {
			return;
		}

		$route = (string) get_query_var( 'adam_events' );

		status_header( 200 );
		nocache_headers();
		get_header();
		echo '<main class="adam-events-shell">';

		if ( 'detail' === $route ) {
			$this->render_detail_page();
		} else {
			$this->render_archive_page();
		}

		echo '</main>';
		get_footer();
		exit;
	}

	/**
	 * Render /eventos/.
	 */
	private function render_archive_page(): void {
		$events = $this->events->visible_events();
		$view   = isset( $_GET['view'] ) && 'calendar' === sanitize_key( wp_unslash( $_GET['view'] ) ) ? 'calendar' : 'list';
		$month  = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : wp_date( 'Y-m' );
		?>
		<section class="adam-events-page">
			<header class="adam-events-hero">
				<div>
					<p class="adam-events-eyebrow"><?php esc_html_e( 'ADAM Events', 'adam-membership' ); ?></p>
					<h1><?php esc_html_e( 'Eventos', 'adam-membership' ); ?></h1>
					<p><?php esc_html_e( 'Agenda, inscricoes e gestao de participantes para os eventos ADAM.', 'adam-membership' ); ?></p>
				</div>
				<div class="adam-events-toggle">
					<a class="adam-events-toggle-button<?php echo 'calendar' === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'view' => 'calendar', 'month' => $month ), home_url( '/eventos/' ) ) ); ?>"><?php esc_html_e( 'Calendario', 'adam-membership' ); ?></a>
					<a class="adam-events-toggle-button<?php echo 'list' === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'view' => 'list' ), home_url( '/eventos/' ) ) ); ?>"><?php esc_html_e( 'Lista', 'adam-membership' ); ?></a>
				</div>
			</header>

			<?php echo wp_kses_post( $this->frontend_notice() ); ?>

			<?php if ( 'calendar' === $view ) : ?>
				<?php $this->render_calendar_view( $events, $month ); ?>
			<?php else : ?>
				<?php $this->render_list_view( $events ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Render one event detail page.
	 */
	private function render_detail_page(): void {
		$slug  = sanitize_title( (string) get_query_var( 'adam_event' ) );
		$event = $this->events->visible_event_by_slug( $slug );

		if ( null === $event ) {
			echo '<section class="adam-events-page"><div class="adam-events-empty">';
			esc_html_e( 'Evento nao encontrado.', 'adam-membership' );
			echo '</div></section>';
			return;
		}

		$member       = $this->current_member();
		$registration = $this->current_registration_for_event( $event, $member );
		?>
		<section class="adam-events-page adam-event-detail-page">
			<p class="adam-events-back"><a href="<?php echo esc_url( home_url( '/eventos/' ) ); ?>">&larr; <?php esc_html_e( 'Voltar aos eventos', 'adam-membership' ); ?></a></p>
			<article class="adam-event-detail-card">
				<?php if ( '' !== $event->cover_image() ) : ?>
					<div class="adam-event-cover"><img src="<?php echo esc_url( $event->cover_image() ); ?>" alt="<?php echo esc_attr( $event->title() ); ?>"></div>
				<?php endif; ?>

				<div class="adam-event-detail-content">
					<div class="adam-event-header-row">
						<div>
							<h1><?php echo esc_html( $event->title() ); ?></h1>
							<div class="adam-event-badges">
								<?php $this->render_badge( $this->access_mode_label( $event->access_mode() ), 'access-' . $event->access_mode() ); ?>
								<?php $this->render_badge( $this->event_status_label( $event->status() ), 'status-' . $event->status() ); ?>
							</div>
						</div>
						<div class="adam-event-count">
							<strong><?php echo esc_html( $this->events->confirmed_count( $event ) . ' / ' . ( $event->max_players() > 0 ? (string) $event->max_players() : __( 'Sem limite', 'adam-membership' ) ) ); ?></strong>
							<span><?php esc_html_e( 'jogadores confirmados', 'adam-membership' ); ?></span>
						</div>
					</div>

					<?php echo wp_kses_post( $this->frontend_notice() ); ?>

					<div class="adam-event-meta-grid">
						<?php $this->render_meta_item( __( 'Data', 'adam-membership' ), $this->format_date( $event->event_date() ) ); ?>
						<?php $this->render_meta_item( __( 'Horario', 'adam-membership' ), $this->format_time_range( $event ) ); ?>
						<?php $this->render_meta_item( __( 'Local', 'adam-membership' ), $event->location() ); ?>
						<?php $this->render_meta_item( __( 'Inscricoes ate', 'adam-membership' ), $this->format_datetime( $event->registration_deadline() ) ?: __( 'Sem prazo definido', 'adam-membership' ) ); ?>
						<?php if ( Event::ACCESS_MEMBER_PRIORITY === $event->access_mode() ) : ?>
							<?php $this->render_meta_item( __( 'Prioridade a socios ate', 'adam-membership' ), $this->format_datetime( $event->priority_deadline() ) ?: __( 'Nao definido', 'adam-membership' ) ); ?>
						<?php endif; ?>
						<?php if ( '' !== $event->map_link() ) : ?>
							<?php $this->render_meta_item( __( 'Mapa', 'adam-membership' ), '<a href="' . esc_url( $event->map_link() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abrir localizacao', 'adam-membership' ) . '</a>', true ); ?>
						<?php endif; ?>
					</div>

					<?php if ( '' !== $event->short_description() ) : ?>
						<p class="adam-event-short"><?php echo esc_html( $event->short_description() ); ?></p>
					<?php endif; ?>

					<div class="adam-event-description">
						<?php echo wp_kses_post( wpautop( $event->full_description() ) ); ?>
					</div>

					<div class="adam-event-registration-box">
						<?php $this->render_registration_panel( $event, $member, $registration ); ?>
					</div>
				</div>
			</article>
		</section>
		<?php
	}

	/**
	 * Render list cards.
	 *
	 * @param array<int, Event> $events Events.
	 */
	private function render_list_view( array $events ): void {
		if ( array() === $events ) {
			echo '<div class="adam-events-empty">';
			esc_html_e( 'Ainda nao existem eventos publicados.', 'adam-membership' );
			echo '</div>';
			return;
		}

		$member = $this->current_member();

		echo '<div class="adam-events-list">';

		foreach ( $events as $event ) {
			$registration = $this->current_registration_for_event( $event, $member );
			?>
			<article class="adam-event-card">
				<div class="adam-event-card-top">
					<div>
						<h2><a href="<?php echo esc_url( $this->events->event_url( $event ) ); ?>"><?php echo esc_html( $event->title() ); ?></a></h2>
						<div class="adam-event-badges">
							<?php $this->render_badge( $this->access_mode_label( $event->access_mode() ), 'access-' . $event->access_mode() ); ?>
							<?php $this->render_badge( $this->event_status_label( $event->status() ), 'status-' . $event->status() ); ?>
							<?php if ( null !== $registration ) : ?>
								<?php $this->render_badge( $this->registration_status_label( $registration->status() ), 'registration-' . $registration->status() ); ?>
							<?php endif; ?>
						</div>
					</div>
								<div class="adam-event-count-inline"><?php echo esc_html( $this->events->confirmed_count( $event ) . ' / ' . ( $event->max_players() > 0 ? (string) $event->max_players() : __( 'Sem limite', 'adam-membership' ) ) . ' ' . __( 'jogadores', 'adam-membership' ) ); ?></div>
				</div>
				<div class="adam-event-list-meta">
					<span><?php echo esc_html( $this->format_date( $event->event_date() ) ); ?></span>
					<span><?php echo esc_html( $this->format_time_range( $event ) ); ?></span>
					<span><?php echo esc_html( $event->location() ); ?></span>
				</div>
				<p><?php echo esc_html( $event->short_description() ); ?></p>
				<div class="adam-event-card-actions">
					<a class="adam-events-primary" href="<?php echo esc_url( $this->events->event_url( $event ) ); ?>"><?php esc_html_e( 'Ver / inscrever', 'adam-membership' ); ?></a>
				</div>
			</article>
			<?php
		}

		echo '</div>';
	}

	/**
	 * Render calendar view.
	 *
	 * @param array<int, Event> $events Events.
	 * @param string            $month  Month in Y-m.
	 */
	private function render_calendar_view( array $events, string $month ): void {
		$month = preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : wp_date( 'Y-m' );
		$start = strtotime( $month . '-01 00:00:00' );

		if ( false === $start ) {
			$start = strtotime( wp_date( 'Y-m-01 00:00:00' ) );
		}

		$start = false === $start ? current_time( 'timestamp' ) : $start;
		$days_in_month = (int) wp_date( 't', $start );
		$first_weekday = (int) wp_date( 'N', $start );
		$prev_month    = wp_date( 'Y-m', strtotime( '-1 month', $start ) ?: $start );
		$next_month    = wp_date( 'Y-m', strtotime( '+1 month', $start ) ?: $start );
		$events_by_day = array();

		foreach ( $events as $event ) {
			if ( ! str_starts_with( $event->event_date(), $month ) ) {
				continue;
			}

			$events_by_day[ $event->event_date() ][] = $event;
		}
		?>
		<div class="adam-events-calendar-wrap">
			<div class="adam-events-calendar-nav">
				<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'calendar', 'month' => $prev_month ), home_url( '/eventos/' ) ) ); ?>">&larr; <?php esc_html_e( 'Mes anterior', 'adam-membership' ); ?></a>
				<h2><?php echo esc_html( wp_date( 'F Y', $start ) ); ?></h2>
				<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'calendar', 'month' => $next_month ), home_url( '/eventos/' ) ) ); ?>"><?php esc_html_e( 'Mes seguinte', 'adam-membership' ); ?> &rarr;</a>
			</div>
			<div class="adam-events-calendar">
				<?php foreach ( array( 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab', 'Dom' ) as $weekday ) : ?>
					<div class="adam-events-calendar-head"><?php echo esc_html( $weekday ); ?></div>
				<?php endforeach; ?>

				<?php for ( $blank = 1; $blank < $first_weekday; ++$blank ) : ?>
					<div class="adam-events-calendar-day is-empty"></div>
				<?php endfor; ?>

				<?php for ( $day = 1; $day <= $days_in_month; ++$day ) : ?>
					<?php $date_key = $month . '-' . str_pad( (string) $day, 2, '0', STR_PAD_LEFT ); ?>
					<div class="adam-events-calendar-day">
						<strong><?php echo esc_html( (string) $day ); ?></strong>
						<?php foreach ( $events_by_day[ $date_key ] ?? array() as $event ) : ?>
							<a class="adam-events-calendar-item" href="<?php echo esc_url( $this->events->event_url( $event ) ); ?>">
								<strong><?php echo esc_html( $event->title() ); ?></strong>
								<small><?php echo esc_html( $this->event_status_label( $event->status() ) ); ?></small>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endfor; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render registration panel for an event detail page.
	 */
	private function render_registration_panel( Event $event, ?Member $member, ?EventRegistration $registration ): void {
		$is_active_member = $member instanceof Member && $member->isActive();

		if ( null !== $registration ) {
			echo '<div class="adam-events-notice info">';
			echo esc_html(
				sprintf(
					/* translators: %s registration status label */
					__( 'Estado da sua inscricao: %s', 'adam-membership' ),
					$this->registration_status_label( $registration->status() )
				)
			);
			echo '</div>';
			$this->render_cancel_form( $event, $registration );
			return;
		}

		if ( ! $event->is_registration_open() ) {
			echo '<div class="adam-events-notice warning">';
			esc_html_e( 'As inscricoes para este evento estao encerradas.', 'adam-membership' );
			echo '</div>';
			return;
		}

		if ( Event::ACCESS_MEMBERS_ONLY === $event->access_mode() && ! $is_active_member ) {
			echo '<div class="adam-events-notice warning">';
			esc_html_e( 'Este evento esta disponivel apenas para socios ADAM ativos.', 'adam-membership' );
			echo '</div>';
			return;
		}

		if ( Event::ACCESS_MEMBER_PRIORITY === $event->access_mode() && $event->priority_window_open() && ! $is_active_member ) {
			echo '<div class="adam-events-notice info">';
			esc_html_e( 'Durante a fase de prioridade, os socios ADAM ativos sao confirmados primeiro. Registos de nao socios podem ficar pendentes ate ao fim da prioridade.', 'adam-membership' );
			echo '</div>';
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-event-registration-form">
			<input type="hidden" name="action" value="adam_membership_event_register">
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event->id() ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->events->event_url( $event ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_event_register_' . $event->id() ); ?>

			<?php if ( $member instanceof Member ) : ?>
				<div class="adam-events-form-grid">
					<div><label><?php esc_html_e( 'Nome', 'adam-membership' ); ?></label><input type="text" value="<?php echo esc_attr( $member->full_name() ); ?>" disabled></div>
					<div><label><?php esc_html_e( 'Email', 'adam-membership' ); ?></label><input type="email" value="<?php echo esc_attr( $member->email() ); ?>" disabled></div>
					<div><label><?php esc_html_e( 'Telefone', 'adam-membership' ); ?></label><input type="text" value="<?php echo esc_attr( (string) $member->field( 'telefone' ) ); ?>" disabled></div>
					<div><label><?php esc_html_e( 'Equipa', 'adam-membership' ); ?></label><input type="text" value="<?php echo esc_attr( (string) $member->field( 'equipa' ) ); ?>" disabled></div>
				</div>
			<?php else : ?>
				<div class="adam-events-form-grid">
					<div><label for="adam_event_name"><?php esc_html_e( 'Nome', 'adam-membership' ); ?></label><input id="adam_event_name" type="text" name="name" required></div>
					<div><label for="adam_event_email"><?php esc_html_e( 'Email', 'adam-membership' ); ?></label><input id="adam_event_email" type="email" name="email" required></div>
					<div><label for="adam_event_phone"><?php esc_html_e( 'Telefone', 'adam-membership' ); ?></label><input id="adam_event_phone" type="text" name="phone" required></div>
					<div><label for="adam_event_team"><?php esc_html_e( 'Equipa / associacao', 'adam-membership' ); ?></label><input id="adam_event_team" type="text" name="team"></div>
				</div>
			<?php endif; ?>

			<button type="submit" class="adam-events-primary"><?php esc_html_e( 'Inscrever', 'adam-membership' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Handle frontend registration.
	 */
	public function handle_register(): void {
		$event_id = isset( $_POST['event_id'] ) ? absint( wp_unslash( $_POST['event_id'] ) ) : 0;
		$redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( (string) wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/eventos/' );

		check_admin_referer( 'adam_membership_event_register_' . $event_id );

		$event = $this->events->repository()->find_event( $event_id );

		if ( null === $event ) {
			wp_safe_redirect( add_query_arg( 'adam_event_error', __( 'Evento nao encontrado.', 'adam-membership' ), $redirect ) );
			exit;
		}

		$result = $this->events->register_participant( $event, $_POST, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'adam_event_error', $result->get_error_message(), $redirect ) );
			exit;
		}

		$url = add_query_arg(
			array(
				'adam_event_message' => __( 'Inscricao registada com sucesso.', 'adam-membership' ),
				'registration_id'    => $result->id(),
				'registration_token' => $result->manage_token(),
			),
			$redirect
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle registration cancellation.
	 */
	public function handle_cancel(): void {
		$registration_id = isset( $_POST['registration_id'] ) ? absint( wp_unslash( $_POST['registration_id'] ) ) : 0;
		$token           = isset( $_POST['registration_token'] ) ? sanitize_text_field( wp_unslash( $_POST['registration_token'] ) ) : '';
		$redirect        = isset( $_POST['redirect_to'] ) ? esc_url_raw( (string) wp_unslash( $_POST['redirect_to'] ) ) : home_url( '/eventos/' );

		check_admin_referer( 'adam_membership_event_cancel_' . $registration_id );

		$registration = $this->events->repository()->find_registration( $registration_id );

		if ( null === $registration ) {
			wp_safe_redirect( add_query_arg( 'adam_event_error', __( 'Inscricao nao encontrada.', 'adam-membership' ), $redirect ) );
			exit;
		}

		$current_member = $this->current_member();
		$authorized     = ( $current_member instanceof Member && $registration->member_id() === $current_member->user_id() ) || ( '' !== $token && hash_equals( $registration->manage_token(), $token ) );

		if ( ! $authorized ) {
			wp_safe_redirect( add_query_arg( 'adam_event_error', __( 'Nao tem permissao para cancelar esta inscricao.', 'adam-membership' ), $redirect ) );
			exit;
		}

		$result = $this->events->cancel_registration( $registration );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'adam_event_error', $result->get_error_message(), $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'adam_event_message', __( 'Inscricao cancelada.', 'adam-membership' ), $redirect ) );
		exit;
	}

	/**
	 * Render cancel form.
	 */
	private function render_cancel_form( Event $event, EventRegistration $registration ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="adam-event-cancel-form">
			<input type="hidden" name="action" value="adam_membership_event_cancel_registration">
			<input type="hidden" name="registration_id" value="<?php echo esc_attr( (string) $registration->id() ); ?>">
			<input type="hidden" name="registration_token" value="<?php echo esc_attr( $registration->manage_token() ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $this->events->event_url( $event ) ); ?>">
			<?php wp_nonce_field( 'adam_membership_event_cancel_' . $registration->id() ); ?>
			<button type="submit" class="adam-events-secondary"><?php esc_html_e( 'Cancelar inscricao', 'adam-membership' ); ?></button>
		</form>
		<?php
	}

	private function render_badge( string $label, string $class ): void {
		printf( '<span class="adam-event-badge %1$s">%2$s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	private function render_meta_item( string $label, string $value, bool $allow_html = false ): void {
		?>
		<div class="adam-event-meta-item">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo $allow_html ? wp_kses_post( $value ) : esc_html( $value ); ?></strong>
		</div>
		<?php
	}

	private function frontend_notice(): string {
		$message = isset( $_GET['adam_event_message'] ) ? sanitize_text_field( wp_unslash( $_GET['adam_event_message'] ) ) : '';
		$error   = isset( $_GET['adam_event_error'] ) ? sanitize_text_field( wp_unslash( $_GET['adam_event_error'] ) ) : '';

		if ( '' !== $message ) {
			return '<div class="adam-events-notice success">' . esc_html( $message ) . '</div>';
		}

		if ( '' !== $error ) {
			return '<div class="adam-events-notice error">' . esc_html( $error ) . '</div>';
		}

		return '';
	}

	private function current_member(): ?Member {
		return is_user_logged_in() ? $this->members->find( get_current_user_id() ) : null;
	}

	private function current_registration_for_event( Event $event, ?Member $member ): ?EventRegistration {
		$token = isset( $_GET['registration_token'] ) ? sanitize_text_field( wp_unslash( $_GET['registration_token'] ) ) : '';
		$id    = isset( $_GET['registration_id'] ) ? absint( wp_unslash( $_GET['registration_id'] ) ) : 0;

		if ( $member instanceof Member ) {
			$registration = $this->events->attendee_registration( $event, $member );

			if ( null !== $registration ) {
				return $registration;
			}
		}

		if ( $id > 0 && '' !== $token ) {
			$registration = $this->events->repository()->find_registration( $id );

			if ( null !== $registration && $registration->event_id() === $event->id() && hash_equals( $registration->manage_token(), $token ) ) {
				return $registration;
			}
		}

		return null;
	}

	private function is_events_request(): bool {
		return '' !== (string) get_query_var( 'adam_events' );
	}

	private function access_mode_label( string $mode ): string {
		return match ( $mode ) {
			Event::ACCESS_OPEN            => __( 'Aberto a todos', 'adam-membership' ),
			Event::ACCESS_MEMBER_PRIORITY => __( 'Prioridade a socios', 'adam-membership' ),
			default                       => __( 'Socios ADAM', 'adam-membership' ),
		};
	}

	private function event_status_label( string $status ): string {
		return match ( $status ) {
			Event::STATUS_CANCELLED => __( 'Cancelado', 'adam-membership' ),
			Event::STATUS_COMPLETED => __( 'Concluido', 'adam-membership' ),
			Event::STATUS_DRAFT     => __( 'Rascunho', 'adam-membership' ),
			default                 => __( 'Publicado', 'adam-membership' ),
		};
	}

	private function registration_status_label( string $status ): string {
		return match ( $status ) {
			EventRegistration::STATUS_CONFIRMED   => __( 'Confirmado', 'adam-membership' ),
			EventRegistration::STATUS_PENDING     => __( 'Pendente', 'adam-membership' ),
			EventRegistration::STATUS_WAITING_LIST => __( 'Lista de espera', 'adam-membership' ),
			EventRegistration::STATUS_CANCELLED   => __( 'Cancelado', 'adam-membership' ),
			default                               => __( 'Rejeitado', 'adam-membership' ),
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

	private function format_time_range( Event $event ): string {
		$start = $event->start_time();
		$end   = $event->end_time();

		if ( '' === $start && '' === $end ) {
			return __( 'Horario a definir', 'adam-membership' );
		}

		return '' !== $end ? $start . ' - ' . $end : $start;
	}
}
