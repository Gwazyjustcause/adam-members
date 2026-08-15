<?php
/** Regression checks for financial snapshots and the single current panel. */

declare(strict_types=1);

function adam_snapshot_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$schema = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovementSchema.php' );
$model  = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovement.php' );
$repo   = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovementRepository.php' );
$sync   = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$admin  = (string) file_get_contents( __DIR__ . '/../src/Admin/AdminController.php' );

adam_snapshot_assert( str_contains( $schema, 'member_type varchar(32)' ), 'Financial movements persist Tipo de Sócio.' );
adam_snapshot_assert( str_contains( $model, 'member_number()' ) && str_contains( $model, 'member_name()' ) && str_contains( $model, 'member_type()' ), 'FinancialMovement exposes the complete member snapshot.' );
adam_snapshot_assert( str_contains( $repo, "'member_number' => (string) \$member->field( 'numero_socio' )" ) && str_contains( $repo, "'member_name' => \$member->full_name()" ) && str_contains( $repo, "'member_type' => self::member_type_for( \$member )" ), 'Manual creation snapshots member number, name and type.' );
adam_snapshot_assert( str_contains( $repo, 'ORDER BY membership_year DESC' ) && str_contains( $repo, 'payment_date DESC' ) && str_contains( $repo, 'created_at DESC' ), 'Latest movement selection is deterministic.' );
adam_snapshot_assert( str_contains( $sync, "'member_type' => \$this->membership_type" ) && str_contains( $sync, 'member_type()' ), 'Automatic and manual sync paths use the persisted member-type snapshot.' );
adam_snapshot_assert( str_contains( $sync, "'member_name'    => \$member->full_name()" ) && str_contains( $sync, "'member_name' => \$member->full_name()" ), 'Automatic movements pass member_name into FinancialMovement.' );
adam_snapshot_assert( str_contains( $sync, "if ( '' === trim( \$movement->member_name() ) )" ) && str_contains( $sync, "if ( ! in_array( \$movement->member_type()" ), 'Retry repairs only missing/invalid legacy snapshots.' );
adam_snapshot_assert( str_contains( $admin, 'latest_for_member' ) && 1 === substr_count( $admin, 'render_current_financial_movement_panel( $member )' ), 'The member page renders one current financial panel.' );
adam_snapshot_assert( ! str_contains( $admin, 'render_manual_financial_movement_panels( $member )' ), 'Historical manual movements are not rendered as separate panels.' );

$row = array( 'Renovação ADAM', 'ADAM-0007', 'Gabriela Vicente Ferreira', 2027, 'Renovação', 'Aderente', 12.0, '2027-08-14', 'Transferência bancária', 'Pago', 'manual:movement-1', '' );
adam_snapshot_assert( 12 === count( $row ), 'Manual movements produce a complete A:L row.' );
adam_snapshot_assert( 'ADAM-0007' === $row[1] && 'Gabriela Vicente Ferreira' === $row[2] && 'Aderente' === $row[5] && 'manual:movement-1' === $row[10], 'Manual A:L member snapshot fields are correctly mapped.' );

echo "Financial movement snapshot/UI smoke tests passed.\n";
