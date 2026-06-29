<?php
/**
 * Membership renewal service.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Emails\EmailService;
use AdamMembership\Helpers\Logger;
use WP_Error;

/**
 * Coordinates renewal submission, review, approval, and rejection.
 */
final class RenewalService {
	private MemberRepository $members;
	private RenewalRepository $renewals;
	private EmailService $email;
	private Logger $logger;
	private HistoryService $history;
	private RecognitionService $recognition;

	/**
	 * Constructor.
	 */
	public function __construct( MemberRepository $members, RenewalRepository $renewals, EmailService $email, Logger $logger, HistoryService $history, RecognitionService $recognition ) {
		$this->members  = $members;
		$this->renewals = $renewals;
		$this->email    = $email;
		$this->logger   = $logger;
		$this->history  = $history;
		$this->recognition = $recognition;
	}

	/**
	 * Create a renewal request from Forminator submission data.
	 *
	 * @param int                        $entry_id   Forminator entry ID.
	 * @param array<int|string, mixed>   $field_data Submitted field data.
	 * @return RenewalRequest|WP_Error
	 */
	public function submit_from_forminator( int $entry_id, array $field_data ): RenewalRequest|WP_Error {
		$member = $this->member_from_submission( $field_data );

		if ( null === $member ) {
			return new WP_Error( 'adam_membership_renewal_member_not_found', __( 'Sócio da renovação não encontrado.', 'adam-membership' ) );
		}

		if ( array() !== $this->renewals->pending_for_user( $member->user_id() ) ) {
			return new WP_Error( 'adam_membership_renewal_already_pending', __( 'Já existe um pedido de renovação pendente de revisão.', 'adam-membership' ) );
		}

		$submitted_data = $this->submitted_profile_data( $field_data );
		$request        = $this->renewals->create(
			array(
				'user_id'              => $member->user_id(),
				'member_id'            => $member->user_id(),
				'member_number'        => (string) $member->field( 'numero_socio' ),
				'submission_id'        => $entry_id,
				'submitted_at'         => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
				'current_quota_expiry' => (string) $member->field( 'validade_quota' ),
				'proof_of_payment'     => $this->proof_of_payment( $field_data ),
				'submitted_data'       => $submitted_data,
			)
		);

		$member->save( array( 'estado' => Member::STATUS_RENEWAL_PENDING ) );
		$this->logger->info( 'Renewal submitted.', array( 'member_id' => $member->user_id(), 'renewal_id' => $request->id(), 'submission_id' => $entry_id ) );
		$this->history->renewal_submitted( $member, $request->id(), $entry_id, '' !== $this->proof_url( $request ) );
		$this->email->send_renewal_submitted_email( $member, $request->id() );

		return $request;
	}

