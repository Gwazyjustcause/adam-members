<?php
/** Read-only member document history smoke tests. */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/Document/MemberDocumentHistoryService.php';

use AdamMembership\Document\MemberDocumentHistoryService;

function adam_document_history_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$items = array(
	array( 'year' => '2026', 'request_type' => 'registration', 'request_label' => 'Inscrição', 'document_type' => 'Comprovativo', 'filename' => 'registration.pdf' ),
	array( 'year' => '2027', 'request_type' => 'renewal', 'request_label' => 'Renovação', 'document_type' => 'Comprovativo', 'filename' => 'renewal-2027.pdf' ),
	array( 'year' => '2028', 'request_type' => 'renewal', 'request_label' => 'Renovação', 'document_type' => 'Documento de faturação/recibo', 'filename' => 'ADAM-2028.pdf' ),
);
$groups = MemberDocumentHistoryService::group_items( $items );
adam_document_history_assert( 3 === count( $groups ), 'Registration and two renewal years must remain separate history groups.' );
adam_document_history_assert( '2026' === $groups[0]['year'] && 'registration' === $groups[0]['request_type'], 'Initial registration must have its own year/request group.' );
adam_document_history_assert( '2027' === $groups[1]['year'] && 'renewal' === $groups[1]['request_type'], 'First renewal year must be represented.' );
adam_document_history_assert( '2028' === $groups[2]['year'] && 'renewal' === $groups[2]['request_type'], 'Second renewal year must be represented.' );

$admin = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );
$service = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/MemberDocumentHistoryService.php' );
$repository = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/PrivateDocumentRepository.php' );
$history_repository = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/MemberDocumentHistoryRepository.php' );
$schema = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/MemberDocumentHistorySchema.php' );
$private_repository = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/PrivateDocumentRepository.php' );

adam_document_history_assert( str_contains( $admin, 'Ver histórico de documentos' ), 'Member details must expose the history action.' );
adam_document_history_assert( str_contains( $admin, 'MEMBER_DOCUMENT_HISTORY_PAGE_SLUG' ), 'History must use a dedicated member document page.' );
adam_document_history_assert( strpos( $admin, 'Ver histórico de documentos' ) < strpos( $admin, 'render_current_financial_movement_panel( $member )' ), 'History action must appear above the current financial movement panel.' );
adam_document_history_assert( ! str_contains( $admin, 'foreach ( $member_requests as $request )' ), 'Member details must not render every historical renewal section.' );
adam_document_history_assert( str_contains( $service, 'registration_fields' ) && str_contains( $service, 'renewal_fields' ), 'History must aggregate registration and renewal media fields.' );
adam_document_history_assert( str_contains( $service, 'document_status' ) && str_contains( $service, 'for_references' ), 'History must include private-document versions through request references.' );
adam_document_history_assert( str_contains( $repository, 'for_references' ), 'Private repository must support historical lookup by request references.' );
adam_document_history_assert( str_contains( $service, 'archive_for_member' ) && str_contains( $service, 'archived_keys' ), 'History service must filter explicitly archived entries.' );
adam_document_history_assert( str_contains( $history_repository, 'UNIQUE KEY' ) || str_contains( $schema, 'UNIQUE KEY member_history' ), 'Archive markers must be idempotent per member and history entry.' );
adam_document_history_assert( str_contains( $admin, 'Remover do histórico' ) && str_contains( $admin, 'preservados' ), 'History removal must be explicit and explain that source files are preserved.' );
adam_document_history_assert( ! str_contains( $history_repository, 'wp_delete_attachment' ) && ! str_contains( $history_repository, 'delete_identifier' ), 'Phase 2 must not delete physical files.' );
adam_document_history_assert( str_contains( $schema, 'get_charset_collate' ) && str_contains( $schema, 'maybe_install' ), 'History schema must use WordPress collation and idempotent installation.' );
adam_document_history_assert( str_contains( $admin, 'Eliminar ficheiro permanentemente' ) && str_contains( $admin, 'não pode ser anulada' ), 'Permanent deletion must have a stronger explicit confirmation.' );
adam_document_history_assert( str_contains( $admin, 'handle_delete_document_history' ) && str_contains( $admin, 'adam_membership_delete_document_history_' ), 'Permanent deletion must use a dedicated admin-post action and nonce.' );
adam_document_history_assert( str_contains( $service, 'attachment_in_any_user_meta' ) && str_contains( $service, 'attachment_in_correction_or_apd_data' ) && str_contains( $service, 'request_contains_attachment' ), 'Media deletion must audit member, correction, APD/ANA and renewal references.' );
adam_document_history_assert( str_contains( $service, 'active_document' ) && str_contains( $service, 'for_file_identifier' ), 'Active and shared private documents must be refused.' );
adam_document_history_assert( str_contains( $private_repository, 'delete_with_storage' ) && str_contains( $private_repository, 'superseded_by = NULL' ), 'Private deletion must handle replacement relationships and storage together.' );
adam_document_history_assert( str_contains( $service, 'wp_delete_attachment( $attachment_id, true )' ), 'Safe Media Library deletion must use the WordPress API.' );

echo "Member document history smoke tests passed.\n";
