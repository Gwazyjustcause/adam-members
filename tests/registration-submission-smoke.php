<?php
/** Static contract checks for the native New Member submission flow. */

declare(strict_types=1);

function adam_registration_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$forms       = (string) file_get_contents( dirname( __DIR__ ) . '/src/Form/MembershipForms.php' );
$registration = (string) file_get_contents( dirname( __DIR__ ) . '/src/Form/RegistrationService.php' );
$identification = (string) file_get_contents( dirname( __DIR__ ) . '/src/Form/IdentificationValidator.php' );
$nif         = (string) file_get_contents( dirname( __DIR__ ) . '/src/Member/NifValidator.php' );
$plugin      = (string) file_get_contents( dirname( __DIR__ ) . '/src/Core/Plugin.php' );

adam_registration_assert( str_contains( $forms, 'enctype="multipart/form-data"' ), 'Registration form must submit uploads as multipart data.' );
adam_registration_assert( str_contains( $forms, 'wp_verify_nonce( $registration_nonce, \'adam_membership_registration_form\' )' ), 'Registration must verify its CSRF nonce.' );
adam_registration_assert( str_contains( $forms, 'A sessão do formulário expirou ou é inválida.' ), 'Nonce failures must explain that the form session expired.' );
adam_registration_assert( ! str_contains( $forms, 'Não foi possível validar a submissão da inscrição.' ), 'The old generic registration validation message must not remain.' );
adam_registration_assert( str_contains( $forms, 'validate_nif' ) && str_contains( $registration, 'nif_exists' ), 'Registration must validate and deduplicate NIFs.' );
adam_registration_assert( str_contains( $forms, 'IdentificationValidator::validate' ) && str_contains( $identification, 'return new WP_Error' ), 'Citizen-card validation must remain active.' );
adam_registration_assert( str_contains( $forms, 'validate_email_field' ) && str_contains( $registration, 'is_email( $email )' ), 'Email validation must remain active.' );
adam_registration_assert( str_contains( $forms, 'validate_date_field' ), 'Date validation must remain active.' );
adam_registration_assert( str_contains( $forms, 'process_upload' ) && str_contains( $forms, 'media_handle_upload' ), 'Registration uploads must use the protected upload path.' );
adam_registration_assert( str_contains( $forms, '$this->registration->register(' ), 'A valid submission must reach RegistrationService.' );
adam_registration_assert( str_contains( $forms, 'Registration validation failed' ) && str_contains( $forms, 'error_count' ), 'Validation failures must be logged without submitted PII.' );
adam_registration_assert( str_contains( $forms, 'private Logger $logger' ) && str_contains( $forms, 'Logger $logger' ) && str_contains( $plugin, 'MembershipForms( $settings, $members, $registration_service, $renewals, $teams, $logger )' ), 'MembershipForms must receive the logger used by its validation error path.' );
adam_registration_assert( str_contains( $nif, 'is_valid' ), 'NIF checksum validation must remain active.' );

echo "Registration submission smoke tests passed.\n";
