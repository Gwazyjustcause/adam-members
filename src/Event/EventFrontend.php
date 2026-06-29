<?php
/**
 * Frontend events controller.
 *
 * @package AdamMembership\Event
 */

declare(strict_types=1);

namespace AdamMembership\Event;

use AdamMembership\Helpers\Logger;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Points\PointsRepository;
use AdamMembership\Points\PointsService;

/**
 * Renders /eventos/ and the member event check-in flow.
 */
final class EventFrontend {
	private const REWRITE_OPTION = 'adam_membership_events_rewrite_version';
	private const ROUTE_VERSION  = 'events-external-checkin-v1';

	private EventService $events;
	private MemberRepository $members;
	private Logger $logger;

	public function __construct( EventService $events, MemberRepository $members, Logger $logger ) {
		$this->events  = $events;
		$this->members = $members;
		$this->logger  = $logger;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_routes' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'render_route' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_routes(): void {
		add_rewrite_rule( '^eventos/?$', 'index.php?adam_events=archive', 'top' );
		add_rewrite_rule( '^eventos/check-in/([^/]+)/?$', 'index.php?adam_events=checkin&adam_event_checkin=$matches[1]', 'top' );
		add_rewrite_rule( '^eventos/([^/]+)/?$', 'index.php?adam_events=detail&adam_event=$matches[1]', 'top' );
	}

