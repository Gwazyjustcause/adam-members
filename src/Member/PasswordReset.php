<?php
/**
 * Password reset shortcode.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use WP_Error;
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
						>

					</p>

					<p>

						<label for="password2">
							Confirmar Palavra-passe
						</label>

						<input
							type="password"
							id="password2"
							name="password2"
							required
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
				sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ),
				'adam_reset_password'
			)
		) {
			return '<div class="notice notice-error"><p>Pedido inválido.</p></div>';
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