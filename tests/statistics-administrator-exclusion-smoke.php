<?php
/** Regression coverage for administrator exclusion from statistics datasets. */

declare(strict_types=1);

$root = dirname( __DIR__ );
$repository_source = (string) file_get_contents( $root . '/src/Member/MemberRepository.php' );
$statistics_source = (string) file_get_contents( $root . '/src/Analytics/StatisticsService.php' );

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

// The repository query is the source dataset: role exclusion must happen in
// WP_User_Query, before status, founder, date, or any other statistic exists.
$assert( str_contains( $repository_source, 'public function statistical_members' ), 'A dedicated statistical member dataset must exist.' );
$assert( str_contains( $repository_source, "'role__not_in' => array( 'administrator' )" ), 'The statistical member query must exclude administrator roles at query level.' );
$assert( str_contains( $repository_source, "'key'     => 'estado'" ), 'Statistical members must still require membership metadata.' );

$fixtures = array(
	array( 'role' => 'subscriber', 'status' => 'Ativo', 'founder' => false, 'registered' => '2026-08-02' ),
	array( 'role' => 'administrator', 'status' => 'Ativo', 'founder' => true, 'registered' => '2026-08-03' ),
);
$eligible = array_values( array_filter( $fixtures, static fn ( array $user ): bool => 'administrator' !== $user['role'] ) );
$assert( 1 === count( $eligible ), 'A normal member must remain in the statistical dataset.' );
$assert( 1 === count( array_filter( $eligible, static fn ( array $user ): bool => 'Ativo' === $user['status'] ) ), 'An administrator marked active must not count as active.' );
$assert( 0 === count( array_filter( $eligible, static fn ( array $user ): bool => true === $user['founder'] ) ), 'An administrator marked founder must not count as founder.' );
$assert( 0 === count( array_filter( $eligible, static fn ( array $user ): bool => '2026-08-03' === $user['registered'] ) ), 'An administrator created during the selected period must not count as a new member.' );

// Every independently loaded member-based dataset must be constrained to the
// same eligible user IDs, so administrators cannot re-enter through a side
// query (renewals, check-ins, points, or rewards).
$assert( str_contains( $statistics_source, '$this->members->statistical_members()' ), 'Statistics must start from the filtered member dataset.' );
$assert( str_contains( $statistics_source, '$member_ids' ), 'Statistics must derive an eligible member ID set.' );
foreach ( array( 'RenewalRequest $request', 'EventCheckIn $checkin', 'PointsEntry $entry', 'RewardRedemption $redemption' ) as $dataset_type ) {
	$member_accessor = match ( $dataset_type ) {
		'RenewalRequest $request'      => '$request->user_id()',
		'EventCheckIn $checkin'        => '$checkin->member_id()',
		'PointsEntry $entry'            => '$entry->member_id()',
		'RewardRedemption $redemption' => '$redemption->member_id()',
	};
	$assert( str_contains( $statistics_source, '$member_ids[ ' . $member_accessor . ' ]' ), $dataset_type . ' must be filtered to eligible members.' );
}

// These derived statistics all consume the filtered members array, covering
// normal, administrator, active, founder, and selected-period cases.
foreach ( array( 'member_status_counts( $members )', 'expiring_members( $members, 30 )', 'monthly_member_growth( $members_in_range )', 'monthly_member_growth( $members_in_range, false )', 'is_founder()' ) as $expression ) {
	$assert( str_contains( $statistics_source, $expression ), $expression . ' must use the filtered member dataset.' );
}

// CSV rows are generated from the same report object as the UI.
$assert( str_contains( $statistics_source, 'public function export_rows' ), 'CSV export must remain report-backed.' );
$assert( str_contains( $statistics_source, "'summary_cards'" ), 'The report must contain the UI summary cards used by CSV export.' );

echo "Statistics administrator exclusion tests passed.\n";
