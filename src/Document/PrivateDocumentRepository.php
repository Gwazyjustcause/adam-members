<?php
/**
 * Private financial document repository.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

use AdamMembership\Helpers\Logger;
use WP_Error;

/** Persists document metadata without storing file contents in WordPress options. */
final class PrivateDocumentRepository {
	private ?Logger $logger;

	public function __construct( ?Logger $logger = null ) {
		$this->logger = $logger;
	}

	/** @return PrivateDocument|WP_Error */
	public function create( array $data ): PrivateDocument|WP_Error {
		global $wpdb;

		$reference = sanitize_text_field( (string) ( $data['request_reference'] ?? '' ) );
		$type      = sanitize_key( (string) ( $data['request_type'] ?? '' ) );
		$file_identifier = sanitize_text_field( (string) ( $data['file_identifier'] ?? '' ) );
		$this->trace( 'Private document replacement trace v1: repository create entered.', array_merge( array( 'stage' => 'repository.create' ), $this->identifier_diagnostic( $file_identifier, 'file_identifier' ) ) );
		if ( ! preg_match( '/^(registration|renewal):[A-Za-z0-9-]+$/', $reference ) || ! in_array( $type, array( 'registration', 'renewal' ), true ) || ! str_starts_with( $reference, $type . ':' ) ) {
			$this->trace( 'Private document replacement trace v1: repository request validation rejected.', array( 'stage' => 'repository.request_validation', 'error_code' => 'adam_private_document_invalid_request' ) );
			return new WP_Error( 'adam_private_document_invalid_request', __( 'A referência do pedido não é válida.', 'adam-membership' ) );
		}

		if ( ! preg_match( '/^[a-f0-9-]+\.pdf$/i', $file_identifier ) ) {
			$this->trace( 'Private document replacement trace v1: file_identifier validation rejected.', array_merge( array( 'stage' => 'repository.file_identifier_validation', 'error_code' => 'adam_private_document_invalid_identifier' ), $this->identifier_diagnostic( $file_identifier, 'file_identifier' ) ) );
			return new WP_Error( 'adam_private_document_invalid_identifier', __( 'O identificador físico do documento não é válido.', 'adam-membership' ) );
		}

		$now = current_time( 'mysql' );
		$active_key = array_key_exists( 'active_key', $data ) ? $data['active_key'] : $reference;
		if ( null !== $active_key && (string) $active_key !== $reference ) {
			return new WP_Error( 'adam_private_document_invalid_request', __( 'A associação ativa do documento não é válida.', 'adam-membership' ) );
		}
		$row = array(
			'request_reference' => $reference,
			'request_type'      => $type,
			'active_key'        => $active_key,
			'file_identifier'   => $file_identifier,
			'original_name'     => sanitize_file_name( (string) ( $data['original_name'] ?? '' ) ),
			'mime'              => sanitize_text_field( (string) ( $data['mime'] ?? '' ) ),
			'file_size'         => absint( $data['file_size'] ?? 0 ),
			'sha256'            => strtolower( sanitize_text_field( (string) ( $data['sha256'] ?? '' ) ) ),
			'document_status'   => 'active',
			'send_status'       => 'not_sent',
			'uploaded_by'       => absint( $data['uploaded_by'] ?? get_current_user_id() ),
			'created_at'        => $now,
			'updated_at'        => $now,
			'last_sent_at'      => null,
			'last_error'        => null,
			'superseded_by'     => null,
		);

		$format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' );
		if ( false === $wpdb->insert( PrivateDocumentSchema::table_name(), $row, $format ) ) {
			$this->trace( 'Private document replacement trace v1: repository INSERT failed.', array( 'stage' => 'repository.insert', 'error_code' => 'adam_private_document_create_failed' ) );
			return new WP_Error( 'adam_private_document_create_failed', __( 'Não foi possível guardar os metadados do documento.', 'adam-membership' ) );
		}

		$row['id'] = (int) $wpdb->insert_id;
		$this->trace( 'Private document replacement trace v1: repository INSERT succeeded.', array( 'stage' => 'repository.insert', 'document_id' => (int) $wpdb->insert_id ) );
		return new PrivateDocument( $row );
	}

