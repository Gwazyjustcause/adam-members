<?php
/**
 * Membership email service.
 *
 * @package AdamMembership\Emails
 */

declare(strict_types=1);

namespace AdamMembership\Emails;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Helpers\Logger;
use AdamMembership\Member\Member;

/**
 * Sends membership lifecycle emails.
 */
final class EmailService {
	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Logger helper.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Create the email service.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @param Logger             $logger   Logger helper.
	 */
	public function __construct( SettingsRepository $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Send the member approval email.
	 *
	 * @param Member $member Approved member.
	 */
	public function send_approval_email( Member $member ): bool {
		$recipient = $member->email();

		if ( '' === $recipient ) {
			$this->logger->error( 'Approval email was not sent because the member has no email address.', array( 'user_id' => $member->user_id() ) );
			return false;
		}

		$sent = wp_mail(
			$recipient,
			sprintf( '%s - %s', get_bloginfo( 'name' ), __( 'Membership approved', 'adam-membership' ) ),
			$this->build_approval_message( $member ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		if ( ! $sent ) {
			$this->logger->error( 'Approval email failed to send.', array( 'user_id' => $member->user_id() ) );
		}

		return $sent;
	}

	/**
	 * Build the approval email HTML message.
	 *
	 * @param Member $member Approved member.
	 */
	private function build_approval_message( Member $member ): string {
		$member_area_url = $this->settings->member_area_url();
		$member_number   = (string) $member->field( 'numero_socio' );
		$name            = $member->full_name();

		ob_start();
		?>
		<div style="font-family:Arial,sans-serif;line-height:1.6;color:#1d2327;max-width:640px;">
			<h1><?php echo esc_html__( 'Congratulations', 'adam-membership' ); ?></h1>
			<p><?php echo esc_html( sprintf( __( 'Hello %s,', 'adam-membership' ), $name ) ); ?></p>
			<p><?php esc_html_e( 'Your ADAM membership has been approved.', 'adam-membership' ); ?></p>
			<p><strong><?php esc_html_e( 'Member number:', 'adam-membership' ); ?></strong> <?php echo esc_html( $member_number ); ?></p>
			<p><?php esc_html_e( 'You can access your member area using the button below.', 'adam-membership' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $member_area_url ); ?>" style="display:inline-block;background:#2271b1;color:#ffffff;padding:12px 18px;text-decoration:none;border-radius:4px;">
					<?php esc_html_e( 'Login to Member Area', 'adam-membership' ); ?>
				</a>
			</p>
			<p><a href="<?php echo esc_url( $member_area_url ); ?>"><?php echo esc_html( $member_area_url ); ?></a></p>
			<p><?php esc_html_e( 'For security, this email does not include your password.', 'adam-membership' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
