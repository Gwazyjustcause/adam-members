<?php
/**
 * Email confirmation.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

/**
 * Handles email confirmation.
 */
final class EmailConfirmation {

	/**
	 * Register shortcode.
	 */
	public function register(): void {

		add_shortcode(
			'adam_confirm_email',
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

		$token = sanitize_text_field(
			wp_unslash(
				$_GET['token'] ?? ''
			)
		);

		if ( ! $user_id || '' === $token ) {

			return '
			<div class="adam-member-area">

				<div class="adam-card">

					<h2>Confirmar Email</h2>

					<p>O link é inválido.</p>

				</div>

			</div>';
		}

		$saved_token = (string) get_user_meta(
			$user_id,
			'adam_email_token',
			true
		);

		$expires = (int) get_user_meta(
			$user_id,
			'adam_email_token_expires',
			true
		);

		if (
			$token !== $saved_token ||
			time() > $expires
		) {

			return '
			<div class="adam-member-area">

				<div class="adam-card">

					<h2>Confirmar Email</h2>

					<p>O link é inválido ou expirou.</p>

				</div>

			</div>';
		}
        		$new_email = (string) get_user_meta(
			$user_id,
			'adam_pending_email',
			true
		);

		if (
			'' === $new_email ||
			! is_email( $new_email )
		) {

			return '
			<div class="adam-member-area">

				<div class="adam-card">

					<h2>Confirmar Email</h2>

					<p>Não existe nenhum endereço de email pendente.</p>

				</div>

			</div>';
		}

		wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $new_email,
			)
		);

		delete_user_meta(
			$user_id,
			'adam_pending_email'
		);

		delete_user_meta(
			$user_id,
			'adam_email_token'
		);

		delete_user_meta(
			$user_id,
			'adam_email_token_expires'
		);

		wp_safe_redirect(
			home_url( '/socio/?email_changed=1' )
		);

		exit;
	}
}