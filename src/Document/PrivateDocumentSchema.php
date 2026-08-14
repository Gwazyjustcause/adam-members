<?php
/**
 * Private financial-document database schema.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

/** Installs and upgrades the private document table. */
final class PrivateDocumentSchema {
	private const VERSION        = '1.0.0';
	private const VERSION_OPTION = 'adam_membership_private_documents_schema_version';

	/** Install the current schema. */
	public static function install(): bool {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_reference varchar(191) NOT NULL,
			request_type varchar(20) NOT NULL,
			active_key varchar(191) DEFAULT NULL,
			file_identifier varchar(191) NOT NULL,
			original_name varchar(255) NOT NULL,
			mime varchar(100) NOT NULL,
			file_size bigint(20) unsigned NOT NULL,
			sha256 char(64) NOT NULL,
			document_status varchar(20) NOT NULL DEFAULT 'active',
			send_status varchar(20) NOT NULL DEFAULT 'not_sent',
			uploaded_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			last_sent_at datetime DEFAULT NULL,
			last_error text DEFAULT NULL,
			superseded_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY active_request (active_key),
			KEY request_reference (request_reference),
			KEY request_type (request_type),
			KEY document_status (document_status),
			KEY uploaded_by (uploaded_by)
		) {$charset_collate};";

		try {
			dbDelta( $sql );
		} catch ( \Throwable $exception ) {
			update_option( 'adam_membership_private_documents_schema_error', 'Não foi possível instalar o armazenamento de documentos privados.', false );
			if ( is_admin() ) {
				add_action(
					'admin_notices',
					static function (): void {
						printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'O armazenamento privado de documentos não está disponível. O site continua operacional; contacte um administrador técnico.', 'adam-membership' ) );
					}
				);
			}

			return false;
		}
		if ( self::table_exists() ) {
			update_option( self::VERSION_OPTION, self::VERSION, false );
			delete_option( 'adam_membership_private_documents_schema_error' );

			return true;
		}

		update_option( 'adam_membership_private_documents_schema_error', 'Não foi possível instalar o armazenamento de documentos privados.', false );
		if ( is_admin() ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'O armazenamento privado de documentos não está disponível. O site continua operacional; contacte um administrador técnico.', 'adam-membership' ) );
				}
			);
		}

		return false;
	}

	/** Install or upgrade when the stored schema version is stale. */
	public static function maybe_install(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	/** Get the site-specific table name. */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'adam_membership_documents';
	}

	private static function table_exists(): bool {
		global $wpdb;

		return self::table_name() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table_name() ) ) );
	}
}
