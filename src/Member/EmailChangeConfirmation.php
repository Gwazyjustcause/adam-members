<?php
/**
 * Email change confirmation.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use WP_User;

/**
 * Handles email confirmation.
 */
final class EmailChangeConfirmation {

	/**
	 * Register shortcode.
	 */
	public function register(): void {

		add_shortcode(
			'adam_confirm_email_change',
			array( $this, 'render' )
		);
	}

	/**
	 * Render confirmation page.
	 */
	public function render(): string {

		$user_id = absint(
			$_GET['user'] ?? 0
		);

		$key = sanitize_text_field(
			wp_unslash( $_GET['key'] ?? '' )
		);

		if ( ! $user_id || '' === $key ) {

			return $this->error(
				'Link inválido.'
			);
		}

		$user = get_user_by(
			'ID',
			$user_id
		);

		if ( ! $user instanceof WP_User ) {

			return $this->error(
				'Utilizador inválido.'
			);
		}

		$stored_key = (string) get_user_meta(
			$user_id,
			'adam_pending_email_key',
			true
		);

		$pending_email = (string) get_user_meta(
			$user_id,
			'adam_pending_email',
			true
		);

		$expires = (int) get_user_meta(
			$user_id,
			'adam_pending_email_expires',
			true
		);
        		if ( '' === $stored_key ) {

			return $this->error(
				'Este pedido já foi utilizado ou expirou.'
			);
		}

		if ( ! hash_equals( $stored_key, $key ) ) {

			return $this->error(
				'Código de confirmação inválido.'
			);
		}

		if ( time() > $expires ) {

			delete_user_meta(
				$user_id,
				'adam_pending_email'
			);

			delete_user_meta(
				$user_id,
				'adam_pending_email_key'
			);

			delete_user_meta(
				$user_id,
				'adam_pending_email_expires'
			);

			return $this->error(
				'Este link expirou.'
			);
		}

		$result = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $pending_email,
			)
		);

		if ( is_wp_error( $result ) ) {

			return $this->error(
				'Não foi possível atualizar o email.'
			);
		}

		delete_user_meta(
			$user_id,
			'adam_pending_email'
		);

		delete_user_meta(
			$user_id,
			'adam_pending_email_key'
		);

		delete_user_meta(
			$user_id,
			'adam_pending_email_expires'
		);

		ob_start();

		?>

		<div class="adam-member-area">

			<div class="adam-card adam-login-required">

				<h2>✅ Email confirmado</h2>

				<p>
					O seu endereço de email foi alterado com sucesso.
				</p>

				<p>

					<a
						class="button button-primary"
						href="<?php echo esc_url( home_url( '/socio/?email_changed=1' ) ); ?>"
					>
						Voltar à Área do Sócio
					</a>

				</p>

			</div>

		</div>

		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render error.
	 *
	 * @param string $message Error message.
	 */
	private function error( string $message ): string {

		return '
		<div class="adam-member-area">

			<div class="adam-card">

				<h2>Alteração de Email</h2>

				<p>' . esc_html( $message ) . '</p>

			</div>

		</div>';
	}
}