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
use AdamMembership\Core\ManagedPages;
use AdamMembership\Document\PrivateDocument;
use AdamMembership\Document\PrivateDocumentRepository;
use AdamMembership\Document\PrivateDocumentStorage;
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
	private PrivateDocumentRepository $private_documents;
	private PrivateDocumentStorage $private_document_storage;
	private string $last_mail_error_code = '';
	private string $last_mail_error_message = '';

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @param Logger             $logger   Logger helper.
	 */
	public function __construct( SettingsRepository $settings, Logger $logger, PrivateDocumentRepository $private_documents, PrivateDocumentStorage $private_document_storage ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->private_documents = $private_documents;
		$this->private_document_storage = $private_document_storage;
	}

	/**
	 * Get configurable admin email templates.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function admin_templates(): array {
		return array(
			'registration_received' => array(
				'label'        => __( 'Inscrição recebida', 'adam-membership' ),
				'description'  => __( 'Enviado logo após a submissão da inscrição para o novo sócio definir o utilizador e a palavra-passe.', 'adam-membership' ),
				'placeholders' => array( 'member_name', 'member_email', 'account_setup_link', 'processing_period', 'ana_processing_note' ),
			),
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
			'member_change_received' => array(
				'label' => 'Pedido de atualização de dados recebido',
				'description' => 'Enviado quando um sócio submete alterações para aprovação.',
				'placeholders' => array( 'member_name' ),
			),
			'apd_association_received' => array(
				'label' => 'Pedido de associação ANA recebido',
				'description' => 'Enviado quando um sócio submete um pedido APD/ANA.',
				'placeholders' => array( 'member_name', 'amount' ),
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
	public function send_approval_email( Member $member, ?PrivateDocument $document = null ): bool {
		$delivery = $this->document_delivery( $document );
		$sent = $this->send_member_template_email(
			'member_approved',
			$member,
			array(
				'member_area_link' => $this->settings->member_area_url(),
				'login_link'       => $this->settings->member_area_url(),
			),
			array( 'member_id' => $member->user_id() ),
			$delivery['attachments'],
			$delivery['note']
		);
		if ( ! $sent && '' === $delivery['error'] ) {
			$delivery['error'] = $this->last_mail_error_code ?: 'email_send_failed';
		}
		$this->record_document_delivery( $document, $delivery, $sent );

		return $sent;
	}

	/**
	 * Send account setup email after registration submission.
	 *
	 * @param Member $member Member.
	 * @param string $setup_link Secure setup link.
	 */
	public function send_registration_received_email( Member $member, string $setup_link ): bool {
		return $this->send_member_template_email(
			'registration_received',
			$member,
			array(
				'member_email'       => $member->email(),
				'account_setup_link' => $setup_link,
				'processing_period'  => 'adam_primary' === (string) $member->field( 'adam_membership_origin' ) ? '2–7 dias' : '2–5 dias',
				'ana_processing_note' => 'adam_primary' === (string) $member->field( 'adam_membership_origin' ) ? 'A aprovação como sócio só será concluída após confirmação da ANA.' : '',
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
				'correction_body' => '',
			)
		);
	}

	public function send_registration_correction_email( Member $member, string $reason = '', string $note = '' ): bool {
		$link = add_query_arg( array( 'view' => 'correction', 'correction_user' => $member->user_id(), 'correction_token' => hash_hmac( 'sha256', (string) $member->user_id(), wp_salt( 'auth' ) ) ), $this->settings->member_area_url() );
		$body = '';
		if ( '' !== trim( $note ) ) { $body .= '<p><strong>O que precisa de corrigir:</strong><br>' . nl2br( esc_html( $note ) ) . '</p>'; }
		$body .= '<p><a href="' . esc_url( $link ) . '" style="display:inline-block;background:#4f9f2f;color:#fff;padding:12px 20px;text-decoration:none;border-radius:4px;font-weight:bold;">CORRIGIR PEDIDO</a></p>';
		return $this->send_member_template_email( 'member_correction_requested', $member, array( 'reason' => $reason, 'correction_html' => $body ), array( 'member_id' => $member->user_id() ) );
	}

	public function send_member_change_received_email( Member $member ): bool {
		return $this->send_member_template_email( 'member_change_received', $member, array(), array( 'member_id' => $member->user_id() ) );
	}

	public function send_apd_association_received_email( Member $member, string $amount ): bool {
		return $this->send_member_template_email( 'apd_association_received', $member, array( 'amount' => $amount ), array( 'member_id' => $member->user_id() ) );
	}

	public function send_apd_association_rejected_email( Member $member, string $reason = '' ): bool {
		return $this->send_member_template_email( 'apd_association_rejected', $member, array( 'reason' => $reason ?: 'Sem motivo adicional indicado.' ) );
	}

	public function send_apd_association_approved_email( Member $member, string $ana_number = '' ): bool {
		return $this->send_member_template_email( 'apd_association_approved', $member, array( 'ana_number' => $ana_number, 'member_area_link' => $this->settings->member_area_url() ) );
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
	public function send_renewal_approved_email( Member $member, int $renewal_id = 0, ?PrivateDocument $document = null ): bool {
		$delivery = $this->document_delivery( $document );
		$sent = $this->send_member_template_email(
			'renewal_approved',
			$member,
			array(
				'member_area_link' => $this->settings->member_area_url(),
			),
			array( 'renewal_id' => $renewal_id ),
			$delivery['attachments'],
			$delivery['note']
		);
		if ( ! $sent && '' === $delivery['error'] ) {
			$delivery['error'] = $this->last_mail_error_code ?: 'email_send_failed';
		}
		$this->record_document_delivery( $document, $delivery, $sent );

		return $sent;
	}

	/** Send only a private document in a short message. */
	public function send_private_document_email( Member $member, PrivateDocument $document ): bool {
		$this->logger->info( 'Private document send trace v1: email service entered.', array( 'member_id' => $member->user_id(), 'document_id' => $document->id() ) );
		$delivery = $this->document_delivery( $document );
		$this->logger->info( 'Private document send trace v1: attachment preparation completed.', array( 'document_id' => $document->id(), 'available' => $delivery['available'], 'error_code' => $delivery['error'] ) );
		if ( array() === $delivery['attachments'] ) {
			$this->record_document_delivery( $document, $delivery, false );
			return false;
		}
		$sent = $this->send( $member->email(), __( 'Documento referente ao pagamento da sua quota', 'adam-membership' ), '<p>Segue em anexo o documento referente ao pagamento da sua quota.</p>', 'private_document', array( 'member_id' => $member->user_id(), 'document_id' => $document->id() ), $delivery['attachments'] );
		if ( ! $sent && '' === $delivery['error'] ) {
			$delivery['error'] = $this->last_mail_error_code ?: 'email_send_failed';
		}
		$this->record_document_delivery( $document, $delivery, $sent );

		return $sent;
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
			ManagedPages::url( 'password_reset' )
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
	private function send( string $recipient, string $subject, string $message, string $email_type = 'generic', array $context = array(), array $attachments = array() ): bool {
		$this->last_mail_error_code = '';
		$this->last_mail_error_message = '';
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );

		$mail_error_code = '';
		$mail_failure_listener = static function ( $error ) use ( &$mail_error_code ): void {
			if ( $error instanceof \WP_Error ) {
				$mail_error_code = (string) $error->get_error_code();
			}
		};
		$mail_failure_message_listener = function ( $error ) use ( &$mail_error_message ): void {
			if ( $error instanceof \WP_Error ) {
				$mail_error_message = substr( wp_strip_all_tags( (string) $error->get_error_message() ), 0, 500 );
			}
		};
		add_action( 'wp_mail_failed', $mail_failure_listener, 10, 1 );
		$mail_error_message = '';
		add_action( 'wp_mail_failed', $mail_failure_message_listener, 10, 1 );
		$sent = false;
		try {
			if ( ! is_email( $recipient ) ) {
				$mail_error_code = 'invalid_recipient';
			} else {
				$sent = wp_mail( $recipient, $subject, $message, $headers, $attachments );
			}
		} catch ( \Throwable $exception ) {
			$mail_error_code = 'mail_exception';
			$mail_error_message = substr( wp_strip_all_tags( $exception->getMessage() ), 0, 500 );
			$this->logger->error( 'Email transport threw an exception.', array_merge( $context, array( 'email_type' => $email_type, 'exception_class' => get_class( $exception ), 'error_message' => $mail_error_message ) ) );
		} finally {
			remove_action( 'wp_mail_failed', $mail_failure_listener, 10 );
			remove_action( 'wp_mail_failed', $mail_failure_message_listener, 10 );
			remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
			remove_filter( 'wp_mail_from_name', array( $this, 'mail_from_name' ) );
		}
		$this->last_mail_error_code = $sent ? '' : ( $mail_error_code ?: 'email_send_failed' );
		$this->last_mail_error_message = $sent ? '' : $mail_error_message;

		$log_context = array_merge(
			$context,
			array(
				'email_type'     => $email_type,
				'recipient_hash' => wp_hash( $recipient ),
				'subject'        => $subject,
			)
		);

		if ( ! $sent ) {
			$this->logger->error( 'Email failed.', array_merge( $log_context, array( 'error_code' => $this->last_mail_error_code, 'error_message' => $this->last_mail_error_message ) ) );
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
	private function send_member_template_email( string $template_key, Member $member, array $extra = array(), array $context = array(), array $attachments = array(), string $append_html = '' ): bool {
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
			array_merge( $this->member_template_context( $member ), array( 'support_email' => $this->settings->support_email() ), $extra )
		);

		if ( null === $rendered ) {
			return false;
		}
		$rendered['html'] .= $append_html;

		return $this->send(
			$recipient,
			$rendered['subject'],
			$rendered['html'],
			$template_key,
			array_merge( $context, array( 'member_id' => $member->user_id() ) ),
			$attachments
		);
	}

	/** @return array{attachments:array<string,string>,note:string,available:bool,error:string} */
	private function document_delivery( ?PrivateDocument $document ): array {
		if ( null === $document ) {
			return array( 'attachments' => array(), 'note' => '', 'available' => false, 'error' => '' );
		}
		$path = $this->private_document_storage->path( $document );
		if ( is_wp_error( $path ) ) {
			$this->logger->error( 'Private document attachment unavailable.', array( 'document_id' => $document->id(), 'error_code' => $path->get_error_code() ) );
			return array( 'attachments' => array(), 'note' => '', 'available' => false, 'error' => (string) $path->get_error_code() );
		}

		return array(
			'attachments' => array( $document->original_name() => $path ),
			'note'        => '<p>Segue em anexo o documento referente ao pagamento da sua quota.</p>',
			'available'   => true,
			'error'       => '',
		);
	}

	/** @param array{attachments:array<string,string>,note:string,available:bool,error:string} $delivery */
	private function record_document_delivery( ?PrivateDocument $document, array $delivery, bool $email_sent ): void {
		if ( null === $document ) {
			return;
		}
		$now = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
		$this->private_documents->update(
			$document,
			array(
				'send_status'   => $email_sent && $delivery['available'] ? 'sent' : 'failed',
				'last_sent_at'  => $email_sent && $delivery['available'] ? $now : null,
				'last_error'    => $email_sent && $delivery['available'] ? null : ( $delivery['error'] ?: 'email_send_failed' ),
			)
		);
		$this->logger->info(
			$email_sent && $delivery['available'] ? 'Private document email sent.' : 'Private document email failed.',
			array(
				'document_id' => $document->id(),
				'sha256'      => $document->sha256(),
				'send_status' => $email_sent && $delivery['available'] ? 'sent' : 'failed',
				'error_code'  => $email_sent && $delivery['available'] ? '' : ( $delivery['error'] ?: 'email_send_failed' ),
			)
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
			'member_email'     => $member->email(),
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
			'account_setup_link' => '',
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
			'member_email'      => 'joao@example.com',
			'member_number'     => 'ADAM-0001',
			'expiry_date'       => wp_date( 'd/m/Y', strtotime( '+1 year' ) ),
			'quota_value'       => '22,00 €',
			'payment_status'    => __( 'Renovação em análise', 'adam-membership' ),
			'login_link'        => $this->settings->member_area_url(),
			'member_area_link'  => $this->settings->member_area_url(),
			'renewal_link'      => $this->settings->renewal_page_url(),
			'reason'            => __( 'Falta um comprovativo legível.', 'adam-membership' ),
			'new_email'         => 'novo.email@example.com',
			'confirmation_link' => ManagedPages::url( 'email_confirmation' ),
			'reset_link'        => ManagedPages::url( 'password_reset' ),
			'account_setup_link' => $this->settings->account_setup_page_url(),
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
				: ( str_ends_with( $key, '_html' ) ? wp_kses_post( $value ) : esc_html( $value ) );
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
<p style="margin-top:0;">Caso necessite de ajuda ou esclarecimentos, contacte-nos através de <a href="mailto:<?php echo esc_attr( $this->settings->support_email() ); ?>"><?php echo esc_html( $this->settings->support_email() ); ?></a>.</p>
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
