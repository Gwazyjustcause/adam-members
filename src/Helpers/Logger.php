<?php
/**
 * Logging helper.
 *
 * @package AdamMembership\Helpers
 */

declare(strict_types=1);

namespace AdamMembership\Helpers;

/**
 * Centralizes plugin logging.
 */
final class Logger {
	/**
	 * Toggle plugin logging from one place.
	 */
	private const ENABLED = true;

	/**
	 * Log an informational message.
	 *
	 * @param string               $message Message to log.
	 * @param array<string, mixed> $context Optional structured context.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string               $message Message to log.
	 * @param array<string, mixed> $context Optional structured context.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Log a message at the given level.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Message to log.
	 * @param array<string, mixed> $context Optional structured context.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		error_log( $this->format_message( $level, $message, $context ) );
	}

	/**
	 * Determine whether logging is enabled.
	 */
	private function is_enabled(): bool {
		return self::ENABLED && (bool) apply_filters( 'adam_membership_logging_enabled', true );
	}

	/**
	 * Format a log message.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Message to log.
	 * @param array<string, mixed> $context Optional structured context.
	 */
	private function format_message( string $level, string $message, array $context ): string {
		$formatted = sprintf( '[ADAM Membership] %1$s: %2$s', strtoupper( $level ), $message );

		if ( array() === $context ) {
			return $formatted;
		}

		return $formatted . ' ' . wp_json_encode( $context );
	}
}
