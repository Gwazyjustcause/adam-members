<?php
/**
 * Forminator submission value access.
 *
 * @package AdamMembership\Forminator
 */

declare(strict_types=1);

namespace AdamMembership\Forminator;

/**
 * Reads submitted Forminator values through logical field names.
 */
final class SubmissionData {
	/**
	 * Submitted Forminator field data.
	 *
	 * @var array<int|string, mixed>
	 */
	private array $field_data;

	/**
	 * Registration form configuration.
	 *
	 * @var RegistrationFormConfig
	 */
	private RegistrationFormConfig $config;

	/**
	 * Create the submission data reader.
	 *
	 * @param array<int|string, mixed> $field_data Submitted Forminator field data.
	 * @param RegistrationFormConfig  $config     Registration form configuration.
	 */
	public function __construct( array $field_data, RegistrationFormConfig $config ) {
		$this->field_data = $field_data;
		$this->config     = $config;
	}

	/**
	 * Get a submitted value by logical field name.
	 *
	 * @param string $logical_name Logical field name from the registration config.
	 * @return mixed
	 */
	public function get( string $logical_name ): mixed {
		$field_path = $this->config->field( $logical_name );

		if ( '' === $field_path ) {
			return null;
		}

		return $this->get_by_path( $field_path );
	}

	/**
	 * Get a sanitized string value by logical field name.
	 *
	 * @param string $logical_name Logical field name from the registration config.
	 */
	public function get_string( string $logical_name ): string {
		$value = $this->get( $logical_name );

		if ( is_array( $value ) ) {
			$value = implode( ' ', array_filter( array_map( 'strval', $value ) ) );
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Get a sanitized email value by logical field name.
	 *
	 * @param string $logical_name Logical field name from the registration config.
	 */
	public function get_email( string $logical_name ): string {
		$email = sanitize_email( $this->get_string( $logical_name ) );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Get raw field data by configured path.
	 *
	 * @param string $field_path Forminator field identifier, optionally with dot notation.
	 * @return mixed
	 */
	private function get_by_path( string $field_path ): mixed {
		$path_parts = explode( '.', $field_path );
		$field_name = array_shift( $path_parts );

		if ( null === $field_name || '' === $field_name ) {
			return null;
		}

		$value = $this->find_field_value( $field_name );

		foreach ( $path_parts as $path_part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $path_part, $value ) ) {
				return null;
			}

			$value = $value[ $path_part ];
		}

		return $value;
	}

	/**
	 * Find a Forminator field value by field identifier.
	 *
	 * @param string $field_name Forminator field identifier.
	 * @return mixed
	 */
	private function find_field_value( string $field_name ): mixed {
		if ( array_key_exists( $field_name, $this->field_data ) ) {
			return $this->field_data[ $field_name ];
		}

		foreach ( $this->field_data as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = isset( $field['name'] ) ? (string) $field['name'] : '';

			if ( $field_name === $name ) {
				return $field['value'] ?? null;
			}
		}

		return null;
	}
}
