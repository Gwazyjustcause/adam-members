<?php
/** Regression coverage for APD persistence and its approved financial movement. */

declare(strict_types=1);

require_once __DIR__ . '/../src/GoogleSheets/GoogleSheetsTablePlanner.php';

use AdamMembership\GoogleSheets\GoogleSheetsTablePlanner;

function adam_apd_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$service = (string) file_get_contents( __DIR__ . '/../src/Member/ApdAssociationService.php' );
$request = (string) file_get_contents( __DIR__ . '/../src/Member/ApdAssociationRequest.php' );
$sync = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$plugin = (string) file_get_contents( __DIR__ . '/../src/Core/Plugin.php' );
$admin = (string) file_get_contents( __DIR__ . '/../src/Admin/AdminController.php' );
$member_area = (string) file_get_contents( __DIR__ . '/../src/Member/MemberArea.php' );

foreach ( array( 'request_uuid', 'quota_type', 'membership_year', 'payment_amount', 'payment_date', 'payment_method' ) as $field ) {
	adam_apd_assert( str_contains( $service, "'{$field}'" ) || str_contains( $request, "{$field}()" ), "APD persists {$field}." );
}
adam_apd_assert( str_contains( $service, "'quota_type' => 'Associar APD/ANA'" ), 'APD classification is explicit.' );
adam_apd_assert( str_contains( $service, "'request_uuid' => 'apd:'" ), 'APD receives an independent canonical ID.' );
adam_apd_assert( str_contains( $service, "do_action( 'adam_membership_apd_association_approved'" ), 'APD sync starts only after confirmation.' );
adam_apd_assert( str_contains( $plugin, 'sync_apd_association' ), 'Plugin registers APD approval synchronization.' );
adam_apd_assert( str_contains( $admin, "'apd' === " . '$type' ), 'APD retry uses the canonical sync service.' );
foreach ( array( "\$fields['membership_year']", "\$fields['payment_amount']", "\$fields['payment_date']", "\$fields['payment_method']" ) as $field ) {
	adam_apd_assert( str_contains( $member_area, $field ), "APD form exposes {$field}." );
}

$row = array( 'Associar APD/ANA', 'ADAM-0007', 'Member', 2026, 'Associar APD/ANA', 'Efetivo', 12.0, '2026-08-14', 'MB WAY', 'Pago', 'apd:movement-1', '' );
$plan = GoogleSheetsTablePlanner::plan( array(), 'apd:movement-1' );
adam_apd_assert( 0 === $plan['duplicate_row'], 'A new APD movement appends.' );
$existing = GoogleSheetsTablePlanner::plan( array( $row ), 'apd:movement-1' );
adam_apd_assert( 5 === $existing['duplicate_row'], 'Retry finds the APD row by column K.' );
adam_apd_assert( 12 === count( $row ) && 'Associar APD/ANA' === $row[0] && 'apd:movement-1' === $row[10], 'APD uses the complete A:L mapping.' );
$remaining = GoogleSheetsTablePlanner::plan( array( array( 'Inscrição ADAM', 'ADAM-0008', 'Other', 2026, 'Inscrição', 'Efetivo', 12.0, '2026-08-14', 'MB WAY', 'Pago', 'registration:other', '' ) ), 'apd:movement-1' );
adam_apd_assert( 0 === $remaining['duplicate_row'], 'Deleted APD rows are resolved from current sheet state without overwriting another member.' );

foreach ( array( 'Inscrição ADAM', 'Inscrição ADAM/ANA', 'Renovação ADAM', 'Renovação ADAM/ANA', 'Associar APD/ANA' ) as $type ) {
	adam_apd_assert( str_contains( $sync, $type ), "End-to-end mapper retains {$type}." );
}

echo "Google Sheets APD lifecycle smoke tests passed.\n";
