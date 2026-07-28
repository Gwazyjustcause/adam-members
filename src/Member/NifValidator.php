<?php
/**
 * Portuguese NIF validation.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

/**
 * Normalizes and validates Portuguese tax identification numbers.
 */
final class NifValidator {
	/**
	 * Normalize a submitted NIF without accepting formatting characters.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function normalize( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Canonicalize legacy stored values for duplicate comparisons.
	 *
	 * New submissions still have to satisfy the strict nine-digit format.
	 *
	 * @param mixed $value Stored value.
	 */
	public static function canonicalize_stored( mixed $value ): string {
		return preg_replace( '/[\s.-]+/', '', self::normalize( $value ) ) ?? '';
	}

	/**
	 * Determine whether a NIF has a valid format, prefix, and checksum.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function is_valid( mixed $value ): bool {
		$nif = self::normalize( $value );

		if ( ! preg_match( '/^[1235689]\d{8}$/', $nif ) ) {
			return false;
		}

		$sum = 0;

		for ( $index = 0; $index < 8; ++$index ) {
			$sum += (int) $nif[ $index ] * ( 9 - $index );
		}

		$check_digit = 11 - ( $sum % 11 );

		if ( $check_digit >= 10 ) {
			$check_digit = 0;
		}

		return $check_digit === (int) $nif[8];
	}
}
