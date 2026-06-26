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
	 * Create the approval service.
	 *
	 * @param MemberRepository  $members  Member repository.
	 * @param SettingsRepository $settings Settings repository.
	 * @param EmailService      $email    Email service.
	 * @param Logger            $logger   Logger helper.
	 */
	public function __construct( MemberRepository $members, SettingsRepository $settings, EmailService $email, Logger $logger ) {
		$this->members  = $members;
		$this->settings = $settings;
		$this->email    = $email;
		$this->logger   = $logger;
	}

	/**
	 * Approve a pending member.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return true|WP_Error
	 */
	public function approve( int $user_id ): true|WP_Error {
		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error( 'adam_membership_member_not_found', __( 'Member not found.', 'adam-membership' ) );
		}

		$data = array(
			'estado' => Member::STATUS_ACTIVE,
		);

		if ( '' === (string) $member->field( 'numero_socio' ) ) {
			$data['numero_socio'] = $this->settings->reserve_next_member_number();
		}

		if ( '' === (string) $member->field( 'data_adesao' ) ) {
			$data['data_adesao'] = $this->today();
		}

		if ( '' === (string) $member->field( 'validade_quota' ) ) {
			$data['validade_quota'] = $this->one_year_from_today();
		}

		$member->save( $data );
		$this->logger->info( 'Member approved.', array( 'user_id' => $user_id ) );
		$this->email->send_approval_email( $member );

		return true;
	}

	/**
	 * Reject a pending member without deleting the user account.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return true|WP_Error
	 */
	public function reject( int $user_id ): true|WP_Error {
		$member = $this->members->find( $user_id );

		if ( null === $member ) {
			return new WP_Error( 'adam_membership_member_not_found', __( 'Member not found.', 'adam-membership' ) );
		}

		$member->save( array( 'estado' => Member::STATUS_REJECTED ) );
		$this->logger->info( 'Member rejected.', array( 'user_id' => $user_id ) );

		return true;
	}

	/**
	 * Get today's date in the WordPress timezone.
	 */
	private function today(): string {
		return wp_date( 'Y-m-d', current_time( 'timestamp' ) );
	}

	/**
	 * Get the date one year from today in the WordPress timezone.
	 */
	private function one_year_from_today(): string {
		return wp_date( 'Y-m-d', strtotime( '+1 year', current_time( 'timestamp' ) ) );
	}
}
