<?php
/**
 * Private financial document model.
 *
 * @package AdamMembership\Document
 */

declare(strict_types=1);

namespace AdamMembership\Document;

/** Represents one stored version of a private financial document. */
final class PrivateDocument {
	/** @var array<string, mixed> */
	private array $data;

	/** @param array<string, mixed> $data Stored document data. */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/** @return array<string, mixed> */
	public function data(): array { return $this->data; }
	public function id(): int { return absint( $this->data['id'] ?? 0 ); }
	public function request_reference(): string { return (string) ( $this->data['request_reference'] ?? '' ); }
	public function request_type(): string { return (string) ( $this->data['request_type'] ?? '' ); }
	public function active(): bool { return '' !== (string) ( $this->data['active_key'] ?? '' ); }
	public function active_key(): string { return (string) ( $this->data['active_key'] ?? '' ); }
	public function file_identifier(): string { return (string) ( $this->data['file_identifier'] ?? '' ); }
	public function original_name(): string { return (string) ( $this->data['original_name'] ?? '' ); }
	public function mime(): string { return (string) ( $this->data['mime'] ?? '' ); }
	public function file_size(): int { return absint( $this->data['file_size'] ?? 0 ); }
	public function sha256(): string { return (string) ( $this->data['sha256'] ?? '' ); }
	public function document_status(): string { return (string) ( $this->data['document_status'] ?? '' ); }
	public function send_status(): string { return (string) ( $this->data['send_status'] ?? '' ); }
	public function uploaded_by(): int { return absint( $this->data['uploaded_by'] ?? 0 ); }
	public function created_at(): string { return (string) ( $this->data['created_at'] ?? '' ); }
	public function updated_at(): string { return (string) ( $this->data['updated_at'] ?? '' ); }
	public function last_sent_at(): string { return (string) ( $this->data['last_sent_at'] ?? '' ); }
	public function last_error(): string { return (string) ( $this->data['last_error'] ?? '' ); }
}
