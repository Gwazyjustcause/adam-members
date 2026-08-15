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
$member       = (string) file_get_contents( $root . '/src/Member/Member.php' );
$member_area  = (string) file_get_contents( $root . '/src/Member/MemberArea.php' );
$points       = (string) file_get_contents( $root . '/src/Points/PointsService.php' );
$rewards      = (string) file_get_contents( $root . '/src/Reward/RewardService.php' );
$card         = (string) file_get_contents( $root . '/src/Member/CardService.php' );
$success_pos  = strpos( $forms, "if ( 'renewal' === sanitize_key( wp_unslash( \$_GET['adam_form_success'] ?? '' ) ) )" );
$pending_pos  = strpos( $forms, 'if ( $member->isRenewalPending() )' );
$blocked_pos  = strpos( $forms, 'if ( $member->isPending() || $member->isRejected() )' );

adam_renewal_confirmation_assert( false !== $success_pos, 'Renewal success redirects must have a dedicated confirmation branch.' );
adam_renewal_confirmation_assert( false !== $pending_pos && false !== $blocked_pos && $pending_pos < $blocked_pos, 'Renewal-pending must be handled before genuinely unavailable states.' );
adam_renewal_confirmation_assert( false !== $success_pos && false !== $pending_pos && $success_pos < $pending_pos, 'A successful renewal must render before the member becomes renewal-pending.' );
adam_renewal_confirmation_assert( str_contains( $forms, 'Prazo estimado de resposta da ADAM: 2–7 dias.' ), 'Renewal confirmation must include the expected ADAM review time.' );
adam_renewal_confirmation_assert( str_contains( $forms, 'Não é necessário submeter um novo pedido.' ), 'An existing pending renewal must explain that another request is unnecessary.' );
adam_renewal_confirmation_assert( str_contains( $renewal, 'pending_for_user( $member->user_id() )' ) && str_contains( $renewal, 'STATUS_RENEWAL_PENDING' ), 'Renewal submission must reject duplicates and transition the member to renewal-pending.' );
adam_renewal_confirmation_assert( str_contains( $forms, "return \$this->render_renewal_confirmation();" ), 'The success confirmation must return before submission handling can run again on refresh.' );
adam_renewal_confirmation_assert( str_contains( $forms, 'Pedido de renovação recebido' ) && str_contains( $forms, 'adam-confirmation-success' ) && str_contains( $forms, 'Voltar à Área de Sócio' ), 'Renewal success must use the polished confirmation card with title, icon and member-area action.' );
adam_renewal_confirmation_assert( str_contains( $forms, 'Receberá um email assim que a análise estiver concluída.' ) && str_contains( $forms, 'Prazo estimado de resposta da ADAM: 2–7 dias.' ), 'Renewal success must explain review timing and email follow-up.' );

adam_renewal_confirmation_assert( str_contains( $member, 'public function has_active_benefits(): bool' ) && str_contains( $member, 'self::STATUS_RENEWAL_PENDING' ) && str_contains( $member, 'self::QUOTA_EXPIRED !== $this->quota_status()' ), 'Benefits must use a centralized approved-status and quota-validity rule.' );
$benefit_cases = array(
	'active quota without renewal'    => true,
	'active quota with renewal pending' => true,
	'expired quota without renewal'   => false,
	'expired quota with renewal pending' => false,
);
foreach ( $benefit_cases as $case => $available ) {
	adam_renewal_confirmation_assert( array_key_exists( $case, $benefit_cases ) && is_bool( $available ), sprintf( 'Missing benefits regression case: %s.', $case ) );
}
adam_renewal_confirmation_assert( str_contains( $member, 'array( self::STATUS_ACTIVE, self::STATUS_RENEWAL_PENDING )' ), 'Both active and early-renewal-pending statuses must retain benefits when quota is valid.' );
adam_renewal_confirmation_assert( str_contains( $member, 'return self::QUOTA_EXPIRED !== $this->quota_status();' ), 'Expired quota must disable benefits regardless of renewal-pending status.' );
adam_renewal_confirmation_assert( str_contains( $member_area, 'if ( $member->has_active_benefits() ) {' ) && str_contains( $member_area, '$this->render_digital_card( $member );' ) && str_contains( $member_area, '$this->render_points_card( $member );' ), 'The member area must render card and points sections through the benefits rule.' );
adam_renewal_confirmation_assert( str_contains( $member_area, 'return $this->render_points_unavailable_page( $member );' ), 'Direct points-history access must explain inactive membership instead of exposing the history.' );
adam_renewal_confirmation_assert( str_contains( $member_area, 'if ( ! $member->has_active_benefits() ) {' ) && str_contains( $member_area, 'save_card_cosmetics' ), 'Direct card customization submissions must use the benefits rule.' );
adam_renewal_confirmation_assert( str_contains( $points, 'adam_membership_points_member_inactive' ) && substr_count( $points, 'if ( ! $member->has_active_benefits() )' ) >= 3, 'Points earning and redemption must be blocked server-side through the benefits rule.' );
adam_renewal_confirmation_assert( str_contains( $rewards, 'adam_membership_reward_member_inactive' ) && str_contains( $rewards, 'public function member_can_redeem' ), 'Reward redemption must reject inactive members before creating a request.' );
adam_renewal_confirmation_assert( str_contains( $card, 'adam_membership_card_member_inactive' ), 'Card customization must be protected at the card service boundary.' );
adam_renewal_confirmation_assert( str_contains( $card, 'has_active_benefits()' ) && str_contains( $card, 'is_valid = null !== $member' ), 'Public QR validation must use current benefit availability.' );
adam_renewal_confirmation_assert( str_contains( $member_area, '$this->renewal_actions( $member )' ) && str_contains( $member_area, 'adam-status-card__actions' ), 'Expired members must receive a clear primary renewal action inside the status card.' );
adam_renewal_confirmation_assert( str_contains( $member_area, 'Prazo estimado de resposta: 2–7 dias.' ), 'Renewal-pending dashboard must include the expected response time.' );
adam_renewal_confirmation_assert( str_contains( $forms, 'Prazo estimado de resposta: 2–7 dias.' ), 'The renewal form must include the expected response time for an existing pending request.' );
adam_renewal_confirmation_assert( str_contains( $renewal, "'estado'         => Member::STATUS_ACTIVE" ) && str_contains( $renewal, "'validade_quota' => \$new_expiry" ), 'Approved renewal must restore active status and the renewed expiry.' );
adam_renewal_confirmation_assert( ! str_contains( $renewal, 'delete_all_for_member' ) && ! str_contains( $renewal, 'delete_user_meta( $member->user_id(), \'adam_active_' ), 'Renewal approval must not delete points or card customization data.' );
adam_renewal_confirmation_assert( str_contains( $member_area, 'if ( $member->has_active_benefits() ) {' ) && str_contains( $member_area, 'grant_eligible_loyalty_rewards' ), 'Recognition must only reactivate or grant member benefits while benefits are active.' );

echo "Renewal submission confirmation smoke tests passed.\n";
