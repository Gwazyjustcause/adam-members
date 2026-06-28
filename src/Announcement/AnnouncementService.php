<?php
/**
 * Announcement service.
 *
 * @package AdamMembership\Announcement
 */

declare(strict_types=1);

namespace AdamMembership\Announcement;

use AdamMembership\Emails\EmailService;
use AdamMembership\Helpers\Logger;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use WP_Error;

/**
 * Coordinates announcement publication, visibility, and reads.
 */
final class AnnouncementService {
	private const READ_META_KEY = 'adam_membership_announcement_reads';

	/**
	 * Repository.
	 *
	 * @var AnnouncementRepository
	 */
	private AnnouncementRepository $repository;

	/**
	 * Members.
	 *
	 * @var MemberRepository
	 */
	private MemberRepository $members;

	/**
	 * Email service.
	 *
	 * @var EmailService
	 */
	private EmailService $email;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 */
	public function __construct( AnnouncementRepository $repository, MemberRepository $members, EmailService $email, Logger $logger ) {
		$this->repository = $repository;
		$this->members    = $members;
		$this->email      = $email;
		$this->logger     = $logger;
	}

	/**
	 * Save an announcement.
	 *
	 * @param array<string, mixed> $data Announcement data.
	 * @param int                  $id   Optional existing ID.
	 * @return Announcement|WP_Error
	 */
	public function save( array $data, int $id = 0 ): Announcement|WP_Error {
		$prepared = $this->sanitize_data( $data );

		if ( '' === $prepared['title'] ) {
			return new WP_Error( 'adam_membership_announcement_title_required', __( 'Announcement title is required.', 'adam-membership' ) );
		}

		if ( '' === $prepared['content'] ) {
			return new WP_Error( 'adam_membership_announcement_content_required', __( 'Announcement content is required.', 'adam-membership' ) );
		}

		$now = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );

		if ( 0 === $id ) {
			$prepared['created_at'] = $now;
			$prepared['updated_at'] = $now;
			$announcement           = $this->repository->create( $prepared );
			$this->logger->info( 'Announcement created.', array( 'announcement_id' => $announcement->id() ) );
		} else {
			$current = $this->repository->find( $id );

			if ( null === $current ) {
				return new WP_Error( 'adam_membership_announcement_not_found', __( 'Announcement not found.', 'adam-membership' ) );
			}

			$prepared['updated_at'] = $now;
			$announcement           = $this->repository->update( $current, $prepared );
			$this->logger->info( 'Announcement updated.', array( 'announcement_id' => $announcement->id() ) );
		}

		$this->maybe_send_announcement_email( $announcement );

