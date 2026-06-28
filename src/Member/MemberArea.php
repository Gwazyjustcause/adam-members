<?php
/**
 * Member area shortcode.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Helpers\RateLimiter;

/**
 * Handles the frontend member area.
 */
final class MemberArea {

	/**
	 * Member repository.
	 *
	 * @var MemberRepository
	 */
	private MemberRepository $members;

	/**
	 * Renewal service.
	 *
	 * @var RenewalService
	 */
	private RenewalService $renewals;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Digital card service.
	 *
	 * @var CardService
	 */
	private CardService $cards;

	/**
	 * Constructor.
	 *
	 * @param MemberRepository   $members  Member repository.
	 * @param RenewalService     $renewals Renewal service.
	 * @param SettingsRepository $settings Settings repository.
	 * @param CardService        $cards    Digital card service.
	 */
	public function __construct( MemberRepository $members, RenewalService $renewals, SettingsRepository $settings, CardService $cards ) {
		$this->members  = $members;
		$this->renewals = $renewals;
		$this->settings = $settings;
		$this->cards    = $cards;
	}

	/**
	 * Register shortcode and assets.
	 */
	public function register(): void {
		add_shortcode(
			'adam_member_area',
			array( $this, 'render' )
		);

		add_action(
			'wp_enqueue_scripts',
			array( $this, 'enqueue_assets' )
		);
	}

	/**
	 * Enqueue assets.
	 */
	public function enqueue_assets(): void {
		$asset_path = ADAM_MEMBERSHIP_PATH . 'assets/css/member-area.css';

		wp_enqueue_style(
			'adam-member-area',
			ADAM_MEMBERSHIP_URL . 'assets/css/member-area.css',
			array(),
			file_exists( $asset_path ) ? (string) filemtime( $asset_path ) : ADAM_MEMBERSHIP_VERSION
		);

		$script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/member-card.js';

		wp_enqueue_script(
			'adam-member-card',
			ADAM_MEMBERSHIP_URL . 'assets/js/member-card.js',
			array(),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : ADAM_MEMBERSHIP_VERSION,
			true
		);
	}

	/**
	 * Render member area.
	 */
	public function render(): string {
		if ( ! is_user_logged_in() ) {
			$message = $this->process_login();

			return $this->render_login( $message );
		}

		$member = $this->members->find( get_current_user_id() );

		if ( null === $member ) {
			return $this->render_not_found();
		}

		ob_start();
		?>
		<div class="adam-member-area adam-member-dashboard">
			<?php $this->renewals->maybe_send_renewal_reminder( $member ); ?>
			<?php $this->render_header( $member ); ?>
			<?php $this->render_account_notices(); ?>

			<?php
			if ( $member->isPending() ) {
				$this->render_pending( $member );
			} elseif ( $member->isRenewalPending() ) {
				$this->render_renewal_pending( $member );
			} elseif ( $member->isExpired() ) {
				$this->render_expired( $member );
			} elseif ( $member->isRejected() ) {
				$this->render_rejected( $member );
			} elseif ( $member->isActive() ) {
				$this->render_active( $member );
			} else {
				$this->render_unknown_status();
			}
			?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render login page.
	 *
	 * @param string $message Message to display.
	 */
	private function render_login( string $message = '' ): string {
		if ( isset( $_GET['logged_out'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['logged_out'] ) ) ) {
			$message = $this->notice_markup( 'success', __( 'Sessão terminada com sucesso.', 'adam-membership' ) );
		} elseif ( isset( $_GET['password_reset'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['password_reset'] ) ) ) {
			$message = $this->notice_markup( 'success', __( 'A sua palavra-passe foi alterada com sucesso. Pode agora iniciar sessão.', 'adam-membership' ) );
		}

		ob_start();
		?>
		<div class="adam-member-area adam-member-login">
			<section class="adam-login-panel adam-card" aria-labelledby="adam-member-login-title">
				<div class="adam-login-copy">
					<p class="adam-eyebrow"><?php esc_html_e( 'ADAM Membership', 'adam-membership' ); ?></p>
					<h2 id="adam-member-login-title"><?php esc_html_e( 'Área do Sócio', 'adam-membership' ); ?></h2>
					<p><?php esc_html_e( 'Inicie sessão para acompanhar o estado da sua inscrição, consultar os seus dados e gerir o acesso à conta.', 'adam-membership' ); ?></p>
				</div>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post" class="adam-login-form">
					<?php wp_nonce_field( 'adam_member_login' ); ?>

					<div class="adam-form-field">
						<label for="adam_login"><?php esc_html_e( 'Email ou nome de utilizador', 'adam-membership' ); ?></label>
						<input
							type="text"
							id="adam_login"
							name="adam_login"
							required
							autocomplete="username"
						>
					</div>

					<div class="adam-form-field">
						<label for="adam_password"><?php esc_html_e( 'Palavra-passe', 'adam-membership' ); ?></label>
						<div class="adam-password-wrapper">
							<input
								type="password"
								id="adam_password"
								name="adam_password"
								required
								autocomplete="current-password"
							>
						</div>
					</div>

					<label class="adam-remember">
						<input type="checkbox" name="rememberme" value="1">
						<span><?php esc_html_e( 'Lembrar-me', 'adam-membership' ); ?></span>
					</label>

					<div class="adam-form-actions">
						<button type="submit" name="adam_login_submit" class="button button-primary adam-primary-action">
							<?php esc_html_e( 'Iniciar sessão', 'adam-membership' ); ?>
						</button>
						<a class="adam-text-link" href="<?php echo esc_url( home_url( '/recuperar-password/' ) ); ?>">
							<?php esc_html_e( 'Esqueceu-se da palavra-passe?', 'adam-membership' ); ?>
						</a>
					</div>
				</form>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Process login.
	 */
	private function process_login(): string {
		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
			! isset( $_POST['adam_login_submit'] )
		) {
			return '';
		}

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
				'adam_member_login'
			)
		) {
			return $this->notice_markup( 'error', __( 'Pedido inválido.', 'adam-membership' ) );
		}

