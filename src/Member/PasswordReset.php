<?php
/**
 * Password reset shortcode.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use WP_User;

/**
 * Handles password reset.
 */
final class PasswordReset {

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

		wp_enqueue_script(
			'password-strength-meter'
		);

		wp_enqueue_script(
			'zxcvbn-async'
		);

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

		wp_enqueue_style(
			'dashicons'
		);

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
				$login = sanitize_text_field(
			wp_unslash( $_GET['login'] ?? '' )
		);

		$key = sanitize_text_field(
			wp_unslash( $_GET['key'] ?? '' )
		);

		$user = check_password_reset_key(
			$key,
			$login
		);

		if ( ! $user instanceof WP_User ) {

			return '
			<div class="adam-member-area">

				<div class="adam-card">

					<h2>Redefinir Palavra-passe</h2>

					<p>O link é inválido ou expirou.</p>

				</div>

			</div>';
		}

		$message = $this->process( $user );

		ob_start();

		?>

		<div class="adam-member-area">

			<div class="adam-card">

				<h2>Redefinir Palavra-passe</h2>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post">

					<?php wp_nonce_field( 'adam_reset_password' ); ?>

					<input
						type="hidden"
						name="login"
						value="<?php echo esc_attr( $login ); ?>"
					>

					<input
						type="hidden"
						name="key"
						value="<?php echo esc_attr( $key ); ?>"
					>

					<p>

						<label for="password1">
							Nova Palavra-passe
						</label>

						<input
							type="password"
							id="password1"
							name="password1"
							required
							autocomplete="new-password"
						>

					</p>

					<div
						id="adam-password-strength"
						class="adam-password-strength"
					>

						<p class="adam-strength-title">
							Força da palavra-passe
						</p>

						<div
							id="adam-strength-bar"
							class="adam-strength-bar"
						>

							<span></span>
							<span></span>
							<span></span>
							<span></span>
							<span></span>

						</div>

						<p
							id="password-strength-text"
							class="adam-strength-text"
						>
							Muito fraca
						</p>

						<div class="adam-password-rules">

							<p>
								A sua palavra-passe deve conter:
							</p>

							<ul>

								<li id="rule-length">
									✗ Pelo menos 8 caracteres
								</li>

								<li id="rule-lower">
									✗ Uma letra minúscula
								</li>

								<li id="rule-upper">
									✗ Uma letra maiúscula
								</li>

								<li id="rule-number">
									✗ Um número
								</li>

								<li id="rule-symbol">
									✗ Um símbolo
								</li>

							</ul>

						</div>

					</div>
										<p>

						<label for="password2">
							Confirmar Palavra-passe
						</label>

						<input
							type="password"
							id="password2"
							name="password2"
							required
							autocomplete="new-password"
						>

					</p>

					<p>

						<button
							type="submit"
							name="adam_reset_submit"
							class="button button-primary"
						>
							Alterar Palavra-passe
						</button>

					</p>

				</form>

			</div>

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
			'POST' !== $_SERVER['REQUEST_METHOD'] ||
			! isset( $_POST['adam_reset_submit'] )
		) {
			return '';
		}

		if (
			! isset( $_POST['_wpnonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field(
					wp_unslash( $_POST['_wpnonce'] )
				),
				'adam_reset_password'
			)
		) {
			return '
			<div class="notice notice-error">
				<p>Pedido inválido.</p>
			</div>';
		}

		$password1 = (string) wp_unslash(
			$_POST['password1'] ?? ''
		);

		$password2 = (string) wp_unslash(
			$_POST['password2'] ?? ''
		);

		if ( $password1 !== $password2 ) {

			return '
			<div class="notice notice-error">
				<p>As palavras-passe não coincidem.</p>
			</div>';
		}

		if ( strlen( $password1 ) < 8 ) {

			return '
			<div class="notice notice-error">
				<p>A palavra-passe deve ter pelo menos 8 caracteres.</p>
			</div>';
		}

		if (
			wp_check_password(
				$password1,
				$user->user_pass,
				$user->ID
			)
		) {

			return '
			<div class="notice notice-error">
				<p>
					A nova palavra-passe deve ser diferente da palavra-passe atual.
				</p>
			</div>';
		}

		reset_password(
			$user,
			$password1
		);

		wp_safe_redirect(
			home_url( '/socio/' )
		);

		exit;
	}
}