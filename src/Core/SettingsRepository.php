<?php
/**
 * Plugin settings repository.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

/**
 * Reads and writes plugin settings.
 */
final class SettingsRepository {
	private const OPTION_LAST_MEMBER_NUMBER        = 'adam_membership_last_member_number';
	private const OPTION_REGISTRATION_PAGE_URL     = 'adam_membership_registration_page_url';
	private const OPTION_RENEWAL_PAGE_URL          = 'adam_membership_renewal_page_url';
	private const OPTION_ACCOUNT_SETUP_PAGE_URL    = 'adam_membership_account_setup_page_url';
	private const OPTION_EMAIL_FROM_NAME           = 'adam_membership_email_from_name';
	private const OPTION_EMAIL_FROM_ADDRESS        = 'adam_membership_email_from_address';
	private const OPTION_ASSOCIATION_NAME          = 'adam_membership_association_name';
	private const OPTION_ASSOCIATION_LOGO          = 'adam_membership_association_logo';
	private const OPTION_PRIVACY_POLICY_URL        = 'adam_membership_privacy_policy_url';
	private const OPTION_COOKIE_POLICY_URL         = 'adam_membership_cookie_policy_url';
	private const OPTION_MEMBERSHIP_TERMS_URL      = 'adam_membership_membership_terms_url';
	private const OPTION_MEMBERSHIP_FORM_SETTINGS  = 'adam_membership_form_settings';
	private const OPTION_EMAIL_TEMPLATE_SETTINGS   = 'adam_membership_email_template_settings';
	private const DEFAULT_EMAIL_FROM_NAME          = "ADAM - Associa\u{00E7}\u{00E3}o Desportiva de Airsoft do Mondego";
	private const DEFAULT_EMAIL_FROM_ADDRESS       = 'geral@airsoftmondego.pt';
	private const DEFAULT_ASSOCIATION_NAME         = "ADAM - Associa\u{00E7}\u{00E3}o Desportiva de Airsoft do Mondego";
	private const DEFAULT_ASSOCIATION_LOGO         = 'https://airsoftmondego.pt/wp-content/uploads/2026/06/ADAM.png';

	/**
	 * Get the last assigned numeric member number.
	 */
	public function last_assigned_member_number(): int {
		return absint( get_option( self::OPTION_LAST_MEMBER_NUMBER, 0 ) );
	}

	/**
	 * Reserve and return the next formatted member number.
	 */
	public function reserve_next_member_number(): string {
		$next_number = $this->last_assigned_member_number() + 1;

		update_option( self::OPTION_LAST_MEMBER_NUMBER, $next_number, false );

		return $this->format_member_number( $next_number );
	}

	/**
	 * Preview the next formatted member number without reserving it.
	 */
	public function preview_next_member_number(): string {
		return $this->format_member_number( $this->last_assigned_member_number() + 1 );
	}

	/**
	 * Get the member area URL used in emails.
	 */
	public function member_area_url(): string {
		return (string) apply_filters( 'adam_membership_member_area_url', wp_login_url() );
	}

	/**
	 * Get the registration page URL.
	 */
	public function registration_page_url(): string {
		$url = (string) get_option( self::OPTION_REGISTRATION_PAGE_URL, '' );

		return '' !== $url ? $url : home_url( '/inscricao/' );
	}

	/**
	 * Save the registration page URL.
	 *
	 * @param string $url Registration page URL.
	 */
	public function save_registration_page_url( string $url ): void {
		update_option( self::OPTION_REGISTRATION_PAGE_URL, esc_url_raw( $url ), false );
	}

	/**
	 * Get the renewal page URL.
	 */
	public function renewal_page_url(): string {
		$url = (string) get_option( self::OPTION_RENEWAL_PAGE_URL, '' );

		return '' !== $url ? $url : home_url( '/renovar-quota/' );
	}

	/**
	 * Get the account setup page URL.
	 */
	public function account_setup_page_url(): string {
		$url = (string) get_option( self::OPTION_ACCOUNT_SETUP_PAGE_URL, '' );

		return '' !== $url ? $url : home_url( '/definir-user/' );
	}

	/**
	 * Save the renewal page URL.
	 *
	 * @param string $url Renewal page URL.
	 */
	public function save_renewal_page_url( string $url ): void {
		update_option( self::OPTION_RENEWAL_PAGE_URL, esc_url_raw( $url ), false );
	}

	/**
	 * Save the account setup page URL.
	 *
	 * @param string $url Account setup page URL.
	 */
	public function save_account_setup_page_url( string $url ): void {
		update_option( self::OPTION_ACCOUNT_SETUP_PAGE_URL, esc_url_raw( $url ), false );
	}

