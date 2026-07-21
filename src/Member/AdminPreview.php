<?php
/**
 * Administrator preview helpers for token-based member pages.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use WP_User;

/**
 * Provides administrator-only preview behaviour for invalid token pages.
 */
final class AdminPreview {
	/**
	 * Check whether the current user may preview invalid token pages.
	 */
	public static function is_available(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * Build the administrator preview notice.
	 */
	public static function notice_markup(): string {
		return sprintf(
			'<div class="notice notice-warning adam-member-notice adam-member-preview-notice adam-notice adam-notice--warning" role="status"><p><strong>%1$s</strong><br>%2$s</p></div>',
			esc_html__( 'Modo de Pré-visualização (Administrador)', 'adam-membership' ),
			esc_html__( 'Esta página está a ser apresentada sem validação do token. Nenhuma ação executada nesta página será processada.', 'adam-membership' )
		);
	}

	/**
	 * Build a frontend notice when a preview form is posted.
	 */
	public static function submission_notice(): string {
		return sprintf(
			'<div class="notice notice-info adam-member-notice adam-member-preview-notice adam-notice adam-notice--info" role="status"><p>%s</p></div>',
			esc_html__( 'Pré-visualização ativa: a submissão foi ignorada porque esta página está a ser apresentada apenas para inspeção administrativa.', 'adam-membership' )
		);
	}

	/**
	 * Get placeholder values for preview rendering.
	 *
	 * @return array{user_id:int,email:string,username:string,display_name:string,pending_email:string}
	 */
	public static function demo_user(): array {
		$user = wp_get_current_user();

		if ( $user instanceof WP_User && 0 !== (int) $user->ID ) {
			$email = is_email( (string) $user->user_email ) ? (string) $user->user_email : 'preview@adam.pt';

			return array(
				'user_id'       => (int) $user->ID,
				'email'         => $email,
				'username'      => '' !== (string) $user->user_login ? (string) $user->user_login : 'socio.demo',
				'display_name'  => '' !== (string) $user->display_name ? (string) $user->display_name : 'Sócio de Demonstração',
				'pending_email' => 'novo.' . $email,
			);
		}

		return array(
			'user_id'       => 0,
			'email'         => 'preview@adam.pt',
			'username'      => 'socio.demo',
			'display_name'  => 'Sócio de Demonstração',
			'pending_email' => 'novo.preview@adam.pt',
		);
	}
}
