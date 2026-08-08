<?php
/**
 * Smoke tests for Portuguese NIF validation.
 *
 * @package AdamMembership\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/Member/NifValidator.php';

use AdamMembership\Member\NifValidator;

/**
 * Assert a NIF validation result.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @throws RuntimeException When the condition fails.
 */
function adam_nif_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only exception.
		throw new RuntimeException( $message );
	}
}

adam_nif_assert( NifValidator::is_valid( '501964843' ), 'A valid Portuguese NIF should pass.' );
adam_nif_assert( NifValidator::is_valid( '123456789' ), 'A checksum-valid individual NIF should pass.' );
adam_nif_assert( NifValidator::is_valid( '233128875' ), 'The reported NIF 233128875 should pass the local checksum.' );
adam_nif_assert( ! NifValidator::is_valid( '501964842' ), 'An invalid checksum should fail.' );
adam_nif_assert( ! NifValidator::is_valid( '000000000' ), 'An impossible NIF prefix should fail.' );
adam_nif_assert( ! NifValidator::is_valid( '501 964 843' ), 'Formatted values should not satisfy the nine-digit format.' );
adam_nif_assert( ! NifValidator::is_valid( '50196484' ), 'A short NIF should fail.' );
adam_nif_assert( '501964843' === NifValidator::canonicalize_stored( '501 964 843' ), 'Legacy formatting should be normalized only for duplicate comparisons.' );

echo "Portuguese NIF validation smoke tests passed.\n";
