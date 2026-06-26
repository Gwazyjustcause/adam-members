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
					'number'  => 200,
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
}
