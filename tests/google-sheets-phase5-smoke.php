<?php
/**
 * Phase 5 integration-contract checks.
 *
 * These isolated tests inspect the approved workflow and pure table-planning
 * contracts without booting WordPress or contacting Google.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/GoogleSheets/GoogleSheetsTablePlanner.php';

use AdamMembership\GoogleSheets\GoogleSheetsTablePlanner;

function adam_phase5_assert( bool $condition, string $message ): void {
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

adam_phase5_assert( 1 === substr_count( $approval, "do_action( 'adam_membership_member_approved'" ), 'Registration approval has one integration event.' );
adam_phase5_assert( str_contains( $approval, 'return $this->approve( $user_id );' ), 'ANA registration approval reuses the normal path.' );
adam_phase5_assert( 1 === substr_count( $renewal, "do_action( 'adam_membership_renewal_approved'" ), 'Renewal approval has one integration event.' );
adam_phase5_assert( str_contains( $renewal, 'return $this->approve( $request_id );' ), 'ANA renewal approval reuses the normal path.' );
adam_phase5_assert( str_contains( $renewal, 'RenewalRequest::STATUS_PENDING !== $request->status()' ), 'Duplicate renewal approval is rejected before the event.' );

adam_phase5_assert( str_contains( $plugin, 'catch ( \\Throwable $exception )' ), 'Google failures are isolated from approval.' );
adam_phase5_assert( str_contains( $sync, 'STATUS_INACTIVE' ) && str_contains( $sync, 'is_configured' ), 'Disabled Google integration is explicitly inactive.' );
adam_phase5_assert( str_contains( $sync, 'adam_google_sheets_payment_data_missing' ) && str_contains( $sync, 'append_table_row' ), 'Missing payment data is validated before an append.' );
adam_phase5_assert( str_contains( $sync, 'update_table_row' ) && str_contains( $sync, 'same_row' ), 'Changed data updates the existing canonical movement row.' );
adam_phase5_assert( str_contains( $sync, "'membership_year'" ) && str_contains( $admin, 'já sincronizada não pode ser alterado' ), 'A synchronized quota year is persisted and cannot be changed through the payment editor.' );
adam_phase5_assert( str_contains( $sync, 'add_option( $lock_key, $lock_token' ) && str_contains( $sync, 'finally' ), 'Concurrent retries use an atomic request lock.' );
adam_phase5_assert( str_contains( $client, '401 === $status' ) && str_contains( $client, 'adam_google_sheets_unavailable' ), 'Google failures use safe errors.' );

$retry_start = strpos( $admin, 'public function handle_retry_google_sheets' );
$retry_end   = strpos( $admin, '/** Save payment data required', $retry_start );
$retry_code  = false !== $retry_start && false !== $retry_end ? substr( $admin, $retry_start, $retry_end - $retry_start ) : '';
adam_phase5_assert( '' !== $retry_code && str_contains( $retry_code, 'sync_registration' ) && str_contains( $retry_code, 'sync_renewal' ), 'Retry invokes only accounting synchronization.' );
adam_phase5_assert( ! str_contains( $retry_code, 'send_' ) && ! str_contains( $retry_code, 'approve(' ), 'Retry does not resend email or repeat approval actions.' );

adam_phase5_assert( ! str_contains( $approval, "do_action( 'adam_membership_member_rejected'" ), 'Registration rejection has no Google event.' );
adam_phase5_assert( ! str_contains( $approval, "do_action( 'adam_membership_member_correction'" ), 'Registration correction has no Google event.' );
adam_phase5_assert( ! str_contains( $renewal, "do_action( 'adam_membership_renewal_rejected'" ), 'Renewal rejection has no Google event.' );

$row = array( 'Inscrição ADAM', '42', 'Member', 2027, 'Inscricao', 'Efetivo', 25.0, '2026-12-15', 'MB WAY', 'Pago', 'registration:test', '' );
$plan = GoogleSheetsTablePlanner::plan( array( $row ), 'registration:test' );
adam_phase5_assert( 5 === $plan['duplicate_row'], 'A repeated registration ID is detected at its existing row.' );
adam_phase5_assert( GoogleSheetsTablePlanner::rows_match( $row, $plan['duplicate_values'] ), 'An identical repeated approval is idempotent.' );
adam_phase5_assert( ! GoogleSheetsTablePlanner::rows_match( $row, array_replace( $row, array( 5 => 30.0 ) ) ), 'A repeated ID with changed data is detected for update.' );

echo "Google Sheets Phase 5 smoke tests passed.\n";
