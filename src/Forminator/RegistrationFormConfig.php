<?php
/**
 * Forminator registration form configuration.
 *
 * @package AdamMembership\Forminator
 */

declare(strict_types=1);

namespace AdamMembership\Forminator;

/**
 * Stores Forminator form and field mapping for member registrations.
 */
final class RegistrationFormConfig {
	/**
	 * ADAM membership registration Forminator form ID.
	 */
	private const FORM_ID = 178;

	/**
	 * Logical registration fields mapped to Forminator field identifiers.
	 *
	 * Nested values may use dot notation when Forminator stores grouped field data.
	 */
	private const FIELD_MAP = array(
		'email'           => 'email-1',
		'first_name'      => 'name-1.first-name',
		'last_name'       => 'name-1.last-name',
		'phone'           => 'phone-1',
		'nif'             => 'text-1',
		'citizen_card'    => 'text-2',
		'birth_date'      => 'date-1',
		'address'         => 'address-1',
		'team'            => 'text-3',
		'profile_photo'   => 'upload-1',
		'payment_receipt' => 'upload-2',
	);

	/**
	 * Get the Forminator form ID used for member registration.
	 */
	public function form_id(): int {
		return self::FORM_ID;
	}

	/**
	 * Get the Forminator field path for a logical field name.
	 *
	 * @param string $logical_name Logical field name.
	 */
	public function field( string $logical_name ): string {
		return self::FIELD_MAP[ $logical_name ] ?? '';
	}

	/**
	 * Get all configured field mappings.
	 *
	 * @return array<string, string>
	 */
	public function fields(): array {
		return self::FIELD_MAP;
	}
}
