<?php
/**
 * Resolves the Media Library documents selected for an announcement email.
 *
 * @package AdamMembership\Announcement
 */

declare(strict_types=1);

namespace AdamMembership\Announcement;

use WP_Error;

/** Validates and resolves attachments before an announcement is sent. */
final class AnnouncementAttachments {
	/**
	 * The same document types are available in the media picker and at delivery.
	 *
	 * @return array<string,string>
	 */
	public static function mimes(): array {
		$extensions = array( 'pdf', 'doc', 'docx', 'odt', 'rtf', 'csv', 'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp' );

		return array_intersect_key( wp_get_mime_types(), array_flip( $extensions ) );
	}

	public static function filename( int $id ): string {
		$file = get_attached_file( $id );

		return $file ? wp_basename( $file ) : sprintf( __( 'Documento #%d', 'adam-membership' ), $id );
	}

	/** @return array<array-key,string>|WP_Error Named paths for wp_mail(). */
	public static function resolve( Announcement $announcement ): array|WP_Error {
		$ids = $announcement->email_attachment_ids();
		if ( array() === $ids ) {
			return array();
		}

		$base_dir = wp_get_upload_dir()['basedir'] ?? '';
		$base     = '' !== $base_dir ? realpath( $base_dir ) : false;
		$root     = false === $base ? '' : trailingslashit( wp_normalize_path( $base ) );
		$files = array();
		$mimes = self::mimes();

		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			$path = is_string( $file ) && '' !== $file ? realpath( $file ) : false;
			$type = $file ? wp_check_filetype( $file, $mimes )['type'] : false;

			if ( 'attachment' !== get_post_type( $id ) || ! $type || get_post_mime_type( $id ) !== $type || false === $path || '' === $root || ! str_starts_with( wp_normalize_path( $path ), $root ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				return new WP_Error( 'adam_announcement_attachment_unavailable', sprintf( __( 'O anexo «%s» não está disponível como documento na Biblioteca multimédia. Remova-o ou selecione outro ficheiro.', 'adam-membership' ), self::filename( $id ) ) );
			}

			$name = wp_basename( $file );
			if ( isset( $files[ $name ] ) ) {
				// A numeric key lets WordPress derive the filename without replacing the first file.
				$files[] = $path;
			} else {
				$files[ $name ] = $path;
			}
		}

		return $files;
	}
}
