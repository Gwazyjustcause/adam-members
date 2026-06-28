<?php
/**
 * Email confirmation.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

/**
 * Handles legacy email confirmation shortcode.
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
		$confirmation = new EmailChangeConfirmation();

		return $confirmation->render();
	}
}