	public function maybe_flush_rewrite_rules(): void {
		if ( self::ROUTE_VERSION === (string) get_option( self::REWRITE_OPTION, '' ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, self::ROUTE_VERSION, false );
	}

	/**
	 * @param array<int, string> $vars Query vars.
	 * @return array<int, string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'adam_events';
		$vars[] = 'adam_event';
		$vars[] = 'adam_event_checkin';

		return $vars;
	}

	public static function activate(): void {
		$logger = new \AdamMembership\Helpers\Logger();
		$members = new MemberRepository();
		$points  = new PointsService( new PointsRepository(), $members, new \AdamMembership\Member\HistoryRepository(), $logger );
		$self    = new self( new EventService( new EventRepository(), $members, $logger, new \AdamMembership\Member\HistoryRepository(), $points ), $members, $logger );
		$self->register_routes();
		flush_rewrite_rules();
		update_option( self::REWRITE_OPTION, self::ROUTE_VERSION, false );
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
		delete_option( self::REWRITE_OPTION );
	}

	public function enqueue_assets(): void {
		if ( '' === $this->current_events_route() ) {
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

	public function render_route(): void {
		$route = $this->current_events_route();

		if ( '' === $route ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		get_header();
		echo '<main class="adam-events-shell">';

		if ( 'detail' === $route ) {
			$this->render_detail_page();
		} elseif ( 'checkin' === $route ) {
			$this->render_checkin_page();
		} else {
			$this->render_archive_page();
		}

		echo '</main>';
		get_footer();
		exit;
	}

	private function render_archive_page(): void {
		$events = $this->events->visible_events();
		$view   = isset( $_GET['view'] ) && 'calendar' === sanitize_key( wp_unslash( $_GET['view'] ) ) ? 'calendar' : 'list';
		$month  = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : wp_date( 'Y-m' );
		?>
		<section class="adam-events-page">
			<header class="adam-events-hero">
				<div>
					<p class="adam-events-eyebrow"><?php esc_html_e( 'ADAM', 'adam-membership' ); ?></p>
					<h1><?php esc_html_e( 'Eventos', 'adam-membership' ); ?></h1>
					<p><?php esc_html_e( 'Página oficial dos eventos ADAM, com informação completa e ligação direta para a plataforma externa de inscrição.', 'adam-membership' ); ?></p>
				</div>
				<div class="adam-events-toggle">
					<a class="adam-events-toggle-button<?php echo 'calendar' === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'view' => 'calendar', 'month' => $month ), home_url( '/eventos/' ) ) ); ?>"><?php esc_html_e( 'Calendário', 'adam-membership' ); ?></a>
					<a class="adam-events-toggle-button<?php echo 'list' === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'view' => 'list' ), home_url( '/eventos/' ) ) ); ?>"><?php esc_html_e( 'Lista', 'adam-membership' ); ?></a>
				</div>
			</header>

			<?php echo wp_kses_post( $this->frontend_notice() ); ?>

			<?php if ( array() === $events ) : ?>
				<div class="adam-events-empty"><?php esc_html_e( 'Não existem eventos publicados de momento.', 'adam-membership' ); ?></div>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( 'calendar' === $view ) : ?>
				<?php $this->render_calendar_view( $events, $month ); ?>
			<?php else : ?>
				<?php $this->render_list_view( $events ); ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private function render_detail_page(): void {
		$slug  = sanitize_title( (string) get_query_var( 'adam_event' ) );
		$event = $this->events->visible_event_by_slug( $slug );

		if ( null === $event ) {
			echo '<section class="adam-events-page"><div class="adam-events-empty">';
			esc_html_e( 'Evento não encontrado.', 'adam-membership' );
			echo '</div></section>';
			return;
		}
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
								<?php $this->render_badge( $this->event_status_label( $event->status() ), 'status-' . $event->status() ); ?>
								<?php if ( $event->is_paid() ) : ?>
									<?php $this->render_badge( __( 'Pago', 'adam-membership' ), 'event-paid' ); ?>
								<?php else : ?>
									<?php $this->render_badge( __( 'Gratuito', 'adam-membership' ), 'event-free' ); ?>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<?php echo wp_kses_post( $this->frontend_notice() ); ?>

					<div class="adam-event-meta-grid">
						<?php $this->render_meta_item( __( 'Data', 'adam-membership' ), $this->format_date( $event->event_date() ) ); ?>
						<?php $this->render_meta_item( __( 'Horário', 'adam-membership' ), $this->format_time_range( $event ) ); ?>
						<?php $this->render_meta_item( __( 'Local', 'adam-membership' ), $event->location() ); ?>
						<?php if ( $event->is_paid() && '' !== $event->price() ) : ?>
							<?php $this->render_meta_item( __( 'Preço', 'adam-membership' ), $event->price() ); ?>
						<?php endif; ?>
						<?php if ( $event->player_limit() > 0 ) : ?>
							<?php $this->render_meta_item( __( 'Limite de jogadores', 'adam-membership' ), (string) $event->player_limit() ); ?>
						<?php endif; ?>
						<?php if ( '' !== $event->map_link() ) : ?>
							<?php $this->render_meta_item( __( 'Mapa', 'adam-membership' ), '<a href="' . esc_url( $event->map_link() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abrir localização', 'adam-membership' ) . '</a>', true ); ?>
						<?php endif; ?>
					</div>

					<?php if ( '' !== $event->short_description() ) : ?>
						<p class="adam-event-short"><?php echo esc_html( $event->short_description() ); ?></p>
					<?php endif; ?>

					<div class="adam-event-description">
						<?php echo wp_kses_post( wpautop( $event->full_description() ) ); ?>
					</div>

					<?php if ( '' !== $event->notes() ) : ?>
						<div class="adam-event-registration-box">
							<h2><?php esc_html_e( 'Notas', 'adam-membership' ); ?></h2>
							<p><?php echo esc_html( $event->notes() ); ?></p>
						</div>
					<?php endif; ?>

					<div class="adam-event-registration-box">
						<h2><?php esc_html_e( 'Inscrição', 'adam-membership' ); ?></h2>
						<p><?php echo esc_html( sprintf( __( 'As inscrições são feitas através do %s.', 'adam-membership' ), $event->external_provider_name() ) ); ?></p>
						<?php if ( '' !== $event->external_registration_url() ) : ?>
							<p><a class="adam-events-primary" href="<?php echo esc_url( $event->external_registration_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $this->external_cta_label( $event ) ); ?></a></p>
						<?php else : ?>
							<div class="adam-events-notice warning"><?php esc_html_e( 'A ligação externa de inscrição ainda não foi configurada para este evento.', 'adam-membership' ); ?></div>
						<?php endif; ?>
					</div>
				</div>
			</article>
		</section>
		<?php
	}

	private function render_checkin_page(): void {
		$token = sanitize_text_field( (string) get_query_var( 'adam_event_checkin' ) );
		$event = $this->events->event_by_checkin_token( $token );

		$message_class = 'info';
		$message_lines = array();

		if ( null === $event ) {
			$message_class = 'error';
			$message_lines[] = __( 'O código de check-in deste evento é inválido ou já não está disponível.', 'adam-membership' );
		} elseif ( is_user_logged_in() ) {
			$member = $this->current_member();

			if ( ! $member instanceof Member ) {
				$message_class = 'warning';
				$message_lines[] = __( 'Esta vantagem está disponível apenas para contas com sócio ADAM associado.', 'adam-membership' );
			} else {
				$existing_checkin = $this->events->member_checkin_for_event( $event, $member );

				if ( null !== $existing_checkin ) {
					$message_class = 'success';
					$message_lines[] = __( 'Já efetuaste o check-in neste evento.', 'adam-membership' );
					$message_lines[] = sprintf(
						/* translators: %d: points previously awarded */
						__( 'Os +%d ponto(s) desta participação já foram registados.', 'adam-membership' ),
						$existing_checkin->points_awarded()
					);
					$existing_bonus = $this->events->points()->member_bonus_entry_for_event( $member, $event );

