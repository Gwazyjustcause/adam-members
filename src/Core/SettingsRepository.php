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
	private const OPTION_LAST_MEMBER_NUMBER = 'adam_membership_last_member_number';
	private const OPTION_RENEWAL_PAGE_URL   = 'adam_membership_renewal_page_url';
	private const OPTION_EMAIL_FROM_NAME    = 'adam_membership_email_from_name';
	private const OPTION_EMAIL_FROM_ADDRESS = 'adam_membership_email_from_address';
	private const DEFAULT_EMAIL_FROM_NAME   = 'ADAM - Associação Desportiva de Airsoft do Mondego';
	private const DEFAULT_EMAIL_FROM_ADDRESS = 'geral@airsoftmondego.pt';

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
	 * Get the renewal page URL.
	 */
	public function renewal_page_url(): string {
		$url = (string) get_option( self::OPTION_RENEWAL_PAGE_URL, '' );

		return '' !== $url ? $url : home_url( '/renovar-quota/' );
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
	 * Format a numeric member number.
	 *
	 * @param int $number Numeric member number.
	 */
	public function format_member_number( int $number ): string {
		return sprintf( 'ADAM-%04d', $number );
	}
}
