<?php
/**
 * Reward redemption model.
 *
 * @package AdamMembership\Reward
 */

declare(strict_types=1);

namespace AdamMembership\Reward;

/**
 * Represents one member reward redemption request.
 */
final class RewardRedemption {
	public const STATUS_PENDING   = 'pending';
	public const STATUS_APPROVED  = 'approved';
	public const STATUS_REJECTED  = 'rejected';
	public const STATUS_DELIVERED = 'delivered';

	/**
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * @param array<string, mixed> $data Redemption data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function id(): int {
		return absint( $this->data['id'] ?? 0 );
	}

	public function reward_id(): int {
		return absint( $this->data['reward_id'] ?? 0 );
	}

	public function member_id(): int {
		return absint( $this->data['member_id'] ?? 0 );
	}

	public function reward_name(): string {
		return sanitize_text_field( (string) ( $this->data['reward_name'] ?? '' ) );
	}

	public function reward_type(): string {
		return sanitize_key( (string) ( $this->data['reward_type'] ?? '' ) );
	}

	public function points_cost(): int {
		return max( 0, absint( $this->data['points_cost'] ?? 0 ) );
	}

	public function status(): string {
		$status = sanitize_key( (string) ( $this->data['status'] ?? self::STATUS_PENDING ) );

		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_PENDING;
	}

	public function created_at(): string {
		return sanitize_text_field( (string) ( $this->data['created_at'] ?? '' ) );
	}

	public function approved_at(): string {
		return sanitize_text_field( (string) ( $this->data['approved_at'] ?? '' ) );
	}

	public function approved_by(): int {
		return absint( $this->data['approved_by'] ?? 0 );
	}

	public function rejected_at(): string {
		return sanitize_text_field( (string) ( $this->data['rejected_at'] ?? '' ) );
	}

	public function rejected_by(): int {
		return absint( $this->data['rejected_by'] ?? 0 );
	}

	public function rejection_reason(): string {
		return sanitize_textarea_field( (string) ( $this->data['rejection_reason'] ?? '' ) );
	}

	public function delivered_at(): string {
		return sanitize_text_field( (string) ( $this->data['delivered_at'] ?? '' ) );
	}

	public function delivered_by(): int {
		return absint( $this->data['delivered_by'] ?? 0 );
	}

	public function revealed_reward(): string {
		return sanitize_textarea_field( (string) ( $this->data['revealed_reward'] ?? '' ) );
	}

	public function points_entry_id(): int {
		return absint( $this->data['points_entry_id'] ?? 0 );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_PENDING,
			self::STATUS_APPROVED,
			self::STATUS_REJECTED,
			self::STATUS_DELIVERED,
		);
	}
}