					if ( null !== $existing_bonus ) {
						$message_lines[] = $this->events->bonus_congratulations_message( $event );
					}
				} elseif ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['adam_event_checkin_submit'] ) ) {
					check_admin_referer( 'adam_membership_event_checkin_' . $token );
					$result = $this->events->check_in_member( $event, $member );

					if ( is_wp_error( $result ) ) {
						$message_class = 'warning';
						$message_lines[] = $result->get_error_message();
					} else {
						$message_class = 'success';
						$message_lines[] = __( 'Check-in efetuado com sucesso!', 'adam-membership' );
						if ( 0 === $result->checkin()->points_awarded() ) {
							$message_lines[] = __( 'O teu check-in ficou registado com sucesso.', 'adam-membership' );
						} else {
							$message_lines[] = sprintf(
							/* translators: %d: points awarded */
							__( 'Recebeste +%d ponto pela tua participação neste evento.', 'adam-membership' ),
							$result->checkin()->points_awarded()
							);
						}
						if ( $result->has_bonus() ) {
							$message_lines[] = $result->bonus_message();
						}
						$message_lines[] = __( 'Obrigado por fazeres parte da ADAM!', 'adam-membership' );
					}
				} else {
					$eligibility = $this->events->validate_checkin_eligibility( $event, $member );

					if ( is_wp_error( $eligibility ) ) {
						$message_class = 'warning';
						$message_lines[] = $eligibility->get_error_message();
					}
				}
			}
		}
		?>
		<section class="adam-events-page adam-event-checkin-page">
			<div class="adam-events-checkin-panel">
				<p class="adam-events-eyebrow"><?php esc_html_e( 'Vantagem exclusiva para sócios ADAM', 'adam-membership' ); ?></p>
				<h1><?php esc_html_e( 'Check-in de evento', 'adam-membership' ); ?></h1>
				<p class="adam-events-checkin-intro"><?php esc_html_e( 'Ao fazeres o check-in neste evento, acumulas pontos de participação que poderão ser utilizados em futuras recompensas, benefícios e campanhas da associação.', 'adam-membership' ); ?></p>

				<?php if ( null !== $event ) : ?>
					<div class="adam-event-meta-grid adam-event-meta-grid-tight">
						<?php $this->render_meta_item( __( 'Evento', 'adam-membership' ), $event->title() ); ?>
						<?php $this->render_meta_item( __( 'Data', 'adam-membership' ), $this->format_date( $event->event_date() ) ); ?>
						<?php $this->render_meta_item( __( 'Local', 'adam-membership' ), $event->location() ); ?>
						<?php $this->render_meta_item( __( 'Pontos', 'adam-membership' ), $event->checkin_points() > 0 ? '+' . $event->checkin_points() : '0' ); ?>
					</div>
				<?php endif; ?>

				<?php if ( array() !== $message_lines ) : ?>
					<div class="adam-events-notice <?php echo esc_attr( $message_class ); ?>">
						<?php foreach ( $message_lines as $line ) : ?>
							<p><?php echo esc_html( $line ); ?></p>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( null !== $event ) : ?>
					<?php if ( ! is_user_logged_in() ) : ?>
						<div class="adam-event-registration-box">
							<?php
							wp_login_form(
								array(
									'echo'           => true,
									'remember'       => true,
									'redirect'       => $this->events->checkin_url( $event ),
									'label_username' => __( 'Email ou utilizador', 'adam-membership' ),
									'label_password' => __( 'Palavra-passe', 'adam-membership' ),
									'label_log_in'   => __( 'Iniciar sessão para fazer check-in', 'adam-membership' ),
								)
							);
							?>
						</div>
					<?php else : ?>
						<?php $member = $this->current_member(); ?>
						<?php if ( $member instanceof Member && null === $this->events->member_checkin_for_event( $event, $member ) ) : ?>
							<?php $eligibility = $this->events->validate_checkin_eligibility( $event, $member ); ?>
							<?php if ( ! is_wp_error( $eligibility ) ) : ?>
								<form method="post" class="adam-event-checkin-form">
									<?php wp_nonce_field( 'adam_membership_event_checkin_' . $token ); ?>
									<button type="submit" name="adam_event_checkin_submit" value="1" class="adam-events-primary"><?php esc_html_e( 'Confirmar check-in', 'adam-membership' ); ?></button>
								</form>
							<?php endif; ?>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>

				<div class="adam-events-checkin-invite">
					<h2><?php esc_html_e( 'Ainda não és sócio da ADAM?', 'adam-membership' ); ?></h2>
					<p><?php esc_html_e( 'Os sócios ADAM têm acesso a vantagens exclusivas, incluindo o sistema de pontos por participação em eventos, futuras recompensas e outros benefícios da associação.', 'adam-membership' ); ?></p>
					<p><a class="adam-events-secondary" href="https://airsoftmondego.pt" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Torna-te sócio', 'adam-membership' ); ?></a></p>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * @param array<int, Event> $events Events.
	 */
	private function render_list_view( array $events ): void {
		echo '<div class="adam-events-list">';

		foreach ( $events as $event ) {
			?>
			<article class="adam-event-card">
				<div class="adam-event-card-top">
					<div>
						<h2><a href="<?php echo esc_url( $this->events->event_url( $event ) ); ?>"><?php echo esc_html( $event->title() ); ?></a></h2>
						<div class="adam-event-badges">
							<?php $this->render_badge( $this->event_status_label( $event->status() ), 'status-' . $event->status() ); ?>
							<?php if ( $event->is_paid() ) : ?>
								<?php $this->render_badge( __( 'Pago', 'adam-membership' ), 'event-paid' ); ?>
							<?php else : ?>
								<?php $this->render_badge( __( 'Gratuito', 'adam-membership' ), 'event-free' ); ?>
							<?php endif; ?>
						</div>
					</div>
					<div class="adam-event-count-inline">
						<?php
						if ( $event->player_limit() > 0 ) {
							echo esc_html( sprintf( __( '%d jogadores', 'adam-membership' ), $event->player_limit() ) );
						} else {
							esc_html_e( 'Sem limite divulgado', 'adam-membership' );
						}
						?>
					</div>
				</div>
				<div class="adam-event-list-meta">
					<span><?php echo esc_html( $this->format_date( $event->event_date() ) ); ?></span>
					<span><?php echo esc_html( $this->format_time_range( $event ) ); ?></span>
					<span><?php echo esc_html( $event->location() ); ?></span>
				</div>
				<p><?php echo esc_html( $event->short_description() ); ?></p>
				<div class="adam-event-card-actions">
					<a class="adam-events-primary" href="<?php echo esc_url( $this->events->event_url( $event ) ); ?>"><?php esc_html_e( 'Ver evento', 'adam-membership' ); ?></a>
				</div>
			</article>
			<?php
		}

		echo '</div>';
	}

	/**
	 * @param array<int, Event> $events Events.
	 * @param string            $month Month in Y-m.
	 */
	private function render_calendar_view( array $events, string $month ): void {
		$month = preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : wp_date( 'Y-m' );
		$start = strtotime( $month . '-01 00:00:00' );

		if ( false === $start ) {
			$start = strtotime( wp_date( 'Y-m-01 00:00:00' ) );
		}

		$start         = false === $start ? current_time( 'timestamp' ) : $start;
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
				<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'calendar', 'month' => $prev_month ), home_url( '/eventos/' ) ) ); ?>">&larr; <?php esc_html_e( 'Mês anterior', 'adam-membership' ); ?></a>
				<h2><?php echo esc_html( wp_date( 'F Y', $start ) ); ?></h2>
				<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'calendar', 'month' => $next_month ), home_url( '/eventos/' ) ) ); ?>"><?php esc_html_e( 'Mês seguinte', 'adam-membership' ); ?> &rarr;</a>
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

	private function current_events_route(): string {
		$route = sanitize_key( (string) get_query_var( 'adam_events' ) );

		if ( in_array( $route, array( 'archive', 'detail', 'checkin' ), true ) ) {
			return $route;
		}

		$request_path = $this->request_path();

		if ( 'eventos' === $request_path || is_page( 'eventos' ) ) {
			return 'archive';
		}

		if ( str_starts_with( $request_path, 'eventos/check-in/' ) ) {
			$parts = explode( '/', $request_path );
			$token = isset( $parts[2] ) ? sanitize_text_field( (string) $parts[2] ) : '';

			if ( '' !== $token ) {
				set_query_var( 'adam_event_checkin', $token );

				return 'checkin';
			}
		}

		if ( str_starts_with( $request_path, 'eventos/' ) ) {
			$parts = explode( '/', $request_path );
			$slug  = isset( $parts[1] ) ? sanitize_title( (string) $parts[1] ) : '';

			if ( '' !== $slug && 'check-in' !== $slug ) {
				set_query_var( 'adam_event', $slug );

				return 'detail';
			}
		}

		return '';
	}

	private function request_path(): string {
		global $wp;

		$request_path = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';

		if ( '' !== $request_path ) {
			return $request_path;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = trim( (string) wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$base_path   = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $base_path && str_starts_with( $path, $base_path ) ) {
			$path = ltrim( substr( $path, strlen( $base_path ) ), '/' );
		}

		return $path;
	}

	private function event_status_label( string $status ): string {
		return match ( $status ) {
			Event::STATUS_CANCELLED => __( 'Cancelado', 'adam-membership' ),
			Event::STATUS_COMPLETED => __( 'Concluído', 'adam-membership' ),
			Event::STATUS_DRAFT     => __( 'Rascunho', 'adam-membership' ),
			default                 => __( 'Publicado', 'adam-membership' ),
		};
	}

	private function format_date( string $date ): string {
		$timestamp = strtotime( $date . ' 00:00:00' );

		return false === $timestamp ? $date : wp_date( get_option( 'date_format' ), $timestamp );
	}

	private function format_time_range( Event $event ): string {
		$start = $event->start_time();
		$end   = $event->end_time();

		if ( '' === $start && '' === $end ) {
			return __( 'Horário a definir', 'adam-membership' );
		}

		return '' !== $end ? $start . ' - ' . $end : $start;
	}

	private function external_cta_label( Event $event ): string {
		if ( $event->is_paid() ) {
			return sprintf(
				/* translators: %s: external provider name */
				__( 'Inscrever / pagar no %s', 'adam-membership' ),
				$event->external_provider_name()
			);
		}

		return sprintf(
			/* translators: %s: external provider name */
			__( 'Inscrever no %s', 'adam-membership' ),
			$event->external_provider_name()
		);
	}
}
