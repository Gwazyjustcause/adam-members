<?php
/**
 * Card cosmetics service.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Reward\Reward;
use AdamMembership\Reward\RewardRedemption;
use AdamMembership\Reward\RewardService;
use WP_Error;

/**
 * Resolves unlocked cosmetics and active member card selections.
 */
final class CardCosmeticsService {
	public const TYPE_TITLE = 'title';
	public const TYPE_THEME = 'theme';
	public const TYPE_FRAME = 'frame';

	private const FIELD_ACTIVE_TITLE = 'adam_active_title_reward';
	private const FIELD_ACTIVE_THEME = 'adam_active_card_theme';
	private const FIELD_ACTIVE_FRAME = 'adam_active_card_frame';

	private RewardService $rewards;

	public function __construct( RewardService $rewards ) {
		$this->rewards = $rewards;
	}

	/**
	 * Build the active card presentation for a member.
	 *
	 * @return array<string, mixed>
	 */
	public function card_presentation( Member $member ): array {
		$owned         = $this->owned_cosmetics_by_key( $member );
		$active_title  = $this->selected_cosmetic( $member, self::FIELD_ACTIVE_TITLE, self::TYPE_TITLE, $owned );
		$active_theme  = $this->selected_cosmetic( $member, self::FIELD_ACTIVE_THEME, self::TYPE_THEME, $owned );
		$active_frame  = $this->selected_cosmetic( $member, self::FIELD_ACTIVE_FRAME, self::TYPE_FRAME, $owned );
		$loyalty_badge = $this->selected_loyalty_badge( $active_title, $active_theme, $active_frame );
		$classes       = array( 'adam-digital-card' );

		if ( is_array( $active_theme ) && isset( $active_theme['css_class'] ) ) {
			$classes[] = (string) $active_theme['css_class'];
			$classes[] = 'adam-digital-card--theme-rarity-' . sanitize_html_class( (string) $active_theme['rarity'] );
		}

		if ( is_array( $active_frame ) && isset( $active_frame['css_class'] ) ) {
			$classes[] = (string) $active_frame['css_class'];
			$classes[] = 'adam-digital-card--frame-rarity-' . sanitize_html_class( (string) $active_frame['rarity'] );
		}

		if ( $member->is_founder() ) {
			$classes[] = 'adam-digital-card--is-founder';
		}

		return array(
			'classes'         => array_values( array_unique( array_filter( $classes ) ) ),
			'active_title'    => $active_title,
			'active_theme'    => $active_theme,
			'active_frame'    => $active_frame,
			'founder_badge'   => $member->is_founder()
				? ( $member->founder_number() > 0 ? sprintf( __( 'Fundador #%d', 'adam-membership' ), $member->founder_number() ) : __( 'Membro Fundador', 'adam-membership' ) )
				: '',
			'loyalty_badge'   => $loyalty_badge,
			'selected_values' => array(
				'title' => is_array( $active_title ) ? (string) $active_title['key'] : '',
				'theme' => is_array( $active_theme ) ? (string) $active_theme['key'] : '',
				'frame' => is_array( $active_frame ) ? (string) $active_frame['key'] : '',
			),
		);
	}

	/**
	 * Return unlocked cosmetic options grouped by type.
	 *
	 * @return array{titles:array<int, array<string, mixed>>,themes:array<int, array<string, mixed>>,frames:array<int, array<string, mixed>>}
	 */
	public function member_options( Member $member ): array {
		$grouped = array(
			'titles' => array(),
			'themes' => array(),
			'frames' => array(),
		);

		foreach ( $this->owned_cosmetics_by_key( $member ) as $cosmetic ) {
			$type = (string) $cosmetic['type'];

			if ( self::TYPE_TITLE === $type ) {
				$grouped['titles'][] = $cosmetic;
			} elseif ( self::TYPE_THEME === $type ) {
				$grouped['themes'][] = $cosmetic;
			} elseif ( self::TYPE_FRAME === $type ) {
				$grouped['frames'][] = $cosmetic;
			}
		}

		return $grouped;
	}

