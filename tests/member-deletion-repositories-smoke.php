<?php
/**
 * Smoke tests for permanent member data cleanup repositories.
 *
 * @package AdamMembership\Tests
 */

declare(strict_types=1);

$GLOBALS['adam_member_delete_options'] = array(
	'adam_membership_renewal_requests'    => array(
		1 => array(
			'id'      => 1,
			'user_id' => 42,
		),
		2 => array(
			'id'      => 2,
			'user_id' => 77,
		),
	),
	'adam_membership_history_entries'     => array(
		1 => array(
			'id'        => 1,
			'member_id' => 42,
		),
		2 => array(
			'id'        => 2,
			'member_id' => 77,
		),
	),
	'adam_membership_points_entries'      => array(
		1 => array(
			'id'        => 1,
			'member_id' => 42,
		),
		2 => array(
			'id'        => 2,
			'member_id' => 77,
		),
	),
	'adam_membership_reward_redemptions'  => array(
		1 => array(
			'id'        => 1,
			'member_id' => 42,
		),
		2 => array(
			'id'        => 2,
			'member_id' => 77,
		),
	),
	'adam_membership_event_registrations' => array(
		1 => array(
			'id'        => 1,
			'member_id' => 42,
		),
		2 => array(
			'id'        => 2,
			'member_id' => 77,
		),
	),
	'adam_membership_event_checkins'      => array(
		1 => array(
			'id'        => 1,
			'member_id' => 42,
		),
		2 => array(
			'id'        => 2,
			'member_id' => 77,
		),
	),
	'adam_membership_announcements'       => array(
		1 => array(
			'id'               => 1,
			'email_member_ids' => array( 42, 77 ),
		),
		2 => array(
			'id'               => 2,
			'email_member_ids' => array( 77 ),
		),
	),
);

/**
 * Positive integer sanitizer stub.
 *
 * @param mixed $value Raw value.
 */
function absint( mixed $value ): int {
	return abs( (int) $value );
}

/**
 * Text sanitizer stub.
 *
 * @param string $value Raw value.
 */
function sanitize_text_field( string $value ): string {
	return trim( $value );
}

/**
 * Textarea sanitizer stub.
 *
 * @param string $value Raw value.
 */
function sanitize_textarea_field( string $value ): string {
	return trim( $value );
}

/**
 * Email sanitizer stub.
 *
 * @param string $value Raw value.
 */
function sanitize_email( string $value ): string {
	return trim( $value );
}

/**
 * Key sanitizer stub.
 *
 * @param string $value Raw value.
 */
function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ?? '' );
}

/**
 * URL sanitizer stub.
 *
 * @param string $value Raw value.
 */
function esc_url_raw( string $value ): string {
	return $value;
}

/**
 * Read an in-memory option.
 *
 * @param string $key     Option key.
 * @param mixed  $fallback Default value.
 */
function get_option( string $key, mixed $fallback = false ): mixed {
	return $GLOBALS['adam_member_delete_options'][ $key ] ?? $fallback;
}

/**
 * Update an in-memory option.
 *
 * @param string $key      Option key.
 * @param mixed  $value    Option value.
 * @param bool   $autoload Autoload flag.
 */
function update_option( string $key, mixed $value, bool $autoload = true ): bool {
	unset( $autoload );
	$GLOBALS['adam_member_delete_options'][ $key ] = $value;

	return true;
}

/**
 * Assert a smoke-test condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @throws RuntimeException When the condition fails.
 */
function adam_member_delete_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only exception, not browser output.
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/src/Member/RenewalRequest.php';
require_once dirname( __DIR__ ) . '/src/Member/RenewalRepository.php';
require_once dirname( __DIR__ ) . '/src/Member/HistoryEntry.php';
require_once dirname( __DIR__ ) . '/src/Member/HistoryRepository.php';
require_once dirname( __DIR__ ) . '/src/Points/PointsEntry.php';
require_once dirname( __DIR__ ) . '/src/Points/PointsRepository.php';
require_once dirname( __DIR__ ) . '/src/Reward/Reward.php';
require_once dirname( __DIR__ ) . '/src/Reward/RewardRedemption.php';
require_once dirname( __DIR__ ) . '/src/Reward/RewardRepository.php';
require_once dirname( __DIR__ ) . '/src/Event/Event.php';
require_once dirname( __DIR__ ) . '/src/Event/EventRegistration.php';
require_once dirname( __DIR__ ) . '/src/Event/EventCheckIn.php';
require_once dirname( __DIR__ ) . '/src/Event/EventRepository.php';
require_once dirname( __DIR__ ) . '/src/Announcement/Announcement.php';
require_once dirname( __DIR__ ) . '/src/Announcement/AnnouncementRepository.php';

$renewals      = new AdamMembership\Member\RenewalRepository();
$history       = new AdamMembership\Member\HistoryRepository();
$points        = new AdamMembership\Points\PointsRepository();
$rewards       = new AdamMembership\Reward\RewardRepository();
$events        = new AdamMembership\Event\EventRepository();
$announcements = new AdamMembership\Announcement\AnnouncementRepository();

adam_member_delete_assert( 1 === $renewals->delete_for_user( 42 ), 'Renewal cleanup count is incorrect.' );
adam_member_delete_assert( 1 === $history->delete_for_member( 42 ), 'History cleanup count is incorrect.' );
adam_member_delete_assert( 1 === $points->delete_for_member( 42 ), 'Points cleanup count is incorrect.' );
adam_member_delete_assert( 1 === $rewards->delete_redemptions( array( 'member_id' => 42 ) ), 'Reward cleanup count is incorrect.' );
adam_member_delete_assert( 2 === $events->delete_member_interactions( 42 ), 'Event cleanup count is incorrect.' );
adam_member_delete_assert( 1 === $announcements->remove_member_references( 42 ), 'Announcement cleanup count is incorrect.' );

foreach (
	array(
		'adam_membership_renewal_requests',
		'adam_membership_history_entries',
		'adam_membership_points_entries',
		'adam_membership_reward_redemptions',
		'adam_membership_event_registrations',
		'adam_membership_event_checkins',
	) as $option_key
) {
	$records = $GLOBALS['adam_member_delete_options'][ $option_key ];
	adam_member_delete_assert( 1 === count( $records ), $option_key . ' should retain one unrelated record.' );
	adam_member_delete_assert( 77 === absint( reset( $records )['member_id'] ?? reset( $records )['user_id'] ?? 0 ), $option_key . ' retained the wrong record.' );
}

$announcement_records = $GLOBALS['adam_member_delete_options']['adam_membership_announcements'];
adam_member_delete_assert( array( 77 ) === $announcement_records[1]['email_member_ids'], 'Deleted member should be removed from announcement recipients.' );
adam_member_delete_assert( array( 77 ) === $announcement_records[2]['email_member_ids'], 'Unrelated announcement recipients should remain unchanged.' );

echo "Permanent member deletion repository smoke tests passed.\n";
