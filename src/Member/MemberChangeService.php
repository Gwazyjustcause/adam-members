<?php
/** Member profile change workflow. */
declare(strict_types=1);

namespace AdamMembership\Member;

use WP_Error;

final class MemberChangeService {
	public function __construct(
		private MemberChangeRepository $repository,
		private MemberRepository $members
	) {}

	public function repository(): MemberChangeRepository {
		return $this->repository;
	}

	/** @param array<string,mixed> $submitted */
	public function submit( Member $member, array $submitted ): MemberChangeRequest|WP_Error {
		if ( $this->repository->pending_for_user( $member->user_id() ) ) {
			return new WP_Error( 'adam_member_change_pending', __( 'Já existe um pedido de alteração pendente.', 'adam-membership' ) );
		}

		$protected = array(
			'estado', 'numero_socio', 'data_adesao', 'validade_quota',
			'adam_membership_origin', 'adam_membership_fee', 'adam_apd_management_status',
			'adam_apd_ana_confirmation_date', 'adam_founder_status', 'adam_founder_number',
			'adam_active_title_reward', 'adam_active_card_theme', 'adam_active_card_frame',
			'payment_receipt',
		);
		$changes = array();
		foreach ( $submitted as $field => $value ) {
			$field = sanitize_key( (string) $field );
			if ( '' === $field || in_array( $field, $protected, true ) ) {
				continue;
			}
			$old = 'email' === $field ? $member->email() : $member->field( $field );
			$new = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
			if ( (string) $old !== (string) $new ) {
				$changes[ $field ] = array( 'old' => $old, 'new' => $new );
			}
		}

		if ( array() === $changes ) {
			return new WP_Error( 'adam_member_change_empty', __( 'Não foram encontradas alterações.', 'adam-membership' ) );
		}
		return $this->repository->create( $member->user_id(), $changes );
	}

	public function approve( int $id ): true|WP_Error {
		$request = $this->repository->find( $id );
		if ( null === $request || MemberChangeRequest::STATUS_PENDING !== $request->status() ) {
			return new WP_Error( 'adam_member_change_not_pending', __( 'Pedido de alteração inválido.', 'adam-membership' ) );
		}
		$member = $this->members->find( $request->user_id() );
		if ( null === $member ) {
			return new WP_Error( 'adam_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) );
		}
		$patch = array();
		foreach ( $request->changes() as $field => $change ) {
			$new = $change['new'] ?? '';
			if ( 'email' === $field ) {
				$disable_email = static fn(): bool => false;
				add_filter( 'send_email_change_email', $disable_email );
				$result = wp_update_user( array( 'ID' => $member->user_id(), 'user_email' => sanitize_email( (string) $new ) ) );
				remove_filter( 'send_email_change_email', $disable_email );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				continue;
			}
			$patch[ $field ] = $new;
		}
		if ( array() !== $patch ) {
			$member->save( $patch );
		}
		$this->repository->update( $request, array( 'status' => MemberChangeRequest::STATUS_APPROVED, 'reviewed_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'reviewed_by' => get_current_user_id() ) );
		return true;
	}

	public function reject( int $id, string $reason = '', string $note = '' ): true|WP_Error {
		$request = $this->repository->find( $id );
		if ( null === $request || MemberChangeRequest::STATUS_PENDING !== $request->status() ) {
			return new WP_Error( 'adam_member_change_not_pending', __( 'Pedido de alteração inválido.', 'adam-membership' ) );
		}
		if ( '' === trim( $reason ) ) { return new WP_Error( 'adam_member_change_rejection_reason', __( 'Indique o motivo da rejeição.', 'adam-membership' ) ); }
		$this->repository->update( $request, array( 'status' => MemberChangeRequest::STATUS_REJECTED, 'rejection_reason' => sanitize_text_field( $reason ), 'rejection_note' => sanitize_textarea_field( $note ), 'reviewed_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'reviewed_by' => get_current_user_id() ) );
		$member = $this->members->find( $request->user_id() );
		if ( null !== $member && is_email( $member->email() ) ) {
			wp_mail( $member->email(), 'Pedido de alteração de dados não aceite', "O seu pedido de alteração de dados não foi aceite.\n\nMotivo: " . $reason . ( '' !== trim( $note ) ? "\n\nObservações: " . $note : '' ) );
		}
		return true;
	}
}
