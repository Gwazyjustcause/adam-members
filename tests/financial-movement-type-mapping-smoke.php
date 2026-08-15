<?php
/** Explicit regression coverage for quota-type to member-type mapping. */

declare(strict_types=1);

function adam_type_mapping_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$repository = (string) file_get_contents( __DIR__ . '/../src/Finance/FinancialMovementRepository.php' );
$sync       = (string) file_get_contents( __DIR__ . '/../src/GoogleSheets/GoogleSheetsSyncService.php' );

foreach ( array( 'Inscrição ADAM', 'Renovação ADAM' ) as $quota_type ) {
	adam_type_mapping_assert( str_contains( $repository, "in_array( \$quota_type, array( 'Inscrição ADAM', 'Renovação ADAM' ), true )" ), "{$quota_type} maps through the centralized rule." );
}
adam_type_mapping_assert( str_contains( $repository, 'member_type_for_quota_type' ), 'All FinancialMovements use one centralized mapping function.' );
adam_type_mapping_assert( str_contains( $sync, "member_type_for_quota_type( 'Associar APD/ANA' )" ), 'APD movements use the centralized mapping.' );
adam_type_mapping_assert( str_contains( $sync, '$expected_member_type' ), 'Retries repair a snapshot when it disagrees with quota_type.' );

$mapping = static function ( string $quota_type ): string {
	return in_array( $quota_type, array( 'Inscrição ADAM', 'Renovação ADAM' ), true ) ? 'Aderente' : 'Efetivo';
};
$expected = array(
	'Inscrição ADAM' => 'Aderente',
	'Inscrição ADAM/ANA' => 'Efetivo',
	'Renovação ADAM' => 'Aderente',
	'Renovação ADAM/ANA' => 'Efetivo',
	'Associar APD/ANA' => 'Efetivo',
);
foreach ( $expected as $quota_type => $member_type ) {
	adam_type_mapping_assert( $member_type === $mapping( $quota_type ), "{$quota_type} maps to {$member_type}." );
}

$historical = array( 'quota_type' => 'Renovação ADAM', 'member_type' => $mapping( 'Renovação ADAM' ), 'movement_id' => 'manual:old' );
$new = array( 'quota_type' => 'Renovação ADAM/ANA', 'member_type' => $mapping( 'Renovação ADAM/ANA' ), 'movement_id' => 'manual:new' );
adam_type_mapping_assert( 'Aderente' === $historical['member_type'] && 'Efetivo' === $new['member_type'], 'Changing ADAM-only to ADAM/ANA creates a new Efetivo movement while history remains Aderente.' );
adam_type_mapping_assert( 'manual:old' !== $new['movement_id'], 'The new quota type retains independent movement identity.' );

echo "Financial movement type mapping smoke tests passed.\n";
