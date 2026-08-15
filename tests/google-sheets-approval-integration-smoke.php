<?php
/**
 * Static integration-contract checks for Phase 4.
 * No WordPress approval or Google Sheets request is executed.
 */

declare(strict_types=1);

function adam_phase4_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$approval = (string) file_get_contents( __DIR__ . '/../src/Member/ApprovalService.php' );
$renewal  = (string) file_get_contents( __DIR__ . '/../src/Member/RenewalService.php' );
$plugin   = (string) file_get_contents( __DIR__ . '/../src/Core/Plugin.php' );
$sync     = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$client   = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsClient.php' );
$admin    = (string) file_get_contents( __DIR__ . '/../src/Admin/AdminController.php' );

$approval_event = strpos( $approval, "do_action( 'adam_membership_member_approved'" );
$approval_return = strpos( $approval, 'return true;', $approval_event );
adam_phase4_assert( false !== $approval_event && false !== $approval_return && $approval_event < $approval_return, 'Registration sync event is emitted only after approval completes.' );
adam_phase4_assert( 1 === substr_count( $approval, "do_action( 'adam_membership_member_approved'" ), 'Registration approval emits exactly one sync event.' );
adam_phase4_assert( str_contains( $approval, 'return $this->approve( $user_id );' ), 'ANA registration approval delegates to the normal approval path.' );

$renewal_status = strpos( $renewal, "'status'      => RenewalRequest::STATUS_APPROVED" );
$renewal_event  = strpos( $renewal, "do_action( 'adam_membership_renewal_approved'" );
$renewal_return = strpos( $renewal, 'return true;', $renewal_event );
adam_phase4_assert( false !== $renewal_status && false !== $renewal_event && false !== $renewal_return && $renewal_status < $renewal_event && $renewal_event < $renewal_return, 'Renewal sync event is emitted after persisted approval.' );
adam_phase4_assert( 1 === substr_count( $renewal, "do_action( 'adam_membership_renewal_approved'" ), 'Renewal approval emits exactly one sync event.' );
adam_phase4_assert( str_contains( $renewal, 'return $this->approve( $request_id );' ), 'ANA renewal approval delegates to the normal approval path.' );

adam_phase4_assert( str_contains( $plugin, "'adam_membership_member_approved'" ) && str_contains( $plugin, "'adam_membership_renewal_approved'" ), 'Plugin bootstrap registers both post-approval integrations.' );
adam_phase4_assert( str_contains( $plugin, 'catch ( \\Throwable $exception )' ), 'Integration exceptions are isolated from approval.' );
adam_phase4_assert( str_contains( $plugin, 'ensure_registration_movement' ) && str_contains( $plugin, 'ensure_renewal_movement' ) && str_contains( $plugin, 'ensure_apd_movement' ), 'Approval preserves movements without automatically synchronizing Google Sheets.' );
adam_phase4_assert( ! str_contains( $plugin, '$google_sheets_sync->sync_registration' ) && ! str_contains( $plugin, '$google_sheets_sync->sync_renewal' ) && ! str_contains( $plugin, '$google_sheets_sync->sync_apd_association' ), 'Approval hooks do not perform automatic Google synchronization.' );
adam_phase4_assert( str_contains( $sync, 'is_configured' ) && str_contains( $sync, 'STATUS_INACTIVE' ), 'Disabled integration is recorded as inactive without an HTTP attempt.' );
adam_phase4_assert( str_contains( $sync, 'adam_google_sheets_payment_data_missing' ) && str_contains( $sync, 'missing_fields' ), 'Missing financial data remains pending and identifies the missing fields.' );
adam_phase4_assert( str_contains( $client, '401 === $status' ) && str_contains( $client, 'unavailable' ), 'HTTP failures use safe provider-independent errors.' );
$retry_start = strpos( $admin, 'public function handle_retry_google_sheets' );
$retry_end = strpos( $admin, '/** Save payment data required', $retry_start );
$retry_code = false !== $retry_start && false !== $retry_end ? substr( $admin, $retry_start, $retry_end - $retry_start ) : '';
adam_phase4_assert( '' !== $retry_code && ! str_contains( $retry_code, 'send_' ) && str_contains( $retry_code, 'sync_registration' ) && str_contains( $retry_code, 'sync_renewal' ), 'Retry only calls accounting synchronization and does not resend approval emails.' );

echo "Google Sheets approval integration smoke tests passed.\n";
