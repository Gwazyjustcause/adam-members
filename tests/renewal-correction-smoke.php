<?php
/** Regression contract for renewal-specific correction rounds. */

declare(strict_types=1);

$root = dirname( __DIR__ );
$request = (string) file_get_contents( $root . '/src/Member/RenewalRequest.php' );
$repository = (string) file_get_contents( $root . '/src/Member/RenewalRepository.php' );
$service = (string) file_get_contents( $root . '/src/Member/RenewalService.php' );
$admin = (string) file_get_contents( $root . '/src/Admin/AdminController.php' );
$area = (string) file_get_contents( $root . '/src/Member/MemberArea.php' );
$email = (string) file_get_contents( $root . '/src/Emails/EmailService.php' );

function adam_renewal_correction_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

adam_renewal_correction_assert( str_contains( $request, 'STATUS_CORRECTION_REQUESTED' ) && str_contains( $request, 'STATUS_CORRECTION_SUBMITTED' ), 'Renewals need explicit correction lifecycle statuses.' );
adam_renewal_correction_assert( str_contains( $request, 'correction_fields' ) && str_contains( $request, 'correction_history' ), 'Correction fields and repeated-round history must live on the renewal request.' );
adam_renewal_correction_assert( str_contains( $repository, 'STATUS_CORRECTION_REQUESTED' ) && str_contains( $repository, 'STATUS_CORRECTION_SUBMITTED' ), 'Correction requests must remain duplicate-protected.' );
adam_renewal_correction_assert( str_contains( $service, 'public function request_correction' ) && str_contains( $service, 'public function submit_correction' ), 'Renewals must have their own correction service methods.' );
adam_renewal_correction_assert( str_contains( $service, 'array_intersect( $fields' ), 'Administrators may select only fields present on the renewal request.' );
adam_renewal_correction_assert( str_contains( $service, "'status' => RenewalRequest::STATUS_CORRECTION_REQUESTED" ) && str_contains( $service, "'status' => RenewalRequest::STATUS_CORRECTION_SUBMITTED" ), 'Request and resubmission statuses must be persisted on the same renewal record.' );
adam_renewal_correction_assert( str_contains( $service, "'submitted_data' => \$merged" ) && str_contains( $service, '$request->request_uuid()' ), 'Resubmission must merge selected values and preserve the original request UUID/documents.' );
adam_renewal_correction_assert( str_contains( $service, 'STATUS_CORRECTION_SUBMITTED' ) && str_contains( $service, 'approve( int $request_id' ) && str_contains( $service, 'reject( int $request_id' ), 'Corrected renewals must return to the normal approval/rejection lifecycle.' );
adam_renewal_correction_assert( str_contains( $admin, 'ACTION_REQUEST_RENEWAL_CORRECTION' ) && str_contains( $admin, 'render_renewal_correction_selector' ), 'Renewal review must expose the established correction selector and handler.' );
adam_renewal_correction_assert( str_contains( $admin, 'STATUS_CORRECTION_SUBMITTED' ) && str_contains( $admin, 'approval_rows' ), 'Resubmitted renewals must appear in Aprovações.' );
adam_renewal_correction_assert( str_contains( $email, 'send_renewal_correction_email' ) && str_contains( $email, 'member_correction_requested' ), 'Renewal correction must reuse the established correction notification template.' );
adam_renewal_correction_assert( str_contains( $area, "'renewal-correction' ===" ) && str_contains( $area, 'Corrigir pedido' ) && str_contains( $area, 'adam_renewal_correction_submit' ), 'Área de Sócio must render a renewal-specific correction form.' );
adam_renewal_correction_assert( str_contains( $area, 'submit_correction( $request_id' ) && str_contains( $area, 'correction_complete' ), 'Successful renewal correction must resubmit once and use a refresh-safe confirmation redirect.' );
adam_renewal_correction_assert( str_contains( $area, 'only the information indicated' ) || str_contains( $area, 'apenas a informação indicada' ), 'The member UI must explain that only selected fields can be edited.' );
adam_renewal_correction_assert( str_contains( $service, 'payment_receipt' ) && str_contains( $service, 'proof_of_payment' ) && str_contains( $service, '$proof_of_payment' ), 'Payment data and a newly selected receipt must remain part of the renewal record.' );
adam_renewal_correction_assert( str_contains( $service, '$file_uploads' ) && str_contains( $service, 'adam_renewal_correction_file_required' ), 'Selected renewal documents must be replaceable without losing the existing document reference.' );
adam_renewal_correction_assert( str_contains( $service, "'status'      => RenewalRequest::STATUS_APPROVED" ), 'Final approval remains the only path that activates the renewal.' );

echo "Renewal correction smoke tests passed.\n";
