<?php
declare(strict_types=1);
namespace AdamMembership\Member;

final class MemberChangeRequest {
	public const STATUS_PENDING = 'pending_review';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_REJECTED = 'rejected';
	public function __construct( private array $data ) {}
	public function id(): int { return absint( $this->data['id'] ?? 0 ); }
	public function user_id(): int { return absint( $this->data['user_id'] ?? 0 ); }
	public function submitted_at(): string { return (string) ( $this->data['submitted_at'] ?? '' ); }
	public function status(): string { return (string) ( $this->data['status'] ?? self::STATUS_PENDING ); }
	public function changes(): array { return is_array( $this->data['changes'] ?? null ) ? $this->data['changes'] : array(); }
	public function data(): array { return $this->data; }
}
