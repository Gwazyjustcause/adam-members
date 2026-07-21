<?php
/**
 * Account management.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Emails\EmailService;
use AdamMembership\Helpers\RateLimiter;

/**
 * Handles account management.
 */
final class Account {

	/**
	 * Email service.
	 *
	 * @var EmailService
	 */
	private EmailService $email;
	private MemberRepository $members;
	private HistoryService $history;

	/**
	 * Constructor.
	 *
	 * @param EmailService    $email   Email service.
	 * @param MemberRepository $members Member repository.
	 * @param HistoryService   $history Member history service.
	 */
	public function __construct( EmailService $email, MemberRepository $members, HistoryService $history ) {
		$this->email   = $email;
		$this->members = $members;
		$this->history = $history;
	}

	/**
	 * Register shortcodes.
	 */
	public function register(): void {
		add_shortcode( 'adam_change_password', array( $this, 'render_password_form' ) );
		add_shortcode( 'adam_change_email', array( $this, 'render_email_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue password strength and visibility assets.
	 */
	public function enqueue_assets(): void {
		if (
			! is_page(
				array(
					'socio-password',
					'socio-email',
					'redefinir-password',
				)
			)
		) {
			return;
		}

		wp_enqueue_script( 'password-strength-meter' );
		wp_enqueue_script( 'zxcvbn-async' );

		wp_enqueue_script(
			'adam-password-strength',
			ADAM_MEMBERSHIP_URL . 'assets/js/password-strength.js',
			array( 'jquery', 'password-strength-meter' ),
			ADAM_MEMBERSHIP_VERSION,
			true
		);

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_script(
			'adam-password-toggle',
			ADAM_MEMBERSHIP_URL . 'assets/js/password-toggle.js',
			array(),
			ADAM_MEMBERSHIP_VERSION,
			true
		);
	}

	/**
	 * Render password form.
	 */
	public function render_password_form(): string {
		if ( ! is_user_logged_in() ) {
			return $this->render_login_required(
				__( 'Alterar palavra-passe', 'adam-membership' ),
				__( 'E necessario iniciar sessao para alterar a sua palavra-passe.', 'adam-membership' )
			);
		}

		$message = '';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['adam_change_password'] ) ) {
			$message = $this->process_password_change();
		}

		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<section class="adam-member-hero adam-account-hero">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Seguranca da conta', 'adam-membership' ); ?></p>
					<h2><?php esc_html_e( 'Alterar palavra-passe', 'adam-membership' ); ?></h2>
					<p><?php esc_html_e( 'Escolha uma palavra-passe forte e diferente da atual para manter a sua conta protegida.', 'adam-membership' ); ?></p>
				</div>
			</section>

			<section class="adam-card adam-form-card" aria-labelledby="adam-change-password-title">
				<h3 id="adam-change-password-title"><?php esc_html_e( 'Nova credencial de acesso', 'adam-membership' ); ?></h3>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post" class="adam-account-form">
					<?php wp_nonce_field( 'adam_change_password' ); ?>

					<div class="adam-form-field">
						<label for="current_password"><?php esc_html_e( 'Palavra-passe atual', 'adam-membership' ); ?></label>
						<input type="password" id="current_password" name="current_password" required autocomplete="current-password">
					</div>

					<div class="adam-form-field">
						<label for="new_password"><?php esc_html_e( 'Nova palavra-passe', 'adam-membership' ); ?></label>
						<input type="password" id="new_password" name="new_password" required autocomplete="new-password" aria-describedby="adam-password-requirements password-strength-text">
					</div>

					<?php $this->render_password_strength(); ?>

					<div class="adam-form-field">
						<label for="confirm_password"><?php esc_html_e( 'Confirmar nova palavra-passe', 'adam-membership' ); ?></label>
						<input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
					</div>

					<div class="adam-form-actions">
						<button type="submit" name="adam_change_password" class="button button-primary adam-primary-action adam-button">
							<?php esc_html_e( 'Alterar palavra-passe', 'adam-membership' ); ?>
						</button>
						<a class="adam-text-link" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>">
							<?php esc_html_e( 'Voltar à área do sócio', 'adam-membership' ); ?>
						</a>
					</div>
				</form>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render email form.
	 */
	public function render_email_form(): string {
		if ( ! is_user_logged_in() ) {
			return $this->render_login_required(
				__( 'Alterar email', 'adam-membership' ),
				__( 'E necessario iniciar sessao para alterar o seu endereco de email.', 'adam-membership' )
			);
		}

		$message = '';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['adam_change_email'] ) ) {
			$message = $this->process_email_change();
		}

		$current_user = wp_get_current_user();

		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<section class="adam-member-hero adam-account-hero">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Dados de acesso', 'adam-membership' ); ?></p>
					<h2><?php esc_html_e( 'Alterar email', 'adam-membership' ); ?></h2>
					<p><?php esc_html_e( 'O novo endereco so ficara ativo depois de confirmar o link enviado por email.', 'adam-membership' ); ?></p>
				</div>
			</section>

			<section class="adam-card adam-form-card" aria-labelledby="adam-change-email-title">
				<h3 id="adam-change-email-title"><?php esc_html_e( 'Confirmar novo endereco', 'adam-membership' ); ?></h3>

				<div class="adam-member-notice notice notice-info adam-notice adam-notice--info" role="status">
					<p><?php esc_html_e( 'O email atual continuara ativo ate a confirmacao estar concluida.', 'adam-membership' ); ?></p>
				</div>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post" class="adam-account-form">
					<?php wp_nonce_field( 'adam_change_email' ); ?>

					<div class="adam-form-field">
						<label for="current_email"><?php esc_html_e( 'Email atual', 'adam-membership' ); ?></label>
						<input type="email" id="current_email" value="<?php echo esc_attr( $current_user->user_email ); ?>" readonly>
					</div>

					<div class="adam-form-field">
						<label for="new_email"><?php esc_html_e( 'Novo email', 'adam-membership' ); ?></label>
						<input type="email" id="new_email" name="new_email" required autocomplete="email">
					</div>

					<div class="adam-form-field">
						<label for="confirm_email"><?php esc_html_e( 'Confirmar novo email', 'adam-membership' ); ?></label>
						<input type="email" id="confirm_email" name="confirm_email" required autocomplete="email">
					</div>

					<div class="adam-form-field">
						<label for="email_password"><?php esc_html_e( 'Palavra-passe atual', 'adam-membership' ); ?></label>
						<input type="password" id="email_password" name="email_password" required autocomplete="current-password">
					</div>

					<div class="adam-form-actions">
						<button type="submit" name="adam_change_email" class="button button-primary adam-primary-action adam-button">
							<?php esc_html_e( 'Enviar confirmacao', 'adam-membership' ); ?>
						</button>
						<a class="adam-text-link" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>">
							<?php esc_html_e( 'Voltar à área do sócio', 'adam-membership' ); ?>
						</a>
					</div>
				</form>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Process password change.
	 */
	private function process_password_change(): string {
		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'adam_change_password' )
		) {
			return $this->notice_markup( 'error', __( 'Pedido invalido.', 'adam-membership' ) );
		}

