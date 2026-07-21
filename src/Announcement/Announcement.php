<?php
/**
 * Announcement model.
 *
 * @package AdamMembership\Announcement
 */

declare(strict_types=1);

namespace AdamMembership\Announcement;

/**
 * Represents an ADAM announcement.
 */
final class Announcement {
	public const STATUS_DRAFT     = 'draft';
	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_ARCHIVED  = 'archived';
	public const STATUS_EXPIRED   = 'expired';

	public const PRIORITY_INFO      = 'info';
	public const PRIORITY_IMPORTANT = 'important';
	public const PRIORITY_URGENT    = 'urgent';

	public const AUDIENCE_ALL_MEMBERS      = 'all_members';
	public const AUDIENCE_ACTIVE_MEMBERS   = 'active_members';
	public const AUDIENCE_RENEWAL_PENDING  = 'renewal_pending';
	public const AUDIENCE_EXPIRED_MEMBERS  = 'expired_members';
	public const AUDIENCE_PENDING_MEMBERS  = 'pending_members';
	public const AUDIENCE_REJECTED_MEMBERS = 'rejected_members';
	public const AUDIENCE_ADMINS           = 'admins_committee';

	public const DELIVERY_MEMBER_AREA = 'member_area';
	public const DELIVERY_EMAIL       = 'email';

	public const EMAIL_AUDIENCE_CATEGORY_SUBSCRIBERS = 'category_subscribers';
	public const EMAIL_AUDIENCE_ALL_MEMBERS          = 'all_members';
	public const EMAIL_AUDIENCE_ACTIVE_MEMBERS       = 'active_members';
	public const EMAIL_AUDIENCE_TEAM                 = 'specific_team';
	public const EMAIL_AUDIENCE_SPECIFIC_MEMBERS     = 'specific_members';
	public const EMAIL_AUDIENCE_LEGACY               = 'legacy_visibility_audience';

	/**
	 * Raw announcement data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Raw announcement data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get announcement ID.
	 */
	public function id(): int {
		return absint( $this->data['id'] ?? 0 );
	}

	/**
	 * Get title.
	 */
	public function title(): string {
		return sanitize_text_field( (string) ( $this->data['title'] ?? '' ) );
	}

	/**
	 * Get summary.
	 */
	public function summary(): string {
		return sanitize_textarea_field( (string) ( $this->data['summary'] ?? '' ) );
	}

	/**
	 * Get content.
	 */
	public function content(): string {
		return (string) ( $this->data['content'] ?? '' );
	}

	/**
	 * Get category.
	 */
	public function category(): string {
		return sanitize_text_field( (string) ( $this->data['category'] ?? '' ) );
	}

	/**
	 * Get priority.
	 */
	public function priority(): string {
		$priority = sanitize_key( (string) ( $this->data['priority'] ?? self::PRIORITY_INFO ) );

		return in_array( $priority, self::priorities(), true ) ? $priority : self::PRIORITY_INFO;
	}

	/**
	 * Get status.
	 */
	public function status(): string {
		$status = sanitize_key( (string) ( $this->data['status'] ?? self::STATUS_DRAFT ) );

		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_DRAFT;
	}

	/**
	 * Get publish date.
	 */
	public function publish_date(): string {
		return sanitize_text_field( (string) ( $this->data['publish_date'] ?? '' ) );
	}

	/**
	 * Get expiry date.
	 */
	public function expiry_date(): string {
		return sanitize_text_field( (string) ( $this->data['expiry_date'] ?? '' ) );
	}

	/**
	 * Get target audience.
	 */
	public function target_audience(): string {
		$audience = sanitize_key( (string) ( $this->data['target_audience'] ?? self::AUDIENCE_ALL_MEMBERS ) );

		return in_array( $audience, self::audiences(), true ) ? $audience : self::AUDIENCE_ALL_MEMBERS;
	}

	/**
	 * Whether announcement is pinned.
	 */
	public function pinned(): bool {
		return ! empty( $this->data['pinned'] );
	}

	/**
	 * Get enabled delivery channels.
	 *
	 * Existing records did not store this list: they were always visible in the
	 * member area and used send_email as the email-delivery flag.
	 *
	 * @return array<int, string>
	 */
	public function delivery_channels(): array {
		if ( isset( $this->data['delivery_channels'] ) && is_array( $this->data['delivery_channels'] ) ) {
			$channels = array();

			foreach ( $this->data['delivery_channels'] as $key => $value ) {
				$channel = is_int( $key ) ? sanitize_key( (string) $value ) : ( ! empty( $value ) ? sanitize_key( (string) $key ) : '' );

				if ( '' !== $channel ) {
					$channels[] = $channel;
				}
			}

			return array_values( array_unique( $channels ) );
		}

		$channels = array( self::DELIVERY_MEMBER_AREA );

		if ( ! empty( $this->data['send_email'] ) ) {
			$channels[] = self::DELIVERY_EMAIL;
		}

		return $channels;
	}

	/**
	 * Whether a delivery channel is enabled.
	 *
	 * @param string $channel Delivery channel.
	 */
	public function delivers_via( string $channel ): bool {
		return in_array( sanitize_key( $channel ), $this->delivery_channels(), true );
	}

	/**
	 * Whether the announcement is available in the member area.
	 */
	public function show_in_member_area(): bool {
		return $this->delivers_via( self::DELIVERY_MEMBER_AREA );
	}

