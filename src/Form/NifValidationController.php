<?php
/**
 * Registration NIF validation endpoint.
 *
 * @package AdamMembership\Form
 */

declare(strict_types=1);

namespace AdamMembership\Form;

/**
 * Provides the frontend duplicate-NIF availability check.
 */
final class NifValidationController {
	/**
	 * Create the validation controller.
	 *
	 * @param RegistrationService $registration Registration service.
	 */
	public function __construct( private RegistrationService $registration ) {}

	/**
	 * Register authenticated and public AJAX routes.
	 */
	public function register(): void {
		add_action( 'wp_ajax_adam_membership_validate_nif', array( $this, 'validate' ) );
		add_action( 'wp_ajax_nopriv_adam_membership_validate_nif', array( $this, 'validate' ) );
	}

	/**
	 * Validate a submitted NIF without exposing existing member data.
	 */
	public function validate(): void {
		if ( ! check_ajax_referer( 'adam_membership_nif_validation', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Não foi possível validar o NIF. Atualize a página e tente novamente.', 'adam-membership' ),
				),
				403
			);
		}

		$nif    = isset( $_POST['nif'] ) ? sanitize_text_field( wp_unslash( $_POST['nif'] ) ) : '';
		$result = $this->registration->validate_nif( $nif );

		if ( is_wp_error( $result ) ) {
			$code = (string) $result->get_error_code();
			wp_send_json_success(
				array(
					'status'  => 'adam_membership_duplicate_nif' === $code ? 'duplicate' : ( 'adam_membership_nif_check_in_progress' === $code ? 'local_valid' : 'invalid' ),
					'message' => 'adam_membership_nif_check_in_progress' === $code ? '' : $result->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'status'  => 'available',
				'message' => '',
			)
		);
	}
}
