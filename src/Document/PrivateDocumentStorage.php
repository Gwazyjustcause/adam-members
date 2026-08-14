<?php
/**
 * Private financial document storage and validation.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

use WP_Error;

/** Stores PDF files outside the public uploads directory. */
final class PrivateDocumentStorage {
	private const PDF_SIGNATURE = '%PDF';

	/** @return array{identifier:string,original_name:string,mime:string,file_size:int,sha256:string}|WP_Error */
	public function store_upload( array $file ): array|WP_Error {
		$directory = $this->directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		if ( ! isset( $file['tmp_name'], $file['error'], $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'adam_private_document_upload_invalid', __( 'O upload do documento não é válido.', 'adam-membership' ) );
		}

		$temp = $this->temporary_path( $directory );
		if ( ! move_uploaded_file( (string) $file['tmp_name'], $temp ) ) {
			return new WP_Error( 'adam_private_document_store_failed', __( 'Não foi possível guardar temporariamente o documento privado.', 'adam-membership' ) );
		}

		return $this->finalize_temp_file( $temp, (string) $file['name'], $directory );
	}

	/** Store a local source file for controlled tests and maintenance tooling. */
	public function store_source( string $source, string $original_name ): array|WP_Error {
		$directory = $this->directory();
		if ( is_wp_error( $directory ) || ! is_file( $source ) || ! is_readable( $source ) ) {
			return is_wp_error( $directory ) ? $directory : new WP_Error( 'adam_private_document_source_invalid', __( 'A origem do documento não é válida.', 'adam-membership' ) );
		}

		$temp = $this->temporary_path( $directory );
		if ( ! copy( $source, $temp ) ) {
			return new WP_Error( 'adam_private_document_store_failed', __( 'Não foi possível guardar temporariamente o documento privado.', 'adam-membership' ) );
		}

		return $this->finalize_temp_file( $temp, $original_name, $directory );
	}

	public function delete_identifier( string $identifier ): void {
		$directory = $this->directory( false );
		if ( is_wp_error( $directory ) || '' === $identifier || basename( $identifier ) !== $identifier ) {
			return;
		}
		$path = realpath( $directory . DIRECTORY_SEPARATOR . $identifier );
		if ( false !== $path && $this->is_within( $path, $directory ) && is_file( $path ) ) {
			unlink( $path );
		}
	}

	public function path( PrivateDocument $document ): string|WP_Error {
		$directory = $this->directory( false );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}
		$identifier = basename( $document->file_identifier() );
		if ( '' === $identifier || $identifier !== $document->file_identifier() || ! preg_match( '/^[a-f0-9-]+\.pdf$/i', $identifier ) ) {
			return new WP_Error( 'adam_private_document_invalid_identifier', __( 'O identificador do documento não é válido.', 'adam-membership' ) );
		}
		$path = $directory . DIRECTORY_SEPARATOR . $identifier;
		$resolved_path = realpath( $path );
		if ( false === $resolved_path || ! $this->is_within( $resolved_path, $directory ) || ! is_file( $resolved_path ) || ! is_readable( $resolved_path ) || ! $this->is_pdf_file( $resolved_path ) ) {
			return new WP_Error( 'adam_private_document_unavailable', __( 'O documento privado não está disponível.', 'adam-membership' ) );
		}

