<?php
declare(strict_types=1);

namespace AdamMembership\Member;

final class ApdAssociationRequest {
	public const STATUS_PENDING_PAYMENT = 'pending_payment';
	public const STATUS_AWAITING_ADAM = 'awaiting_adam';
	public const STATUS_SUBMITTED_ANA = 'submitted_to_ana';
	public const STATUS_CONFIRMED = 'ana_confirmed';
	public const STATUS_REJECTED = 'rejected';

	private array $data;

	public function __construct( array $data ) { $this->data = $data; }
	public function id(): int { return absint( $this->data['id'] ?? 0 ); }
	public function user_id(): int { return absint( $this->data['user_id'] ?? 0 ); }
	public function status(): string { return (string) ( $this->data['status'] ?? self::STATUS_PENDING_PAYMENT ); }
	public function payment_status(): string { return (string) ( $this->data['payment_status'] ?? 'pending' ); }
	public function amount(): string { return (string) ( $this->data['amount'] ?? '0.00' ); }
	public function requested_at(): string { return (string) ( $this->data['requested_at'] ?? '' ); }
	public function confirmation_date(): string { return (string) ( $this->data['ana_confirmation_date'] ?? '' ); }
	public function data(): array { return $this->data; }
}
