<?php
/**
 * Static and model/storage smoke tests for private financial documents.
 *
 * This test does not boot WordPress, create a database table, or touch a
 * production filesystem. It verifies the implementation contracts that are
 * independent of a WordPress runtime.
 *
 * @package AdamMembership\Tests
 */

declare(strict_types=1);

function absint( mixed $value ): int {
	return abs( (int) $value );
}

function sanitize_text_field( string $value ): string {
	return trim( $value );
}

function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ?? '' );
}

function sanitize_file_name( string $value ): string {
	return preg_replace( '/[^a-zA-Z0-9._-]/', '_', basename( $value ) ) ?? '';
}

function current_time( string $type ): string {
	unset( $type );

	return '2026-08-14 12:00:00';
}

function get_current_user_id(): int {
	return 7;
}

function wp_generate_uuid4(): string {
	return sprintf( '%08x-%04x-%04x-%04x-%012x', random_int( 0, 0xffffffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, PHP_INT_MAX ) );
}

function wp_mkdir_p( string $target ): bool {
	return is_dir( $target ) || mkdir( $target, 0700, true );
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $text, string $domain = '' ): string {
	unset( $domain );

	return $text;
}

final class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
}

require_once dirname( __DIR__ ) . '/src/Document/PrivateDocumentSchema.php';
require_once dirname( __DIR__ ) . '/src/Document/PrivateDocument.php';
require_once dirname( __DIR__ ) . '/src/Document/PrivateDocumentRepository.php';
require_once dirname( __DIR__ ) . '/src/Document/PrivateDocumentStorage.php';

use AdamMembership\Document\PrivateDocument;
use AdamMembership\Document\PrivateDocumentRepository;
use AdamMembership\Document\PrivateDocumentSchema;
use AdamMembership\Document\PrivateDocumentStorage;

function adam_private_documents_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$schema = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/PrivateDocumentSchema.php' );
$storage = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/PrivateDocumentStorage.php' );
$download = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/PrivateDocumentDownloadController.php' );

adam_private_documents_assert( str_contains( $schema, 'dbDelta( $sql )' ), 'Schema must use dbDelta().' );
adam_private_documents_assert( str_contains( $schema, 'adam_membership_private_documents_schema_version' ), 'Schema must have a version option.' );
adam_private_documents_assert( str_contains( $schema, 'UNIQUE KEY active_request (active_key)' ), 'Schema must prevent two active documents for one request.' );
adam_private_documents_assert( str_contains( $schema, 'last_error text' ) && str_contains( $schema, 'superseded_by' ), 'Schema must retain safe error and replacement history metadata.' );
adam_private_documents_assert( str_contains( $storage, 'ADAM_PRIVATE_DOCUMENTS_PATH' ) && str_contains( $storage, 'ADAM_PRIVATE_DOCUMENT_MAX_BYTES' ), 'Storage must use the approved configuration constants.' );
adam_private_documents_assert( str_contains( $storage, 'new \\finfo' ) && str_contains( $storage, "'%PDF'" ), 'Storage must validate real MIME and PDF signature.' );
adam_private_documents_assert( ! str_contains( $storage, 'media_handle_upload' ) && ! str_contains( $storage, 'wp_upload_dir' ), 'Private storage must not fall back to public WordPress uploads.' );
adam_private_documents_assert( str_contains( $storage, 'rename( $temp, $target )' ) && str_contains( $storage, 'file_exists( $target )' ), 'Storage must finalize through a non-overwriting rename.' );
adam_private_documents_assert( str_contains( $storage, 'delete_identifier' ), 'Storage must support rollback after a metadata failure.' );
adam_private_documents_assert( str_contains( $schema, 'get_charset_collate' ), 'Schema must use WordPress charset and collation.' );
adam_private_documents_assert( str_contains( $download, 'Cache-Control: no-store, private' ), 'Downloads must disable caching.' );
adam_private_documents_assert( str_contains( $download, "current_user_can( 'manage_options' )" ) && str_contains( $download, 'check_admin_referer' ), 'Downloads must require capability and nonce.' );

