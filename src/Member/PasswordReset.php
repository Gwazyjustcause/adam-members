<?php
/**
 * Password reset shortcode.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Helpers\RateLimiter;
use WP_User;

/**
 * Handles password reset.
 */
final class PasswordReset {
	private MemberRepository $members;
	private HistoryService $history;

	/**
	 * Constructor.
	 *
	 * @param MemberRepository $members Member repository.
	 * @param HistoryService   $history Member history service.
	 */
	public function __construct( MemberRepository $members, HistoryService $history ) {
		$this->members = $members;
		$this->history = $history;
	}

	/**
	 * Register shortcode.
	 */
	public function register(): void {
		add_shortcode(
			'adam_reset_password',
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
		if ( ! is_page( 'redefinir-password' ) ) {
			return;
		}

		wp_enqueue_script( 'password-strength-meter' );
		wp_enqueue_script( 'zxcvbn-async' );

		wp_enqueue_script(
			'adam-password-strength',
			ADAM_MEMBERSHIP_URL . 'assets/js/password-strength.js',
			array(
				'jquery',
				'password-strength-meter',
			),
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
	 * Render page.
	 */
	public function render(): string {
		$login          = sanitize_text_field( wp_unslash( $_GET['login'] ?? '' ) );
		$key            = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
		$preview_notice = '';
		$is_preview     = false;

		$user = check_password_reset_key( $key, $login );

		if ( ! $user instanceof WP_User && AdminPreview::is_available() ) {
			$is_preview     = true;
			$preview_notice = AdminPreview::notice_markup();
			$preview_user   = AdminPreview::demo_user();
			$login          = (string) $preview_user['username'];
			$key            = 'preview-key';
		} elseif ( ! $user instanceof WP_User ) {
			return $this->render_invalid_link();
		}

		$message = $is_preview ? $this->preview_message() : $this->process( $user );

		if ( str_contains( $message, 'adam-login-required' ) ) {
			return $message;
		}

		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<?php echo wp_kses_post( $preview_notice ); ?>
			<section class="adam-member-hero adam-account-hero">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Acesso à conta', 'adam-membership' ); ?></p>
					<h2><?php esc_html_e( 'Redefinir palavra-passe', 'adam-membership' ); ?></h2>
					<p><?php esc_html_e( 'Escolha uma nova palavra-passe para voltar a aceder à área de sócio.', 'adam-membership' ); ?></p>
				</div>
			</section>

			<section class="adam-card adam-form-card" aria-labelledby="adam-reset-password-title">
				<h3 id="adam-reset-password-title"><?php esc_html_e( 'Nova palavra-passe', 'adam-membership' ); ?></h3>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post" class="adam-account-form">
					<?php wp_nonce_field( 'adam_reset_password' ); ?>

					<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">
					<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">

					<div class="adam-form-field">
						<label for="password1"><?php esc_html_e( 'Nova palavra-passe', 'adam-membership' ); ?></label>
						<input
							type="password"
							id="password1"
							name="password1"
							required
							autocomplete="new-password"
							aria-describedby="adam-password-requirements password-strength-text"
						>
					</div>

					<?php $this->render_password_strength(); ?>

					<div class="adam-form-field">
						<label for="password2"><?php esc_html_e( 'Confirmar nova palavra-passe', 'adam-membership' ); ?></label>
						<input
							type="password"
							id="password2"
							name="password2"
							required
							autocomplete="new-password"
						>
					</div>

					<div class="adam-form-actions">
						<button type="submit" name="adam_reset_submit" class="button button-primary adam-primary-action adam-button" <?php disabled( $is_preview ); ?>>
							<?php esc_html_e( 'Alterar palavra-passe', 'adam-membership' ); ?>
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
	 * Process password reset.
	 *
	 * @param WP_User $user User.
	 */
	private function process( WP_User $user ): string {
		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
			! isset( $_POST['adam_reset_submit'] )
		) {
			return '';
		}

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
				'adam_reset_password'
			)
		) {
			return $this->notice_markup( 'error', __( 'Pedido inválido.', 'adam-membership' ) );
		}

		$password1 = (string) wp_unslash( $_POST['password1'] ?? '' );
		$password2 = (string) wp_unslash( $_POST['password2'] ?? '' );
		$identity  = RateLimiter::request_identity( (string) $user->user_login );

		if ( RateLimiter::too_many_attempts( 'password_reset', $identity, 5, 15 * MINUTE_IN_SECONDS ) ) {
			return $this->notice_markup( 'error', __( 'Demasiadas tentativas. Tente novamente mais tarde.', 'adam-membership' ) );
		}

		RateLimiter::hit( 'password_reset', $identity, 15 * MINUTE_IN_SECONDS );

		if ( $password1 !== $password2 ) {
			return $this->notice_markup( 'error', __( 'As palavras-passe não coincidem.', 'adam-membership' ) );
		}

		if ( strlen( $password1 ) < 8 ) {
			return $this->notice_markup( 'error', __( 'A palavra-passe deve ter pelo menos 8 caracteres.', 'adam-membership' ) );
		}

		if ( wp_check_password( $password1, $user->user_pass, $user->ID ) ) {
			return $this->notice_markup( 'error', __( 'A nova palavra-passe deve ser diferente da palavra-passe atual.', 'adam-membership' ) );
		}

		reset_password( $user, $password1 );
		RateLimiter::clear( 'password_reset', $identity );
		$member = $this->members->find( (int) $user->ID );

		if ( null !== $member ) {
			$this->history->password_reset_completed( $member );
		}

		return $this->render_success();
	}

	/**
	 * Build a non-processing preview message.
	 */
	private function preview_message(): string {
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return AdminPreview::submission_notice();
		}

		return '';
	}

