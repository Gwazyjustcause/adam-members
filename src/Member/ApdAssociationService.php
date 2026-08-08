<?php
declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Core\SettingsRepository;
use WP_Error;

final class ApdAssociationService {
	public function __construct( private ApdAssociationRepository $repository, private MemberRepository $members, private SettingsRepository $settings ) {}

	public function repository(): ApdAssociationRepository { return $this->repository; }

	public function eligible( Member $member ): bool {
		$status = (string) $member->field( 'adam_apd_management_status' );
		if ( Member::APD_EXTERNAL === $status && 'adam_primary' === (string) $member->field( 'adam_membership_origin' ) && '' === (string) $member->field( 'adam_apd_ana_confirmation_date' ) ) { $status = Member::APD_MANAGED; }
		return $member->isActive() && Member::APD_MANAGED !== $status && ! $this->repository->has_active( $member->user_id() );
	}

	public function price_for( Member $member, string $date = '' ): string {
		$start = $member->join_date_timestamp();
		$now = $date ? strtotime( $date ) : current_time( 'timestamp' );
		$months = 0;
		if ( $start && $now ) { $months = max( 0, (int) floor( ( $now - $start ) / MONTH_IN_SECONDS / 30.4375 ) ); }
		$fees = (array) ( $this->settings->membership_form_settings()['apd_association_fees'] ?? array() );
		$key = $months <= 3 ? '0_3' : ( $months <= 6 ? '4_6' : ( $months <= 9 ? '7_9' : '10_plus' ) );
		return (string) ( $fees[ $key ] ?? ( $key === '0_3' ? '12.00' : ( $key === '4_6' ? '14.00' : ( $key === '7_9' ? '17.00' : '22.00' ) ) ) );
	}

	public function submit( Member $member, array $data, string $receipt = '' ): ApdAssociationRequest|WP_Error {
		if ( ! $this->eligible( $member ) ) { return new WP_Error( 'adam_apd_not_eligible', __( 'Este pedido de associaÃ§Ã£o APD nÃ£o estÃ¡ disponÃ­vel.', 'adam-membership' ) ); }
		$requested = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
		$request = $this->repository->create( array( 'user_id' => $member->user_id(), 'member_number' => (string) $member->field( 'numero_socio' ), 'requested_at' => $requested, 'membership_start' => (string) $member->field( 'data_adesao' ), 'amount' => $this->price_for( $member, $requested ), 'payment_status' => '' === $receipt ? 'pending' : 'submitted', 'proof_of_payment' => $receipt, 'submitted_data' => $data ) );
		$member->save( array( 'adam_apd_management_status' => Member::APD_PENDING ) );
		return $request;
	}

	public function confirm( int $request_id, string $date ): true|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( null === $request ) { return new WP_Error( 'adam_apd_request_not_found', __( 'Pedido APD nÃ£o encontrado.', 'adam-membership' ) ); }
		$member = $this->members->find( $request->user_id() );
		if ( null === $member ) { return new WP_Error( 'adam_member_not_found', __( 'SÃ³cio nÃ£o encontrado.', 'adam-membership' ) ); }
		$member->save( array( 'adam_apd_management_status' => Member::APD_MANAGED, 'adam_apd_ana_confirmation_date' => $date, 'data_adesao' => $date, 'validade_quota' => gmdate( 'Y-m-d', strtotime( '+1 year', strtotime( $date ) ) ), 'estado' => Member::STATUS_ACTIVE ) );
		$this->repository->update( $request, array( 'status' => ApdAssociationRequest::STATUS_CONFIRMED, 'ana_confirmation_date' => $date, 'reviewed_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'reviewed_by' => get_current_user_id() ) );
		return true;
	}
}