$document = new PrivateDocument(
	array(
		'id'                => 9,
		'request_reference' => 'renewal:example',
		'request_type'      => 'renewal',
		'active_key'        => 'renewal:example',
		'file_identifier'   => '123e4567-e89b-12d3-a456-426614174000.pdf',
		'original_name'     => 'quota-2026.pdf',
		'mime'              => 'application/pdf',
		'file_size'         => 1024,
		'sha256'            => str_repeat( 'a', 64 ),
		'document_status'   => 'active',
		'send_status'       => 'not_sent',
	)
);
adam_private_documents_assert( 9 === $document->id() && $document->active(), 'PrivateDocument must expose identity and active state.' );
adam_private_documents_assert( 'renewal:example' === $document->request_reference(), 'PrivateDocument must preserve canonical request reference.' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adam-wp-root' );
}
$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
$private_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adam-private-documents-smoke-' . bin2hex( random_bytes( 4 ) );
$source_root  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adam-private-documents-source-' . bin2hex( random_bytes( 4 ) );
mkdir( $source_root, 0700, true );
define( 'ADAM_PRIVATE_DOCUMENTS_PATH', $private_root );
define( 'ADAM_PRIVATE_DOCUMENT_MAX_BYTES', 1024 );
$storage_service = new PrivateDocumentStorage();
$path_result     = $storage_service->path( $document );
adam_private_documents_assert( is_wp_error( $path_result ), 'Missing private storage must return a safe error.' );

$valid_source = $source_root . DIRECTORY_SEPARATOR . 'valid.bin';
file_put_contents( $valid_source, "%PDF-1.7\nvalid test document" );
$stored = $storage_service->store_source( $valid_source, 'invoice.pdf' );
if ( class_exists( 'finfo' ) ) {
	adam_private_documents_assert( is_array( $stored ) && 'application/pdf' === $stored['mime'], 'A valid PDF must be stored.' );
	$stored_again = $storage_service->store_source( $valid_source, 'invoice.pdf' );
	adam_private_documents_assert( is_array( $stored_again ) && $stored['identifier'] !== $stored_again['identifier'], 'A second upload must not overwrite the first file.' );
} else {
	adam_private_documents_assert( is_wp_error( $stored ), 'Missing MIME validation support must fail safely.' );
}

$fake_source = $source_root . DIRECTORY_SEPARATOR . 'fake.pdf';
file_put_contents( $fake_source, 'not a pdf' );
adam_private_documents_assert( is_wp_error( $storage_service->store_source( $fake_source, 'fake.pdf' ) ), 'A false PDF must be rejected.' );
adam_private_documents_assert( is_wp_error( $storage_service->store_source( $valid_source, 'fake.txt' ) ), 'A non-PDF extension must be rejected.' );
$too_large = $source_root . DIRECTORY_SEPARATOR . 'large.pdf';
file_put_contents( $too_large, "%PDF-1.7\n" . str_repeat( 'x', 1100 ) );
adam_private_documents_assert( is_wp_error( $storage_service->store_source( $too_large, 'large.pdf' ) ), 'An oversized file must be rejected.' );

$traversal = new PrivateDocument( array_merge( $document->data(), array( 'file_identifier' => '../outside.pdf' ) ) );
adam_private_documents_assert( is_wp_error( $storage_service->path( $traversal ) ), 'Path traversal must be rejected.' );

if ( class_exists( 'finfo' ) ) {
	$GLOBALS['wpdb'] = new class {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public function insert( string $table, array $data, array $format ): false { unset( $table, $data, $format ); return false; }
	};
	$rollback = ( new PrivateDocumentRepository() )->create_from_source(
		array( 'request_reference' => 'renewal:rollback', 'request_type' => 'renewal' ),
		$valid_source,
		'rollback.pdf',
		$storage_service
	);
	adam_private_documents_assert( is_wp_error( $rollback ), 'A database failure must return an error.' );
	adam_private_documents_assert( 0 === count( glob( $private_root . DIRECTORY_SEPARATOR . '*.pdf' ) ?: array() ), 'A database failure must roll back the newly stored file.' );
}

foreach ( glob( $private_root . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT ) ?: array() as $path ) { if ( is_file( $path ) ) { unlink( $path ); } }
foreach ( glob( $source_root . DIRECTORY_SEPARATOR . '*', GLOB_NOSORT ) ?: array() as $path ) { if ( is_file( $path ) ) { unlink( $path ); } }
rmdir( $private_root );
rmdir( $source_root );

adam_private_documents_assert( 'adam_membership_documents' === PrivateDocumentSchema::table_name() || str_ends_with( PrivateDocumentSchema::table_name(), 'adam_membership_documents' ), 'Schema table name must be site-prefixed.' );

echo "Private documents smoke tests passed.\n";
