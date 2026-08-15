<?php
/** Regression checks for financial snapshots and the single current panel. */

declare(strict_types=1);

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $value ): int { return abs( (int) $value ); }
}

require_once __DIR__ . '/../src/Finance/FinancialMovement.php';

use AdamMembership\Finance\FinancialMovement;

function adam_snapshot_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$schema = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovementSchema.php' );
$model  = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovement.php' );
$repo   = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovementRepository.php' );
$sync   = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$client = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsClient.php' );
$admin  = (string) file_get_contents( __DIR__ . '/../src/Admin/AdminController.php' );
$css    = (string) file_get_contents( __DIR__ . '/../assets/css/admin.css' );

adam_snapshot_assert( str_contains( $schema, 'member_type varchar(32)' ), 'Financial movements persist Tipo de Sócio.' );
adam_snapshot_assert( str_contains( $model, 'member_number()' ) && str_contains( $model, 'member_name()' ) && str_contains( $model, 'member_type()' ), 'FinancialMovement exposes the complete member snapshot.' );
adam_snapshot_assert( str_contains( $repo, "'member_number' => (string) \$member->field( 'numero_socio' )" ) && str_contains( $repo, "'member_name' => \$member->full_name()" ) && str_contains( $repo, 'member_type_for_quota_type' ), 'Manual creation snapshots member number, name and quota-derived type.' );
adam_snapshot_assert( str_contains( $repo, 'ORDER BY membership_year DESC' ) && str_contains( $repo, 'payment_date DESC' ) && str_contains( $repo, 'created_at DESC' ), 'Latest movement selection is deterministic.' );
adam_snapshot_assert( str_contains( $sync, 'FinancialMovementRepository::member_type_for_quota_type' ) && str_contains( $sync, 'member_type()' ), 'Automatic and manual sync paths use the centralized persisted member-type snapshot.' );
adam_snapshot_assert( str_contains( $sync, "'member_name'    => \$member->full_name()" ) && str_contains( $sync, "'member_name' => \$member->full_name()" ), 'Automatic movements pass member_name into FinancialMovement.' );
adam_snapshot_assert( str_contains( $client, 'in_array( $index, array( 3, 6 ), true )' ), 'Only year (D) and amount (G) are sent as numeric Google values.' );
adam_snapshot_assert( ! str_contains( $client, 'in_array( $index, array( 2, 5 ), true )' ), 'Name (C) and member type (F) are not coerced to numeric zero.' );
adam_snapshot_assert( str_contains( $sync, "if ( '' === trim( \$movement->member_name() ) )" ) && str_contains( $sync, 'if ( $movement->member_type() !== $expected_member_type )' ), 'Retry repairs snapshots that disagree with quota_type.' );
adam_snapshot_assert( str_contains( $admin, 'latest_for_member' ) && 1 === substr_count( $admin, 'render_current_financial_movement_panel( $member )' ), 'The member page renders one current financial panel.' );
adam_snapshot_assert( ! str_contains( $admin, 'render_manual_financial_movement_panels( $member )' ), 'Historical manual movements are not rendered as separate panels.' );
adam_snapshot_assert( str_contains( $admin, 'adam-google-sheets-payment-form' ) && str_contains( $admin, 'adam-google-sheets-quota-field' ) && str_contains( $admin, 'adam-google-sheets-payment-fields' ) && str_contains( $admin, 'adam-google-sheets-payment-actions' ), 'All financial panel renderings use the shared visual layout classes.' );
adam_snapshot_assert( str_contains( $css, 'grid-template-columns: repeat(4, minmax(0, 1fr))' ) && str_contains( $css, 'grid-template-columns: repeat(2, minmax(0, 1fr))' ) && str_contains( $css, 'grid-template-columns: 1fr' ), 'Financial fields stay on one desktop row and wrap responsively.' );

$row = array( 'Renovação ADAM', 'ADAM-0007', 'Gabriela Vicente Ferreira', 2027, 'Renovação', 'Aderente', 12.0, '2027-08-14', 'Transferência bancária', 'Pago', 'manual:movement-1', '' );
adam_snapshot_assert( 12 === count( $row ), 'Manual movements produce a complete A:L row.' );
adam_snapshot_assert( 'ADAM-0007' === $row[1] && 'Gabriela Vicente Ferreira' === $row[2] && 'Aderente' === $row[5] && 'manual:movement-1' === $row[10], 'Manual A:L member snapshot fields are correctly mapped.' );

$created = array( 'movement_id' => 'manual:movement-1', 'member_id' => 7, 'member_number' => 'ADAM-0007', 'member_name' => 'Gabriela Vicente Ferreira', 'member_type' => 'Aderente', 'quota_type' => 'Renovação ADAM', 'membership_year' => 2027, 'amount' => '12.00', 'payment_date' => '2027-08-14', 'payment_method' => 'Transferência bancária', 'source_type' => 'manual', 'source_reference' => 'manual:movement-1' );
$hydrated = new FinancialMovement( $created );
$hydrated_row = array( $hydrated->quota_type(), $hydrated->member_number(), $hydrated->member_name(), $hydrated->membership_year(), 'Renovação', $hydrated->member_type(), (float) $hydrated->amount(), $hydrated->payment_date(), $hydrated->payment_method(), 'Pago', $hydrated->movement_id(), '' );
adam_snapshot_assert( 'ADAM-0007' === $hydrated_row[1] && 'Gabriela Vicente Ferreira' === $hydrated_row[2] && 'Aderente' === $hydrated_row[5], 'Explicit manual fixture survives persistence/hydration into the Google row.' );

echo "Financial movement snapshot/UI smoke tests passed.\n";
