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
	 * Email change confirmation handler.
	 *
	 * @var EmailChangeConfirmation
	 */
	private EmailChangeConfirmation $confirmation;

	/**
	 * Constructor.
	 *
	 * @param EmailChangeConfirmation $confirmation Email change confirmation handler.
	 */
	public function __construct( EmailChangeConfirmation $confirmation ) {
		$this->confirmation = $confirmation;
	}

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
		return $this->confirmation->render();
	}
}