	/**
	 * Whether the announcement is promoted on the member-area homepage.
	 */
	public function show_on_member_homepage(): bool {
		return array_key_exists( 'show_on_member_homepage', $this->data ) ? ! empty( $this->data['show_on_member_homepage'] ) : true;
	}

	/**
	 * Get action button label.
	 */
	public function action_label(): string {
		return sanitize_text_field( (string) ( $this->data['action_label'] ?? '' ) );
	}

	/**
	 * Get action button URL.
	 */
	public function action_url(): string {
		return esc_url_raw( (string) ( $this->data['action_url'] ?? '' ) );
	}

	/**
	 * Whether member-area email is enabled.
	 */
	public function send_email(): bool {
		return $this->delivers_via( self::DELIVERY_EMAIL );
	}

	/**
	 * Whether this announcement predates separate email-recipient settings.
	 */
	public function has_email_audience(): bool {
		return isset( $this->data['email_audience'] ) && '' !== sanitize_key( (string) $this->data['email_audience'] );
	}

	/**
	 * Get email recipient audience.
	 */
	public function email_audience(): string {
		if ( ! $this->has_email_audience() ) {
			return self::EMAIL_AUDIENCE_LEGACY;
		}

		$audience = sanitize_key( (string) $this->data['email_audience'] );

		if ( self::EMAIL_AUDIENCE_LEGACY === $audience ) {
			return self::EMAIL_AUDIENCE_LEGACY;
		}

		return in_array( $audience, self::email_audiences(), true ) ? $audience : self::EMAIL_AUDIENCE_CATEGORY_SUBSCRIBERS;
	}

	/**
	 * Get the selected email team ID.
	 */
	public function email_team_id(): int {
		return absint( $this->data['email_team_id'] ?? 0 );
	}

	/**
	 * Get selected email member IDs.
	 *
	 * @return array<int, int>
	 */
	public function email_member_ids(): array {
		$member_ids = isset( $this->data['email_member_ids'] ) && is_array( $this->data['email_member_ids'] ) ? $this->data['email_member_ids'] : array();

		return array_values( array_unique( array_filter( array_map( 'absint', $member_ids ) ) ) );
	}

	/**
	 * Get email sent at timestamp.
	 */
	public function email_sent_at(): string {
		return sanitize_text_field( (string) ( $this->data['email_sent_at'] ?? '' ) );
	}

	/**
	 * Get created at datetime.
	 */
	public function created_at(): string {
		return sanitize_text_field( (string) ( $this->data['created_at'] ?? '' ) );
	}

	/**
	 * Get updated at datetime.
	 */
	public function updated_at(): string {
		return sanitize_text_field( (string) ( $this->data['updated_at'] ?? '' ) );
	}

	/**
	 * Whether the announcement should currently be visible.
	 */
	public function is_visible(): bool {
		return self::STATUS_PUBLISHED === $this->effective_status();
	}

	/**
	 * Get the current effective status.
	 */
	public function effective_status(): string {
		$status = $this->status();

		if ( self::STATUS_ARCHIVED === $status || self::STATUS_DRAFT === $status ) {
			return $status;
		}

		$now = current_time( 'timestamp' );

		if ( '' !== $this->expiry_date() ) {
			$expiry = strtotime( $this->expiry_date() . ' 23:59:59' );

			if ( false !== $expiry && $expiry < $now ) {
				return self::STATUS_EXPIRED;
			}
		}

		if ( self::STATUS_SCHEDULED === $status ) {
			$publish = strtotime( $this->publish_date() . ' 00:00:00' );

			if ( false === $publish || $publish > $now ) {
				return self::STATUS_SCHEDULED;
			}

			return self::STATUS_PUBLISHED;
		}

		return $status;
	}

	/**
	 * Convert announcement to array.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Get allowed statuses.
	 *
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_DRAFT,
			self::STATUS_SCHEDULED,
			self::STATUS_PUBLISHED,
			self::STATUS_ARCHIVED,
			self::STATUS_EXPIRED,
		);
	}

	/**
	 * Get allowed priorities.
	 *
	 * @return array<int, string>
	 */
	public static function priorities(): array {
		return array(
			self::PRIORITY_INFO,
			self::PRIORITY_IMPORTANT,
			self::PRIORITY_URGENT,
		);
	}

	/**
	 * Get allowed target audiences.
	 *
	 * @return array<int, string>
	 */
	public static function audiences(): array {
		return array(
			self::AUDIENCE_ALL_MEMBERS,
			self::AUDIENCE_ACTIVE_MEMBERS,
			self::AUDIENCE_RENEWAL_PENDING,
			self::AUDIENCE_EXPIRED_MEMBERS,
			self::AUDIENCE_PENDING_MEMBERS,
			self::AUDIENCE_REJECTED_MEMBERS,
			self::AUDIENCE_ADMINS,
		);
	}

	/**
	 * Get supported email recipient audiences.
	 *
	 * @return array<int, string>
	 */
	public static function email_audiences(): array {
		return array(
			self::EMAIL_AUDIENCE_CATEGORY_SUBSCRIBERS,
			self::EMAIL_AUDIENCE_ALL_MEMBERS,
			self::EMAIL_AUDIENCE_ACTIVE_MEMBERS,
			self::EMAIL_AUDIENCE_TEAM,
			self::EMAIL_AUDIENCE_SPECIFIC_MEMBERS,
		);
	}
}
