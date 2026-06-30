<?php
/**
 * Forminator user registration integration.
 *
 * @package AdamMembership\Forminator
 */

declare(strict_types=1);

namespace AdamMembership\Forminator;

use AdamMembership\Form\RegistrationService;
use AdamMembership\Helpers\Logger;

/**
 * Creates WordPress users and initializes member records from approved Forminator submissions.
 */
final class UserRegistration {
	/**
	 * Registration form configuration.
	 *
	 * @var RegistrationFormConfig
	 */
	private RegistrationFormConfig $config;

	/**
	 * Logger helper.
	 *
	 * @var Logger
	 */
	private Logger $logger;
	private RegistrationService $registration;

	/**
	 * Create the registration service.
	 *
	 * @param RegistrationFormConfig $config  Registration form configuration.
	 * @param Logger                 $logger  Logger helper.
	 * @param RegistrationService    $registration Native registration service.
	 */
	public function __construct( RegistrationFormConfig $config, Logger $logger, RegistrationService $registration ) {
		$this->config       = $config;
		$this->logger       = $logger;
		$this->registration = $registration;
	}

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
		if ( $this->config->form_id() !== absint( $form_id ) ) {
			return;
		}

		$this->logger->info( 'Registration started.', array( 'entry_id' => $entry_id ) );

		if ( ! is_array( $field_data_array ) ) {
			$this->logger->error( 'Registration failed because Forminator submission data was not an array.', array( 'entry_id' => $entry_id ) );
			return;
		}

		$submission = new SubmissionData( $field_data_array, $this->config );
		$email      = $submission->get_email( 'email' );

		if ( '' === $email ) {
			$this->logger->error( 'Registration failed because the submitted email was missing or invalid.', array( 'entry_id' => $entry_id ) );
			return;
		}

		if ( email_exists( $email ) ) {
			$this->logger->info( 'Registration skipped because the submitted email already exists.', array( 'email_hash' => wp_hash( $email ) ) );
			return;
		}

		if ( username_exists( $email ) ) {
			$this->logger->error( 'Registration failed because the submitted email is already used as a username.', array( 'email_hash' => wp_hash( $email ) ) );
			return;
		}

		$result = $this->registration->register( $this->build_payload( $submission, $email ), absint( $entry_id ) );

		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Registration failed during WordPress user creation.',
				array(
					'email_hash' => wp_hash( $email ),
					'error'      => $result->get_error_message(),
				)
			);
			return;
		}
	}

	/**
	 * Build a normalized registration payload.
	 *
	 * @param SubmissionData $submission Submitted registration data.
	 * @param string         $email      Submitted email address.
	 * @return array<string, mixed>
	 */
	private function build_payload( SubmissionData $submission, string $email ): array {
		return array(
			'email'             => $email,
			'full_name'         => $this->build_display_name( $submission->get_string( 'first_name' ), $submission->get_string( 'last_name' ), $email ),
			'phone'             => $submission->get_string( 'phone' ),
			'nif'               => $submission->get_string( 'nif' ),
			'citizen_card'      => $submission->get_string( 'citizen_card' ),
			'birth_date'        => $submission->get_string( 'birth_date' ),
			'address_line_1'    => $submission->get_string( 'address' ),
			'team'              => $submission->get_string( 'team' ),
			'profile_photo'     => $submission->get( 'profile_photo' ),
			'payment_receipt'   => $submission->get( 'payment_receipt' ),
			'membership_mode'   => 'adam_primary',
			'membership_fee'    => '',
		);
	}

	/**
	 * Build a display name from submitted profile data.
	 *
	 * @param string $first_name Submitted first name.
	 * @param string $last_name  Submitted last name.
	 * @param string $email      Submitted email address.
	 */
	private function build_display_name( string $first_name, string $last_name, string $email ): string {
		$display_name = trim( $first_name . ' ' . $last_name );

		return '' !== $display_name ? $display_name : $email;
	}
}
