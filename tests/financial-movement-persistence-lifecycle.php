<?php
/** Actual repository persistence lifecycle test using an isolated in-memory wpdb double. */

declare(strict_types=1);

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'absint' ) ) { function absint( mixed $value ): int { return abs( (int) $value ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( string $type ): string { return '2026-08-09 12:00:00'; } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
	}
}

final class AdamMovementWpdbDouble {
	public string $prefix = 'wp_';
	public array $rows = array();

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[sd]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_row( string $query, string $output ): ?array {
		if ( preg_match( "/movement_id = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows as $row ) {
				if ( $row['movement_id'] === stripslashes( $match[1] ) ) { return $row; }
			}
		}
		return null;
	}

	public function update( string $table, array $changes, array $where ): int {
		foreach ( $this->rows as $index => $row ) {
			if ( (int) $row['id'] !== (int) $where['id'] ) { continue; }
			$this->rows[ $index ] = array_merge( $row, $changes );
			return 1;
		}
		return 0;
	}
}

$wpdb = new AdamMovementWpdbDouble();
$wpdb->rows[] = array(
	'id' => 1, 'movement_id' => 'registration:39d9371e-test', 'member_id' => 7,
	'member_number' => 'ADAM-0007', 'member_name' => 'Test Member', 'member_type' => 'Aderente',
	'source_type' => 'registration', 'source_reference' => 'registration:39d9371e-test',
	'quota_type' => 'Inscrição ADAM', 'membership_year' => 0, 'amount' => '12.00',
	'payment_date' => '2026-08-09', 'payment_method' => '', 'financial_status' => 'paid',
	'google_state' => 'pending',
);

require_once __DIR__ . '/../src/Finance/FinancialMovementSchema.php';
require_once __DIR__ . '/../src/Finance/FinancialMovement.php';
require_once __DIR__ . '/../src/Finance/FinancialMovementRepository.php';

use AdamMembership\Finance\FinancialMovementRepository;

$repository = new FinancialMovementRepository();
$values = array( 'membership_year' => 2026, 'amount' => '12.00', 'payment_date' => '2026-08-09', 'payment_method' => 'Transferência bancária', 'financial_status' => 'paid' );

foreach ( array( 'registration:39d9371e-test', 'renewal:test', 'apd:test', 'manual:test' ) as $movement_id ) {
	$wpdb->rows[0]['movement_id'] = $movement_id;
	$wpdb->rows[0]['source_reference'] = $movement_id;
	$movement = $repository->find( $movement_id );
	if ( null === $movement || ! $repository->update( $movement, $values ) ) { fwrite( STDERR, "FAIL: update failed for {$movement_id}\n" ); exit( 1 ); }
	$reloaded = $repository->find( $movement_id );
	if ( null === $reloaded || 2026 !== $reloaded->membership_year() || '12.00' !== number_format( (float) $reloaded->amount(), 2, '.', '' ) || '2026-08-09' !== $reloaded->payment_date() || 'Transferência bancária' !== $reloaded->payment_method() || 'paid' !== $reloaded->financial_status() ) {
		fwrite( STDERR, "FAIL: persisted values did not survive reload for {$movement_id}\n" ); exit( 1 );
	}
}

$invalid = $repository->find( 'manual:test' );
if ( null === $invalid || $repository->update( $invalid, array( 'membership_year' => 0, 'payment_method' => '', 'financial_status' => 'paid' ) ) ) {
	fwrite( STDERR, "FAIL: incomplete payment data was accepted as paid\n" ); exit( 1 );
}
$still_valid = $repository->find( 'manual:test' );
if ( null === $still_valid || 2026 !== $still_valid->membership_year() || 'Transferência bancária' !== $still_valid->payment_method() || 'paid' !== $still_valid->financial_status() ) {
	fwrite( STDERR, "FAIL: rejected paid update changed the persisted movement\n" ); exit( 1 );
}

echo "Financial movement persistence lifecycle tests passed.\n";
