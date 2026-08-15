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

adam_document_history_assert( str_contains( $admin, 'Ver histórico de documentos' ), 'Member details must expose the history action.' );
adam_document_history_assert( str_contains( $admin, 'MEMBER_DOCUMENT_HISTORY_PAGE_SLUG' ), 'History must use a dedicated member document page.' );
adam_document_history_assert( ! str_contains( $admin, 'foreach ( $member_requests as $request )' ), 'Member details must not render every historical renewal section.' );
adam_document_history_assert( str_contains( $service, 'registration_fields' ) && str_contains( $service, 'renewal_fields' ), 'History must aggregate registration and renewal media fields.' );
adam_document_history_assert( str_contains( $service, 'document_status' ) && str_contains( $service, 'for_references' ), 'History must include private-document versions through request references.' );
adam_document_history_assert( str_contains( $repository, 'for_references' ), 'Private repository must support historical lookup by request references.' );

echo "Member document history smoke tests passed.\n";
