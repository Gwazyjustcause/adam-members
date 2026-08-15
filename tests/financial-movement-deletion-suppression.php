<?php
/** Regression test for deletion tombstones and legacy migration suppression. */

declare(strict_types=1);

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'absint' ) ) { function absint( mixed $value ): int { return abs( (int) $value ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( string $type ): string { return '2026-08-15 12:00:00'; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id(): int { return 99; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( mixed $value ): string { return json_encode( $value ) ?: ''; } }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { public function __construct( public string $code = '', public string $message = '' ) {} } }

final class AdamSuppressionWpdbDouble {
	public string $prefix = 'wp_';
	public array $rows = array();
	public array $tombstones = array();

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) { $query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'", $query, 1 ) ?? $query; }
		return $query;
	}

	public function get_row( string $query, string $output ): ?array {
		if ( preg_match( "/movement_id = '([^']+)'/", $query, $match ) ) { foreach ( $this->rows as $row ) { if ( $row['movement_id'] === stripslashes( $match[1] ) ) { return $row; } } }
		return null;
	}

	public function get_var( string $query ): mixed {
		if ( preg_match( "/movement_id = '([^']+)'/", $query, $match ) ) { foreach ( $this->tombstones as $row ) { if ( $row['movement_id'] === stripslashes( $match[1] ) ) { return $row['id']; } } }
		return null;
	}

	public function insert( string $table, array $data, array $format ): int|false {
		if ( str_contains( $table, 'tombstones' ) ) { foreach ( $this->tombstones as $row ) { if ( $row['movement_id'] === $data['movement_id'] ) { return false; } } $data['id'] = count( $this->tombstones ) + 1; $this->tombstones[] = $data; return 1; }
		$data['id'] = count( $this->rows ) + 1; $this->rows[] = $data; return 1;
	}

	public function update( string $table, array $changes, array $where ): int { foreach ( $this->rows as $index => $row ) { if ( (int) $row['id'] === (int) $where['id'] ) { $this->rows[ $index ] = array_merge( $row, $changes ); return 1; } } return 0; }
	public function delete( string $table, array $where, array $format ): int { foreach ( $this->rows as $index => $row ) { if ( (int) $row['id'] === (int) $where['id'] ) { array_splice( $this->rows, $index, 1 ); return 1; } } return 0; }
}

$wpdb = new AdamSuppressionWpdbDouble();
$wpdb->rows[] = array( 'id' => 1, 'movement_id' => 'registration:deleted', 'member_id' => 7, 'member_number' => 'ADAM-0007', 'member_name' => 'Member', 'member_type' => 'Aderente', 'source_type' => 'registration', 'source_reference' => 'registration:deleted', 'quota_type' => 'Inscrição ADAM', 'membership_year' => 2026, 'amount' => '12.00', 'payment_date' => '2026-08-08', 'payment_method' => 'Transferência bancária', 'financial_status' => 'paid', 'google_state' => 'synchronized' );

require_once __DIR__ . '/../src/Finance/FinancialMovementSchema.php';
require_once __DIR__ . '/../src/Finance/FinancialMovement.php';
require_once __DIR__ . '/../src/Finance/FinancialMovementRepository.php';

use AdamMembership\Finance\FinancialMovementRepository;

$repository = new FinancialMovementRepository();
$movement = $repository->find( 'registration:deleted' );
if ( null === $movement || ! $repository->suppress( $movement->movement_id() ) || ! $repository->delete( $movement ) || null !== $repository->find( 'registration:deleted' ) || ! $repository->is_suppressed( 'registration:deleted' ) ) { fwrite( STDERR, "FAIL: deleted movement was not suppressed and removed\n" ); exit( 1 ); }

$blocked = $repository->ensure( array( 'movement_id' => 'registration:deleted', 'source_type' => 'registration', 'source_reference' => 'registration:deleted', 'quota_type' => 'Inscrição ADAM', 'membership_year' => 2026, 'amount' => '12.00', 'payment_date' => '2026-08-08', 'payment_method' => 'Transferência bancária', 'financial_status' => 'paid' ) );
if ( ! $blocked instanceof WP_Error || count( $wpdb->rows ) !== 0 ) { fwrite( STDERR, "FAIL: tombstoned movement was recreated\n" ); exit( 1 ); }

echo "Financial movement deletion suppression tests passed.\n";
