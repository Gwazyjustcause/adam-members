<?php
/**
 * Event check-in model.
 *
 * @package AdamMembership\Event
 */

declare(strict_types=1);

namespace AdamMembership\Event;

/**
 * Represents one member check-in for an event.
 */
final class EventCheckIn {
	/**
	 * Raw check-in data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Check-in data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get raw payload.
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

	public function points_awarded(): int {
		return max( 0, absint( $this->data['points_awarded'] ?? 0 ) );
	}

	public function created_by_admin(): bool {
		return ! empty( $this->data['created_by_admin'] );
	}

	public function checked_in_at(): string {
		return sanitize_text_field( (string) ( $this->data['checked_in_at'] ?? '' ) );
	}

	public function created_at(): string {
		return sanitize_text_field( (string) ( $this->data['created_at'] ?? '' ) );
	}
}
