<?php
/**
 * Team database schema.
 *
 * @package AdamMembership\Team
 */

declare(strict_types=1);

namespace AdamMembership\Team;

/**
 * Installs and upgrades the team table without changing existing plugin data.
 */
final class TeamSchema {
	private const VERSION        = '1.0.0';
	private const VERSION_OPTION = 'adam_membership_teams_schema_version';

	/**
	 * Install the current schema.
	 */
	public static function install(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(191) NOT NULL,
			slug varchar(191) NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY name (name),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Upgrade the schema once after a plugin update.
	 */
	public static function maybe_install(): void {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Get the site-specific team table name.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'adam_teams';
	}
}
