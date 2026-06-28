<?php
/**
 * Member history service.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use WP_User;

/**
 * Records meaningful member timeline events.
 */
final class HistoryService {
	public const ACTOR_ADMIN  = 'admin';
	public const ACTOR_MEMBER = 'member';
	public const ACTOR_SYSTEM = 'system';

	private HistoryRepository $history;
	private MemberRepository $members;

	/**
	 * Constructor.
	 */
	public function __construct( HistoryRepository $history, MemberRepository $members ) {
		$this->history = $history;
		$this->members = $members;
	}

	/**
	 * Register runtime hooks for member activity.
	 */
	public function register(): void {
		add_action( 'wp_login', array( $this, 'handle_login' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'handle_logout' ) );
	}

	/**
	 * Handle member logins.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       User.
	 */
	public function handle_login( string $user_login, WP_User $user ): void {
		unset( $user_login );

		$member = $this->members->find( (int) $user->ID );

		if ( null === $member ) {
			return;
		}

		$this->member_event(
			'member_login',
			__( 'Início de sessão do sócio', 'adam-membership' ),
			$member,
			__( 'O sócio iniciou sessão com sucesso.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Handle member logouts.
	 */
	public function handle_logout(): void {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return;
		}

		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return;
		}

		$this->member_event(
			'member_logout',
			__( 'Fim de sessão do sócio', 'adam-membership' ),
			$member,
			__( 'O sócio terminou a sessão.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Log a completed registration submission.
	 *
	 * @param Member $member   Member.
	 * @param int    $entry_id Forminator entry ID.
	 */
	public function registration_submitted( Member $member, int $entry_id ): void {
		$this->member_event(
			'registration_submitted',
			__( 'Inscrição submetida', 'adam-membership' ),
			$member,
			__( 'O formulário de inscrição foi submetido com sucesso.', 'adam-membership' ),
			array(
				'entry_id' => $entry_id,
			)
		);
	}

	/**
	 * Log a password reset request.
	 *
	 * @param Member $member Member.
	 */
	public function password_reset_requested( Member $member ): void {
		$this->system_event(
			'password_reset_requested',
			__( 'Reposição de palavra-passe pedida', 'adam-membership' ),
			$member,
			__( 'Foram pedidas instruções para repor a palavra-passe.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Log a completed password reset.
	 *
	 * @param Member $member Member.
	 */
	public function password_reset_completed( Member $member ): void {
		$this->member_event(
			'password_reset_completed',
			__( 'Reposição de palavra-passe concluída', 'adam-membership' ),
			$member,
			__( 'A palavra-passe foi reposta com sucesso.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Log a member password change.
	 *
	 * @param Member $member Member.
	 */
	public function password_changed( Member $member ): void {
		$this->member_event(
			'password_changed',
			__( 'Palavra-passe alterada', 'adam-membership' ),
			$member,
			__( 'O sócio alterou a palavra-passe da conta.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Log a requested email change.
	 *
	 * @param Member $member Member.
	 */
	public function email_change_requested( Member $member ): void {
		$this->member_event(
			'email_change_requested',
			__( 'Alteração de email pedida', 'adam-membership' ),
			$member,
			__( 'O sócio pediu uma alteração do endereço de email.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Log a completed email change.
	 *
	 * @param Member $member    Member.
	 * @param string $old_email Previous email.
	 * @param string $new_email New email.
	 */
	public function email_changed( Member $member, string $old_email, string $new_email ): void {
		$this->member_event(
			'email_changed',
			__( 'Email alterado', 'adam-membership' ),
			$member,
			__( 'O endereço de email do sócio foi atualizado.', 'adam-membership' ),
			array(
				'old_email' => sanitize_email( $old_email ),
				'new_email' => sanitize_email( $new_email ),
			)
		);
	}

	/**
	 * Log an approval action.
	 *
	 * @param Member $member     Member.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 */
	public function member_approved( Member $member, string $old_status, string $new_status ): void {
		$this->admin_event(
			'member_approved',
			__( 'Sócio aprovado', 'adam-membership' ),
			$member,
			__( 'A administração aprovou a inscrição.', 'adam-membership' ),
			array(
				'old_status' => $old_status,
				'new_status' => $new_status,
			)
		);
	}

	/**
	 * Log a rejection action.
	 *
	 * @param Member $member     Member.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @param string $reason     Rejection reason.
	 */
	public function member_rejected( Member $member, string $old_status, string $new_status, string $reason = '' ): void {
		$this->admin_event(
			'member_rejected',
			__( 'Sócio rejeitado', 'adam-membership' ),
			$member,
			__( 'A administração rejeitou a inscrição.', 'adam-membership' ),
			array(
				'old_status' => $old_status,
				'new_status' => $new_status,
				'reason'     => sanitize_text_field( $reason ),
			)
		);
	}

	/**
	 * Log an admin member edit.
	 *
	 * @param Member                              $member  Member.
	 * @param array<string, array{old:string,new:string}> $changes Changed fields.
	 */
	public function member_edited_by_admin( Member $member, array $changes ): void {
		$this->admin_event(
			'member_edited_by_admin',
			__( 'Sócio editado pela administração', 'adam-membership' ),
			$member,
			__( 'A administração atualizou os dados do sócio.', 'adam-membership' ),
			array(
				'changes' => $changes,
			)
		);
	}

	/**
	 * Log a quota validity change.
	 *
	 * @param Member $member     Member.
	 * @param string $old_expiry Previous expiry date.
	 * @param string $new_expiry New expiry date.
	 */
	public function quota_date_changed( Member $member, string $old_expiry, string $new_expiry ): void {
		$this->admin_event(
			'quota_date_changed',
			__( 'Data da quota alterada', 'adam-membership' ),
			$member,
			__( 'A administração alterou a data de validade da quota.', 'adam-membership' ),
			array(
				'old_value' => $old_expiry,
				'new_value' => $new_expiry,
			)
		);
	}

	/**
	 * Log a manual approval email resend.
	 *
	 * @param Member $member Member.
	 */
	public function approval_email_resent( Member $member ): void {
		$this->admin_event(
			'approval_email_resent',
			__( 'Email de aprovação reenviado', 'adam-membership' ),
			$member,
			__( 'A administração reenviou manualmente o email de aprovação.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Log a digital card token regeneration.
	 *
	 * @param Member $member Member.
	 */
	public function card_token_regenerated( Member $member ): void {
		$this->admin_event(
			'card_token_regenerated',
			__( 'Token do cartão regenerado', 'adam-membership' ),
			$member,
			__( 'A administração regenerou o token de validação do cartão digital.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Log a renewal submission.
	 *
	 * @param Member $member      Member.
	 * @param int    $renewal_id  Renewal request ID.
	 * @param int    $entry_id    Forminator entry ID.
	 * @param bool   $proof_added Whether a proof of payment was attached.
	 */
	public function renewal_submitted( Member $member, int $renewal_id, int $entry_id, bool $proof_added ): void {
		$this->member_event(
			'renewal_submitted',
			__( 'Renovação submetida', 'adam-membership' ),
			$member,
			__( 'O sócio submeteu um pedido de renovação.', 'adam-membership' ),
			array(
				'renewal_id'      => $renewal_id,
				'submission_id'   => $entry_id,
				'proof_attached'  => $proof_added ? 'sim' : 'não',
			)
		);
	}

	/**
	 * Log an administrator review of a renewal request.
	 *
	 * @param Member $member     Member.
	 * @param int    $renewal_id Renewal request ID.
	 */
	public function renewal_reviewed( Member $member, int $renewal_id ): void {
		$this->admin_event(
			'renewal_reviewed',
			__( 'Renovação revista', 'adam-membership' ),
			$member,
			__( 'A administração reviu o pedido de renovação.', 'adam-membership' ),
			array(
				'renewal_id' => $renewal_id,
			)
		);
	}

	/**
	 * Log an approved renewal.
	 *
	 * @param Member                              $member     Member.
	 * @param int                                 $renewal_id Renewal request ID.
	 * @param string                              $old_expiry Previous expiry date.
	 * @param string                              $new_expiry New expiry date.
	 * @param array<string, array{old:string,new:string}> $changes Profile changes applied.
	 */
	public function renewal_approved( Member $member, int $renewal_id, string $old_expiry, string $new_expiry, array $changes = array() ): void {
		$this->admin_event(
			'renewal_approved',
			__( 'Renovação aprovada', 'adam-membership' ),
			$member,
			__( 'A administração aprovou o pedido de renovação.', 'adam-membership' ),
			array(
				'renewal_id'  => $renewal_id,
				'old_expiry'  => $old_expiry,
				'new_expiry'  => $new_expiry,
				'field_changes' => $changes,
			)
		);
	}

	/**
	 * Log a rejected renewal.
	 *
	 * @param Member $member     Member.
	 * @param int    $renewal_id Renewal request ID.
	 * @param string $reason     Rejection reason.
	 */
	public function renewal_rejected( Member $member, int $renewal_id, string $reason = '' ): void {
		$this->admin_event(
			'renewal_rejected',
			__( 'Renovação rejeitada', 'adam-membership' ),
			$member,
			__( 'A administração rejeitou o pedido de renovação.', 'adam-membership' ),
			array(
				'renewal_id' => $renewal_id,
				'reason'     => sanitize_text_field( $reason ),
			)
		);
	}

	/**
	 * Log a system-generated quota expiry.
	 *
	 * @param Member $member      Member.
	 * @param string $expiry_date Expiry date.
	 */
	public function quota_expired( Member $member, string $expiry_date ): void {
		$this->system_event(
			'quota_expired',
			__( 'Quota expirada', 'adam-membership' ),
			$member,
			__( 'A inscrição expirou automaticamente após a data de validade da quota.', 'adam-membership' ),
			array(
				'expiry_date' => $expiry_date,
			)
		);
	}

	/**
	 * Log a renewal reminder email.
	 *
	 * @param Member $member Member.
	 */
	public function renewal_reminder_sent( Member $member ): void {
		$this->system_event(
			'renewal_reminder_sent',
			__( 'Lembrete de renovação enviado', 'adam-membership' ),
			$member,
			__( 'O sistema enviou um email de lembrete de renovação.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Log a quota expired notice email.
	 *
	 * @param Member $member Member.
	 */
	public function quota_expired_notice_sent( Member $member ): void {
		$this->system_event(
			'quota_expired_notice_sent',
			__( 'Aviso de quota expirada enviado', 'adam-membership' ),
			$member,
			__( 'O sistema enviou o email de aviso de quota expirada.', 'adam-membership' ),
			array()
		);
	}

	/**
	 * Log an admin event.
	 *
	 * @param string               $action_key Action key.
	 * @param string               $action     Action label.
	 * @param Member               $member     Member.
	 * @param string               $message    Description.
	 * @param array<string, mixed> $details    Details.
	 */
	private function admin_event( string $action_key, string $action, Member $member, string $message, array $details ): void {
		$this->record(
			$action_key,
			$action,
			self::ACTOR_ADMIN,
			$member,
			$message,
			$details,
			get_current_user_id()
		);
	}

	/**
	 * Log a member event.
	 *
	 * @param string               $action_key Action key.
	 * @param string               $action     Action label.
	 * @param Member               $member     Member.
	 * @param string               $message    Description.
	 * @param array<string, mixed> $details    Details.
	 */
	private function member_event( string $action_key, string $action, Member $member, string $message, array $details ): void {
		$this->record(
			$action_key,
			$action,
			self::ACTOR_MEMBER,
			$member,
			$message,
			$details,
			$member->user_id()
		);
	}

	/**
	 * Log a system event.
	 *
	 * @param string               $action_key Action key.
	 * @param string               $action     Action label.
	 * @param Member               $member     Member.
	 * @param string               $message    Description.
	 * @param array<string, mixed> $details    Details.
	 */
	private function system_event( string $action_key, string $action, Member $member, string $message, array $details ): void {
		$this->record(
			$action_key,
			$action,
			self::ACTOR_SYSTEM,
			$member,
			$message,
			$details,
			0
		);
	}

	/**
	 * Persist a history entry.
	 *
	 * @param string               $action_key Action key.
	 * @param string               $action     Action label.
	 * @param string               $actor_type Actor type.
	 * @param Member               $member     Member.
	 * @param string               $message    Description.
	 * @param array<string, mixed> $details    Details.
	 * @param int                  $actor_id   Actor user ID.
	 */
	private function record( string $action_key, string $action, string $actor_type, Member $member, string $message, array $details, int $actor_id ): void {
		$this->history->create(
			array(
				'member_id'     => $member->user_id(),
				'member_number' => sanitize_text_field( (string) $member->field( 'numero_socio' ) ),
				'member_name'   => sanitize_text_field( $member->full_name() ),
				'member_email'  => sanitize_email( $member->email() ),
				'action_key'    => sanitize_key( $action_key ),
				'action_label'  => sanitize_text_field( $action ),
				'actor_type'    => sanitize_key( $actor_type ),
				'actor_id'      => $actor_id,
				'actor_name'    => $this->actor_name( $actor_type, $actor_id, $member ),
				'description'   => sanitize_text_field( $message ),
				'details'       => $this->sanitize_details( $details ),
				'created_at'    => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);
	}

	/**
	 * Resolve a safe actor label.
	 *
	 * @param string $actor_type Actor type.
	 * @param int    $actor_id   Actor user ID.
	 * @param Member $member     Member.
	 */
	private function actor_name( string $actor_type, int $actor_id, Member $member ): string {
		if ( self::ACTOR_SYSTEM === $actor_type ) {
			return __( 'Sistema', 'adam-membership' );
		}

		$user = 0 !== $actor_id ? get_user_by( 'ID', $actor_id ) : null;

		if ( $user instanceof WP_User ) {
			return sanitize_text_field( $user->display_name );
		}

		return self::ACTOR_MEMBER === $actor_type ? sanitize_text_field( $member->full_name() ) : __( 'Administrador', 'adam-membership' );
	}

	/**
	 * Sanitize structured details for storage.
	 *
	 * @param array<string, mixed> $details Details.
	 * @return array<string, mixed>
	 */
	private function sanitize_details( array $details ): array {
		$sanitized = array();

		foreach ( $details as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_details( $value );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			if ( null === $value ) {
				$sanitized[ $key ] = '';
				continue;
			}

			$sanitized[ $key ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}
}
