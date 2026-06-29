<?php
/**
 * Member approval service.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Core\SettingsRepository;
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

	/**
	 * Constructor.
	 */
	public function __construct(
		MemberRepository $members,
		SettingsRepository $settings,
		EmailService $email,
		Logger $logger,
		HistoryService $history,
		RecognitionService $recognition
	) {
		$this->members  = $members;
		$this->settings = $settings;
		$this->email    = $email;
		$this->logger   = $logger;
		$this->history  = $history;
		$this->recognition = $recognition;
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

		/*
		 * Approve member.
		 */
		$old_status = $member->status();
		$member->approve();
		$this->log_status_change( $member, $old_status, $member->status(), 'Sócio aprovado.' );

		/*
		 * Assign member number.
		 */
		if ( '' === (string) $member->field( 'numero_socio' ) ) {

			$member->save(
				array(
					'numero_socio' => $this->settings->reserve_next_member_number(),
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

		$this->email->send_approval_email( $member );

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

		if ( ! $this->email->send_approval_email( $member ) ) {
			return new WP_Error(
				'adam_membership_approval_email_failed',
				__( 'Não foi possível enviar o email de aprovação.', 'adam-membership' )
			);
		}

		$this->logger->info( 'Email de aprovação reenviado.', array( 'user_id' => $user_id ) );
		$this->history->approval_email_resent( $member );

		return true;
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
}
