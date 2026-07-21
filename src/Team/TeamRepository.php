<?php
/**
 * Team repository.
 *
 * @package AdamMembership\Team
 */

declare(strict_types=1);

namespace AdamMembership\Team;

use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use WP_Error;

/**
 * Stores and retrieves teams from the dedicated team table.
 */
final class TeamRepository {
	private const ASSOCIATED_MINIMUM_ACTIVE_MEMBERS = 5;

	/**
	 * Member repository used for derived team membership data.
	 *
	 * @var MemberRepository
	 */
	private MemberRepository $members;

	/**
	 * Constructor.
	 *
	 * @param MemberRepository|null $members Member repository.
	 */
	public function __construct( ?MemberRepository $members = null ) {
		$this->members = $members ?? new MemberRepository();
	}

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
				'team_type'  => Team::TYPE_TEAM,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Find an equivalent team or create it when it does not exist.
	 *
	 * A second lookup after insertion also covers a concurrent request that
	 * creates the same canonical slug between the initial lookup and insert.
	 *
	 * @param string $name Team name.
	 */
	public function find_or_create( string $name ): ?Team {
		$name = self::normalize_name( $name );

		if ( '' === $name ) {
			return null;
		}

		$team = $this->find_by_name( $name );

		if ( null !== $team ) {
			return $team;
		}

		return $this->create( $name ) ?? $this->find_by_name( $name );
	}

