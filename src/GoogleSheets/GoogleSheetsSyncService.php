<?php
/**
 * Synchronizes approved quota movements with Google Sheets.
 *
 * @package AdamMembership\GoogleSheets
 */

declare(strict_types=1);

namespace AdamMembership\GoogleSheets;

use AdamMembership\Helpers\Logger;
use AdamMembership\Member\HistoryRepository;
use AdamMembership\Member\Member;
use AdamMembership\Member\RenewalRequest;
use AdamMembership\Member\RenewalRepository;
use WP_Error;

/**
 * Builds and synchronizes one financial quota movement at a time.
 */
final class GoogleSheetsSyncService {
	public const STATUS_PENDING = 'pending';
	public const STATUS_SYNCED  = 'synchronized';
	public const STATUS_FAILED  = 'failed';
	public const PAYMENT_METHODS = array( 'Transferência bancária', 'MB WAY', 'Cartão', 'Numerário', 'Outro' );

	private const REGISTRATION_UUID = 'adam_membership_registration_request_uuid';
	private const REGISTRATION_DATA = 'adam_membership_google_sheets_sync';
	private const RENEWAL_UUID      = 'request_uuid';
	private const RENEWAL_SYNC      = 'google_sheets_sync';

	private GoogleSheetsClient $client;
	private HistoryRepository $history;
	private Logger $logger;
	private RenewalRepository $renewals;

	public function __construct( GoogleSheetsClient $client, HistoryRepository $history, Logger $logger, RenewalRepository $renewals ) {
		$this->client  = $client;
		$this->history = $history;
		$this->logger  = $logger;
		$this->renewals = $renewals;
	}

	/** Return or create the permanent registration request ID. */
	public function registration_request_id( int $user_id ): string {
		$current = (string) get_user_meta( $user_id, self::REGISTRATION_UUID, true );
		if ( '' !== $current ) {
			return $current;
		}
		$id = 'registration:' . wp_generate_uuid4();
		add_user_meta( $user_id, self::REGISTRATION_UUID, $id, true );
		$current = (string) get_user_meta( $user_id, self::REGISTRATION_UUID, true );
		return '' !== $current ? $current : 'legacy-registration:' . $user_id;
	}

	/** Synchronize one approved registration. */
	public function sync_registration( Member $member ): true|WP_Error {
		if ( Member::STATUS_ACTIVE !== $member->status() ) {
			return new WP_Error( 'adam_google_sheets_not_approved', __( 'A inscrição só pode ser sincronizada depois de aprovada.', 'adam-membership' ) );
		}
		$movement = $this->registration_movement( $member );
		return $this->sync( $movement, $member );
	}

	/** Synchronize one approved renewal. */
	public function sync_renewal( RenewalRequest $request, Member $member ): true|WP_Error {
		if ( RenewalRequest::STATUS_APPROVED !== $request->status() ) {
			return new WP_Error( 'adam_google_sheets_not_approved', __( 'A renovação só pode ser sincronizada depois de aprovada.', 'adam-membership' ) );
		}
		$data = $request->data();
		$movement = array(
			'request_id'     => $request->request_uuid(),
			'member_number'  => (string) $member->field( 'numero_socio' ),
			'name'           => $member->full_name(),
			'year'           => (string) ( $data['membership_year'] ?? '' ),
			'movement'       => 'Renovação',
			'type'           => $this->membership_type( (string) ( $data['submitted_data']['adam_membership_origin'] ?? $member->field( 'adam_membership_origin' ) ) ),
			'amount'         => (string) ( $data['payment_amount'] ?? $data['submitted_data']['adam_membership_fee'] ?? '' ),
			'payment_date'   => (string) ( $data['payment_date'] ?? '' ),
			'method'         => (string) ( $data['payment_method'] ?? '' ),
			'status'         => 'Pago',
			'order_id'       => (string) ( $data['source_order_id'] ?? $request->id() ),
			'note'           => '',
		);
		return $this->sync( $movement, $member, $request->id() );
	}

