<?php
declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Core\CorrectionFieldCatalog;
use AdamMembership\Emails\EmailService;
use WP_Error;

final class ApdAssociationService {
	public function __construct( private ApdAssociationRepository $repository, private MemberRepository $members, private SettingsRepository $settings, private ?EmailService $email = null ) {}

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
		if ( ! $this->eligible( $member ) ) { return new WP_Error( 'adam_apd_not_eligible', __( 'Este pedido de associação APD não está disponível.', 'adam-membership' ) ); }
		if ( '' === trim( $receipt ) ) { return new WP_Error( 'adam_apd_receipt_required', __( 'O comprovativo de pagamento é obrigatório.', 'adam-membership' ) ); }
		$year = absint( $data['membership_year'] ?? 0 );
		$amount = str_replace( ',', '.', sanitize_text_field( (string) ( $data['payment_amount'] ?? '' ) ) );
		$date = sanitize_text_field( (string) ( $data['payment_date'] ?? '' ) );
		$method = sanitize_text_field( (string) ( $data['payment_method'] ?? '' ) );
		$parsed_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		$methods = array( 'Transferência bancária', 'MB WAY', 'Cartão', 'Numerário', 'Outro' );
		if ( $year < 2000 || $year > 2100 || ! is_numeric( $amount ) || (float) $amount <= 0 || false === $parsed_date || $parsed_date->format( 'Y-m-d' ) !== $date || ! in_array( $method, $methods, true ) ) {
			return new WP_Error( 'adam_apd_payment_data_required', __( 'Indique o ano, valor efetivamente pago, data e método de pagamento.', 'adam-membership' ) );
		}
		$requested = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );
		$request = $this->repository->create( array( 'request_uuid' => 'apd:' . wp_generate_uuid4(), 'quota_type' => 'Associar APD/ANA', 'user_id' => $member->user_id(), 'member_number' => (string) $member->field( 'numero_socio' ), 'requested_at' => $requested, 'membership_start' => (string) $member->field( 'data_adesao' ), 'membership_year' => $year, 'payment_amount' => number_format( (float) $amount, 2, '.', '' ), 'payment_date' => $date, 'payment_method' => $method, 'amount' => number_format( (float) $amount, 2, '.', '' ), 'payment_status' => 'submitted', 'proof_of_payment' => $receipt, 'submitted_data' => $data ) );
		$member->save( array( 'adam_apd_management_status' => Member::APD_PENDING ) );
		if ( null !== $this->email ) { $this->email->send_apd_association_received_email( $member, $request->amount() ); }
		try {
			do_action( 'adam_membership_apd_association_submitted', $request, $member );
		} catch ( \Throwable $exception ) {
			// External operational synchronization must not undo a valid APD request.
		}
		return $request;
	}

	public function confirm( int $request_id, string $date, string $ana_member_number = '' ): true|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( null !== $request && ApdAssociationRequest::STATUS_SUBMITTED_ANA !== $request->status() ) { return new WP_Error( 'adam_apd_not_ready', __( 'O pedido só pode ser aprovado depois de ser submetido à ANA.', 'adam-membership' ) ); }
		if ( null !== $request && ( '' === trim( $date ) || '' === trim( $ana_member_number ) ) ) { return new WP_Error( 'adam_apd_confirmation_required', __( 'Indique a data de confirmação e o número ANA.', 'adam-membership' ) ); }
		if ( null === $request ) { return new WP_Error( 'adam_apd_request_not_found', __( 'Pedido APD não encontrado.', 'adam-membership' ) ); }
		$member = $this->members->find( $request->user_id() );
		if ( null === $member ) { return new WP_Error( 'adam_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) ); }
		$updates = array( 'adam_apd_management_status' => Member::APD_MANAGED, 'adam_apd_ana_confirmation_date' => $date, 'adam_external_association_name' => 'ANA', 'adam_external_member_number' => $ana_member_number, 'estado' => Member::STATUS_ACTIVE );
		$submitted = $request->data()['submitted_data'] ?? array();
		if ( is_array( $submitted ) ) {
			if ( isset( $submitted['full_name'] ) && is_string( $submitted['full_name'] ) ) {
				$name_parts = preg_split( '/\s+/', trim( $submitted['full_name'] ) ) ?: array();
				$first_name = sanitize_text_field( (string) array_shift( $name_parts ) );
				$last_name  = sanitize_text_field( implode( ' ', $name_parts ) );
				update_user_meta( $member->user_id(), 'first_name', $first_name );
				update_user_meta( $member->user_id(), 'last_name', $last_name );
				wp_update_user( array( 'ID' => $member->user_id(), 'display_name' => trim( $first_name . ' ' . $last_name ), 'nickname' => trim( $first_name . ' ' . $last_name ) ) );
			}
			if ( isset( $submitted['email'] ) && is_email( (string) $submitted['email'] ) ) { wp_update_user( array( 'ID' => $member->user_id(), 'user_email' => sanitize_email( (string) $submitted['email'] ) ) ); }
			foreach ( array( 'data_nascimento' => 'birth_date', 'genero' => 'gender', 'estado_civil' => 'marital_status', 'profissao' => 'profession', 'naturalidade' => 'birthplace', 'nacionalidade' => 'nationality', 'telefone' => 'phone', 'telefone_fixo' => 'telephone', 'morada' => 'address_line_1', 'morada_linha_2' => 'address_line_2', 'codigo_postal' => 'postcode', 'cidade' => 'city', 'municipio' => 'municipality', 'pais' => 'country', 'cartao_cidadao' => 'citizen_card', 'documento_validade' => 'document_expiry_date', 'documento_local_emissao' => 'document_issuing_place', 'nif' => 'nif', 'equipa' => 'team' ) as $member_field => $submitted_field ) {
				if ( isset( $submitted[ $submitted_field ] ) ) { $updates[ $member_field ] = sanitize_text_field( (string) $submitted[ $submitted_field ] ); }
			}
			if ( ! empty( $submitted['remove_profile_photo'] ) ) { $updates['profile_photo'] = ''; } elseif ( ! empty( $submitted['profile_photo'] ) ) { $updates['profile_photo'] = absint( $submitted['profile_photo'] ); }
			if ( isset( $submitted['external_association_name'] ) ) { $updates['adam_external_association_name'] = sanitize_text_field( (string) $submitted['external_association_name'] ); }
			if ( isset( $submitted['external_member_number'] ) ) { $updates['adam_external_member_number'] = sanitize_text_field( (string) $submitted['external_member_number'] ); }
			if ( isset( $submitted['external_association_proof'] ) ) { $updates['adam_external_association_proof'] = absint( $submitted['external_association_proof'] ); }
		}
		$member->save( $updates );
		$confirmed = $this->repository->update( $request, array( 'status' => ApdAssociationRequest::STATUS_CONFIRMED, 'ana_confirmation_date' => $date, 'reviewed_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'reviewed_by' => get_current_user_id() ) );
		do_action( 'adam_membership_apd_association_approved', $confirmed, $member );
		if ( null !== $this->email ) { $this->email->send_apd_association_approved_email( $member, $ana_member_number ); }
		return true;
	}

	public function mark_payment_received( int $request_id ): true|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( null === $request ) { return new WP_Error( 'adam_apd_request_not_found', __( 'Pedido APD não encontrado.', 'adam-membership' ) ); }
		if ( ApdAssociationRequest::STATUS_PENDING_PAYMENT !== $request->status() ) { return new WP_Error( 'adam_apd_payment_stage', __( 'O pagamento já foi processado neste pedido.', 'adam-membership' ) ); }
		$this->repository->update( $request, array( 'payment_status' => 'paid', 'status' => ApdAssociationRequest::STATUS_AWAITING_ADAM, 'payment_confirmed_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'payment_confirmed_by' => get_current_user_id() ) );
		return true;
	}

	public function submit_to_ana( int $request_id ): true|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( null === $request ) { return new WP_Error( 'adam_apd_request_not_found', __( 'Pedido APD não encontrado.', 'adam-membership' ) ); }
		if ( ApdAssociationRequest::STATUS_AWAITING_ADAM !== $request->status() ) { return new WP_Error( 'adam_apd_submit_stage', __( 'Confirme primeiro o pagamento deste pedido.', 'adam-membership' ) ); }
		$this->repository->update( $request, array( 'status' => ApdAssociationRequest::STATUS_SUBMITTED_ANA, 'submitted_to_ana_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'submitted_to_ana_by' => get_current_user_id() ) );
		return true;
	}

	public function reject( int $request_id, string $reason, string $note = '' ): true|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( null === $request ) { return new WP_Error( 'adam_apd_request_not_found', __( 'Pedido APD não encontrado.', 'adam-membership' ) ); }
		if ( '' === trim( $reason ) ) { return new WP_Error( 'adam_apd_rejection_reason', __( 'Indique o motivo da rejeição.', 'adam-membership' ) ); }
		$member = $this->members->find( $request->user_id() );
		if ( null !== $member && Member::APD_PENDING === (string) $member->field( 'adam_apd_management_status' ) ) { $member->save( array( 'adam_apd_management_status' => Member::APD_EXTERNAL ) ); }
		$this->repository->update( $request, array( 'status' => ApdAssociationRequest::STATUS_REJECTED, 'rejection_reason' => sanitize_text_field( $reason ), 'rejection_note' => sanitize_textarea_field( $note ), 'reviewed_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ), 'reviewed_by' => get_current_user_id() ) );
		if ( null !== $this->email && null !== $member ) { $this->email->send_apd_association_rejected_email( $member, $reason ); }
		return true;
	}

	public function request_correction( int $request_id, string $reason, string $note = '', array $fields = array() ): true|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( null === $request || in_array( $request->status(), array( ApdAssociationRequest::STATUS_CONFIRMED, ApdAssociationRequest::STATUS_REJECTED ), true ) ) { return new WP_Error( 'adam_apd_request_invalid', __( 'Este pedido já não pode ser corrigido.', 'adam-membership' ) ); }
		if ( '' === trim( $reason ) || ( 'Outro motivo' === trim( $reason ) && '' === trim( $note ) ) ) { return new WP_Error( 'adam_apd_correction_reason', __( 'Indique o motivo e, quando aplicável, uma explicação.', 'adam-membership' ) ); }
		$fields = array_values( array_intersect( array_unique( array_map( 'sanitize_key', $fields ) ), array_merge( array_keys( CorrectionFieldCatalog::labels() ), array( 'payment_receipt' ) ) ) );
		if ( array() === $fields ) { return new WP_Error( 'adam_apd_correction_fields', __( 'Selecione pelo menos um campo ou documento a corrigir.', 'adam-membership' ) ); }
		$this->repository->update( $request, array( 'status' => ApdAssociationRequest::STATUS_CORRECTION_REQUESTED, 'correction_fields' => $fields, 'correction_reason' => sanitize_text_field( $reason ), 'correction_note' => sanitize_textarea_field( $note ), 'correction_requested_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) ) );
		return true;
	}

	/** Resubmit the same APD request after selected member information is corrected. */
	public function submit_correction( int $request_id, int $user_id, array $submitted, mixed $proof_of_payment = null ): true|WP_Error {
		$request = $this->repository->find( $request_id );
		if ( null === $request || $request->user_id() !== $user_id || ApdAssociationRequest::STATUS_CORRECTION_REQUESTED !== $request->status() ) { return new WP_Error( 'adam_apd_correction_invalid', __( 'Este pedido já não pode ser corrigido.', 'adam-membership' ) ); }
		$allowed = $request->correction_fields();
		$clean = (array) ( $request->data()['submitted_data'] ?? array() );
		foreach ( $allowed as $field ) {
			if ( 'payment_receipt' === $field ) {
				if ( ! is_string( $proof_of_payment ) || '' === trim( $proof_of_payment ) ) { return new WP_Error( 'adam_apd_correction_payment_required', __( 'Envie o comprovativo de pagamento solicitado antes de reenviar o pedido.', 'adam-membership' ) ); }
				continue;
			}
			if ( ! array_key_exists( $field, $submitted ) || ! is_scalar( $submitted[ $field ] ) || '' === trim( (string) $submitted[ $field ] ) ) { return new WP_Error( 'adam_apd_correction_field_required', __( 'Preencha todos os campos solicitados antes de reenviar o pedido.', 'adam-membership' ) ); }
			$clean[ $field ] = sanitize_text_field( (string) $submitted[ $field ] );
		}
		$updates = array( 'status' => ApdAssociationRequest::STATUS_AWAITING_ADAM, 'submitted_data' => $clean, 'correction_resubmitted_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ) );
		if ( is_string( $proof_of_payment ) && '' !== trim( $proof_of_payment ) ) { $updates['proof_of_payment'] = esc_url_raw( $proof_of_payment ); }
		$this->repository->update( $request, $updates );
		return true;
	}
}
