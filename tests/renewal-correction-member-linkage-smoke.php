<?php
/** Regression contract for renewal correction confirmation and account linkage. */

declare(strict_types=1);

$root = dirname( __DIR__ );
$area = (string) file_get_contents( $root . '/src/Member/MemberArea.php' );
$service = (string) file_get_contents( $root . '/src/Member/RenewalService.php' );
$request = (string) file_get_contents( $root . '/src/Member/RenewalRequest.php' );

function adam_renewal_linkage_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$request_lookup = strpos( $area, '$request = $this->renewals->find_request( $request_id );' );
$success_flag   = strpos( $area, '(string) ( $_GET[\'correction_complete\']' );
$status_guard   = strpos( $area, 'RenewalRequest::STATUS_CORRECTION_REQUESTED !== $request->status()' );
adam_renewal_linkage_assert( false !== $request_lookup && false !== $success_flag && false !== $status_guard && $success_flag < $status_guard, 'Renewal correction success must render before the correction-requested status guard.' );
adam_renewal_linkage_assert( str_contains( $area, '\'request_id\' => $request_id, \'correction_complete\' => \'1\'' ), 'The success redirect must retain the original renewal request ID.' );
adam_renewal_linkage_assert( str_contains( $service, '$request->user_id() !== $user_id' ) && str_contains( $service, '$this->renewals->update( $request, $data )' ), 'Resubmission must verify the existing member owner and update the same renewal record.' );
adam_renewal_linkage_assert( str_contains( $service, '$request->request_uuid()' ), 'Renewal approval/document lifecycle must continue using the original request UUID.' );
adam_renewal_linkage_assert( str_contains( $service, '\'email\' === $field' ) && str_contains( $service, 'update_member_email' ), 'Email corrections must be applied only during approval through the existing account-safe email path.' );
adam_renewal_linkage_assert( str_contains( $request, "'user_id'" ) && str_contains( $request, 'request_uuid' ), 'The renewal model must retain both the member account ID and canonical UUID.' );

echo "Renewal correction member-linkage smoke tests passed.\n";
