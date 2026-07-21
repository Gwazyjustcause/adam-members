<?php
/**
 * Lightweight smoke tests for communication preferences and legacy notices.
 *
 * @package AdamMembership\Tests
 */

declare(strict_types=1);

$GLOBALS['adam_test_user_meta']       = array();
$GLOBALS['adam_test_future_category'] = false;

/**
 * Translation stub.
 *
 * @param string $text   Source text.
 * @param string $domain Text domain.
 */
function __( string $text, string $domain = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $text;
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
 * Key sanitizer stub.
 *
 * @param string $value Raw value.
 */
function sanitize_key( string $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
}

/**
 * Title sanitizer stub.
 *
 * @param string $value Raw value.
 */
function sanitize_title( string $value ): string {
	$ascii = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
	$value = false === $ascii ? $value : $ascii;
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ) ?? '', '-' );
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
 * Positive integer sanitizer stub.
 *
 * @param mixed $value Raw value.
 */
function absint( mixed $value ): int {
	return abs( (int) $value );
}

/**
 * Filter stub with an optional future category.
 *
 * @param string $hook  Filter hook.
 * @param mixed  $value Filtered value.
 */
function apply_filters( string $hook, mixed $value ): mixed {
	if ( 'adam_membership_communication_categories' === $hook && ! empty( $GLOBALS['adam_test_future_category'] ) ) {
		$value['novidades'] = array(
			'label' => 'Novidades',
			'type'  => 'optional',
		);
	}

	return $value;
}

/**
 * User-meta read stub.
 *
 * @param int    $user_id User ID.
 * @param string $key     Meta key.
 * @param bool   $single  Whether to return one value.
 */
function get_user_meta( int $user_id, string $key, bool $single ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $GLOBALS['adam_test_user_meta'][ $user_id ][ $key ] ?? '';
}

/**
 * User-meta write stub.
 *
 * @param int    $user_id User ID.
 * @param string $key     Meta key.
 * @param mixed  $value   Meta value.
 */
function update_user_meta( int $user_id, string $key, mixed $value ): bool {
	$GLOBALS['adam_test_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

/**
 * Assert a smoke-test condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 * @throws RuntimeException When the condition fails.
 */
function adam_test_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only exception, not browser output.
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/src/Communication/CommunicationCategoryRegistry.php';
require_once dirname( __DIR__ ) . '/src/Communication/CommunicationPreferences.php';
require_once dirname( __DIR__ ) . '/src/Announcement/Announcement.php';

use AdamMembership\Announcement\Announcement;
use AdamMembership\Communication\CommunicationCategoryRegistry;
use AdamMembership\Communication\CommunicationPreferences;

$registry    = new CommunicationCategoryRegistry();
$preferences = new CommunicationPreferences( $registry );

adam_test_assert( $registry->is_optional( 'Eventos' ), 'Eventos should be optional.' );
adam_test_assert( ! $registry->is_optional( 'Quotas' ), 'Quotas should be mandatory.' );
adam_test_assert( ! $registry->is_optional( 'Legacy category' ), 'Unknown legacy categories should fail safe as mandatory.' );

$defaults = $preferences->subscriptions( 42 );
adam_test_assert( ! in_array( false, $defaults, true ), 'Members without metadata should be subscribed to every optional category.' );

$preferences->save_subscriptions( 42, CommunicationPreferences::CHANNEL_EMAIL, array( 'eventos' ) );
adam_test_assert( $preferences->is_subscribed( 42, 'Eventos' ), 'An enabled optional category should remain subscribed.' );
adam_test_assert( ! $preferences->is_subscribed( 42, 'Website' ), 'An explicitly disabled optional category should be suppressed.' );
adam_test_assert( $preferences->is_subscribed( 42, 'Quotas' ), 'Mandatory categories should ignore opt-outs.' );

$GLOBALS['adam_test_future_category'] = true;
adam_test_assert( $preferences->is_subscribed( 42, 'Novidades' ), 'New optional categories should default to subscribed without migration.' );

$legacy = new Announcement(
	array(
		'send_email' => true,
	)
);
adam_test_assert( $legacy->show_in_member_area(), 'Legacy notices should remain visible in the member area.' );
adam_test_assert( $legacy->show_on_member_homepage(), 'Legacy notices should remain visible on the member-area homepage.' );
adam_test_assert( $legacy->send_email(), 'Legacy email flags should remain enabled.' );
adam_test_assert( Announcement::EMAIL_AUDIENCE_LEGACY === $legacy->email_audience(), 'Legacy notices should keep their original audience behavior.' );

$email_only = new Announcement(
	array(
		'delivery_channels'       => array( Announcement::DELIVERY_EMAIL ),
		'show_on_member_homepage' => false,
		'email_audience'          => Announcement::EMAIL_AUDIENCE_CATEGORY_SUBSCRIBERS,
	)
);
adam_test_assert( ! $email_only->show_in_member_area(), 'Email delivery should be independent from member-area visibility.' );
adam_test_assert( $email_only->send_email(), 'The email delivery channel should be enabled independently.' );

echo "Communication preference smoke tests passed.\n";
