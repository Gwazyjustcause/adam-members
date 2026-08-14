<?php
/**
 * Authenticated private document downloads.
 *
 * @package AdamMembership\Admin
 */

declare(strict_types=1);

namespace AdamMembership\Admin;

use AdamMembership\Document\PrivateDocumentRepository;
use AdamMembership\Document\PrivateDocumentStorage;

/** Serves private documents only to administrators with a valid nonce. */
final class PrivateDocumentDownloadController {
	public function __construct( private PrivateDocumentRepository $documents, private PrivateDocumentStorage $storage ) {}

	public function register(): void { add_action( 'admin_post_adam_membership_download_private_document', array( $this, 'handle_download' ) ); }

	public function download_url( int $document_id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'adam_membership_download_private_document', 'document_id' => $document_id ), admin_url( 'admin-post.php' ) ), 'adam_membership_download_private_document_' . $document_id );
	}

	public function handle_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão para consultar este documento.', 'adam-membership' ), '', array( 'response' => 403 ) );
		}
		$id = absint( $_GET['document_id'] ?? 0 );
		check_admin_referer( 'adam_membership_download_private_document_' . $id );
		$document = $this->documents->find( $id );
		$path     = null !== $document ? $this->storage->path( $document ) : new \WP_Error( 'adam_private_document_not_found' );
		if ( is_wp_error( $path ) ) {
			wp_die( esc_html__( 'Documento privado não encontrado.', 'adam-membership' ), '', array( 'response' => 404 ) );
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $document->original_name() ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, private' );
		readfile( $path );
		exit;
	}
}
