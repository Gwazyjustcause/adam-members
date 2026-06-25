<?php
/**
 * Forminator user registration integration.
 *
 * @package AdamMembership\Forminator
 */

declare(strict_types=1);

namespace AdamMembership\Forminator;

use WP_Error;

/**
 * Creates WordPress users from approved Forminator submissions.
 */
final class UserRegistration {
	/**
	 * Forminator form ID used for ADAM member applications.
	 */
	private const FORM_ID = 178;

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_action( 'forminator_custom_form_after_save_entry', array( $this, 'handle_submission' ), 10, 3 );
	}

	/**
	 * Handle successful Forminator submissions.
	 *
	 * @param mixed $entry_id         Forminator entry ID.
	 * @param mixed $form_id          Forminator form ID.
	 * @param mixed $field_data_array Submitted field data.
	 */
	public function handle_submission( mixed $entry_id, mixed $form_id, mixed $field_data_array ): void {
		unset( $entry_id );

		if ( self::FORM_ID !== absint( $form_id ) ) {
			return;
		}

		if ( ! is_array( $field_data_array ) ) {
			error_log( 'ADAM Membership: Forminator submission data was not an array.' );
			return;
		}

		$email = $this->extract_email( $field_data_array );

		if ( '' === $email ) {
			error_log( 'ADAM Membership: Unable to find a valid email in Forminator form 178 submission.' );
			return;
		}

		if ( email_exists( $email ) ) {
			return;
		}

		if ( username_exists( $email ) ) {
			error_log( sprintf( 'ADAM Membership: Username already exists for submitted email %s.', $email ) );
			return;
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $email,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'role'       => 'subscriber',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			$this->log_wp_error( $user_id, $email );
			return;
		}

		wp_new_user_notification( (int) $user_id, null, 'user' );
	}

	/**
	 * Extract the submitted email from Forminator field data.
	 *
	 * @param array<int|string, mixed> $field_data_array Submitted field data.
	 */
	private function extract_email( array $field_data_array ): string {
		$preferred_email = $this->find_email_by_field_name( $field_data_array );

		if ( '' !== $preferred_email ) {
			return $preferred_email;
		}

		return $this->find_first_email_value( $field_data_array );
	}

	/**
	 * Find an email value from fields that identify themselves as email fields.
	 *
	 * @param array<int|string, mixed> $field_data_array Submitted field data.
	 */
	private function find_email_by_field_name( array $field_data_array ): string {
		foreach ( $field_data_array as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_name = isset( $field['name'] ) ? (string) $field['name'] : '';
			$field_type = isset( $field['type'] ) ? (string) $field['type'] : '';

			if ( ! str_contains( $field_name, 'email' ) && 'email' !== $field_type ) {
				continue;
			}

			$email = $this->sanitize_email_value( $field['value'] ?? '' );

			if ( '' !== $email ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * Find the first valid email value in submitted data.
	 *
	 * @param mixed $data Submitted field data.
	 */
	private function find_first_email_value( mixed $data ): string {
		if ( is_scalar( $data ) ) {
			return $this->sanitize_email_value( $data );
		}

		if ( ! is_array( $data ) ) {
			return '';
		}

		foreach ( $data as $value ) {
			$email = $this->find_first_email_value( $value );

			if ( '' !== $email ) {
				return $email;
			}
		}

		return '';
	}

	/**
	 * Sanitize and validate a possible email value.
	 *
	 * @param mixed $value Possible email value.
	 */
	private function sanitize_email_value( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$email = sanitize_email( (string) $value );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Log user creation failures.
	 *
	 * @param WP_Error $error Failed user creation error.
	 * @param string   $email Submitted email address.
	 */
	private function log_wp_error( WP_Error $error, string $email ): void {
		error_log(
			sprintf(
				'ADAM Membership: Failed to create user for %1$s. Error: %2$s',
				$email,
				$error->get_error_message()
			)
		);
	}
}
