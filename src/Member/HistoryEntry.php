<?php
/**
 * Member history entry model.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

/**
 * Represents a stored member history entry.
 */
final class HistoryEntry {
	/**
	 * Raw entry data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Entry data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get the entry ID.
	 */
	public function id(): int {
		return absint( $this->data['id'] ?? 0 );
	}

	/**
	 * Get the member user ID.
	 */
	public function member_id(): int {
		return absint( $this->data['member_id'] ?? 0 );
	}

	/**
	 * Get the stored member number snapshot.
	 */
	public function member_number(): string {
		return (string) ( $this->data['member_number'] ?? '' );
	}

	/**
	 * Get the stored member name snapshot.
	 */
	public function member_name(): string {
		return (string) ( $this->data['member_name'] ?? '' );
	}

	/**
	 * Get the stored member email snapshot.
	 */
	public function member_email(): string {
		return (string) ( $this->data['member_email'] ?? '' );
	}

	/**
	 * Get the action key.
	 */
	public function action_key(): string {
		return (string) ( $this->data['action_key'] ?? '' );
	}

	/**
	 * Get the action label.
	 */
	public function action_label(): string {
		return (string) ( $this->data['action_label'] ?? '' );
	}

	/**
	 * Get the actor type.
	 */
	public function actor_type(): string {
		return (string) ( $this->data['actor_type'] ?? 'system' );
	}

	/**
	 * Get the actor user ID when available.
	 */
	public function actor_id(): int {
		return absint( $this->data['actor_id'] ?? 0 );
	}

	/**
	 * Get the stored actor name.
	 */
	public function actor_name(): string {
		return (string) ( $this->data['actor_name'] ?? '' );
	}

	/**
	 * Get the short description.
	 */
	public function description(): string {
		return (string) ( $this->data['description'] ?? '' );
	}

	/**
	 * Get optional structured details.
	 *
	 * @return array<string, mixed>
	 */
	public function details(): array {
		$details = $this->data['details'] ?? array();

		return is_array( $details ) ? $details : array();
	}

	/**
	 * Get the created timestamp.
	 */
	public function created_at(): string {
		return (string) ( $this->data['created_at'] ?? '' );
	}

	/**
	 * Get raw entry data.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}
}