	/**
	 * Render invalid or expired reset-link state.
	 */
	private function render_invalid_link(): string {
		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<section class="adam-card adam-login-required" aria-labelledby="adam-invalid-reset-title">
				<p class="adam-eyebrow"><?php esc_html_e( 'Link expirado', 'adam-membership' ); ?></p>
				<h2 id="adam-invalid-reset-title"><?php esc_html_e( 'Redefinir palavra-passe', 'adam-membership' ); ?></h2>
				<p><?php esc_html_e( 'O link é inválido ou expirou. Peça um novo link para continuar.', 'adam-membership' ); ?></p>

				<div class="adam-form-actions adam-form-actions-center">
					<a class="button button-primary adam-primary-action adam-button" href="<?php echo esc_url( home_url( '/recuperar-password/' ) ); ?>">
						<?php esc_html_e( 'Pedir novo link', 'adam-membership' ); ?>
					</a>
					<a class="button" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>">
						<?php esc_html_e( 'Voltar ao início de sessão', 'adam-membership' ); ?>
					</a>
				</div>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render successful reset state.
	 */
	private function render_success(): string {
		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<section class="adam-card adam-login-required" aria-labelledby="adam-reset-success-title">
				<p class="adam-eyebrow"><?php esc_html_e( 'Palavra-passe atualizada', 'adam-membership' ); ?></p>
				<h2 id="adam-reset-success-title"><?php esc_html_e( 'Palavra-passe redefinida', 'adam-membership' ); ?></h2>
				<p><?php esc_html_e( 'A sua palavra-passe foi redefinida com sucesso.', 'adam-membership' ); ?></p>
				<p><?php esc_html_e( 'Já pode iniciar sessão utilizando a sua nova palavra-passe.', 'adam-membership' ); ?></p>

				<div class="adam-form-actions adam-form-actions-center">
					<a class="button button-primary adam-primary-action adam-button" href="<?php echo esc_url( home_url( '/socio/' ) ); ?>">
						<?php esc_html_e( 'Iniciar sessão', 'adam-membership' ); ?>
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
			<p class="adam-strength-title"><?php esc_html_e( 'Força da palavra-passe', 'adam-membership' ); ?></p>

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
					<li id="rule-lower"><?php esc_html_e( 'Uma letra minúscula', 'adam-membership' ); ?></li>
					<li id="rule-upper"><?php esc_html_e( 'Uma letra maiúscula', 'adam-membership' ); ?></li>
					<li id="rule-number"><?php esc_html_e( 'Um número', 'adam-membership' ); ?></li>
					<li id="rule-symbol"><?php esc_html_e( 'Um símbolo', 'adam-membership' ); ?></li>
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