	/**
	 * Get the branded email sender name.
	 */
	public function email_from_name(): string {
		$name = (string) get_option( self::OPTION_EMAIL_FROM_NAME, '' );

		return '' !== trim( $name ) ? sanitize_text_field( $name ) : self::DEFAULT_EMAIL_FROM_NAME;
	}

	/**
	 * Get the branded email sender address.
	 */
	public function email_from_address(): string {
		$email = sanitize_email( (string) get_option( self::OPTION_EMAIL_FROM_ADDRESS, '' ) );

		return is_email( $email ) ? $email : self::DEFAULT_EMAIL_FROM_ADDRESS;
	}

	/**
	 * Save branded email sender settings.
	 *
	 * @param string $name  Sender name.
	 * @param string $email Sender email address.
	 */
	public function save_email_sender( string $name, string $email ): void {
		update_option( self::OPTION_EMAIL_FROM_NAME, sanitize_text_field( $name ), false );
		update_option( self::OPTION_EMAIL_FROM_ADDRESS, sanitize_email( $email ), false );
	}

	/**
	 * Get association display name.
	 */
	public function association_name(): string {
		$name = (string) get_option( self::OPTION_ASSOCIATION_NAME, '' );

		return '' !== trim( $name ) ? sanitize_text_field( $name ) : self::DEFAULT_ASSOCIATION_NAME;
	}

	/**
	 * Get association logo URL.
	 */
	public function association_logo_url(): string {
		$url = esc_url_raw( (string) get_option( self::OPTION_ASSOCIATION_LOGO, '' ) );

		return '' !== $url ? $url : self::DEFAULT_ASSOCIATION_LOGO;
	}

	/**
	 * Save association display settings.
	 *
	 * @param string $name Association name.
	 * @param string $logo Logo URL.
	 */
	public function save_association_settings( string $name, string $logo ): void {
		update_option( self::OPTION_ASSOCIATION_NAME, sanitize_text_field( $name ), false );
		update_option( self::OPTION_ASSOCIATION_LOGO, esc_url_raw( $logo ), false );
	}

	/**
	 * Get privacy policy URL.
	 */
	public function privacy_policy_url(): string {
		$url = esc_url_raw( (string) get_option( self::OPTION_PRIVACY_POLICY_URL, '' ) );

		return '' !== $url ? $url : get_privacy_policy_url();
	}

	/**
	 * Get cookie policy URL.
	 */
	public function cookie_policy_url(): string {
		return esc_url_raw( (string) get_option( self::OPTION_COOKIE_POLICY_URL, '' ) );
	}

	/**
	 * Get membership terms URL.
	 */
	public function membership_terms_url(): string {
		return esc_url_raw( (string) get_option( self::OPTION_MEMBERSHIP_TERMS_URL, '' ) );
	}

	/**
	 * Save compliance page URLs.
	 *
	 * @param string $privacy Privacy policy URL.
	 * @param string $cookie Cookie policy URL.
	 * @param string $terms Membership terms URL.
	 */
	public function save_compliance_pages( string $privacy, string $cookie, string $terms ): void {
		update_option( self::OPTION_PRIVACY_POLICY_URL, esc_url_raw( $privacy ), false );
		update_option( self::OPTION_COOKIE_POLICY_URL, esc_url_raw( $cookie ), false );
		update_option( self::OPTION_MEMBERSHIP_TERMS_URL, esc_url_raw( $terms ), false );
	}

	/**
	 * Get native membership form settings.
	 *
	 * @return array<string, mixed>
	 */
	public function membership_form_settings(): array {
		$stored = get_option( self::OPTION_MEMBERSHIP_FORM_SETTINGS, array() );

		$settings = $this->normalize_membership_form_settings(
			$this->merge_membership_form_settings(
				$this->default_membership_form_settings(),
				is_array( $stored ) ? $stored : array()
			)
		);

		$settings['registration_fields'] = $this->enrich_form_field_settings( (array) $settings['registration_fields'], 'registration' );
		$settings['renewal_fields']      = $this->enrich_form_field_settings( (array) $settings['renewal_fields'], 'renewal' );

		return $settings;
	}

