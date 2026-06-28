<?php
/**
 * Scheduled membership maintenance.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

use AdamMembership\Helpers\Logger;
use AdamMembership\Member\HistoryService;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Member\RenewalRepository;
use AdamMembership\Member\RenewalService;
use Throwable;

/**
 * Runs recurring ADAM Membership maintenance tasks.
 */
final class MaintenanceService {
	public const CRON_HOOK = 'adam_membership_daily_maintenance';

	private MemberRepository $members;
	private RenewalRepository $renewals;
	private RenewalService $renewal_service;
	private Logger $logger;
	private HistoryService $history;

	/**
	 * Constructor.
	 */
	public function __construct( MemberRepository $members, RenewalRepository $renewals, RenewalService $renewal_service, Logger $logger, HistoryService $history ) {
		$this->members         = $members;
		$this->renewals        = $renewals;
		$this->renewal_service = $renewal_service;
		$this->logger          = $logger;
		$this->history         = $history;
	}

	/**
	 * Register runtime hooks.
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedule the daily maintenance event if it is missing.
	 */
	public static function activate(): void {
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
	}

	/**
	 * Remove scheduled maintenance events.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Run all daily membership maintenance tasks.
	 */
	public function run(): void {
		$this->logger->info( 'Membership maintenance started.' );

		foreach ( $this->members->all_members() as $member ) {
			try {
				$this->process_member( $member );
			} catch ( Throwable $throwable ) {
				$this->logger->error(
					'Membership maintenance failed for member.',
					array(
						'member_id' => $member->user_id(),
						'error'     => $throwable->getMessage(),
					)
				);
			}
		}

		$this->review_renewal_requests();
		$this->logger->info( 'Membership maintenance completed.' );
	}

	/**
	 * Process a single member.
	 *
	 * @param Member $member Member.
	 */
	private function process_member( Member $member ): void {
		if ( $member->isRejected() || $member->isRenewalPending() ) {
			return;
		}

		$expiry_timestamp = $member->quota_expiry_timestamp();

		if ( 0 === $expiry_timestamp ) {
			$this->logger->error( 'Membership maintenance found missing or invalid quota expiry.', array( 'member_id' => $member->user_id() ) );
			return;
		}

		$this->maybe_send_renewal_reminder( $member );
		$this->maybe_expire_member( $member, $expiry_timestamp );
	}

	/**
	 * Send the renewal reminder once per renewal cycle.
	 *
	 * @param Member $member Member.
	 */
	private function maybe_send_renewal_reminder( Member $member ): void {
		if ( ! $member->can_renew() ) {
			return;
		}

		$this->renewal_service->maybe_send_renewal_reminder( $member );
	}

	/**
	 * Expire active memberships after the quota expiry date passes.
	 *
	 * @param Member $member           Member.
	 * @param int    $expiry_timestamp Expiry timestamp.
	 */
	private function maybe_expire_member( Member $member, int $expiry_timestamp ): void {
		if ( Member::STATUS_ACTIVE !== $member->status() ) {
			return;
		}

		$today = strtotime( wp_date( 'Y-m-d', current_time( 'timestamp' ) ) );

		if ( false === $today || $expiry_timestamp >= $today ) {
			return;
		}

		$expiry_date = wp_date( 'Y-m-d', $expiry_timestamp );

		$member->save( array( 'estado' => Member::STATUS_EXPIRED ) );
		$this->logger->info(
			'Membership expired automatically.',
			array(
				'member_id'   => $member->user_id(),
				'expiry_date' => $expiry_date,
				'timestamp'   => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);
		$this->history->quota_expired( $member, $expiry_date );

		if ( $expiry_date === (string) get_user_meta( $member->user_id(), 'adam_membership_quota_expired_notice_sent_for', true ) ) {
			return;
		}

		if ( ! $this->renewal_service->send_quota_expired_notice( $member ) ) {
			$this->logger->error( 'Quota expired email failed during maintenance.', array( 'member_id' => $member->user_id(), 'expiry_date' => $expiry_date ) );
			return;
		}

		update_user_meta( $member->user_id(), 'adam_membership_quota_expired_notice_sent_for', $expiry_date );
	}

	/**
	 * Review renewal request state for scheduled cleanup needs.
	 */
	private function review_renewal_requests(): void {
		$completed = $this->renewals->admin_requests( array( 'status' => 'approved' ) );
		$rejected  = $this->renewals->admin_requests( array( 'status' => 'rejected' ) );

		$this->logger->info(
			'Renewal request maintenance reviewed historical requests.',
			array(
				'approved_count' => count( $completed ),
				'rejected_count' => count( $rejected ),
			)
		);
	}
}
