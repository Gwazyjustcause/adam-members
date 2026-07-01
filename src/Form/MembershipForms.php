<?php
/**
 * Native membership forms frontend.
 *
 * @package AdamMembership\Form
 */

declare(strict_types=1);

namespace AdamMembership\Form;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Member\RenewalService;
use WP_Error;

/**
 * Renders and processes native registration and renewal forms.
 */
final class MembershipForms {
	private SettingsRepository $settings;
	private MemberRepository $members;
	private RegistrationService $registration;
	private RenewalService $renewals;

	/**
	 * @var array<string, mixed>|null
	 */
	private ?array $form_settings = null;

	public function __construct( SettingsRepository $settings, MemberRepository $members, RegistrationService $registration, RenewalService $renewals ) {
		$this->settings     = $settings;
		$this->members      = $members;
		$this->registration = $registration;
		$this->renewals     = $renewals;
	}

	/**
	 * Register shortcodes and assets.
	 */
	public function register(): void {
		add_shortcode( 'adam_registration_form', array( $this, 'render_registration_shortcode' ) );
		add_shortcode( 'adam_renewal_form', array( $this, 'render_renewal_shortcode' ) );
		add_shortcode( 'adam_membership_form', array( $this, 'render_generic_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_filter( 'the_content', array( $this, 'inject_native_forms_into_configured_pages' ), 8 );
	}

	/**
	 * Register shared frontend assets.
	 */
	public function register_assets(): void {
		$style_path  = ADAM_MEMBERSHIP_PATH . 'assets/css/membership-forms.css';
		$script_path = ADAM_MEMBERSHIP_PATH . 'assets/js/membership-forms.js';

		wp_register_style(
			'adam-membership-forms',
			ADAM_MEMBERSHIP_URL . 'assets/css/membership-forms.css',
			array(),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : ADAM_MEMBERSHIP_VERSION
		);

		wp_register_script(
			'adam-membership-forms',
			ADAM_MEMBERSHIP_URL . 'assets/js/membership-forms.js',
			array(),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : ADAM_MEMBERSHIP_VERSION,
			true
		);

		if ( $this->should_enqueue_assets_for_request() ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Render generic membership form shortcode.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 */
	public function render_generic_shortcode( array $atts = array() ): string {
		$type = sanitize_key( (string) ( $atts['type'] ?? 'registration' ) );

		return 'renewal' === $type ? $this->render_renewal_shortcode() : $this->render_registration_shortcode();
	}

	/**
	 * Render native registration form.
	 */
	public function render_registration_shortcode(): string {
		if ( empty( $this->settings()['forms']['registration']['enabled'] ) ) {
			return $this->notice_markup( 'info', __( "O formulário de inscrição está temporariamente indisponível.", 'adam-membership' ) );
		}

		if ( is_user_logged_in() && $this->members->find( get_current_user_id() ) instanceof Member ) {
			return $this->notice_markup( 'info', __( "J\u{00E1} existe uma sess\u{00E3}o iniciada. Caso pretenda renovar ou gerir a sua conta, utilize a \u{00C1}rea do S\u{00F3}cio.", 'adam-membership' ) );
		}

		$state    = $this->handle_registration_submission();
		$settings = $this->settings();
		$values   = is_array( $state['values'] ?? null ) ? $state['values'] : array();

		ob_start();
		?>
		<section class="adam-public-form adam-card" data-adam-membership-form="registration">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( "Inscri\u{00E7}\u{00E3}o ADAM", 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( "Novo S\u{00F3}cio", 'adam-membership' ); ?></h3>
				</div>
			</div>

			<?php echo wp_kses_post( $this->render_submission_notice( 'registration', $state ) ); ?>
			<?php echo wp_kses_post( wpautop( esc_html( (string) $settings['legal']['registration_help'] ) ) ); ?>

			<form method="post" enctype="multipart/form-data" class="adam-membership-native-form">
				<input type="hidden" name="adam_membership_form_action" value="registration">
				<?php wp_nonce_field( 'adam_membership_registration_form' ); ?>

				<div class="adam-form-section">
					<h4><?php esc_html_e( "J\u{00E1} pertence a outra associa\u{00E7}\u{00E3}o de airsoft?", 'adam-membership' ); ?></h4>
					<div class="adam-choice-grid">
						<label class="adam-choice-card">
							<input type="radio" name="membership_mode" value="adam_primary" <?php checked( 'external_association' !== (string) ( $values['membership_mode'] ?? '' ) ); ?>>
							<span><?php echo esc_html( sprintf( __( "N\u{00E3}o, a ADAM ser\u{00E1} a minha associa\u{00E7}\u{00E3}o principal \u{2014} %s/ano", 'adam-membership' ), $this->format_fee( (string) $settings['fees']['primary'] ) ) ); ?></span>
						</label>
						<label class="adam-choice-card">
							<input type="radio" name="membership_mode" value="external_association" <?php checked( 'external_association', (string) ( $values['membership_mode'] ?? '' ) ); ?>>
							<span><?php echo esc_html( sprintf( __( "Sim, j\u{00E1} perten\u{00E7}o a outra associa\u{00E7}\u{00E3}o de airsoft \u{2014} %s/ano", 'adam-membership' ), $this->format_fee( (string) $settings['fees']['secondary'] ) ) ); ?></span>
						</label>
					</div>
				</div>

				<div class="adam-form-grid">
					<?php foreach ( $this->ordered_render_fields( 'registration', 'always' ) as $field_key => $config ) : ?>
						<?php $this->render_configured_field( 'registration', $field_key, $config, $values ); ?>
					<?php endforeach; ?>
				</div>

				<div class="adam-form-grid adam-conditional-group" data-adam-conditional="registration-external" <?php echo 'external_association' === (string) ( $values['membership_mode'] ?? '' ) ? '' : 'hidden'; ?>>
					<?php foreach ( $this->ordered_render_fields( 'registration', 'registration_external' ) as $field_key => $config ) : ?>
						<?php $this->render_configured_field( 'registration', $field_key, $config, $values ); ?>
					<?php endforeach; ?>
				</div>

				<?php $this->render_payment_panel( 'registration', $values ); ?>

				<div class="adam-form-grid">
					<?php foreach ( $this->ordered_post_payment_fields( 'registration' ) as $field_key => $config ) : ?>
						<?php $this->render_configured_field( 'registration', $field_key, $config, $values ); ?>
					<?php endforeach; ?>
				</div>

				<div class="adam-form-actions">
					<button type="submit" class="adam-card-link"><?php esc_html_e( "Submeter inscri\u{00E7}\u{00E3}o", 'adam-membership' ); ?></button>
				</div>
			</form>
		</section>
		<?php

		return (string) ob_get_clean();
	}
	/**
	 * Render native renewal form.
	 */
	public function render_renewal_shortcode(): string {
		if ( empty( $this->settings()['forms']['renewal']['enabled'] ) ) {
			return $this->notice_markup( 'info', __( "O formulário de renovação está temporariamente indisponível.", 'adam-membership' ) );
		}

		if ( ! is_user_logged_in() ) {
			return $this->notice_markup(
				'info',
				sprintf(
					/* translators: %s: login URL. */
					__( "Para renovar a quota, inicie sess\u{00E3}o na sua conta ADAM. <a href=\"%s\">Entrar</a>", 'adam-membership' ),
					esc_url( wp_login_url( $this->current_url() ) )
				)
			);
		}

		$member = $this->members->find( get_current_user_id() );

		if ( null === $member ) {
			return $this->notice_markup( 'error', __( "N\u{00E3}o foi poss\u{00ED}vel localizar a conta de s\u{00F3}cio associada a esta sess\u{00E3}o.", 'adam-membership' ) );
		}

		if ( $member->isPending() || $member->isRejected() || $member->isRenewalPending() ) {
			return $this->notice_markup( 'info', __( "A renova\u{00E7}\u{00E3}o n\u{00E3}o est\u{00E1} dispon\u{00ED}vel para o estado atual da conta.", 'adam-membership' ) );
		}

		$state    = $this->handle_renewal_submission( $member );
		$settings = $this->settings();
		$values   = is_array( $state['values'] ?? null ) ? $state['values'] : $this->default_renewal_values( $member );

		ob_start();
		?>
		<section class="adam-public-form adam-card" data-adam-membership-form="renewal">
			<div class="adam-card-heading">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( "Renova\u{00E7}\u{00E3}o ADAM", 'adam-membership' ); ?></p>
					<h3><?php esc_html_e( 'Renovar quota', 'adam-membership' ); ?></h3>
				</div>
			</div>

			<?php echo wp_kses_post( $this->render_submission_notice( 'renewal', $state ) ); ?>
			<?php echo wp_kses_post( wpautop( esc_html( (string) $settings['legal']['renewal_help'] ) ) ); ?>

			<form method="post" enctype="multipart/form-data" class="adam-membership-native-form">
				<input type="hidden" name="adam_membership_form_action" value="renewal">
				<?php wp_nonce_field( 'adam_membership_renewal_form' ); ?>

				<div class="adam-form-summary">
					<div>
						<span><?php esc_html_e( "S\u{00F3}cio", 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( $member->full_name() ); ?></strong>
					</div>
					<div>
						<span><?php esc_html_e( "N.\u{00BA} de s\u{00F3}cio", 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( (string) $member->field( 'numero_socio' ) ); ?></strong>
					</div>
					<div>
						<span><?php esc_html_e( 'Validade atual', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( $this->format_date( (string) $member->field( 'validade_quota' ) ) ); ?></strong>
					</div>
				</div>

				<div class="adam-form-section">
					<h4><?php esc_html_e( 'Como pretende renovar este ano?', 'adam-membership' ); ?></h4>
					<div class="adam-choice-grid">
						<label class="adam-choice-card">
							<input type="radio" name="renewal_mode" value="adam_primary" <?php checked( 'adam_primary', (string) ( $values['renewal_mode'] ?? 'adam_primary' ) ); ?>>
							<span><?php echo esc_html( sprintf( __( "A ADAM ser\u{00E1} a minha associa\u{00E7}\u{00E3}o principal \u{2014} %s/ano", 'adam-membership' ), $this->format_fee( (string) $settings['fees']['primary'] ) ) ); ?></span>
						</label>
						<label class="adam-choice-card">
							<input type="radio" name="renewal_mode" value="external_association" <?php checked( 'external_association', (string) ( $values['renewal_mode'] ?? '' ) ); ?>>
							<span><?php echo esc_html( sprintf( __( "Continuo associado atrav\u{00E9}s de outra associa\u{00E7}\u{00E3}o de airsoft \u{2014} %s/ano", 'adam-membership' ), $this->format_fee( (string) $settings['fees']['secondary'] ) ) ); ?></span>
						</label>
					</div>
				</div>

				<div class="adam-form-section">
					<h4><?php esc_html_e( "Os seus dados pessoais sofreram altera\u{00E7}\u{00F5}es desde a \u{00FA}ltima renova\u{00E7}\u{00E3}o?", 'adam-membership' ); ?></h4>
					<div class="adam-inline-choice">
						<label><input type="radio" name="profile_changed" value="0" <?php checked( '1', (string) ( $values['profile_changed'] ?? '' ), false ); ?> <?php checked( '0', (string) ( $values['profile_changed'] ?? '0' ) ); ?>> <?php esc_html_e( "N\u{00E3}o", 'adam-membership' ); ?></label>
						<label><input type="radio" name="profile_changed" value="1" <?php checked( '1', (string) ( $values['profile_changed'] ?? '' ) ); ?>> <?php esc_html_e( 'Sim', 'adam-membership' ); ?></label>
					</div>
				</div>

				<div class="adam-form-grid">
					<?php foreach ( $this->ordered_render_fields( 'renewal', 'always' ) as $field_key => $config ) : ?>
						<?php $this->render_configured_field( 'renewal', $field_key, $config, $values ); ?>
					<?php endforeach; ?>
				</div>

				<div class="adam-form-grid adam-conditional-group" data-adam-conditional="renewal-profile" <?php echo '1' === (string) ( $values['profile_changed'] ?? '0' ) ? '' : 'hidden'; ?>>
					<?php foreach ( $this->ordered_render_fields( 'renewal', 'renewal_profile' ) as $field_key => $config ) : ?>
						<?php $this->render_configured_field( 'renewal', $field_key, $config, $values ); ?>
					<?php endforeach; ?>
				</div>

				<div class="adam-form-grid adam-conditional-group" data-adam-conditional="renewal-external" <?php echo 'external_association' === (string) ( $values['renewal_mode'] ?? '' ) ? '' : 'hidden'; ?>>
					<?php foreach ( $this->ordered_render_fields( 'renewal', 'renewal_external' ) as $field_key => $config ) : ?>
						<?php $this->render_configured_field( 'renewal', $field_key, $config, $values ); ?>
					<?php endforeach; ?>
				</div>

				<?php $this->render_payment_panel( 'renewal', $values ); ?>

				<div class="adam-form-grid">
					<?php foreach ( $this->ordered_post_payment_fields( 'renewal' ) as $field_key => $config ) : ?>
						<?php $this->render_configured_field( 'renewal', $field_key, $config, $values ); ?>
					<?php endforeach; ?>
				</div>

				<div class="adam-form-actions">
					<button type="submit" class="adam-card-link"><?php esc_html_e( "Submeter renova\u{00E7}\u{00E3}o", 'adam-membership' ); ?></button>
				</div>
			</form>
		</section>
		<?php

		return (string) ob_get_clean();
	}
	/**
	 * Handle registration submission.
	 *
	 * @return array<string, mixed>
	 */
	private function handle_registration_submission(): array {
		$values = $this->posted_values();

		if ( 'registration' !== (string) ( $values['adam_membership_form_action'] ?? '' ) ) {
			return array( 'values' => array( 'membership_mode' => 'adam_primary' ) );
		}

		$errors = array();

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'adam_membership_registration_form' ) ) {
			$errors[] = __( "N\u{00E3}o foi poss\u{00ED}vel validar a submiss\u{00E3}o da inscri\u{00E7}\u{00E3}o.", 'adam-membership' );
		}

		$mode     = 'external_association' === (string) ( $values['membership_mode'] ?? '' ) ? 'external_association' : 'adam_primary';
		$settings = $this->settings();

		$this->validate_text_field( 'registration', 'full_name', $values, $errors );
		$this->validate_email_field( 'registration', 'email', $values, $errors );
		$this->validate_text_field( 'registration', 'citizen_card', $values, $errors );
		$this->validate_text_field( 'registration', 'nif', $values, $errors );
		$this->validate_date_field( 'registration', 'birth_date', $values, $errors );
		$this->validate_text_field( 'registration', 'phone', $values, $errors );
		$this->validate_text_field( 'registration', 'address_line_1', $values, $errors );
		$this->validate_text_field( 'registration', 'address_line_2', $values, $errors );
		$this->validate_text_field( 'registration', 'city', $values, $errors );
		$this->validate_text_field( 'registration', 'municipality', $values, $errors );
		$this->validate_text_field( 'registration', 'postcode', $values, $errors );
		$this->validate_text_field( 'registration', 'country', $values, $errors );
		$this->validate_text_field( 'registration', 'team', $values, $errors );
		$this->validate_privacy( 'registration', $values, $errors );

		if ( 'external_association' === $mode ) {
			$this->validate_text_field( 'registration', 'external_association_name', $values, $errors, true );
			$this->validate_text_field( 'registration', 'external_member_number', $values, $errors, true );
		}

		$this->validate_custom_fields( 'registration', $values, $errors, $mode, false );

		$profile_photo = $this->process_upload( 'registration', 'profile_photo', $errors, array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
		$receipt       = $this->process_upload( 'registration', 'payment_receipt', $errors, array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' ) );
		$association_proof = 'external_association' === $mode ? $this->process_upload( 'registration', 'external_association_proof', $errors, array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' ), true ) : '';
		$custom_payload = $this->custom_submission_payload( 'registration', $values, $errors, $mode, false );

		if ( array() !== $errors ) {
			return array(
				'values' => $values,
				'errors' => $errors,
			);
		}

		$result = $this->registration->register(
			array(
				'full_name'                  => (string) ( $values['full_name'] ?? '' ),
				'email'                      => (string) ( $values['email'] ?? '' ),
				'citizen_card'               => (string) ( $values['citizen_card'] ?? '' ),
				'nif'                        => (string) ( $values['nif'] ?? '' ),
				'birth_date'                 => (string) ( $values['birth_date'] ?? '' ),
				'phone'                      => (string) ( $values['phone'] ?? '' ),
				'address_line_1'             => (string) ( $values['address_line_1'] ?? '' ),
				'address_line_2'             => (string) ( $values['address_line_2'] ?? '' ),
				'city'                       => (string) ( $values['city'] ?? '' ),
				'municipality'               => (string) ( $values['municipality'] ?? '' ),
				'postcode'                   => (string) ( $values['postcode'] ?? '' ),
				'country'                    => (string) ( $values['country'] ?? '' ),
				'team'                       => (string) ( $values['team'] ?? '' ),
				'membership_mode'            => $mode,
				'membership_fee'             => 'external_association' === $mode ? (string) $settings['fees']['secondary'] : (string) $settings['fees']['primary'],
				'external_association_name'  => (string) ( $values['external_association_name'] ?? '' ),
				'external_member_number'     => (string) ( $values['external_member_number'] ?? '' ),
				'external_association_proof' => $association_proof,
				'profile_photo'              => $profile_photo,
				'payment_receipt'            => $receipt,
				'custom_fields'              => $custom_payload,
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'values' => $values,
				'errors' => array( $result->get_error_message() ),
			);
		}

		$this->redirect_after_success( 'registration' );
		return array( 'values' => $values );
	}

	/**
	 * Handle renewal submission.
	 *
	 * @param Member $member Member.
	 * @return array<string, mixed>
	 */
	private function handle_renewal_submission( Member $member ): array {
		$values = $this->posted_values();

		if ( 'renewal' !== (string) ( $values['adam_membership_form_action'] ?? '' ) ) {
			return array( 'values' => $this->default_renewal_values( $member ) );
		}

		$errors = array();

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'adam_membership_renewal_form' ) ) {
			$errors[] = __( "N\u{00E3}o foi poss\u{00ED}vel validar a submiss\u{00E3}o da renova\u{00E7}\u{00E3}o.", 'adam-membership' );
		}

		$renewal_mode    = 'external_association' === (string) ( $values['renewal_mode'] ?? '' ) ? 'external_association' : 'adam_primary';
		$profile_changed = '1' === (string) ( $values['profile_changed'] ?? '0' );
		$settings        = $this->settings();

		if ( $profile_changed ) {
			$this->validate_text_field( 'renewal', 'phone', $values, $errors );
			$this->validate_text_field( 'renewal', 'address_line_1', $values, $errors );
			$this->validate_text_field( 'renewal', 'address_line_2', $values, $errors );
			$this->validate_text_field( 'renewal', 'city', $values, $errors );
			$this->validate_text_field( 'renewal', 'municipality', $values, $errors );
			$this->validate_text_field( 'renewal', 'postcode', $values, $errors );
			$this->validate_text_field( 'renewal', 'country', $values, $errors );
		}

		if ( 'external_association' === $renewal_mode ) {
			$this->validate_text_field( 'renewal', 'external_association_name', $values, $errors, true );
			$this->validate_text_field( 'renewal', 'external_member_number', $values, $errors, true );
		}

		$this->validate_privacy( 'renewal', $values, $errors );
		$this->validate_custom_fields( 'renewal', $values, $errors, $renewal_mode, $profile_changed );

		$receipt           = $this->process_upload( 'renewal', 'payment_receipt', $errors, array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' ) );
		$association_proof = 'external_association' === $renewal_mode ? $this->process_upload( 'renewal', 'external_association_proof', $errors, array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' ), true ) : '';
		$custom_payload    = $this->custom_submission_payload( 'renewal', $values, $errors, $renewal_mode, $profile_changed );

		if ( array() !== $errors ) {
			return array(
				'values' => $values,
				'errors' => $errors,
			);
		}

		$submitted_data = array(
			'adam_membership_origin'         => $renewal_mode,
			'adam_membership_fee'            => 'external_association' === $renewal_mode ? (string) $settings['fees']['secondary'] : (string) $settings['fees']['primary'],
			'adam_external_association_name' => 'external_association' === $renewal_mode ? (string) ( $values['external_association_name'] ?? '' ) : '',
			'adam_external_member_number'    => 'external_association' === $renewal_mode ? (string) ( $values['external_member_number'] ?? '' ) : '',
			'adam_external_association_proof' => 'external_association' === $renewal_mode ? $association_proof : '',
		);

		if ( $profile_changed ) {
			$submitted_data['telefone']      = (string) ( $values['phone'] ?? '' );
			$submitted_data['morada']        = (string) ( $values['address_line_1'] ?? '' );
			$submitted_data['morada_linha_2'] = (string) ( $values['address_line_2'] ?? '' );
			$submitted_data['cidade']        = (string) ( $values['city'] ?? '' );
			$submitted_data['municipio']     = (string) ( $values['municipality'] ?? '' );
			$submitted_data['codigo_postal'] = (string) ( $values['postcode'] ?? '' );
			$submitted_data['pais']          = (string) ( $values['country'] ?? '' );
		}

		$submitted_data = array_merge( $submitted_data, $custom_payload );

		$result = $this->renewals->submit( $member, $submitted_data, $receipt, 0 );

		if ( is_wp_error( $result ) ) {
			return array(
				'values' => $values,
				'errors' => array( $result->get_error_message() ),
			);
		}

		$this->redirect_after_success( 'renewal' );
		return array( 'values' => $values );
	}

	/**
	 * Enqueue frontend assets.
	 */
	private function enqueue_assets(): void {
		wp_enqueue_style( 'adam-membership-forms' );
		wp_enqueue_script( 'adam-membership-forms' );

		wp_add_inline_script(
			'adam-membership-forms',
			'window.adamMembershipFormsConfig = ' . wp_json_encode(
				array(
					'primaryFee'   => $this->format_fee( (string) $this->settings()['fees']['primary'] ),
					'secondaryFee' => $this->format_fee( (string) $this->settings()['fees']['secondary'] ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Decide whether the current request should load form assets.
	 */
	private function should_enqueue_assets_for_request(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$permalink = get_permalink( $post );
		$content   = (string) $post->post_content;

		if ( is_string( $permalink ) && ( $this->same_url( $permalink, $this->settings->registration_page_url() ) || $this->same_url( $permalink, $this->settings->renewal_page_url() ) ) ) {
			return true;
		}

		return str_contains( $content, '[adam_registration_form' )
			|| str_contains( $content, '[adam_renewal_form' )
			|| str_contains( $content, '[adam_membership_form' );
	}

	/**
	 * Get merged form settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		if ( null === $this->form_settings ) {
			$this->form_settings = $this->settings->membership_form_settings();
		}

		return $this->form_settings;
	}

	/**
	 * Render one text field when enabled.
	 *
	 * @param string               $form Form type.
	 * @param string               $field Field key.
	 * @param array<string, mixed> $values Posted/default values.
	 * @param string               $type Input type.
	 * @param string               $extra_class Optional extra class.
	 */
	private function render_text_field( string $form, string $field, array $values, string $type = 'text', string $extra_class = '' ): void {
		$config = $this->field_config( $form, $field );

		if ( ! $config['enabled'] ) {
			return;
		}
		?>
		<label class="adam-form-field <?php echo esc_attr( $extra_class ); ?>">
			<span><?php echo esc_html( $config['label'] . ( $config['required'] ? ' *' : '' ) ); ?></span>
			<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) ( $values[ $field ] ?? '' ) ); ?>">
			<?php if ( '' !== $config['help'] ) : ?>
				<small><?php echo esc_html( $config['help'] ); ?></small>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * Render one upload field when enabled.
	 *
	 * @param string $form Form type.
	 * @param string $field Field key.
	 * @param string $accept Accept attribute.
	 * @param string $extra_class Optional extra class.
	 */
	private function render_upload_field( string $form, string $field, string $accept = '', string $extra_class = '' ): void {
		$config = $this->field_config( $form, $field );

		if ( ! $config['enabled'] ) {
			return;
		}
		?>
		<label class="adam-form-field <?php echo esc_attr( $extra_class ); ?>">
			<span><?php echo esc_html( $config['label'] . ( $config['required'] ? ' *' : '' ) ); ?></span>
			<input type="file" name="<?php echo esc_attr( $field ); ?>" <?php echo '' !== $accept ? 'accept="' . esc_attr( $accept ) . '"' : ''; ?>>
			<?php if ( '' !== $config['help'] ) : ?>
				<small><?php echo esc_html( $config['help'] ); ?></small>
			<?php endif; ?>
		</label>
		<?php
	}

	/**
	 * Render privacy acceptance field.
	 *
	 * @param string               $form Form type.
	 * @param array<string, mixed> $values Form values.
	 */
	private function render_privacy_field( string $form, array $values ): void {
		$config = $this->field_config( $form, 'privacy_acceptance' );

		if ( ! $config['enabled'] ) {
			return;
		}

		$text = 'renewal' === $form ? (string) $this->settings()['legal']['renewal_privacy_text'] : (string) $this->settings()['legal']['registration_privacy_text'];
		?>
		<div class="adam-form-field adam-field--full adam-checkbox-field">
			<label class="adam-checkbox-control">
				<input type="checkbox" name="privacy_acceptance" value="1" <?php checked( '1', (string) ( $values['privacy_acceptance'] ?? '' ) ); ?>>
				<span class="adam-checkbox-label"><?php echo esc_html( '' !== $text ? $text : $config['label'] ); ?></span>
			</label>
			<?php if ( '' !== $config['help'] ) : ?>
				<small><?php echo esc_html( $config['help'] ); ?></small>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render payment instructions and fee panel.
	 *
	 * @param string               $form Form type.
	 * @param array<string, mixed> $values Current values.
	 * @param bool                 $allow_external_switch Whether renewal can use the external flow.
	 */
	private function render_payment_panel( string $form, array $values, bool $allow_external_switch = true ): void {
		$settings = $this->settings();
		$mode     = 'registration' === $form
			? ( 'external_association' === (string) ( $values['membership_mode'] ?? '' ) ? 'external_association' : 'adam_primary' )
			: ( $allow_external_switch && 'external_association' === (string) ( $values['renewal_mode'] ?? '' ) ? 'external_association' : 'adam_primary' );
		?>
		<section class="adam-payment-panel" data-adam-payment-panel>
			<div class="adam-payment-panel__header">
				<div>
					<p class="adam-eyebrow"><?php esc_html_e( 'Pagamento da Quota', 'adam-membership' ); ?></p>
					<h4><?php esc_html_e( "Instru\u{00E7}\u{00F5}es de pagamento", 'adam-membership' ); ?></h4>
				</div>
				<div class="adam-payment-panel__fee">
					<span><?php esc_html_e( 'Valor da Quota', 'adam-membership' ); ?></span>
					<strong data-adam-fee-value><?php echo esc_html( $this->format_fee( 'external_association' === $mode ? (string) $settings['fees']['secondary'] : (string) $settings['fees']['primary'] ) ); ?></strong>
				</div>
			</div>
			<div class="adam-payment-panel__grid">
				<?php if ( '' !== trim( (string) $settings['payment']['mbway'] ) ) : ?>
					<div>
						<span><?php esc_html_e( 'MB Way', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( (string) $settings['payment']['mbway'] ); ?></strong>
					</div>
				<?php endif; ?>
				<?php if ( '' !== trim( (string) $settings['payment']['iban'] ) ) : ?>
					<div>
						<span><?php esc_html_e( 'IBAN', 'adam-membership' ); ?></span>
						<strong><?php echo esc_html( (string) $settings['payment']['iban'] ); ?></strong>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( '' !== trim( (string) $settings['payment']['instructions'] ) ) : ?>
				<p class="adam-payment-panel__notes"><?php echo esc_html( (string) $settings['payment']['instructions'] ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Validate text field by configuration.
	 *
	 * @param string               $form Form type.
	 * @param string               $field Field key.
	 * @param array<string, mixed> $values Posted values.
	 * @param array<int, string>   $errors Error list.
	 * @param bool                 $force_required Force required.
	 */
	private function validate_text_field( string $form, string $field, array $values, array &$errors, bool $force_required = false ): void {
		$config = $this->field_config( $form, $field );

		if ( ! $config['enabled'] ) {
			return;
		}

		if ( ! $force_required && ! $config['required'] ) {
			return;
		}

		if ( '' === trim( (string) ( $values[ $field ] ?? '' ) ) ) {
			$errors[] = sprintf( __( "O campo \"%s\" \u{00E9} obrigat\u{00F3}rio.", 'adam-membership' ), $config['label'] );
		}
	}

	/**
	 * Validate email field.
	 *
	 * @param string               $form Form type.
	 * @param string               $field Field key.
	 * @param array<string, mixed> $values Values.
	 * @param array<int, string>   $errors Errors.
	 */
	private function validate_email_field( string $form, string $field, array $values, array &$errors ): void {
		$this->validate_text_field( $form, $field, $values, $errors );

		$config = $this->field_config( $form, $field );

		if ( ! $config['enabled'] ) {
			return;
		}

		$email = sanitize_email( (string) ( $values[ $field ] ?? '' ) );

		if ( '' !== $email && ! is_email( $email ) ) {
			$errors[] = __( "Introduza um endere\u{00E7}o de email v\u{00E1}lido.", 'adam-membership' );
		}
	}

	/**
	 * Validate date field.
	 *
	 * @param string               $form Form type.
	 * @param string               $field Field key.
	 * @param array<string, mixed> $values Values.
	 * @param array<int, string>   $errors Errors.
	 */
	private function validate_date_field( string $form, string $field, array $values, array &$errors ): void {
		$this->validate_text_field( $form, $field, $values, $errors );

		$config = $this->field_config( $form, $field );

		if ( ! $config['enabled'] ) {
			return;
		}

		$value = (string) ( $values[ $field ] ?? '' );

		if ( '' !== $value && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) || false === strtotime( $value ) ) ) {
			$errors[] = sprintf( __( "O campo \"%s\" tem um formato de data inv\u{00E1}lido.", 'adam-membership' ), $config['label'] );
		}
	}

	/**
	 * Validate privacy checkbox.
	 *
	 * @param string               $form Form type.
	 * @param array<string, mixed> $values Values.
	 * @param array<int, string>   $errors Errors.
	 */
	private function validate_privacy( string $form, array $values, array &$errors ): void {
		$config = $this->field_config( $form, 'privacy_acceptance' );

		if ( $config['enabled'] && $config['required'] && '1' !== (string) ( $values['privacy_acceptance'] ?? '' ) ) {
			$errors[] = __( "\u{00C9} necess\u{00E1}rio aceitar a pol\u{00ED}tica de privacidade para continuar.", 'adam-membership' );
		}
	}

	/**
	 * Process a frontend upload field.
	 *
	 * @param string             $form Form type.
	 * @param string             $field Field key.
	 * @param array<int, string> $errors Errors.
	 * @param array<string, string> $mimes Allowed mimes.
	 * @param bool               $force_required Force requirement.
	 * @return mixed
	 */
	private function process_upload( string $form, string $field, array &$errors, array $mimes, bool $force_required = false ): mixed {
		$config = $this->field_config( $form, $field );

		if ( ! $config['enabled'] ) {
			return '';
		}

		$required = $force_required || $config['required'];
		$file     = $_FILES[ $field ] ?? null;

		if ( ! is_array( $file ) || UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			if ( $required ) {
				$errors[] = sprintf( __( "O ficheiro \"%s\" \u{00E9} obrigat\u{00F3}rio.", 'adam-membership' ), $config['label'] );
			}

			return '';
		}

		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_OK ) ) {
			$errors[] = sprintf( __( "N\u{00E3}o foi poss\u{00ED}vel carregar o ficheiro \"%s\".", 'adam-membership' ), $config['label'] );
			return '';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload(
			$field,
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => $mimes,
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			$errors[] = $attachment_id->get_error_message();
			return '';
		}

		return $attachment_id;
	}

	/**
	 * Build default renewal values from the current member.
	 *
	 * @param Member $member Member.
	 * @return array<string, string>
	 */
	private function default_renewal_values( Member $member ): array {
		$values = array(
			'phone'                     => (string) $member->field( 'telefone' ),
			'address_line_1'            => (string) $member->field( 'morada' ),
			'address_line_2'            => (string) $member->field( 'morada_linha_2' ),
			'city'                      => (string) $member->field( 'cidade' ),
			'municipality'              => (string) $member->field( 'municipio' ),
			'postcode'                  => (string) $member->field( 'codigo_postal' ),
			'country'                   => (string) $member->field( 'pais' ),
			'external_association_name' => (string) $member->field( 'adam_external_association_name' ),
			'external_member_number'    => (string) $member->field( 'adam_external_member_number' ),
			'renewal_mode'              => $this->member_uses_external_association( $member ) ? 'external_association' : 'adam_primary',
			'profile_changed'           => '0',
		);

		foreach ( array_keys( $this->custom_field_configs( 'renewal' ) ) as $field_key ) {
			$values[ $field_key ] = (string) $member->field( $this->custom_member_meta_key( $field_key ) );
		}

		return $values;
	}

	/**
	 * Determine whether the member currently renews through another association.
	 *
	 * Supports both the current source-of-truth field and older records that
	 * still only have external association metadata populated.
	 *
	 * @param Member $member Member record.
	 */
	private function member_uses_external_association( Member $member ): bool {
		if ( 'external_association' === (string) $member->field( 'adam_membership_origin' ) ) {
			return true;
		}

		return '' !== trim( (string) $member->field( 'adam_external_association_name' ) )
			|| '' !== trim( (string) $member->field( 'adam_external_member_number' ) )
			|| '' !== trim( (string) $member->field( 'adam_external_association_proof' ) );
	}

	/**
	 * Get field config for form/field pair.
	 *
	 * @param string $form Form type.
	 * @param string $field Field key.
	 * @return array<string, mixed>
	 */
	private function field_config( string $form, string $field ): array {
		$key      = 'renewal' === $form ? 'renewal_fields' : 'registration_fields';
		$settings = $this->settings();
		$config   = $settings[ $key ][ $field ] ?? array();

		return array(
			'label'    => is_string( $config['label'] ?? null ) ? $config['label'] : $field,
			'help'     => is_string( $config['help'] ?? null ) ? $config['help'] : '',
			'enabled'  => ! empty( $config['enabled'] ),
			'required' => ! empty( $config['required'] ),
			'type'     => is_string( $config['type'] ?? null ) ? $config['type'] : 'text',
			'options'  => is_string( $config['options'] ?? null ) ? $config['options'] : '',
			'conditional' => is_string( $config['conditional'] ?? null ) ? $config['conditional'] : 'always',
			'order'    => absint( $config['order'] ?? 999 ),
			'locked'   => ! empty( $config['locked'] ),
		);
	}

	/**
	 * Get ordered renderable fields for a condition bucket.
	 *
	 * @param string $form      Form key.
	 * @param string $condition Condition key.
	 * @return array<string, array<string, mixed>>
	 */
	private function ordered_render_fields( string $form, string $condition ): array {
		$key      = 'renewal' === $form ? 'renewal_fields' : 'registration_fields';
		$settings = (array) $this->settings()[ $key ];
		$fields   = array();

		foreach ( $settings as $field_key => $config ) {
			if ( ! is_string( $field_key ) || ! is_array( $config ) ) {
				continue;
			}

			if ( in_array( $field_key, array( 'payment_receipt', 'privacy_acceptance' ), true ) ) {
				continue;
			}

			if ( $condition !== (string) ( $config['conditional'] ?? 'always' ) ) {
				continue;
			}

			$fields[ $field_key ] = $this->field_config( $form, $field_key );
		}

		uasort( $fields, static fn ( array $left, array $right ): int => (int) $left['order'] <=> (int) $right['order'] );

		return $fields;
	}

	/**
	 * Get ordered post-payment fields.
	 *
	 * @param string $form Form key.
	 * @return array<string, array<string, mixed>>
	 */
	private function ordered_post_payment_fields( string $form ): array {
		$fields = array(
			'payment_receipt'    => $this->field_config( $form, 'payment_receipt' ),
			'privacy_acceptance' => $this->field_config( $form, 'privacy_acceptance' ),
		);

		uasort( $fields, static fn ( array $left, array $right ): int => (int) $left['order'] <=> (int) $right['order'] );

		return $fields;
	}

	/**
	 * Render one configured field.
	 *
	 * @param string               $form   Form key.
	 * @param string               $field  Field key.
	 * @param array<string, mixed> $config Field config.
	 * @param array<string, mixed> $values Form values.
	 */
	private function render_configured_field( string $form, string $field, array $config, array $values ): void {
		if ( ! $config['enabled'] ) {
			return;
		}

		if ( 'privacy_acceptance' === $field ) {
			$this->render_privacy_field( $form, $values );
			return;
		}

		$type        = (string) ( $config['type'] ?? 'text' );
		$field_class = $this->field_layout_class( $type );
		$label       = (string) $config['label'] . ( ! empty( $config['required'] ) ? ' *' : '' );
		$value       = (string) ( $values[ $field ] ?? '' );

		if ( 'file' === $type ) {
			$this->render_upload_field( $form, $field, $this->field_accept_attribute( $field ), $field_class );
			return;
		}

		if ( 'checkbox' === $type ) {
			?>
			<div class="adam-form-field <?php echo esc_attr( trim( 'adam-checkbox-field ' . $field_class ) ); ?>">
				<label class="adam-checkbox-control">
					<input type="checkbox" name="<?php echo esc_attr( $field ); ?>" value="1" <?php checked( '1', $value ); ?>>
					<span class="adam-checkbox-label"><?php echo esc_html( $label ); ?></span>
				</label>
				<?php if ( '' !== (string) $config['help'] ) : ?>
					<small><?php echo esc_html( (string) $config['help'] ); ?></small>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		if ( 'textarea' === $type ) {
			?>
			<label class="adam-form-field <?php echo esc_attr( $field_class ); ?>">
				<span><?php echo esc_html( $label ); ?></span>
				<textarea name="<?php echo esc_attr( $field ); ?>" rows="4"><?php echo esc_textarea( $value ); ?></textarea>
				<?php if ( '' !== (string) $config['help'] ) : ?>
					<small><?php echo esc_html( (string) $config['help'] ); ?></small>
				<?php endif; ?>
			</label>
			<?php
			return;
		}

		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			$options = $this->parse_field_options( (string) $config['options'] );
			?>
			<label class="adam-form-field <?php echo esc_attr( $field_class ); ?>">
				<span><?php echo esc_html( $label ); ?></span>
				<?php if ( 'select' === $type ) : ?>
					<select name="<?php echo esc_attr( $field ); ?>">
						<option value=""><?php esc_html_e( 'Selecionar', 'adam-membership' ); ?></option>
						<?php foreach ( $options as $option_value => $option_label ) : ?>
							<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<div class="adam-inline-choice">
						<?php foreach ( $options as $option_value => $option_label ) : ?>
							<label><input type="radio" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $option_value ); ?>" <?php checked( $value, $option_value ); ?>> <?php echo esc_html( $option_label ); ?></label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php if ( '' !== (string) $config['help'] ) : ?>
					<small><?php echo esc_html( (string) $config['help'] ); ?></small>
				<?php endif; ?>
			</label>
			<?php
			return;
		}

		$input_type = match ( $type ) {
			'email' => 'email',
			'phone' => 'tel',
			'number' => 'number',
			'date' => 'date',
			default => 'text',
		};

		$this->render_text_field( $form, $field, $values, $input_type, $field_class );
	}

	/**
	 * Parse configured select/radio options.
	 *
	 * @param string $options Raw options.
	 * @return array<string, string>
	 */
	private function parse_field_options( string $options ): array {
		$lines  = preg_split( '/\r\n|\r|\n/', $options ) ?: array();
		$parsed = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			if ( str_contains( $line, '|' ) ) {
				$parts = array_map( 'trim', explode( '|', $line, 2 ) );
				$value = sanitize_text_field( (string) $parts[0] );
				$label = sanitize_text_field( (string) $parts[1] );
			} else {
				$value = sanitize_text_field( $line );
				$label = $value;
			}

			if ( '' !== $value ) {
				$parsed[ $value ] = '' !== $label ? $label : $value;
			}
		}

		return $parsed;
	}

	/**
	 * Get the layout class for a field type.
	 *
	 * @param string $type Field type.
	 */
	private function field_layout_class( string $type ): string {
		return in_array( $type, array( 'file', 'textarea', 'checkbox' ), true ) ? 'adam-field--full' : '';
	}

	/**
	 * Get the accept attribute for a file field.
	 *
	 * @param string $field Field key.
	 */
	private function field_accept_attribute( string $field ): string {
		return 'profile_photo' === $field ? 'image/*' : '.pdf,image/*';
	}

	/**
	 * Get custom field configs for a form.
	 *
	 * @param string $form Form key.
	 * @return array<string, array<string, mixed>>
	 */
	private function custom_field_configs( string $form ): array {
		$key      = 'renewal' === $form ? 'renewal_fields' : 'registration_fields';
		$settings = (array) $this->settings()[ $key ];
		$custom   = array();

		foreach ( $settings as $field_key => $config ) {
			if ( ! is_string( $field_key ) || ! is_array( $config ) ) {
				continue;
			}

			if ( ! empty( $config['locked'] ) ) {
				continue;
			}

			$custom[ $field_key ] = $this->field_config( $form, $field_key );
		}

		return $custom;
	}

	/**
	 * Validate custom fields that are active for the current path.
	 *
	 * @param string               $form            Form key.
	 * @param array<string, mixed> $values          Posted values.
	 * @param array<int, string>   $errors          Error list.
	 * @param string               $associationMode Membership mode.
	 * @param bool                 $profileChanged  Profile update toggle.
	 */
	private function validate_custom_fields( string $form, array $values, array &$errors, string $associationMode, bool $profileChanged ): void {
		foreach ( $this->custom_field_configs( $form ) as $field_key => $config ) {
			if ( ! $this->is_field_condition_active( (string) $config['conditional'], $associationMode, $profileChanged ) || ! $config['enabled'] ) {
				continue;
			}

			$type  = (string) $config['type'];
			$value = (string) ( $values[ $field_key ] ?? '' );

			if ( 'file' === $type ) {
				continue;
			}

			if ( ! empty( $config['required'] ) && '' === trim( $value ) && ! ( 'checkbox' === $type && '1' === $value ) ) {
				$errors[] = sprintf( __( 'O campo "%s" é obrigatório.', 'adam-membership' ), (string) $config['label'] );
				continue;
			}

			if ( 'email' === $type && '' !== $value && ! is_email( $value ) ) {
				$errors[] = __( 'Introduza um endereço de email válido.', 'adam-membership' );
			}

			if ( 'date' === $type && '' !== $value && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) || false === strtotime( $value ) ) ) {
				$errors[] = sprintf( __( 'O campo "%s" tem um formato de data inválido.', 'adam-membership' ), (string) $config['label'] );
			}
		}
	}

	/**
	 * Build the submitted custom payload for the current path.
	 *
	 * @param string               $form            Form key.
	 * @param array<string, mixed> $values          Posted values.
	 * @param array<int, string>   $errors          Error list.
	 * @param string               $associationMode Membership mode.
	 * @param bool                 $profileChanged  Profile update toggle.
	 * @return array<string, mixed>
	 */
	private function custom_submission_payload( string $form, array $values, array &$errors, string $associationMode, bool $profileChanged ): array {
		$payload = array();

		foreach ( $this->custom_field_configs( $form ) as $field_key => $config ) {
			if ( ! $this->is_field_condition_active( (string) $config['conditional'], $associationMode, $profileChanged ) || ! $config['enabled'] ) {
				continue;
			}

			if ( 'file' === (string) $config['type'] ) {
				$payload[ $this->custom_member_meta_key( $field_key ) ] = $this->process_upload(
					$form,
					$field_key,
					$errors,
					array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf' ),
					! empty( $config['required'] )
				);
				continue;
			}

			$payload[ $this->custom_member_meta_key( $field_key ) ] = 'checkbox' === (string) $config['type']
				? ( '1' === (string) ( $values[ $field_key ] ?? '' ) ? '1' : '' )
				: (string) ( $values[ $field_key ] ?? '' );
		}

		return $payload;
	}

	/**
	 * Check whether a conditional field should be active for the current path.
	 *
	 * @param string $condition       Condition key.
	 * @param string $associationMode Membership mode.
	 * @param bool   $profileChanged  Profile update toggle.
	 */
	private function is_field_condition_active( string $condition, string $associationMode, bool $profileChanged ): bool {
		return match ( $condition ) {
			'registration_external', 'renewal_external' => 'external_association' === $associationMode,
			'renewal_profile'                            => $profileChanged,
			default                                      => true,
		};
	}

	/**
	 * Build the member meta key for a custom field.
	 *
	 * @param string $field_key Field key.
	 */
	private function custom_member_meta_key( string $field_key ): string {
		return 'adam_custom_' . sanitize_key( $field_key );
	}

	/**
	 * Read current posted values.
	 *
	 * @return array<string, string>
	 */
	private function posted_values(): array {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return array();
		}

		$values = array();

		foreach ( $_POST as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$values[ $key ] = is_array( $value )
				? sanitize_text_field( implode( ' ', array_filter( array_map( 'strval', wp_unslash( $value ) ) ) ) )
				: sanitize_text_field( wp_unslash( $value ) );
		}

		return $values;
	}

	/**
	 * Render submission notice.
	 *
	 * @param string               $form Form type.
	 * @param array<string, mixed> $state Current state.
	 */
	private function render_submission_notice( string $form, array $state ): string {
		$success_key = isset( $_GET['adam_form_success'] ) ? sanitize_key( wp_unslash( $_GET['adam_form_success'] ) ) : '';

		if ( $success_key === $form ) {
			return $this->notice_markup(
				'success',
				'renewal' === $form
					? __( "O pedido de renova\u{00E7}\u{00E3}o foi submetido com sucesso e est\u{00E1} agora em an\u{00E1}lise.", 'adam-membership' )
					: __( "A inscri\u{00E7}\u{00E3}o foi submetida com sucesso. A conta ficou pendente de aprova\u{00E7}\u{00E3}o pela ADAM.", 'adam-membership' )
			);
		}

		$errors = is_array( $state['errors'] ?? null ) ? $state['errors'] : array();

		if ( array() === $errors ) {
			return '';
		}

		$list = '<ul>';

		foreach ( $errors as $error ) {
			$list .= '<li>' . esc_html( (string) $error ) . '</li>';
		}

		$list .= '</ul>';

		return $this->notice_markup( 'error', $list, true );
	}

	/**
	 * Redirect after successful submission.
	 *
	 * @param string $form Form key.
	 */
	private function redirect_after_success( string $form ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'adam_form_success' => $form,
				),
				$this->current_url()
			)
		);
		exit;
	}

	/**
	 * Format fee for display.
	 *
	 * @param string $fee Fee value.
	 */
	private function format_fee( string $fee ): string {
		return number_format_i18n( (float) str_replace( ',', '.', $fee ), 2 ) . ' ' . html_entity_decode( '&#8364;', ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Format canonical date for display.
	 *
	 * @param string $date Stored date.
	 */
	private function format_date( string $date ): string {
		$timestamp = strtotime( $date );

		return false === $timestamp ? $date : wp_date( 'd/m/Y', $timestamp );
	}

	/**
	 * Build a notice box.
	 *
	 * @param string $type Type.
	 * @param string $message Message HTML/text.
	 * @param bool   $is_html Whether message already contains HTML.
	 */
	private function notice_markup( string $type, string $message, bool $is_html = false ): string {
		return sprintf(
			'<div class="adam-notice adam-notice--%1$s">%2$s</div>',
			esc_attr( $type ),
			$is_html ? wp_kses_post( $message ) : wp_kses_post( wpautop( $message ) )
		);
	}

	/**
	 * Get the current absolute page URL.
	 */
	private function current_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';

		return home_url( $request_uri );
	}

	/**
	 * Inject native forms on configured public pages when legacy shortcodes remain.
	 *
	 * @param string $content Page content.
	 */
	public function inject_native_forms_into_configured_pages( string $content ): string {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$permalink = get_permalink();

		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return $content;
		}

		$native_content = $content;

		if ( $this->same_url( $permalink, $this->settings->registration_page_url() ) ) {
			if ( str_contains( $content, '[adam_registration_form' ) || str_contains( $content, '[adam_membership_form' ) ) {
				return $content;
			}

			$native_content = preg_replace( '/\[forminator_form[^\]]*\]/', '', $content ) ?: $content;
			return $native_content . "\n\n[adam_registration_form]";
		}

		if ( $this->same_url( $permalink, $this->settings->renewal_page_url() ) ) {
			if ( str_contains( $content, '[adam_renewal_form' ) || str_contains( $content, '[adam_membership_form' ) ) {
				return $content;
			}

			$native_content = preg_replace( '/\[forminator_form[^\]]*\]/', '', $content ) ?: $content;
			return $native_content . "\n\n[adam_renewal_form]";
		}

		return $content;
	}

	/**
	 * Compare two URLs by normalized path.
	 *
	 * @param string $left Left URL.
	 * @param string $right Right URL.
	 */
	private function same_url( string $left, string $right ): bool {
		$left_path  = wp_parse_url( $left, PHP_URL_PATH );
		$right_path = wp_parse_url( $right, PHP_URL_PATH );

		if ( ! is_string( $left_path ) || ! is_string( $right_path ) ) {
			return false;
		}

		return trailingslashit( $left_path ) === trailingslashit( $right_path );
	}
}
