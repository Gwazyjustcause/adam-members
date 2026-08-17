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
use AdamMembership\Member\ApdAssociationRequest;
use AdamMembership\Member\RenewalRequest;
use AdamMembership\Member\RenewalRepository;
use AdamMembership\Member\ApdAssociationRepository;
use AdamMembership\Finance\FinancialMovement;
use AdamMembership\Finance\FinancialMovementRepository;
use WP_Error;

/**
 * Builds and synchronizes one financial quota movement at a time.
 */
final class GoogleSheetsSyncService {
	public const STATUS_PENDING = 'pending';
	public const STATUS_SYNCED  = 'synchronized';
	public const STATUS_FAILED  = 'failed';
	public const STATUS_INACTIVE = 'inactive';
	public const PAYMENT_METHODS = array( 'Transferência bancária', 'MB WAY', 'Cartão', 'Numerário', 'Outro' );

	private const REGISTRATION_UUID = 'adam_membership_registration_request_uuid';
	private const REGISTRATION_DATA = 'adam_membership_google_sheets_sync';
	private const RENEWAL_UUID      = 'request_uuid';
	private const RENEWAL_SYNC      = 'google_sheets_sync';

	private GoogleSheetsClient $client;
	private HistoryRepository $history;
	private Logger $logger;
	private RenewalRepository $renewals;
	private ApdAssociationRepository $apd_repository;
	private ?ApdAssociationRequest $active_apd_request = null;
	private FinancialMovementRepository $movements;
	private ?FinancialMovement $active_movement = null;

	public function __construct( GoogleSheetsClient $client, HistoryRepository $history, Logger $logger, RenewalRepository $renewals, FinancialMovementRepository $movements ) {
		$this->client  = $client;
		$this->history = $history;
		$this->logger  = $logger;
		$this->renewals = $renewals;
		$this->apd_repository = new ApdAssociationRepository();
		$this->movements = $movements;
	}

