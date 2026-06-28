<?php
/**
 * Password recovery shortcode.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Emails\EmailService;
use AdamMembership\Helpers\RateLimiter;

/**
 * Handles password recovery.
 */
final class PasswordRecovery {

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
	 * @param EmailService     $email   Email service.
	 * @param MemberRepository $members Member repository.
	 * @param HistoryService   $history Member history service.
	 */
	public function __construct( EmailService $email, MemberRepository $members, HistoryService $history ) {
		$this->email   = $email;
		$this->members = $members;
		$this->history = $history;
	}

	/**
	 * Register shortcode.
	 */
	public function register(): void {
		add_shortcode(
			'adam_recuperar_password',
			array( $this, 'render' )
		);
	}

	/**
	 * Render page.
	 */
	public function render(): string {
		$message = $this->process();

		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<section class="adam-member-hero adam-account-hero">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Acesso à conta', 'adam-membership' ); ?></p>
					<h2><?php esc_html_e( 'Recuperar palavra-passe', 'adam-membership' ); ?></h2>
					<p><?php esc_html_e( 'Introduza o email ou nome de utilizador associado à sua conta ADAM.', 'adam-membership' ); ?></p>
				</div>
			</section>

			<section class="adam-card adam-form-card" aria-labelledby="adam-password-recovery-title">
				<h3 id="adam-password-recovery-title"><?php esc_html_e( 'Receber instruções por email', 'adam-membership' ); ?></h3>
				<p class="adam-form-intro"><?php esc_html_e( 'Se existir uma conta associada aos dados indicados, enviaremos um link para redefinir a palavra-passe. O email pode demorar alguns minutos a chegar.', 'adam-membership' ); ?></p>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post" class="adam-account-form">
					<?php wp_nonce_field( 'adam_password_recovery' ); ?>

					<div class="adam-form-field">
						<label for="adam_recovery_login"><?php esc_html_e( 'Email ou nome de utilizador', 'adam-membership' ); ?></label>
						<input
							type="text"
							id="adam_recovery_login"
							name="adam_recovery_login"
							required
							autocomplete="username"
						>
					</div>

					<div class="adam-form-actions">
						<button type="submit" name="adam_password_recovery_submit" class="button button-primary adam-primary-action">
							<?php esc_html_e( 'Enviar email', 'adam-membership' ); ?>
						</button>
						<a class="adam-text-link" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>">
							<?php esc_html_e( 'Voltar ao início de sessão', 'adam-membership' ); ?>
						</a>
					</div>
				</form>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Process password recovery.
	 */
	private function process(): string {
		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
			! isset( $_POST['adam_password_recovery_submit'] )
		) {
			return '';
		}

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
				'adam_password_recovery'
			)
		) {
			return $this->notice_markup( 'error', __( 'Pedido inválido.', 'adam-membership' ) );
		}

		$login = sanitize_text_field(
			wp_unslash( $_POST['adam_recovery_login'] ?? '' )
		);
		$identity = RateLimiter::request_identity( $login );

		if ( RateLimiter::too_many_attempts( 'password_recovery', $identity, 3, 15 * MINUTE_IN_SECONDS ) ) {
			return $this->notice_markup(
				'success',
				__( 'Se existir uma conta associada aos dados introduzidos, recebera um email com instrucoes para redefinir a palavra-passe. Verifique tambem a pasta de spam.', 'adam-membership' )
			);
		}

		RateLimiter::hit( 'password_recovery', $identity, 15 * MINUTE_IN_SECONDS );

		$user = get_user_by( 'login', $login );

		if ( ! $user && is_email( $login ) ) {
			$user = get_user_by( 'email', $login );
		}

		if ( $user instanceof \WP_User ) {
			$key = get_password_reset_key( $user );

			if ( ! is_wp_error( $key ) ) {
				$this->email->send_password_reset_email( $user, $key );
				$member = $this->members->find( (int) $user->ID );

				if ( null !== $member ) {
					$this->history->password_reset_requested( $member );
				}
			}
		}

		return $this->notice_markup(
			'success',
			__( 'Se existir uma conta associada aos dados introduzidos, receberá um email com instruções para redefinir a palavra-passe. Verifique também a pasta de spam.', 'adam-membership' )
		);
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
}