	/**
	 * Save native membership form settings.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 */
	public function save_membership_form_settings( array $settings ): void {
		$defaults = $this->default_membership_form_settings();
		$clean    = $defaults;

		$clean['forms']['registration']['enabled'] = ! empty( $settings['forms']['registration']['enabled'] );
		$clean['forms']['renewal']['enabled']      = ! empty( $settings['forms']['renewal']['enabled'] );

		$clean['fees']['primary']   = $this->sanitize_money( $settings['fees']['primary'] ?? $defaults['fees']['primary'] );
		$clean['fees']['secondary'] = $this->sanitize_money( $settings['fees']['secondary'] ?? $defaults['fees']['secondary'] );

		$clean['payment']['mbway']        = sanitize_text_field( (string) ( $settings['payment']['mbway'] ?? $defaults['payment']['mbway'] ) );
		$clean['payment']['iban']         = sanitize_text_field( (string) ( $settings['payment']['iban'] ?? $defaults['payment']['iban'] ) );
		$clean['payment']['instructions'] = sanitize_textarea_field( (string) ( $settings['payment']['instructions'] ?? $defaults['payment']['instructions'] ) );

		$clean['legal']['registration_privacy_text'] = sanitize_textarea_field( (string) ( $settings['legal']['registration_privacy_text'] ?? $defaults['legal']['registration_privacy_text'] ) );
		$clean['legal']['renewal_privacy_text']      = sanitize_textarea_field( (string) ( $settings['legal']['renewal_privacy_text'] ?? $defaults['legal']['renewal_privacy_text'] ) );
		$clean['legal']['registration_help']         = sanitize_textarea_field( (string) ( $settings['legal']['registration_help'] ?? $defaults['legal']['registration_help'] ) );
		$clean['legal']['renewal_help']              = sanitize_textarea_field( (string) ( $settings['legal']['renewal_help'] ?? $defaults['legal']['renewal_help'] ) );

		$clean['registration_fields'] = $this->sanitize_form_field_settings(
			(array) ( $settings['registration_fields'] ?? array() ),
			(array) $defaults['registration_fields'],
			'registration'
		);
		$clean['renewal_fields'] = $this->sanitize_form_field_settings(
			(array) ( $settings['renewal_fields'] ?? array() ),
			(array) $defaults['renewal_fields'],
			'renewal'
		);

		update_option( self::OPTION_MEMBERSHIP_FORM_SETTINGS, $clean, false );
	}

	/**
	 * Get configurable email template settings.
	 *
	 * @return array<string, mixed>
	 */
	public function email_template_settings(): array {
		$stored = get_option( self::OPTION_EMAIL_TEMPLATE_SETTINGS, array() );

		return $this->normalize_membership_form_settings(
			$this->merge_membership_form_settings(
				$this->default_email_template_settings(),
				is_array( $stored ) ? $stored : array()
			)
		);
	}

	/**
	 * Save configurable email template settings.
	 *
	 * @param array<string, mixed> $settings Raw email settings.
	 */
	public function save_email_template_settings( array $settings ): void {
		$defaults = $this->default_email_template_settings();
		$clean    = $defaults;

		foreach ( $defaults as $key => $config ) {
			$input = isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ? $settings[ $key ] : array();

			$clean[ $key ]['enabled'] = ! empty( $input['enabled'] );
			$clean[ $key ]['subject'] = sanitize_text_field( (string) ( $input['subject'] ?? $config['subject'] ) );
			$clean[ $key ]['body']    = wp_kses_post( (string) ( $input['body'] ?? $config['body'] ) );
		}

		update_option( self::OPTION_EMAIL_TEMPLATE_SETTINGS, $clean, false );
	}

	/**
	 * Format a numeric member number.
	 *
	 * @param int $number Numeric member number.
	 */
	public function format_member_number( int $number ): string {
		return sprintf( 'ADAM-%04d', $number );
	}

