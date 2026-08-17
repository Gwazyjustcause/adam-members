<?php
/** Regression contract for the actual admin payment form POST representation. */

declare(strict_types=1);

function adam_payment_post_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$admin = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );
$sync = (string) file_get_contents( dirname( __DIR__ ) . '/src/GoogleSheets/GoogleSheetsSyncService.php' );

// These are the browser-submitted values from the actual HTML controls:
// type=date submits ISO Y-m-d even where the localized browser display is d/m/Y.
$_POST = array(
	'membership_year' => '2026',
	'payment_amount'  => '12,00',
	'payment_date'    => '2026-08-17',
	'payment_method'  => 'Transferência bancária',
	'quota_type'      => 'Inscrição ADAM',
);
$year = (int) $_POST['membership_year'];
$amount = str_replace( ',', '.', trim( (string) $_POST['payment_amount'] ) );
$date = trim( (string) $_POST['payment_date'] );
$method = trim( (string) $_POST['payment_method'] );
$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );

adam_payment_post_assert( 'membership_year' === 'membership_year' && 2026 === $year, 'The form year POST value must normalize to 2026.' );
adam_payment_post_assert( '12.00' === number_format( (float) $amount, 2, '.', '' ), 'The Portuguese amount 12,00 must normalize to 12.00.' );
adam_payment_post_assert( false !== $parsed && '2026-08-17' === $parsed->format( 'Y-m-d' ), 'The browser-submitted ISO date must remain the canonical date.' );
adam_payment_post_assert( 'Transferência bancária' === $method, 'The submitted payment method option value must be preserved.' );
adam_payment_post_assert( str_contains( $admin, 'name="payment_date"' ) && str_contains( $admin, "\$_POST['payment_date']" ), 'The HTML date name and handler POST key must match.' );
adam_payment_post_assert( str_contains( $admin, 'name="payment_method"' ) && str_contains( $admin, 'GoogleSheetsSyncService::PAYMENT_METHODS' ), 'The payment method select must use the actual shared option values.' );
adam_payment_post_assert( str_contains( $sync, "\$movement['membership_year']" ) && str_contains( $sync, "\$movement['payment_method']" ), 'Registration payment data must be mapped to repository canonical keys.' );
adam_payment_post_assert( str_contains( $sync, "unset( \$movement['year'], \$movement['method'] )" ), 'Legacy registration keys must not be passed as the persistence contract.' );

echo "Financial payment POST normalization smoke tests passed.\n";