	/**
	 * Return or create the permanent registration request ID.
	 *
	 * The current registration lifecycle stores one ID per user; renewals use
	 * independent request UUIDs. Keep this limitation explicit until a future
	 * registration-request repository can provide one ID per registration.
	 */
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
		$record = $this->movements->ensure( array_merge( $movement, array( 'member_id' => $member->user_id(), 'member_number' => (string) $member->field( 'numero_socio' ), 'member_name' => $member->full_name(), 'member_type' => FinancialMovementRepository::member_type_for_quota_type( (string) $movement['quota_type'] ) ) ) );
		if ( is_wp_error( $record ) ) { return $record; }
		return $this->sync_record( $record, $member );
	}

	/** Persist a financially confirmed registration without approving or synchronizing it. */
	public function ensure_registration_movement( Member $member, ?array $financial = null ): FinancialMovement|WP_Error {
		$movement = $this->registration_movement( $member );
		if ( null === $financial ) {
			$existing = $this->movements->find( (string) $movement['movement_id'] );
			if ( null !== $existing ) {
				$movement['year'] = (string) $existing->membership_year();
				$movement['amount'] = $existing->amount();
				$movement['payment_date'] = $existing->payment_date();
				$movement['method'] = $existing->payment_method();
			}
		}
		if ( null !== $financial ) {
			$movement['year'] = (string) $financial['membership_year'];
			$movement['amount'] = (string) $financial['amount'];
			$movement['payment_date'] = (string) $financial['payment_date'];
			$movement['method'] = (string) $financial['payment_method'];
		}
		// registration_movement() retains its legacy export keys, while the
		// financial repository accepts the canonical persistence keys.
		$movement['membership_year'] = absint( $movement['year'] ?? 0 );
		$movement['payment_method'] = (string) ( $movement['method'] ?? '' );
		unset( $movement['year'], $movement['method'] );
		return $this->movements->ensure( array_merge( $movement, array(
			'member_id' => $member->user_id(),
			'member_number' => (string) $member->field( 'numero_socio' ),
			'member_name' => $member->full_name(),
			'member_type' => FinancialMovementRepository::member_type_for_quota_type( (string) $movement['quota_type'] ),
			'financial_status' => 'paid',
		) ) );
	}

	/** Synchronize one approved renewal. */
	public function sync_renewal( RenewalRequest $request, Member $member ): true|WP_Error {
		if ( RenewalRequest::STATUS_APPROVED !== $request->status() ) {
			return new WP_Error( 'adam_google_sheets_not_approved', __( 'A renovação só pode ser sincronizada depois de aprovada.', 'adam-membership' ) );
		}
		$data = $request->data();
		$sync = (array) ( $data['google_sheets_sync'] ?? array() );
		$movement = array(
			'quota_type'     => $this->quota_type( (string) ( $data['submitted_data']['adam_membership_origin'] ?? '' ), 'renewal' ),
			'movement_id'    => $request->request_uuid(),
			'member_number'  => (string) $member->field( 'numero_socio' ),
			'name'           => $member->full_name(),
			'member_name'    => $member->full_name(),
			'member_type'    => FinancialMovementRepository::member_type_for_quota_type( (string) $this->quota_type( (string) ( $data['submitted_data']['adam_membership_origin'] ?? '' ), 'renewal' ) ),
			'year'           => (string) ( $data['membership_year'] ?? '' ),
			'movement'       => 'Renovação',
			'type'           => $this->membership_type( (string) ( $data['submitted_data']['adam_membership_origin'] ?? $member->field( 'adam_membership_origin' ) ) ),
			'amount'         => (string) ( $data['payment_amount'] ?? $data['submitted_data']['adam_membership_fee'] ?? '' ),
			'payment_date'   => (string) ( $data['payment_date'] ?? '' ),
			'method'         => (string) ( $data['payment_method'] ?? '' ),
			'status'         => 'Pago',
			'order_id'       => (string) ( $data['source_order_id'] ?? $request->id() ),
			'note'           => '',
			'google_state'   => (string) ( $sync['state'] ?? 'pending' ), 'google_row_number' => absint( $sync['row_number'] ?? 0 ),
		);
		$record = $this->movements->ensure( array_merge( $movement, array( 'source_type' => 'renewal', 'source_reference' => $request->request_uuid(), 'member_id' => $member->user_id() ) ) );
		if ( is_wp_error( $record ) ) { return $record; }
		return $this->sync_record( $record, $member, $request->id() );
	}

	/** Persist a financially confirmed renewal without approving the request. */
	public function ensure_renewal_movement( RenewalRequest $request, Member $member, ?array $financial = null ): FinancialMovement|WP_Error {
		$data = $request->data();
		$quota_type = $this->quota_type( (string) ( $data['submitted_data']['adam_membership_origin'] ?? '' ), 'renewal' );
		$sync = (array) ( $data['google_sheets_sync'] ?? array() );
		if ( null === $financial ) {
			$existing = $this->movements->find( $request->request_uuid() );
			if ( null !== $existing ) {
				$financial = array( 'membership_year' => $existing->membership_year(), 'amount' => $existing->amount(), 'payment_date' => $existing->payment_date(), 'payment_method' => $existing->payment_method() );
			} else {
				$financial = array(
					'membership_year' => absint( $data['membership_year'] ?? 0 ),
					'amount' => (string) ( $data['payment_amount'] ?? $data['submitted_data']['adam_membership_fee'] ?? '' ),
					'payment_date' => (string) ( $data['payment_date'] ?? '' ),
					'payment_method' => (string) ( $data['payment_method'] ?? '' ),
				);
			}
		}
		return $this->movements->ensure( array(
			'quota_type' => $quota_type,
			'movement_id' => $request->request_uuid(),
			'source_type' => 'renewal',
			'source_reference' => $request->request_uuid(),
			'member_id' => $member->user_id(),
			'member_number' => (string) $member->field( 'numero_socio' ),
			'member_name' => $member->full_name(),
			'member_type' => FinancialMovementRepository::member_type_for_quota_type( $quota_type ),
			'membership_year' => absint( $financial['membership_year'] ?? 0 ),
			'amount' => (string) ( $financial['amount'] ?? '' ),
			'payment_date' => (string) ( $financial['payment_date'] ?? '' ),
			'payment_method' => (string) ( $financial['payment_method'] ?? '' ),
			'financial_status' => 'paid',
			'google_state' => (string) ( $sync['state'] ?? 'pending' ),
		) );
	}

	/** Synchronize a confirmed APD/ANA financial movement. */
	public function sync_apd_association( ApdAssociationRequest $request, Member $member ): true|WP_Error {
		if ( ApdAssociationRequest::STATUS_CONFIRMED !== $request->status() ) {
			return new WP_Error( 'adam_google_sheets_not_approved', __( 'O movimento APD sÃ³ pode ser sincronizado depois de confirmado.', 'adam-membership' ) );
		}
		$movement = array(
			'quota_type' => 'Associar APD/ANA', 'movement_id' => $request->request_uuid(),
			'member_number' => (string) $member->field( 'numero_socio' ), 'name' => $member->full_name(), 'member_name' => $member->full_name(),
			'member_type' => FinancialMovementRepository::member_type_for_quota_type( 'Associar APD/ANA' ),
			'year' => (string) $request->membership_year(), 'movement' => 'Associar APD/ANA',
			'type' => $this->membership_type( (string) $member->field( 'adam_membership_origin' ) ),
			'amount' => $request->payment_amount(), 'payment_date' => $request->payment_date(),
			'method' => $request->payment_method(), 'status' => 'Pago', 'order_id' => (string) $request->id(), 'note' => '',
		);
		$sync = (array) ( $request->data()['google_sheets_sync'] ?? array() );
		$movement['google_state'] = (string) ( $sync['state'] ?? 'pending' );
		$movement['google_row_number'] = absint( $sync['row_number'] ?? 0 );
		$record = $this->movements->ensure( array_merge( $movement, array( 'source_type' => 'apd', 'source_reference' => $request->request_uuid(), 'member_id' => $member->user_id() ) ) );
		if ( is_wp_error( $record ) ) { return $record; }
		return $this->sync_record( $record, $member, 0, $request );
	}

	/** Persist a financially confirmed APD movement without confirming the APD request. */
	public function ensure_apd_movement( ApdAssociationRequest $request, Member $member ): FinancialMovement|WP_Error {
		$existing = $this->movements->find( $request->request_uuid() );
		if ( null !== $existing ) {
			return $this->movements->ensure( array(
				'quota_type' => 'Associar APD/ANA', 'movement_id' => $request->request_uuid(), 'source_type' => 'apd', 'source_reference' => $request->request_uuid(), 'member_id' => $member->user_id(),
				'member_number' => (string) $member->field( 'numero_socio' ), 'member_name' => $member->full_name(), 'member_type' => FinancialMovementRepository::member_type_for_quota_type( 'Associar APD/ANA' ),
				'membership_year' => $existing->membership_year(), 'amount' => $existing->amount(), 'payment_date' => $existing->payment_date(), 'payment_method' => $existing->payment_method(), 'financial_status' => $existing->financial_status(), 'google_state' => $existing->google_state(),
			) );
		}
		return $this->movements->ensure( array(
			'quota_type' => 'Associar APD/ANA',
			'movement_id' => $request->request_uuid(),
			'source_type' => 'apd',
			'source_reference' => $request->request_uuid(),
			'member_id' => $member->user_id(),
			'member_number' => (string) $member->field( 'numero_socio' ),
			'member_name' => $member->full_name(),
			'member_type' => FinancialMovementRepository::member_type_for_quota_type( 'Associar APD/ANA' ),
			'membership_year' => $request->membership_year(),
			'amount' => $request->payment_amount(),
			'payment_date' => $request->payment_date(),
			'payment_method' => $request->payment_method(),
			'financial_status' => 'paid',
			'google_state' => 'pending',
		) );
	}

	public function sync_manual( FinancialMovement $movement, Member $member ): true|WP_Error {
		return $this->sync_persisted_movement( $movement, $member );
	}

	/** Synchronize an already-persisted movement without rerunning its originating workflow. */
	public function sync_persisted_movement( FinancialMovement $movement, Member $member ): true|WP_Error {
		$repair = array();
		if ( '' === trim( $movement->member_number() ) ) { $repair['member_number'] = (string) $member->field( 'numero_socio' ); }
		if ( '' === trim( $movement->member_name() ) ) { $repair['member_name'] = $member->full_name(); }
		$expected_member_type = FinancialMovementRepository::member_type_for_quota_type( $movement->quota_type() );
		if ( $movement->member_type() !== $expected_member_type ) { $repair['member_type'] = $expected_member_type; }
		if ( array() !== $repair ) {
			$this->movements->update( $movement, $repair );
			$movement = $this->movements->find( $movement->movement_id() ) ?? $movement;
		}
		return $this->sync_record( $movement, $member );
	}

	private function sync_record( FinancialMovement $record, Member $member, int $renewal_id = 0, ?ApdAssociationRequest $apd_request = null ): true|WP_Error {
		$payload = array( 'quota_type' => $record->quota_type(), 'movement_id' => $record->movement_id(), 'member_number' => $record->member_number() ?: (string) $member->field( 'numero_socio' ), 'name' => $record->member_name() ?: $member->full_name(), 'year' => (string) $record->membership_year(), 'movement' => $this->movement_label( $record->quota_type() ), 'type' => $record->member_type() ?: FinancialMovementRepository::member_type_for_quota_type( $record->quota_type() ), 'amount' => $record->amount(), 'payment_date' => $record->payment_date(), 'method' => $record->payment_method(), 'status' => 'Pago', 'financial_status' => $record->financial_status(), 'order_id' => $record->source_reference(), 'note' => '' );
		$this->active_movement = $record;
		try { return $this->sync( $payload, $member, $renewal_id, $apd_request ); } finally { $this->active_movement = null; }
	}

	/** Build the canonical row data for a registration. */
	private function registration_movement( Member $member ): array {
		$request_id = $this->registration_request_id( $member->user_id() );
		$sync = (array) get_user_meta( $member->user_id(), self::REGISTRATION_DATA, true );
		return array(
			'quota_type'    => $this->quota_type( (string) $member->field( 'adam_membership_origin' ), 'registration' ),
			'movement_id'   => $request_id, 'source_type' => 'registration', 'source_reference' => $request_id,
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
			'google_state'  => (string) ( $sync['state'] ?? 'pending' ), 'google_row_number' => absint( $sync['row_number'] ?? 0 ),
			'note'          => '',
		);
	}

	/** Append or update idempotently after checking canonical ID in column K. */
	private function sync( array $movement, Member $member, int $renewal_id = 0, ?ApdAssociationRequest $apd_request = null ): true|WP_Error {
		$lock_key = 'adam_google_sheets_lock_' . md5( (string) $movement['movement_id'] );
		$lock_token = time() . ':' . wp_generate_uuid4();
		$existing_lock = (string) get_option( $lock_key, '' );
		if ( '' !== $existing_lock && (int) strtok( $existing_lock, ':' ) < time() - 60 ) {
			delete_option( $lock_key );
		}
		if ( ! add_option( $lock_key, $lock_token, '', 'no' ) ) {
			return new WP_Error( 'adam_google_sheets_sync_in_progress', __( 'Este movimento já está a ser sincronizado. Tente novamente dentro de alguns segundos.', 'adam-membership' ) );
		}
		try {
			$this->active_apd_request = $apd_request;
			return $this->sync_locked( $movement, $member, $renewal_id, $apd_request );
		} catch ( \Throwable $exception ) {
			$this->client->log_exception( (string) $movement['movement_id'], 'sync_service', $exception );
			return $this->finish( (string) $movement['movement_id'], self::STATUS_FAILED, new WP_Error( 'adam_google_sheets_unexpected', __( 'A sincronizaÃ§Ã£o Google Sheets falhou. Pode repetir a operaÃ§Ã£o.', 'adam-membership' ) ), $member, $renewal_id );
		} finally {
			$this->active_apd_request = null;
			if ( $lock_token === (string) get_option( $lock_key, '' ) ) {
				delete_option( $lock_key );
			}
		}
	}

	/** Perform validation, duplicate detection and a bounded table write while locked. */
	private function sync_locked( array $movement, Member $member, int $renewal_id = 0, ?ApdAssociationRequest $apd_request = null ): true|WP_Error {
		if ( ! $this->client->is_configured() ) {
			return $this->finish( $movement['movement_id'], self::STATUS_INACTIVE, true, $member, $renewal_id );
		}
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
			return $this->finish( $movement['movement_id'], self::STATUS_PENDING, new WP_Error( 'adam_google_sheets_payment_data_missing', __( 'Dados de pagamento em falta para sincronizar este movimento.', 'adam-membership' ) ), $member, $renewal_id, 0, array_keys( $missing ) );
		}
		$row = $this->row( $movement );
		$existing = $this->client->read_values( 'A5:L', (string) $movement['movement_id'] );
		if ( is_wp_error( $existing ) ) {
			$this->client->log_failure( (string) $movement['movement_id'], 'read_values', $existing );
			return $this->finish( $movement['movement_id'], self::STATUS_FAILED, $existing, $member, $renewal_id );
		}
		$plan = GoogleSheetsTablePlanner::plan( (array) ( $existing['values'] ?? array() ), (string) $movement['movement_id'] );
		if ( $plan['duplicate_row'] > 0 && ! $this->same_row( $plan['duplicate_values'], $row ) ) {
			$updated = $this->client->update_table_row( $row, (string) $movement['movement_id'] );
			if ( is_wp_error( $updated ) ) {
				$this->client->log_failure( (string) $movement['movement_id'], 'update', $updated );
				return $this->finish( $movement['movement_id'], self::STATUS_FAILED, $updated, $member, $renewal_id );
			}
			return $this->finish( $movement['movement_id'], self::STATUS_SYNCED, true, $member, $renewal_id, absint( $updated['row_number'] ?? $plan['duplicate_row'] ) );
		}
		if ( 'paid' !== (string) ( $movement['financial_status'] ?? '' ) ) {
			return $this->finish( $movement['movement_id'], self::STATUS_FAILED, new WP_Error( 'adam_google_sheets_financial_status_invalid', __( 'O movimento financeiro não está confirmado como Pago. Guarde novamente os dados de pagamento antes de sincronizar.', 'adam-membership' ) ), $member, $renewal_id );
		}
		if ( $plan['duplicate_row'] > 0 ) {
			$values = $plan['duplicate_values'];
			if ( $this->same_row( $values, $row ) ) {
				return $this->finish( $movement['movement_id'], self::STATUS_SYNCED, true, $member, $renewal_id, $plan['duplicate_row'] );
			}
			return $this->finish( $movement['movement_id'], self::STATUS_FAILED, new WP_Error( 'adam_google_sheets_conflict', __( 'Não foi possível atualizar o movimento existente.', 'adam-membership' ) ), $member, $renewal_id );
		}
		$appended = $this->client->append_table_row( $row, (string) $movement['movement_id'], absint( $plan['target_row'] ?? 0 ) );
		if ( is_wp_error( $appended ) ) {
			$this->client->log_failure( (string) $movement['movement_id'], 'append_or_confirmation', $appended );
			return $this->finish( $movement['movement_id'], self::STATUS_FAILED, $appended, $member, $renewal_id );
		}
		$range = (array) ( $appended['table']['range'] ?? array() );
		$row_number = absint( $range['endRowIndex'] ?? 0 );
		return $this->finish( $movement['movement_id'], self::STATUS_SYNCED, true, $member, $renewal_id, $row_number );
	}

	/** Convert canonical movement data into columns A:L. */
	private function row( array $movement ): array {
		return array( $movement['quota_type'], $movement['member_number'], $movement['name'], absint( $movement['year'] ), $movement['movement'], $movement['type'], (float) str_replace( ',', '.', (string) $movement['amount'] ), $movement['payment_date'], $movement['method'], $movement['status'], $movement['movement_id'], $movement['note'] );
	}

	/** Compare existing and expected rows without treating formatting as data. */
	private function same_row( array $stored, array $expected ): bool {
		return GoogleSheetsTablePlanner::rows_match( $stored, $expected );
	}

	/** Map the internal origin to the accounting label. */
	private function membership_type( string $origin ): string {
		return 'external_association' === $origin ? 'Aderente' : 'Efetivo';
	}

	/** Map the persisted workflow choice to the transaction classification. */
	private function quota_type( string $origin, string $movement ): string {
		if ( 'registration' === $movement ) {
			return 'external_association' === $origin ? 'Inscrição ADAM' : 'Inscrição ADAM/ANA';
		}
		return 'external_association' === $origin ? 'Renovação ADAM' : 'Renovação ADAM/ANA';
	}

	private function movement_label( string $quota_type ): string {
		return str_starts_with( $quota_type, 'Inscrição' ) ? 'Inscrição' : ( str_starts_with( $quota_type, 'Renovação' ) ? 'Renovação' : 'Associar APD/ANA' );
	}

	/** Persist status metadata and a safe audit entry. */
	private function finish( string $request_id, string $status, true|WP_Error $result, Member $member, int $renewal_id = 0, int $row_number = 0, array $missing_fields = array(), ?ApdAssociationRequest $apd_request = null ): true|WP_Error {
		$apd_request = $apd_request ?? $this->active_apd_request;
		$previous = $renewal_id > 0 ? (array) ( $this->renewals->find( $renewal_id )?->data()[ self::RENEWAL_SYNC ] ?? array() ) : (array) get_user_meta( $member->user_id(), self::REGISTRATION_DATA, true );
		$membership_year = null !== $apd_request ? $apd_request->membership_year() : ( $renewal_id > 0 ? absint( $this->renewals->find( $renewal_id )?->data()['membership_year'] ?? 0 ) : absint( get_user_meta( $member->user_id(), 'adam_membership_year', true ) ) );
		$meta = array( 'state' => $status, 'request_id' => $request_id, 'membership_year' => $membership_year, 'row_number' => $row_number, 'timestamp' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'last_error' => is_wp_error( $result ) ? $result->get_error_code() : '', 'missing_fields' => $missing_fields, 'retry_count' => 1 + absint( $previous['retry_count'] ?? 0 ) );
		if ( null !== $this->active_movement ) {
			$this->movements->save_sync_state( $this->active_movement, $meta );
		}
		if ( null !== $apd_request ) {
			$this->apd_repository->save_sync_state( $apd_request, $meta );
		} elseif ( $renewal_id <= 0 ) {
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
		$description = self::STATUS_INACTIVE === $status ? 'Google Sheets synchronization is not active.' : ( is_wp_error( $result ) ? 'Google Sheets synchronization failed.' : 'Google Sheets synchronization completed.' );
		$this->history->create( array( 'member_id' => $member->user_id(), 'member_number' => (string) $member->field( 'numero_socio' ), 'member_name' => $member->full_name(), 'member_email' => sanitize_email( $member->email() ), 'action_key' => 'google_sheets_sync_' . $status, 'action_label' => 'Google Sheets sync', 'actor_type' => 'system', 'actor_id' => 0, 'actor_name' => 'Sistema', 'description' => $description, 'details' => array( 'request_id' => $request_id, 'row_number' => $row_number, 'renewal_id' => $renewal_id ), 'created_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) ) );
		if ( is_wp_error( $result ) ) {
			$this->logger->error( 'Google Sheets synchronization failed.', array( 'request_id' => $request_id, 'status' => $status, 'error_code' => $result->get_error_code() ) );
			return $result;
		}
		return true;
	}
}
