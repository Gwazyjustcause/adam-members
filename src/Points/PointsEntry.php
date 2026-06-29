<?php
/**
 * Points ledger entry model.
 *
 * @package AdamMembership\Points
 */

declare(strict_types=1);

namespace AdamMembership\Points;

/**
 * Represents one points movement.
 */
final class PointsEntry {
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

	public function id(): int {
		return absint( $this->data['id'] ?? 0 );
	}

	public function member_id(): int {
		return absint( $this->data['member_id'] ?? 0 );
	}

	public function points(): int {
		return (int) ( $this->data['points'] ?? 0 );
	}

	public function reason(): string {
		return sanitize_text_field( (string) ( $this->data['reason'] ?? '' ) );
	}

	public function source_type(): string {
		return sanitize_key( (string) ( $this->data['source_type'] ?? '' ) );
	}

	public function source_id(): int {
		return absint( $this->data['source_id'] ?? 0 );
	}

	public function created_at(): string {
		return sanitize_text_field( (string) ( $this->data['created_at'] ?? '' ) );
	}

	public function created_by(): int {
		return absint( $this->data['created_by'] ?? 0 );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		$meta = $this->data['meta'] ?? array();

		return is_array( $meta ) ? $meta : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}
}
