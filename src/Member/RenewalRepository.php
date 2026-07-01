<?php
/**
 * Renewal request repository.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

/**
 * Stores renewal requests in WordPress options.
 */
final class RenewalRepository {
	private const OPTION_REQUESTS = 'adam_membership_renewal_requests';
	private const OPTION_NEXT_ID  = 'adam_membership_renewal_next_id';

	/**
	 * Create a renewal request.
	 *
	 * @param array<string, mixed> $data Request data.
	 */
	public function create( array $data ): RenewalRequest {
		$id       = absint( get_option( self::OPTION_NEXT_ID, 1 ) );
		$requests = $this->raw_requests();

		$data['id']     = $id;
		$data['status'] = RenewalRequest::STATUS_PENDING;

		$requests[ $id ] = $data;

		update_option( self::OPTION_REQUESTS, $requests, false );
		update_option( self::OPTION_NEXT_ID, $id + 1, false );

		return new RenewalRequest( $data );
	}

	/**
	 * Find a request by ID.
	 *
	 * @param int $id Request ID.
	 */
	public function find( int $id ): ?RenewalRequest {
		$requests = $this->raw_requests();

		return isset( $requests[ $id ] ) && is_array( $requests[ $id ] ) ? new RenewalRequest( $requests[ $id ] ) : null;
	}

	/**
	 * Update a request.
	 *
	 * @param RenewalRequest       $request Request.
	 * @param array<string, mixed> $data    Data to merge.
	 */
	public function update( RenewalRequest $request, array $data ): RenewalRequest {
		$requests = $this->raw_requests();
		$updated  = array_merge( $request->data(), $data );

		$requests[ $request->id() ] = $updated;
		update_option( self::OPTION_REQUESTS, $requests, false );

		return new RenewalRequest( $updated );
	}

	/**
	 * Get admin requests.
	 *
	 * @param array{status?:string,order?:string} $filters Filters.
	 * @return array<int, RenewalRequest>
	 */
	public function admin_requests( array $filters = array() ): array {
		$status   = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		$order    = isset( $filters['order'] ) && 'asc' === strtolower( (string) $filters['order'] ) ? 'asc' : 'desc';
		$requests = array_map(
			static fn ( array $data ): RenewalRequest => new RenewalRequest( $data ),
			array_values( $this->raw_requests() )
		);

		$requests = array_values(
			array_filter(
				$requests,
				static fn ( RenewalRequest $request ): bool => '' === $status || $request->status() === $status
			)
		);

		usort(
			$requests,
			static function ( RenewalRequest $a, RenewalRequest $b ) use ( $order ): int {
				$result = strtotime( $a->submitted_at() ) <=> strtotime( $b->submitted_at() );

				return 'asc' === $order ? $result : -$result;
			}
		);

		return $requests;
	}

	/**
	 * Get all requests for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, RenewalRequest>
	 */
	public function for_user( int $user_id ): array {
		return array_values(
			array_filter(
				$this->admin_requests(),
				static fn ( RenewalRequest $request ): bool => $request->user_id() === $user_id
			)
		);
	}

	/**
	 * Get pending requests for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, RenewalRequest>
	 */
	public function pending_for_user( int $user_id ): array {
		return array_values(
			array_filter(
				$this->admin_requests( array( 'status' => RenewalRequest::STATUS_PENDING ) ),
				static fn ( RenewalRequest $request ): bool => $request->user_id() === $user_id
			)
		);
	}

	/**
	 * Get raw stored requests.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function raw_requests(): array {
		$requests = get_option( self::OPTION_REQUESTS, array() );

		return is_array( $requests ) ? $requests : array();
	}
}
