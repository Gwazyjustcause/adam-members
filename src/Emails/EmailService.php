<?php
/**
 * Membership email service.
 *
 * @package AdamMembership\Emails
 */

declare(strict_types=1);

namespace AdamMembership\Emails;

use AdamMembership\Announcement\Announcement;
use AdamMembership\Core\SettingsRepository;
use AdamMembership\Helpers\Logger;
use AdamMembership\Member\Member;
use WP_User;

/**
 * Sends membership lifecycle emails.
 */
final class EmailService {
	/**
	 * ADAM logo.
	 */
	private const LOGO_URL = 'https://airsoftmondego.pt/wp-content/uploads/2026/06/ADAM.png';

	/**
	 * Primary colour.
	 */
	private const PRIMARY = '#2e7d32';

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @param Logger             $logger   Logger helper.
	 */
	public function __construct( SettingsRepository $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Get configurable admin email templates.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function admin_templates(): array {
		return array(
			'member_approved' => array(
				'label'        => __( 'Sócio aprovado', 'adam-membership' ),
				'description'  => __( 'Enviado quando a Direção aprova uma nova inscrição.', 'adam-membership' ),
				'placeholders' => array( 'member_name', 'member_number', 'member_area_link', 'login_link', 'quota_value', 'expiry_date' ),
			),
			'member_rejected' => array(
				'label'        => __( 'Sócio rejeitado', 'adam-membership' ),
				'description'  => __( 'Enviado quando uma inscrição é rejeitada.', 'adam-membership' ),
				'placeholders' => array( 'member_name', 'reason' ),
			),
			'renewal_submitted' => array(
				'label'        => __( 'Renovação submetida', 'adam-membership' ),
				'description'  => __( 'Confirma a receção da renovação e do comprovativo de pagamento.', 'adam-membership' ),
				'placeholders' => array( 'member_name', 'member_number', 'payment_status', 'quota_value', 'renewal_link' ),
			),
			'renewal_approved' => array(
				'label'        => __( 'Renovação aprovada', 'adam-membership' ),
				'description'  => __( 'Enviado quando a renovação é aprovada.', 'adam-membership' ),
				'placeholders' => array( 'member_name', 'member_number', 'expiry_date', 'member_area_link', 'quota_value' ),
			),
			'renewal_rejected' => array(
				'label'        => __( 'Renovação rejeitada', 'adam-membership' ),
				'description'  => __( 'Enviado quando a renovação não é aprovada.', 'adam-membership' ),
				'placeholders' => array( 'member_name', 'reason', 'renewal_link' ),
			),
			'renewal_reminder' => array(
				'label'        => __( 'Lembrete de renovação', 'adam-membership' ),
				'description'  => __( 'Lembrete automático antes da quota expirar.', 'adam-membership' ),
				'placeholders' => array( 'member_name', 'member_number', 'expiry_date', 'renewal_link' ),
			),
			'quota_expired' => array(
				'label'        => __( 'Quota expirada', 'adam-membership' ),
				'description'  => __( 'Aviso automático quando a quota expira.', 'adam-membership' ),
				'placeholders' => array( 'member_name', 'member_number', 'expiry_date', 'renewal_link' ),
			),
			'password_reset' => array(
				'label'        => __( 'Redefinição de palavra-passe', 'adam-membership' ),
				'description'  => __( 'Email automático de recuperação de palavra-passe.', 'adam-membership' ),
				'placeholders' => array( 'member_name', 'reset_link', 'login_link' ),
			),
			'email_confirmation' => array(
				'label'        => __( 'Confirmação de alteração de email', 'adam-membership' ),
				'description'  => __( 'Enviado quando um sócio pede a alteração do endereço de email.', 'adam-membership' ),
				'placeholders' => array( 'member_name', 'new_email', 'confirmation_link' ),
			),
		);
	}

	/**
	 * Build a preview for a configured email template.
	 *
	 * @param string $template_key Template key.
	 * @return array{subject:string,body:string,html:string}|null
	 */
	public function preview_email_template( string $template_key ): ?array {
		return $this->render_configured_email_template( $template_key, $this->sample_template_context() );
	}

	/**
	 * Send a test message for a configured email template.
	 *
	 * @param string $template_key Template key.
	 * @param string $recipient    Recipient email.
	 */
	public function send_test_email_template( string $template_key, string $recipient ): bool {
		$recipient = sanitize_email( $recipient );

		if ( ! is_email( $recipient ) ) {
			return false;
		}

		$rendered = $this->render_configured_email_template( $template_key, $this->sample_template_context() );

		if ( null === $rendered ) {
			return false;
		}

		return $this->send(
			$recipient,
			$rendered['subject'],
			$rendered['html'],
			$template_key . '_test',
			array( 'test_email' => true )
		);
	}

	/**
	 * Send approval email.
	 *
	 * @param Member $member Member.
	 */
	public function send_approval_email( Member $member ): bool {
		return $this->send_member_template_email(
			'member_approved',
			$member,
			array(
				'member_area_link' => $this->settings->member_area_url(),
				'login_link'       => $this->settings->member_area_url(),
			),
			array( 'member_id' => $member->user_id() )
		);
	}

	/**
	 * Send registration rejected email.
	 *
	 * @param Member $member Member.
	 * @param string $reason Safe rejection reason.
	 */
	public function send_registration_rejected_email( Member $member, string $reason = '' ): bool {
		return $this->send_member_template_email(
			'member_rejected',
			$member,
			array(
				'reason' => '' !== trim( $reason ) ? $reason : __( 'Sem motivo adicional indicado.', 'adam-membership' ),
			)
		);
	}

	/**
	 * Send renewal submitted confirmation email.
	 *
	 * @param Member $member     Member.
	 * @param int    $renewal_id Renewal request ID.
	 */
	public function send_renewal_submitted_email( Member $member, int $renewal_id = 0 ): bool {
		return $this->send_member_template_email(
			'renewal_submitted',
			$member,
			array(
				'payment_status' => __( 'Renovação em análise', 'adam-membership' ),
				'renewal_link'   => $this->settings->renewal_page_url(),
			),
			array( 'renewal_id' => $renewal_id )
		);
	}

	/**
	 * Send renewal pending confirmation email.
	 *
	 * @param Member $member     Member.
	 * @param int    $renewal_id Renewal request ID.
	 */
	public function send_renewal_pending_email( Member $member, int $renewal_id = 0 ): bool {
		return $this->send_renewal_submitted_email( $member, $renewal_id );
	}

	/**
	 * Send renewal reminder email.
	 *
	 * @param Member $member Member.
	 */
	public function send_renewal_reminder_email( Member $member ): bool {
		return $this->send_member_template_email(
			'renewal_reminder',
			$member,
			array(
				'renewal_link' => $this->settings->renewal_page_url(),
			)
		);
	}

	/**
	 * Send renewal approved email.
	 *
	 * @param Member $member     Member.
	 * @param int    $renewal_id Renewal request ID.
	 */
	public function send_renewal_approved_email( Member $member, int $renewal_id = 0 ): bool {
		return $this->send_member_template_email(
			'renewal_approved',
			$member,
			array(
				'member_area_link' => $this->settings->member_area_url(),
			),
			array( 'renewal_id' => $renewal_id )
		);
	}

	/**
	 * Send renewal rejected email.
	 *
	 * @param Member $member     Member.
	 * @param string $reason     Safe rejection reason.
	 * @param int    $renewal_id Renewal request ID.
	 */
	public function send_renewal_rejected_email( Member $member, string $reason = '', int $renewal_id = 0 ): bool {
		return $this->send_member_template_email(
			'renewal_rejected',
			$member,
			array(
				'reason'       => '' !== trim( $reason ) ? $reason : __( 'Sem motivo adicional indicado.', 'adam-membership' ),
				'renewal_link' => $this->settings->renewal_page_url(),
			),
			array( 'renewal_id' => $renewal_id )
		);
	}

	/**
	 * Send quota expired notice.
	 *
	 * @param Member $member Member.
	 */
	public function send_quota_expired_email( Member $member ): bool {
		return $this->send_member_template_email(
			'quota_expired',
			$member,
			array(
				'renewal_link' => $this->settings->renewal_page_url(),
			)
		);
	}

	/**
	 * Send an optional announcement email.
	 *
	 * @param Member       $member       Member.
	 * @param Announcement $announcement Announcement.
	 */
	public function send_announcement_email( Member $member, Announcement $announcement ): bool {
		$button = '';

		if ( '' !== $announcement->action_label() && '' !== $announcement->action_url() ) {
			$button = '<p style="text-align:center;"><a href="' . esc_url( $announcement->action_url() ) . '">' . esc_html( $announcement->action_label() ) . '</a></p>';
		}

		$content = sprintf(
			'<p>Olá <strong>%1$s</strong>,</p><p>%2$s</p><div>%3$s</div>%4$s',
			esc_html( $member->full_name() ),
			esc_html( $announcement->summary() ),
			wp_kses_post( wpautop( $announcement->content() ) ),
			$button
		);

		return $this->send(
			$member->email(),
			$announcement->title(),
			$this->render_template( $announcement->title(), $content ),
			'announcement',
			array(
				'announcement_id' => $announcement->id(),
				'member_id'       => $member->user_id(),
			)
		);
	}

	/**
	 * Send password reset email.
	 *
	 * @param WP_User $user User.
	 * @param string  $key  Reset key.
	 */
	public function send_password_reset_email( WP_User $user, string $key ): bool {
		$reset_url = add_query_arg(
			array(
				'login' => rawurlencode( $user->user_login ),
				'key'   => rawurlencode( $key ),
			),
			home_url( '/redefinir-password/' )
		);

		$rendered = $this->render_configured_email_template(
			'password_reset',
			array(
				'member_name' => $user->display_name,
				'reset_link'  => $reset_url,
				'login_link'  => wp_login_url(),
			)
		);

		if ( null === $rendered ) {
			return false;
		}

		return $this->send(
			$user->user_email,
			$rendered['subject'],
			$rendered['html'],
			'password_reset',
			array( 'user_id' => (int) $user->ID )
		);
	}

	/**
	 * Send email confirmation email.
	 *
	 * @param WP_User $user      User.
	 * @param string  $new_email New email.
	 * @param string  $link      Confirmation link.
	 */
	public function send_email_confirmation( WP_User $user, string $new_email, string $link ): bool {
		$rendered = $this->render_configured_email_template(
			'email_confirmation',
			array(
				'member_name'       => $user->display_name,
				'new_email'         => $new_email,
				'confirmation_link' => $link,
			)
		);

		if ( null === $rendered ) {
			return false;
		}

		return $this->send(
			$new_email,
			$rendered['subject'],
			$rendered['html'],
			'email_confirmation',
			array( 'user_id' => (int) $user->ID )
		);
	}

	/**
	 * Send HTML email.
	 *
	 * @param string               $recipient  Recipient.
	 * @param string               $subject    Subject.
	 * @param string               $message    HTML message.
	 * @param string               $email_type Email type.
	 * @param array<string, mixed> $context    Log context.
	 */
	private function send( string $recipient, string $subject, string $message, string $email_type = 'generic', array $context = array() ): bool {
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );

		try {
			$sent = wp_mail( $recipient, $subject, $message, $headers );
		} finally {
			remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
			remove_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );
		}

