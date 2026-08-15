<?php
/** Permanent document-history deletion safety contracts. */

declare(strict_types=1);

function adam_document_deletion_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$service = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/MemberDocumentHistoryService.php' );
$admin = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );
$repository = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/PrivateDocumentRepository.php' );

$scenarios = array(
	'registration Media Library reference' => 'attachment_in_any_user_meta',
	'renewal reference'                   => 'request_contains_attachment',
	'correction/APD/ANA reference'        => 'attachment_in_correction_or_apd_data',
	'active private PDF'                  => 'active_document',
	'shared private PDF'                  => 'private_file_shared',
	'superseded private PDF'              => 'delete_with_storage',
	'successful Media Library deletion'   => 'wp_delete_attachment( $attachment_id, true )',
);
foreach ( $scenarios as $label => $marker ) {
	adam_document_deletion_assert( str_contains( $service, $marker ) || str_contains( $repository, $marker ), $label . ' must have an explicit safety path.' );
}
adam_document_deletion_assert( str_contains( $admin, 'manage_options' ) || str_contains( $admin, 'CAPABILITY' ), 'Deletion must require manage_options.' );
adam_document_deletion_assert( str_contains( $admin, 'Esta ação elimina permanentemente o ficheiro e não pode ser anulada' ), 'Deletion must require explicit irreversible confirmation.' );
adam_document_deletion_assert( str_contains( $admin, 'document_history_permanently_deleted' ), 'Deletion must be audit logged.' );
adam_document_deletion_assert( str_contains( $admin, 'catch ( \\Throwable $exception )' ) && str_contains( $admin, 'redirect_document_history_error' ), 'Unexpected deletion failures must be caught and redirected.' );
adam_document_deletion_assert( str_contains( $admin, "'stage' => 'service.refused'" ) && str_contains( $admin, "'stage' => 'handler.catch'" ), 'Expected refusals and unexpected exceptions must be traceable in debug.log.' );
adam_document_deletion_assert( ! str_contains( $admin, 'logger->warning' ), 'The deletion handler must use an available logger method on refusal paths.' );
adam_document_deletion_assert( str_contains( $admin, 'redirect_document_history_message' ), 'Successful deletion must also terminate with an explicit history redirect.' );
adam_document_deletion_assert( substr_count( $admin, 'wp_safe_redirect( add_query_arg' ) >= 2, 'Success and failure deletion paths must both redirect.' );

echo "Member document history deletion smoke tests passed.\n";
