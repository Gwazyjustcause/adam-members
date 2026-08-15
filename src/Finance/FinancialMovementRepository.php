<?php
declare(strict_types=1);

namespace AdamMembership\Finance;

use AdamMembership\Member\Member;
use WP_Error;

final class FinancialMovementRepository {
	private const TYPES = array( 'Inscrição ADAM', 'Inscrição ADAM/ANA', 'Renovação ADAM', 'Renovação ADAM/ANA', 'Associar APD/ANA' );

	public function find( string $movement_id ): ?FinancialMovement {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FinancialMovementSchema::table_name() . ' WHERE movement_id = %s LIMIT 1', $movement_id ), ARRAY_A );
		return is_array( $row ) ? new FinancialMovement( $row ) : null;
	}

	public function find_by_source( string $source_type, string $source_reference ): ?FinancialMovement {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FinancialMovementSchema::table_name() . ' WHERE source_type = %s AND source_reference = %s LIMIT 1', $source_type, $source_reference ), ARRAY_A );
		return is_array( $row ) ? new FinancialMovement( $row ) : null;
	}

	public function for_member( int $member_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . FinancialMovementSchema::table_name() . ' WHERE member_id = %d ORDER BY id ASC', $member_id ), ARRAY_A );
		return array_map( static fn ( array $row ): FinancialMovement => new FinancialMovement( $row ), is_array( $rows ) ? $rows : array() );
	}

	public function ensure( array $data ): FinancialMovement|WP_Error {
		$movement_id = sanitize_text_field( (string) ( $data['movement_id'] ?? '' ) );
		$source_type = sanitize_key( (string) ( $data['source_type'] ?? '' ) );
		$source_reference = sanitize_text_field( (string) ( $data['source_reference'] ?? $movement_id ) );
		$quota_type = (string) ( $data['quota_type'] ?? '' );
		if ( '' === $movement_id || '' === $source_type || '' === $source_reference || ! in_array( $quota_type, self::TYPES, true ) ) { return new WP_Error( 'adam_financial_movement_invalid', 'Movimento financeiro inválido.' ); }
		$current = $this->find( $movement_id ) ?? $this->find_by_source( $source_type, $source_reference );
		$now = current_time( 'mysql' );
		$row = array(
			'movement_id' => $movement_id, 'member_id' => absint( $data['member_id'] ?? 0 ),
			'member_number' => (string) ( $data['member_number'] ?? '' ), 'member_name' => (string) ( $data['member_name'] ?? '' ),
			'source_type' => $source_type, 'source_reference' => $source_reference, 'quota_type' => $quota_type,
			'membership_year' => absint( $data['membership_year'] ?? 0 ), 'amount' => number_format( (float) ( $data['amount'] ?? 0 ), 2, '.', '' ),
			'payment_date' => '' !== (string) ( $data['payment_date'] ?? '' ) ? (string) $data['payment_date'] : null,
			'payment_method' => (string) ( $data['payment_method'] ?? '' ), 'financial_status' => (string) ( $data['financial_status'] ?? 'paid' ),
			'google_state' => (string) ( $data['google_state'] ?? 'pending' ), 'google_row_number' => absint( $data['google_row_number'] ?? 0 ),
			'google_error_code' => (string) ( $data['google_error_code'] ?? '' ), 'google_missing_fields' => (string) ( $data['google_missing_fields'] ?? '' ), 'google_retry_count' => absint( $data['google_retry_count'] ?? 0 ),
			'updated_at' => $now,
		);
		if ( null !== $current ) {
			$changes = $row;
			unset( $changes['movement_id'], $changes['source_type'], $changes['source_reference'], $changes['quota_type'], $changes['google_state'], $changes['google_row_number'], $changes['google_error_code'], $changes['google_missing_fields'], $changes['google_retry_count'] );
			$this->update( $current, $changes );
			return $this->find( $current->movement_id() ) ?? $current;
		}
		$row['created_at'] = $now;
		global $wpdb;
		$format = array( '%s','%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%d','%s','%s','%d','%s','%s' );
		if ( false === $wpdb->insert( FinancialMovementSchema::table_name(), $row, $format ) ) { return new WP_Error( 'adam_financial_movement_store_failed', 'Não foi possível guardar o movimento financeiro.' ); }
		return $this->find( $movement_id ) ?? new WP_Error( 'adam_financial_movement_store_failed', 'Não foi possível ler o movimento financeiro guardado.' );
	}

	public function update( FinancialMovement $movement, array $changes ): bool {
		global $wpdb;
		$changes['updated_at'] = current_time( 'mysql' );
		return false !== $wpdb->update( FinancialMovementSchema::table_name(), $changes, array( 'id' => $movement->id() ) );
	}

	public function save_sync_state( FinancialMovement $movement, array $state ): bool {
		return $this->update( $movement, array( 'google_state' => (string) ( $state['state'] ?? 'failed' ), 'google_row_number' => absint( $state['row_number'] ?? 0 ), 'google_error_code' => (string) ( $state['last_error'] ?? '' ), 'google_missing_fields' => wp_json_encode( (array) ( $state['missing_fields'] ?? array() ) ), 'google_retry_count' => absint( $state['retry_count'] ?? 0 ) ) );
	}

	public function create_manual( Member $member, array $data ): FinancialMovement|WP_Error {
		$id = 'manual:' . wp_generate_uuid4();
		return $this->ensure( array_merge( $data, array( 'movement_id' => $id, 'source_type' => 'manual', 'source_reference' => $id, 'member_id' => $member->user_id(), 'member_number' => (string) $member->field( 'numero_socio' ), 'member_name' => $member->full_name(), 'financial_status' => 'paid' ) ) );
	}
}