		$log_context = array_merge(
			$context,
			array(
				'email_type'     => $email_type,
				'recipient_hash' => wp_hash( $recipient ),
				'subject'        => $subject,
			)
		);

		if ( ! $sent ) {
			$this->logger->error( 'Email failed.', $log_context );
			return false;
		}

		$this->logger->info( 'Email sent.', $log_context );

		return true;
	}

	/**
	 * Get branded sender email for ADAM Membership messages.
	 */
	public function mail_from(): string {
		return $this->settings->email_from_address();
	}

	/**
	 * Get branded sender name for ADAM Membership messages.
	 */
	public function mail_from_name(): string {
		return $this->settings->email_from_name();
	}

	/**
	 * Send a configured template to a member.
	 *
	 * @param string               $template_key Template key.
	 * @param Member               $member       Member.
	 * @param array<string, mixed> $extra        Additional context.
	 * @param array<string, mixed> $context      Log context.
	 */
	private function send_member_template_email( string $template_key, Member $member, array $extra = array(), array $context = array() ): bool {
		$recipient = $member->email();

		if ( '' === $recipient ) {
			$this->logger->error(
				'Membership lifecycle email was not sent because the member has no email address.',
				array_merge(
					$context,
					array(
						'member_id'   => $member->user_id(),
						'email_type'  => $template_key,
					)
				)
			);

			return false;
		}

		$template_settings = $this->settings->email_template_settings();
		$template          = $template_settings[ $template_key ] ?? null;

		if ( ! is_array( $template ) || empty( $template['enabled'] ) ) {
			$this->logger->info(
				'Configured email skipped because it is disabled.',
				array_merge( $context, array( 'email_type' => $template_key, 'member_id' => $member->user_id() ) )
			);

			return true;
		}

		$rendered = $this->render_configured_email_template(
			$template_key,
			array_merge( $this->member_template_context( $member ), $extra )
		);

		if ( null === $rendered ) {
			return false;
		}

		return $this->send(
			$recipient,
			$rendered['subject'],
			$rendered['html'],
			$template_key,
			array_merge( $context, array( 'member_id' => $member->user_id() ) )
		);
	}

	/**
	 * Render a configured email template.
	 *
	 * @param string               $template_key Template key.
	 * @param array<string, mixed> $context      Placeholder context.
	 * @return array{subject:string,body:string,html:string}|null
	 */
	private function render_configured_email_template( string $template_key, array $context ): ?array {
		$template_settings = $this->settings->email_template_settings();
		$template          = $template_settings[ $template_key ] ?? null;

		if ( ! is_array( $template ) ) {
			return null;
		}

		$subject = $this->replace_placeholders( (string) ( $template['subject'] ?? '' ), $context );
		$body    = $this->replace_placeholders( (string) ( $template['body'] ?? '' ), $context );
		$html    = $this->render_template( wp_strip_all_tags( $subject ), $this->normalize_body_markup( $body ) );

		return array(
			'subject' => $subject,
			'body'    => $body,
			'html'    => $html,
		);
	}

	/**
	 * Build placeholder context from a member.
	 *
	 * @param Member $member Member.
	 * @return array<string, string>
	 */
	private function member_template_context( Member $member ): array {
		return array(
			'member_name'      => $member->full_name(),
			'member_number'    => $this->member_number( $member ),
			'expiry_date'      => $this->format_date( $member->field( 'validade_quota' ) ),
			'quota_value'      => $this->quota_value( $member ),
			'payment_status'   => '',
			'login_link'       => $this->settings->member_area_url(),
			'member_area_link' => $this->settings->member_area_url(),
			'renewal_link'     => $this->settings->renewal_page_url(),
			'reason'           => '',
			'new_email'        => '',
			'confirmation_link' => '',
			'reset_link'       => '',
		);
	}

	/**
	 * Build sample preview context.
	 *
	 * @return array<string, string>
	 */
	private function sample_template_context(): array {
		return array(
			'member_name'       => 'João Exemplo',
			'member_number'     => 'ADAM-0001',
			'expiry_date'       => wp_date( 'd/m/Y', strtotime( '+1 year' ) ),
			'quota_value'       => '22,00 €',
			'payment_status'    => __( 'Renovação em análise', 'adam-membership' ),
			'login_link'        => $this->settings->member_area_url(),
			'member_area_link'  => $this->settings->member_area_url(),
			'renewal_link'      => $this->settings->renewal_page_url(),
			'reason'            => __( 'Falta um comprovativo legível.', 'adam-membership' ),
			'new_email'         => 'novo.email@example.com',
			'confirmation_link' => home_url( '/confirmar-email/' ),
			'reset_link'        => home_url( '/redefinir-password/' ),
		);
	}

	/**
	 * Replace supported placeholders.
	 *
	 * @param string               $text    Raw text.
	 * @param array<string, mixed> $context Placeholder context.
	 */
	private function replace_placeholders( string $text, array $context ): string {
		$replacements = array();

		foreach ( $context as $key => $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';

			$replacements[ '{{' . $key . '}}' ] = str_ends_with( $key, '_link' )
				? esc_url( $value )
				: esc_html( $value );
		}

		return strtr( $text, $replacements );
	}

	/**
	 * Normalize email body markup.
	 *
	 * @param string $body Body.
	 */
	private function normalize_body_markup( string $body ): string {
		if ( preg_match( '/<[a-z][^>]*>/i', $body ) ) {
			return wp_kses_post( $body );
		}

		return wp_kses_post( wpautop( esc_html( $body ) ) );
	}

	/**
	 * Get a formatted member number.
	 *
	 * @param Member $member Member.
	 */
	private function member_number( Member $member ): string {
		$member_number = trim( (string) $member->field( 'numero_socio' ) );

		return '' !== $member_number ? $member_number : __( 'Por atribuir', 'adam-membership' );
	}

	/**
	 * Get a formatted quota value for email placeholders.
	 *
	 * @param Member $member Member.
	 */
	private function quota_value( Member $member ): string {
		$fee = trim( (string) $member->field( 'adam_membership_fee' ) );

		if ( '' === $fee ) {
			$forms = $this->settings->membership_form_settings();
			$fee   = (string) ( $forms['fees']['primary'] ?? '22.00' );
		}

		return number_format_i18n( (float) str_replace( ',', '.', $fee ), 2 ) . ' ' . html_entity_decode( '&#8364;', ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Format a stored date.
	 *
	 * @param mixed $date Stored date.
	 */
	private function format_date( mixed $date ): string {
		if ( ! is_scalar( $date ) ) {
			return '';
		}

		$date = trim( (string) $date );

		if ( '' === $date ) {
			return '';
		}

		if ( preg_match( '/^\d{8}$/', $date ) ) {
			return substr( $date, 6, 2 ) . '/' . substr( $date, 4, 2 ) . '/' . substr( $date, 0, 4 );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return substr( $date, 8, 2 ) . '/' . substr( $date, 5, 2 ) . '/' . substr( $date, 0, 4 );
		}

		return $date;
	}

	/**
	 * Render the standard ADAM email template.
	 *
	 * @param string $title   Email title.
	 * @param string $content Email content.
	 */
	private function render_template( string $title, string $content ): string {
		ob_start();
		?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $title ); ?></title>
</head>
<body style="margin:0;padding:40px 0;background:#f3f5f7;font-family:Arial,Helvetica,sans-serif;color:#1d2327;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td align="center">
<table role="presentation" width="650" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 18px rgba(0,0,0,.08);">
<tr>
<td style="background:<?php echo esc_attr( self::PRIMARY ); ?>;padding:35px;text-align:center;">
<img src="<?php echo esc_url( self::LOGO_URL ); ?>" alt="ADAM" style="max-width:180px;height:auto;display:block;margin:0 auto 25px;">
<h1 style="margin:0;color:#ffffff;font-size:30px;font-weight:700;"><?php echo esc_html( $title ); ?></h1>
</td>
</tr>
<tr>
<td style="padding:40px;font-size:16px;line-height:1.8;">
<?php echo wp_kses_post( $content ); ?>
</td>
</tr>
<tr>
<td style="padding:30px;background:#fafafa;border-top:1px solid #e4e4e4;font-size:13px;line-height:1.8;color:#666;">
<p style="margin-top:0;"><?php esc_html_e( 'Caso necessite de apoio, contacte a Direção da ADAM.', 'adam-membership' ); ?></p>
<p style="margin-bottom:0;"><strong>ADAM - Associação Desportiva de Airsoft do Mondego</strong></p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
		<?php

		return (string) ob_get_clean();
	}
}
