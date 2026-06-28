<?php
/**
 * Member history repository.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

/**
 * Stores and queries member history entries.
 */
final class HistoryRepository {
	private const OPTION_ENTRIES = 'adam_membership_history_entries';
	private const OPTION_NEXT_ID = 'adam_membership_history_next_id';

	/**
	 * Create a history entry.
	 *
	 * @param array<string, mixed> $data Entry data.
	 */
	public function create( array $data ): HistoryEntry {
		$id      = absint( get_option( self::OPTION_NEXT_ID, 1 ) );
		$entries = $this->raw_entries();

		$data['id'] = $id;
		$entries[ $id ] = $data;

		update_option( self::OPTION_ENTRIES, $entries, false );
		update_option( self::OPTION_NEXT_ID, $id + 1, false );

		return new HistoryEntry( $data );
	}

	/**
	 * Query history entries.
	 *
	 * @param array<string, mixed> $filters Query filters.
	 * @return array<int, HistoryEntry>
	 */
	public function query( array $filters = array() ): array {
		$entries = array_map(
			static fn ( array $data ): HistoryEntry => new HistoryEntry( $data ),
			array_values( $this->raw_entries() )
		);

		$search     = isset( $filters['search'] ) ? sanitize_text_field( (string) $filters['search'] ) : '';
		$action_key = isset( $filters['action_key'] ) ? sanitize_key( (string) $filters['action_key'] ) : '';
		$actor_type = isset( $filters['actor_type'] ) ? sanitize_key( (string) $filters['actor_type'] ) : '';
		$date_from  = isset( $filters['date_from'] ) ? sanitize_text_field( (string) $filters['date_from'] ) : '';
		$date_to    = isset( $filters['date_to'] ) ? sanitize_text_field( (string) $filters['date_to'] ) : '';
		$member_id  = isset( $filters['member_id'] ) ? absint( $filters['member_id'] ) : 0;
		$limit      = isset( $filters['limit'] ) ? absint( $filters['limit'] ) : 0;

		$entries = array_values(
			array_filter(
				$entries,
				function ( HistoryEntry $entry ) use ( $search, $action_key, $actor_type, $date_from, $date_to, $member_id ): bool {
					if ( '' !== $action_key && $entry->action_key() !== $action_key ) {
						return false;
					}

					if ( '' !== $actor_type && $entry->actor_type() !== $actor_type ) {
						return false;
					}

					if ( 0 !== $member_id && $entry->member_id() !== $member_id ) {
						return false;
					}

					if ( ! $this->matches_date_range( $entry, $date_from, $date_to ) ) {
						return false;
					}

					return $this->matches_search( $entry, $search );
				}
			)
		);

		usort(
			$entries,
			static function ( HistoryEntry $a, HistoryEntry $b ): int {
				return strtotime( $b->created_at() ) <=> strtotime( $a->created_at() );
			}
		);

		if ( 0 !== $limit ) {
			$entries = array_slice( $entries, 0, $limit );
		}

		return $entries;
	}

	/**
	 * Get timeline entries for one member.
	 *
	 * @param int $member_id Member ID.
	 * @param int $limit     Optional result limit.
	 * @return array<int, HistoryEntry>
	 */
	public function for_member( int $member_id, int $limit = 20 ): array {
		return $this->query(
			array(
				'member_id' => $member_id,
				'limit'     => $limit,
			)
		);
	}

	/**
	 * Get available action types from stored history.
	 *
	 * @return array<string, string>
	 */
	public function action_types(): array {
		$types = array();

		foreach ( $this->raw_entries() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$key   = sanitize_key( (string) ( $entry['action_key'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $entry['action_label'] ?? '' ) );

			if ( '' !== $key && '' !== $label ) {
				$types[ $key ] = $label;
			}
		}

		asort( $types );

		return $types;
	}

	/**
	 * Check whether an entry matches the search term.
	 *
	 * @param HistoryEntry $entry  History entry.
	 * @param string       $search Search term.
	 */
	private function matches_search( HistoryEntry $entry, string $search ): bool {
		if ( '' === $search ) {
			return true;
		}

		$haystacks = array(
			strtolower( $entry->member_name() ),
			strtolower( $entry->member_email() ),
			strtolower( $entry->member_number() ),
			strtolower( $entry->actor_name() ),
			strtolower( $entry->action_label() ),
			strtolower( $entry->description() ),
			(string) $entry->member_id(),
			(string) $entry->actor_id(),
		);

		$needle = strtolower( $search );

		foreach ( $haystacks as $haystack ) {
			if ( '' !== $haystack && str_contains( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an entry is inside the selected date range.
	 *
	 * @param HistoryEntry $entry     History entry.
	 * @param string       $date_from Start date in Y-m-d.
	 * @param string       $date_to   End date in Y-m-d.
	 */
	private function matches_date_range( HistoryEntry $entry, string $date_from, string $date_to ): bool {
		$timestamp = strtotime( $entry->created_at() );

		if ( false === $timestamp ) {
			return false;
		}

		if ( '' !== $date_from ) {
			$from = strtotime( $date_from . ' 00:00:00' );

			if ( false !== $from && $timestamp < $from ) {
				return false;
			}
		}

		if ( '' !== $date_to ) {
			$to = strtotime( $date_to . ' 23:59:59' );

			if ( false !== $to && $timestamp > $to ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get raw stored entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function raw_entries(): array {
		$entries = get_option( self::OPTION_ENTRIES, array() );

		return is_array( $entries ) ? $entries : array();
	}
}