		return $resolved_path;
	}

	/** @return array{identifier:string,original_name:string,mime:string,file_size:int,sha256:string}|WP_Error */
	private function finalize_temp_file( string $temp, string $original_name, string $directory ): array|WP_Error {
		$validation = $this->validate_file( $temp, $original_name );
		if ( is_wp_error( $validation ) ) {
			unlink( $temp );
			return $validation;
		}
		$identifier = wp_generate_uuid4() . '.pdf';
		$target     = $directory . DIRECTORY_SEPARATOR . $identifier;
		if ( file_exists( $target ) || ! rename( $temp, $target ) ) {
			unlink( $temp );
			return new WP_Error( 'adam_private_document_store_failed', __( 'Não foi possível finalizar o documento privado.', 'adam-membership' ) );
		}
		if ( ! $this->restrict_permissions( $target ) ) {
			unlink( $target );
			return new WP_Error( 'adam_private_document_permissions_failed', __( 'As permissões do documento privado não são seguras.', 'adam-membership' ) );
		}
		$final_size = (int) filesize( $target );
		if ( $final_size <= 0 || $final_size > $this->max_size() ) {
			unlink( $target );
			return new WP_Error( 'adam_private_document_pdf_invalid', __( 'O documento excede o tamanho permitido.', 'adam-membership' ) );
		}

		return array( 'identifier' => $identifier, 'original_name' => sanitize_file_name( $original_name ), 'mime' => 'application/pdf', 'file_size' => $final_size, 'sha256' => (string) hash_file( 'sha256', $target ) );
	}

	/** @return true|WP_Error */
	private function validate_file( string $path, string $original_name ): true|WP_Error {
		if ( 'pdf' !== strtolower( (string) pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'adam_private_document_pdf_invalid', __( 'O documento deve ser um PDF válido.', 'adam-membership' ) );
		}
		$max_size = defined( 'ADAM_PRIVATE_DOCUMENT_MAX_BYTES' ) ? absint( ADAM_PRIVATE_DOCUMENT_MAX_BYTES ) : 10 * 1024 * 1024;
		$size     = filesize( $path );
		if ( false === $size || $size <= 0 || $size > $max_size ) {
			return new WP_Error( 'adam_private_document_pdf_invalid', __( 'O documento deve ser um PDF válido até ao tamanho máximo configurado.', 'adam-membership' ) );
		}
		if ( ! class_exists( 'finfo' ) ) {
			return new WP_Error( 'adam_private_document_mime_unavailable', __( 'Não foi possível validar o tipo MIME do documento.', 'adam-membership' ) );
		}
		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$mime  = $finfo->file( $path );
		$handle = fopen( $path, 'rb' );
		$prefix = false !== $handle ? fread( $handle, 4 ) : '';
		if ( false !== $handle ) { fclose( $handle ); }
		if ( 'application/pdf' !== $mime || self::PDF_SIGNATURE !== $prefix || (int) filesize( $path ) !== $size ) {
			return new WP_Error( 'adam_private_document_pdf_invalid', __( 'O documento não passou a validação de segurança PDF.', 'adam-membership' ) );
		}

		return true;
	}

	private function temporary_path( string $directory ): string { return $directory . DIRECTORY_SEPARATOR . '.upload-' . wp_generate_uuid4() . '.tmp'; }
	private function max_size(): int { return defined( 'ADAM_PRIVATE_DOCUMENT_MAX_BYTES' ) ? absint( ADAM_PRIVATE_DOCUMENT_MAX_BYTES ) : 10 * 1024 * 1024; }
	private function restrict_permissions( string $path ): bool {
		$directory = dirname( $path );
		$directory_ok = DIRECTORY_SEPARATOR !== '/' || chmod( $directory, 0700 );
		$file_ok      = DIRECTORY_SEPARATOR !== '/' || chmod( $path, 0600 );
		return $directory_ok && $file_ok;
	}

	private function directory( bool $create = true ): string|WP_Error {
		if ( ! defined( 'ADAM_PRIVATE_DOCUMENTS_PATH' ) || '' === trim( (string) ADAM_PRIVATE_DOCUMENTS_PATH ) || ! $this->is_absolute_path( (string) ADAM_PRIVATE_DOCUMENTS_PATH ) ) {
			return new WP_Error( 'adam_private_document_path_missing', __( 'O armazenamento privado de documentos não está configurado.', 'adam-membership' ) );
		}
		$directory = rtrim( (string) ADAM_PRIVATE_DOCUMENTS_PATH, '\\/ ' );
		$parent    = realpath( dirname( $directory ) );
		$webroot   = realpath( ABSPATH );
		if ( false === $parent || ( false !== $webroot && $this->is_within( $directory, $webroot ) ) ) {
			return new WP_Error( 'adam_private_document_path_unsafe', __( 'O caminho do armazenamento privado não é seguro.', 'adam-membership' ) );
		}
		if ( ! is_dir( $directory ) && $create && ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'adam_private_document_directory_failed', __( 'Não foi possível criar o armazenamento privado de documentos.', 'adam-membership' ) );
		}
		if ( ! is_dir( $directory ) || ! is_readable( $directory ) || ! is_writable( $directory ) ) {
			return new WP_Error( 'adam_private_document_directory_unavailable', __( 'O diretório de documentos privados não está disponível.', 'adam-membership' ) );
		}
		$resolved_directory = realpath( $directory );
		if ( false === $resolved_directory || ( false !== $webroot && $this->is_within( $resolved_directory, $webroot ) ) ) {
			return new WP_Error( 'adam_private_document_path_unsafe', __( 'O caminho do armazenamento privado não é seguro.', 'adam-membership' ) );
		}
		if ( DIRECTORY_SEPARATOR === '/' && ! chmod( $resolved_directory, 0700 ) ) {
			return new WP_Error( 'adam_private_document_permissions_failed', __( 'As permissões do armazenamento privado não são seguras.', 'adam-membership' ) );
		}

		return $resolved_directory;
	}

	private function is_absolute_path( string $path ): bool { return str_starts_with( $path, '/' ) || (bool) preg_match( '/^[A-Za-z]:[\\\\\/]/', $path ); }
	private function is_within( string $path, string $root ): bool { $path = rtrim( str_replace( '\\', '/', $path ), '/' ) . '/'; $root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/'; return str_starts_with( strtolower( $path ), strtolower( $root ) ); }
	private function is_pdf_file( string $path ): bool {
		$handle = fopen( $path, 'rb' );
		$prefix = false !== $handle ? fread( $handle, 4 ) : '';
		if ( false !== $handle ) {
			fclose( $handle );
		}
		if ( '%PDF' !== $prefix ) {
			return false;
		}
		$finfo = class_exists( 'finfo' ) ? new \finfo( FILEINFO_MIME_TYPE ) : false;
		$mime  = false !== $finfo ? $finfo->file( $path ) : '';

		return 'application/pdf' === $mime;
	}
}
