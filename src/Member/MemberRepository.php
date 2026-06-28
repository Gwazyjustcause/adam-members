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
					if ( '' !== $status && $member->status() !== $status ) {
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
			'rejected'     => 0,
			'expired'      => 0,
			'expiring_soon' => 0,
		);

		foreach ( $this->all_members() as $member ) {
			++$counts['total'];

			if ( $member->isActive() ) {
				++$counts['active'];
			} elseif ( $member->isPending() ) {
				++$counts['pending'];
			} elseif ( $member->isRejected() ) {
				++$counts['rejected'];
			}

			if ( $member->isActive() && Member::QUOTA_EXPIRED === $member->quota_status() ) {
				++$counts['expired'];
			}

			if ( $member->isActive() && Member::QUOTA_EXPIRING_SOON === $member->quota_status() ) {
				++$counts['expiring_soon'];
			}
		}

		return $counts;
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
			'status'        => $member->status(),
			'member_number' => (string) $member->field( 'numero_socio' ),
			'quota'         => $member->quota_expiry_timestamp(),
			default         => $member->registration_timestamp(),
		};
	}
}
