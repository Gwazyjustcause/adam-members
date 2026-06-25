<?php
/**
 * Forminator user registration integration.
 *
 * @package AdamMembership\Forminator
 */

declare(strict_types=1);

namespace AdamMembership\Forminator;

use AdamMembership\Helpers\Logger;
use AdamMembership\Member\Member;
use WP_Error;

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

	/**
	 * Create the registration service.
	 *
	 * @param RegistrationFormConfig $config Registration form configuration.
	 * @param Logger                 $logger Logger helper.
	 */
	public function __construct( RegistrationFormConfig $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
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
			$this->logger->info( 'Registration skipped because the submitted email already exists.', array( 'email' => $email ) );
			return;
		}

		if ( username_exists( $email ) ) {
			$this->logger->error( 'Registration failed because the submitted email is already used as a username.', array( 'email' => $email ) );
			return;
		}

		$user_id = $this->create_user( $submission, $email );

		if ( is_wp_error( $user_id ) ) {
			$this->logger->error(
				'Registration failed during WordPress user creation.',
				array(
					'email' => $email,
					'error' => $user_id->get_error_message(),
				)
			);
			return;
		}

		$this->logger->info( 'User created.', array( 'user_id' => $user_id ) );

		wp_new_user_notification( (int) $user_id, null, 'user' );

		$member = new Member( (int) $user_id );
		$member->initialize( $this->build_member_data( $submission ) );

		$this->logger->info( 'Member initialized.', array( 'user_id' => $user_id ) );
	}

	/**
	 * Create a WordPress subscriber user from submitted data.
	 *
	 * @param SubmissionData $submission Submitted registration data.
	 * @param string         $email      Submitted email address.
	 * @return int|WP_Error
	 */
	private function create_user( SubmissionData $submission, string $email ): int|WP_Error {
		$first_name   = $submission->get_string( 'first_name' );
		$last_name    = $submission->get_string( 'last_name' );
		$display_name = $this->build_display_name( $first_name, $last_name, $email );

		return wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'role'         => 'subscriber',
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $display_name,
				'nickname'     => $display_name,
			)
		);
	}

	/**
	 * Build member data from submitted registration values.
	 *
	 * @param SubmissionData $submission Submitted registration data.
	 * @return array<string, mixed>
	 */
	private function build_member_data( SubmissionData $submission ): array {
		return array(
			'estado'            => 'Pendente',
			'numero_socio'      => '',
			'data_adesao'       => '',
			'validade_quota'    => '',
			'telefone'          => $submission->get_string( 'phone' ),
			'nif'               => $submission->get_string( 'nif' ),
			'cartao_cidadao'    => $submission->get_string( 'citizen_card' ),
			'data_nascimento'   => $submission->get_string( 'birth_date' ),
			'morada'            => $submission->get_string( 'address' ),
			'equipa'            => $submission->get_string( 'team' ),
			'profile_photo'     => $submission->get( 'profile_photo' ),
			'payment_receipt'   => $submission->get( 'payment_receipt' ),
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
