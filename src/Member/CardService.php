<?php
/**
 * Digital membership card service.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Helpers\Logger;
use AdamMembership\Reward\Reward;
use AdamMembership\Reward\RewardService;
use WP_Error;
use WP_User_Query;

/**
 * Generates and validates digital membership cards.
 */
final class CardService {
	private const TOKEN_META = 'adam_membership_card_token';

	private MemberRepository $members;
	private SettingsRepository $settings;
	private Logger $logger;
	private CardCosmeticsService $cosmetics;
	private RewardService $rewards;

	/**
	 * Constructor.
	 */
	public function __construct( MemberRepository $members, SettingsRepository $settings, Logger $logger, CardCosmeticsService $cosmetics, RewardService $rewards ) {
		$this->members   = $members;
		$this->settings  = $settings;
		$this->logger    = $logger;
		$this->cosmetics = $cosmetics;
		$this->rewards   = $rewards;
	}

	/**
	 * Register frontend validation endpoint hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_render_validation_page' ) );
	}

	/**
	 * Get or create the validation token for a member.
	 *
	 * @param Member $member Member.
	 */
	public function token( Member $member ): string {
		$token = (string) get_user_meta( $member->user_id(), self::TOKEN_META, true );

		if ( '' !== $token ) {
			return $token;
		}

		return $this->regenerate_token( $member );
	}

	/**
	 * Regenerate the validation token for a member.
	 *
	 * @param Member $member Member.
	 */
	public function regenerate_token( Member $member ): string {
		$token = wp_generate_password( 48, false, false );

		update_user_meta( $member->user_id(), self::TOKEN_META, $token );
		$this->logger->info(
			'Digital membership card token regenerated.',
			array(
				'member_id' => $member->user_id(),
				'admin_id'  => get_current_user_id(),
			)
		);

		return $token;
	}

	/**
	 * Get the validation URL for a member.
	 *
	 * @param Member $member Member.
	 */
	public function validation_url( Member $member ): string {
		return add_query_arg(
			array(
				'adam_card_token' => $this->token( $member ),
			),
			home_url( '/validar-socio/' )
		);
	}

	/**
	 * Get a QR image URL for the member validation URL.
	 *
	 * @param Member $member Member.
	 */
	public function qr_image_url( Member $member ): string {
		/*
		 * Temporary lightweight QR generation: only the opaque validation URL is
		 * sent to the QR image service. Replace with a bundled local QR encoder
		 * when a dependency policy is chosen.
		 */
		return add_query_arg(
			array(
				'size' => '220x220',
				'data' => $this->validation_url( $member ),
			),
			'https://api.qrserver.com/v1/create-qr-code/'
		);
	}

	/**
	 * Render the public card validation page when requested.
	 */
	public function maybe_render_validation_page(): void {
		$token = isset( $_GET['adam_card_token'] ) ? sanitize_text_field( wp_unslash( $_GET['adam_card_token'] ) ) : '';

		if ( '' === $token ) {
			return;
		}

		if ( ! $this->is_validation_request() ) {
			return;
		}

		$member = $this->member_by_token( $token );
		$is_valid = null !== $member && $member->isActive();

		status_header( 200 );
		nocache_headers();
		$this->render_validation_markup( $member, $is_valid );
		exit;
	}

	/**
	 * Get association name.
	 */
	public function association_name(): string {
		return $this->settings->association_name();
	}

	/**
	 * Get association logo URL.
	 */
	public function association_logo_url(): string {
		return $this->settings->association_logo_url();
	}

	/**
	 * Get the resolved digital card cosmetics for a member.
	 *
	 * @return array<string, mixed>
	 */
	public function card_presentation( Member $member ): array {
		return $this->cosmetics->card_presentation( $member );
	}

	/**
	 * Build the normalized card data payload for a member.
	 *
	 * @return array<string, mixed>
	 */
	public function card_data( Member $member ): array {
		$member_number = (string) $member->field( 'numero_socio' );

		return array(
			'association_name' => $this->association_name(),
			'association_logo' => $this->association_logo_url(),
			'status'           => $member->effective_status(),
			'member_name'      => $member->full_name(),
			'member_number'    => $member_number,
			'member_number_ui' => '' !== $member_number ? $member_number : __( 'Numero por atribuir', 'adam-membership' ),
			'joined_date'      => $this->format_date( $member->field( 'data_adesao' ) ),
			'expiry_date'      => $this->format_date( $member->field( 'validade_quota' ) ),
			'photo_url'        => $member->media_url( 'profile_photo' ),
			'initials'         => $this->member_initials( $member->full_name() ),
			'validation_url'   => $this->validation_url( $member ),
			'qr_image_url'     => $this->qr_image_url( $member ),
		);
	}