		$login = sanitize_text_field(
			wp_unslash( $_POST['adam_login'] ?? '' )
		);
		$identity = RateLimiter::request_identity( $login );

		if ( RateLimiter::too_many_attempts( 'member_login', $identity, 8, 15 * MINUTE_IN_SECONDS ) ) {
			return $this->notice_markup( 'error', __( 'Demasiadas tentativas. Tente novamente mais tarde.', 'adam-membership' ) );
		}

		RateLimiter::hit( 'member_login', $identity, 15 * MINUTE_IN_SECONDS );

		if ( is_email( $login ) ) {
			$user = get_user_by( 'email', $login );

			if ( $user instanceof \WP_User ) {
				$login = $user->user_login;
			}
		}

		$result = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => (string) wp_unslash( $_POST['adam_password'] ?? '' ),
				'remember'      => isset( $_POST['rememberme'] ),
			),
			false
		);

		if ( is_wp_error( $result ) ) {
			return $this->notice_markup( 'error', __( 'Email ou palavra-passe incorretos.', 'adam-membership' ) );
		}

		wp_safe_redirect( home_url( '/socio/' ) );
		RateLimiter::clear( 'member_login', $identity );
		exit;
	}

	/**
	 * Render member not found.
	 */
	private function render_not_found(): string {
		ob_start();
		?>
		<div class="adam-member-area adam-member-dashboard">
			<section class="adam-card adam-empty-state">
				<p class="adam-eyebrow"><?php esc_html_e( 'Área do Sócio', 'adam-membership' ); ?></p>
				<h2><?php esc_html_e( 'Informação indisponível', 'adam-membership' ); ?></h2>
				<p><?php esc_html_e( 'Não foi encontrada informação de associado para esta conta.', 'adam-membership' ); ?></p>
				<a class="adam-action-card" href="<?php echo esc_url( wp_logout_url( home_url( '/socio/?logged_out=1' ) ) ); ?>">
					<?php esc_html_e( 'Terminar sessão', 'adam-membership' ); ?>
				</a>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render page header.
	 *
	 * @param Member $member Member.
	 */
	private function render_header( Member $member ): void {
		?>
		<header class="adam-member-hero">
			<div>
				<p class="adam-eyebrow"><?php esc_html_e( 'Área do Sócio', 'adam-membership' ); ?></p>
				<h2>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: member full name. */
							__( 'Bem-vindo, %s', 'adam-membership' ),
							$member->full_name()
						)
					);
					?>
				</h2>
				<p><?php esc_html_e( 'O seu painel central para acompanhar a inscrição, quota, dados de sócio e próximas funcionalidades da ADAM.', 'adam-membership' ); ?></p>
			</div>

			<div class="adam-hero-status">
				<?php $this->render_status_badge( $member->effective_status() ); ?>
				<span><?php echo esc_html( (string) $member->field( 'numero_socio' ) ?: __( 'Número por atribuir', 'adam-membership' ) ); ?></span>
			</div>
		</header>
		<?php
	}

	/**
	 * Render account notices.
	 */
	private function render_account_notices(): void {
		if ( isset( $_GET['password_changed'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['password_changed'] ) ) ) {
			echo wp_kses_post( $this->notice_markup( 'success', __( 'Palavra-passe alterada com sucesso.', 'adam-membership' ) ) );
			return;
		}

		if ( isset( $_GET['email_changed'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['email_changed'] ) ) ) {
			echo wp_kses_post( $this->notice_markup( 'success', __( 'Endereço de email alterado com sucesso.', 'adam-membership' ) ) );
		}
	}

	/**
	 * Render pending dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_pending( Member $member ): void {
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->status(),
				__( 'O seu pedido de inscrição foi recebido e encontra-se em análise pela ADAM.', 'adam-membership' )
			);

			$this->render_notifications_card(
				array(
					__( 'A equipa ADAM está a validar os seus dados e comprovativo de pagamento.', 'adam-membership' ),
					__( 'Receberá uma atualização assim que o processo for concluído.', 'adam-membership' ),
				)
			);

			$this->render_future_card();

			$this->render_actions(
				array(
					array(
						'label'       => __( 'Terminar sessão', 'adam-membership' ),
						'description' => '',
						'url'         => wp_logout_url( home_url( '/socio/?logged_out=1' ) ),
					),
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Render renewal pending dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_renewal_pending( Member $member ): void {
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->effective_status(),
				__( 'O seu pedido de renovação foi submetido e encontra-se em análise pela ADAM.', 'adam-membership' )
			);

			$this->render_membership( $member );

			$this->render_notifications_card(
				array(
					__( 'Receberá uma atualização assim que a renovação for confirmada.', 'adam-membership' ),
					__( 'Renovação em análise.', 'adam-membership' ),
				)
			);

			$this->render_profile( $member );
			$this->render_standard_account_actions();
			?>
		</div>
		<?php
	}

	/**
	 * Render expired dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_expired( Member $member ): void {
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->effective_status(),
				__( 'A sua quota expirou. Para voltar a ter a inscrição ativa, submeta a renovação.', 'adam-membership' )
			);

			$this->render_membership( $member );

			$this->render_notifications_card(
				array(
					__( 'A sua conta continua disponível para consultar dados e iniciar a renovação.', 'adam-membership' ),
				)
			);

			$this->render_renewal_action( $member );
			$this->render_profile( $member );
			$this->render_standard_account_actions();
			?>
		</div>
		<?php
	}

	/**
	 * Render rejected dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_rejected( Member $member ): void {
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->status(),
				__( 'Infelizmente a sua inscrição não foi aprovada. Caso pretenda mais informações, contacte a ADAM.', 'adam-membership' )
			);

			$this->render_notifications_card( $this->rejection_messages( $member ) );

			$this->render_profile( $member );

			$this->render_actions(
				array(
					array(
						'label'       => __( 'Terminar sessão', 'adam-membership' ),
						'description' => '',
						'url'         => wp_logout_url( home_url( '/socio/?logged_out=1' ) ),
					),
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * Render active dashboard.
	 *
	 * @param Member $member Member.
	 */
	private function render_active( Member $member ): void {
		?>
		<div class="adam-dashboard-grid">
			<?php
			$this->render_status_card(
				$member->effective_status(),
				__( 'A sua inscrição encontra-se ativa. Pode consultar os seus dados e gerir o acesso à conta.', 'adam-membership' )
			);

			$this->render_membership( $member );

			$this->render_notifications_card(
				array(
					__( 'O seu cartão digital está disponível para validação através de QR code.', 'adam-membership' ),
				)
			);

			$this->render_renewal_action( $member );

			$this->render_digital_card( $member );

			$this->render_profile( $member );

			$this->render_standard_account_actions();
			?>
		</div>
		<?php
	}

	/**
	 * Render the digital membership card.
	 *
	 * @param Member $member Member.
	 */
	private function render_digital_card( Member $member ): void {
		if ( ! $member->isActive() ) {
			return;
		}

		$photo_url     = $member->media_url( 'profile_photo' );
		$member_number = (string) $member->field( 'numero_socio' );
		$joined_date   = $this->format_date( $member->field( 'data_adesao' ) );
		$expiry_date   = $this->format_date( $member->field( 'validade_quota' ) );
		?>
		<section class="adam-card adam-digital-card-section" aria-label="<?php esc_attr_e( 'Digital membership card', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Cartão digital', 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( 'Cartão de sócio ADAM', 'adam-membership' ); ?></h3>
				</div>
				<div class="adam-card-actions">
					<button type="button" class="adam-card-link adam-card-print-button" data-adam-print-card><?php esc_html_e( 'Imprimir cartão', 'adam-membership' ); ?></button>
					<a class="adam-card-link" href="<?php echo esc_url( $this->cards->validation_url( $member ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Validar online', 'adam-membership' ); ?></a>
				</div>
			</div>
			<article class="adam-digital-card" aria-label="<?php esc_attr_e( 'ADAM digital membership card', 'adam-membership' ); ?>">
				<div class="adam-digital-card__shine" aria-hidden="true"></div>
				<header class="adam-digital-card__header">
					<img class="adam-digital-card__logo" src="<?php echo esc_url( $this->cards->association_logo_url() ); ?>" alt="<?php echo esc_attr( $this->cards->association_name() ); ?>">
					<div>
						<span><?php esc_html_e( 'Associação Desportiva', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( $this->cards->association_name() ); ?></strong>
					</div>
					<?php $this->render_status_badge( $member->effective_status() ); ?>
				</header>

				<div class="adam-digital-card__body">
					<div class="adam-digital-card__photo">
						<?php if ( '' !== $photo_url ) : ?>
							<img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $member->full_name() ); ?>">
						<?php else : ?>
							<span><?php echo esc_html( $this->member_initials( $member ) ); ?></span>
						<?php endif; ?>
					</div>

					<div class="adam-digital-card__identity">
						<span><?php esc_html_e( 'Nome do sócio', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( $member->full_name() ); ?></strong>
						<small><?php echo esc_html( '' !== $member_number ? $member_number : __( 'Número por atribuir', 'adam-membership' ) ); ?></small>
					</div>

					<div class="adam-digital-card__qr">
						<img src="<?php echo esc_url( $this->cards->qr_image_url( $member ) ); ?>" alt="<?php esc_attr_e( 'QR code for member validation', 'adam-membership' ); ?>">
						<span><?php esc_html_e( 'Validar cartão', 'adam-membership' ); ?></span>
					</div>
				</div>

				<div class="adam-digital-card__details" aria-label="<?php esc_attr_e( 'Membership details', 'adam-membership' ); ?>">
					<div>
						<span><?php esc_html_e( 'N.º de sócio', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( '' !== $member_number ? $member_number : __( 'Por atribuir', 'adam-membership' ) ); ?></strong>
					</div>
					<div>
						<span><?php esc_html_e( 'Data de adesão', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( '' !== $joined_date ? $joined_date : __( 'Indisponível', 'adam-membership' ) ); ?></strong>
					</div>
					<div>
						<span><?php esc_html_e( 'Válido até', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( '' !== $expiry_date ? $expiry_date : __( 'Indisponível', 'adam-membership' ) ); ?></strong>
					</div>
				</div>

				<footer class="adam-digital-card__footer">
					<span><?php esc_html_e( 'airsoftmondego.pt', 'adam-membership' ); ?></span>
					<span><?php esc_html_e( 'Cartão digital ADAM', 'adam-membership' ); ?></span>
				</footer>
			</article>
		</section>
		<?php
	}

	/**
	 * Get initials for a member photo fallback.
	 *
	 * @param Member $member Member.
	 */
	private function member_initials( Member $member ): string {
		$parts    = preg_split( '/\s+/', trim( $member->full_name() ) );
		$initials = '';

		if ( is_array( $parts ) ) {
			foreach ( array_slice( $parts, 0, 2 ) as $part ) {
				$initials .= strtoupper( substr( $part, 0, 1 ) );
			}
		}

		return '' !== $initials ? $initials : 'AD';
	}

	/**
	 * Render unknown status state.
	 */
	private function render_unknown_status(): void {
		$this->render_status_card(
			__( 'Estado desconhecido', 'adam-membership' ),
			__( 'Não foi possível determinar o estado atual da sua inscrição.', 'adam-membership' )
		);
	}

	/**
	 * Render status card.
	 *
	 * @param string $status  Status.
	 * @param string $message Message.
	 */
	private function render_status_card( string $status, string $message ): void {
		?>
		<section class="adam-card adam-status-card" aria-label="<?php esc_attr_e( 'Estado da inscrição', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Estado da inscrição', 'adam-membership' ); ?></p>
				<?php $this->render_status_badge( $status ); ?>
			</div>
			<p><?php echo esc_html( $message ); ?></p>
		</section>
		<?php
	}

	/**
	 * Render a status badge.
	 *
	 * @param string $status Member status.
	 */
	private function render_status_badge( string $status ): void {
		printf(
			'<span class="adam-badge %1$s">%2$s</span>',
			esc_attr( $this->status_class( $status ) ),
			esc_html( $status )
		);
	}

	/**
	 * Render membership information.
	 *
	 * @param Member $member Member.
	 */
	private function render_membership( Member $member ): void {
		?>
		<section class="adam-card adam-membership-card" aria-label="<?php esc_attr_e( 'Quota e identificação', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Quota e identificação', 'adam-membership' ); ?></p>
			</div>

			<div class="adam-data-list">
				<?php $this->render_data_item( __( 'N.º de sócio', 'adam-membership' ), (string) $member->field( 'numero_socio' ) ); ?>
				<?php $this->render_data_item( __( 'Data de adesão', 'adam-membership' ), $this->format_date( $member->field( 'data_adesao' ) ) ); ?>
				<?php $this->render_data_item( __( 'Validade da quota', 'adam-membership' ), $this->format_date( $member->field( 'validade_quota' ) ) ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render member profile.
	 *
	 * @param Member $member Member.
	 */
	private function render_profile( Member $member ): void {
		?>
		<section class="adam-card adam-profile-card" aria-label="<?php esc_attr_e( 'Dados do sócio', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Dados do sócio', 'adam-membership' ); ?></p>
			</div>

			<div class="adam-data-list">
				<?php $this->render_data_item( __( 'Nome', 'adam-membership' ), $member->full_name() ); ?>
				<?php $this->render_data_item( __( 'Email', 'adam-membership' ), $member->email() ); ?>
				<?php $this->render_data_item( __( 'Telefone', 'adam-membership' ), (string) $member->field( 'telefone' ) ); ?>
				<?php $this->render_data_item( __( 'Equipa', 'adam-membership' ), (string) $member->field( 'equipa' ) ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render notifications card.
	 *
	 * @param array<int, string> $messages Notification messages.
	 */
	private function render_notifications_card( array $messages ): void {
		?>
		<section class="adam-card adam-notifications-card" aria-label="<?php esc_attr_e( 'Notificações', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Notificações', 'adam-membership' ); ?></p>
			</div>

			<ul class="adam-notification-list">
				<?php foreach ( $messages as $message ) : ?>
					<li><?php echo esc_html( $message ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Render future-ready member tools card.
	 */
	private function render_future_card(): void {
		?>
		<section class="adam-card adam-future-card" aria-label="<?php esc_attr_e( 'Funcionalidades em preparação', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Em preparação', 'adam-membership' ); ?></p>
				<h3><?php esc_html_e( 'Cartão, QR code e renovações', 'adam-membership' ); ?></h3>
			</div>
			<p><?php esc_html_e( 'Esta área está preparada para receber o cartão digital de sócio, QR code e gestão de renovações.', 'adam-membership' ); ?></p>
		</section>
		<?php
	}

	/**
	 * Render renewal action when the member is eligible.
	 *
	 * @param Member $member Member.
	 */
	private function render_renewal_action( Member $member ): void {
		if ( ! $member->can_renew() ) {
			return;
		}

		$this->render_actions(
			array(
				array(
					'label'       => __( 'Renovar quota', 'adam-membership' ),
					'description' => '',
					'url'         => $this->settings->renewal_page_url(),
				),
			)
		);
	}

	/**
	 * Render standard account management actions.
	 */
	private function render_standard_account_actions(): void {
		$this->render_actions(
			array(
				array(
					'label'       => __( 'Alterar palavra-passe', 'adam-membership' ),
					'description' => '',
					'url'         => home_url( '/socio-password/' ),
				),
				array(
					'label'       => __( 'Alterar email', 'adam-membership' ),
					'description' => '',
					'url'         => home_url( '/socio-email/' ),
				),
				array(
					'label'       => __( 'Terminar sessão', 'adam-membership' ),
					'description' => '',
					'url'         => wp_logout_url( home_url( '/socio/?logged_out=1' ) ),
				),
			)
		);
	}

	/**
	 * Get safe rejection messages for the member.
	 *
	 * @param Member $member Member.
	 * @return array<int, string>
	 */
	private function rejection_messages( Member $member ): array {
		$messages = array(
			__( 'A sua inscrição foi analisada e não foi aprovada pela ADAM.', 'adam-membership' ),
			__( 'Caso pretenda mais informações, contacte a Direção da ADAM.', 'adam-membership' ),
		);

		$reason = $member->field( 'motivo_rejeicao' );

		if ( is_scalar( $reason ) && '' !== trim( (string) $reason ) ) {
			$messages[] = sprintf(
				/* translators: %s: rejection reason. */
				__( 'Motivo indicado: %s', 'adam-membership' ),
				trim( (string) $reason )
			);
		}

		return $messages;
	}

	/**
	 * Render action links.
	 *
	 * @param array<int,array{label:string,description:string,url:string}> $actions Actions.
	 */
	private function render_actions( array $actions ): void {
		?>
		<section class="adam-card adam-actions-card" aria-label="<?php esc_attr_e( 'Ações da conta', 'adam-membership' ); ?>">
			<div class="adam-card-heading">
				<p class="adam-eyebrow"><?php esc_html_e( 'Ações', 'adam-membership' ); ?></p>
			</div>

			<div class="adam-action-grid">
				<?php foreach ( $actions as $action ) : ?>
					<a class="adam-action-card" href="<?php echo esc_url( $action['url'] ); ?>">
						<strong><?php echo esc_html( $action['label'] ); ?></strong>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a data item.
	 *
	 * @param string $label Data label.
	 * @param string $value Data value.
	 */
	private function render_data_item( string $label, string $value ): void {
		?>
		<div class="adam-data-item">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( '' !== $value ? $value : __( 'Por preencher', 'adam-membership' ) ); ?></strong>
		</div>
		<?php
	}

	/**
	 * Build notice markup.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 */
	private function notice_markup( string $type, string $message ): string {
		$role = 'error' === $type ? 'alert' : 'status';

		return sprintf(
			'<div class="notice notice-%1$s adam-member-notice" role="%2$s"><p>%3$s</p></div>',
			esc_attr( $type ),
			esc_attr( $role ),
			esc_html( $message )
		);
	}

	/**
	 * Convert a member status into a badge class.
	 *
	 * @param string $status Member status.
	 */
	private function status_class( string $status ): string {
		if ( Member::STATUS_ACTIVE === $status ) {
			return 'active';
		}

		if ( Member::STATUS_REJECTED === $status ) {
			return 'rejected expired';
		}

		if ( Member::STATUS_EXPIRED === $status ) {
			return 'expired';
		}

		if ( Member::STATUS_RENEWAL_PENDING === $status ) {
			return 'pending warning renewal-pending';
		}

		if ( Member::STATUS_PENDING === $status ) {
			return 'pending warning';
		}

		return 'unknown';
	}

	/**
	 * Format stored dates for display.
	 *
	 * @param mixed $date Raw date value.
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
}