		return $announcement;
	}

	/**
	 * Archive an announcement.
	 *
	 * @param int $announcement_id Announcement ID.
	 * @return true|WP_Error
	 */
	public function archive( int $announcement_id ): true|WP_Error {
		$announcement = $this->repository->find( $announcement_id );

		if ( null === $announcement ) {
			return new WP_Error( 'adam_membership_announcement_not_found', __( 'Announcement not found.', 'adam-membership' ) );
		}

		$this->repository->update(
			$announcement,
			array(
				'status'     => Announcement::STATUS_ARCHIVED,
				'updated_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);

		$this->logger->info( 'Announcement archived.', array( 'announcement_id' => $announcement_id ) );

		return true;
	}

	/**
	 * Delete an announcement.
	 *
	 * @param int $announcement_id Announcement ID.
	 */
	public function delete( int $announcement_id ): void {
		$this->repository->delete( $announcement_id );
		$this->logger->info( 'Announcement deleted.', array( 'announcement_id' => $announcement_id ) );
	}

	/**
	 * Get visible announcements for a member.
	 *
	 * @param Member $member Member.
	 * @return array<int, Announcement>
	 */
	public function visible_for_member( Member $member ): array {
		$announcements = $this->repository->query();

		return array_values(
			array_filter(
				$announcements,
				function ( Announcement $announcement ) use ( $member ): bool {
					return $announcement->is_visible() && $this->matches_member( $announcement, $member );
				}
			)
		);
	}

	/**
	 * Find a visible member announcement.
	 *
	 * @param Member $member          Member.
	 * @param int    $announcement_id Announcement ID.
	 */
	public function visible_announcement( Member $member, int $announcement_id ): ?Announcement {
		$announcement = $this->repository->find( $announcement_id );

		if ( null === $announcement || ! $announcement->is_visible() || ! $this->matches_member( $announcement, $member ) ) {
			return null;
		}

		return $announcement;
	}

	/**
	 * Mark an announcement as read.
	 *
	 * @param Member        $member       Member.
	 * @param Announcement  $announcement Announcement.
	 */
	public function mark_read( Member $member, Announcement $announcement ): void {
		$reads                           = $this->member_reads( $member->user_id() );
		$reads[ $announcement->id() ]    = wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) );

		update_user_meta( $member->user_id(), self::READ_META_KEY, $reads );
	}

	/**
	 * Check whether an announcement is unread for a member.
	 *
	 * @param Member       $member       Member.
	 * @param Announcement $announcement Announcement.
	 */
	public function is_unread( Member $member, Announcement $announcement ): bool {
		$reads = $this->member_reads( $member->user_id() );

		return ! isset( $reads[ $announcement->id() ] );
	}

	/**
	 * Get read statistics.
	 *
	 * @param Announcement $announcement Announcement.
	 * @return array{targeted:int,read:int,unread:int}
	 */
	public function stats( Announcement $announcement ): array {
		$targeted = 0;
		$read     = 0;

		foreach ( $this->target_members( $announcement ) as $member ) {
			++$targeted;

			if ( ! $this->is_unread( $member, $announcement ) ) {
				++$read;
			}
		}

		return array(
			'targeted' => $targeted,
			'read'     => $read,
			'unread'   => max( 0, $targeted - $read ),
		);
	}

	/**
	 * Get admin list data.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, Announcement>
	 */
	public function admin_list( array $filters = array() ): array {
		return $this->repository->query( $filters );
	}

	/**
	 * Get repository instance.
	 */
	public function repository(): AnnouncementRepository {
		return $this->repository;
	}

	/**
	 * Get category labels.
	 *
	 * @return array<int, string>
	 */
	public function categories(): array {
		return array(
			'Assembleia Geral',
			'Eventos',
			'Quotas',
			'Regulamentos',
			'Seguro',
			'Seguranca',
			'Website',
			'Informacao Geral',
			'Urgente',
		);
	}

	/**
	 * Sanitize announcement data.
	 *
	 * @param array<string, mixed> $data Raw announcement data.
	 * @return array<string, mixed>
	 */
	private function sanitize_data( array $data ): array {
		$status = isset( $data['status'] ) ? sanitize_key( (string) $data['status'] ) : Announcement::STATUS_DRAFT;

		if ( ! in_array( $status, Announcement::statuses(), true ) ) {
			$status = Announcement::STATUS_DRAFT;
		}

		$priority = isset( $data['priority'] ) ? sanitize_key( (string) $data['priority'] ) : Announcement::PRIORITY_INFO;

		if ( ! in_array( $priority, Announcement::priorities(), true ) ) {
			$priority = Announcement::PRIORITY_INFO;
		}

		$audience = isset( $data['target_audience'] ) ? sanitize_key( (string) $data['target_audience'] ) : Announcement::AUDIENCE_ALL_MEMBERS;

		if ( ! in_array( $audience, Announcement::audiences(), true ) ) {
			$audience = Announcement::AUDIENCE_ALL_MEMBERS;
		}

		return array(
			'title'           => isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '',
			'summary'         => isset( $data['summary'] ) ? sanitize_textarea_field( (string) $data['summary'] ) : '',
			'content'         => isset( $data['content'] ) ? wp_kses_post( (string) $data['content'] ) : '',
			'category'        => isset( $data['category'] ) ? sanitize_text_field( (string) $data['category'] ) : '',
			'priority'        => $priority,
			'status'          => $status,
			'publish_date'    => $this->sanitize_date( (string) ( $data['publish_date'] ?? '' ) ),
			'expiry_date'     => $this->sanitize_date( (string) ( $data['expiry_date'] ?? '' ) ),
			'target_audience' => $audience,
			'pinned'          => ! empty( $data['pinned'] ),
			'action_label'    => isset( $data['action_label'] ) ? sanitize_text_field( (string) $data['action_label'] ) : '',
			'action_url'      => isset( $data['action_url'] ) ? esc_url_raw( (string) $data['action_url'] ) : '',
			'send_email'      => ! empty( $data['send_email'] ),
			'email_sent_at'   => sanitize_text_field( (string) ( $data['email_sent_at'] ?? '' ) ),
		);
	}

	/**
	 * Send optional announcement email.
	 *
	 * @param Announcement $announcement Announcement.
	 */
	private function maybe_send_announcement_email( Announcement $announcement ): void {
		if ( ! $announcement->send_email() || Announcement::STATUS_PUBLISHED !== $announcement->effective_status() || '' !== $announcement->email_sent_at() ) {
			return;
		}

		$sent_count = 0;

		foreach ( $this->target_members( $announcement ) as $member ) {
			if ( $this->email->send_announcement_email( $member, $announcement ) ) {
				++$sent_count;
			}
		}

		$this->repository->update(
			$announcement,
			array(
				'email_sent_at' => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
				'updated_at'    => wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) ),
			)
		);

		$this->logger->info(
			'Announcement email dispatched.',
			array(
				'announcement_id' => $announcement->id(),
				'recipients'      => $sent_count,
			)
		);
	}

	/**
	 * Get target members for an announcement.
	 *
	 * @param Announcement $announcement Announcement.
	 * @return array<int, Member>
	 */
	private function target_members( Announcement $announcement ): array {
		return array_values(
			array_filter(
				$this->members->all_members(),
				fn ( Member $member ): bool => $this->matches_member( $announcement, $member )
			)
		);
	}

	/**
	 * Check whether a member matches the target audience.
	 *
	 * @param Announcement $announcement Announcement.
	 * @param Member       $member       Member.
	 */
	private function matches_member( Announcement $announcement, Member $member ): bool {
		$user = $member->user();

		return match ( $announcement->target_audience() ) {
			Announcement::AUDIENCE_ACTIVE_MEMBERS   => $member->isActive(),
			Announcement::AUDIENCE_RENEWAL_PENDING  => $member->isRenewalPending(),
			Announcement::AUDIENCE_EXPIRED_MEMBERS  => $member->isExpired(),
			Announcement::AUDIENCE_PENDING_MEMBERS  => $member->isPending(),
			Announcement::AUDIENCE_REJECTED_MEMBERS => $member->isRejected(),
			Announcement::AUDIENCE_ADMINS           => null !== $user && $user->has_cap( 'manage_options' ),
			default                                 => true,
		};
	}

	/**
	 * Get member read map.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, string>
	 */
	private function member_reads( int $user_id ): array {
		$reads = get_user_meta( $user_id, self::READ_META_KEY, true );

		return is_array( $reads ) ? $reads : array();
	}

	/**
	 * Sanitize date inputs.
	 *
	 * @param string $date Raw date.
	 */
	private function sanitize_date( string $date ): string {
		$date = trim( sanitize_text_field( $date ) );

		if ( '' === $date ) {
			return '';
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}
}
