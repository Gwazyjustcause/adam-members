<?php
/**
 * Team repository.
 *
 * @package AdamMembership\Team
 */

declare(strict_types=1);

namespace AdamMembership\Team;

/**
 * Stores and retrieves teams from the dedicated team table.
 */
final class TeamRepository {
	/**
	 * Create a team unless an equivalent normalized name already exists.
	 *
	 * @param string $name Team name.
	 */
	public function create( string $name ): ?Team {
		global $wpdb;

		$name = self::normalize_name( $name );
		$slug = self::normalize_slug( $name );

		if ( '' === $name || '' === $slug || $this->exists( $name ) ) {
			return null;
		}

		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This repository is the team persistence boundary.
		$inserted = $wpdb->insert(
			TeamSchema::table_name(),
			array(
				'name'       => $name,
				'slug'       => $slug,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Find a team by ID.
	 *
	 * @param int $team_id Team ID.
	 */
	public function find( int $team_id ): ?Team {
		global $wpdb;

		if ( $team_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Team writes must be immediately visible.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				TeamSchema::table_name(),
				$team_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? new Team( $row ) : null;
	}

	/**
	 * Find a team by name using its canonical slug.
	 *
	 * @param string $name Team name.
	 */
	public function find_by_name( string $name ): ?Team {
		return $this->find_by_slug( self::normalize_slug( $name ) );
	}

	/**
	 * Find a team by slug.
	 *
	 * @param string $slug Team slug.
	 */
	public function find_by_slug( string $slug ): ?Team {
		global $wpdb;

		$slug = self::normalize_slug( $slug );

		if ( '' === $slug ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Team writes must be immediately visible.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE slug = %s',
				TeamSchema::table_name(),
				$slug
			),
			ARRAY_A
		);

		return is_array( $row ) ? new Team( $row ) : null;
	}

	/**
	 * List all teams ordered by name.
	 *
	 * @return array<int, Team>
	 */
	public function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Team writes must be immediately visible.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY name ASC',
				TeamSchema::table_name()
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn ( array $row ): Team => new Team( $row ),
			$rows
		);
	}

	/**
	 * Update a team name and canonical slug.
	 *
	 * @param int    $team_id Team ID.
	 * @param string $name    New team name.
	 */
	public function update( int $team_id, string $name ): ?Team {
		global $wpdb;

		if ( null === $this->find( $team_id ) ) {
			return null;
		}

		$name = self::normalize_name( $name );
		$slug = self::normalize_slug( $name );

		if ( '' === $name || '' === $slug || $this->exists( $name, $team_id ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This repository is the team persistence boundary.
		$updated = $wpdb->update(
			TeamSchema::table_name(),
			array(
				'name'       => $name,
				'slug'       => $slug,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $team_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return null;
		}

		return $this->find( $team_id );
	}

	/**
	 * Delete a team.
	 *
	 * @param int $team_id Team ID.
	 */
	public function delete( int $team_id ): bool {
		global $wpdb;

		if ( $team_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This repository is the team persistence boundary.
		$deleted = $wpdb->delete(
			TeamSchema::table_name(),
			array( 'id' => $team_id ),
			array( '%d' )
		);

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Check whether an equivalent normalized team exists.
	 *
	 * @param string $name            Team name.
	 * @param int    $exclude_team_id Team ID to exclude.
	 */
	public function exists( string $name, int $exclude_team_id = 0 ): bool {
		global $wpdb;

		$slug = self::normalize_slug( $name );

		if ( '' === $slug ) {
			return false;
		}

		if ( $exclude_team_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence checks require current database state.
			$team_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE slug = %s AND id <> %d',
					TeamSchema::table_name(),
					$slug,
					$exclude_team_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence checks require current database state.
			$team_id = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE slug = %s',
					TeamSchema::table_name(),
					$slug
				)
			);
		}

		return null !== $team_id;
	}

	/**
	 * Normalize a team name for storage and display.
	 *
	 * @param string $name Team name.
	 */
	public static function normalize_name( string $name ): string {
		$name       = sanitize_text_field( $name );
		$normalized = preg_replace( '/[\s\p{Z}]+/u', ' ', $name );

		return null === $normalized ? '' : trim( $normalized );
	}

	/**
	 * Produce the canonical, case-insensitive team identifier.
	 *
	 * @param string $name Team name or slug.
	 */
	public static function normalize_slug( string $name ): string {
		return sanitize_title( self::normalize_name( $name ) );
	}
}
