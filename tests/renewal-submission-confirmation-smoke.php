<?php
/** Regression contract for the expired-member renewal confirmation flow. */

declare(strict_types=1);

function adam_renewal_confirmation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$root         = dirname( __DIR__ );
$forms        = (string) file_get_contents( $root . '/src/Form/MembershipForms.php' );
$renewal      = (string) file_get_contents( $root . '/src/Member/RenewalService.php' );
$success_pos  = strpos( $forms, "if ( 'renewal' === sanitize_key( wp_unslash( \$_GET['adam_form_success'] ?? '' ) ) )" );
$pending_pos  = strpos( $forms, 'if ( $member->isRenewalPending() )' );
$blocked_pos  = strpos( $forms, 'if ( $member->isPending() || $member->isRejected() )' );

adam_renewal_confirmation_assert( false !== $success_pos, 'Renewal success redirects must have a dedicated confirmation branch.' );
adam_renewal_confirmation_assert( false !== $pending_pos && false !== $blocked_pos && $pending_pos < $blocked_pos, 'Renewal-pending must be handled before genuinely unavailable states.' );
adam_renewal_confirmation_assert( false !== $success_pos && false !== $pending_pos && $success_pos < $pending_pos, 'A successful renewal must render before the member becomes renewal-pending.' );
adam_renewal_confirmation_assert( str_contains( $forms, 'Prazo estimado de resposta da ADAM: 2–7 dias.' ), 'Renewal confirmation must include the expected ADAM review time.' );
adam_renewal_confirmation_assert( str_contains( $forms, 'Não é necessário submeter um novo pedido.' ), 'An existing pending renewal must explain that another request is unnecessary.' );
adam_renewal_confirmation_assert( str_contains( $renewal, 'pending_for_user( $member->user_id() )' ) && str_contains( $renewal, 'STATUS_RENEWAL_PENDING' ), 'Renewal submission must reject duplicates and transition the member to renewal-pending.' );
adam_renewal_confirmation_assert( str_contains( $forms, "return \$this->render_submission_notice( 'renewal', array() );" ), 'The success confirmation must return before submission handling can run again on refresh.' );

echo "Renewal submission confirmation smoke tests passed.\n";
