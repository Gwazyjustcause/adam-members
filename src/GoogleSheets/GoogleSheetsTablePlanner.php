<?php
/**
 * Plans safe writes within the Quotas worksheet.
 *
 * @package AdamMembership\GoogleSheets
 */

declare(strict_types=1);

namespace AdamMembership\GoogleSheets;

/**
 * Treats rows as occupied from returned values, not from visual formatting.
 */
final class GoogleSheetsTablePlanner {
	/** Model the inclusive A4:L24-style table range after one table-row append. */
	public static function expanded_range( string $range ): string {
		if ( 1 !== preg_match( '/^(A\d+:L)(\d+)$/', $range, $matches ) ) {
			return $range;
		}
		return $matches[1] . ( (int) $matches[2] + 1 );
	}

	/** Compare the stored A:L row with the expected canonical row. */
	public static function rows_match( array $stored, array $expected ): bool {
		$stored = array_slice( array_pad( $stored, 12, '' ), 0, 12 );
		$expected = array_slice( array_pad( $expected, 12, '' ), 0, 12 );
		foreach ( array( 6, 7 ) as $index ) {
			$stored[ $index ] = self::normalize_numeric_or_date( $index, $stored[ $index ] );
			$expected[ $index ] = self::normalize_numeric_or_date( $index, $expected[ $index ] );
		}
		return array_map( 'strval', $stored ) === array_map( 'strval', $expected );
	}

	private static function normalize_numeric_or_date( int $index, mixed $value ): string|float {
		if ( 6 === $index ) {
			$normalized = str_replace( array( '€', ' ' ), array( '', '' ), (string) $value );
			if ( str_contains( $normalized, ',' ) ) {
				$normalized = str_replace( '.', '', $normalized );
				$normalized = str_replace( ',', '.', $normalized );
			}
			return is_numeric( $normalized ) ? (float) $normalized : (string) $value;
		}
		if ( 7 === $index ) {
			if ( is_numeric( $value ) ) {
				$timestamp = (int) round( ( (float) $value - 25569 ) * 86400 );
				return gmdate( 'Y-m-d', $timestamp );
			}
			$date = \DateTimeImmutable::createFromFormat( '!d/m/Y', (string) $value );
			return false !== $date ? $date->format( 'Y-m-d' ) : (string) $value;
		}
		return (string) $value;
	}

	/**
	 * @param array<int, mixed> $values Rows returned from A5:L.
	 * @return array{target_row:int,requires_insert:bool,duplicate_row:int,duplicate_values:array<int,mixed>}
	 */
	public static function plan( array $values, string $request_id ): array {
		$target = 0;
		$last_occupied = 24;
		$limit = max( 19, count( $values ) - 1 );
		for ( $index = 0; $index <= $limit; $index++ ) {
			$raw_row = $values[ $index ] ?? array();
			$row_number = 5 + (int) $index;
			$row = array_pad( array_values( (array) $raw_row ), 12, '' );
			$occupied = (bool) array_filter( $row, static fn ( mixed $value ): bool => '' !== trim( (string) $value ) );
			if ( $occupied ) {
				$last_occupied = max( $last_occupied, $row_number );
			}
			if ( (string) $row[10] === $request_id ) {
				return array( 'target_row' => $row_number, 'requires_insert' => false, 'duplicate_row' => $row_number, 'duplicate_values' => $row );
			}
			if ( 0 === $target && $row_number <= 24 && ! $occupied ) {
				$target = $row_number;
			}
		}
		if ( 0 === $target ) {
			$target = $last_occupied + 1;
		}
		return array( 'target_row' => $target, 'requires_insert' => $target > 24, 'duplicate_row' => 0, 'duplicate_values' => array() );
	}
}
