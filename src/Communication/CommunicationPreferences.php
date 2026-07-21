<?php
/**
 * Member communication preferences.
 *
 * @package AdamMembership\Communication
 */

declare(strict_types=1);

namespace AdamMembership\Communication;

/**
 * Stores channel preferences per member in a future-ready user-meta document.
 */
final class CommunicationPreferences {
	public const CHANNEL_EMAIL = 'email';

	private const META_KEY = 'adam_membership_communication_preferences';

	/**
	 * Category registry.
	 *
	 * @var CommunicationCategoryRegistry
	 */
	private CommunicationCategoryRegistry $categories;

	/**
	 * Constructor.
	 *
	 * @param CommunicationCategoryRegistry $categories Category registry.
	 */
	public function __construct( CommunicationCategoryRegistry $categories ) {
		$this->categories = $categories;
	}

	/**
	 * Get the category registry.
	 */
	public function categories(): CommunicationCategoryRegistry {
		return $this->categories;
	}

	/**
	 * Get optional subscriptions for a member and channel.
	 *
	 * @param int    $user_id Member user ID.
	 * @param string $channel Delivery channel.
	 * @return array<string, bool>
	 */
	public function subscriptions( int $user_id, string $channel = self::CHANNEL_EMAIL ): array {
		$subscriptions = array();

		foreach ( $this->categories->optional() as $category_id => $category ) {
			$subscriptions[ $category_id ] = $this->is_subscribed( $user_id, $category_id, $channel );
		}

		return $subscriptions;
	}

	/**
	 * Determine whether a member accepts a category through a channel.
	 *
	 * Mandatory and unknown legacy categories always return true.
	 *
	 * @param int    $user_id Member user ID.
	 * @param string $category Stored category label or ID.
	 * @param string $channel Delivery channel.
	 */
	public function is_subscribed( int $user_id, string $category, string $channel = self::CHANNEL_EMAIL ): bool {
		$category_id = $this->categories->id_for( $category );

		if ( null === $category_id || ! $this->categories->is_optional( $category_id ) ) {
			return true;
		}

		return ! in_array( $category_id, $this->disabled_categories( $user_id, $channel ), true );
	}

	/**
	 * Save the complete set of enabled optional categories for a channel.
	 *
	 * Missing metadata and newly introduced optional categories are enabled by
	 * default; only explicit opt-outs are persisted.
	 *
	 * @param int                $user_id             Member user ID.
	 * @param string             $channel             Delivery channel.
	 * @param array<int, string> $enabled_categories Enabled category IDs.
	 * @return array<string, bool> Updated subscriptions.
	 */
	public function save_subscriptions( int $user_id, string $channel, array $enabled_categories ): array {
		$channel = sanitize_key( $channel );

		if ( '' === $channel ) {
			$channel = self::CHANNEL_EMAIL;
		}

		$optional_ids = array_keys( $this->categories->optional() );
		$enabled_ids  = array_values(
			array_intersect(
				$optional_ids,
				array_unique( array_map( 'sanitize_title', $enabled_categories ) )
			)
		);
		$preferences  = $this->raw_preferences( $user_id );

		if ( ! isset( $preferences['channels'] ) || ! is_array( $preferences['channels'] ) ) {
			$preferences['channels'] = array();
		}

		$channel_preferences = isset( $preferences['channels'][ $channel ] ) && is_array( $preferences['channels'][ $channel ] )
			? $preferences['channels'][ $channel ]
			: array();

		$preferences['version']                     = 1;
		$channel_preferences['disabled_categories'] = array_values( array_diff( $optional_ids, $enabled_ids ) );
		$preferences['channels'][ $channel ]        = $channel_preferences;

		update_user_meta( $user_id, self::META_KEY, $preferences );

		return $this->subscriptions( $user_id, $channel );
	}

	/**
	 * Get disabled category IDs for a channel.
	 *
	 * @param int    $user_id Member user ID.
	 * @param string $channel Delivery channel.
	 * @return array<int, string>
	 */
	private function disabled_categories( int $user_id, string $channel ): array {
		$preferences = $this->raw_preferences( $user_id );
		$channel     = sanitize_key( $channel );
		$disabled    = $preferences['channels'][ $channel ]['disabled_categories'] ?? array();

		if ( ! is_array( $disabled ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_unique(
					array_map(
						static fn ( mixed $category_id ): string => is_scalar( $category_id ) ? sanitize_title( (string) $category_id ) : '',
						$disabled
					)
				)
			)
		);
	}

	/**
	 * Load the preference document.
	 *
	 * @param int $user_id Member user ID.
	 * @return array<string, mixed>
	 */
	private function raw_preferences( int $user_id ): array {
		$preferences = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $preferences ) ? $preferences : array();
	}
}
