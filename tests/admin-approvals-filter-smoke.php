<?php
/** Regression contract for the admin approvals category filters. */

declare(strict_types=1);

$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );

function adam_approvals_filter_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

adam_approvals_filter_assert( str_contains( $source, '$visible_rows = array_values( array_filter( $rows' ), 'Approvals must filter the canonical row set before rendering.' );
adam_approvals_filter_assert( str_contains( $source, '$this->normalize_approval_type( (string) ( $row[\'type\'] ?? \'\' ) )' ), 'Filtering must use canonical request types, not translated labels.' );
adam_approvals_filter_assert( str_contains( $source, "'registrations' => 'Inscrições'" ) && str_contains( $source, "'renewals' => 'Renovações'" ) && str_contains( $source, "'changes' => 'Alterações de dados'" ) && str_contains( $source, "'apd' => 'APD / ANA'" ), 'All approval categories must be present.' );
adam_approvals_filter_assert( str_contains( $source, "'registration' => 'registrations'" ) && str_contains( $source, "'renewal' => 'renewals'" ) && str_contains( $source, "'memberchange' => 'changes'" ) && str_contains( $source, "'apdassociation' => 'apd'" ), 'Known legacy/current type names must normalize safely.' );
adam_approvals_filter_assert( str_contains( $source, 'is-active' ) && str_contains( $source, 'aria-current=' ), 'The selected category must have an accessible active state.' );
adam_approvals_filter_assert( str_contains( $source, 'Não existem pedidos pendentes na categoria' ), 'Filtered empty states must explain the selected category.' );
adam_approvals_filter_assert( substr_count( $source, "'approval_type' => '" ) >= 4, 'Review links must preserve their canonical category.' );
adam_approvals_filter_assert( str_contains( $source, '$requested_id = absint( $_GET[\'request_id\'] ?? 0 );' ), 'The data-change review route must honor the selected request ID.' );

echo "Admin approvals filter smoke tests passed.\n";
