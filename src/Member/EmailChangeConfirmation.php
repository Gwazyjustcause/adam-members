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
	 * Member repository.
	 *
	 * @var MemberRepository
	 */
	private MemberRepository $members;

	/**
	 * Member history service.
	 *
	 * @var HistoryService
	 */
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

		if ( AdminPreview::is_available() && ( 0 === $user_id || '' === $token ) ) {
			return $this->render_preview_state();
		}

		if ( 0 === $user_id || '' === $token ) {
			return $this->error( __( 'Link inválido.', 'adam-membership' ) );
		}

		$user = get_user_by( 'ID', $user_id );

		if ( ! $user instanceof WP_User ) {
			return AdminPreview::is_available() ? $this->render_preview_state() : $this->error( __( 'Link inválido.', 'adam-membership' ) );
		}

		$stored_token_hash = (string) get_user_meta( $user_id, 'adam_email_token', true );
		$pending_email     = sanitize_email( (string) get_user_meta( $user_id, 'adam_pending_email', true ) );
		$expires           = absint( get_user_meta( $user_id, 'adam_email_token_expires', true ) );
		$old_email         = sanitize_email( $user->user_email );

		if ( '' === $stored_token_hash || '' === $pending_email || ! is_email( $pending_email ) ) {
			return AdminPreview::is_available() ? $this->render_preview_state() : $this->error( __( 'Este pedido já foi utilizado ou expirou.', 'adam-membership' ) );
		}

		if ( 0 === $expires || time() > $expires ) {
			if ( AdminPreview::is_available() ) {
				return $this->render_preview_state();
			}

			$this->delete_pending_change( $user_id );

			return $this->error( __( 'Este link expirou.', 'adam-membership' ) );
		}

		if ( ! hash_equals( $stored_token_hash, wp_hash( $token ) ) ) {
			return AdminPreview::is_available() ? $this->render_preview_state() : $this->error( __( 'Código de confirmação inválido.', 'adam-membership' ) );
		}

		if ( email_exists( $pending_email ) && strtolower( $pending_email ) !== strtolower( $user->user_email ) ) {
			$this->delete_pending_change( $user_id );

			return $this->error( __( 'Não foi possível atualizar o email.', 'adam-membership' ) );
		}

		$result = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $pending_email,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->error( __( 'Não foi possível atualizar o email.', 'adam-membership' ) );
		}

		$this->delete_pending_change( $user_id );

		$member = $this->members->find( $user_id );

		if ( null !== $member ) {
			$this->history->email_changed( $member, $old_email, $pending_email );
		}

		return $this->render_success_state();
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
	 * Render success output.
	 */
	private function render_success_state( string $notice = '' ): string {
		ob_start();
		?>
		<div class="adam-member-area adam-account-page">
			<?php echo wp_kses_post( $notice ); ?>
			<section class="adam-card adam-login-required" aria-labelledby="adam-email-confirmed-title">
				<p class="adam-eyebrow"><?php esc_html_e( 'Email confirmado', 'adam-membership' ); ?></p>
				<h2 id="adam-email-confirmed-title"><?php esc_html_e( 'Email atualizado', 'adam-membership' ); ?></h2>
				<p><?php esc_html_e( 'O seu endereço de email foi alterado com sucesso.', 'adam-membership' ); ?></p>

				<div class="adam-form-actions adam-form-actions-center">
					<a class="button button-primary adam-primary-action" href="<?php echo esc_url( home_url( '/socio/?email_changed=1' ) ); ?>">
						<?php esc_html_e( 'Voltar à área do sócio', 'adam-membership' ); ?>
					</a>
				</div>
			</section>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render administrator preview output.
	 */
	private function render_preview_state(): string {
		return $this->render_success_state( AdminPreview::notice_markup() );
	}

	/**
	 * Render error.
	 *
	 * @param string $message Error message.
	 */
	private function error( string $message ): string {
		return sprintf(
			'<div class="adam-member-area adam-account-page"><section class="adam-card adam-login-required"><h2>%1$s</h2><p>%2$s</p></section></div>',
			esc_html__( 'Alteração de email', 'adam-membership' ),
			esc_html( $message )
		);
	}
}
