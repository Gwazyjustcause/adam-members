<?php
/**
 * Repository for logical document-history archive markers.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

use WP_Error;

/** Archives history entries without deleting or mutating their source records. */
final class MemberDocumentHistoryRepository {
	/** @return array<int,string> */
	public function archived_keys( int $member_id ): array {
		global $wpdb;
		if ( $member_id <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT history_key FROM ' . MemberDocumentHistorySchema::table_name() . ' WHERE member_id = %d', $member_id ) );
		return array_values( array_filter( array_map( 'strval', is_array( $rows ) ? $rows : array() ) ) );
	}

	/** @return true|WP_Error */
	public function archive( int $member_id, string $history_key, string $source_type, int $source_id ): true|WP_Error {
		global $wpdb;
		$history_key = sanitize_text_field( $history_key );
		$source_type = sanitize_key( $source_type );
		if ( $member_id <= 0 || ! preg_match( '/^[a-z0-9:_-]{1,191}$/', $history_key ) || ! in_array( $source_type, array( 'media', 'private' ), true ) || $source_id < 0 ) {
			return new WP_Error( 'adam_membership_invalid_history_entry', __( 'A entrada do histórico não é válida.', 'adam-membership' ) );
		}
		$result = $wpdb->insert(
			MemberDocumentHistorySchema::table_name(),
			array(
				'member_id'   => $member_id,
				'history_key' => $history_key,
				'source_type' => $source_type,
				'source_id'   => $source_id,
				'archived_at' => current_time( 'mysql' ),
				'archived_by' => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d' )
		);
		if ( false === $result ) {
			$existing = in_array( $history_key, $this->archived_keys( $member_id ), true );
			return $existing ? true : new WP_Error( 'adam_membership_history_archive_failed', __( 'Não foi possível remover a entrada do histórico.', 'adam-membership' ) );
		}
		return true;
	}
}
