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
	public function send_approval_email(
		Member $member
	): bool {

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
			$message
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
			)
		);
	}
		/**
	 * Send HTML email.
	 */
	private function send(
		string $recipient,
		string $subject,
		string $message
	): bool {

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		$sent = wp_mail(
			$recipient,
			$subject,
			$message,
			$headers
		);

		if ( ! $sent ) {

			$this->logger->error(
				'Email failed.',
				array(
					'recipient' => $recipient,
					'subject'   => $subject,
				)
			);
		}

		return $sent;
	}

	/**
	 * Build approval email.
	 */
	private function build_approval_message(
		Member $member
	): string {

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
				Aceder à Área de Sócio
			</a>',
			esc_url(
				$this->settings->member_area_url()
			),
			self::PRIMARY
		);

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