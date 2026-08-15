<?php
/**
 * Regression coverage for the approved-renewal recovery path.
 *
 * This is deliberately a source/integration-contract test: the local suite
 * has no WordPress database or production Google credentials, so it verifies
 * the real workflow sections and models the idempotent row decision without
 * calling either external system.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/GoogleSheets/GoogleSheetsTablePlanner.php';

use AdamMembership\GoogleSheets\GoogleSheetsTablePlanner;

function adam_recovery_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$sync       = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$renewal    = (string) file_get_contents( __DIR__ . '/../src/Member/RenewalService.php' );
$apd        = (string) file_get_contents( __DIR__ . '/../src/Member/ApdAssociationService.php' );
$approval   = (string) file_get_contents( __DIR__ . '/../src/Member/ApprovalService.php' );
$plugin     = (string) file_get_contents( __DIR__ . '/../src/Core/Plugin.php' );
$admin      = (string) file_get_contents( __DIR__ . '/../src/Admin/AdminController.php' );
$repository = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovementRepository.php' );

adam_recovery_assert( str_contains( $sync, "'movement_id'    => \$request->request_uuid()" ), 'Renewal approval/sync constructs movement_id from renewal:{uuid}.' );
adam_recovery_assert( str_contains( $sync, "'source_type' => 'renewal'" ) && str_contains( $sync, "'source_reference' => \$request->request_uuid()" ), 'Renewal source type/reference remain separate.' );
adam_recovery_assert( str_contains( $sync, "'movement_id' => \$record->movement_id()" ), 'Sync uses the persisted movement ID for lookup, locking and the A:L row.' );
adam_recovery_assert( str_contains( $repository, '$this->find( $movement_id ) ?? $this->find_by_source' ), 'Recovery first resolves the same movement ID and then source identity.' );

$retry_start = strpos( $admin, 'public function handle_retry_google_sheets' );
$retry_end   = strpos( $admin, '/** Save payment data required', $retry_start );
$retry       = false !== $retry_start && false !== $retry_end ? substr( $admin, $retry_start, $retry_end - $retry_start ) : '';
adam_recovery_assert( str_contains( $retry, "'renewal' === \$type" ) && str_contains( $retry, 'sync_renewal' ), 'Retry loads the approved renewal and calls only the financial sync.' );
adam_recovery_assert( ! str_contains( $retry, '->approve(' ) && ! str_contains( $retry, 'send_approval' ) && ! str_contains( $retry, 'confirm_ana' ), 'Renewal retry does not rerun approval side effects.' );
adam_recovery_assert( str_contains( $renewal, "'request_uuid'         => 'renewal:'" ) || str_contains( $renewal, "'request_uuid' => 'renewal:'" ), 'Renewal requests retain their original renewal:{uuid}.' );
adam_recovery_assert( str_contains( $plugin, "'adam_membership_renewal_approved'" ) && str_contains( $renewal, "do_action( 'adam_membership_renewal_approved'" ), 'Normal renewal approval remains the source of the first sync attempt.' );
adam_recovery_assert( str_contains( $apd, "'request_uuid' => 'apd:'" ), 'APD requests retain their original apd:{uuid}.' );

$row = array( 'Renovação ADAM', 'ADAM-0007', 'Member', 2027, 'Renovação', 'Efetivo', 12.0, '2026-12-14', 'Transferência bancária', 'Pago', 'renewal:existing-1', '' );
$first = GoogleSheetsTablePlanner::plan( array(), 'renewal:existing-1' );
adam_recovery_assert( 0 === $first['duplicate_row'], 'Approved renewal with missing movement/row is eligible for one append.' );
$one_row = array( $row );
$retry_plan = GoogleSheetsTablePlanner::plan( $one_row, 'renewal:existing-1' );
adam_recovery_assert( 5 === $retry_plan['duplicate_row'], 'Retry finds the same renewal row by canonical ID.' );
adam_recovery_assert( 5 === GoogleSheetsTablePlanner::plan( $one_row, 'renewal:existing-1' )['duplicate_row'], 'Repeated retry cannot append a second renewal row.' );

echo "Financial movement renewal recovery smoke tests passed.\n";