	/** Build the canonical row data for a registration. */
	private function registration_movement( Member $member ): array {
		return array(
			'request_id'    => $this->registration_request_id( $member->user_id() ),
			'member_number' => (string) $member->field( 'numero_socio' ),
			'name'          => $member->full_name(),
			'year'          => (string) get_user_meta( $member->user_id(), 'adam_membership_year', true ),
			'movement'      => 'Inscrição',
			'type'          => $this->membership_type( (string) $member->field( 'adam_membership_origin' ) ),
			'amount'        => (string) get_user_meta( $member->user_id(), 'adam_membership_payment_amount', true ),
			'payment_date'  => (string) get_user_meta( $member->user_id(), 'adam_membership_payment_date', true ),
			'method'        => (string) get_user_meta( $member->user_id(), 'adam_membership_payment_method', true ),
			'status'        => 'Pago',
			'order_id'      => (string) get_user_meta( $member->user_id(), 'adam_membership_source_order_id', true ),
			'note'          => '',
		);
	}

	/** Append idempotently after checking column J. */
	private function sync( array $movement, Member $member, int $renewal_id = 0 ): true|WP_Error {
		$lock_key = 'adam_google_sheets_lock_' . md5( (string) $movement['request_id'] );
		$lock_token = time() . ':' . wp_generate_uuid4();
		$existing_lock = (string) get_option( $lock_key, '' );
		if ( '' !== $existing_lock && (int) strtok( $existing_lock, ':' ) < time() - 60 ) {
			delete_option( $lock_key );
		}
		if ( ! add_option( $lock_key, $lock_token, '', 'no' ) ) {
			return new WP_Error( 'adam_google_sheets_sync_in_progress', __( 'Este movimento já está a ser sincronizado. Tente novamente dentro de alguns segundos.', 'adam-membership' ) );
		}
		try {
			return $this->sync_locked( $movement, $member, $renewal_id );
		} finally {
			if ( $lock_token === (string) get_option( $lock_key, '' ) ) {
				delete_option( $lock_key );
			}
		}
	}