	/**
	 * Get default native membership form settings.
	 *
	 * @return array<string, mixed>
	 */
	private function default_membership_form_settings(): array {
		return array(
			'forms' => array(
				'registration' => array(
					'enabled' => true,
				),
				'renewal' => array(
					'enabled' => true,
				),
			),
			'fees' => array(
				'primary'   => '22.00',
				'secondary' => '12.00',
			),
			'payment' => array(
				'mbway'        => '',
				'iban'         => '',
				'instructions' => "Envie o comprovativo de pagamento com o formul\u{00E1}rio.",
			),
			'legal' => array(
				'registration_privacy_text' => "Li e aceito a Pol\u{00ED}tica de Privacidade da ADAM.",
				'renewal_privacy_text'      => "Li e aceito a Pol\u{00ED}tica de Privacidade da ADAM.",
				'registration_help'         => "Preencha todos os campos obrigat\u{00F3}rios e anexe os comprovativos necess\u{00E1}rios.",
				'renewal_help'              => "Confirme os dados da sua renova\u{00E7}\u{00E3}o e anexe o comprovativo de pagamento.",
			),
			'registration_fields' => array(
				'full_name' => array(
					'label'    => 'Nome Completo',
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'email' => array(
					'label'    => 'Email',
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'citizen_card' => array(
					'label'    => "BI / Cart\u{00E3}o de Cidad\u{00E3}o",
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'nif' => array(
					'label'    => 'NIF',
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'birth_date' => array(
					'label'    => 'Data de Nascimento',
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'phone' => array(
					'label'    => "N\u{00FA}mero de Telem\u{00F3}vel",
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'address_line_1' => array(
					'label'    => 'Rua',
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'address_line_2' => array(
					'label'    => 'Apartamento, suite, etc.',
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'city' => array(
					'label'    => 'Cidade',
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'municipality' => array(
					'label'    => "Munic\u{00ED}pio",
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'postcode' => array(
					'label'    => "ZIP / C\u{00F3}digo Postal",
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'country' => array(
					'label'    => "Pa\u{00ED}s",
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'team' => array(
					'label'    => 'Equipa (opcional)',
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'profile_photo' => array(
					'label'    => 'Fotografia',
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'payment_receipt' => array(
					'label'    => 'Comprovativo de pagamento',
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'privacy_acceptance' => array(
					'label'    => "Aceita\u{00E7}\u{00E3}o da Pol\u{00ED}tica de Privacidade",
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'external_association_name' => array(
					'label'    => "Nome da Associa\u{00E7}\u{00E3}o",
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'external_member_number' => array(
					'label'    => "N\u{00FA}mero de S\u{00F3}cio na Associa\u{00E7}\u{00E3}o",
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'external_association_proof' => array(
					'label'    => "Comprovativo de Associa\u{00E7}\u{00E3}o",
					'help'     => "Pode enviar, por exemplo, cart\u{00E3}o de s\u{00F3}cio, declara\u{00E7}\u{00E3}o emitida pela associa\u{00E7}\u{00E3}o ou outro documento comprovativo.",
					'enabled'  => true,
					'required' => true,
				),
			),
			'renewal_fields' => array(
				'phone' => array(
					'label'    => "Telem\u{00F3}vel",
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'address_line_1' => array(
					'label'    => 'Rua',
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'address_line_2' => array(
					'label'    => 'Apartamento, suite, etc.',
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'city' => array(
					'label'    => 'Cidade',
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'municipality' => array(
					'label'    => "Munic\u{00ED}pio",
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'postcode' => array(
					'label'    => "ZIP / C\u{00F3}digo Postal",
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'country' => array(
					'label'    => "Pa\u{00ED}s",
					'help'     => '',
					'enabled'  => true,
					'required' => false,
				),
				'payment_receipt' => array(
					'label'    => 'Comprovativo de pagamento',
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'privacy_acceptance' => array(
					'label'    => "Aceita\u{00E7}\u{00E3}o da Pol\u{00ED}tica de Privacidade",
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'external_association_name' => array(
					'label'    => "Nome da Associa\u{00E7}\u{00E3}o",
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'external_member_number' => array(
					'label'    => "N\u{00FA}mero de S\u{00F3}cio na Associa\u{00E7}\u{00E3}o",
					'help'     => '',
					'enabled'  => true,
					'required' => true,
				),
				'external_association_proof' => array(
					'label'    => "Comprovativo de Associa\u{00E7}\u{00E3}o",
					'help'     => "Pode enviar, por exemplo, cart\u{00E3}o de s\u{00F3}cio, declara\u{00E7}\u{00E3}o emitida pela associa\u{00E7}\u{00E3}o ou outro documento comprovativo.",
					'enabled'  => true,
					'required' => true,
				),
			),
		);
	}

	/**
	 * Get default automatic email template settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function default_email_template_settings(): array {
		return array(
			'registration_received' => array(
				'enabled' => true,
				'subject' => "Complete o acesso \u{00E0} sua conta ADAM",
				'body'    => "<p>Ol\u{00E1} <strong>{{member_name}}</strong>,</p><p>Recebemos a sua inscri\u{00E7}\u{00E3}o na ADAM e o processo encontra-se agora pendente de valida\u{00E7}\u{00E3}o.</p><p>Para concluir a cria\u{00E7}\u{00E3}o da sua conta, escolha o seu nome de utilizador e a sua palavra-passe atrav\u{00E9}s do link seguro abaixo:</p><p><a href=\"{{account_setup_link}}\">Definir utilizador e palavra-passe</a></p><p>Este link \u{00E9} pessoal, tem validade limitada e deixa de funcionar ap\u{00F3}s a primeira utiliza\u{00E7}\u{00E3}o.</p><p><strong>Email associado:</strong> {{member_email}}</p>",
			),
			'member_approved' => array(
				'enabled' => true,
				'subject' => "A sua inscri\u{00E7}\u{00E3}o na ADAM foi aprovada",
				'body'    => "<p>Ol\u{00E1} <strong>{{member_name}}</strong>,</p><p>\u{00C9} com enorme satisfa\u{00E7}\u{00E3}o que informamos que a sua inscri\u{00E7}\u{00E3}o na ADAM foi aprovada.</p><p><strong>N.\u{00BA} de s\u{00F3}cio:</strong> {{member_number}}</p><p>O seu registo encontra-se agora ativo.</p><p><a href=\"{{member_area_link}}\">Aceder \u{00E0} \u{00C1}rea de S\u{00F3}cio</a></p><p>Cumprimentos,<br><strong>A Dire\u{00E7}\u{00E3}o da ADAM</strong></p>",
			),
			'member_rejected' => array(
				'enabled' => true,
				'subject' => "A sua inscri\u{00E7}\u{00E3}o na ADAM n\u{00E3}o foi aprovada",
				'body'    => "<p>Ol\u{00E1} <strong>{{member_name}}</strong>,</p><p>A sua inscri\u{00E7}\u{00E3}o foi analisada pela Dire\u{00E7}\u{00E3}o da ADAM e n\u{00E3}o foi aprovada.</p><p><strong>Motivo indicado:</strong> {{reason}}</p><p>Caso pretenda mais informa\u{00E7}\u{00F5}es, contacte a Dire\u{00E7}\u{00E3}o da ADAM.</p>",
			),
			'renewal_submitted' => array(
				'enabled' => true,
				'subject' => "Pedido de renova\u{00E7}\u{00E3}o recebido",
				'body'    => "<p>Ol\u{00E1} <strong>{{member_name}}</strong>,</p><p>Recebemos o seu pedido de renova\u{00E7}\u{00E3}o de quota e o respetivo comprovativo de pagamento.</p><p><strong>Estado atual:</strong> {{payment_status}}</p><p><strong>Valor indicado:</strong> {{quota_value}}</p>",
			),
			'renewal_approved' => array(
				'enabled' => true,
				'subject' => "Renova\u{00E7}\u{00E3}o da quota aprovada",
				'body'    => "<p>Ol\u{00E1} <strong>{{member_name}}</strong>,</p><p>A sua renova\u{00E7}\u{00E3}o de quota foi aprovada.</p><p><strong>N.\u{00BA} de s\u{00F3}cio:</strong> {{member_number}}</p><p><strong>Nova validade da quota:</strong> {{expiry_date}}</p><p><a href=\"{{member_area_link}}\">Aceder \u{00E0} \u{00C1}rea de S\u{00F3}cio</a></p>",
			),
			'renewal_rejected' => array(
				'enabled' => true,
				'subject' => "Renova\u{00E7}\u{00E3}o da quota n\u{00E3}o aprovada",
				'body'    => "<p>Ol\u{00E1} <strong>{{member_name}}</strong>,</p><p>O seu pedido de renova\u{00E7}\u{00E3}o foi analisado e n\u{00E3}o foi aprovado.</p><p><strong>Motivo indicado:</strong> {{reason}}</p><p><a href=\"{{renewal_link}}\">Aceder \u{00E0} renova\u{00E7}\u{00E3}o</a></p>",
			),
			'renewal_reminder' => array(
				'enabled' => true,
				'subject' => "Lembrete de renova\u{00E7}\u{00E3}o da quota ADAM",
				'body'    => "<p>Ol\u{00E1} <strong>{{member_name}}</strong>,</p><p>A validade da sua quota est\u{00E1} a aproximar-se.</p><p><strong>N.\u{00BA} de s\u{00F3}cio:</strong> {{member_number}}</p><p><strong>Validade atual:</strong> {{expiry_date}}</p><p><a href=\"{{renewal_link}}\">Abrir formul\u{00E1}rio de renova\u{00E7}\u{00E3}o</a></p>",
			),
			'quota_expired' => array(
				'enabled' => true,
				'subject' => "A sua quota ADAM expirou",
				'body'    => "<p>Ol\u{00E1} <strong>{{member_name}}</strong>,</p><p>A sua quota encontra-se expirada.</p><p><strong>N.\u{00BA} de s\u{00F3}cio:</strong> {{member_number}}</p><p><strong>Validade da quota:</strong> {{expiry_date}}</p><p><a href=\"{{renewal_link}}\">Renovar quota</a></p>",
			),
			'password_reset' => array(
				'enabled' => true,
				'subject' => "Redefini\u{00E7}\u{00E3}o da Palavra-passe",
				'body'    => "<p>Ol\u{00E1} <strong>{{member_name}}</strong>,</p><p>Recebemos um pedido para redefinir a palavra-passe da sua conta.</p><p><a href=\"{{reset_link}}\">Redefinir Palavra-passe</a></p><p>Se n\u{00E3}o efetuou este pedido, pode ignorar este email.</p>",
			),
			'email_confirmation' => array(
				'enabled' => true,
				'subject' => "Confirmar altera\u{00E7}\u{00E3}o de email",
				'body'    => "<p>Ol\u{00E1} <strong>{{member_name}}</strong>,</p><p>Recebemos um pedido para alterar o endere\u{00E7}o de email da sua conta.</p><p><strong>Novo endere\u{00E7}o:</strong> {{new_email}}</p><p><a href=\"{{confirmation_link}}\">Confirmar altera\u{00E7}\u{00E3}o de email</a></p><p>Se n\u{00E3}o efetuou este pedido, ignore este email.</p>",
			),
		);
	}

	/**
	 * Deep merge settings arrays while preserving defaults.
	 *
	 * @param array<string, mixed> $defaults Default settings.
	 * @param array<string, mixed> $stored Stored settings.
	 * @return array<string, mixed>
	 */
	private function merge_membership_form_settings( array $defaults, array $stored ): array {
		foreach ( $stored as $key => $value ) {
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) ) {
				$defaults[ $key ] = $this->merge_membership_form_settings( $defaults[ $key ], $value );
				continue;
			}

			$defaults[ $key ] = $value;
		}

		return $defaults;
	}

	/**
	 * Normalize legacy mojibake in stored form settings.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return array<string, mixed>
	 */
	private function normalize_membership_form_settings( array $settings ): array {
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				$settings[ $key ] = $this->normalize_membership_form_settings( $value );
				continue;
			}

			if ( is_string( $value ) ) {
				$settings[ $key ] = $this->normalize_legacy_text( $value );
			}
		}

		return $settings;
	}

	/**
	 * Sanitize field configuration arrays.
	 *
	 * @param array<string, mixed> $input Input field settings.
	 * @param array<string, mixed> $defaults Default field settings.
	 * @return array<string, mixed>
	 */
	private function sanitize_form_field_settings( array $input, array $defaults, string $form ): array {
		$clean     = array();
		$system    = $this->enrich_form_field_settings( $defaults, $form );
		$processed = array();

		foreach ( $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$field_key = $this->sanitize_form_field_key( (string) ( $row['field_key'] ?? '' ) );

			if ( '' === $field_key || 'external_ana_number' === $field_key || isset( $processed[ $field_key ] ) ) {
				continue;
			}

			$is_system = isset( $system[ $field_key ] );

			if ( ! $is_system && ! empty( $row['delete'] ) ) {
				continue;
			}

			$clean[ $field_key ] = $this->sanitize_single_form_field(
				$field_key,
				$row,
				$is_system ? (array) $system[ $field_key ] : array(),
				$form,
				$is_system
			);
			$processed[ $field_key ] = true;
		}

		foreach ( $system as $field_key => $field_defaults ) {
			if ( isset( $processed[ $field_key ] ) ) {
				continue;
			}

			$clean[ $field_key ] = $this->sanitize_single_form_field(
				(string) $field_key,
				array(),
				(array) $field_defaults,
				$form,
				true
			);
		}

		uasort(
			$clean,
			static function ( array $left, array $right ): int {
				$order_compare = (int) ( $left['order'] ?? 0 ) <=> (int) ( $right['order'] ?? 0 );

				if ( 0 !== $order_compare ) {
					return $order_compare;
				}

				return strcmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) );
			}
		);

		return $clean;
	}

	/**
	 * Enrich field settings with editor metadata.
	 *
	 * @param array<string, mixed> $fields Raw field settings.
	 * @param string               $form   Form key.
	 * @return array<string, array<string, mixed>>
	 */
	private function enrich_form_field_settings( array $fields, string $form ): array {
		$types      = $this->system_field_types( $form );
		$orders     = $this->system_field_order( $form );
		$conditions = $this->system_field_conditions( $form );
		$system     = $this->system_field_keys( $form );
		$clean      = array();

		foreach ( $fields as $field_key => $config ) {
			if ( ! is_string( $field_key ) || ! is_array( $config ) || 'external_ana_number' === $field_key ) {
				continue;
			}

			$is_system = in_array( $field_key, $system, true );
			$clean[ $field_key ] = array(
				'label'      => sanitize_text_field( (string) ( $config['label'] ?? $field_key ) ),
				'help'       => sanitize_textarea_field( (string) ( $config['help'] ?? '' ) ),
				'enabled'    => ! empty( $config['enabled'] ),
				'required'   => ! empty( $config['required'] ),
				'type'       => $this->sanitize_form_field_type( (string) ( $config['type'] ?? ( $types[ $field_key ] ?? 'text' ) ) ),
				'options'    => sanitize_textarea_field( (string) ( $config['options'] ?? '' ) ),
				'conditional' => $this->sanitize_form_field_condition( (string) ( $config['conditional'] ?? ( $conditions[ $field_key ] ?? 'always' ) ), $form ),
				'order'      => absint( $config['order'] ?? ( $orders[ $field_key ] ?? 999 ) ),
				'locked'     => $is_system,
				'removable'  => ! $is_system,
			);
		}

		return $clean;
	}

	/**
	 * Sanitize a single field definition.
	 *
	 * @param string               $field_key Field key.
	 * @param array<string, mixed> $input     Raw row input.
	 * @param array<string, mixed> $defaults  Defaults.
	 * @param string               $form      Form key.
	 * @param bool                 $is_system Whether the field is protected.
	 * @return array<string, mixed>
	 */
	private function sanitize_single_form_field( string $field_key, array $input, array $defaults, string $form, bool $is_system ): array {
		$type = $this->sanitize_form_field_type( (string) ( $input['type'] ?? ( $defaults['type'] ?? 'text' ) ) );

		return array(
			'label'       => sanitize_text_field( (string) ( $input['label'] ?? ( $defaults['label'] ?? $field_key ) ) ),
			'help'        => sanitize_textarea_field( (string) ( $input['help'] ?? ( $defaults['help'] ?? '' ) ) ),
			'enabled'     => ! empty( $input['enabled'] ) || ( $is_system && ! array_key_exists( 'enabled', $input ) ? ! empty( $defaults['enabled'] ) : false ),
			'required'    => ! empty( $input['required'] ) || ( $is_system && ! array_key_exists( 'required', $input ) ? ! empty( $defaults['required'] ) : false ),
			'type'        => $type,
			'options'     => in_array( $type, array( 'select', 'radio' ), true ) ? sanitize_textarea_field( (string) ( $input['options'] ?? ( $defaults['options'] ?? '' ) ) ) : '',
			'conditional' => $this->sanitize_form_field_condition( (string) ( $input['conditional'] ?? ( $defaults['conditional'] ?? 'always' ) ), $form ),
			'order'       => absint( $input['order'] ?? ( $defaults['order'] ?? 999 ) ),
			'locked'      => $is_system,
			'removable'   => ! $is_system,
		);
	}

	/**
	 * Sanitize a form field key.
	 *
	 * @param string $field_key Raw field key.
	 */
	private function sanitize_form_field_key( string $field_key ): string {
		$field_key = sanitize_key( $field_key );

		return str_starts_with( $field_key, 'field_' ) || str_starts_with( $field_key, 'custom_' ) || preg_match( '/^[a-z][a-z0-9_]*$/', $field_key )
			? $field_key
			: '';
	}

	/**
	 * Sanitize form field type.
	 *
	 * @param string $type Raw type.
	 */
	private function sanitize_form_field_type( string $type ): string {
		$allowed = array( 'text', 'email', 'phone', 'number', 'date', 'select', 'radio', 'checkbox', 'file', 'textarea' );
		$type    = sanitize_key( $type );

		return in_array( $type, $allowed, true ) ? $type : 'text';
	}

	/**
	 * Sanitize a conditional visibility rule.
	 *
	 * @param string $condition Raw condition.
	 * @param string $form      Form key.
	 */
	private function sanitize_form_field_condition( string $condition, string $form ): string {
		$allowed = 'registration' === $form
			? array( 'always', 'registration_external' )
			: array( 'always', 'renewal_profile', 'renewal_external' );

		$condition = sanitize_key( $condition );

		return in_array( $condition, $allowed, true ) ? $condition : 'always';
	}

	/**
	 * Get protected field keys for a form.
	 *
	 * @param string $form Form key.
	 * @return array<int, string>
	 */
	private function system_field_keys( string $form ): array {
		return 'registration' === $form
			? array( 'full_name', 'email', 'citizen_card', 'nif', 'birth_date', 'phone', 'address_line_1', 'address_line_2', 'city', 'municipality', 'postcode', 'country', 'team', 'profile_photo', 'payment_receipt', 'privacy_acceptance', 'external_association_name', 'external_member_number', 'external_association_proof' )
			: array( 'phone', 'address_line_1', 'address_line_2', 'city', 'municipality', 'postcode', 'country', 'payment_receipt', 'privacy_acceptance', 'external_association_name', 'external_member_number', 'external_association_proof' );
	}

	/**
	 * Get default field types for protected fields.
	 *
	 * @param string $form Form key.
	 * @return array<string, string>
	 */
	private function system_field_types( string $form ): array {
		$shared = array(
			'phone'                      => 'phone',
			'address_line_1'             => 'text',
			'address_line_2'             => 'text',
			'city'                       => 'text',
			'municipality'               => 'text',
			'postcode'                   => 'text',
			'country'                    => 'text',
			'payment_receipt'            => 'file',
			'privacy_acceptance'         => 'checkbox',
			'external_association_name'  => 'text',
			'external_member_number'     => 'text',
			'external_association_proof' => 'file',
		);

		if ( 'renewal' === $form ) {
			return $shared;
		}

		return array_merge(
			array(
				'full_name'      => 'text',
				'email'          => 'email',
				'citizen_card'   => 'text',
				'nif'            => 'text',
				'birth_date'     => 'date',
				'team'           => 'text',
				'profile_photo'  => 'file',
			),
			$shared
		);
	}

	/**
	 * Get default conditional rules for protected fields.
	 *
	 * @param string $form Form key.
	 * @return array<string, string>
	 */
	private function system_field_conditions( string $form ): array {
		if ( 'renewal' === $form ) {
			return array(
				'phone'                      => 'renewal_profile',
				'address_line_1'             => 'renewal_profile',
				'address_line_2'             => 'renewal_profile',
				'city'                       => 'renewal_profile',
				'municipality'               => 'renewal_profile',
				'postcode'                   => 'renewal_profile',
				'country'                    => 'renewal_profile',
				'payment_receipt'            => 'always',
				'privacy_acceptance'         => 'always',
				'external_association_name'  => 'renewal_external',
				'external_member_number'     => 'renewal_external',
				'external_association_proof' => 'renewal_external',
			);
		}

		return array(
			'full_name'                  => 'always',
			'email'                      => 'always',
			'citizen_card'               => 'always',
			'nif'                        => 'always',
			'birth_date'                 => 'always',
			'phone'                      => 'always',
			'address_line_1'             => 'always',
			'address_line_2'             => 'always',
			'city'                       => 'always',
			'municipality'               => 'always',
			'postcode'                   => 'always',
			'country'                    => 'always',
			'team'                       => 'always',
			'profile_photo'              => 'always',
			'payment_receipt'            => 'always',
			'privacy_acceptance'         => 'always',
			'external_association_name'  => 'registration_external',
			'external_member_number'     => 'registration_external',
			'external_association_proof' => 'registration_external',
		);
	}

	/**
	 * Get default field order for protected fields.
	 *
	 * @param string $form Form key.
	 * @return array<string, int>
	 */
	private function system_field_order( string $form ): array {
		$keys  = $this->system_field_keys( $form );
		$order = array();

		foreach ( array_values( $keys ) as $index => $field_key ) {
			$order[ $field_key ] = $index + 1;
		}

		return $order;
	}

	/**
	 * Repair common legacy mojibake sequences from older form settings.
	 *
	 * @param string $value Raw text.
	 */
	private function normalize_legacy_text( string $value ): string {
		if ( ! str_contains( $value, "\u{00C3}" ) && ! str_contains( $value, "\u{00C2}" ) && ! str_contains( $value, "\u{FFFD}" ) ) {
			return $value;
		}

		return strtr(
			$value,
			array(
				"\u{00C3}\u{00A1}" => "\u{00E1}",
				"\u{00C3}\u{00A0}" => "\u{00E0}",
				"\u{00C3}\u{00A2}" => "\u{00E2}",
				"\u{00C3}\u{00A3}" => "\u{00E3}",
				"\u{00C3}\u{00A9}" => "\u{00E9}",
				"\u{00C3}\u{00AA}" => "\u{00EA}",
				"\u{00C3}\u{00AD}" => "\u{00ED}",
				"\u{00C3}\u{00B3}" => "\u{00F3}",
				"\u{00C3}\u{00B4}" => "\u{00F4}",
				"\u{00C3}\u{00B5}" => "\u{00F5}",
				"\u{00C3}\u{00BA}" => "\u{00FA}",
				"\u{00C3}\u{00A7}" => "\u{00E7}",
				"\u{00C3}\u{0081}" => "\u{00C1}",
				"\u{00C3}\u{0089}" => "\u{00C9}",
				"\u{00C3}\u{0093}" => "\u{00D3}",
				"\u{00C3}\u{009A}" => "\u{00DA}",
				"\u{00C3}\u{0087}" => "\u{00C7}",
				"\u{00C2}\u{00BA}" => "\u{00BA}",
				"\u{00C2}\u{00AA}" => "\u{00AA}",
				"\u{FFFD}"         => '',
			)
		);
	}

	/**
	 * Normalize fee values with two decimals.
	 *
	 * @param mixed $value Raw fee value.
	 */
	private function sanitize_money( mixed $value ): string {
		$normalized = str_replace( ',', '.', sanitize_text_field( (string) $value ) );
		$amount     = is_numeric( $normalized ) ? (float) $normalized : 0.0;

		return number_format( max( 0, $amount ), 2, '.', '' );
	}
}
