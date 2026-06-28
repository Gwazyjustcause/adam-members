<?php
/**
 * Rate limiting helper.
 *
 * @package AdamMembership\Helpers
 */

declare(strict_types=1);

namespace AdamMembership\Helpers;

/**
 * Provides transient-backed throttling for public account actions.
 */
final class RateLimiter {

	/**
	 * Check whether an action has exceeded its limit.
	 *
	 * @param string $action   Action name.
	 * @param string $identity User, login, email, or request identity.
	 * @param int    $limit    Maximum attempts allowed.
	 * @param int    $window   Time window in seconds.
	 */
	public static function too_many_attempts( string $action, string $identity, int $limit, int $window ): bool {
		return self::attempts( $action, $identity ) >= $limit;
	}

	/**
	 * Record an action attempt.
	 *
	 * @param string $action   Action name.
	 * @param string $identity User, login, email, or request identity.
	 * @param int    $window   Time window in seconds.
	 */
	public static function hit( string $action, string $identity, int $window ): void {
		$key      = self::key( $action, $identity );
		$attempts = self::attempts( $action, $identity ) + 1;

		set_transient( $key, $attempts, $window );
	}

	/**
	 * Clear attempts for an action and identity.
	 *
	 * @param string $action   Action name.
	 * @param string $identity User, login, email, or request identity.
	 */
	public static function clear( string $action, string $identity ): void {
		delete_transient( self::key( $action, $identity ) );
	}

	/**
	 * Build a request identity using the client IP address when available.
	 *
	 * @param string $suffix Additional identity data.
	 */
	public static function request_identity( string $suffix = '' ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		return strtolower( trim( $ip . '|' . $suffix ) );
	}

	/**
	 * Get the current attempt count.
	 *
	 * @param string $action   Action name.
	 * @param string $identity User, login, email, or request identity.
	 */
	private static function attempts( string $action, string $identity ): int {
		return absint( get_transient( self::key( $action, $identity ) ) );
	}

	/**
	 * Build a safe transient key.
	 *
	 * @param string $action   Action name.
	 * @param string $identity User, login, email, or request identity.
	 */
	private static function key( string $action, string $identity ): string {
		return 'adam_rl_' . md5( $action . '|' . strtolower( $identity ) );
	}
}