	/** Perform validation, duplicate detection and a bounded table write while locked. */
	private function sync_locked( array $movement, Member $member, int $renewal_id = 0 ): true|WP_Error {
		$missing = array_filter( array( 'year', 'amount', 'payment_date', 'method' ), static fn ( string $key ): bool => '' === trim( (string) ( $movement[ $key ] ?? '' ) ) );
		if ( '' !== trim( (string) ( $movement['amount'] ?? '' ) ) && ! is_numeric( str_replace( ',', '.', (string) $movement['amount'] ) ) ) {
			$missing['amount'] = true;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) ( $movement['payment_date'] ?? '' ) );
		if ( '' !== trim( (string) ( $movement['payment_date'] ?? '' ) ) && ( false === $date || $date->format( 'Y-m-d' ) !== (string) $movement['payment_date'] ) ) {
			$missing['payment_date'] = true;
		}
		if ( '' !== trim( (string) ( $movement['method'] ?? '' ) ) && ! in_array( (string) $movement['method'], self::PAYMENT_METHODS, true ) ) {
			$missing['method'] = true;
		}
		if ( array() !== $missing ) {
			return $this->finish( $movement['request_id'], self::STATUS_PENDING, new WP_Error( 'adam_google_sheets_payment_data_missing', __( 'Dados de pagamento em falta para sincronizar este movimento.', 'adam-membership' ) ), $member, $renewal_id );
		}
		$row = $this->row( $movement );
		$existing = $this->client->read_values( 'A5:K' );
		if ( is_wp_error( $existing ) ) {
			return $this->finish( $movement['request_id'], self::STATUS_FAILED, $existing, $member, $renewal_id );
		}
		$plan = GoogleSheetsTablePlanner::plan( (array) ( $existing['values'] ?? array() ), (string) $movement['request_id'] );
		if ( $plan['duplicate_row'] > 0 ) {
			$values = $plan['duplicate_values'];
			if ( $this->same_row( $values, $row ) ) {
				return $this->finish( $movement['request_id'], self::STATUS_SYNCED, true, $member, $renewal_id, $plan['duplicate_row'] );
			}
			return $this->finish( $movement['request_id'], self::STATUS_FAILED, new WP_Error( 'adam_google_sheets_conflict', __( 'O ID do pedido já existe na spreadsheet com dados diferentes.', 'adam-membership' ) ), $member, $renewal_id );
		}
		$appended = $this->client->append_table_row( $row );
		if ( is_wp_error( $appended ) ) {
			return $this->finish( $movement['request_id'], self::STATUS_FAILED, $appended, $member, $renewal_id );
		}
		$range = (array) ( $appended['table']['range'] ?? array() );
		$row_number = absint( $range['endRowIndex'] ?? 0 );
		return $this->finish( $movement['request_id'], self::STATUS_SYNCED, true, $member, $renewal_id, $row_number );
	}

	/** Convert canonical movement data into columns A:K. */
	private function row( array $movement ): array {
		return array( $movement['member_number'], $movement['name'], absint( $movement['year'] ), $movement['movement'], $movement['type'], (float) str_replace( ',', '.', (string) $movement['amount'] ), $movement['payment_date'], $movement['method'], $movement['status'], $movement['request_id'], $movement['note'] );
	}

	/** Compare existing and expected rows without treating formatting as data. */
	private function same_row( array $stored, array $expected ): bool {
		return GoogleSheetsTablePlanner::rows_match( $stored, $expected );
	}

	/** Map the internal origin to the accounting label. */
	private function membership_type( string $origin ): string {
		return 'external_association' === $origin ? 'Aderente' : 'Efetivo';
	}

	/** Persist status metadata and a safe audit entry. */
	private function finish( string $request_id, string $status, true|WP_Error $result, Member $member, int $renewal_id = 0, int $row_number = 0 ): true|WP_Error {
		$previous = $renewal_id > 0 ? (array) ( $this->renewals->find( $renewal_id )?->data()[ self::RENEWAL_SYNC ] ?? array() ) : (array) get_user_meta( $member->user_id(), self::REGISTRATION_DATA, true );
		$meta = array( 'state' => $status, 'request_id' => $request_id, 'row_number' => $row_number, 'timestamp' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'last_error' => is_wp_error( $result ) ? $result->get_error_code() : '', 'retry_count' => 1 + absint( $previous['retry_count'] ?? 0 ) );
		if ( $renewal_id <= 0 ) {
			update_user_meta( $member->user_id(), self::REGISTRATION_DATA, $meta );
		}
		if ( $renewal_id > 0 ) {
			$request = $this->renewals->find( $renewal_id );
			if ( null !== $request ) {
				$data = $request->data();
				$data[ self::RENEWAL_SYNC ] = $meta;
				$this->renewals->update( $request, array( self::RENEWAL_SYNC => $meta ) );
			}
		}
		$this->history->create( array( 'member_id' => $member->user_id(), 'member_number' => (string) $member->field( 'numero_socio' ), 'member_name' => $member->full_name(), 'member_email' => sanitize_email( $member->email() ), 'action_key' => 'google_sheets_sync_' . $status, 'action_label' => 'Google Sheets sync', 'actor_type' => 'system', 'actor_id' => 0, 'actor_name' => 'Sistema', 'description' => is_wp_error( $result ) ? 'Google Sheets synchronization failed.' : 'Google Sheets synchronization completed.', 'details' => array( 'request_id' => $request_id, 'row_number' => $row_number, 'renewal_id' => $renewal_id ), 'created_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) ) );
		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'Google Sheets synchronization failed.', array( 'request_id' => $request_id, 'status' => $status, 'error_code' => $result->get_error_code() ) );
			return $result;
		}
		return true;
	}
}
