<?php
/**
 * Event model.
 *
 * @package AdamMembership\Event
 */

declare(strict_types=1);

namespace AdamMembership\Event;

/**
 * Represents an ADAM event.
 */
final class Event {
	public const ACCESS_MEMBERS_ONLY   = 'members_only';
	public const ACCESS_OPEN           = 'open';
	public const ACCESS_MEMBER_PRIORITY = 'member_priority';

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Raw event data.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Event data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get event data.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	public function id(): int {
		return absint( $this->data['id'] ?? 0 );
	}

	public function slug(): string {
		return sanitize_title( (string) ( $this->data['slug'] ?? '' ) );
	}

	public function title(): string {
		return sanitize_text_field( (string) ( $this->data['title'] ?? '' ) );
	}

	public function short_description(): string {
		return sanitize_textarea_field( (string) ( $this->data['short_description'] ?? '' ) );
	}

	public function full_description(): string {
		return (string) ( $this->data['full_description'] ?? '' );
	}

	public function event_date(): string {
		return sanitize_text_field( (string) ( $this->data['event_date'] ?? '' ) );
	}

	public function start_time(): string {
		return sanitize_text_field( (string) ( $this->data['start_time'] ?? '' ) );
	}

	public function end_time(): string {
		return sanitize_text_field( (string) ( $this->data['end_time'] ?? '' ) );
	}

	public function location(): string {
		return sanitize_text_field( (string) ( $this->data['location'] ?? '' ) );
	}

	public function map_link(): string {
		return esc_url_raw( (string) ( $this->data['map_link'] ?? '' ) );
	}

	public function cover_image(): string {
		return esc_url_raw( (string) ( $this->data['cover_image'] ?? '' ) );
	}

	public function access_mode(): string {
		$mode = sanitize_key( (string) ( $this->data['access_mode'] ?? self::ACCESS_MEMBERS_ONLY ) );

		return in_array( $mode, self::access_modes(), true ) ? $mode : self::ACCESS_MEMBERS_ONLY;
	}

	public function max_players(): int {
		return max( 0, absint( $this->data['max_players'] ?? 0 ) );
	}

	public function waiting_list_enabled(): bool {
		return ! empty( $this->data['waiting_list_enabled'] );
	}

	public function waiting_list_limit(): int {
		return max( 0, absint( $this->data['waiting_list_limit'] ?? 0 ) );
	}

	public function registration_deadline(): string {
		return sanitize_text_field( (string) ( $this->data['registration_deadline'] ?? '' ) );
	}

	public function priority_deadline(): string {
		return sanitize_text_field( (string) ( $this->data['priority_deadline'] ?? '' ) );
	}

	public function status(): string {
		$status = sanitize_key( (string) ( $this->data['status'] ?? self::STATUS_DRAFT ) );

		return in_array( $status, self::statuses(), true ) ? $status : self::STATUS_DRAFT;
	}

	public function created_at(): string {
		return sanitize_text_field( (string) ( $this->data['created_at'] ?? '' ) );
	}

	public function updated_at(): string {
		return sanitize_text_field( (string) ( $this->data['updated_at'] ?? '' ) );
	}

	public function is_visible(): bool {
		return in_array( $this->status(), array( self::STATUS_PUBLISHED, self::STATUS_CANCELLED, self::STATUS_COMPLETED ), true );
	}

	public function is_registration_open(): bool {
		if ( self::STATUS_PUBLISHED !== $this->status() ) {
			return false;
		}

		$deadline = $this->registration_deadline_timestamp();

		return 0 === $deadline || $deadline >= current_time( 'timestamp' );
	}

	public function registration_deadline_timestamp(): int {
		return $this->datetime_to_timestamp( $this->registration_deadline() );
	}

	public function priority_deadline_timestamp(): int {
		return $this->datetime_to_timestamp( $this->priority_deadline() );
	}

	public function starts_at_timestamp(): int {
		return $this->datetime_to_timestamp( trim( $this->event_date() . ' ' . $this->start_time() ) );
	}

	public function ends_at_timestamp(): int {
		$end = trim( $this->event_date() . ' ' . $this->end_time() );

		return '' === trim( $this->end_time() ) ? 0 : $this->datetime_to_timestamp( $end );
	}

	public function priority_window_open(): bool {
		if ( self::ACCESS_MEMBER_PRIORITY !== $this->access_mode() ) {
			return false;
		}

		$deadline = $this->priority_deadline_timestamp();

		return 0 !== $deadline && $deadline >= current_time( 'timestamp' );
	}

	/**
	 * Get access mode list.
	 *
	 * @return array<int, string>
	 */
	public static function access_modes(): array {
		return array(
			self::ACCESS_MEMBERS_ONLY,
			self::ACCESS_OPEN,
			self::ACCESS_MEMBER_PRIORITY,
		);
	}

	/**
	 * Get status list.
	 *
	 * @return array<int, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_DRAFT,
			self::STATUS_PUBLISHED,
			self::STATUS_CANCELLED,
			self::STATUS_COMPLETED,
		);
	}

	/**
	 * Convert a stored datetime-like string to a timestamp.
	 *
	 * @param string $value Date or datetime.
	 */
	private function datetime_to_timestamp( string $value ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			return 0;
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? 0 : $timestamp;
	}
}
