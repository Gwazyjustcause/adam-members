<?php
/**
 * Reward repository.
 *
 * @package AdamMembership\Reward
 */

declare(strict_types=1);

namespace AdamMembership\Reward;

/**
 * Stores rewards and redemption requests.
 */
final class RewardRepository {
	private const OPTION_REWARDS            = 'adam_membership_rewards';
	private const OPTION_REWARD_NEXT_ID     = 'adam_membership_reward_next_id';
	private const OPTION_REDEMPTIONS        = 'adam_membership_reward_redemptions';
	private const OPTION_REDEMPTION_NEXT_ID = 'adam_membership_reward_redemption_next_id';

	/**
	 * @param array<string, mixed> $data Reward data.
	 */
	public function create_reward( array $data ): Reward {
		$id      = absint( get_option( self::OPTION_REWARD_NEXT_ID, 1 ) );
		$rewards = $this->raw_rewards();

		$data['id']   = $id;
		$rewards[ $id ] = $data;

		update_option( self::OPTION_REWARDS, $rewards, false );
		update_option( self::OPTION_REWARD_NEXT_ID, $id + 1, false );

		return new Reward( $data );
	}

	/**
	 * @param array<string, mixed> $data Updated reward data.
	 */
	public function update_reward( Reward $reward, array $data ): Reward {
		$rewards = $this->raw_rewards();
		$updated = array_merge( $reward->data(), $data );

		$rewards[ $reward->id() ] = $updated;
		update_option( self::OPTION_REWARDS, $rewards, false );

		return new Reward( $updated );
	}

	public function delete_reward( int $reward_id ): void {
		$rewards = $this->raw_rewards();
		unset( $rewards[ $reward_id ] );
		update_option( self::OPTION_REWARDS, $rewards, false );
	}

	public function find_reward( int $reward_id ): ?Reward {
		$rewards = $this->raw_rewards();

		if ( ! isset( $rewards[ $reward_id ] ) || ! is_array( $rewards[ $reward_id ] ) ) {
			return null;
		}

		return new Reward( $rewards[ $reward_id ] );
	}

