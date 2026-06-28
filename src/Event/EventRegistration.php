<?php
/**
 * Event registration model.
 *
 * @package AdamMembership\Event
 */

declare(strict_types=1);

namespace AdamMembership\Event;

/**
 * Represents one event registration.
 */
final class EventRegistration {
	public const STATUS_CONFIRMED   = 'confirmed';
	public const STATUS_PENDING     = 'pending';
	public const STATUS_WAITING_LIST = 'waiting_list';
	public const STATUS_CANCELLED   = 'cancelled';
	public const STATUS_REJECTED    = 'rejected';

	/**
	 * Raw data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Registration data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get data payload.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	public function id(): int {
		return absint( $this->data['id'] ?? 0 );
	}

	public function event_id(): int {
		return absint( $this->data['event_id'] ?? 0 );
	}

	public function member_id(): int {
		return absint( $this->data['member_id'] ?? 0 );
	}

	public function name(): string {
		return sanitize_text_field( (string) ( $this->data['name'] ?? '' ) );
	}

	public function email(): string {
		return sanitize_email( (string) ( $this->data['email'] ?? '' ) );
	}

	public function phone(): string {
		return sanitize_text_field( (string) ( $this->data['phone'] ?? '' ) );
	}

	public function team(): string {
		return sanitize_text_field( (string) ( $this->data['team'] ?? '' ) );
	}

	public function status(): string {
		$status = sanitize_key( (string) ( $this->data['status'] ?? self::STATUS_PENDING ) );

		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_PENDING;
	}

	public function manage_token(): string {
		return sanitize_text_field( (string) ( $this->data['manage_token'] ?? '' ) );
	}

	public function created_at(): string {
		return sanitize_text_field( (string) ( $this->data['created_at'] ?? '' ) );
	}

	public function updated_at(): string {
		return sanitize_text_field( (string) ( $this->data['updated_at'] ?? '' ) );
	}

	public function notes(): string {
		return sanitize_textarea_field( (string) ( $this->data['notes'] ?? '' ) );
	}

	public function is_active(): bool {
		return ! in_array( $this->status(), array( self::STATUS_CANCELLED, self::STATUS_REJECTED ), true );
	}

	/**
	 * Get available registration statuses.
	 *
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_CONFIRMED,
			self::STATUS_PENDING,
			self::STATUS_WAITING_LIST,
			self::STATUS_CANCELLED,
			self::STATUS_REJECTED,
		);
	}
}
