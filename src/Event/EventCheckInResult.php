<?php
/**
 * Event check-in result model.
 *
 * @package AdamMembership\Event
 */

declare(strict_types=1);

namespace AdamMembership\Event;

use AdamMembership\Points\PointsEntry;

/**
 * Represents the outcome of a member event check-in.
 */
final class EventCheckInResult {
	private EventCheckIn $checkin;
	private ?PointsEntry $bonus_entry;
	private string $bonus_message;

	public function __construct( EventCheckIn $checkin, ?PointsEntry $bonus_entry = null, string $bonus_message = '' ) {
		$this->checkin       = $checkin;
		$this->bonus_entry   = $bonus_entry;
		$this->bonus_message = $bonus_message;
	}

	public function checkin(): EventCheckIn {
		return $this->checkin;
	}

	public function bonus_entry(): ?PointsEntry {
		return $this->bonus_entry;
	}

	public function has_bonus(): bool {
		return $this->bonus_entry instanceof PointsEntry;
	}

	public function bonus_message(): string {
		return sanitize_textarea_field( $this->bonus_message );
	}
}
