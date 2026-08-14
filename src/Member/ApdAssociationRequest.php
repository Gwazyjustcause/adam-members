<?php
declare(strict_types=1);

namespace AdamMembership\Member;

final class ApdAssociationRequest {
	public const STATUS_PENDING_PAYMENT = 'pending_payment';
	public const STATUS_AWAITING_ADAM = 'awaiting_adam';
	public const STATUS_SUBMITTED_ANA = 'submitted_to_ana';
	public const STATUS_CONFIRMED = 'ana_confirmed';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_CORRECTION_REQUESTED = 'correction_requested';

	private array $data;

	public function __construct( array $data ) { $this->data = $data; }
	public function id(): int { return absint( $this->data['id'] ?? 0 ); }
	public function user_id(): int { return absint( $this->data['user_id'] ?? 0 ); }
	public function status(): string { return (string) ( $this->data['status'] ?? self::STATUS_PENDING_PAYMENT ); }
	public function payment_status(): string { return (string) ( $this->data['payment_status'] ?? 'pending' ); }
	public function amount(): string { return (string) ( $this->data['amount'] ?? '0.00' ); }
	public function request_uuid(): string { $value = (string) ( $this->data['request_uuid'] ?? '' ); return '' !== $value ? $value : ( $this->id() > 0 ? 'legacy-apd:' . $this->id() : '' ); }
	public function quota_type(): string { return (string) ( $this->data['quota_type'] ?? 'Associar APD/ANA' ); }
	public function membership_year(): int { return absint( $this->data['membership_year'] ?? 0 ); }
	public function payment_amount(): string { return (string) ( $this->data['payment_amount'] ?? '' ); }
	public function payment_date(): string { return (string) ( $this->data['payment_date'] ?? '' ); }
	public function payment_method(): string { return (string) ( $this->data['payment_method'] ?? '' ); }
	public function requested_at(): string { return (string) ( $this->data['requested_at'] ?? '' ); }
	public function confirmation_date(): string { return (string) ( $this->data['ana_confirmation_date'] ?? '' ); }
	public function data(): array { return $this->data; }
}