		$user     = wp_get_current_user();
		$current  = (string) wp_unslash( $_POST['current_password'] ?? '' );
		$new      = (string) wp_unslash( $_POST['new_password'] ?? '' );
		$confirm  = (string) wp_unslash( $_POST['confirm_password'] ?? '' );
		$identity = (string) $user->ID;

		if ( RateLimiter::too_many_attempts( 'change_password', $identity, 5, 15 * MINUTE_IN_SECONDS ) ) {
			return $this->notice_markup( 'error', __( 'Demasiadas tentativas. Tente novamente mais tarde.', 'adam-membership' ) );
		}

		RateLimiter::hit( 'change_password', $identity, 15 * MINUTE_IN_SECONDS );

		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			return $this->notice_markup( 'error', __( 'A palavra-passe atual esta incorreta.', 'adam-membership' ) );
		}

		if ( $new !== $confirm ) {
			return $this->notice_markup( 'error', __( 'As palavras-passe nao coincidem.', 'adam-membership' ) );
		}

		if ( strlen( $new ) < 8 ) {
			return $this->notice_markup( 'error', __( 'A palavra-passe deve ter pelo menos 8 caracteres.', 'adam-membership' ) );
		}

		if ( wp_check_password( $new, $user->user_pass, $user->ID ) ) {
			return $this->notice_markup( 'error', __( 'A nova palavra-passe deve ser diferente da palavra-passe atual.', 'adam-membership' ) );
		}

		wp_set_password( $new, $user->ID );
		wp_set_auth_cookie( $user->ID );
		RateLimiter::clear( 'change_password', $identity );
		$member = $this->members->find( (int) $user->ID );

		if ( null !== $member ) {
			$this->history->password_changed( $member );
		}

		wp_safe_redirect( home_url( '/socio/?password_changed=1' ) );
		exit;
	}

	/**
	 * Process email change.
	 */
	private function process_email_change(): string {
		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'adam_change_email' )
		) {
			return $this->notice_markup( 'error', __( 'Pedido invalido.', 'adam-membership' ) );
		}

		$user     = wp_get_current_user();
		$new      = sanitize_email( wp_unslash( $_POST['new_email'] ?? '' ) );
		$confirm  = sanitize_email( wp_unslash( $_POST['confirm_email'] ?? '' ) );
		$password = (string) wp_unslash( $_POST['email_password'] ?? '' );
		$identity = (string) $user->ID;

		if ( RateLimiter::too_many_attempts( 'change_email', $identity, 5, 15 * MINUTE_IN_SECONDS ) ) {
			return $this->notice_markup( 'error', __( 'Demasiadas tentativas. Tente novamente mais tarde.', 'adam-membership' ) );
		}

		RateLimiter::hit( 'change_email', $identity, 15 * MINUTE_IN_SECONDS );

		if ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return $this->notice_markup( 'error', __( 'A palavra-passe atual esta incorreta.', 'adam-membership' ) );
		}

		if ( $new !== $confirm || ! is_email( $new ) || email_exists( $new ) ) {
			return $this->notice_markup( 'error', __( 'Nao foi possivel iniciar a alteracao de email com os dados indicados.', 'adam-membership' ) );
		}

		$token = wp_generate_password( 32, false, false );

		update_user_meta( $user->ID, 'adam_pending_email', $new );
		update_user_meta( $user->ID, 'adam_email_token', wp_hash( $token ) );
		update_user_meta( $user->ID, 'adam_email_token_expires', time() + DAY_IN_SECONDS );

		$link = add_query_arg(
			array(
				'token' => $token,
				'user'  => $user->ID,
			),
			home_url( '/confirmar-email/' )
		);

		$this->email->send_email_confirmation( $user, $new, $link );
		RateLimiter::clear( 'change_email', $identity );
		$member = $this->members->find( (int) $user->ID );

		if ( null !== $member ) {
			$this->history->email_change_requested( $member );
		}

		return $this->notice_markup(
			'success',
			__( 'Enviamos um email de confirmacao para o novo endereco. A alteracao apenas sera concluida depois de clicar no link de confirmacao.', 'adam-membership' )
		);
	}

	/**
	 * Render login-required state.
	 *
	 * @param string $title   Page title.
	 * @param string $message Login-required message.
	 */
	private function render_login_required( string $title, string $message ): string {
		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<section class="adam-card adam-login-required" aria-labelledby="adam-login-required-title">
				<p class="adam-eyebrow"><?php esc_html_e( 'Acesso reservado', 'adam-membership' ); ?></p>
				<h2 id="adam-login-required-title"><?php echo esc_html( $title ); ?></h2>
				<p><?php echo esc_html( $message ); ?></p>
				<p><?php esc_html_e( 'Esta pagina destina-se apenas a associados que ja possuem uma conta na ADAM.', 'adam-membership' ); ?></p>

				<div class="adam-form-actions adam-form-actions-center">
					<a class="button button-primary adam-primary-action adam-button" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>">
						<?php esc_html_e( 'Iniciar sessao', 'adam-membership' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( home_url( '/recuperar-password/' ) ); ?>">
						<?php esc_html_e( 'Recuperar palavra-passe', 'adam-membership' ); ?>
					</a>
				</div>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render password strength guidance.
	 */
	private function render_password_strength(): void {
		?>
		<div id="adam-password-strength" class="adam-password-strength">
			<p class="adam-strength-title"><?php esc_html_e( 'Forca da palavra-passe', 'adam-membership' ); ?></p>

			<div id="adam-strength-bar" class="adam-strength-bar" aria-hidden="true">
				<span></span>
				<span></span>
				<span></span>
				<span></span>
				<span></span>
			</div>

			<p id="password-strength-text" class="adam-strength-text" aria-live="polite">
				<?php esc_html_e( 'Muito fraca', 'adam-membership' ); ?>
			</p>

			<div id="adam-password-requirements" class="adam-password-rules">
				<p><?php esc_html_e( 'A sua palavra-passe deve conter:', 'adam-membership' ); ?></p>
				<ul>
					<li id="rule-length"><?php esc_html_e( 'Pelo menos 8 caracteres', 'adam-membership' ); ?></li>
					<li id="rule-lower"><?php esc_html_e( 'Uma letra minuscula', 'adam-membership' ); ?></li>
					<li id="rule-upper"><?php esc_html_e( 'Uma letra maiuscula', 'adam-membership' ); ?></li>
					<li id="rule-number"><?php esc_html_e( 'Um numero', 'adam-membership' ); ?></li>
					<li id="rule-symbol"><?php esc_html_e( 'Um simbolo', 'adam-membership' ); ?></li>
				</ul>
			</div>
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
			'<div class="notice notice-%1$s adam-member-notice adam-notice" role="%2$s"><p>%3$s</p></div>',
			esc_attr( $type ),
			esc_attr( $role ),
			esc_html( $message )
		);
	}
}
