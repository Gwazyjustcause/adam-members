<?php
/**
 * Frontend privacy and cookie consent manager.
 *
 * @package AdamMembership\Privacy
 */

declare(strict_types=1);

namespace AdamMembership\Privacy;

use AdamMembership\Core\SettingsRepository;

/**
 * Renders the cookie banner, stores consent state, and blocks non-essential scripts.
 */
final class ConsentManager {
	private const COOKIE_NAME    = 'adam_cookie_consent';
	private const COOKIE_MAX_AGE = 15552000;

	/**
	 * @var array<int, string>
	 */
	private const ALLOWED_CATEGORIES = array(
		'necessary',
		'preferences',
		'analytics',
		'marketing',
	);

	private SettingsRepository $settings;

	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register frontend hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_head', array( $this, 'render_bootstrap_script' ), 1 );
		add_action( 'wp_footer', array( $this, 'render_markup' ), 40 );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_loader_tag' ), 20, 3 );
	}

	/**
	 * Enqueue consent assets.
	 */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$style_path = ADAM_MEMBERSHIP_PATH . 'assets/css/privacy-consent.css';
		$script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/privacy-consent.js';

		wp_enqueue_style(
			'adam-privacy-consent',
			ADAM_MEMBERSHIP_URL . 'assets/css/privacy-consent.css',
			array(),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : ADAM_MEMBERSHIP_VERSION
		);

		wp_enqueue_script(
			'adam-privacy-consent',
			ADAM_MEMBERSHIP_URL . 'assets/js/privacy-consent.js',
			array(),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : ADAM_MEMBERSHIP_VERSION,
			true
		);

		wp_add_inline_script(
			'adam-privacy-consent',
			'window.adamCookieConsentConfig = ' . wp_json_encode( $this->script_config() ) . ';',
			'before'
		);
	}

	/**
	 * Render a tiny head bootstrap so consent state is available before deferred scripts activate.
	 */
	public function render_bootstrap_script(): void {
		if ( is_admin() ) {
			return;
		}

		$state = wp_json_encode( $this->current_state() );

		if ( ! is_string( $state ) ) {
			return;
		}
		?>
		<script id="adam-cookie-consent-bootstrap">
		window.adamCookieConsent = window.adamCookieConsent || {};
		window.adamCookieConsent.state = <?php echo $state; ?>;
		</script>
		<?php
	}

	/**
	 * Render banner, preferences sheet, and footer preferences link.
	 */
	public function render_markup(): void {
		if ( is_admin() ) {
			return;
		}

		$state             = $this->current_state();
		$has_decision      = ! empty( $state['has_decision'] );
		$privacy_url       = $this->settings->privacy_policy_url();
		$cookie_policy_url = $this->settings->cookie_policy_url();
		$terms_url         = $this->settings->membership_terms_url();
		?>
		<div class="adam-cookie-consent-root" data-adam-cookie-root>
			<div
				class="adam-cookie-banner<?php echo $has_decision ? ' is-hidden' : ''; ?>"
				role="region"
				aria-label="<?php esc_attr_e( 'Preferências de cookies', 'adam-membership' ); ?>"
				data-adam-cookie-banner
				hidden
			>
				<div class="adam-cookie-banner__content">
					<div>
						<h2><?php esc_html_e( 'A ADAM utiliza cookies para melhorar a tua experiência.', 'adam-membership' ); ?></h2>
						<p>
							<?php esc_html_e( 'Os cookies estritamente necessários permanecem ativos. Os restantes apenas serão carregados após a tua escolha.', 'adam-membership' ); ?>
							<?php if ( '' !== $privacy_url || '' !== $cookie_policy_url || '' !== $terms_url ) : ?>
								<span class="adam-cookie-banner__links">
									<?php if ( '' !== $privacy_url ) : ?>
										<a href="<?php echo esc_url( $privacy_url ); ?>"><?php esc_html_e( 'Política de Privacidade', 'adam-membership' ); ?></a>
									<?php endif; ?>
									<?php if ( '' !== $cookie_policy_url ) : ?>
										<a href="<?php echo esc_url( $cookie_policy_url ); ?>"><?php esc_html_e( 'Política de Cookies', 'adam-membership' ); ?></a>
									<?php endif; ?>
									<?php if ( '' !== $terms_url ) : ?>
										<a href="<?php echo esc_url( $terms_url ); ?>"><?php esc_html_e( 'Termos de Sócio', 'adam-membership' ); ?></a>
									<?php endif; ?>
								</span>
							<?php endif; ?>
						</p>
					</div>
					<div class="adam-cookie-banner__actions">
						<button type="button" class="adam-cookie-button is-secondary" data-adam-cookie-action="reject"><?php esc_html_e( 'Rejeitar não essenciais', 'adam-membership' ); ?></button>
						<button type="button" class="adam-cookie-button is-ghost" data-adam-cookie-action="customize" aria-expanded="false" aria-controls="adam-cookie-preferences"><?php esc_html_e( 'Personalizar', 'adam-membership' ); ?></button>
						<button type="button" class="adam-cookie-button" data-adam-cookie-action="accept"><?php esc_html_e( 'Aceitar tudo', 'adam-membership' ); ?></button>
					</div>
				</div>
			</div>

			<div class="adam-cookie-modal" data-adam-cookie-modal hidden>
				<div class="adam-cookie-modal__dialog" id="adam-cookie-preferences" role="dialog" aria-modal="false" aria-labelledby="adam-cookie-modal-title">
					<div class="adam-cookie-modal__header">
						<div>
							<p class="adam-cookie-modal__eyebrow"><?php esc_html_e( 'Cookies ADAM', 'adam-membership' ); ?></p>
							<h2 id="adam-cookie-modal-title"><?php esc_html_e( 'Escolhe as tuas preferências', 'adam-membership' ); ?></h2>
						</div>
						<button type="button" class="adam-cookie-icon-button" data-adam-cookie-close aria-label="<?php esc_attr_e( 'Fechar preferências de cookies', 'adam-membership' ); ?>">×</button>
					</div>
					<div class="adam-cookie-modal__body">
						<div class="adam-cookie-option is-required">
							<div>
								<h3><?php esc_html_e( 'Estritamente necessários', 'adam-membership' ); ?></h3>
								<p><?php esc_html_e( 'Garantem segurança, autenticação e funcionamento base do site e da Área de Sócio.', 'adam-membership' ); ?></p>
							</div>
							<span class="adam-cookie-pill"><?php esc_html_e( 'Sempre ativo', 'adam-membership' ); ?></span>
						</div>
						<label class="adam-cookie-option">
							<div>
								<h3><?php esc_html_e( 'Preferências', 'adam-membership' ); ?></h3>
								<p><?php esc_html_e( 'Permitem memorizar escolhas opcionais e elementos incorporados não essenciais.', 'adam-membership' ); ?></p>
							</div>
							<input type="checkbox" data-adam-cookie-category="preferences" <?php checked( ! empty( $state['preferences'] ) ); ?>>
						</label>
						<label class="adam-cookie-option">
							<div>
								<h3><?php esc_html_e( 'Analítica', 'adam-membership' ); ?></h3>
								<p><?php esc_html_e( 'Ajuda a ADAM a perceber como o site é utilizado e a melhorar a experiência.', 'adam-membership' ); ?></p>
							</div>
							<input type="checkbox" data-adam-cookie-category="analytics" <?php checked( ! empty( $state['analytics'] ) ); ?>>
						</label>
						<label class="adam-cookie-option">
							<div>
								<h3><?php esc_html_e( 'Marketing', 'adam-membership' ); ?></h3>
								<p><?php esc_html_e( 'Controla integrações promocionais e rastreio publicitário de terceiros.', 'adam-membership' ); ?></p>
							</div>
							<input type="checkbox" data-adam-cookie-category="marketing" <?php checked( ! empty( $state['marketing'] ) ); ?>>
						</label>
					</div>
					<div class="adam-cookie-modal__footer">
						<button type="button" class="adam-cookie-button is-secondary" data-adam-cookie-action="reject"><?php esc_html_e( 'Rejeitar não essenciais', 'adam-membership' ); ?></button>
						<button type="button" class="adam-cookie-button" data-adam-cookie-action="save"><?php esc_html_e( 'Guardar preferências', 'adam-membership' ); ?></button>
					</div>
				</div>
			</div>

			<div class="adam-cookie-footer-link">
				<button type="button" class="adam-cookie-footer-link__button" data-adam-cookie-action="reopen">
					<?php esc_html_e( 'Preferências de cookies', 'adam-membership' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Filter script tags and defer non-essential handles until consent is granted.
	 *
	 * @param string $tag Script tag HTML.
	 * @param string $handle Script handle.
	 * @param string $src Script source URL.
	 */
	public function filter_script_loader_tag( string $tag, string $handle, string $src ): string {
		if ( is_admin() ) {
			return $tag;
		}

		$category = $this->script_category_for_tag( $handle, $src, $tag );

		if ( '' === $category || $this->allows_category( $category ) ) {
			return $tag;
		}

		if ( str_contains( $tag, 'data-adam-consent=' ) ) {
			return $tag;
		}

		$replacement = '<script type="text/plain" data-adam-consent="' . esc_attr( $category ) . '" data-adam-consent-blocked="1" ';

		return preg_replace( '/<script\s/i', $replacement, $tag, 1 ) ?: $tag;
	}

	/**
	 * Get policy links and current state for the frontend script.
	 *
	 * @return array<string, mixed>
	 */
	private function script_config(): array {
		return array(
			'cookieName' => self::COOKIE_NAME,
			'maxAge'     => self::COOKIE_MAX_AGE,
			'state'      => $this->current_state(),
		);
	}

	/**
	 * @return array<string, bool>
	 */
	private function current_state(): array {
		$defaults = array(
			'has_decision' => false,
			'necessary'    => true,
			'preferences'  => false,
			'analytics'    => false,
			'marketing'    => false,
		);

		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return $defaults;
		}

		$raw = sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE_NAME ] ) );

		if ( '' === $raw ) {
			return $defaults;
		}

		$data = json_decode( rawurldecode( $raw ), true );

		if ( ! is_array( $data ) ) {
			return $defaults;
		}

		foreach ( self::ALLOWED_CATEGORIES as $category ) {
			$defaults[ $category ] = 'necessary' === $category ? true : ! empty( $data[ $category ] );
		}

		$defaults['has_decision'] = true;

		return $defaults;
	}

	/**
	 * Determine whether the current consent state allows a category.
	 */
	private function allows_category( string $category ): bool {
		$state = $this->current_state();

		return 'necessary' === $category || ! empty( $state[ $category ] );
	}

	/**
	 * Resolve the consent category for a script handle or source URL.
	 */
	private function script_category_for_tag( string $handle, string $src, string $tag ): string {
		if ( preg_match( '/data-adam-consent-category=(["\'])(.*?)\1/i', $tag, $matches ) ) {
			$category = sanitize_key( $matches[2] );

			if ( in_array( $category, self::ALLOWED_CATEGORIES, true ) && 'necessary' !== $category ) {
				return $category;
			}
		}

		$handle_categories = (array) apply_filters(
			'adam_membership_consent_handle_categories',
			array(
				'google-analytics' => 'analytics',
				'google-tag-manager' => 'analytics',
				'gtm' => 'analytics',
				'facebook-pixel' => 'marketing',
				'meta-pixel' => 'marketing',
				'tiktok-pixel' => 'marketing',
				'linkedin-insight' => 'marketing',
			)
		);

		$resolved = $handle_categories[ $handle ] ?? '';

		if ( is_string( $resolved ) && in_array( $resolved, self::ALLOWED_CATEGORIES, true ) ) {
			return $resolved;
		}

		$host = wp_parse_url( $src, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			if ( preg_match( '/\ssrc=(["\'])(.*?)\1/i', $tag, $matches ) ) {
				$host = (string) wp_parse_url( $matches[2], PHP_URL_HOST );
			}
		}

		if ( '' === $host ) {
			return '';
		}

		$domains = (array) apply_filters(
			'adam_membership_consent_domain_categories',
			array(
				'googletagmanager.com' => 'analytics',
				'google-analytics.com' => 'analytics',
				'stats.g.doubleclick.net' => 'analytics',
				'plausible.io' => 'analytics',
				'connect.facebook.net' => 'marketing',
				'static.ads-twitter.com' => 'marketing',
				'snap.licdn.com' => 'marketing',
				'www.youtube.com' => 'preferences',
				'player.vimeo.com' => 'preferences',
			)
		);

		foreach ( $domains as $domain => $category ) {
			if ( ! is_string( $domain ) || ! is_string( $category ) ) {
				continue;
			}

			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return in_array( $category, self::ALLOWED_CATEGORIES, true ) ? $category : '';
			}
		}

		return '';
	}
}
