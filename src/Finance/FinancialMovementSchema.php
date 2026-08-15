<?php
declare(strict_types=1);

namespace AdamMembership\Finance;

final class FinancialMovementSchema {
	private const VERSION = '1.2.0';
	private const VERSION_OPTION = 'adam_membership_financial_movements_schema_version';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'adam_financial_movements';
	}

	public static function tombstone_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'adam_financial_movement_tombstones';
	}

	public static function install(): bool {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
		$table = self::table_name();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			movement_id varchar(191) NOT NULL,
			member_id bigint(20) unsigned NOT NULL,
			member_number varchar(64) NOT NULL DEFAULT '',
			member_name varchar(191) NOT NULL DEFAULT '',
			member_type varchar(32) NOT NULL DEFAULT '',
			source_type varchar(32) NOT NULL,
			source_reference varchar(191) NOT NULL,
			quota_type varchar(64) NOT NULL,
			membership_year smallint(5) unsigned NOT NULL DEFAULT 0,
			amount decimal(12,2) NOT NULL DEFAULT 0.00,
			payment_date date DEFAULT NULL,
			payment_method varchar(64) NOT NULL DEFAULT '',
			financial_status varchar(32) NOT NULL DEFAULT 'paid',
			google_state varchar(32) NOT NULL DEFAULT 'pending',
			google_row_number bigint(20) unsigned NOT NULL DEFAULT 0,
			google_error_code varchar(191) NOT NULL DEFAULT '',
			google_missing_fields text NOT NULL,
			google_retry_count int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY movement_id (movement_id),
			UNIQUE KEY source_reference (source_type,source_reference),
			KEY member_id (member_id),
			KEY quota_type (quota_type),
			KEY membership_year (membership_year),
			KEY google_state (google_state)
		) {$wpdb->get_charset_collate()};";
		$tombstones = self::tombstone_table_name();
		$tombstone_sql = "CREATE TABLE {$tombstones} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			movement_id varchar(191) NOT NULL,
			deleted_at datetime NOT NULL,
			deleted_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY movement_id (movement_id)
		) {$wpdb->get_charset_collate()};";
		try { dbDelta( $sql ); dbDelta( $tombstone_sql ); } catch ( \Throwable $exception ) { return false; }
		if ( self::table_exists() && self::tombstone_table_exists() ) { update_option( self::VERSION_OPTION, self::VERSION, false ); return true; }
		return false;
	}

	public static function maybe_install(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) { self::install(); }
	}

	private static function table_exists(): bool {
		global $wpdb;
		return self::table_name() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table_name() ) ) );
	}

	private static function tombstone_table_exists(): bool {
		global $wpdb;
		return self::tombstone_table_name() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::tombstone_table_name() ) ) );
	}
}
