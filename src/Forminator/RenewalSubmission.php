<?php
/**
 * Forminator renewal integration.
 *
 * @package AdamMembership\Forminator
 */

declare(strict_types=1);

namespace AdamMembership\Forminator;

use AdamMembership\Helpers\Logger;
use AdamMembership\Member\RenewalService;

/**
 * Creates renewal requests from the official renewal form.
 */
final class RenewalSubmission {
	private const FORM_ID = 280;

	private RenewalService $renewals;
	private Logger $logger;

	/**
	 * Constructor.
	 */
	public function __construct( RenewalService $renewals, Logger $logger ) {
		$this->renewals = $renewals;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'forminator_custom_form_after_save_entry', array( $this, 'handle_submission' ), 10, 3 );
	}

	/**
	 * Handle the official renewal form submission.
	 *
	 * @param mixed $entry_id         Entry ID.
	 * @param mixed $form_id          Form ID.
	 * @param mixed $field_data_array Field data.
	 */
	public function handle_submission( mixed $entry_id, mixed $form_id, mixed $field_data_array ): void {
		if ( self::FORM_ID !== absint( $form_id ) ) {
			return;
		}

		if ( ! is_array( $field_data_array ) ) {
			$this->logger->error( 'Renewal submission ignored because Forminator data was not an array.', array( 'entry_id' => $entry_id ) );
			return;
		}

		$result = $this->renewals->submit_from_forminator( absint( $entry_id ), $field_data_array );

		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'Renewal submission failed.', array( 'entry_id' => $entry_id, 'error' => $result->get_error_message() ) );
		}
	}
}
