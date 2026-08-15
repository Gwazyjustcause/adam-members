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

	public function is_suppressed( string $movement_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . FinancialMovementSchema::tombstone_table_name() . ' WHERE movement_id = %s LIMIT 1', $movement_id ) );
	}

	public function suppress( string $movement_id ): bool {
		global $wpdb;
		return false !== $wpdb->insert( FinancialMovementSchema::tombstone_table_name(), array( 'movement_id' => $movement_id, 'deleted_at' => current_time( 'mysql' ), 'deleted_by' => get_current_user_id() ), array( '%s', '%s', '%d' ) ) || $this->is_suppressed( $movement_id );
	}

	public function find_by_source( string $source_type, string $source_reference ): ?FinancialMovement {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FinancialMovementSchema::table_name() . ' WHERE source_type = %s AND source_reference = %s LIMIT 1', $source_type, $source_reference ), ARRAY_A );
		return is_array( $row ) ? new FinancialMovement( $row ) : null;
	}

	public function for_member( int $member_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . FinancialMovementSchema::table_name() . ' WHERE member_id = %d ORDER BY membership_year DESC, (payment_date IS NULL) ASC, payment_date DESC, created_at DESC, id DESC', $member_id ), ARRAY_A );
		return array_map( static fn ( array $row ): FinancialMovement => new FinancialMovement( $row ), is_array( $rows ) ? $rows : array() );
	}

	public function latest_for_member( int $member_id ): ?FinancialMovement {
		$movements = $this->for_member( $member_id );
		return $movements[0] ?? null;
	}

	public static function member_type_for_quota_type( string $quota_type ): string {
		return in_array( $quota_type, array( 'Inscrição ADAM', 'Renovação ADAM' ), true ) ? 'Aderente' : 'Efetivo';
	}

	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . FinancialMovementSchema::table_name() . ' ORDER BY membership_year DESC, (payment_date IS NULL) ASC, payment_date DESC, created_at DESC, id DESC', ARRAY_A );
		return array_map( static fn ( array $row ): FinancialMovement => new FinancialMovement( $row ), is_array( $rows ) ? $rows : array() );
	}

	public function ensure( array $data ): FinancialMovement|WP_Error {
		$movement_id = sanitize_text_field( (string) ( $data['movement_id'] ?? '' ) );
		$source_type = sanitize_key( (string) ( $data['source_type'] ?? '' ) );
		$source_reference = sanitize_text_field( (string) ( $data['source_reference'] ?? $movement_id ) );
		$quota_type = (string) ( $data['quota_type'] ?? '' );
		if ( '' === $movement_id || '' === $source_type || '' === $source_reference || ! in_array( $quota_type, self::TYPES, true ) ) { return new WP_Error( 'adam_financial_movement_invalid', 'Movimento financeiro inválido.' ); }
		if ( $this->is_suppressed( $movement_id ) ) { return new WP_Error( 'adam_financial_movement_suppressed', 'Este movimento financeiro foi eliminado pelo administrador e não pode ser recriado automaticamente.' ); }
		$requested_status = array_key_exists( 'financial_status', $data ) ? (string) $data['financial_status'] : 'pending';
		if ( 'paid' === $requested_status && ! self::valid_payment_data( $data ) ) { return new WP_Error( 'adam_financial_movement_payment_incomplete', 'Não é possível marcar o movimento como Pago com dados de pagamento incompletos.' ); }
		$current = $this->find( $movement_id ) ?? $this->find_by_source( $source_type, $source_reference );
		$now = current_time( 'mysql' );
		$existing_member_number = null !== $current ? $current->member_number() : '';
		$existing_member_name   = null !== $current ? $current->member_name() : '';
		$row = array(
			'movement_id' => $movement_id, 'member_id' => absint( $data['member_id'] ?? 0 ),
			'member_number' => '' !== $existing_member_number ? $existing_member_number : (string) ( $data['member_number'] ?? '' ),
			'member_name' => '' !== $existing_member_name ? $existing_member_name : (string) ( $data['member_name'] ?? '' ),
			'member_type' => self::member_type_for_quota_type( $quota_type ),
			'source_type' => $source_type, 'source_reference' => $source_reference, 'quota_type' => $quota_type,
			'membership_year' => absint( $data['membership_year'] ?? 0 ), 'amount' => number_format( (float) ( $data['amount'] ?? 0 ), 2, '.', '' ),
			'payment_date' => '' !== (string) ( $data['payment_date'] ?? '' ) ? (string) $data['payment_date'] : null,
			'payment_method' => (string) ( $data['payment_method'] ?? '' ), 'financial_status' => $requested_status,
			'google_state' => (string) ( $data['google_state'] ?? 'pending' ), 'google_row_number' => absint( $data['google_row_number'] ?? 0 ),
			'google_error_code' => (string) ( $data['google_error_code'] ?? '' ), 'google_missing_fields' => (string) ( $data['google_missing_fields'] ?? '' ), 'google_retry_count' => absint( $data['google_retry_count'] ?? 0 ),
			'updated_at' => $now,
		);
		if ( null !== $current ) {
			$changes = $row;
			unset( $changes['movement_id'], $changes['source_type'], $changes['source_reference'], $changes['quota_type'], $changes['google_state'], $changes['google_row_number'], $changes['google_error_code'], $changes['google_missing_fields'], $changes['google_retry_count'] );
			if ( ! $this->update( $current, $changes ) ) {
				return new WP_Error( 'adam_financial_movement_store_failed', 'Não foi possível atualizar o movimento financeiro.' );
			}
			$updated = $this->find( $current->movement_id() );
			return null !== $updated ? $updated : new WP_Error( 'adam_financial_movement_store_failed', 'Não foi possível reler o movimento financeiro atualizado.' );
		}
		$row['created_at'] = $now;
		global $wpdb;
		$format = array( '%s','%d','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%d','%s','%s','%d','%s','%s' );
		if ( false === $wpdb->insert( FinancialMovementSchema::table_name(), $row, $format ) ) { return new WP_Error( 'adam_financial_movement_store_failed', 'Não foi possível guardar o movimento financeiro.' ); }
		return $this->find( $movement_id ) ?? new WP_Error( 'adam_financial_movement_store_failed', 'Não foi possível ler o movimento financeiro guardado.' );
	}

	public function update( FinancialMovement $movement, array $changes ): bool {
		global $wpdb;
		$effective = array(
			'membership_year' => $changes['membership_year'] ?? $movement->membership_year(),
			'amount' => $changes['amount'] ?? $movement->amount(),
			'payment_date' => $changes['payment_date'] ?? $movement->payment_date(),
			'payment_method' => $changes['payment_method'] ?? $movement->payment_method(),
			'financial_status' => $changes['financial_status'] ?? $movement->financial_status(),
		);
		if ( 'paid' === (string) $effective['financial_status'] && ! self::valid_payment_data( $effective ) ) {
			$this->diagnostic_log( 'repository_update_rejected', $movement, $effective );
			return false;
		}
		$this->diagnostic_log( 'repository_update_before', $movement, $effective );
		$changes['updated_at'] = current_time( 'mysql' );
		$result = false !== $wpdb->update( FinancialMovementSchema::table_name(), $changes, array( 'id' => $movement->id() ) );
		$this->diagnostic_log( 'repository_update_after', $movement, $effective, $result );
		return $result;
	}

	/** @param array<string,mixed> $data */
	private static function valid_payment_data( array $data ): bool {
		$year = absint( $data['membership_year'] ?? 0 );
		$amount = (float) ( $data['amount'] ?? 0 );
		$date = (string) ( $data['payment_date'] ?? '' );
		$method = (string) ( $data['payment_method'] ?? '' );
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return $year >= 2000 && $year <= 2100 && $amount > 0 && false !== $parsed && $parsed->format( 'Y-m-d' ) === $date && in_array( $method, array( 'Transferência bancária', 'MB WAY', 'Cartão', 'Numerário', 'Outro' ), true );
	}

	/** @param array<string,mixed> $data */
	private function diagnostic_log( string $stage, FinancialMovement $movement, array $data, ?bool $result = null ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) { return; }
		$context = array( 'stage' => $stage, 'handler' => 'FinancialMovementRepository::update', 'movement_id' => $movement->movement_id(), 'membership_year' => absint( $data['membership_year'] ?? 0 ), 'payment_method' => (string) ( $data['payment_method'] ?? '' ), 'amount' => (string) ( $data['amount'] ?? '' ), 'payment_date' => (string) ( $data['payment_date'] ?? '' ), 'financial_status' => (string) ( $data['financial_status'] ?? '' ) );
		if ( null !== $result ) { $context['result'] = $result ? 'true' : 'false'; }
		error_log( '[ADAM Membership] financial_save_trace ' . wp_json_encode( $context ) );
	}

	public function delete( FinancialMovement $movement ): bool {
		global $wpdb;
		return false !== $wpdb->delete( FinancialMovementSchema::table_name(), array( 'id' => $movement->id() ), array( '%d' ) );
	}

	public function save_sync_state( FinancialMovement $movement, array $state ): bool {
		return $this->update( $movement, array( 'google_state' => (string) ( $state['state'] ?? 'failed' ), 'google_row_number' => absint( $state['row_number'] ?? 0 ), 'google_error_code' => (string) ( $state['last_error'] ?? '' ), 'google_missing_fields' => wp_json_encode( (array) ( $state['missing_fields'] ?? array() ) ), 'google_retry_count' => absint( $state['retry_count'] ?? 0 ) ) );
	}

	public function create_manual( Member $member, array $data ): FinancialMovement|WP_Error {
		$id = 'manual:' . wp_generate_uuid4();
		return $this->ensure( array_merge( $data, array( 'movement_id' => $id, 'source_type' => 'manual', 'source_reference' => $id, 'member_id' => $member->user_id(), 'member_number' => (string) $member->field( 'numero_socio' ), 'member_name' => $member->full_name(), 'financial_status' => 'paid' ) ) );
	}
}
