<?php
/** Separate operational queue for submitted membership workflows. */

declare(strict_types=1);

namespace AdamMembership\GoogleSheets;

use AdamMembership\Helpers\Logger;
use AdamMembership\Member\ApdAssociationRequest;
use AdamMembership\Member\Member;
use AdamMembership\Member\RenewalRequest;
use WP_Error;

final class GoogleSheetsMembershipWorkflowService {
	private const SHEET_NAME = 'Gestão de Sócios';
	private const SYNC_META = 'adam_membership_gestao_socios_sync';
	private const LOCK_PREFIX = 'adam_membership_gestao_socios_lock_';

	public function __construct( private GoogleSheetsClient $client, private Logger $logger ) {}

	public function sync_registration( Member $member ): true|WP_Error {
		$id = (string) get_user_meta( $member->user_id(), 'adam_membership_registration_request_uuid', true );
		$type = 'adam_primary' === (string) $member->field( 'adam_membership_origin' ) ? 'Inscrição ADAM/ANA' : 'Inscrição ADAM';
		return $this->sync( $id, $member, $type );
	}

	public function sync_renewal( RenewalRequest $request, Member $member ): true|WP_Error {
		$data = $request->submitted_data();
		$type = 'adam_primary' === (string) ( $data['adam_membership_origin'] ?? '' ) ? 'Renovação ADAM/ANA' : 'Renovação ADAM';
		return $this->sync( $request->request_uuid(), $member, $type );
	}

	public function sync_apd( ApdAssociationRequest $request, Member $member ): true|WP_Error {
		return $this->sync( $request->request_uuid(), $member, 'Associar APD/ANA' );
	}

	private function sync( string $request_id, Member $member, string $quota_type ): true|WP_Error {
		if ( '' === $request_id ) { return $this->failure( $member, '', new WP_Error( 'adam_google_sheets_request_id_missing', __( 'O identificador do pedido não está disponível.', 'adam-membership' ) ) ); }
		$lock_key = self::LOCK_PREFIX . md5( $request_id );
		$lock_time = absint( get_option( $lock_key, 0 ) );
		if ( $lock_time > 0 && $lock_time < time() - 60 ) { delete_option( $lock_key ); }
		if ( ! add_option( $lock_key, time(), '', 'no' ) ) { return new WP_Error( 'adam_google_sheets_sync_in_progress', __( 'Este pedido já está a ser sincronizado.', 'adam-membership' ) ); }
		try {
			$ids = $this->client->workflow_request_ids( self::SHEET_NAME, $request_id );
			if ( is_wp_error( $ids ) ) { return $this->failure( $member, $request_id, $ids ); }
			if ( in_array( $request_id, $ids, true ) ) { return $this->success( $member, $request_id, 'already_present', $quota_type ); }
			$ana = str_ends_with( $quota_type, '/ANA' ) || 'Associar APD/ANA' === $quota_type;
			$row = array( 'Tesoureiro', $quota_type, $member->full_name(), 'Por confirmar', $ana ? 'Espera' : 'Não aplicável', 'Espera', 'Por iniciar', '' );
			$result = $this->client->append_workflow_row( self::SHEET_NAME, $row, $request_id );
			return is_wp_error( $result ) ? $this->failure( $member, $request_id, $result ) : $this->success( $member, $request_id, 'appended', $quota_type );
		} catch ( \Throwable $exception ) {
			$this->logger->error( 'Gestão de Sócios synchronization threw an exception.', array( 'request_id' => $request_id, 'error_code' => 'adam_google_sheets_unexpected' ) );
			return $this->failure( $member, $request_id, new WP_Error( 'adam_google_sheets_unexpected', __( 'A sincronização da Gestão de Sócios falhou.', 'adam-membership' ) ) );
		} finally { delete_option( $lock_key ); }
	}

	private function success( Member $member, string $request_id, string $action, string $quota_type ): true {
		update_user_meta( $member->user_id(), self::SYNC_META . '_' . md5( $request_id ), array( 'state' => 'synced', 'request_id' => $request_id, 'quota_type' => $quota_type, 'action' => $action, 'updated_at' => current_time( 'mysql' ) ) );
		return true;
	}

	private function failure( Member $member, string $request_id, WP_Error $error ): WP_Error {
		if ( $member->user_id() > 0 ) { update_user_meta( $member->user_id(), self::SYNC_META . '_' . md5( $request_id ), array( 'state' => 'failed', 'request_id' => $request_id, 'last_error' => $error->get_error_code(), 'updated_at' => current_time( 'mysql' ) ) ); }
		$this->logger->error( 'Gestão de Sócios synchronization failed.', array( 'request_id' => $request_id, 'error_code' => $error->get_error_code() ) );
		return $error;
	}
}