	/**
	 * Approve a renewal request.
	 *
	 * @param int $request_id Request ID.
	 * @return true|WP_Error
	 */
	public function approve( int $request_id ): true|WP_Error {
		$request = $this->renewals->find( $request_id );

		if ( null === $request ) {
			return new WP_Error( 'adam_membership_renewal_not_found', __( 'Pedido de renovação não encontrado.', 'adam-membership' ) );
		}

		if ( RenewalRequest::STATUS_PENDING !== $request->status() ) {
			return new WP_Error( 'adam_membership_renewal_not_pending', __( 'Apenas pedidos de renovação pendentes podem ser aprovados.', 'adam-membership' ) );
		}

		$member = $this->members->find( $request->user_id() );

		if ( null === $member ) {
			return new WP_Error( 'adam_membership_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) );
		}

		$this->audit( 'Um administrador reviu a renovação.', $member, array( 'renewal_id' => $request->id() ) );
		$this->history->renewal_reviewed( $member, $request->id() );
		$field_changes = $this->changed_fields( $request, $member );

		foreach ( $field_changes as $field => $change ) {
			if ( 'email' === $field ) {
				$email_result = $this->update_member_email( $member, $change['new'] );

				if ( $email_result instanceof WP_Error ) {
					return $email_result;
				}
			} else {
				$member->save( array( $field => $change['new'] ) );
			}

			$this->audit( 'Campo do perfil alterado durante a aprovação da renovação.', $member, array( 'field' => $field, 'old_value' => $change['old'], 'new_value' => $change['new'] ) );
		}

		$old_expiry = (string) $member->field( 'validade_quota' );
		$new_expiry = $this->next_expiry_date( $member );

		$member->save(
			array(
				'estado'         => Member::STATUS_ACTIVE,
				'validade_quota' => $new_expiry,
			)
		);

		delete_user_meta( $member->user_id(), 'adam_membership_renewal_reminder_sent' );
		delete_user_meta( $member->user_id(), 'adam_membership_renewal_reminder_date' );

		$this->renewals->update(
			$request,
			array(
				'status'      => RenewalRequest::STATUS_APPROVED,
				'reviewed_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
				'reviewed_by' => get_current_user_id(),
			)
		);

		$this->audit( 'Data de validade alterada durante a aprovação da renovação.', $member, array( 'old_value' => $old_expiry, 'new_value' => $new_expiry, 'renewal_id' => $request->id() ) );
		$this->audit( 'Renovação aprovada.', $member, array( 'renewal_id' => $request->id() ) );
		$this->recognition->handle_renewal_approved( $member );
		$this->history->renewal_approved( $member, $request->id(), $old_expiry, $new_expiry, $field_changes );
		$this->email->send_renewal_approved_email( $member, $request->id() );

		return true;
	}

	/**
	 * Reject a renewal request.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $reason     Rejection reason.
	 * @return true|WP_Error
	 */
	public function reject( int $request_id, string $reason = '' ): true|WP_Error {
		$request = $this->renewals->find( $request_id );

		if ( null === $request ) {
			return new WP_Error( 'adam_membership_renewal_not_found', __( 'Pedido de renovação não encontrado.', 'adam-membership' ) );
		}

		if ( RenewalRequest::STATUS_PENDING !== $request->status() ) {
			return new WP_Error( 'adam_membership_renewal_not_pending', __( 'Apenas pedidos de renovação pendentes podem ser rejeitados.', 'adam-membership' ) );
		}

		$member = $this->members->find( $request->user_id() );

		if ( null === $member ) {
			return new WP_Error( 'adam_membership_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) );
		}

		$this->audit( 'Um administrador reviu a renovação.', $member, array( 'renewal_id' => $request->id() ) );
		$this->history->renewal_reviewed( $member, $request->id() );

		$member->save( array( 'estado' => Member::STATUS_EXPIRED ) );
		$this->renewals->update(
			$request,
			array(
				'status'           => RenewalRequest::STATUS_REJECTED,
				'rejection_reason' => $reason,
				'reviewed_at'      => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
				'reviewed_by'      => get_current_user_id(),
			)
		);

		$this->audit( 'Renovação rejeitada.', $member, array( 'renewal_id' => $request->id(), 'reason' => $reason ) );
		$this->history->renewal_rejected( $member, $request->id(), $reason );
		$this->email->send_renewal_rejected_email( $member, $reason, $request->id() );

		return true;
	}

	/**
	 * Get submitted changes compared to the current member profile.
	 *
	 * @param RenewalRequest $request Request.
	 * @param Member         $member  Member.
	 * @return array<string, array{old:string,new:string}>
	 */
	public function changed_fields( RenewalRequest $request, Member $member ): array {
		$changes = array();

		foreach ( $request->submitted_data() as $field => $new_value ) {
			$old_value = 'email' === $field ? $member->email() : $this->scalar( $member->field( $field ) );

			if ( '' === $new_value || $old_value === $new_value ) {
				continue;
			}

			$changes[ $field ] = array(
				'old' => $old_value,
				'new' => $new_value,
			);
		}

		return $changes;
	}

	/**
	 * Send a single renewal reminder when the member is within the reminder window.
	 *
	 * @param Member $member Member.
	 */
	public function maybe_send_renewal_reminder( Member $member ): void {
		if ( Member::QUOTA_EXPIRING_SOON !== $member->quota_status() || $member->isRejected() || $member->isRenewalPending() ) {
			return;
		}

		if ( '1' === (string) get_user_meta( $member->user_id(), 'adam_membership_renewal_reminder_sent', true ) ) {
			return;
		}

		if ( ! $this->email->send_renewal_reminder_email( $member ) ) {
			return;
		}

		update_user_meta( $member->user_id(), 'adam_membership_renewal_reminder_sent', '1' );
		update_user_meta( $member->user_id(), 'adam_membership_renewal_reminder_date', wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) );
		$this->audit( 'Lembrete de renovação enviado.', $member );
		$this->history->renewal_reminder_sent( $member );
	}

	/**
	 * Send the quota expired notice.
	 *
	 * @param Member $member Member.
	 */
	public function send_quota_expired_notice( Member $member ): bool {
		$sent = $this->email->send_quota_expired_email( $member );

		if ( $sent ) {
			$this->audit( 'Aviso de quota expirada enviado.', $member );
			$this->history->quota_expired_notice_sent( $member );
		}

		return $sent;
	}

	/**
	 * Build a Forminator submission admin URL.
	 *
	 * @param RenewalRequest $request Request.
	 */
	public function forminator_submission_url( RenewalRequest $request ): string {
		return add_query_arg(
			array(
				'page'     => 'forminator-entries',
				'form_id'  => 280,
				'entry_id' => $request->submission_id(),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a proof of payment URL when possible.
	 *
	 * @param RenewalRequest $request Request.
	 */
	public function proof_url( RenewalRequest $request ): string {
		return $this->media_url( $request->proof_of_payment() );
	}

	/**
	 * Resolve member from renewal submission.
	 *
	 * @param array<int|string, mixed> $field_data Submitted field data.
	 */
	private function member_from_submission( array $field_data ): ?Member {
		$email = sanitize_email( $this->field_value_by_candidates( $field_data, array( 'email', 'email-1', 'user_email' ) ) );

		if ( is_email( $email ) ) {
			$user = get_user_by( 'email', $email );

			if ( $user instanceof \WP_User ) {
				return $this->members->find( (int) $user->ID );
			}
		}

		return is_user_logged_in() ? $this->members->find( get_current_user_id() ) : null;
	}

	/**
	 * Extract submitted member profile data.
	 *
	 * @param array<int|string, mixed> $field_data Submitted field data.
	 * @return array<string, string>
	 */
	private function submitted_profile_data( array $field_data ): array {
		$map = array(
			'telefone'             => array( 'telefone', 'phone', 'phone-1' ),
			'morada'               => array( 'morada', 'address', 'address-1' ),
			'codigo_postal'        => array( 'codigo_postal', 'postcode', 'zip', 'text-1' ),
			'cidade'               => array( 'cidade', 'city', 'text-2' ),
			'contacto_emergencia'  => array( 'contacto_emergencia', 'emergency_contact', 'text-3' ),
			'equipa'               => array( 'equipa', 'team', 'text-4' ),
			'email'                => array( 'email', 'email-1', 'user_email' ),
		);
		$data = array();

		foreach ( $map as $member_field => $candidates ) {
			$value = sanitize_text_field( $this->field_value_by_candidates( $field_data, $candidates ) );

			if ( '' !== $value ) {
				$data[ $member_field ] = $value;
			}
		}

		return $data;
	}

	/**
	 * Extract proof of payment reference.
	 *
	 * @param array<int|string, mixed> $field_data Submitted field data.
	 * @return mixed
	 */
	private function proof_of_payment( array $field_data ): mixed {
		foreach ( $field_data as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = strtolower( (string) ( $field['name'] ?? '' ) );

			if ( str_contains( $name, 'comprovativo' ) || str_contains( $name, 'receipt' ) || str_contains( $name, 'proof' ) || str_contains( $name, 'upload' ) ) {
				return $field['value'] ?? '';
			}
		}

		return '';
	}

	/**
	 * Find a field value by candidate field names.
	 *
	 * @param array<int|string, mixed> $field_data Submitted field data.
	 * @param array<int, string>       $candidates Candidate names.
	 */
	private function field_value_by_candidates( array $field_data, array $candidates ): string {
		foreach ( $field_data as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = (string) ( $field['name'] ?? '' );

			if ( in_array( $name, $candidates, true ) ) {
				return $this->scalar( $field['value'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Calculate the next quota expiry date.
	 *
	 * @param Member $member Member.
	 */
	private function next_expiry_date( Member $member ): string {
		$base = max( current_time( 'timestamp' ), $member->quota_expiry_timestamp() );

		return wp_date( 'Y-m-d', strtotime( '+1 year', $base ) );
	}

	/**
	 * Update member email.
	 *
	 * @param Member $member Member.
	 * @param string $email  New email.
	 * @return true|WP_Error
	 */
	private function update_member_email( Member $member, string $email ): true|WP_Error {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'adam_membership_invalid_email', __( 'O endereço de email submetido é inválido.', 'adam-membership' ) );
		}

		$existing = email_exists( $email );

		if ( false !== $existing && (int) $existing !== $member->user_id() ) {
			return new WP_Error( 'adam_membership_email_exists', __( 'O endereço de email submetido já está a ser utilizado por outro utilizador.', 'adam-membership' ) );
		}

		$result = wp_update_user(
			array(
				'ID'         => $member->user_id(),
				'user_email' => $email,
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Normalize a scalar value.
	 *
	 * @param mixed $value Value.
	 */
	private function scalar( mixed $value ): string {
		if ( is_array( $value ) ) {
			return sanitize_text_field( implode( ' ', array_filter( array_map( 'strval', $value ) ) ) );
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Build a media URL from a stored reference.
	 *
	 * @param mixed $value Media value.
	 */
	private function media_url( mixed $value ): string {
		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_url( absint( $value ) );

			return false !== $url ? $url : '';
		}

		if ( is_string( $value ) && wp_http_validate_url( $value ) ) {
			return $value;
		}

		if ( is_array( $value ) ) {
			foreach ( array( 'url', 'file_url', 'source_url' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && wp_http_validate_url( $value[ $key ] ) ) {
					return $value[ $key ];
				}
			}
		}

		return '';
	}

	/**
	 * Write an audit log entry.
	 *
	 * @param string               $message Message.
	 * @param Member               $member  Member.
	 * @param array<string, mixed> $context Context.
	 */
	private function audit( string $message, Member $member, array $context = array() ): void {
		$this->logger->info(
			$message,
			array_merge(
				$context,
				array(
					'member'      => $member->user_id(),
					'admin'       => get_current_user_id(),
					'timestamp'   => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
				)
			)
		);
	}
}
