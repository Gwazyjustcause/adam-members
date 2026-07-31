<?php
/**
 * Administrator registration preview smoke tests.
 *
 * @package AdamMembership\Tests
 */

declare(strict_types=1);

$GLOBALS['adam_test_logged_in']  = false;
$GLOBALS['adam_test_capability'] = false;

/** Test whether the simulated user is logged in. */
function is_user_logged_in(): bool {
	return (bool) $GLOBALS['adam_test_logged_in'];
}

/**
 * Test a simulated WordPress capability.
 *
 * @param string $capability Capability name.
 */
function current_user_can( string $capability ): bool {
	return 'manage_options' === $capability && (bool) $GLOBALS['adam_test_capability'];
}

require_once dirname( __DIR__ ) . '/src/Member/AdminPreview.php';

use AdamMembership\Member\AdminPreview;

/**
 * Assert registration preview behaviour.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 */
function adam_registration_preview_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

adam_registration_preview_assert( ! AdminPreview::is_available(), 'Visitors must not receive the registration preview bypass.' );

$GLOBALS['adam_test_logged_in'] = true;
adam_registration_preview_assert( ! AdminPreview::is_available(), 'Logged-in users without manage_options must not receive the registration preview bypass.' );

$GLOBALS['adam_test_capability'] = true;
adam_registration_preview_assert( AdminPreview::is_available(), 'Administrators must receive the registration preview bypass.' );

$root       = dirname( __DIR__ );
$managed    = (string) file_get_contents( $root . '/src/Core/ManagedPages.php' );
$membership = (string) file_get_contents( $root . '/src/Form/MembershipForms.php' );

adam_registration_preview_assert(
	str_contains( $managed, "'registration'       => static fn (): bool => AdminPreview::is_available()" ),
	'The shared registration-page protection does not expose the administrator bypass.'
);
adam_registration_preview_assert(
	str_contains( $membership, '! AdminPreview::is_available() && is_user_logged_in()' ),
	'The logged-in member guard still hides the applicant form from administrators.'
);

echo "Administrator registration preview smoke tests passed.\n";
