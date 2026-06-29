<?php
/**
 * Reward model.
 *
 * @package AdamMembership\Reward
 */

declare(strict_types=1);

namespace AdamMembership\Reward;

/**
 * Represents one ADAM reward.
 */
final class Reward {
	public const TYPE_PERMANENT_UNLOCK = 'permanent_unlock';
	public const TYPE_CONSUMABLE       = 'consumable';
	public const TYPE_MYSTERY_REWARD   = 'mystery_reward';
	public const TYPE_PHYSICAL_REWARD  = 'physical_reward';
	public const TYPE_MANUAL_REWARD    = 'manual_reward';
	public const TYPE_DIGITAL_COSMETIC = 'digital_cosmetic';
	public const TYPE_RAFFLE_TICKET    = 'raffle_ticket';

	public const RARITY_COMMON          = 'common';
	public const RARITY_UNCOMMON       = 'uncommon';
	public const RARITY_RARE           = 'rare';
	public const RARITY_EPIC           = 'epic';
	public const RARITY_LEGENDARY      = 'legendary';
	public const RARITY_LIMITED_EDITION = 'limited_edition';

	/**
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * @param array<string, mixed> $data Reward data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function id(): int {
		return absint( $this->data['id'] ?? 0 );
	}

	public function name(): string {
		return sanitize_text_field( (string) ( $this->data['name'] ?? '' ) );
	}

	public function description(): string {
		return sanitize_textarea_field( (string) ( $this->data['description'] ?? '' ) );
	}

	public function category(): string {
		return sanitize_text_field( (string) ( $this->data['category'] ?? '' ) );
	}

	public function type(): string {
		$type = sanitize_key( (string) ( $this->data['type'] ?? self::TYPE_PERMANENT_UNLOCK ) );

		return in_array( $type, self::types(), true ) ? $type : self::TYPE_PERMANENT_UNLOCK;
	}

	public function rarity(): string {
		$rarity = sanitize_key( (string) ( $this->data['rarity'] ?? self::RARITY_COMMON ) );

		return in_array( $rarity, self::rarities(), true ) ? $rarity : self::RARITY_COMMON;
	}

	public function points_cost(): int {
		return max( 0, absint( $this->data['points_cost'] ?? 0 ) );
	}

	public function image_url(): string {
		return esc_url_raw( (string) ( $this->data['image_url'] ?? '' ) );
	}

	public function availability_label(): string {
		return sanitize_text_field( (string) ( $this->data['availability_label'] ?? __( 'Disponível', 'adam-membership' ) ) );
	}

	public function active(): bool {
		return ! empty( $this->data['active'] );
	}

	public function approval_required(): bool {
		return ! empty( $this->data['approval_required'] );
	}

	public function redeemable(): bool {
		if ( array_key_exists( 'redeemable', $this->data ) ) {
			return ! empty( $this->data['redeemable'] );
		}

		return self::TYPE_MANUAL_REWARD !== $this->type();
	}

	public function mystery_reveal_text(): string {
		return sanitize_textarea_field( (string) ( $this->data['mystery_reveal_text'] ?? '' ) );
	}

	public function reward_value(): string {
		return sanitize_text_field( (string) ( $this->data['reward_value'] ?? '' ) );
	}

	public function created_at(): string {
		return sanitize_text_field( (string) ( $this->data['created_at'] ?? '' ) );
	}

	public function updated_at(): string {
		return sanitize_text_field( (string) ( $this->data['updated_at'] ?? '' ) );
	}

	public function is_visible(): bool {
		return $this->active();
	}

	public function is_single_claim(): bool {
		return in_array( $this->type(), array( self::TYPE_PERMANENT_UNLOCK, self::TYPE_DIGITAL_COSMETIC ), true );
	}

	public function is_mystery(): bool {
		return self::TYPE_MYSTERY_REWARD === $this->type();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * @return array<int, string>
	 */
	public static function types(): array {
		return array(
			self::TYPE_PERMANENT_UNLOCK,
			self::TYPE_CONSUMABLE,
			self::TYPE_MYSTERY_REWARD,
			self::TYPE_PHYSICAL_REWARD,
			self::TYPE_MANUAL_REWARD,
			self::TYPE_DIGITAL_COSMETIC,
			self::TYPE_RAFFLE_TICKET,
		);
	}

	/**
	 * @return array<int, string>
	 */
	public static function rarities(): array {
		return array(
			self::RARITY_COMMON,
			self::RARITY_UNCOMMON,
			self::RARITY_RARE,
			self::RARITY_EPIC,
			self::RARITY_LEGENDARY,
			self::RARITY_LIMITED_EDITION,
		);
	}
}