	/** Store a file and create metadata, rolling the file back if the DB insert fails. */
	public function create_from_upload( array $data, array $file, PrivateDocumentStorage $storage ): PrivateDocument|WP_Error {
		$this->trace( 'Private document replacement trace v1: create_from_upload entered.', array( 'stage' => 'repository.create_from_upload', 'upload_error' => (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) );
		$stored = $storage->store_upload( $file );
		$this->trace( 'Private document replacement trace v1: returned immediately after store_upload.', array( 'stage' => 'repository.after_store_upload.raw', 'stored_type' => get_debug_type( $stored ) ) );
		if ( is_wp_error( $stored ) ) {
			$this->trace( 'Private document replacement trace v1: create_from_upload storage failed.', array( 'stage' => 'repository.create_from_upload.storage', 'error_code' => $stored->get_error_code() ) );
			return $stored;
		}
		$this->trace( 'Private document replacement trace v1: store_upload result accepted as metadata.', array( 'stage' => 'repository.after_store_upload.accepted', 'stored_is_array' => is_array( $stored ), 'identifier_key_present' => is_array( $stored ) && array_key_exists( 'identifier', $stored ) ) );
		$this->trace( 'Private document replacement trace v1: create_from_upload storage returned.', array_merge( array( 'stage' => 'repository.create_from_upload.storage_return' ), $this->identifier_diagnostic( (string) ( $stored['identifier'] ?? '' ), 'identifier' ) ) );

		return $this->persist_stored( $data, $stored, $storage );
	}

	/** Store a local source and create metadata, primarily for maintenance tooling. */
	public function create_from_source( array $data, string $source, string $original_name, PrivateDocumentStorage $storage ): PrivateDocument|WP_Error {
		$stored = $storage->store_source( $source, $original_name );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return $this->persist_stored( $data, $stored, $storage );
	}

	/** Replace the active document while preserving the previous version. */
	public function replace_from_upload( array $data, array $file, PrivateDocumentStorage $storage ): PrivateDocument|WP_Error {
		$stored              = null;
		$transaction_started = false;
		try {
		$this->trace( 'Private document replacement trace v1: replace_from_upload entered.', array( 'stage' => 'repository.replace_from_upload', 'upload_error' => (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) );
		$stored = $storage->store_upload( $file );
		$this->trace( 'Private document replacement trace v1: returned immediately after store_upload.', array( 'stage' => 'repository.replace_after_store_upload.raw', 'stored_type' => get_debug_type( $stored ) ) );
		if ( is_wp_error( $stored ) ) {
			$this->trace( 'Private document replacement trace v1: replacement storage failed.', array( 'stage' => 'repository.replace_from_upload.storage', 'error_code' => $stored->get_error_code() ) );
			return $stored;
		}
		$this->trace( 'Private document replacement trace v1: store_upload result accepted as metadata.', array( 'stage' => 'repository.replace_after_store_upload.accepted', 'stored_is_array' => is_array( $stored ), 'identifier_key_present' => is_array( $stored ) && array_key_exists( 'identifier', $stored ) ) );
		$this->trace( 'Private document replacement trace v1: replacement storage returned.', array_merge( array( 'stage' => 'repository.replace_from_upload.storage_return' ), $this->identifier_diagnostic( (string) ( $stored['identifier'] ?? '' ), 'identifier' ) ) );

		global $wpdb;
		$reference = (string) ( $data['request_reference'] ?? '' );
		$wpdb->query( 'START TRANSACTION' );
		$transaction_started = true;
		$current = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PrivateDocumentSchema::table_name() . ' WHERE active_key = %s LIMIT 1 FOR UPDATE', $reference ), ARRAY_A );
		$this->trace( 'Private document replacement trace v1: before reading stored identifier.', array( 'stage' => 'repository.replace_before_identifier_read', 'stored_is_array' => is_array( $stored ) ) );
		$file_identifier = (string) ( $stored['identifier'] ?? '' );
		$this->trace( 'Private document replacement trace v1: stored identifier read.', array_merge( array( 'stage' => 'repository.replace_after_identifier_read' ), $this->identifier_diagnostic( $file_identifier, 'identifier' ) ) );
		$this->trace( 'Private document replacement trace v1: identifier mapped to file_identifier.', array_merge( array( 'stage' => 'repository.identifier_mapping' ), $this->identifier_diagnostic( (string) ( $stored['identifier'] ?? '' ), 'identifier' ), $this->identifier_diagnostic( $file_identifier, 'file_identifier' ) ) );
		$result = $this->create( array_merge( $data, $stored, array( 'file_identifier' => $file_identifier, 'active_key' => is_array( $current ) ? null : $reference ) ) );
		if ( is_wp_error( $result ) ) {
			$this->trace( 'Private document replacement trace v1: new metadata INSERT rejected; rolling back.', array( 'stage' => 'repository.replace_from_upload.rollback', 'error_code' => $result->get_error_code() ) );
			$wpdb->query( 'ROLLBACK' );
			$storage->delete_identifier( (string) $stored['identifier'] );
			return $result;
		}
		if ( is_array( $current ) && false === $wpdb->update( PrivateDocumentSchema::table_name(), array( 'active_key' => null, 'document_status' => 'superseded', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => absint( $current['id'] ) ) ) ) {
			$this->trace( 'Private document replacement trace v1: previous document update failed; rolling back.', array( 'stage' => 'repository.replace_from_upload.supersede.rollback' ) );
			$wpdb->query( 'ROLLBACK' );
			$storage->delete_identifier( (string) $stored['identifier'] );
			return new WP_Error( 'adam_private_document_replace_failed', __( 'Não foi possível substituir o documento privado.', 'adam-membership' ) );
		}
		if ( is_array( $current ) && false === $wpdb->update( PrivateDocumentSchema::table_name(), array( 'superseded_by' => $result->id() ), array( 'id' => absint( $current['id'] ) ) ) ) {
			$this->trace( 'Private document replacement trace v1: superseded_by update failed; rolling back.', array( 'stage' => 'repository.replace_from_upload.history.rollback' ) );
			$wpdb->query( 'ROLLBACK' );
			$storage->delete_identifier( (string) $stored['identifier'] );
			return new WP_Error( 'adam_private_document_replace_failed', __( 'Não foi possível concluir a substituição do documento privado.', 'adam-membership' ) );
		}
		if ( is_array( $current ) && is_wp_error( $this->update( $result, array( 'active_key' => $reference ) ) ) ) {
			$this->trace( 'Private document replacement trace v1: new document activation failed; rolling back.', array( 'stage' => 'repository.replace_from_upload.activation.rollback' ) );
			$wpdb->query( 'ROLLBACK' );
			$storage->delete_identifier( (string) $stored['identifier'] );
			return new WP_Error( 'adam_private_document_replace_failed', __( 'Não foi possível ativar o novo documento privado.', 'adam-membership' ) );
		}
		$wpdb->query( 'COMMIT' );
		$this->trace( 'Private document replacement trace v1: replacement committed.', array( 'stage' => 'repository.replace_from_upload.commit', 'document_id' => $result->id() ) );

		return $result;
		} catch ( \Throwable $exception ) {
			if ( $transaction_started ) {
				$wpdb->query( 'ROLLBACK' );
			}
			if ( is_array( $stored ) ) {
				$storage->delete_identifier( (string) ( $stored['identifier'] ?? '' ) );
			}
			$this->logger?->error( 'Private document replacement trace v2: throwable caught.', array(
				'stage'             => 'repository.replace_from_upload.catch',
				'exception_class'    => get_class( $exception ),
				'exception_file'     => basename( $exception->getFile() ),
				'exception_line'     => $exception->getLine(),
				'exception_code'     => (string) $exception->getCode(),
				'cleanup_attempted'  => is_array( $stored ),
				'rollback_attempted' => $transaction_started,
			) );
			return new WP_Error( 'adam_private_document_replace_failed', __( 'Não foi possível substituir o documento privado.', 'adam-membership' ) );
		}
	}

