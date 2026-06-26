<?php
/**
 * Password recovery shortcode.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

/**
 * Handles password recovery.
 */
final class PasswordRecovery {

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

		<div class="adam-member-area">

			<div class="adam-card">

				<h2>Recuperar Palavra-passe</h2>

				<p>
					Introduza o seu email ou nome de utilizador.
				</p>

				<?php echo wp_kses_post( $message ); ?>

				<form method="post">

					<?php wp_nonce_field( 'adam_password_recovery' ); ?>

					<p>

						<label for="adam_recovery_login">
							Email ou Nome de Utilizador
						</label>

						<input
							type="text"
							id="adam_recovery_login"
							name="adam_recovery_login"
							required
						>

					</p>

					<p>

						<button
							type="submit"
							name="adam_password_recovery_submit"
							class="button button-primary"
						>
							Enviar Email
						</button>

					</p>

				</form>

			</div>

		</div>

		<?php

		return (string) ob_get_clean();
	}
    	/**
	 * Process password recovery.
	 */
	private function process(): string {

		if (
			'POST' !== $_SERVER['REQUEST_METHOD'] ||
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
			return '<div class="notice notice-error"><p>Pedido inválido.</p></div>';
		}

		$login = sanitize_text_field(
			wp_unslash( $_POST['adam_recovery_login'] ?? '' )
		);

		if ( '' !== $login ) {

			retrieve_password( $login );
		}

		return '
		<div class="notice notice-success">
			<p>
				Se existir uma conta associada aos dados introduzidos,
				receberá um email com instruções para redefinir a palavra-passe.
			</p>
		</div>';
	}
}
