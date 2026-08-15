<?php
/** Static contract checks for the initial account activation flow. */

declare(strict_types=1);

$root    = dirname( __DIR__ );
$setup   = (string) file_get_contents( $root . '/src/Member/AccountSetup.php' );
$account = (string) file_get_contents( $root . '/src/Member/Account.php' );
$reset   = (string) file_get_contents( $root . '/src/Member/PasswordReset.php' );
$member_area = (string) file_get_contents( $root . '/src/Member/MemberArea.php' );
$forms   = (string) file_get_contents( $root . '/src/Form/MembershipForms.php' );
$script  = (string) file_get_contents( $root . '/assets/js/password-strength.js' );
$assert  = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( str_contains( $setup, 'data-adam-account-setup' ), 'Activation form must identify its scoped client validation flow.' );
$assert( str_contains( $setup, 'adam-account-setup-feedback' ), 'Activation form must include visible client validation feedback.' );
$assert( str_contains( $setup, 'validate_token( $user, $token' ), 'Activation submission must revalidate the one-time token on the server.' );
$assert( str_contains( $setup, 'username_exists( $username )' ), 'Activation submission must validate existing WordPress usernames.' );
$assert( str_contains( $setup, 'username_owner_id( $username )' ), 'Activation submission must validate reserved ADAM username aliases.' );
$assert( str_contains( $setup, 'strlen( $password ) < 8' ), 'Activation submission must validate password length on the server.' );
$assert( str_contains( $setup, 'wp_check_password' ), 'Activation submission must reject reuse of the current password.' );
$assert( str_contains( $script, 'const isStrongEnough = meetsPasswordRules' ), 'Password enablement must use the visible password rules.' );
$assert( ! str_contains( $script, 'score >= 3' ), 'Password enablement must not add a hidden zxcvbn score requirement.' );
$assert( str_contains( $script, 'accountUsername.addEventListener(\'input\'' ), 'Activation username changes must refresh enablement state.' );
$assert( str_contains( $script, 'accountFeedback.textContent' ), 'Every client-side activation blocker must be presented visibly.' );
$assert( substr_count( $account, "preg_match( '/[a-z]/'" ) > 0, 'Password change must enforce the displayed lowercase rule on the server.' );
$assert( substr_count( $reset, "preg_match( '/[a-z]/'" ) > 0, 'Password reset must enforce the displayed lowercase rule on the server.' );
$assert( str_contains( $script, 'Confirme a palavra-passe.' ), 'Empty password confirmation must have visible feedback.' );
$assert( str_contains( $account, 'Os novos emails não coincidem.' ) && str_contains( $account, 'Já existe uma conta associada a este email.' ), 'Email-change failures must explain the actionable cause.' );
$assert( str_contains( $member_area, 'echo wp_kses_post( $message );' ), 'APD correction failures must be rendered back to the user.' );
$assert( str_contains( $forms, "echo \$config['required'] ? 'required' : '';" ), 'Configured required fields must communicate required state to the browser.' );

echo "Account setup validation smoke tests passed.\n";
