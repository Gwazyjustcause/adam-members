<?php
declare(strict_types=1);

namespace AdamMembership\Member;

final class ApdAssociationRepository {
	private const OPTION = 'adam_membership_apd_association_requests';
	private const NEXT_ID = 'adam_membership_apd_association_next_id';

	public function create( array $data ): ApdAssociationRequest {
		$id = max( 1, absint( get_option( self::NEXT_ID, 1 ) ) );
		$data['id'] = $id;
		$data['status'] = ApdAssociationRequest::STATUS_PENDING_PAYMENT;
		$rows = get_option( self::OPTION, array() );
		$rows = is_array( $rows ) ? $rows : array();
		$rows[ $id ] = $data;
		update_option( self::OPTION, $rows, false );
		update_option( self::NEXT_ID, $id + 1, false );
		return new ApdAssociationRequest( $data );
	}

	public function find( int $id ): ?ApdAssociationRequest {
		$rows = get_option( self::OPTION, array() );
		$data = is_array( $rows ) && isset( $rows[ $id ] ) && is_array( $rows[ $id ] ) ? $rows[ $id ] : null;
		return null === $data ? null : new ApdAssociationRequest( $data );
	}

	public function update( ApdAssociationRequest $request, array $changes ): ApdAssociationRequest {
		$data = array_merge( $request->data(), $changes );
		$rows = get_option( self::OPTION, array() );
		$rows = is_array( $rows ) ? $rows : array();
		$rows[ $request->id() ] = $data;
		update_option( self::OPTION, $rows, false );
		return new ApdAssociationRequest( $data );
	}

	public function save_sync_state( ApdAssociationRequest $request, array $state ): ApdAssociationRequest {
		return $this->update( $request, array( 'google_sheets_sync' => $state ) );
	}

	public function for_user( int $user_id ): array {
		$rows = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && absint( $row['user_id'] ?? 0 ) === $user_id ) { $out[] = new ApdAssociationRequest( $row ); }
		}
		usort( $out, static fn( ApdAssociationRequest $a, ApdAssociationRequest $b ): int => strcmp( $b->requested_at(), $a->requested_at() ) );
		return $out;
	}

	public function all(): array {
		$rows = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) { if ( is_array( $row ) ) { $out[] = new ApdAssociationRequest( $row ); } }
		usort( $out, static fn( ApdAssociationRequest $a, ApdAssociationRequest $b ): int => strcmp( $b->requested_at(), $a->requested_at() ) );
		return $out;
	}

	public function has_active( int $user_id ): bool {
		foreach ( $this->for_user( $user_id ) as $request ) {
			if ( ! in_array( $request->status(), array( ApdAssociationRequest::STATUS_CONFIRMED, ApdAssociationRequest::STATUS_REJECTED ), true ) ) { return true; }
		}
		return false;
	}

	public function reset_for_user( int $user_id ): void {
		$rows = get_option( self::OPTION, array() );
		if ( ! is_array( $rows ) ) { return; }
		foreach ( $rows as $id => $row ) {
			if ( is_array( $row ) && absint( $row['user_id'] ?? 0 ) === $user_id && ! in_array( (string) ( $row['status'] ?? '' ), array( ApdAssociationRequest::STATUS_CONFIRMED, ApdAssociationRequest::STATUS_REJECTED ), true ) ) {
				$rows[ $id ]['status'] = ApdAssociationRequest::STATUS_REJECTED;
			}
		}
		update_option( self::OPTION, $rows, false );
	}
}
