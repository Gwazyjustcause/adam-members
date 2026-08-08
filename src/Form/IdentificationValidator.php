<?php
declare(strict_types=1);

namespace AdamMembership\Form;

use WP_Error;

/** Canonical Portuguese BI/Cartão de Cidadão value normalisation and validation. */
final class IdentificationValidator {
	public static function normalize( mixed $value ): string {
		$value = strtoupper( trim( (string) $value ) );
		$value = preg_replace( '/\s+/', ' ', $value ) ?: '';
		if ( preg_match( '/^(\d{8})\s?(\d)\s?([A-Z]{2})\s?(\d)$/', $value, $matches ) ) {
			return $matches[1] . ' ' . $matches[2] . ' ' . $matches[3] . $matches[4];
		}
		return $value;
	}

	public static function is_valid( mixed $value ): bool {
		return 1 === preg_match( '/^\d{8} \d [A-Z]{2}\d$/', self::normalize( $value ) );
	}

	public static function validate( mixed $value, bool $required = true ): true|WP_Error {
		$normalized = self::normalize( $value );
		if ( '' === $normalized && ! $required ) { return true; }
		if ( ! self::is_valid( $normalized ) ) {
			return new WP_Error( 'adam_invalid_citizen_card', 'Introduza o número completo do Cartão de Cidadão, incluindo os 4 caracteres finais.' );
		}
		return true;
	}
}