	/** @param array<string, mixed> $stored */
	private function persist_stored( array $data, array $stored, PrivateDocumentStorage $storage ): PrivateDocument|WP_Error {
		$file_identifier = (string) ( $stored['identifier'] ?? '' );
		$this->trace( 'Private document replacement trace v1: identifier mapped to file_identifier.', array_merge( array( 'stage' => 'repository.persist_stored.identifier_mapping' ), $this->identifier_diagnostic( (string) ( $stored['identifier'] ?? '' ), 'identifier' ), $this->identifier_diagnostic( $file_identifier, 'file_identifier' ) ) );
		$result = $this->create( array_merge( $data, $stored, array( 'file_identifier' => $file_identifier ) ) );
		if ( is_wp_error( $result ) ) {
			$storage->delete_identifier( (string) $stored['identifier'] );
		}

		return $result;
	}

	/** @param mixed $value @return array<string, bool|int|string> */
	private function identifier_diagnostic( mixed $value, string $field ): array {
		$identifier = is_string( $value ) ? $value : '';
		return array(
			$field . '_present'     => '' !== $identifier,
			$field . '_length'      => strlen( $identifier ),
			$field . '_fingerprint' => hash( 'sha256', $identifier ),
			$field . '_has_pdf_shape' => 1 === preg_match( '/^[a-f0-9-]+\.pdf$/i', $identifier ),
		);
	}

	private function trace( string $message, array $context = array() ): void {
		if ( null !== $this->logger ) {
			$this->logger->info( $message, $context );
		}
	}