	/**
	 * Persist a member cosmetic selection.
	 *
	 * @param array<string, mixed> $raw_data Raw posted values.
	 * @return true|WP_Error
	 */
	public function save_member_selection( Member $member, array $raw_data ): true|WP_Error {
		$owned = $this->owned_cosmetics_by_key( $member );

		$title = $this->validated_selection(
			isset( $raw_data['active_title_reward'] ) ? (string) $raw_data['active_title_reward'] : '',
			self::TYPE_TITLE,
			$owned
		);
		$theme = $this->validated_selection(
			isset( $raw_data['active_card_theme'] ) ? (string) $raw_data['active_card_theme'] : '',
			self::TYPE_THEME,
			$owned
		);
		$frame = $this->validated_selection(
			isset( $raw_data['active_card_frame'] ) ? (string) $raw_data['active_card_frame'] : '',
			self::TYPE_FRAME,
			$owned
		);

		if ( $title instanceof WP_Error || $theme instanceof WP_Error || $frame instanceof WP_Error ) {
			return $title instanceof WP_Error ? $title : ( $theme instanceof WP_Error ? $theme : $frame );
		}

		$member->save(
			array(
				self::FIELD_ACTIVE_TITLE => $title,
				self::FIELD_ACTIVE_THEME => $theme,
				self::FIELD_ACTIVE_FRAME => $frame,
			)
		);

		return true;
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function registry(): array {
		return array(
			'title_operador'               => array( 'name' => 'Operador', 'type' => self::TYPE_TITLE, 'rarity' => 'common', 'css_class' => 'adam-card-title--operador', 'unlock_source' => 'points' ),
			'title_explorador'             => array( 'name' => 'Explorador', 'type' => self::TYPE_TITLE, 'rarity' => 'common', 'css_class' => 'adam-card-title--explorador', 'unlock_source' => 'points' ),
			'title_sobrevivente'           => array( 'name' => 'Sobrevivente', 'type' => self::TYPE_TITLE, 'rarity' => 'common', 'css_class' => 'adam-card-title--sobrevivente', 'unlock_source' => 'points' ),
			'title_veterano_adam'          => array( 'name' => 'Veterano ADAM', 'type' => self::TYPE_TITLE, 'rarity' => 'uncommon', 'css_class' => 'adam-card-title--veterano-adam', 'unlock_source' => 'loyalty' ),
			'title_mestre_do_cqb'          => array( 'name' => 'Mestre do CQB', 'type' => self::TYPE_TITLE, 'rarity' => 'uncommon', 'css_class' => 'adam-card-title--mestre-do-cqb', 'unlock_source' => 'points' ),
			'title_atirador_de_elite'      => array( 'name' => 'Atirador de Elite', 'type' => self::TYPE_TITLE, 'rarity' => 'rare', 'css_class' => 'adam-card-title--atirador-de-elite', 'unlock_source' => 'points' ),
			'title_operador_elite'         => array( 'name' => 'Operador Elite', 'type' => self::TYPE_TITLE, 'rarity' => 'rare', 'css_class' => 'adam-card-title--operador-elite', 'unlock_source' => 'points' ),
			'title_operador_experiente'    => array( 'name' => 'Operador Experiente', 'type' => self::TYPE_TITLE, 'rarity' => 'rare', 'css_class' => 'adam-card-title--operador-experiente', 'unlock_source' => 'loyalty' ),
			'title_lider_de_esquadra'      => array( 'name' => 'Lider de Esquadra', 'type' => self::TYPE_TITLE, 'rarity' => 'rare', 'css_class' => 'adam-card-title--lider-de-esquadra', 'unlock_source' => 'points' ),
			'title_guardiao_adam'          => array( 'name' => 'Guardiao ADAM', 'type' => self::TYPE_TITLE, 'rarity' => 'epic', 'css_class' => 'adam-card-title--guardiao-adam', 'unlock_source' => 'loyalty' ),
			'title_lenda_adam'             => array( 'name' => 'Lenda ADAM', 'type' => self::TYPE_TITLE, 'rarity' => 'legendary', 'css_class' => 'adam-card-title--lenda-adam', 'unlock_source' => 'loyalty' ),
			'title_comandante_adam'        => array( 'name' => 'Comandante ADAM', 'type' => self::TYPE_TITLE, 'rarity' => 'legendary', 'css_class' => 'adam-card-title--comandante-adam', 'unlock_source' => 'points' ),
			'title_fundador_honorario'     => array( 'name' => 'Fundador Honorario', 'type' => self::TYPE_TITLE, 'rarity' => 'founder', 'css_class' => 'adam-card-title--fundador-honorario', 'unlock_source' => 'manual' ),
			'title_founder'                => array( 'name' => 'Founder', 'type' => self::TYPE_TITLE, 'rarity' => 'founder', 'css_class' => 'adam-card-title--founder', 'unlock_source' => 'founder' ),
			'card_theme_woodland'          => array( 'name' => 'Woodland', 'type' => self::TYPE_THEME, 'rarity' => 'common', 'css_class' => 'adam-digital-card--theme-woodland', 'unlock_source' => 'points' ),
			'card_theme_desert'            => array( 'name' => 'Desert', 'type' => self::TYPE_THEME, 'rarity' => 'common', 'css_class' => 'adam-digital-card--theme-desert', 'unlock_source' => 'points' ),
			'card_theme_urban'             => array( 'name' => 'Urban', 'type' => self::TYPE_THEME, 'rarity' => 'common', 'css_class' => 'adam-digital-card--theme-urban', 'unlock_source' => 'points' ),
			'card_theme_carbon_fiber'      => array( 'name' => 'Carbon Fiber', 'type' => self::TYPE_THEME, 'rarity' => 'uncommon', 'css_class' => 'adam-digital-card--theme-carbon-fiber', 'unlock_source' => 'loyalty' ),
			'card_theme_flecktarn'         => array( 'name' => 'Flecktarn', 'type' => self::TYPE_THEME, 'rarity' => 'uncommon', 'css_class' => 'adam-digital-card--theme-flecktarn', 'unlock_source' => 'points' ),
			'card_theme_digital_green'     => array( 'name' => 'Digital Green', 'type' => self::TYPE_THEME, 'rarity' => 'uncommon', 'css_class' => 'adam-digital-card--theme-digital-green', 'unlock_source' => 'points' ),
			'card_theme_blue_steel'        => array( 'name' => 'Blue Steel', 'type' => self::TYPE_THEME, 'rarity' => 'uncommon', 'css_class' => 'adam-digital-card--theme-blue-steel', 'unlock_source' => 'points' ),
			'card_theme_midnight_black'    => array( 'name' => 'Midnight Black', 'type' => self::TYPE_THEME, 'rarity' => 'rare', 'css_class' => 'adam-digital-card--theme-midnight-black', 'unlock_source' => 'points' ),
			'card_theme_arctic_white'      => array( 'name' => 'Arctic White', 'type' => self::TYPE_THEME, 'rarity' => 'rare', 'css_class' => 'adam-digital-card--theme-arctic-white', 'unlock_source' => 'points' ),
			'card_theme_crimson_strike'    => array( 'name' => 'Crimson Strike', 'type' => self::TYPE_THEME, 'rarity' => 'rare', 'css_class' => 'adam-digital-card--theme-crimson-strike', 'unlock_source' => 'points' ),
			'card_theme_purple_nebula'     => array( 'name' => 'Purple Nebula', 'type' => self::TYPE_THEME, 'rarity' => 'rare', 'css_class' => 'adam-digital-card--theme-purple-nebula', 'unlock_source' => 'points' ),
			'card_theme_gold'              => array( 'name' => 'Gold', 'type' => self::TYPE_THEME, 'rarity' => 'epic', 'css_class' => 'adam-digital-card--theme-gold', 'unlock_source' => 'loyalty' ),
			'card_theme_emerald'           => array( 'name' => 'Emerald', 'type' => self::TYPE_THEME, 'rarity' => 'epic', 'css_class' => 'adam-digital-card--theme-emerald', 'unlock_source' => 'points' ),
			'card_theme_sapphire'          => array( 'name' => 'Sapphire', 'type' => self::TYPE_THEME, 'rarity' => 'epic', 'css_class' => 'adam-digital-card--theme-sapphire', 'unlock_source' => 'points' ),
			'card_theme_ruby'              => array( 'name' => 'Ruby', 'type' => self::TYPE_THEME, 'rarity' => 'epic', 'css_class' => 'adam-digital-card--theme-ruby', 'unlock_source' => 'points' ),
			'card_theme_obsidian'          => array( 'name' => 'Obsidian', 'type' => self::TYPE_THEME, 'rarity' => 'legendary', 'css_class' => 'adam-digital-card--theme-obsidian', 'unlock_source' => 'points' ),
			'card_theme_phoenix'           => array( 'name' => 'Phoenix', 'type' => self::TYPE_THEME, 'rarity' => 'legendary', 'css_class' => 'adam-digital-card--theme-phoenix', 'unlock_source' => 'points' ),
			'card_theme_platinum'          => array( 'name' => 'Platinum', 'type' => self::TYPE_THEME, 'rarity' => 'legendary', 'css_class' => 'adam-digital-card--theme-platinum', 'unlock_source' => 'points' ),
			'card_theme_founder'           => array( 'name' => 'Founder', 'type' => self::TYPE_THEME, 'rarity' => 'founder', 'css_class' => 'adam-digital-card--theme-founder', 'unlock_source' => 'founder' ),
			'card_theme_legendary_loyalty' => array( 'name' => 'Legado ADAM', 'type' => self::TYPE_THEME, 'rarity' => 'legendary', 'css_class' => 'adam-digital-card--theme-legendary-loyalty', 'unlock_source' => 'loyalty' ),
			'card_theme_christmas_edition' => array( 'name' => 'Christmas Edition', 'type' => self::TYPE_THEME, 'rarity' => 'limited_edition', 'css_class' => 'adam-digital-card--theme-christmas-edition', 'unlock_source' => 'special' ),
			'card_theme_halloween_edition' => array( 'name' => 'Halloween Edition', 'type' => self::TYPE_THEME, 'rarity' => 'limited_edition', 'css_class' => 'adam-digital-card--theme-halloween-edition', 'unlock_source' => 'special' ),
			'card_theme_anniversary_edition' => array( 'name' => 'Anniversary Edition', 'type' => self::TYPE_THEME, 'rarity' => 'limited_edition', 'css_class' => 'adam-digital-card--theme-anniversary-edition', 'unlock_source' => 'special' ),
			'card_frame_standard_silver'   => array( 'name' => 'Standard Silver', 'type' => self::TYPE_FRAME, 'rarity' => 'common', 'css_class' => 'adam-digital-card--frame-standard-silver', 'unlock_source' => 'points' ),
			'card_frame_tactical_green'    => array( 'name' => 'Tactical Green', 'type' => self::TYPE_FRAME, 'rarity' => 'common', 'css_class' => 'adam-digital-card--frame-tactical-green', 'unlock_source' => 'points' ),
			'card_frame_veteran_bronze'    => array( 'name' => 'Veteran Bronze', 'type' => self::TYPE_FRAME, 'rarity' => 'uncommon', 'css_class' => 'adam-digital-card--frame-veteran-bronze', 'unlock_source' => 'loyalty' ),
			'card_frame_carbon_edge'       => array( 'name' => 'Carbon Edge', 'type' => self::TYPE_FRAME, 'rarity' => 'uncommon', 'css_class' => 'adam-digital-card--frame-carbon-edge', 'unlock_source' => 'points' ),
			'card_frame_elite_blue'        => array( 'name' => 'Elite Blue', 'type' => self::TYPE_FRAME, 'rarity' => 'rare', 'css_class' => 'adam-digital-card--frame-elite-blue', 'unlock_source' => 'points' ),
			'card_frame_titanium'          => array( 'name' => 'Titanium', 'type' => self::TYPE_FRAME, 'rarity' => 'rare', 'css_class' => 'adam-digital-card--frame-titanium', 'unlock_source' => 'points' ),
			'card_frame_veteran_gold'      => array( 'name' => 'Veteran Gold', 'type' => self::TYPE_FRAME, 'rarity' => 'epic', 'css_class' => 'adam-digital-card--frame-veteran-gold', 'unlock_source' => 'loyalty' ),
			'card_frame_golden_honor'      => array( 'name' => 'Golden Honor', 'type' => self::TYPE_FRAME, 'rarity' => 'epic', 'css_class' => 'adam-digital-card--frame-golden-honor', 'unlock_source' => 'points' ),
			'card_frame_emerald_prestige'  => array( 'name' => 'Emerald Prestige', 'type' => self::TYPE_FRAME, 'rarity' => 'epic', 'css_class' => 'adam-digital-card--frame-emerald-prestige', 'unlock_source' => 'points' ),
			'card_frame_diamond_frame'     => array( 'name' => 'Diamond Frame', 'type' => self::TYPE_FRAME, 'rarity' => 'legendary', 'css_class' => 'adam-digital-card--frame-diamond-frame', 'unlock_source' => 'points' ),
			'card_frame_founder_frame'     => array( 'name' => 'Founder Frame', 'type' => self::TYPE_FRAME, 'rarity' => 'founder', 'css_class' => 'adam-digital-card--frame-founder-frame', 'unlock_source' => 'founder' ),
			'card_frame_legendary_loyalty' => array( 'name' => 'Legacy Frame', 'type' => self::TYPE_FRAME, 'rarity' => 'legendary', 'css_class' => 'adam-digital-card--frame-legendary-loyalty', 'unlock_source' => 'loyalty' ),
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $owned
	 */
	private function selected_cosmetic( Member $member, string $field, string $type, array $owned ): ?array {
		$selected_key = sanitize_key( (string) $member->field( $field ) );

		if ( '' === $selected_key || ! isset( $owned[ $selected_key ] ) ) {
			return null;
		}

		$cosmetic = $owned[ $selected_key ];

		return isset( $cosmetic['type'] ) && $type === $cosmetic['type'] ? $cosmetic : null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function owned_cosmetics_by_key( Member $member ): array {
		$owned    = array();
		$registry = $this->registry();

		foreach ( $this->rewards->member_redemptions( $member ) as $redemption ) {
			if ( ! in_array( $redemption->status(), array( RewardRedemption::STATUS_APPROVED, RewardRedemption::STATUS_DELIVERED ), true ) ) {
				continue;
			}

			$reward = $this->rewards->repository()->find_reward( $redemption->reward_id() );

			if ( ! $reward instanceof Reward ) {
				continue;
			}

			$key = sanitize_key( $reward->reward_value() );

			if ( '' === $key || ! isset( $registry[ $key ] ) || isset( $owned[ $key ] ) ) {
				continue;
			}

			$owned[ $key ] = $this->decorate_definition( $key, $registry[ $key ], $reward );
		}

		return $owned;
	}

	/**
	 * @param array<string, string> $definition
	 * @return array<string, mixed>
	 */
	private function decorate_definition( string $key, array $definition, Reward $reward ): array {
		$rarity = $definition['rarity'] ?? $reward->rarity();

		return array(
			'key'                 => $key,
			'name'                => '' !== $reward->name() ? $reward->name() : $definition['name'],
			'type'                => $definition['type'],
			'rarity'              => $rarity,
			'rarity_label'        => $this->rarity_label( $rarity ),
			'css_class'           => $definition['css_class'],
			'points_cost'         => $reward->points_cost(),
			'unlock_source'       => $definition['unlock_source'],
			'unlock_source_label' => $this->unlock_source_label( $definition['unlock_source'] ),
			'render_mode'         => 'css',
			'reward_id'           => $reward->id(),
		);
	}

	private function selected_loyalty_badge( ?array $active_title, ?array $active_theme, ?array $active_frame ): string {
		foreach ( array( $active_title, $active_theme, $active_frame ) as $cosmetic ) {
			if ( is_array( $cosmetic ) && 'loyalty' === ( $cosmetic['unlock_source'] ?? '' ) ) {
				return __( 'Fidelidade ADAM', 'adam-membership' );
			}
		}

		return '';
	}

	/**
	 * @param array<string, array<string, mixed>> $owned
	 * @return string|WP_Error
	 */
	private function validated_selection( string $selection, string $type, array $owned ): string|WP_Error {
		$key = sanitize_key( $selection );

		if ( '' === $key ) {
			return '';
		}

		if ( ! isset( $owned[ $key ] ) || $type !== ( $owned[ $key ]['type'] ?? '' ) ) {
			return new WP_Error(
				'adam_membership_invalid_cosmetic_selection',
				__( 'A selecao de cosmetico escolhida nao esta desbloqueada para esta conta.', 'adam-membership' )
			);
		}

		return $key;
	}

	private function rarity_label( string $rarity ): string {
		return match ( $rarity ) {
			'common'          => __( 'Comum', 'adam-membership' ),
			'uncommon'        => __( 'Incomum', 'adam-membership' ),
			'rare'            => __( 'Rara', 'adam-membership' ),
			'epic'            => __( 'Epica', 'adam-membership' ),
			'legendary'       => __( 'Lendaria', 'adam-membership' ),
			'limited_edition' => __( 'Edicao limitada', 'adam-membership' ),
			'founder'         => __( 'Fundador', 'adam-membership' ),
			default           => ucfirst( $rarity ),
		};
	}

	private function unlock_source_label( string $source ): string {
		return match ( $source ) {
			'points'   => __( 'Pontos ADAM', 'adam-membership' ),
			'loyalty'  => __( 'Fidelidade ADAM', 'adam-membership' ),
			'founder'  => __( 'Fundadores', 'adam-membership' ),
			'manual'   => __( 'Atribuicao manual', 'adam-membership' ),
			default    => __( 'Evento especial', 'adam-membership' ),
		};
	}
}
