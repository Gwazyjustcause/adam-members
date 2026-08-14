<?php
/**
 * Member approval service.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Document\PrivateDocumentRepository;
use AdamMembership\Emails\EmailService;
use AdamMembership\Helpers\Logger;
use WP_Error;

/**
 * Applies member approval workflow rules.
 */
final class ApprovalService {

	/**
	 * Member repository.
	 *
	 * @var MemberRepository
	 */
	private MemberRepository $members;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Email service.
	 *
	 * @var EmailService
	 */
	private EmailService $email;

	/**
	 * Logger helper.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Member history service.
	 *
	 * @var HistoryService
	 */
	private HistoryService $history;
	private RecognitionService $recognition;
	private PrivateDocumentRepository $private_documents;

	/**
	 * Constructor.
	 */
	public function __construct(
		MemberRepository $members,
		SettingsRepository $settings,
		EmailService $email,
		Logger $logger,
		HistoryService $history,
		RecognitionService $recognition,
		PrivateDocumentRepository $private_documents
	) {
		$this->members  = $members;
		$this->settings = $settings;
		$this->email    = $email;
		$this->logger   = $logger;
		$this->history  = $history;
		$this->recognition = $recognition;
		$this->private_documents = $private_documents;
	}

	/**
	 * Approve a member.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	public function approve( int $user_id ): true|WP_Error {

		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error(
				'adam_membership_member_not_found',
				__( 'Sócio não encontrado.', 'adam-membership' )
			);
		}

		if ( 'adam_primary' === (string) $member->field( 'adam_membership_origin' ) && '' === (string) $member->field( 'adam_apd_ana_confirmation_date' ) ) {
			return new WP_Error( 'adam_membership_ana_confirmation_required', __( 'A confirmação da ANA é necessária antes de aprovar esta inscrição.', 'adam-membership' ) );
		}
		$missing_documents = $this->missing_registration_documents( $member );

		if ( array() !== $missing_documents ) {
			return new WP_Error(
				'adam_membership_missing_documents',
				implode( ' ', $missing_documents )
			);
		}

		/*
		 * Approve member.
		 */
		$old_status = $member->status();
		$member->approve();
		$member->save( array( 'adam_correction_status' => '' ) );
		$this->log_status_change( $member, $old_status, $member->status(), 'Sócio aprovado.' );

		/*
		 * Assign member number.
		 */
		if ( '' === (string) $member->field( 'numero_socio' ) ) {
			$member_number = $this->next_available_member_number();

			$member->save(
				array(
					'numero_socio' => $member_number,
				)
			);
		}

		/*
		 * Set join date.
		 */
		if ( '' === (string) $member->field( 'data_adesao' ) ) {

			$member->save(
				array(
					'data_adesao' => $this->today(),
				)
			);
		}

		/*
		 * Set expiry date.
		 */
		if ( '' === (string) $member->field( 'validade_quota' ) ) {

			$member->save(
				array(
					'validade_quota' => $this->one_year_from_today(),
				)
			);
		}

		$this->logger->info(
			'Sócio aprovado.',
			array(
				'user_id' => $user_id,
			)
		);
		$this->recognition->handle_member_approved( $member );
		$this->history->member_approved( $member, $old_status, $member->status() );

		$this->email->send_approval_email( $member, $this->private_document( $member ) );
		/**
		 * The WordPress approval is complete. Integrations must treat failures
		 * from this notification as independent from the approval result.
		 */
		do_action( 'adam_membership_member_approved', $member );

