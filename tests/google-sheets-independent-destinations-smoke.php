<?php
/** Contract checks for independent Quotas and Gestão de Sócios destinations. */

declare(strict_types=1);

function adam_independent_sheets_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/SettingsRepository.php' );
$client   = (string) file_get_contents( dirname( __DIR__ ) . '/src/GoogleSheets/GoogleSheetsClient.php' );
$admin    = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );
$plugin   = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Plugin.php' );
$workflow = (string) file_get_contents( dirname( __DIR__ ) . '/src/GoogleSheets/GoogleSheetsMembershipWorkflowService.php' );

adam_independent_sheets_assert( str_contains( $settings, "'gestao_spreadsheet_id'" ), 'Settings must persist an independent Gestão spreadsheet ID.' );
adam_independent_sheets_assert( str_contains( $settings, '?string $gestao_spreadsheet_id = null' ), 'The existing Quotas save path must preserve its configuration when the new field is omitted.' );
adam_independent_sheets_assert( str_contains( $admin, 'name="google_sheets_gestao_spreadsheet_id"' ) && str_contains( $admin, 'ID da folha Gestão de Sócios' ), 'The settings UI must expose the separate Gestão spreadsheet field.' );
adam_independent_sheets_assert( str_contains( $admin, 'adam_membership_test_gestao_google_sheets' ) && str_contains( $admin, 'test_gestao_connection' ), 'The separate Gestão connection test must be registered and invoked.' );
adam_independent_sheets_assert( str_contains( $client, "config['gestao_spreadsheet_id']" ) && str_contains( $client, 'gestao_spreadsheet_id()' ), 'Gestão requests must resolve their own configured destination.' );
adam_independent_sheets_assert( str_contains( $client, "config['spreadsheet_id']" ) && str_contains( $client, 'QuotasTable' ), 'Quotas destination and table logic must remain present.' );
adam_independent_sheets_assert( str_contains( $client, 'request_json' ) && str_contains( $client, '$destination_id = \'\' !== trim( $spreadsheet_id )' ), 'Destination selection must be request-scoped, without mutating global settings.' );
adam_independent_sheets_assert( str_contains( $client, 'adam_google_sheets_gestao_spreadsheet_missing' ), 'Missing Gestão ID must produce a Gestão-specific configuration error.' );
adam_independent_sheets_assert( str_contains( $client, 'gestao_connection_test' ) && ! str_contains( substr( $client, strpos( $client, 'public function test_gestao_connection' ), 2500 ), ':batchUpdate' ), 'The Gestão connection test must be read-only.' );
adam_independent_sheets_assert( str_contains( $plugin, 'adam_membership_registration_submitted' ) && str_contains( $workflow, 'append_workflow_row' ), 'Registration submission must target Gestão immediately.' );

$retry_start = strpos( $admin, 'public function handle_retry_google_sheets' );
$retry_end   = strpos( $admin, '/** Save payment data required', $retry_start );
$retry       = false !== $retry_start && false !== $retry_end ? substr( $admin, $retry_start, $retry_end - $retry_start ) : '';
adam_independent_sheets_assert( str_contains( $retry, 'Member::STATUS_ACTIVE !== $member->status()' ), 'Pending registration retry must stop after Gestão synchronization.' );
adam_independent_sheets_assert( strpos( $retry, 'Member::STATUS_ACTIVE !== $member->status()' ) < strpos( $retry, '$this->google_sheets_sync->sync_persisted_movement' ), 'Pending retry guard must run before any Quotas synchronization.' );
adam_independent_sheets_assert( str_contains( $workflow, 'workflow_request_ids' ) && str_contains( $client, 'createDeveloperMetadata' ), 'Retry remains idempotent through request UUID developer metadata.' );

echo "Independent Google Sheets destination smoke tests passed.\n";