	public function find( int $id ): ?PrivateDocument {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PrivateDocumentSchema::table_name() . ' WHERE id = %d', $id ), ARRAY_A );

		return is_array( $row ) ? new PrivateDocument( $row ) : null;
	}

	public function find_active( string $request_reference ): ?PrivateDocument {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PrivateDocumentSchema::table_name() . ' WHERE active_key = %s LIMIT 1', $request_reference ), ARRAY_A );

		return is_array( $row ) ? new PrivateDocument( $row ) : null;
	}

	/** @param array<int,string> $references @return array<int,PrivateDocument> */
	public function for_references( array $references ): array {
		global $wpdb;
		$references = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $references ) ) ) );
		if ( array() === $references ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $references ), '%s' ) );
		$query = $wpdb->prepare( 'SELECT * FROM ' . PrivateDocumentSchema::table_name() . " WHERE request_reference IN ({$placeholders}) ORDER BY created_at ASC, id ASC", ...$references );
		$rows  = $wpdb->get_results( $query, ARRAY_A );

		return array_values(
			array_map(
				static fn ( array $row ): PrivateDocument => new PrivateDocument( $row ),
				is_array( $rows ) ? $rows : array()
			)
		);
	}

	/** @return array<int,PrivateDocument> */
	public function for_file_identifier( string $identifier ): array {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9-]+\.pdf$/i', $identifier ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . PrivateDocumentSchema::table_name() . ' WHERE file_identifier = %s ORDER BY id ASC', $identifier ), ARRAY_A );
		return array_values( array_map( static fn ( array $row ): PrivateDocument => new PrivateDocument( $row ), is_array( $rows ) ? $rows : array() ) );
	}

	/** @param array<string, mixed> $data */
	public function update( PrivateDocument $document, array $data ): PrivateDocument|WP_Error {
		global $wpdb;
		$allowed = array( 'document_status', 'send_status', 'last_sent_at', 'last_error', 'superseded_by', 'active_key', 'updated_at' );
		$changes = array_intersect_key( $data, array_flip( $allowed ) );
		if ( array() === $changes ) {
			return $document;
		}
		$changes['updated_at'] = $changes['updated_at'] ?? current_time( 'mysql' );
		if ( false === $wpdb->update( PrivateDocumentSchema::table_name(), $changes, array( 'id' => $document->id() ) ) ) {
			return new WP_Error( 'adam_private_document_update_failed', __( 'Não foi possível atualizar os metadados do documento.', 'adam-membership' ) );
		}

		return new PrivateDocument( array_merge( $document->data(), $changes ) );
	}

	public function mark_orphaned( PrivateDocument $document ): PrivateDocument|WP_Error {
		return $this->update( $document, array( 'active_key' => null, 'document_status' => 'orphaned' ) );
	}

	public function mark_superseded( PrivateDocument $document, int $replacement_id ): PrivateDocument|WP_Error {
		return $this->update( $document, array( 'active_key' => null, 'document_status' => 'superseded', 'superseded_by' => $replacement_id ) );
	}

	/** Permanently remove one already-audited metadata row. */
	public function delete( PrivateDocument $document ): true|WP_Error {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . PrivateDocumentSchema::table_name() . ' SET superseded_by = NULL WHERE superseded_by = %d', $document->id() ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'adam_private_document_delete_failed', __( 'Não foi possível atualizar as relações do documento.', 'adam-membership' ) );
		}
		if ( false === $wpdb->delete( PrivateDocumentSchema::table_name(), array( 'id' => $document->id() ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'adam_private_document_delete_failed', __( 'Não foi possível remover o registo do documento.', 'adam-membership' ) );
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}

	/** Delete the metadata and physical file in one guarded operation. */
	public function delete_with_storage( PrivateDocument $document, PrivateDocumentStorage $storage ): true|WP_Error {
		$path = $storage->path( $document );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . PrivateDocumentSchema::table_name() . ' SET superseded_by = NULL WHERE superseded_by = %d', $document->id() ) ) || false === $wpdb->delete( PrivateDocumentSchema::table_name(), array( 'id' => $document->id() ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'adam_private_document_delete_failed', __( 'Não foi possível remover o registo do documento.', 'adam-membership' ) );
		}
		try {
			$storage->delete_identifier( $document->file_identifier() );
		} catch ( \Throwable $exception ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'adam_private_document_delete_failed', __( 'Não foi possível eliminar o ficheiro privado.', 'adam-membership' ) );
		}
		if ( is_file( $path ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'adam_private_document_delete_failed', __( 'Não foi possível eliminar o ficheiro privado.', 'adam-membership' ) );
		}
		$wpdb->query( 'COMMIT' );
		return true;
	}
}
