<?php
/**
 * Renewal request model.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

/**
 * Represents a stored membership renewal request.
 */
final class RenewalRequest {
	public const STATUS_PENDING  = 'pending_review';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';

	/**
	 * Stored request data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Create the model.
	 *
	 * @param array<string, mixed> $data Stored request data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get all request data.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Get the request ID.
	 */
	public function id(): int {
		return absint( $this->data['id'] ?? 0 );
	}

	/**
	 * Get the WordPress user ID.
	 */
	public function user_id(): int {
		return absint( $this->data['user_id'] ?? 0 );
	}

	/**
	 * Get the Forminator submission ID.
	 */
	public function submission_id(): int {
		return absint( $this->data['submission_id'] ?? 0 );
	}

	/**
	 * Get the submitted date.
	 */
	public function submitted_at(): string {
		return is_scalar( $this->data['submitted_at'] ?? '' ) ? (string) $this->data['submitted_at'] : '';
	}

	/**
	 * Get the current quota expiry date captured at submission time.
	 */
	public function current_quota_expiry(): string {
		return is_scalar( $this->data['current_quota_expiry'] ?? '' ) ? (string) $this->data['current_quota_expiry'] : '';
	}

	/**
	 * Get the request status.
	 */
	public function status(): string {
		$status = is_scalar( $this->data['status'] ?? '' ) ? (string) $this->data['status'] : '';

		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_PENDING;
	}

	/**
	 * Get uploaded proof of payment reference.
	 *
	 * @return mixed
	 */
	public function proof_of_payment(): mixed {
		return $this->data['proof_of_payment'] ?? '';
	}

	/**
	 * Get submitted profile data.
	 *
	 * @return array<string, string>
	 */
	public function submitted_data(): array {
		$data = $this->data['submitted_data'] ?? array();

		return is_array( $data ) ? array_map( 'strval', $data ) : array();
	}

	/**
	 * Get all allowed request statuses.
	 *
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_PENDING,
			self::STATUS_APPROVED,
			self::STATUS_REJECTED,
		);
	}
}
