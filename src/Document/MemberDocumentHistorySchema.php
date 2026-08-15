<?php
/**
 * Explicit archive markers for the member document history view.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

/** Stores logical history removals without changing source documents or files. */
final class MemberDocumentHistorySchema {
	private const VERSION = '1.0.0';
	private const VERSION_OPTION = 'adam_membership_document_history_schema_version';

	public static function maybe_install(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	public static function install(): bool {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$sql = "CREATE TABLE " . self::table_name() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			member_id bigint(20) unsigned NOT NULL,
			history_key varchar(191) NOT NULL,
			source_type varchar(20) NOT NULL,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			archived_at datetime NOT NULL,
			archived_by bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY member_history (member_id, history_key),
			KEY member_id (member_id),
			KEY source (source_type, source_id)
		) " . $wpdb->get_charset_collate() . ";";

		try {
			dbDelta( $sql );
		} catch ( \Throwable $exception ) {
			update_option( 'adam_membership_document_history_schema_error', 'Não foi possível instalar o arquivo histórico de documentos.', false );
			if ( is_admin() ) {
				add_action( 'admin_notices', static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'O arquivo histórico de documentos não está disponível. O site continua operacional; contacte um administrador técnico.', 'adam-membership' ) . '</p></div>';
				} );
			}
			return false;
		}

		$exists = self::table_name() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table_name() ) ) );
		if ( ! $exists ) {
			update_option( 'adam_membership_document_history_schema_error', 'Não foi possível instalar o arquivo histórico de documentos.', false );
			return false;
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
		delete_option( 'adam_membership_document_history_schema_error' );
		return true;
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'adam_membership_document_history';
	}
}
