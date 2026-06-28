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
	 * Secondary green colour.
	 */
	private const PRIMARY_DARK = '#1b5e20';

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
	 */
	public function __construct(
		SettingsRepository $settings,
		Logger $logger
	) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Send approval email.
	 */
	public function send_approval_email( Member $member ): bool {

		$recipient = $member->email();

		if ( '' === $recipient ) {

			$this->logger->error(
				'Approval email was not sent because the member has no email address.',
				array(
					'user_id' => $member->user_id(),
				)
			);

			return false;
		}

		$subject = 'A sua inscrição na ADAM foi aprovada';

		$message = $this->build_approval_message(
			$member
		);

		return $this->send(
			$recipient,
			$subject,
			$message,
			'account_approved',
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
		return $this->send_member_lifecycle_email(
			$member,
			'A sua inscrição na ADAM não foi aprovada',
			'Rejeição da inscrição',
			sprintf(
				'<p>Olá <strong>%1$s</strong>,</p>
				<p>A sua inscrição foi analisada pela Direção da ADAM e não foi aprovada.</p>
				%2$s
				<p>Caso pretenda mais informações, contacte a Direção da ADAM.</p>',
				esc_html( $member->full_name() ),
				'' !== $reason ? '<p><strong>Motivo indicado:</strong> ' . esc_html( $reason ) . '</p>' : ''
			),
			'registration_rejected'
		);
	}

	/**
	 * Send renewal submitted confirmation email.
	 *
	 * @param Member $member     Member.
	 * @param int    $renewal_id Renewal request ID.
	 */
	public function send_renewal_submitted_email( Member $member, int $renewal_id = 0 ): bool {
		return $this->send_member_lifecycle_email(
			$member,
			'Pedido de renovação recebido',
			'Renovação em análise',
			sprintf(
				'<p>Olá <strong>%1$s</strong>,</p>
				<p>Recebemos o seu pedido de renovação de quota.</p>
				<p>A Direção da ADAM irá analisar o comprovativo de pagamento submetido.</p>
				<p><strong>Estado atual:</strong> Renovação em análise.</p>',
				esc_html( $member->full_name() )
			),
			'renewal_submitted',
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
		$button = $this->button( $this->settings->renewal_page_url(), 'Abrir formulário de renovação' );

		return $this->send_member_lifecycle_email(
			$member,
			'Lembrete de renovação da quota ADAM',
			'Renovação da quota',
			sprintf(
				'<p>Olá <strong>%1$s</strong>,</p>
				<p>A validade da sua quota está a aproximar-se.</p>
				<p><strong>N.º de sócio:</strong> %2$s</p>
				<p><strong>Validade atual:</strong> %3$s</p>
				<p>Para manter a inscrição ativa, efetue o pagamento e submeta o formulário de renovação com o comprovativo.</p>
				<p style="text-align:center;">%4$s</p>',
				esc_html( $member->full_name() ),
				esc_html( $this->member_number( $member ) ),
				esc_html( $this->format_date( $member->field( 'validade_quota' ) ) ),
				$button
			),
			'renewal_reminder'
		);
	}


	/**
	 * Send renewal approved email.
	 *
	 * @param Member $member Member.
	 */
	public function send_renewal_approved_email( Member $member, int $renewal_id = 0 ): bool {
		$button = $this->button( $this->settings->member_area_url(), 'Aceder à Área de Sócio' );

		return $this->send_member_lifecycle_email(
			$member,
			'Renovação da quota aprovada',
			'Quota renovada',
			sprintf(
				'<p>Olá <strong>%1$s</strong>,</p>
				<p>A sua renovação de quota foi aprovada.</p>
				<p><strong>N.º de sócio:</strong> %2$s</p>
				<p><strong>Nova validade da quota:</strong> %3$s</p>
				<p style="text-align:center;">%4$s</p>',
				esc_html( $member->full_name() ),
				esc_html( $this->member_number( $member ) ),
				esc_html( $this->format_date( $member->field( 'validade_quota' ) ) ),
				$button
			),
			'renewal_approved',
			array( 'renewal_id' => $renewal_id )
		);
	}

	/**
	 * Send renewal rejected email.
	 *
	 * @param Member $member Member.
	 * @param string $reason Safe rejection reason.
	 */
	public function send_renewal_rejected_email( Member $member, string $reason = '', int $renewal_id = 0 ): bool {
		$button = $this->button( $this->settings->renewal_page_url(), 'Aceder à renovação' );

		return $this->send_member_lifecycle_email(
			$member,
			'Renovação da quota não aprovada',
			'Renovação não aprovada',
			sprintf(
				'<p>Olá <strong>%1$s</strong>,</p>
				<p>O seu pedido de renovação foi analisado e não foi aprovado.</p>
				%2$s
				<p>Caso pretenda mais informações, contacte a Direção da ADAM.</p>
				<p style="text-align:center;">%3$s</p>',
				esc_html( $member->full_name() ),
				'' !== $reason ? '<p><strong>Motivo indicado:</strong> ' . esc_html( $reason ) . '</p>' : '',
				$button
			),
			'renewal_rejected',
			array( 'renewal_id' => $renewal_id )
		);
	}

	/**
	 * Send quota expired notice.
	 *
	 * @param Member $member Member.
	 */
	public function send_quota_expired_email( Member $member ): bool {
		$button = $this->button( $this->settings->renewal_page_url(), 'Renovar quota' );

		return $this->send_member_lifecycle_email(
			$member,
			'A sua quota ADAM expirou',
			'Quota expirada',
			sprintf(
				'<p>Olá <strong>%1$s</strong>,</p>
				<p>A sua quota encontra-se expirada.</p>
				<p><strong>N.º de sócio:</strong> %2$s</p>
				<p><strong>Validade da quota:</strong> %3$s</p>
				<p>A inscrição fica inativa até que a renovação seja submetida e aprovada pela ADAM.</p>
				<p style="text-align:center;">%4$s</p>',
				esc_html( $member->full_name() ),
				esc_html( $this->member_number( $member ) ),
				esc_html( $this->format_date( $member->field( 'validade_quota' ) ) ),
				$button
			),
			'quota_expired'
		);
	}

	/**
	 * Send password reset email.
	 */
	public function send_password_reset_email(
		WP_User $user,
		string $key
	): bool {

		$reset_url = add_query_arg(
			array(
				'login' => rawurlencode(
					$user->user_login
				),
				'key' => rawurlencode(
					$key
				),
			),
			home_url(
				'/redefinir-password/'
			)
		);

		$button = sprintf(
			'<a href="%1$s"
				style="
					display:inline-block;
					background:%2$s;
					color:#ffffff;
					padding:14px 28px;
					text-decoration:none;
					border-radius:8px;
					font-weight:bold;
					font-size:16px;
				">
				Redefinir Palavra-passe
			</a>',
			esc_url(
				$reset_url
			),
			self::PRIMARY
		);

		$content = sprintf(
			'
			<p>Olá <strong>%1$s</strong>,</p>

			<p>
			Recebemos um pedido para redefinir a palavra-passe da sua conta.
			</p>

			<p>
			Clique no botão abaixo para continuar.
			</p>

			<p style="text-align:center;">
			%2$s
			</p>

			<p>
			Se não efetuou este pedido,
			pode ignorar este email.
			</p>

			<p>
			Cumprimentos,
			<br>
			<strong>ADAM</strong>
			</p>
			',
			esc_html(
				$user->display_name
			),
			$button
		);

		return $this->send(
			$user->user_email,
			'Redefinição da Palavra-passe',
			$this->render_template(
				'Redefinição da Palavra-passe',
				$content
			),
			'password_reset',
			array( 'user_id' => (int) $user->ID )
		);
	}
		/**
	 * Send email confirmation email.
	 */
	public function send_email_confirmation(
		WP_User $user,
		string $new_email,
		string $link
	): bool {

		$button = sprintf(
			'<a href="%1$s"
				style="
					display:inline-block;
					background:%2$s;
					color:#ffffff;
					padding:14px 28px;
					text-decoration:none;
					border-radius:8px;
					font-weight:bold;
					font-size:16px;
				">
				Confirmar Email
			</a>',
			esc_url(
				$link
			),
			self::PRIMARY
		);

		$content = sprintf(
			'
			<p>Olá <strong>%1$s</strong>,</p>

			<p>
			Recebemos um pedido para alterar o endereço de email da sua conta.
			</p>

			<p>
			O novo endereço solicitado é:
			</p>

			<p>
			<strong>%2$s</strong>
			</p>

			<p>
			Para concluir a alteração clique no botão abaixo.
			</p>

			<p style="text-align:center;">
				%3$s
			</p>

			<p>
			Se não efetuou este pedido, ignore este email.
			Nenhuma alteração será realizada.
			</p>

			<p>
			Cumprimentos,<br>
			<strong>ADAM</strong>
			</p>
			',
			esc_html(
				$user->display_name
			),
			esc_html(
				$new_email
			),
			$button
		);

		return $this->send(
			$new_email,
			'Confirmar alteração de email',
			$this->render_template(
				'Confirmar alteração de email',
				$content
			),
			'email_confirmation',
			array( 'user_id' => (int) $user->ID )
		);
	}
		/**
	 * Send HTML email.
	 */
	private function send(
		string $recipient,
		string $subject,
		string $message,
		string $email_type = 'generic',
		array $context = array()
	): bool {

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );

		try {
			$sent = wp_mail(
				$recipient,
				$subject,
				$message,
				$headers
			);
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
			$this->logger->error(
				'Email failed.',
				$log_context
			);
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
	 * Send a member lifecycle email using the standard template.
	 *
	 * @param Member $member  Member.
	 * @param string $subject Email subject.
	 * @param string $title   Email title.
	 * @param string $content Email body content.
	 */
	private function send_member_lifecycle_email( Member $member, string $subject, string $title, string $content, string $email_type, array $context = array() ): bool {
		$recipient = $member->email();

		if ( '' === $recipient ) {
			$this->logger->error(
				'Membership lifecycle email was not sent because the member has no email address.',
				array(
					'user_id' => $member->user_id(),
					'subject' => $subject,
					'email_type' => $email_type,
				)
			);

			return false;
		}

		return $this->send(
			$recipient,
			$subject,
			$this->render_template( $title, $content ),
			$email_type,
			array_merge( $context, array( 'member_id' => $member->user_id() ) )
		);
	}

	/**
	 * Build approval email.
	 */
	private function build_approval_message(
		Member $member
	): string {

		$button = $this->button( $this->settings->member_area_url(), 'Aceder à Área de Sócio' );

		$content = sprintf(
			'
			<p>Olá <strong>%1$s</strong>,</p>

			<p>
			É com enorme satisfação que informamos que a sua inscrição na
			<strong>ADAM - Associação Desportiva de Airsoft do Mondego</strong>
			foi aprovada.
			</p>

			<p>
			O seu registo encontra-se agora
			<strong>ativo</strong>.
			</p>

			<hr>

			<p>
			<strong>N.º de Sócio:</strong>
			%2$s
			</p>

			<p style="text-align:center;">
			%3$s
			</p>

			<p>
			Na Área de Sócio poderá consultar os seus dados, renovar a quota e alterar a sua palavra-passe.
			</p>

			<p>
			Por motivos de segurança, esta mensagem não inclui a sua palavra-passe.
			</p>

			<p>
			Cumprimentos,<br>
			<strong>A Direção da ADAM</strong>
			</p>
			',
			esc_html(
				$member->full_name()
			),
			esc_html(
				(string) $member->field(
					'numero_socio'
				)
			),
			$button
		);

		return $this->render_template(
			'Bem-vindo à ADAM!',
			$content
		);
	}

	/**
	 * Build a consistent email button.
	 *
	 * @param string $url   Button URL.
	 * @param string $label Button label.
	 */
	private function button( string $url, string $label ): string {
		return sprintf(
			'<a href="%1$s"
				style="
					display:inline-block;
					background:%2$s;
					color:#ffffff;
					padding:14px 28px;
					text-decoration:none;
					border-radius:8px;
					font-weight:bold;
					font-size:16px;
				">
				%3$s
			</a>',
			esc_url( $url ),
			esc_attr( self::PRIMARY ),
			esc_html( $label )
		);
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
	private function render_template(
		string $title,
		string $content
	): string {

		ob_start();
		?>
<!DOCTYPE html>
<html lang="pt">
<head>

<meta charset="UTF-8">

<meta
	name="viewport"
	content="width=device-width, initial-scale=1.0">

<title><?php echo esc_html( $title ); ?></title>

</head>

<body
	style="
		margin:0;
		padding:40px 0;
		background:#f3f5f7;
		font-family:Arial,Helvetica,sans-serif;
		color:#1d2327;
	">

<table
	role="presentation"
	width="100%"
	cellpadding="0"
	cellspacing="0">

<tr>

<td align="center">

<table
	role="presentation"
	width="650"
	cellpadding="0"
	cellspacing="0"
	style="
		background:#ffffff;
		border-radius:12px;
		overflow:hidden;
		box-shadow:0 4px 18px rgba(0,0,0,.08);
	">

<tr>

<td
	style="
		background:<?php echo esc_attr( self::PRIMARY ); ?>;
		padding:35px;
		text-align:center;
	">

<img
	src="<?php echo esc_url( self::LOGO_URL ); ?>"
	alt="ADAM"
	style="
		max-width:180px;
		height:auto;
		display:block;
		margin:0 auto 25px;
	">

<h1
	style="
		margin:0;
		color:#ffffff;
		font-size:30px;
		font-weight:700;
	">

<?php echo esc_html( $title ); ?>

</h1>

</td>

</tr>

<tr>

<td
	style="
		padding:40px;
		font-size:16px;
		line-height:1.8;
	">

<?php echo wp_kses_post( $content ); ?>

</td>

</tr>

<tr>

<td
	style="
		padding:30px;
		background:#fafafa;
		border-top:1px solid #e4e4e4;
		font-size:13px;
		line-height:1.8;
		color:#666;
	">

<p style="margin-top:0;">

Caso necessite de apoio, contacte a Direção da ADAM.

</p>

<p>

<a
	href="<?php echo esc_url( $this->settings->member_area_url() ); ?>"
	style="
		color:<?php echo esc_attr( self::PRIMARY ); ?>;
		font-weight:bold;
		text-decoration:none;
	">

Área de Sócio

</a>

</p>

<p style="margin-bottom:0;">

Esta é uma mensagem automática da
<strong>ADAM – Associação Desportiva de Airsoft do Mondego</strong>.

</p>

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
