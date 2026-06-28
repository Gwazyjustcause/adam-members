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
	 * Format a numeric member number.
	 *
	 * @param int $number Numeric member number.
	 */
	public function format_member_number( int $number ): string {
		return sprintf( 'ADAM-%04d', $number );
	}
}
