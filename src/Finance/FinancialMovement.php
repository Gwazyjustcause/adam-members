<?php
declare(strict_types=1);

namespace AdamMembership\Finance;

final class FinancialMovement {
	public function __construct( private array $data ) {}
	public function data(): array { return $this->data; }
	public function id(): int { return absint( $this->data['id'] ?? 0 ); }
	public function movement_id(): string { return (string) ( $this->data['movement_id'] ?? '' ); }
	public function member_id(): int { return absint( $this->data['member_id'] ?? 0 ); }
	public function source_type(): string { return (string) ( $this->data['source_type'] ?? '' ); }
	public function source_reference(): string { return (string) ( $this->data['source_reference'] ?? '' ); }
	public function quota_type(): string { return (string) ( $this->data['quota_type'] ?? '' ); }
	public function membership_year(): int { return absint( $this->data['membership_year'] ?? 0 ); }
	public function amount(): string { return (string) ( $this->data['amount'] ?? '' ); }
	public function payment_date(): string { return (string) ( $this->data['payment_date'] ?? '' ); }
	public function payment_method(): string { return (string) ( $this->data['payment_method'] ?? '' ); }
	public function google_state(): string { return (string) ( $this->data['google_state'] ?? 'pending' ); }
}
