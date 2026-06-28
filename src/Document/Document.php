<?php
/**
 * Document model.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

/**
 * Represents an ADAM document centre item.
 */
final class Document {
	public const STATUS_DRAFT     = 'draft';
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_ARCHIVED  = 'archived';

	public const AUDIENCE_ALL_MEMBERS     = 'all_members';
	public const AUDIENCE_ACTIVE_MEMBERS  = 'active_members';
	public const AUDIENCE_RENEWAL_PENDING = 'renewal_pending';
	public const AUDIENCE_EXPIRED_MEMBERS = 'expired_members';
	public const AUDIENCE_PENDING_MEMBERS = 'pending_members';
	public const AUDIENCE_ADMINS          = 'admins_committee';

	/**
	 * Raw document data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Raw document data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get document ID.
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
	 * Get description.
	 */
	public function description(): string {
		return sanitize_textarea_field( (string) ( $this->data['description'] ?? '' ) );
	}

	/**
	 * Get category.
	 */
	public function category(): string {
		return sanitize_text_field( (string) ( $this->data['category'] ?? '' ) );
	}

	/**
	 * Get stored version.
	 */
	public function version(): string {
		return sanitize_text_field( (string) ( $this->data['version'] ?? '1.0' ) );
	}

	/**
	 * Get status.
	 */
	public function status(): string {
		$status = sanitize_key( (string) ( $this->data['status'] ?? self::STATUS_DRAFT ) );

		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_DRAFT;
	}

	/**
	 * Get target audience.
	 */
	public function target_audience(): string {
		$audience = sanitize_key( (string) ( $this->data['target_audience'] ?? self::AUDIENCE_ALL_MEMBERS ) );

		return in_array( $audience, self::audiences(), true ) ? $audience : self::AUDIENCE_ALL_MEMBERS;
	}

	/**
	 * Whether the document is important.
	 */
	public function important(): bool {
		return ! empty( $this->data['important'] );
	}

	/**
	 * Get upload date.
	 */
	public function upload_date(): string {
		return sanitize_text_field( (string) ( $this->data['upload_date'] ?? '' ) );
	}

	/**
	 * Get last updated datetime.
	 */
	public function updated_at(): string {
		return sanitize_text_field( (string) ( $this->data['updated_at'] ?? '' ) );
	}

	/**
	 * Get stored file path.
	 */
	public function file_path(): string {
		return sanitize_text_field( (string) ( $this->data['file_path'] ?? '' ) );
	}

	/**
	 * Get original file name.
	 */
	public function file_name(): string {
		return sanitize_file_name( (string) ( $this->data['file_name'] ?? '' ) );
	}

	/**
	 * Get stored MIME type.
	 */
	public function mime_type(): string {
		return sanitize_text_field( (string) ( $this->data['mime_type'] ?? '' ) );
	}

	/**
	 * Get stored file size.
	 */
	public function file_size(): int {
		return absint( $this->data['file_size'] ?? 0 );
	}

	/**
	 * Whether the document appears in member area.
	 */
	public function is_visible(): bool {
		return self::STATUS_PUBLISHED === $this->status();
	}

	/**
	 * Convert document to array.
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
			self::STATUS_PUBLISHED,
			self::STATUS_ARCHIVED,
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
			self::AUDIENCE_ADMINS,
		);
	}
}
