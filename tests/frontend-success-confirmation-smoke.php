<?php
/** Regression contract for polished, refresh-safe frontend success states. */

declare(strict_types=1);

$root = dirname( __DIR__ );

function adam_success_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
	}
}

$forms       = (string) file_get_contents( $root . '/src/Form/MembershipForms.php' );
$member_area = (string) file_get_contents( $root . '/src/Member/MemberArea.php' );
$setup       = (string) file_get_contents( $root . '/src/Member/AccountSetup.php' );
$reset       = (string) file_get_contents( $root . '/src/Member/PasswordReset.php' );
$email       = (string) file_get_contents( $root . '/src/Member/EmailChangeConfirmation.php' );
$account     = (string) file_get_contents( $root . '/src/Member/Account.php' );
$recovery    = (string) file_get_contents( $root . '/src/Member/PasswordRecovery.php' );

// Every standalone account/member confirmation uses the shared ADAM confirmation treatment.
foreach ( array( $member_area, $setup, $reset, $email ) as $source ) {
	adam_success_assert( str_contains( $source, 'adam-confirmation-icon' ), 'Standalone success state is missing the shared success icon.' );
}
adam_success_assert( str_contains( $forms, 'adam-confirmation-success' ), 'Public registration/renewal confirmations must use the existing success treatment.' );
adam_success_assert( substr_count( $member_area, 'render_correction_confirmation_page(' ) >= 3, 'Both correction paths must use the shared correction confirmation renderer.' );
adam_success_assert( str_contains( $member_area, 'adam-confirmation-page' ) && str_contains( $member_area, 'Voltar à Área de Sócio' ), 'Correction confirmation must be a complete member-area card with a next action.' );

// Registration and renewal success are redirect/query states: rendering them must happen
// before any current-status check and must not process POST again on refresh.
$renewal_success = strpos( $forms, "if ( 'renewal' === sanitize_key( wp_unslash( \$_GET['adam_form_success'] ?? '' ) ) )" );
$renewal_pending = strpos( $forms, 'if ( $member->isRenewalPending() )' );
adam_success_assert( false !== $renewal_success && false !== $renewal_pending && $renewal_success < $renewal_pending, 'Renewal success must render before pending/unavailable checks.' );
adam_success_assert( str_contains( $forms, 'return $this->render_renewal_confirmation();' ) && str_contains( $forms, 'adam-confirmation-success' ), 'Renewal success must be a read-only polished confirmation.' );
adam_success_assert( str_contains( $forms, 'adam_form_success' ) && str_contains( $forms, 'redirect_after_success' ), 'Registration and renewal must use redirect-based success states to avoid refresh resubmission.' );

// These actions intentionally remain inline on their full forms; they are not empty pages.
adam_success_assert( str_contains( $account, "'success'," ), 'Account changes must retain visible inline success feedback.' );
adam_success_assert( str_contains( $recovery, 'notice_markup' ) && str_contains( $recovery, 'success' ), 'Password recovery must retain visible success feedback on the recovery form.' );

echo "Frontend success confirmation smoke tests passed.\n";
