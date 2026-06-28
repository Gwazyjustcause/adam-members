<?php
/**
 * Announcement repository.
 *
 * @package AdamMembership\Announcement
 */

declare(strict_types=1);

namespace AdamMembership\Announcement;

/**
 * Stores and retrieves announcements.
 */
final class AnnouncementRepository {
	private const OPTION_ITEMS   = 'adam_membership_announcements';
	private const OPTION_NEXT_ID = 'adam_membership_announcement_next_id';

	/**
	 * Create an announcement.
	 *
	 * @param array<string, mixed> $data Announcement data.
	 */
	public function create( array $data ): Announcement {
		$id            = absint( get_option( self::OPTION_NEXT_ID, 1 ) );
		$announcements = $this->raw_items();
		$data['id']    = $id;

		$announcements[ $id ] = $data;

		update_option( self::OPTION_ITEMS, $announcements, false );
		update_option( self::OPTION_NEXT_ID, $id + 1, false );

		return new Announcement( $data );
	}

	/**
	 * Update an announcement.
	 *
	 * @param Announcement         $announcement Existing announcement.
	 * @param array<string, mixed> $data         Updated data.
	 */
	public function update( Announcement $announcement, array $data ): Announcement {
		$announcements = $this->raw_items();
		$current       = $announcement->data();
		$updated       = array_merge( $current, $data );

		$announcements[ $announcement->id() ] = $updated;

		update_option( self::OPTION_ITEMS, $announcements, false );

		return new Announcement( $updated );
	}

	/**
	 * Delete an announcement.
	 *
	 * @param int $announcement_id Announcement ID.
	 */
	public function delete( int $announcement_id ): void {
		$announcements = $this->raw_items();
		unset( $announcements[ $announcement_id ] );
		update_option( self::OPTION_ITEMS, $announcements, false );
	}

	/**
	 * Find one announcement.
	 *
	 * @param int $announcement_id Announcement ID.
	 */
	public function find( int $announcement_id ): ?Announcement {
		$announcements = $this->raw_items();

		if ( ! isset( $announcements[ $announcement_id ] ) || ! is_array( $announcements[ $announcement_id ] ) ) {
			return null;
		}

		return new Announcement( $announcements[ $announcement_id ] );
	}

	/**
	 * Query announcements.
	 *
	 * @param array<string, mixed> $filters Query filters.
	 * @return array<int, Announcement>
	 */
	public function query( array $filters = array() ): array {
		$search = isset( $filters['search'] ) ? strtolower( sanitize_text_field( (string) $filters['search'] ) ) : '';
		$status = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';

		$announcements = array_map(
			static fn ( array $item ): Announcement => new Announcement( $item ),
			array_values( $this->raw_items() )
		);

		$announcements = array_values(
			array_filter(
				$announcements,
				static function ( Announcement $announcement ) use ( $search, $status ): bool {
					if ( '' !== $status && $announcement->effective_status() !== $status && $announcement->status() !== $status ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						implode(
							' ',
							array(
								$announcement->title(),
								$announcement->summary(),
								$announcement->category(),
								$announcement->target_audience(),
							)
						)
					);

					return str_contains( $haystack, $search );
				}
			)
		);

		usort(
			$announcements,
			static function ( Announcement $left, Announcement $right ): int {
				$left_time  = strtotime( $left->publish_date() . ' 00:00:00' ) ?: 0;
				$right_time = strtotime( $right->publish_date() . ' 00:00:00' ) ?: 0;

				if ( $left->pinned() !== $right->pinned() ) {
					return $left->pinned() ? -1 : 1;
				}

				if ( $left->priority() !== $right->priority() ) {
					return self::priority_weight( $right->priority() ) <=> self::priority_weight( $left->priority() );
				}

				return $right_time <=> $left_time;
			}
		);

		return $announcements;
	}

	/**
	 * Get raw stored items.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function raw_items(): array {
		$items = get_option( self::OPTION_ITEMS, array() );

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Get a comparable priority weight.
	 *
	 * @param string $priority Priority.
	 */
	private static function priority_weight( string $priority ): int {
		return match ( $priority ) {
			Announcement::PRIORITY_URGENT    => 3,
			Announcement::PRIORITY_IMPORTANT => 2,
			default                          => 1,
		};
	}
}