		return true;
	}

	/**
	 * Reject a member.
	 *
	 * @param int    $user_id User ID.
	 * @param string $reason  Safe rejection reason.
	 * @param string $note    Private admin note.
	 * @return true|WP_Error
	 */
	public function reject( int $user_id, string $reason = '', string $note = '' ): true|WP_Error {

		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error(
				'adam_membership_member_not_found',
				__( 'Sócio não encontrado.', 'adam-membership' )
			);
		}

		if ( '' === $reason ) {
			return new WP_Error(
				'adam_membership_rejection_reason_required',
				__( 'Selecione um motivo de rejeição.', 'adam-membership' )
			);
		}

		$old_status = $member->status();

		if ( Member::STATUS_RENEWAL_PENDING === $old_status ) {
			$member->save(
				array(
					'estado'              => Member::STATUS_EXPIRED,
					'motivo_rejeicao'     => $reason,
					'nota_rejeicao_admin' => $note,
				)
			);
			$this->log_status_change( $member, $old_status, Member::STATUS_EXPIRED, 'Renovação do sócio rejeitada.' );
			$this->history->renewal_rejected( $member, 0, $reason );
			$this->email->send_renewal_rejected_email( $member, $reason );
		} else {
			$member->reject( $reason, $note );
			$member->save( array( 'adam_correction_status' => '' ) );
			$this->log_status_change( $member, $old_status, $member->status(), 'Sócio rejeitado.' );
			$this->history->member_rejected( $member, $old_status, $member->status(), $reason );
			$this->email->send_registration_rejected_email( $member, $reason );
		}

		$this->logger->info(
			'Sócio rejeitado.',
			array(
				'user_id' => $user_id,
				'reason'  => $reason,
			)
		);

		return true;
	}

	public function request_correction( int $user_id, string $reason = '', string $note = '', array $fields = array() ): true|WP_Error {
		$member = $this->members->find( $user_id );
		if ( null === $member ) { return new WP_Error( 'adam_membership_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) ); }
		if ( '' === trim( $reason ) || ( in_array( trim( $reason ), array( 'Outro', 'Outro motivo' ), true ) && '' === trim( $note ) ) ) { return new WP_Error( 'adam_membership_correction_reason_required', __( 'Indique o motivo e, quando aplicável, uma explicação.', 'adam-membership' ) ); }
		$fields = array_values( array_filter( array_map( 'sanitize_key', $fields ) ) );
		if ( array() === $fields ) { return new WP_Error( 'adam_membership_correction_fields_required', __( 'Selecione pelo menos um campo ou documento a corrigir.', 'adam-membership' ) ); }
		$history = is_array( $member->field( 'adam_correction_history' ) ) ? $member->field( 'adam_correction_history' ) : array();
		$round_id = count( $history ) + 1;
		$storage_map = array( 'full_name' => 'nome', 'birth_date' => 'data_nascimento', 'marital_status' => 'estado_civil', 'gender' => 'genero', 'profession' => 'profissao', 'birthplace' => 'naturalidade', 'nationality' => 'nacionalidade', 'phone' => 'telefone', 'telephone' => 'telefone_fixo', 'address_line_1' => 'morada', 'address_line_2' => 'morada_linha_2', 'postcode' => 'codigo_postal', 'city' => 'cidade', 'municipality' => 'municipio', 'country' => 'pais', 'citizen_card' => 'cartao_cidadao', 'document_expiry_date' => 'documento_validade', 'document_issuing_place' => 'documento_local_emissao', 'nif' => 'nif', 'team' => 'equipa', 'profile_photo' => 'profile_photo', 'payment_receipt' => 'payment_receipt', 'external_association_proof' => 'adam_external_association_proof' );
		$previous_values = array();
		foreach ( $fields as $field ) { $storage_key = $storage_map[ $field ] ?? $field; $previous_values[ $field ] = 'email' === $field ? $member->email() : $member->field( $storage_key ); }
		$history[] = array( 'id' => $round_id, 'status' => 'correction_requested', 'requested_at' => current_time( 'mysql' ), 'reason' => $reason, 'note' => $note, 'fields' => $fields, 'previous_values' => $previous_values );
		$member->save( array( 'estado' => Member::STATUS_REJECTED, 'motivo_rejeicao' => $reason, 'nota_rejeicao_admin' => $note, 'adam_correction_status' => 'correction_requested', 'adam_correction_reason' => $reason, 'adam_correction_note' => $note, 'adam_correction_fields' => $fields, 'adam_correction_active_round' => $round_id, 'adam_correction_history' => $history ) );
		try {
			$this->email->send_registration_correction_email( $member, $reason, $note );
		} catch ( \Throwable $exception ) {
			$this->logger->error( 'Correction notification failed.', array( 'user_id' => $user_id, 'error' => $exception->getMessage() ) );
		}
		return true;
	}

	/**
	 * Get missing required registration documents for a member awaiting approval.
	 *
	 * @param Member $member Member.
	 * @return array<int, string>
	 */
	public function missing_registration_documents( Member $member ): array {
		$warnings          = array();
		$association_mode  = 'external_association' === (string) $member->field( 'adam_membership_origin' ) ? 'external_association' : 'adam_primary';
		$fields            = $this->registration_document_fields();

		foreach ( $fields as $field ) {
			if ( empty( $field['required'] ) || ! $this->document_condition_required( (string) $field['conditional'], $association_mode ) ) {
				continue;
			}

			$value = $member->field( (string) $field['meta_key'] );
			$url   = $this->media_reference_url( $value );

			if ( '' !== $url ) {
				continue;
			}

			$warnings[] = sprintf(
				/* translators: %s: document label */
				__( '%s: em falta.', 'adam-membership' ),
				(string) $field['label']
			);
		}

		return $warnings;
	}

	/**
	 * Renew a member quota for one year.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	public function renew_quota( int $user_id ): true|WP_Error {
		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error(
				'adam_membership_member_not_found',
				__( 'Sócio não encontrado.', 'adam-membership' )
			);
		}

		if ( 'adam_primary' === (string) $member->field( 'adam_membership_origin' ) && '' === (string) $member->field( 'adam_apd_ana_confirmation_date' ) ) {
			return new WP_Error( 'adam_membership_ana_confirmation_required', __( 'A confirmação da ANA é necessária antes de aprovar esta inscrição.', 'adam-membership' ) );
		}

		$base_timestamp = max( current_time( 'timestamp' ), $member->quota_expiry_timestamp() );
		$old_status     = $member->status();
		$old_expiry     = (string) $member->field( 'validade_quota' );
		$new_expiry     = wp_date( 'Y-m-d', strtotime( '+1 year', $base_timestamp ) );

		$member->save(
			array(
				'estado'         => Member::STATUS_ACTIVE,
				'validade_quota' => $new_expiry,
			)
		);

		$this->log_status_change( $member, $old_status, Member::STATUS_ACTIVE, 'Quota do sócio renovada.' );
		$this->logger->info( 'Quota do sócio renovada.', array( 'user_id' => $user_id ) );
		$this->history->quota_date_changed( $member, $old_expiry, $new_expiry );
		$this->email->send_renewal_approved_email( $member );

		return true;
	}

	/** Record ANA confirmation and complete an initial ADAM/ANA registration. */
	public function confirm_ana_and_approve( int $user_id, string $date ): true|WP_Error {
		$member = $this->members->find( $user_id );
		if ( null === $member ) { return new WP_Error( 'adam_membership_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) ); }
		if ( '' === $date || false === strtotime( $date ) ) { return new WP_Error( 'adam_membership_invalid_ana_date', __( 'Indique uma data de confirmação ANA válida.', 'adam-membership' ) ); }
		$member->save( array( 'adam_apd_ana_confirmation_date' => $date, 'adam_apd_management_status' => Member::APD_MANAGED, 'data_adesao' => $date, 'validade_quota' => gmdate( 'Y-m-d', strtotime( '+1 year', strtotime( $date ) ) ) ) );
		return $this->approve( $user_id );
	}

	/**
	 * Change the member quota validity date.
	 *
	 * @param int    $user_id User ID.
	 * @param string $date    New quota validity date in Y-m-d format.
	 * @return true|WP_Error
	 */
	public function change_quota_validity( int $user_id, string $date ): true|WP_Error {
		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error(
				'adam_membership_member_not_found',
				__( 'Sócio não encontrado.', 'adam-membership' )
			);
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || false === strtotime( $date ) ) {
			return new WP_Error(
				'adam_membership_invalid_quota_date',
				__( 'Data de validade da quota inválida.', 'adam-membership' )
			);
		}

		$old_expiry = (string) $member->field( 'validade_quota' );

		$member->save(
			array(
				'validade_quota' => $date,
			)
		);

		$this->logger->info( 'Validade da quota do sócio alterada.', array( 'user_id' => $user_id ) );
		$this->history->quota_date_changed( $member, $old_expiry, $date );

		return true;
	}

	/**
	 * Resend the approval email to a member.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	public function resend_approval_email( int $user_id ): true|WP_Error {
		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error(
				'adam_membership_member_not_found',
				__( 'Sócio não encontrado.', 'adam-membership' )
			);
		}

		if ( ! $member->isActive() ) {
			return new WP_Error(
				'adam_membership_member_not_active',
				__( 'Apenas sócios ativos podem receber o email de aprovação.', 'adam-membership' )
			);
		}

		if ( ! $this->email->send_approval_email( $member, $this->private_document( $member ) ) ) {
			return new WP_Error(
				'adam_membership_approval_email_failed',
				__( 'Não foi possível enviar o email de aprovação.', 'adam-membership' )
			);
		}

		$this->logger->info( 'Email de aprovação reenviado.', array( 'user_id' => $user_id ) );
		$this->history->approval_email_resent( $member );

		return true;
	}

	/** Send only the registration document without repeating approval. */
	public function send_private_document( int $user_id ): true|WP_Error {
		$this->logger->info( 'Private document send trace v1: member service entered.', array( 'user_id' => $user_id ) );
		$member = $this->members->find( $user_id );
		if ( null === $member ) {
			return new WP_Error( 'adam_membership_member_not_found', __( 'Sócio não encontrado.', 'adam-membership' ) );
		}
		$document = $this->private_document( $member );
		$this->logger->info( 'Private document send trace v1: member document lookup completed.', array( 'user_id' => $user_id, 'document_found' => null !== $document ) );
		if ( null === $document || ! $this->email->send_private_document_email( $member, $document ) ) {
			return new WP_Error( 'adam_private_document_email_failed', __( 'Não foi possível enviar o documento ao sócio.', 'adam-membership' ) );
		}

		$this->logger->info( 'Private document sent to member.', array( 'member_id' => $user_id, 'document_id' => $document->id(), 'sha256' => $document->sha256() ) );

		return true;
	}

	private function private_document( Member $member ): ?\AdamMembership\Document\PrivateDocument {
		$reference = (string) get_user_meta( $member->user_id(), 'adam_membership_registration_request_uuid', true );
		if ( ! str_starts_with( $reference, 'registration:' ) ) {
			$reference = 'registration:legacy-' . $member->user_id();
		}

		return $this->private_documents->find_active( $reference );
	}

	/**
	 * Today's date.
	 */
	private function today(): string {
		return wp_date(
			'Y-m-d',
			current_time( 'timestamp' )
		);
	}

	/**
	 * One year from today.
	 */
	private function one_year_from_today(): string {
		return wp_date(
			'Y-m-d',
			strtotime(
				'+1 year',
				current_time( 'timestamp' )
			)
		);
	}

	/**
	 * Log a member status change.
	 *
	 * @param Member $member     Member.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @param string $message    Log message.
	 */
	private function log_status_change( Member $member, string $old_status, string $new_status, string $message ): void {
		if ( $old_status === $new_status ) {
			return;
		}

		$this->logger->info(
			$message,
			array(
				'user_id'    => $member->user_id(),
				'old_status' => $old_status,
				'new_status' => $new_status,
			)
		);
	}

	/**
	 * Get registration upload field definitions.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function registration_document_fields(): array {
		$settings = $this->settings->membership_form_settings();
		$fields   = isset( $settings['registration_fields'] ) && is_array( $settings['registration_fields'] ) ? $settings['registration_fields'] : array();
		$rows     = array();

		foreach ( $fields as $field_key => $config ) {
			if ( ! is_string( $field_key ) || ! is_array( $config ) || empty( $config['enabled'] ) || 'file' !== (string) ( $config['type'] ?? '' ) ) {
				continue;
			}

			$rows[] = array(
				'label'      => is_string( $config['label'] ?? null ) ? (string) $config['label'] : $field_key,
				'required'   => ! empty( $config['required'] ),
				'conditional'=> is_string( $config['conditional'] ?? null ) ? (string) $config['conditional'] : 'always',
				'meta_key'   => ! empty( $config['locked'] ) ? $this->document_meta_key( $field_key ) : 'adam_custom_' . sanitize_key( $field_key ),
				'order'      => absint( $config['order'] ?? 999 ),
			);
		}

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return (int) ( $left['order'] ?? 999 ) <=> (int) ( $right['order'] ?? 999 );
			}
		);

		return $rows;
	}

	/**
	 * Check whether a conditional document is required for the member flow.
	 *
	 * @param string $condition Condition key.
	 * @param string $association_mode Association mode.
	 */
	private function document_condition_required( string $condition, string $association_mode ): bool {
		return match ( $condition ) {
			'registration_external' => 'external_association' === $association_mode,
			default                 => true,
		};
	}

	/**
	 * Resolve the stored member meta key for a configured document field.
	 *
	 * @param string $field_key Form field key.
	 */
	private function document_meta_key( string $field_key ): string {
		return match ( $field_key ) {
			'external_association_proof' => 'adam_external_association_proof',
			default                      => $field_key,
		};
	}

	/**
	 * Resolve a media reference to a URL.
	 *
	 * @param mixed $value Stored media reference.
	 */
	private function media_reference_url( mixed $value ): string {
		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_url( absint( $value ) );

			return false !== $url ? $url : '';
		}

		if ( is_string( $value ) && '' !== trim( $value ) && wp_http_validate_url( trim( $value ) ) ) {
			return trim( $value );
		}

		return '';
	}

	/**
	 * Reserve the next unique ADAM member number.
	 */
	private function next_available_member_number(): string {
		do {
			$member_number = $this->settings->reserve_next_member_number();
		} while ( $this->members->member_number_exists( $member_number ) );

		return $member_number;
	}
}