	/**
	 * Resolve an optional form selection to canonical storage values.
	 *
	 * An empty selection is valid. A null return means a non-empty team could
	 * not be retrieved or created.
	 *
	 * @param string $name Submitted team name.
	 * @return array{team_id:int,name:string}|null
	 */
	public function resolve_selection( string $name ): ?array {
		$name = self::normalize_name( $name );

		if ( '' === $name ) {
			return array(
				'team_id' => 0,
				'name'    => '',
			);
		}

		$team = $this->find_or_create( $name );

		if ( null === $team ) {
			return null;
		}

		return array(
			'team_id' => $team->id(),
			'name'    => $team->name(),
		);
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
	 * Search and sort teams for administration without persisting member counts.
	 *
	 * @param array{search?:string,orderby?:string,order?:string} $filters Filters.
	 * @return array<int, array{team:Team,active_members:int,total_members:int,eligible:bool}>
	 */
	public function admin_list( array $filters = array() ): array {
		$search      = $this->search_value( (string) ( $filters['search'] ?? '' ) );
		$rows        = array();
		$all_members = $this->members->all_members();

		foreach ( $this->all() as $team ) {
			if ( '' !== $search && ! str_contains( $this->search_value( $team->name() ), $search ) ) {
				continue;
			}

			$team_members   = array_filter(
				$all_members,
				fn ( Member $member ): bool => $this->member_belongs_to_team( $member, $team )
			);
			$active_members = count(
				array_filter(
					$team_members,
					static fn ( Member $member ): bool => Member::STATUS_ACTIVE === $member->effective_status()
				)
			);
			$rows[]         = array(
				'team'           => $team,
				'active_members' => $active_members,
				'total_members'  => count( $team_members ),
				'eligible'       => $active_members >= self::ASSOCIATED_MINIMUM_ACTIVE_MEMBERS,
			);
		}

		$orderby = in_array( (string) ( $filters['orderby'] ?? '' ), array( 'name', 'members', 'created_at', 'updated_at', 'type', 'eligible' ), true ) ? (string) $filters['orderby'] : 'name';
		$order   = 'desc' === strtolower( (string) ( $filters['order'] ?? '' ) ) ? 'desc' : 'asc';

		usort(
			$rows,
			static function ( array $left, array $right ) use ( $orderby, $order ): int {
				$left_team  = $left['team'];
				$right_team = $right['team'];
				$left_value = match ( $orderby ) {
					'members'    => $left['active_members'],
					'created_at' => $left_team->created_at(),
					'updated_at' => $left_team->updated_at(),
					'type'       => $left_team->type(),
					'eligible'   => $left['eligible'],
					default      => strtolower( $left_team->name() ),
				};
				$right_value = match ( $orderby ) {
					'members'    => $right['active_members'],
					'created_at' => $right_team->created_at(),
					'updated_at' => $right_team->updated_at(),
					'type'       => $right_team->type(),
					'eligible'   => $right['eligible'],
					default      => strtolower( $right_team->name() ),
				};
				$result = $left_value <=> $right_value;

				if ( 0 === $result ) {
					$result = strcasecmp( $left_team->name(), $right_team->name() );
				}

				return 'desc' === $order ? -$result : $result;
			}
		);

		return $rows;
	}

	/**
	 * Get all teams currently marked as associated teams.
	 *
	 * This reflects the administrator-controlled state and never changes it.
	 *
	 * @return array<int, Team>
	 */
	public function associated_teams(): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn ( Team $team ): bool => Team::TYPE_ASSOCIATED === $team->type()
			)
		);
	}

	/**
	 * Get all teams that currently meet association eligibility.
	 *
	 * @return array<int, Team>
	 */
	public function eligible_teams(): array {
		return array_values(
			array_map(
				static fn ( array $row ): Team => $row['team'],
				array_filter(
					$this->admin_list(),
					static fn ( array $row ): bool => $row['eligible']
				)
			)
		);
	}

	/**
	 * Determine current association eligibility for a team.
	 *
	 * @param Team|int $team Team model or ID.
	 */
	public function is_eligible( Team|int $team ): bool {
		$team_id = $team instanceof Team ? $team->id() : $team;

		return $team_id > 0 && $this->member_count( $team_id, true ) >= self::ASSOCIATED_MINIMUM_ACTIVE_MEMBERS;
	}

	/**
	 * Build one reusable team summary for internal consumers.
	 *
	 * @param Team|int $team Team model or ID.
	 * @return array{id:int,name:string,slug:string,state:string,member_count:int,active_member_count:int,eligible:bool}|null
	 */
	public function summary( Team|int $team ): ?array {
		$team = $team instanceof Team ? $team : $this->find( $team );

		if ( null === $team ) {
			return null;
		}

		$members      = $this->members_for_team( $team->id() );
		$active_count = count(
			array_filter(
				$members,
				static fn ( Member $member ): bool => Member::STATUS_ACTIVE === $member->effective_status()
			)
		);

		return array(
			'id'                  => $team->id(),
			'name'                => $team->name(),
			'slug'                => $team->slug(),
			'state'               => $team->type(),
			'member_count'        => count( $members ),
			'active_member_count' => $active_count,
			'eligible'            => $active_count >= self::ASSOCIATED_MINIMUM_ACTIVE_MEMBERS,
		);
	}

	/**
	 * Public internal directory for future ADAM Comunidade integration.
	 *
	 * The returned data is calculated from ADAM Sócios and is read-only for
	 * consumers. No eligibility or member counts are duplicated in storage.
	 *
	 * @return array<int, array{id:int,name:string,slug:string,state:string,member_count:int,active_member_count:int,eligible:bool}>
	 */
	public function public_directory(): array {
		return array_map(
			static fn ( array $row ): array => array(
				'id'                  => $row['team']->id(),
				'name'                => $row['team']->name(),
				'slug'                => $row['team']->slug(),
				'state'               => $row['team']->type(),
				'member_count'        => $row['total_members'],
				'active_member_count' => $row['active_members'],
				'eligible'            => $row['eligible'],
			),
			$this->admin_list()
		);
	}

	/**
	 * Calculate aggregate team statistics for administration dashboards.
	 *
	 * @return array{teams:int,associated_teams:int,eligible_teams:int,distributed_members:int}
	 */
	public function statistics(): array {
		$rows = $this->admin_list();

		return array(
			'teams'               => count( $rows ),
			'associated_teams'    => count(
				array_filter(
					$rows,
					static fn ( array $row ): bool => Team::TYPE_ASSOCIATED === $row['team']->type()
				)
			),
			'eligible_teams'      => count(
				array_filter(
					$rows,
					static fn ( array $row ): bool => $row['eligible']
				)
			),
			'distributed_members' => array_sum( array_column( $rows, 'total_members' ) ),
		);
	}

	/**
	 * Get members associated with a team.
	 *
	 * Team ID is authoritative. The legacy name is considered only for members
	 * that do not yet have an ID-based association.
	 *
	 * @param int  $team_id     Team ID.
	 * @param bool $active_only Return only active members.
	 * @return array<int, Member>
	 */
	public function members_for_team( int $team_id, bool $active_only = false ): array {
		$team = $this->find( $team_id );

		if ( null === $team ) {
			return array();
		}

		return array_values(
			array_filter(
				$this->members->all_members(),
				fn ( Member $member ): bool => $this->member_belongs_to_team( $member, $team ) && ( ! $active_only || Member::STATUS_ACTIVE === $member->effective_status() )
			)
		);
	}

	/**
	 * Calculate the current member count for a team.
	 *
	 * @param int  $team_id     Team ID.
	 * @param bool $active_only Count only active members.
	 */
	public function member_count( int $team_id, bool $active_only = false ): int {
		return count( $this->members_for_team( $team_id, $active_only ) );
	}

	/**
	 * Minimum active members required for associated-team status.
	 */
	public function associated_minimum_active_members(): int {
		return self::ASSOCIATED_MINIMUM_ACTIVE_MEMBERS;
	}

	/**
	 * Update a team name and canonical slug.
	 *
	 * @param int    $team_id Team ID.
	 * @param string $name    New team name.
	 */
	public function update( int $team_id, string $name ): ?Team {
		$team = $this->find( $team_id );

		if ( null === $team ) {
			return null;
		}

		$result = $this->update_details( $team_id, $name, $team->type() );

		return $result instanceof WP_Error ? null : $result;
	}

	/**
	 * Update a team's administrative type.
	 *
	 * @param int    $team_id Team ID.
	 * @param string $type    Team type.
	 * @return Team|WP_Error
	 */
	public function update_type( int $team_id, string $type ): Team|WP_Error {
		$team = $this->find( $team_id );

		if ( null === $team ) {
			return new WP_Error( 'adam_membership_team_not_found', __( 'Equipa não encontrada.', 'adam-membership' ) );
		}

		return $this->update_details( $team_id, $team->name(), $type );
	}

	/**
	 * Update the editable administrative details of a team in one operation.
	 *
	 * @param int    $team_id Team ID.
	 * @param string $name    Team name.
	 * @param string $type    Team type.
	 * @return Team|WP_Error
	 */
	public function update_details( int $team_id, string $name, string $type ): Team|WP_Error {
		global $wpdb;

		$team = $this->find( $team_id );
		$name = self::normalize_name( $name );
		$slug = self::normalize_slug( $name );
		$type = sanitize_key( $type );

		if ( null === $team ) {
			return new WP_Error( 'adam_membership_team_not_found', __( 'Equipa não encontrada.', 'adam-membership' ) );
		}

		if ( '' === $name || '' === $slug ) {
			return new WP_Error( 'adam_membership_team_name_required', __( 'O nome da equipa é obrigatório.', 'adam-membership' ) );
		}

		if ( $this->exists( $name, $team_id ) ) {
			return new WP_Error( 'adam_membership_team_name_exists', __( 'Já existe uma equipa com um nome equivalente.', 'adam-membership' ) );
		}

		if ( ! in_array( $type, array( Team::TYPE_TEAM, Team::TYPE_ASSOCIATED ), true ) ) {
			return new WP_Error( 'adam_membership_team_type_invalid', __( 'Tipo de equipa inválido.', 'adam-membership' ) );
		}

		if ( Team::TYPE_ASSOCIATED === $type && Team::TYPE_ASSOCIATED !== $team->type() && ! $this->is_eligible( $team ) ) {
			return new WP_Error(
				'adam_membership_team_associated_minimum',
				__( 'Esta equipa necessita de pelo menos 5 sócios ativos para poder ser marcada como Equipa Associada.', 'adam-membership' )
			);
		}

		$associated_members = $this->members_for_team( $team_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This repository is the team persistence boundary.
		$updated = $wpdb->update(
			TeamSchema::table_name(),
			array(
				'name'       => $name,
				'slug'       => $slug,
				'team_type'  => $type,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $team_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'adam_membership_team_update_failed', __( 'Não foi possível atualizar a equipa.', 'adam-membership' ) );
		}

		foreach ( $associated_members as $member ) {
			$member->save(
				array(
					'equipa'  => $name,
					'team_id' => $team_id,
				)
			);
		}

		return $this->find( $team_id ) ?? new WP_Error( 'adam_membership_team_not_found', __( 'Equipa não encontrada.', 'adam-membership' ) );
	}

	/**
	 * Delete a team.
	 *
	 * @param int $team_id Team ID.
	 */
	public function delete( int $team_id ): bool {
		global $wpdb;

		if ( $team_id <= 0 || null === $this->find( $team_id ) || $this->member_count( $team_id ) > 0 ) {
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

	/**
	 * Normalize text used by team administration search.
	 *
	 * @param string $value Search value.
	 */
	private function search_value( string $value ): string {
		return strtolower( remove_accents( self::normalize_name( $value ) ) );
	}

	/**
	 * Determine whether a member belongs to a team.
	 *
	 * @param Member $member Member.
	 * @param Team   $team   Team.
	 */
	private function member_belongs_to_team( Member $member, Team $team ): bool {
		$member_team_id = absint( $member->field( 'team_id' ) );

		return $member_team_id > 0
			? $member_team_id === $team->id()
			: self::normalize_slug( (string) $member->field( 'equipa' ) ) === $team->slug();
	}
}