	/**
	 * Build sample card data for the reward editor preview.
	 *
	 * @return array<string, mixed>
	 */
	public function preview_card_data(): array {
		return array(
			'association_name' => $this->association_name(),
			'association_logo' => $this->association_logo_url(),
			'status'           => Member::STATUS_ACTIVE,
			'member_name'      => __( 'NOME DO SOCIO', 'adam-membership' ),
			'member_number'    => 'ADAM-0001',
			'member_number_ui' => 'ADAM-0001',
			'joined_date'      => '28/06/2026',
			'expiry_date'      => '04/04/2027',
			'photo_url'        => '',
			'initials'         => 'NS',
			'validation_url'   => '',
			'qr_image_url'     => $this->preview_qr_data_uri(),
		);
	}

	/**
	 * Build preview card presentation for a reward being edited.
	 *
	 * @return array<string, mixed>
	 */
	public function reward_preview_presentation( Reward $reward, array $style_override = array() ): array {
		$resolved_style = array_merge(
			$this->rewards->reward_visual_style( $reward ),
			$style_override
		);

		$presentation = array(
			'classes'       => array( 'adam-digital-card' ),
			'custom_style'  => $resolved_style,
			'founder_badge' => '',
			'loyalty_badge' => '',
			'active_title'  => array(
				'name'   => __( 'SOBREVIVENTE', 'adam-membership' ),
				'rarity' => Reward::RARITY_COMMON,
			),
		);

		$reward_value = sanitize_key( $reward->reward_value() );

		if ( str_starts_with( $reward_value, 'card_theme_' ) ) {
			$presentation['classes'][] = 'adam-digital-card--theme-rarity-' . sanitize_html_class( $reward->rarity() );
		}

		if ( str_starts_with( $reward_value, 'card_frame_' ) ) {
			$presentation['classes'][] = 'adam-digital-card--has-frame';
			$presentation['classes'][] = 'adam-digital-card--frame-rarity-' . sanitize_html_class( $reward->rarity() );
		}

		return $presentation;
	}

