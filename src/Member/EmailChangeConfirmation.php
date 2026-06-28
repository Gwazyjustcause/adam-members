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
 * Handles email change confirmation.
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
		$user_id = absint( $_GET['user'] ?? 0 );
		$token   = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );

		if ( 0 === $user_id || '' === $token ) {
			return $this->error( __( 'Link invalido.', 'adam-membership' ) );
		}

		$user = get_user_by( 'ID', $user_id );

		if ( ! $user instanceof WP_User ) {
			return $this->error( __( 'Link invalido.', 'adam-membership' ) );
		}

		$stored_token_hash = (string) get_user_meta( $user_id, 'adam_email_token', true );
		$pending_email     = sanitize_email( (string) get_user_meta( $user_id, 'adam_pending_email', true ) );
		$expires           = absint( get_user_meta( $user_id, 'adam_email_token_expires', true ) );

		if ( '' === $stored_token_hash || '' === $pending_email || ! is_email( $pending_email ) ) {
			return $this->error( __( 'Este pedido ja foi utilizado ou expirou.', 'adam-membership' ) );
		}

		if ( 0 === $expires || time() > $expires ) {
			$this->delete_pending_change( $user_id );

			return $this->error( __( 'Este link expirou.', 'adam-membership' ) );
		}

		if ( ! hash_equals( $stored_token_hash, wp_hash( $token ) ) ) {
			return $this->error( __( 'Codigo de confirmacao invalido.', 'adam-membership' ) );
		}

		if ( email_exists( $pending_email ) && strtolower( $pending_email ) !== strtolower( $user->user_email ) ) {
			$this->delete_pending_change( $user_id );

			return $this->error( __( 'Nao foi possivel atualizar o email.', 'adam-membership' ) );
		}

		$result = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $pending_email,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->error( __( 'Nao foi possivel atualizar o email.', 'adam-membership' ) );
		}

		$this->delete_pending_change( $user_id );

		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<section class="adam-card adam-login-required" aria-labelledby="adam-email-confirmed-title">
				<p class="adam-eyebrow"><?php esc_html_e( 'Email confirmado', 'adam-membership' ); ?></p>
				<h2 id="adam-email-confirmed-title"><?php esc_html_e( 'Email atualizado', 'adam-membership' ); ?></h2>
				<p><?php esc_html_e( 'O seu endereco de email foi alterado com sucesso.', 'adam-membership' ); ?></p>

				<div class="adam-form-actions adam-form-actions-center">
					<a class="button button-primary adam-primary-action" href="<?php echo esc_url( home_url( '/socio/?email_changed=1' ) ); ?>">
						<?php esc_html_e( 'Voltar a area de socio', 'adam-membership' ); ?>
					</a>
				</div>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Delete pending email change metadata.
	 *
	 * @param int $user_id User ID.
	 */
	private function delete_pending_change( int $user_id ): void {
		delete_user_meta( $user_id, 'adam_pending_email' );
		delete_user_meta( $user_id, 'adam_email_token' );
		delete_user_meta( $user_id, 'adam_email_token_expires' );
	}

	/**
	 * Render error.
	 *
	 * @param string $message Error message.
	 */
	private function error( string $message ): string {
		return sprintf(
			'<div class="adam-member-area adam-account-page"><section class="adam-card adam-login-required"><h2>%1$s</h2><p>%2$s</p></section></div>',
			esc_html__( 'Alteracao de email', 'adam-membership' ),
			esc_html( $message )
		);
	}
}