	public function find_reward_by_value( string $reward_value ): ?Reward {
		$reward_value = sanitize_text_field( $reward_value );

		if ( '' === $reward_value ) {
			return null;
		}

		foreach ( $this->raw_rewards() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$reward = new Reward( $item );

			if ( $reward->reward_value() === $reward_value ) {
				return $reward;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, Reward>
	 */
	public function query_rewards( array $filters = array() ): array {
		$search   = isset( $filters['search'] ) ? strtolower( sanitize_text_field( (string) $filters['search'] ) ) : '';
		$category = isset( $filters['category'] ) ? sanitize_text_field( (string) $filters['category'] ) : '';
		$type     = isset( $filters['type'] ) ? sanitize_key( (string) $filters['type'] ) : '';
		$active   = array_key_exists( 'active', $filters ) ? (bool) $filters['active'] : null;

		$rewards = array_map(
			static fn ( array $item ): Reward => new Reward( $item ),
			array_values( $this->raw_rewards() )
		);

		$rewards = array_values(
			array_filter(
				$rewards,
				static function ( Reward $reward ) use ( $search, $category, $type, $active ): bool {
					if ( '' !== $category && $reward->category() !== $category ) {
						return false;
					}

					if ( '' !== $type && $reward->type() !== $type ) {
						return false;
					}

					if ( null !== $active && $reward->active() !== $active ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						implode(
							' ',
							array(
								$reward->name(),
								$reward->description(),
								$reward->category(),
								$reward->type(),
								$reward->availability_label(),
							)
						)
					);

					return str_contains( $haystack, $search );
				}
			)
		);

		usort(
			$rewards,
			static function ( Reward $left, Reward $right ): int {
				if ( $left->active() !== $right->active() ) {
					return $left->active() ? -1 : 1;
				}

				if ( $left->points_cost() !== $right->points_cost() ) {
					return $left->points_cost() <=> $right->points_cost();
				}

				return strcmp( $left->name(), $right->name() );
			}
		);

		return $rewards;
	}

	/**
	 * @param array<string, mixed> $data Redemption data.
	 */
	public function create_redemption( array $data ): RewardRedemption {
		$id          = absint( get_option( self::OPTION_REDEMPTION_NEXT_ID, 1 ) );
		$redemptions = $this->raw_redemptions();

		$data['id']         = $id;
		$redemptions[ $id ] = $data;

		update_option( self::OPTION_REDEMPTIONS, $redemptions, false );
		update_option( self::OPTION_REDEMPTION_NEXT_ID, $id + 1, false );

		return new RewardRedemption( $data );
	}

	/**
	 * @param array<string, mixed> $data Updated data.
	 */
	public function update_redemption( RewardRedemption $redemption, array $data ): RewardRedemption {
		$redemptions = $this->raw_redemptions();
		$updated     = array_merge( $redemption->data(), $data );

		$redemptions[ $redemption->id() ] = $updated;
		update_option( self::OPTION_REDEMPTIONS, $redemptions, false );

		return new RewardRedemption( $updated );
	}

	public function find_redemption( int $redemption_id ): ?RewardRedemption {
		$redemptions = $this->raw_redemptions();

		if ( ! isset( $redemptions[ $redemption_id ] ) || ! is_array( $redemptions[ $redemption_id ] ) ) {
			return null;
		}

		return new RewardRedemption( $redemptions[ $redemption_id ] );
	}

	/**
	 * Delete redemptions matching the provided filters.
	 *
	 * @param array{member_id?:int,reward_id?:int,status?:string} $filters Filters.
	 * @return int
	 */
	public function delete_redemptions( array $filters = array() ): int {
		$member_id   = isset( $filters['member_id'] ) ? absint( $filters['member_id'] ) : 0;
		$reward_id   = isset( $filters['reward_id'] ) ? absint( $filters['reward_id'] ) : 0;
		$status      = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		$redemptions = $this->raw_redemptions();
		$removed     = 0;

		foreach ( $redemptions as $id => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$redemption = new RewardRedemption( $item );

			if ( 0 !== $member_id && $redemption->member_id() !== $member_id ) {
				continue;
			}

			if ( 0 !== $reward_id && $redemption->reward_id() !== $reward_id ) {
				continue;
			}

			if ( '' !== $status && $redemption->status() !== $status ) {
				continue;
			}

			unset( $redemptions[ $id ] );
			++$removed;
		}

		if ( $removed > 0 ) {
			update_option( self::OPTION_REDEMPTIONS, $redemptions, false );
		}

		return $removed;
	}

	/**
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, RewardRedemption>
	 */
	public function query_redemptions( array $filters = array() ): array {
		$member_id = isset( $filters['member_id'] ) ? absint( $filters['member_id'] ) : 0;
		$reward_id = isset( $filters['reward_id'] ) ? absint( $filters['reward_id'] ) : 0;
		$status    = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		$search    = isset( $filters['search'] ) ? strtolower( sanitize_text_field( (string) $filters['search'] ) ) : '';
		$limit     = isset( $filters['limit'] ) ? absint( $filters['limit'] ) : 0;

		$redemptions = array_map(
			static fn ( array $item ): RewardRedemption => new RewardRedemption( $item ),
			array_values( $this->raw_redemptions() )
		);

		$redemptions = array_values(
			array_filter(
				$redemptions,
				static function ( RewardRedemption $redemption ) use ( $member_id, $reward_id, $status, $search ): bool {
					if ( 0 !== $member_id && $redemption->member_id() !== $member_id ) {
						return false;
					}

					if ( 0 !== $reward_id && $redemption->reward_id() !== $reward_id ) {
						return false;
					}

					if ( '' !== $status && $redemption->status() !== $status ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						implode(
							' ',
							array(
								$redemption->reward_name(),
								$redemption->reward_type(),
								$redemption->rejection_reason(),
								(string) $redemption->member_id(),
							)
						)
					);

					return str_contains( $haystack, $search );
				}
			)
		);

		usort(
			$redemptions,
			static fn ( RewardRedemption $left, RewardRedemption $right ): int => strcmp( $right->created_at(), $left->created_at() )
		);

		if ( $limit > 0 ) {
			$redemptions = array_slice( $redemptions, 0, $limit );
		}

		return $redemptions;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function raw_rewards(): array {
		$rewards = get_option( self::OPTION_REWARDS, array() );

		return is_array( $rewards ) ? $rewards : array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function raw_redemptions(): array {
		$redemptions = get_option( self::OPTION_REDEMPTIONS, array() );

		return is_array( $redemptions ) ? $redemptions : array();
	}
}
