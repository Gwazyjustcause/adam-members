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
use WP_User;

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
	 * Constructor.
	 */
	public function __construct(
		MemberRepository $members,
		SettingsRepository $settings,
		EmailService $email,
		Logger $logger
	) {
		$this->members  = $members;
		$this->settings = $settings;
		$this->email    = $email;
		$this->logger   = $logger;
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
				__( 'Member not found.', 'adam-membership' )
			);
		}

		/*
		 * Approve member.
		 */
		$member->approve();

		/*
		 * Promote WordPress role.
		 */
		$user = $member->user();

		if ( $user instanceof WP_User ) {
			$user->set_role( 'scio' );
		}

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
			'Member approved.',
			array(
				'user_id' => $user_id,
			)
		);

		$this->email->send_approval_email( $member );

		return true;
	}

	/**
	 * Reject a member.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	public function reject( int $user_id ): true|WP_Error {

		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error(
				'adam_membership_member_not_found',
				__( 'Member not found.', 'adam-membership' )
			);
		}

		$member->reject();

		$user = $member->user();

		if ( $user instanceof WP_User ) {
			$user->set_role( 'visitante' );
		}

		$this->logger->info(
			'Member rejected.',
			array(
				'user_id' => $user_id,
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
				__( 'Member not found.', 'adam-membership' )
			);
		}

		$base_timestamp = max( current_time( 'timestamp' ), $member->quota_expiry_timestamp() );

		$member->save(
			array(
				'estado'         => Member::STATUS_ACTIVE,
				'validade_quota' => wp_date( 'Y-m-d', strtotime( '+1 year', $base_timestamp ) ),
			)
		);

		$this->logger->info( 'Member quota renewed.', array( 'user_id' => $user_id ) );

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
				__( 'Member not found.', 'adam-membership' )
			);
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || false === strtotime( $date ) ) {
			return new WP_Error(
				'adam_membership_invalid_quota_date',
				__( 'Invalid quota validity date.', 'adam-membership' )
			);
		}

		$member->save(
			array(
				'validade_quota' => $date,
			)
		);

		$this->logger->info( 'Member quota validity changed.', array( 'user_id' => $user_id ) );

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
				__( 'Member not found.', 'adam-membership' )
			);
		}

		if ( ! $member->isActive() ) {
			return new WP_Error(
				'adam_membership_member_not_active',
				__( 'Only active members can receive the approval email.', 'adam-membership' )
			);
		}

		if ( ! $this->email->send_approval_email( $member ) ) {
			return new WP_Error(
				'adam_membership_approval_email_failed',
				__( 'The approval email could not be sent.', 'adam-membership' )
			);
		}

		$this->logger->info( 'Approval email resent.', array( 'user_id' => $user_id ) );

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
}
