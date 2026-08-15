<?php
/** Contract checks for the isolated Gestão de Sócios submission workflow. */

declare(strict_types=1);

function adam_membership_workflow_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$service = (string) file_get_contents( dirname( __DIR__ ) . '/src/GoogleSheets/GoogleSheetsMembershipWorkflowService.php' );
$client  = (string) file_get_contents( dirname( __DIR__ ) . '/src/GoogleSheets/GoogleSheetsClient.php' );
$registration = (string) file_get_contents( dirname( __DIR__ ) . '/src/Form/RegistrationService.php' );
$plugin = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Plugin.php' );
$quota = (string) file_get_contents( dirname( __DIR__ ) . '/src/GoogleSheets/GoogleSheetsSyncService.php' );
$quota_client = (string) file_get_contents( dirname( __DIR__ ) . '/src/GoogleSheets/GoogleSheetsClient.php' );
$renewal = (string) file_get_contents( dirname( __DIR__ ) . '/src/Member/RenewalService.php' );
$apd = (string) file_get_contents( dirname( __DIR__ ) . '/src/Member/ApdAssociationService.php' );
$workflow_script = (string) file_get_contents( dirname( __DIR__ ) . '/docs/gestao-de-socios-workflow.gs' );

adam_membership_workflow_assert( str_contains( $service, "'Gestão de Sócios'" ), 'The new service must target the separate Gestão de Sócios worksheet.' );
adam_membership_workflow_assert( str_contains( $service, "'Tesoureiro'" ) && str_contains( $service, "'Por confirmar'" ) && str_contains( $service, "'Por iniciar'" ), 'The initial operational values must be explicit.' );
adam_membership_workflow_assert( str_contains( $service, "'Inscrição ADAM/ANA'" ) && str_contains( $service, "'Renovação ADAM/ANA'" ) && str_contains( $service, "'Associar APD/ANA'" ), 'All workflow types must be mapped.' );
adam_membership_workflow_assert( str_contains( $service, "'Espera' : 'Não aplicável'" ), 'ANA-required and ADAM-only submissions must initialize distinct ANA values.' );
adam_membership_workflow_assert( str_contains( $client, 'adam_gestao_socios_request_id' ) && str_contains( $service, 'request_uuid' ), 'Idempotency must use the request UUID as row metadata, not a visible column.' );
adam_membership_workflow_assert( str_contains( $client, 'createDeveloperMetadata' ) && str_contains( $client, 'updateTable' ) && str_contains( $client, 'copyPaste' ), 'Workflow writes must expand the real table and preserve row formatting.' );
adam_membership_workflow_assert( str_contains( $registration, "adam_membership_registration_submitted" ) && str_contains( $registration, 'outside the registration transaction' ), 'Registration workflow must run after successful submission and outside the rollback path.' );
adam_membership_workflow_assert( str_contains( $renewal, 'adam_membership_renewal_submitted' ) && str_contains( $apd, 'adam_membership_apd_association_submitted' ), 'Renewal and APD submissions must have independent submission hooks.' );
adam_membership_workflow_assert( str_contains( $plugin, 'GoogleSheetsMembershipWorkflowService' ) && str_contains( $plugin, 'adam_membership_registration_submitted' ), 'The isolated service must be wired to the submission hook.' );
adam_membership_workflow_assert( str_contains( $quota_client, 'QuotasTable' ) && str_contains( $quota, 'sync_registration' ), 'The existing Quotas synchronization code must remain present.' );
adam_membership_workflow_assert( ! str_contains( $service, 'adam_membership_member_approved' ), 'The new workflow must not be approval-driven.' );
adam_membership_workflow_assert( str_contains( $workflow_script, "currentState === 'Concluído'" ) && str_contains( $workflow_script, "currentState === 'Rejeitado'" ), 'Completed and rejected rows must remain terminal.' );
adam_membership_workflow_assert( str_contains( $workflow_script, "state = 'Pronto'" ) && str_contains( $workflow_script, "invoice === 'Disponível' || invoice === 'Entregue'" ), 'Spreadsheet automation must calculate readiness using the updated invoice values.' );

echo "Google Sheets membership workflow smoke tests passed.\n";