	/**
	 * Render the shared digital card component.
	 *
	 * @param array<string, mixed> $card_data Card data payload.
	 * @param array<string, mixed> $presentation Card presentation payload.
	 */
	public function render_card( array $card_data, array $presentation = array() ): string {
		$resolved = $this->resolve_card_presentation( $presentation );

		ob_start();
		?>
		<article class="<?php echo esc_attr( $resolved['class_name'] ); ?>"<?php echo '' !== $resolved['inline_style'] ? ' style="' . esc_attr( $resolved['inline_style'] ) . '"' : ''; ?> data-adam-card-preview data-adam-card-base-class="<?php echo esc_attr( $resolved['base_class_name'] ); ?>" aria-label="<?php esc_attr_e( 'ADAM digital membership card', 'adam-membership' ); ?>">
			<div class="adam-digital-card__shine" aria-hidden="true"></div>
			<div class="adam-digital-card__backdrop"<?php echo '' !== $resolved['background_image_url'] ? ' style="background-image:url(' . esc_url( $resolved['background_image_url'] ) . ');"' : ''; ?> data-adam-card-backdrop></div>
			<div class="adam-digital-card__pattern adam-digital-card__pattern--<?php echo esc_attr( $resolved['pattern'] ); ?>" data-adam-card-pattern></div>
			<div class="adam-digital-card__art adam-digital-card__art--<?php echo esc_attr( $resolved['image_position'] ); ?> adam-digital-card__art--layer-<?php echo esc_attr( $resolved['image_layer'] ); ?>"<?php echo '' === $resolved['art_image_url'] ? ' hidden' : ''; ?> data-adam-card-art-wrap>
				<img src="<?php echo esc_url( $resolved['art_image_url'] ); ?>" alt="" data-adam-card-art>
			</div>
			<div class="adam-digital-card__shapes" data-adam-card-shapes>
				<?php foreach ( $resolved['shapes'] as $shape ) : ?>
					<?php $this->render_shape( (array) $shape ); ?>
				<?php endforeach; ?>
			</div>
			<div class="adam-digital-card__frame" aria-hidden="true"></div>
			<div class="adam-digital-card__frame-shine" aria-hidden="true"></div>

			<header class="adam-digital-card__header">
				<img class="adam-digital-card__logo" src="<?php echo esc_url( (string) $card_data['association_logo'] ); ?>" alt="<?php echo esc_attr( (string) $card_data['association_name'] ); ?>">
				<div>
					<span><?php esc_html_e( 'Associacao Desportiva', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( (string) $card_data['association_name'] ); ?></strong>
					<div class="adam-digital-card__badges">
						<?php if ( '' !== (string) ( $presentation['founder_badge'] ?? '' ) ) : ?>
							<small class="adam-digital-card__founder"><?php echo esc_html( (string) $presentation['founder_badge'] ); ?></small>
						<?php endif; ?>
						<?php if ( '' !== (string) ( $presentation['loyalty_badge'] ?? '' ) ) : ?>
							<small class="adam-digital-card__loyalty"><?php echo esc_html( (string) $presentation['loyalty_badge'] ); ?></small>
						<?php endif; ?>
					</div>
				</div>
				<?php echo wp_kses_post( $this->status_badge_markup( (string) $card_data['status'] ) ); ?>
			</header>

			<div class="adam-digital-card__body">
				<div class="adam-digital-card__photo">
					<?php if ( '' !== (string) $card_data['photo_url'] ) : ?>
						<img src="<?php echo esc_url( (string) $card_data['photo_url'] ); ?>" alt="<?php echo esc_attr( (string) $card_data['member_name'] ); ?>">
					<?php else : ?>
						<span><?php echo esc_html( (string) $card_data['initials'] ); ?></span>
					<?php endif; ?>
				</div>

				<div class="adam-digital-card__identity">
					<span><?php esc_html_e( 'Nome do socio', 'adam-membership' ); ?></span>
					<?php if ( is_array( $presentation['active_title'] ?? null ) && '' !== (string) ( $presentation['active_title']['name'] ?? '' ) ) : ?>
						<div class="adam-digital-card__rank">
							<small><?php esc_html_e( 'Titulo ativo', 'adam-membership' ); ?></small>
							<em class="adam-digital-card__title adam-digital-card__title--<?php echo esc_attr( sanitize_html_class( (string) ( $presentation['active_title']['rarity'] ?? 'common' ) ) ); ?>" data-adam-card-title>
								<span class="adam-digital-card__title-mark" aria-hidden="true"></span>
								<span data-adam-card-title-text><?php echo esc_html( (string) $presentation['active_title']['name'] ); ?></span>
							</em>
						</div>
					<?php endif; ?>
					<strong><?php echo esc_html( (string) $card_data['member_name'] ); ?></strong>
					<small><?php echo esc_html( (string) $card_data['member_number_ui'] ); ?></small>
				</div>

				<div class="adam-digital-card__qr">
					<img src="<?php echo esc_url( (string) $card_data['qr_image_url'] ); ?>" alt="<?php esc_attr_e( 'QR code for member validation', 'adam-membership' ); ?>">
					<span><?php esc_html_e( 'Validar cartao', 'adam-membership' ); ?></span>
				</div>
			</div>

			<div class="adam-digital-card__details" aria-label="<?php esc_attr_e( 'Membership details', 'adam-membership' ); ?>">
				<div>
					<span><?php esc_html_e( 'N.º de socio', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( (string) $card_data['member_number_ui'] ); ?></strong>
				</div>
				<div>
					<span><?php esc_html_e( 'Data de adesao', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( '' !== (string) $card_data['joined_date'] ? (string) $card_data['joined_date'] : __( 'Indisponivel', 'adam-membership' ) ); ?></strong>
				</div>
				<div>
					<span><?php esc_html_e( 'Valido ate', 'adam-membership' ); ?></span>
					<strong><?php echo esc_html( '' !== (string) $card_data['expiry_date'] ? (string) $card_data['expiry_date'] : __( 'Indisponivel', 'adam-membership' ) ); ?></strong>
				</div>
			</div>

			<footer class="adam-digital-card__footer">
				<span><?php esc_html_e( 'airsoftmondego.pt', 'adam-membership' ); ?></span>
				<span><?php esc_html_e( 'Cartao digital ADAM', 'adam-membership' ); ?></span>
			</footer>
		</article>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Get unlocked member cosmetic options.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function member_cosmetic_options( Member $member ): array {
		return $this->cosmetics->member_options( $member );
	}

	/**
	 * Persist member cosmetic selections.
	 *
	 * @param array<string, mixed> $raw_data Raw posted data.
	 * @return true|WP_Error
	 */
	public function save_member_cosmetic_selection( Member $member, array $raw_data ): true|WP_Error {
		return $this->cosmetics->save_member_selection( $member, $raw_data );
	}

	/**
	 * Build the presentation metadata used by the shared renderer.
	 *
	 * @param array<string, mixed> $presentation Raw presentation payload.
	 * @return array<string, mixed>
	 */
	private function resolve_card_presentation( array $presentation ): array {
		$style            = isset( $presentation['custom_style'] ) && is_array( $presentation['custom_style'] ) ? $presentation['custom_style'] : array();
		$classes          = array_map( 'sanitize_html_class', (array) ( $presentation['classes'] ?? array( 'adam-digital-card' ) ) );
		$card_subtype     = sanitize_key( (string) ( $style['card_subtype'] ?? 'background' ) );
		$background_mode  = sanitize_key( (string) ( $style['background_mode'] ?? 'gradient' ) );
		$pattern          = sanitize_html_class( (string) ( $style['pattern'] ?? 'grid' ) );
		$image_position   = sanitize_html_class( (string) ( $style['card_image_position'] ?? 'top-right' ) );
		$image_layer      = sanitize_html_class( (string) ( $style['card_image_layer'] ?? 'overlay' ) );
		$frame_style      = $this->normalize_frame_preset( $style['frame_style'] ?? 'none' );
		$frame_finish     = $this->normalize_frame_finish( $style['frame_finish'] ?? 'none' );
		$badge_style      = sanitize_html_class( (string) ( $style['badge_style'] ?? 'soft' ) );
		$rarity_effect    = sanitize_html_class( (string) ( $style['rarity_effect'] ?? 'auto' ) );
		$classes_for_auto = (array) ( $presentation['classes'] ?? array() );
		$rarity_effect    = 'auto' === $rarity_effect ? $this->auto_rarity_effect( $classes_for_auto ) : $rarity_effect;
		$art_image_url    = 'card_style' === $card_subtype ? esc_url_raw( (string) ( $style['image_url'] ?? '' ) ) : '';
		$background_image = 'image' === $background_mode ? esc_url_raw( (string) ( $style['background_image_url'] ?? '' ) ) : '';

		$classes[] = 'adam-digital-card--preview-pattern-' . $pattern;
		$classes[] = 'adam-digital-card--preview-frame-' . $frame_style;
		$classes[] = 'adam-digital-card--preview-frame-finish-' . $frame_finish;
		if ( ! empty( $style['frame_shine_animated'] ) ) {
			$classes[] = 'adam-digital-card--preview-frame-shine-animated';
		}
		$classes[] = 'adam-digital-card--preview-badge-' . $badge_style;
		$classes[] = 'adam-digital-card--preview-effect-' . $rarity_effect;

		return array(
			'base_class_name'     => implode( ' ', array_values( array_unique( array_filter( array_map( 'sanitize_html_class', (array) ( $presentation['classes'] ?? array( 'adam-digital-card' ) ) ) ) ) ) ),
			'class_name'          => implode( ' ', array_values( array_unique( array_filter( $classes ) ) ) ),
			'inline_style'        => $this->preview_inline_style( $style ),
			'pattern'             => '' !== $pattern ? $pattern : 'grid',
			'image_position'      => '' !== $image_position ? $image_position : 'top-right',
			'image_layer'         => '' !== $image_layer ? $image_layer : 'overlay',
			'art_image_url'       => $art_image_url,
			'background_image_url'=> $background_image,
			'shapes'              => is_array( $style['shapes'] ?? null ) ? $style['shapes'] : array(),
		);
	}

	/**
	 * @param array<string, mixed> $style Style payload.
	 */
	private function preview_inline_style( array $style ): string {
		if ( array() === $style ) {
			return '';
		}

		$background = $this->preview_background_value( $style );
		$is_style_reward = 'card_style' === sanitize_key( (string) ( $style['card_subtype'] ?? 'background' ) );
		$frame_style    = $this->normalize_frame_preset( $style['frame_style'] ?? 'none' );
		$frame_width    = $is_style_reward && 'none' !== $frame_style ? max( 0, (int) ( $style['border_width'] ?? 0 ) ) : 0;
		$frame_color    = (string) ( $style['border_color'] ?? '#ffffff' );
		$frame_finish   = $this->normalize_frame_finish( $style['frame_finish'] ?? 'none' );
		$frame_secondary = $this->frame_supports_secondary_color( $frame_style )
			? (string) ( $style['frame_inner_color'] ?? '#ffffff' )
			: $frame_color;
		$vars       = array(
			'--adam-card-surface'                 => $background,
			'--adam-card-ink'                     => (string) ( $style['text_color'] ?? '#ffffff' ),
			'--adam-card-muted'                   => (string) ( $style['muted_text_color'] ?? 'rgba(255,255,255,0.82)' ),
			'--adam-card-radius'                  => '28px',
			'--adam-card-shadow'                  => 'none',
			'--adam-frame-width'                  => $frame_width . 'px',
			'--adam-frame-visibility'             => $frame_width > 0 ? '1' : '0',
			'--adam-frame-color'                  => $frame_color,
			'--adam-frame-secondary-color'        => $frame_secondary,
			'--adam-card-frame-inset'             => '0px',
			'--adam-frame-inner-highlight'        => (string) ( max( 0, min( 100, (int) ( $style['frame_inner_highlight'] ?? 24 ) ) ) / 100 ),
			'--adam-frame-inner-glow'             => (string) ( max( 0, min( 100, (int) ( $style['frame_inner_glow'] ?? 0 ) ) ) / 100 ),
			'--adam-frame-accent-line'            => (string) ( max( 0, min( 100, (int) ( $style['frame_accent_line'] ?? 0 ) ) ) / 100 ),
			'--adam-frame-shine-opacity'          => ! empty( $style['frame_shine_enabled'] ) ? (string) ( max( 0, min( 100, (int) ( $style['frame_shine_intensity'] ?? 0 ) ) ) / 100 ) : '0',
			'--adam-frame-shine-angle'            => max( 0, min( 180, (int) ( $style['frame_shine_angle'] ?? 135 ) ) ) . 'deg',
			'--adam-frame-shine-width'            => max( 10, min( 60, (int) ( $style['frame_shine_width'] ?? 26 ) ) ) . '%',
			'--adam-frame-shine-duration'         => max( 4, min( 24, (int) ( $style['frame_shine_speed'] ?? 10 ) ) ) . 's',
			'--adam-card-content-padding'         => '28px',
			'--adam-card-content-gap'             => '20px',
			'--adam-card-title-surface'           => $this->color_with_alpha( (string) ( $style['accent_color'] ?? '#ffffff' ), 0.18 ),
			'--adam-card-title-border'            => $this->color_with_alpha( (string) ( $style['accent_color'] ?? '#ffffff' ), 0.26 ),
			'--adam-card-title-color'             => (string) ( $style['title_color'] ?? ( $style['text_color'] ?? '#ffffff' ) ),
			'--adam-card-title-size'              => max( 14, min( 28, (int) ( $style['title_size'] ?? 15 ) ) ) . 'px',
			'--adam-card-title-weight'            => (string) max( 400, (int) ( $style['title_weight'] ?? 900 ) ),
			'--adam-card-title-align'             => (string) ( $style['title_align'] ?? 'left' ),
			'--adam-card-title-shadow'            => max( 0, (int) ( $style['title_shadow'] ?? 0 ) ) . 'px',
			'--adam-card-photo-border'            => $this->color_with_alpha( (string) ( $style['accent_color'] ?? '#ffffff' ), 0.8 ),
			'--adam-card-pattern-color'           => (string) ( $style['pattern_color'] ?? '#86efac' ),
			'--adam-card-pattern-base'            => (string) ( $style['pattern_background_color'] ?? '#143826' ),
			'--adam-card-pattern-opacity'         => (string) ( max( 0, min( 100, (int) ( $style['pattern_opacity'] ?? 18 ) ) ) / 100 ),
			'--adam-card-pattern-size'            => max( 6, (int) ( $style['pattern_scale'] ?? 24 ) ) . 'px',
			'--adam-card-pattern-spacing'         => max( 6, (int) ( $style['pattern_spacing'] ?? 24 ) ) . 'px',
			'--adam-card-pattern-density'         => (string) max( 1, (int) ( $style['pattern_density'] ?? 2 ) ),
			'--adam-card-pattern-rotation'        => max( 0, (int) ( $style['pattern_rotation'] ?? 0 ) ) . 'deg',
			'--adam-card-background-opacity'      => (string) ( max( 0, min( 100, (int) ( $style['background_image_opacity'] ?? 18 ) ) ) / 100 ),
			'--adam-card-background-size'         => max( 20, (int) ( $style['background_image_size'] ?? 100 ) ) . '%',
			'--adam-card-background-position'     => str_replace( '-', ' ', (string) ( $style['background_image_position'] ?? 'center' ) ),
			'--adam-card-background-blend'        => (string) ( $style['background_image_blend_mode'] ?? 'screen' ),
			'--adam-card-art-opacity'             => (string) ( max( 0, min( 100, (int) ( $style['card_image_opacity'] ?? 22 ) ) ) / 100 ),
			'--adam-card-art-size'                => max( 10, (int) ( $style['card_image_size'] ?? 36 ) ) . '%',
		);

		if ( 'none' === $frame_finish ) {
			$vars['--adam-frame-inner-highlight'] = '0';
			$vars['--adam-frame-inner-glow']      = '0';
			$vars['--adam-frame-accent-line']     = '0';
		}

		$parts = array();

		foreach ( $vars as $property => $value ) {
			$parts[] = $property . ':' . $value;
		}

		return implode( ';', $parts ) . ';';
	}

	/**
	 * Normalize legacy frame presets to the new compact model.
	 */
	private function normalize_frame_preset( mixed $value ): string {
		$preset = sanitize_key( (string) $value );

		return match ( $preset ) {
			'solid', 'simple' => 'simple',
			'double' => 'double',
			'segmented', 'accent' => 'accent',
			'metallic' => 'metallic',
			'neon' => 'neon',
			'premium' => 'premium',
			default => 'none',
		};
	}

	/**
	 * Determine whether a frame preset needs a secondary tone.
	 */
	private function frame_supports_secondary_color( string $preset ): bool {
		return in_array( $preset, array( 'double', 'accent', 'metallic', 'neon', 'premium' ), true );
	}

	/**
	 * Normalize frame finish values.
	 */
	private function normalize_frame_finish( mixed $value ): string {
		$finish = sanitize_key( (string) $value );

		return in_array( $finish, array( 'none', 'glossy', 'metallic', 'neon', 'satin' ), true ) ? $finish : 'none';
	}

	/**
	 * @param array<string, mixed> $shape Shape configuration.
	 */
	private function render_shape( array $shape ): void {
		$type     = sanitize_html_class( (string) ( $shape['type'] ?? 'circle' ) );
		$x        = max( 0, min( 100, (int) ( $shape['x'] ?? 50 ) ) );
		$y        = max( 0, min( 100, (int) ( $shape['y'] ?? 50 ) ) );
		$width    = max( 1, min( 100, (int) ( $shape['width'] ?? 12 ) ) );
		$height   = max( 1, min( 100, (int) ( $shape['height'] ?? 12 ) ) );
		$rotation = max( 0, min( 360, (int) ( $shape['rotation'] ?? 0 ) ) );
		$opacity  = max( 0, min( 100, (int) ( $shape['opacity'] ?? 28 ) ) ) / 100;
		$color    = sanitize_text_field( (string) ( $shape['color'] ?? '#ffffff' ) );
		?>
		<span class="adam-digital-card__shape adam-digital-card__shape--<?php echo esc_attr( $type ); ?>" style="<?php echo esc_attr( sprintf( 'left:%1$s%%;top:%2$s%%;width:%3$s%%;height:%4$s%%;transform:rotate(%5$sdeg);opacity:%6$s;background:%7$s;', (string) $x, (string) $y, (string) $width, (string) $height, (string) $rotation, (string) $opacity, $color ) ); ?>"></span>
		<?php
	}

	private function status_badge_markup( string $status ): string {
		return sprintf(
			'<span class="adam-badge %1$s">%2$s</span>',
			esc_attr( $this->status_class( $status ) ),
			esc_html( $status )
		);
	}

	private function status_class( string $status ): string {
		if ( Member::STATUS_ACTIVE === $status ) {
			return 'active';
		}

		if ( Member::STATUS_REJECTED === $status ) {
			return 'rejected expired';
		}

		if ( Member::STATUS_EXPIRED === $status ) {
			return 'expired';
		}

		if ( Member::STATUS_RENEWAL_PENDING === $status ) {
			return 'pending warning renewal-pending';
		}

		if ( Member::STATUS_PENDING === $status ) {
			return 'pending warning';
		}

		return 'unknown';
	}

	private function preview_background_value( array $style ): string {
		$primary   = (string) ( $style['background_color'] ?? '#143826' );
		$secondary = (string) ( $style['background_color_secondary'] ?? $primary );
		$tertiary  = (string) ( $style['background_color_tertiary'] ?? $secondary );
		$angle     = max( 0, min( 360, (int) ( $style['gradient_angle'] ?? 135 ) ) );
		$origin    = str_replace( '-', ' ', (string) ( $style['gradient_origin'] ?? 'center' ) );
		$midpoint  = max( 0, min( 100, (int) ( $style['gradient_stop_secondary'] ?? 52 ) ) );
		$end       = max( 0, min( 100, (int) ( $style['gradient_stop_tertiary'] ?? 100 ) ) );
		$opacity   = max( 0, min( 100, (int) ( $style['gradient_opacity'] ?? 100 ) ) ) / 100;

		if ( 'solid' === (string) ( $style['background_mode'] ?? 'gradient' ) ) {
			return $primary;
		}

		return sprintf(
			'radial-gradient(circle at %1$s, %2$s 0%%, transparent 34%%), linear-gradient(%3$sdeg, %4$s 0%%, %5$s %6$s%%, %7$s %8$s%%)',
			$origin,
			$this->color_with_alpha( $primary, $opacity * 0.48 ),
			(string) $angle,
			$primary,
			$secondary,
			(string) $midpoint,
			$tertiary,
			(string) $end
		);
	}

	private function color_with_alpha( string $color, float $alpha ): string {
		$color = trim( $color );
		$alpha = max( 0, min( 1, $alpha ) );

		if ( preg_match( '/^#([a-f0-9]{6})$/i', $color, $matches ) ) {
			$hex = $matches[1];

			return sprintf(
				'rgba(%1$d, %2$d, %3$d, %4$s)',
				hexdec( substr( $hex, 0, 2 ) ),
				hexdec( substr( $hex, 2, 2 ) ),
				hexdec( substr( $hex, 4, 2 ) ),
				(string) $alpha
			);
		}

		if ( preg_match( '/^#([a-f0-9]{3})$/i', $color, $matches ) ) {
			$hex = $matches[1];

			return sprintf(
				'rgba(%1$d, %2$d, %3$d, %4$s)',
				hexdec( str_repeat( $hex[0], 2 ) ),
				hexdec( str_repeat( $hex[1], 2 ) ),
				hexdec( str_repeat( $hex[2], 2 ) ),
				(string) $alpha
			);
		}

		return $color;
	}

	private function member_initials( string $full_name ): string {
		$parts = preg_split( '/\s+/', trim( $full_name ) );
		$parts = is_array( $parts ) ? array_values( array_filter( $parts ) ) : array();

		if ( array() === $parts ) {
			return 'AD';
		}

		$initials = '';

		foreach ( array_slice( $parts, 0, 2 ) as $part ) {
			$initials .= strtoupper( substr( $part, 0, 1 ) );
		}

		return $initials;
	}

	private function preview_qr_data_uri(): string {
		$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 220 220">
<rect width="220" height="220" fill="#ffffff"/>
<g fill="#0f2f1e">
<rect x="16" y="16" width="56" height="56"/><rect x="24" y="24" width="40" height="40" fill="#fff"/><rect x="32" y="32" width="24" height="24"/>
<rect x="148" y="16" width="56" height="56"/><rect x="156" y="24" width="40" height="40" fill="#fff"/><rect x="164" y="32" width="24" height="24"/>
<rect x="16" y="148" width="56" height="56"/><rect x="24" y="156" width="40" height="40" fill="#fff"/><rect x="32" y="164" width="24" height="24"/>
<rect x="92" y="20" width="12" height="12"/><rect x="108" y="20" width="12" height="12"/><rect x="124" y="20" width="12" height="12"/>
<rect x="88" y="48" width="16" height="16"/><rect x="112" y="48" width="12" height="12"/><rect x="128" y="44" width="16" height="16"/>
<rect x="88" y="84" width="12" height="12"/><rect x="104" y="84" width="12" height="12"/><rect x="120" y="84" width="12" height="12"/><rect x="136" y="84" width="12" height="12"/>
<rect x="88" y="108" width="20" height="20"/><rect x="116" y="108" width="12" height="12"/><rect x="136" y="108" width="20" height="20"/>
<rect x="84" y="140" width="12" height="12"/><rect x="100" y="140" width="12" height="12"/><rect x="120" y="140" width="12" height="12"/><rect x="140" y="140" width="12" height="12"/>
<rect x="88" y="160" width="16" height="16"/><rect x="112" y="160" width="28" height="12"/><rect x="148" y="160" width="16" height="16"/>
<rect x="88" y="184" width="12" height="12"/><rect x="108" y="184" width="12" height="12"/><rect x="128" y="184" width="12" height="12"/><rect x="148" y="184" width="12" height="12"/>
</g>
</svg>
SVG;

		return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode( $svg );
	}

	/**
	 * @param array<int, string> $classes Base presentation classes.
	 */
	private function auto_rarity_effect( array $classes ): string {
		$class_string = implode( ' ', $classes );

		if ( str_contains( $class_string, 'legendary' ) || str_contains( $class_string, 'founder' ) ) {
			return 'metallic';
		}

		if ( str_contains( $class_string, 'epic' ) || str_contains( $class_string, 'rare' ) || str_contains( $class_string, 'limited_edition' ) ) {
			return 'glow';
		}

		return 'subtle';
	}

	/**
	 * Determine whether the current request is for the validation endpoint.
	 */
	private function is_validation_request(): bool {
		$request_path    = wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		$validation_path = wp_parse_url( home_url( '/validar-socio/' ), PHP_URL_PATH );

		if ( ! is_string( $request_path ) || ! is_string( $validation_path ) ) {
			return false;
		}

		return trim( $request_path, '/' ) === trim( $validation_path, '/' );
	}

	/**
	 * Find a member by validation token.
	 *
	 * @param string $token Validation token.
	 */
	private function member_by_token( string $token ): ?Member {
		$query = new WP_User_Query(
			array(
				'fields'     => 'ID',
				'number'     => 1,
				'meta_key'   => self::TOKEN_META,
				'meta_value' => $token,
			)
		);

		$results = $query->get_results();

		if ( array() === $results ) {
			return null;
		}

		return $this->members->find( absint( $results[0] ) );
	}

	/**
	 * Render validation result markup.
	 *
	 * @param Member|null $member   Member.
	 * @param bool        $is_valid Whether the member is valid.
	 */
	private function render_validation_markup( ?Member $member, bool $is_valid ): void {
		$status       = null !== $member ? $member->effective_status() : __( 'Invalid token', 'adam-membership' );
		$validated_at = wp_date( 'd/m/Y H:i', current_time( 'timestamp' ) );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e( 'ADAM Member Validation', 'adam-membership' ); ?></title>
			<?php wp_head(); ?>
			<style>
				body { margin: 0; background: linear-gradient(135deg, #f4faf5, #e8f4ea); color: #102033; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
				.adam-card-validation { min-height: 100vh; display: grid; place-items: center; padding: 28px; }
				.adam-card-validation__panel { width: min(580px, 100%); padding: 34px; border: 1px solid #d9e4dc; border-radius: 26px; background: #fff; box-shadow: 0 22px 56px rgba(23, 63, 36, 0.16); text-align: center; }
				.adam-card-validation__logo { max-width: 150px; height: auto; margin-bottom: 18px; }
				.adam-card-validation h1 { margin: 0 0 10px; color: #173f24; font-size: clamp(2rem, 6vw, 3rem); line-height: 1; }
				.adam-card-validation p { margin: 0; color: #5d6b7c; }
				.adam-card-validation__status { display: inline-flex; margin: 18px 0 22px; padding: 9px 16px; border-radius: 999px; font-weight: 800; }
				.adam-card-validation__status.valid { background: #dcfce7; color: #14532d; }
				.adam-card-validation__status.invalid { background: #fee2e2; color: #991b1b; }
				.adam-card-validation__data { display: grid; gap: 10px; margin-top: 18px; text-align: left; }
				.adam-card-validation__row { display: flex; justify-content: space-between; gap: 18px; padding: 12px 0; border-bottom: 1px solid #edf3ee; }
				.adam-card-validation__row span { color: #5d6b7c; font-weight: 700; }
				.adam-card-validation__row strong { text-align: right; }
				.adam-card-validation__checked { margin-top: 20px; padding-top: 18px; border-top: 1px solid #edf3ee; font-size: 0.92rem; }
			</style>
		</head>
		<body>
			<main class="adam-card-validation">
				<section class="adam-card-validation__panel">
					<img class="adam-card-validation__logo" src="<?php echo esc_url( $this->association_logo_url() ); ?>" alt="<?php echo esc_attr( $this->association_name() ); ?>">
					<h1><?php echo esc_html( $is_valid ? __( 'Valid member', 'adam-membership' ) : __( 'Invalid or inactive member', 'adam-membership' ) ); ?></h1>
					<p><?php echo esc_html( $this->association_name() ); ?></p>
					<span class="adam-card-validation__status <?php echo esc_attr( $is_valid ? 'valid' : 'invalid' ); ?>"><?php echo esc_html( $is_valid ? __( 'Active', 'adam-membership' ) : __( 'Not active', 'adam-membership' ) ); ?></span>
					<?php if ( null !== $member ) : ?>
						<div class="adam-card-validation__data">
							<?php $this->render_validation_row( __( 'Name', 'adam-membership' ), $member->full_name() ); ?>
							<?php $this->render_validation_row( __( 'Member number', 'adam-membership' ), (string) $member->field( 'numero_socio' ) ); ?>
							<?php $this->render_validation_row( __( 'Status', 'adam-membership' ), $status ); ?>
							<?php $this->render_validation_row( __( 'Date of subscription', 'adam-membership' ), $this->format_date( $member->field( 'data_adesao' ) ) ); ?>
							<?php $this->render_validation_row( __( 'Quota expiry', 'adam-membership' ), $this->format_date( $member->field( 'validade_quota' ) ) ); ?>
						</div>
					<?php else : ?>
						<p><?php esc_html_e( 'The validation token is missing or invalid.', 'adam-membership' ); ?></p>
					<?php endif; ?>
					<p class="adam-card-validation__checked">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: validation date and time. */
								__( 'Validated on %s', 'adam-membership' ),
								$validated_at
							)
						);
						?>
					</p>
				</section>
			</main>
			<?php wp_footer(); ?>
		</body>
		</html>
		<?php
	}

	/**
	 * Render a validation data row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private function render_validation_row( string $label, string $value ): void {
		?>
		<div class="adam-card-validation__row">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( '' !== $value ? $value : __( 'Unavailable', 'adam-membership' ) ); ?></strong>
		</div>
		<?php
	}

	/**
	 * Format a stored date.
	 *
	 * @param mixed $date Stored date.
	 */
	private function format_date( mixed $date ): string {
		if ( ! is_scalar( $date ) ) {
			return '';
		}

		$date = trim( (string) $date );

		if ( preg_match( '/^\d{8}$/', $date ) ) {
			return substr( $date, 6, 2 ) . '/' . substr( $date, 4, 2 ) . '/' . substr( $date, 0, 4 );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return substr( $date, 8, 2 ) . '/' . substr( $date, 5, 2 ) . '/' . substr( $date, 0, 4 );
		}

		return $date;
	}
}
