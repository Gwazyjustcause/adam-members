<?php
/**
 * Points repository.
 *
 * @package AdamMembership\Points
 */

declare(strict_types=1);

namespace AdamMembership\Points;

/**
 * Stores and queries points ledger entries.
 */
final class PointsRepository {
	private const OPTION_ENTRIES = 'adam_membership_points_entries';
	private const OPTION_NEXT_ID = 'adam_membership_points_next_id';

	/**
	 * @param array<string, mixed> $data Entry data.
	 */
	public function create( array $data ): PointsEntry {
		$id      = absint( get_option( self::OPTION_NEXT_ID, 1 ) );
		$entries = $this->raw_entries();

		$data['id']      = $id;
		$entries[ $id ]  = $data;

		update_option( self::OPTION_ENTRIES, $entries, false );
		update_option( self::OPTION_NEXT_ID, $id + 1, false );

		return new PointsEntry( $data );
	}

	/**
	 * @param array<string, mixed> $filters Query filters.
	 * @return array<int, PointsEntry>
	 */
	public function query( array $filters = array() ): array {
		$entries = array_map(
			static fn ( array $entry ): PointsEntry => new PointsEntry( $entry ),
			array_values( $this->raw_entries() )
		);

		$member_id   = isset( $filters['member_id'] ) ? absint( $filters['member_id'] ) : 0;
		$source_type = isset( $filters['source_type'] ) ? sanitize_key( (string) $filters['source_type'] ) : '';
		$source_id   = isset( $filters['source_id'] ) ? absint( $filters['source_id'] ) : 0;
		$search      = isset( $filters['search'] ) ? strtolower( sanitize_text_field( (string) $filters['search'] ) ) : '';
		$limit       = isset( $filters['limit'] ) ? absint( $filters['limit'] ) : 0;

		$entries = array_values(
			array_filter(
				$entries,
				static function ( PointsEntry $entry ) use ( $member_id, $source_type, $source_id, $search ): bool {
					if ( 0 !== $member_id && $entry->member_id() !== $member_id ) {
						return false;
					}

					if ( '' !== $source_type && $entry->source_type() !== $source_type ) {
						return false;
					}

					if ( 0 !== $source_id && $entry->source_id() !== $source_id ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower(
						implode(
							' ',
							array(
								(string) $entry->member_id(),
								(string) $entry->source_id(),
								$entry->reason(),
								$entry->source_type(),
							)
						)
					);

					return str_contains( $haystack, $search );
				}
			)
		);

		usort(
			$entries,
			static fn ( PointsEntry $left, PointsEntry $right ): int => strtotime( $right->created_at() ) <=> strtotime( $left->created_at() )
		);

		if ( $limit > 0 ) {
			$entries = array_slice( $entries, 0, $limit );
		}

		return $entries;
	}

	public function find_by_source( int $member_id, string $source_type, int $source_id ): ?PointsEntry {
		foreach ( $this->raw_entries() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if (
				absint( $entry['member_id'] ?? 0 ) === $member_id &&
				sanitize_key( (string) ( $entry['source_type'] ?? '' ) ) === sanitize_key( $source_type ) &&
				absint( $entry['source_id'] ?? 0 ) === $source_id
			) {
				return new PointsEntry( $entry );
			}
		}

		return null;
	}

	public function first_by_source( string $source_type, int $source_id ): ?PointsEntry {
		foreach ( $this->raw_entries() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if (
				sanitize_key( (string) ( $entry['source_type'] ?? '' ) ) === sanitize_key( $source_type ) &&
				absint( $entry['source_id'] ?? 0 ) === $source_id
			) {
				return new PointsEntry( $entry );
			}
		}

		return null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function raw_entries(): array {
		$entries = get_option( self::OPTION_ENTRIES, array() );

		return is_array( $entries ) ? $entries : array();
	}
}
