<?php
/**
 * Private financial document repository.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

use WP_Error;

/** Persists document metadata without storing file contents in WordPress options. */
final class PrivateDocumentRepository {
	/** @return PrivateDocument|WP_Error */
	public function create( array $data ): PrivateDocument|WP_Error {
		global $wpdb;

		$reference = sanitize_text_field( (string) ( $data['request_reference'] ?? '' ) );
		$type      = sanitize_key( (string) ( $data['request_type'] ?? '' ) );
		if ( ! preg_match( '/^(registration|renewal):[A-Za-z0-9-]+$/', $reference ) || ! in_array( $type, array( 'registration', 'renewal' ), true ) || ! str_starts_with( $reference, $type . ':' ) ) {
			return new WP_Error( 'adam_private_document_invalid_request', __( 'A referência do pedido não é válida.', 'adam-membership' ) );
		}

		$now = current_time( 'mysql' );
		$row = array(
			'request_reference' => $reference,
			'request_type'      => $type,
			'active_key'        => $reference,
			'file_identifier'   => sanitize_text_field( (string) ( $data['file_identifier'] ?? '' ) ),
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
			return new WP_Error( 'adam_private_document_create_failed', __( 'Não foi possível guardar os metadados do documento.', 'adam-membership' ) );
		}

		$row['id'] = (int) $wpdb->insert_id;
		return new PrivateDocument( $row );
	}

	/** Store a file and create metadata, rolling the file back if the DB insert fails. */
	public function create_from_upload( array $data, array $file, PrivateDocumentStorage $storage ): PrivateDocument|WP_Error {
		$stored = $storage->store_upload( $file );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

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
		$stored = $storage->store_upload( $file );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		global $wpdb;
		$reference = (string) ( $data['request_reference'] ?? '' );
		$wpdb->query( 'START TRANSACTION' );
		$current = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PrivateDocumentSchema::table_name() . ' WHERE active_key = %s LIMIT 1 FOR UPDATE', $reference ), ARRAY_A );
		if ( is_array( $current ) && false === $wpdb->update( PrivateDocumentSchema::table_name(), array( 'active_key' => null, 'document_status' => 'superseded', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => absint( $current['id'] ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			$storage->delete_identifier( (string) $stored['identifier'] );
			return new WP_Error( 'adam_private_document_replace_failed', __( 'Não foi possível substituir o documento privado.', 'adam-membership' ) );
		}

		$result = $this->create( array_merge( $data, $stored ) );
		if ( is_wp_error( $result ) ) {
			$wpdb->query( 'ROLLBACK' );
			$storage->delete_identifier( (string) $stored['identifier'] );
			return $result;
		}
		if ( is_array( $current ) && false === $wpdb->update( PrivateDocumentSchema::table_name(), array( 'superseded_by' => $result->id() ), array( 'id' => absint( $current['id'] ) ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			$storage->delete_identifier( (string) $stored['identifier'] );
			return new WP_Error( 'adam_private_document_replace_failed', __( 'Não foi possível concluir a substituição do documento privado.', 'adam-membership' ) );
		}
		$wpdb->query( 'COMMIT' );

		return $result;
	}

	/** @param array<string, mixed> $stored */
	private function persist_stored( array $data, array $stored, PrivateDocumentStorage $storage ): PrivateDocument|WP_Error {
		$result = $this->create( array_merge( $data, $stored ) );
		if ( is_wp_error( $result ) ) {
			$storage->delete_identifier( (string) $stored['identifier'] );
		}

		return $result;
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
}
