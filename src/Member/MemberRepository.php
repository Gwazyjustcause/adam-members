<?php
/**
 * Member repository.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use WP_User_Query;

/**
 * Loads member models from WordPress users.
 */
final class MemberRepository {
	/**
	 * Find a member by WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function find( int $user_id ): ?Member {
		return Member::load( $user_id );
	}

	/**
	 * Get pending members.
	 *
	 * @return array<int, Member>
	 */
	public function pending_members(): array {
		return $this->query_members(
			array(
				'meta_key'   => 'estado',
				'meta_value' => Member::STATUS_PENDING,
			)
		);
	}

	/**
	 * Get all members with membership status metadata.
	 *
	 * @return array<int, Member>
	 */
	public function all_members(): array {
		return $this->query_members(
			array(
				'meta_query' => array(
					array(
						'key'     => 'estado',
						'compare' => 'EXISTS',
					),
				),
			)
		);
	}

	/**
	 * Get all founding members ordered by founder number.
	 *
	 * @return array<int, Member>
	 */
	public function founding_members(): array {
		$members = array_values(
			array_filter(
				$this->all_members(),
				static fn ( Member $member ): bool => $member->is_founder()
			)
		);

		usort(
			$members,
			static function ( Member $left, Member $right ): int {
				$left_number  = $left->founder_number();
				$right_number = $right->founder_number();

				if ( $left_number === $right_number ) {
					return $left->registration_timestamp() <=> $right->registration_timestamp();
				}

				if ( 0 === $left_number ) {
					return 1;
				}

				if ( 0 === $right_number ) {
					return -1;
				}

				return $left_number <=> $right_number;
			}
		);

		return $members;
	}

	/**
	 * Search, filter, and sort members for admin screens.
	 *
	 * @param array{search?:string,status?:string,quota_status?:string,orderby?:string,order?:string} $filters Filters.
	 * @return array<int, Member>
	 */
	public function admin_members( array $filters = array() ): array {
		$members = $this->all_members();
		$search  = isset( $filters['search'] ) ? strtolower( trim( (string) $filters['search'] ) ) : '';
		$status  = isset( $filters['status'] ) ? trim( (string) $filters['status'] ) : '';
		$quota   = isset( $filters['quota_status'] ) ? trim( (string) $filters['quota_status'] ) : '';

		$members = array_values(
			array_filter(
				$members,
				static function ( Member $member ) use ( $search, $status, $quota ): bool {
					if ( '' !== $status && $member->effective_status() !== $status ) {
						return false;
					}

					if ( '' !== $quota && $member->quota_status() !== $quota ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						implode(
							' ',
							array(
								$member->full_name(),
								$member->email(),
								(string) $member->field( 'numero_socio' ),
							)
						)
					);

					return str_contains( $haystack, $search );
				}
			)
		);

		$orderby = isset( $filters['orderby'] ) ? sanitize_key( (string) $filters['orderby'] ) : 'registered';
		$order   = isset( $filters['order'] ) && 'asc' === strtolower( (string) $filters['order'] ) ? 'asc' : 'desc';

		usort(
			$members,
			static function ( Member $a, Member $b ) use ( $orderby, $order ): int {
				$a_value = self::sort_value( $a, $orderby );
				$b_value = self::sort_value( $b, $orderby );
				$result  = $a_value <=> $b_value;

				return 'asc' === $order ? $result : -$result;
			}
		);

		return $members;
	}

	/**
	 * Count members by dashboard category.
	 *
	 * @return array<string, int>
	 */
	public function dashboard_counts(): array {
		$counts = array(
			'total'        => 0,
			'active'       => 0,
			'pending'      => 0,
			'renewal_pending' => 0,
			'rejected'     => 0,
			'expired'      => 0,
			'expiring_soon' => 0,
		);

		foreach ( $this->all_members() as $member ) {
			++$counts['total'];

			if ( Member::STATUS_ACTIVE === $member->effective_status() ) {
				++$counts['active'];
			} elseif ( Member::STATUS_PENDING === $member->effective_status() ) {
				++$counts['pending'];
			} elseif ( Member::STATUS_REJECTED === $member->effective_status() ) {
				++$counts['rejected'];
			} elseif ( Member::STATUS_EXPIRED === $member->effective_status() ) {
				++$counts['expired'];
			} elseif ( Member::STATUS_RENEWAL_PENDING === $member->effective_status() ) {
				++$counts['renewal_pending'];
			}

			if ( Member::STATUS_ACTIVE === $member->effective_status() && Member::QUOTA_EXPIRING_SOON === $member->quota_status() ) {
				++$counts['expiring_soon'];
			}
		}

		return $counts;
	}

	/**
	 * Determine whether a member number is already assigned to another member.
	 *
	 * @param string $member_number  Member number.
	 * @param int    $exclude_user_id User ID to exclude from the check.
	 */
	public function member_number_exists( string $member_number, int $exclude_user_id = 0 ): bool {
		$member_number = trim( $member_number );
		$numeric_value = Member::member_number_numeric_value( $member_number );

		if ( '' === $member_number ) {
			return false;
		}

		foreach ( $this->all_members() as $member ) {
			if ( $member->user_id() === $exclude_user_id ) {
				continue;
			}

			$existing = trim( (string) $member->field( 'numero_socio' ) );

			if ( '' === $existing ) {
				continue;
			}

			if ( strtolower( $existing ) === strtolower( $member_number ) ) {
				return true;
			}

			if ( 0 !== $numeric_value && $member->member_number_value() === $numeric_value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine whether a founder number is already assigned to another member.
	 *
	 * @param int $founder_number   Founder number.
	 * @param int $exclude_user_id  User ID to exclude from the check.
	 */
	public function founder_number_exists( int $founder_number, int $exclude_user_id = 0 ): bool {
		if ( $founder_number <= 0 ) {
			return false;
		}

		foreach ( $this->founding_members() as $member ) {
			if ( $member->user_id() === $exclude_user_id ) {
				continue;
			}

			if ( $member->founder_number() === $founder_number ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Query members.
	 *
	 * @param array<string, mixed> $args User query arguments.
	 * @return array<int, Member>
	 */
	private function query_members( array $args ): array {
		$query = new WP_User_Query(
			array_merge(
				array(
					'fields'  => 'ID',
					'orderby' => 'registered',
					'order'   => 'DESC',
				),
				$args
			)
		);

		$members = array();

		foreach ( $query->get_results() as $user_id ) {
			$member = Member::load( absint( $user_id ) );

			if ( null !== $member ) {
				$members[] = $member;
			}
		}

		return $members;
	}

	/**
	 * Get sortable value for a member.
	 *
	 * @param Member $member  Member.
	 * @param string $orderby Sort key.
	 */
	private static function sort_value( Member $member, string $orderby ): string|int {
		return match ( $orderby ) {
			'name'          => strtolower( $member->full_name() ),
			'email'         => strtolower( $member->email() ),
			'status'        => $member->effective_status(),
			'member_number' => $member->member_number_value(),
			'quota'         => $member->quota_expiry_timestamp(),
			default         => $member->registration_timestamp(),
		};
	}
}
