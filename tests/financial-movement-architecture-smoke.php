<?php
declare(strict_types=1);

function adam_finance_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$schema = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovementSchema.php' );
$repository = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovementRepository.php' );
$sync = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );
$admin = (string) file_get_contents( __DIR__ . '/../src/Admin/AdminController.php' );
$bootstrap = (string) file_get_contents( __DIR__ . '/../adam-membership.php' );

adam_finance_assert( str_contains( $schema, 'UNIQUE KEY movement_id' ), 'Movement IDs are unique.' );
adam_finance_assert( str_contains( $schema, 'UNIQUE KEY source_reference' ), 'Source type/reference is unique.' );
foreach ( array( 'quota_type', 'membership_year', 'amount', 'payment_date', 'payment_method', 'google_state' ) as $field ) {
	adam_finance_assert( str_contains( $schema, $field ), "Schema stores {$field}." );
}
adam_finance_assert( str_contains( $repository, 'find_by_source' ) && str_contains( $repository, 'ensure' ), 'Legacy records migrate idempotently by source.' );
adam_finance_assert( str_contains( $repository, "'manual:' . wp_generate_uuid4()" ), 'Manual movements get independent IDs.' );
adam_finance_assert( str_contains( $sync, '$this->movements->ensure' ), 'Automatic workflows persist FinancialMovement before sync.' );
adam_finance_assert( str_contains( $sync, "'movement_id'    => \$request->request_uuid()" ), 'Renewals pass their canonical ID as movement_id.' );
adam_finance_assert( str_contains( $sync, "'quota_type' => 'Associar APD/ANA', 'movement_id' => \$request->request_uuid()" ), 'APD passes its canonical ID as movement_id.' );
adam_finance_assert( str_contains( $sync, "'movement_id'   => \$request_id, 'source_type' => 'registration'" ), 'Registrations pass their canonical ID as movement_id.' );
adam_finance_assert( ! str_contains( $sync, "'request_id'     => \$request->request_uuid()" ) && ! str_contains( $sync, "'request_id' => \$request->request_uuid()" ), 'Workflow constructors do not use request_id as the FinancialMovement ID.' );
adam_finance_assert( str_contains( $sync, "'movement_id' => \$record->movement_id()" ), 'The Google sync payload preserves the FinancialMovement ID.' );
adam_finance_assert( str_contains( $sync, 'sync_manual' ), 'Manual movements use the same sync path.' );
adam_finance_assert( str_contains( $admin, 'create_manual_financial_movement' ) && str_contains( $admin, 'Selecionar outro tipo cria um novo movimento manual' ), 'Changing type creates a manual movement and explains the consequence.' );
adam_finance_assert( str_contains( $admin, "'manual' === \$type" ) && str_contains( $admin, 'sync_manual' ), 'Manual movements can be edited and retried without membership side effects.' );
adam_finance_assert( str_contains( $bootstrap, 'FinancialMovementSchema::maybe_install' ), 'Schema upgrades run through the normal non-destructive init path.' );

echo "Financial movement architecture smoke tests passed.\n";
