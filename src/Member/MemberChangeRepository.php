<?php
declare(strict_types=1);

namespace AdamMembership\Member;

final class MemberChangeRepository {
	private const OPTION = 'adam_membership_member_change_requests';
	private const NEXT_ID = 'adam_membership_member_change_next_id';

	/** @param array<string,mixed> $changes */
	public function create( int $user_id, array $changes ): MemberChangeRequest {
		$id = max( 1, absint( get_option( self::NEXT_ID, 1 ) ) );
		$rows = get_option( self::OPTION, array() );
		$rows = is_array( $rows ) ? $rows : array();
		$data = array( 'id' => $id, 'user_id' => $user_id, 'submitted_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'status' => MemberChangeRequest::STATUS_PENDING, 'changes' => $changes );
		$rows[ $id ] = $data;
		update_option( self::OPTION, $rows, false );
		update_option( self::NEXT_ID, $id + 1, false );
		return new MemberChangeRequest( $data );
	}

	public function find( int $id ): ?MemberChangeRequest {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) && isset( $rows[ $id ] ) && is_array( $rows[ $id ] ) ? new MemberChangeRequest( $rows[ $id ] ) : null;
	}

	/** @param array<string,mixed> $changes */
	public function update( MemberChangeRequest $request, array $changes ): MemberChangeRequest {
		$rows = get_option( self::OPTION, array() );
		$rows = is_array( $rows ) ? $rows : array();
		$data = array_merge( $request->data(), $changes );
		$rows[ $request->id() ] = $data;
		update_option( self::OPTION, $rows, false );
		return new MemberChangeRequest( $data );
	}

	/** @return array<int,MemberChangeRequest> */
	public function all( string $status = '' ): array {
		$rows = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && ( '' === $status || (string) ( $row['status'] ?? '' ) === $status ) ) {
				$out[] = new MemberChangeRequest( $row );
			}
		}
		usort( $out, static fn( MemberChangeRequest $a, MemberChangeRequest $b ): int => strcmp( $b->submitted_at(), $a->submitted_at() ) );
		return $out;
	}

	public function pending_for_user( int $user_id ): bool {
		foreach ( $this->all( MemberChangeRequest::STATUS_PENDING ) as $request ) {
			if ( $request->user_id() === $user_id ) {
				return true;
			}
		}
		return false;
	}
}
