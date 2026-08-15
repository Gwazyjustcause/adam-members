<?php
/** Regression contract for the shared approval correction field catalogue. */

declare(strict_types=1);

$root = dirname( __DIR__ );
$catalog = (string) file_get_contents( $root . '/src/Core/CorrectionFieldCatalog.php' );
$admin = (string) file_get_contents( $root . '/src/Admin/AdminController.php' );
$renewal = (string) file_get_contents( $root . '/src/Member/RenewalService.php' );
$member_change = (string) file_get_contents( $root . '/src/Member/MemberChangeService.php' );
$apd = (string) file_get_contents( $root . '/src/Member/ApdAssociationService.php' );
$area = (string) file_get_contents( $root . '/src/Member/MemberArea.php' );

function adam_approval_catalog_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

adam_approval_catalog_assert( str_contains( $catalog, "'full_name' => 'Nome completo'" ) && str_contains( $catalog, "'citizen_card' => 'BI / Cartão de Cidadão'" ) && str_contains( $catalog, "'external_association_name' => 'Nome da associação / APD'" ), 'The catalogue must expose the complete Portuguese registration field set.' );
adam_approval_catalog_assert( str_contains( $catalog, 'public static function definitions' ) && str_contains( $catalog, 'public static function storage_key' ) && str_contains( $catalog, 'public static function value' ), 'The catalogue must be the shared source for labels, storage and prefilled values.' );
adam_approval_catalog_assert( str_contains( $admin, 'CorrectionFieldCatalog::definitions' ) && str_contains( $admin, 'CorrectionFieldCatalog::groups' ), 'Every admin correction selector must use the shared catalogue and groups.' );
adam_approval_catalog_assert( str_contains( $renewal, 'array_keys( CorrectionFieldCatalog::labels() )' ) && str_contains( $renewal, 'CorrectionFieldCatalog::storage_key( $field )' ), 'A renewal may select and persist a member field that was not in its original submitted snapshot.' );
adam_approval_catalog_assert( str_contains( $member_change, 'request_correction( int $id, string $reason, string $note = \'\', array $fields' ) && str_contains( $member_change, 'CorrectionFieldCatalog::value' ), 'Data-change corrections must use the same complete catalogue and member values.' );
adam_approval_catalog_assert( str_contains( $apd, 'request_correction( int $request_id, string $reason, string $note = \'\', array $fields' ) && str_contains( $apd, 'submit_correction' ), 'APD/ANA corrections must preserve the same request and selected field set.' );
adam_approval_catalog_assert( str_contains( $area, 'render_full_member_correction_page' ) && str_contains( $area, 'render_apd_full_correction_page' ) && str_contains( $area, 'CorrectionFieldCatalog::value( $member, $key )' ), 'Member correction pages must prefill stored values and render only selected fields.' );
adam_approval_catalog_assert( str_contains( $area, 'CorrectionFieldCatalog::is_file( $key )' ) && str_contains( $apd, "'payment_receipt'" ), 'Request-specific documents/payment proof remain available alongside member fields.' );

echo "Approval correction catalogue smoke tests passed.\n";
