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
			return new WP_Error( 'adam_member_change_pending', __( "J\u{00E1} existe um pedido de altera\u{00E7}\u{00E3}o pendente.", 'adam-membership' ) );
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
			return new WP_Error( 'adam_member_change_empty', __( "N\u{00E3}o foram encontradas altera\u{00E7}\u{00F5}es.", 'adam-membership' ) );
		}
		return $this->repository->create( $member->user_id(), $changes );
	}

	public function approve( int $id ): true|WP_Error {
		$request = $this->repository->find( $id );
		if ( null === $request || MemberChangeRequest::STATUS_PENDING !== $request->status() ) {
			return new WP_Error( 'adam_member_change_not_pending', __( "Pedido de altera\u{00E7}\u{00E3}o inv\u{00E1}lido.", 'adam-membership' ) );
		}
		$member = $this->members->find( $request->user_id() );
		if ( null === $member ) {
			return new WP_Error( 'adam_member_not_found', __( "S\u{00F3}cio n\u{00E3}o encontrado.", 'adam-membership' ) );
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

	public function reject( int $id ): true|WP_Error {
		$request = $this->repository->find( $id );
		if ( null === $request || MemberChangeRequest::STATUS_PENDING !== $request->status() ) {
			return new WP_Error( 'adam_member_change_not_pending', __( "Pedido de altera\u{00E7}\u{00E3}o inv\u{00E1}lido.", 'adam-membership' ) );
		}
		$this->repository->update( $request, array( 'status' => MemberChangeRequest::STATUS_REJECTED, 'reviewed_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'reviewed_by' => get_current_user_id() ) );
		return true;
	}
}
