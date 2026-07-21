<?php
/**
 * Team model.
 *
 * @package AdamMembership\Team
 */

declare(strict_types=1);

namespace AdamMembership\Team;

/**
 * Represents an ADAM team.
 */
final class Team {
	/**
	 * Raw team data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Raw team data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get team ID.
	 */
	public function id(): int {
		return absint( $this->data['id'] ?? 0 );
	}

	/**
	 * Get team name.
	 */
	public function name(): string {
		return sanitize_text_field( (string) ( $this->data['name'] ?? '' ) );
	}

	/**
	 * Get team slug.
	 */
	public function slug(): string {
		return sanitize_title( (string) ( $this->data['slug'] ?? '' ) );
	}

	/**
	 * Get creation datetime.
	 */
	public function created_at(): string {
		return sanitize_text_field( (string) ( $this->data['created_at'] ?? '' ) );
	}

	/**
	 * Get last update datetime.
	 */
	public function updated_at(): string {
		return sanitize_text_field( (string) ( $this->data['updated_at'] ?? '' ) );
	}

	/**
	 * Convert the team to an array.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}
}
