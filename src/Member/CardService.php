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

	/**
	 * Constructor.
	 */
	public function __construct( MemberRepository $members, SettingsRepository $settings, Logger $logger, CardCosmeticsService $cosmetics ) {
		$this->members   = $members;
		$this->settings  = $settings;
		$this->logger    = $logger;
		$this->cosmetics = $cosmetics;
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
