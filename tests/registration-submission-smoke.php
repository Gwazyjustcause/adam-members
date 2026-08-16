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
$javascript  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/membership-forms.js' );

adam_registration_assert( str_contains( $forms, 'enctype="multipart/form-data"' ), 'Registration form must submit uploads as multipart data.' );
adam_registration_assert( str_contains( $forms, 'DONOTCACHEPAGE' ) && str_contains( $forms, 'nocache_headers' ), 'The public registration page must not cache stale nonces or success confirmations.' );
adam_registration_assert( str_contains( $forms, 'wp_verify_nonce( $registration_nonce, \'adam_membership_registration_form\' )' ), 'Registration must verify its CSRF nonce.' );
adam_registration_assert( str_contains( $forms, 'A sessão do formulário expirou ou é inválida.' ), 'Nonce failures must explain that the form session expired.' );
adam_registration_assert( ! str_contains( $forms, 'Não foi possível validar a submissão da inscrição.' ), 'The old generic registration validation message must not remain.' );
adam_registration_assert( str_contains( $forms, 'validate_nif' ) && str_contains( $registration, 'nif_exists' ), 'Registration must validate and deduplicate NIFs.' );
adam_registration_assert( str_contains( $forms, 'IdentificationValidator::validate' ) && str_contains( $identification, 'return new WP_Error' ), 'Citizen-card validation must remain active.' );
adam_registration_assert( str_contains( $forms, 'validate_email_field' ) && str_contains( $registration, 'is_email( $email )' ), 'Email validation must remain active.' );
adam_registration_assert( str_contains( $forms, 'validate_date_field' ), 'Date validation must remain active.' );
adam_registration_assert( str_contains( $forms, 'process_upload' ) && str_contains( $forms, 'media_handle_upload' ), 'Registration uploads must use the protected upload path.' );
adam_registration_assert( str_contains( $forms, 'Registration upload processing failed.' ) && str_contains( $forms, 'media_handle_upload' ) && str_contains( $forms, 'Não foi possível guardar o ficheiro' ), 'Upload exceptions and WP_Error results must return a safe form error.' );
adam_registration_assert( str_contains( $forms, 'cleanup_registration_uploads' ) && str_contains( $forms, 'cleanup_renewal_uploads' ), 'Failed upload pipelines must clean up media created before the failure.' );
adam_registration_assert( str_contains( $forms, 'Registration rendering recovered from an unexpected submission failure.' ), 'Unexpected submission failures must not produce an empty shortcode.' );
adam_registration_assert( str_contains( $forms, 'cleanup_pending_uploads' ) && str_contains( $forms, 'safe_registration_error_message' ), 'Unexpected failures must clean tracked uploads and hide internal service errors.' );
adam_registration_assert( str_contains( $forms, 'adam_membership_duplicate_nif' ) && str_contains( $forms, 'erro interno' ), 'Known validation errors remain specific while internal errors stay generic.' );
adam_registration_assert( str_contains( $forms, 'CONTENT_LENGTH' ) && str_contains( $forms, 'A submissão não chegou completa ao servidor.' ), 'Truncated or incomplete multipart submissions must show a clear retryable error instead of silently reloading.' );
adam_registration_assert( str_contains( $forms, 'request_exceeds_post_max_size' ) && str_contains( $forms, 'post_max_size' ), 'The server must reject a request body larger than PHP post_max_size before processing partial registration data.' );
adam_registration_assert( str_contains( $forms, 'registrationPostMaxBytes' ) && str_contains( $forms, 'registrationUploadMaxBytes' ), 'The registration form must receive the effective PHP request and per-file upload limits.' );
adam_registration_assert( str_contains( $javascript, 'validateRegistrationUploadSize' ) && str_contains( $javascript, 'new FormData( form )' ) && str_contains( $javascript, 'event.stopImmediatePropagation()' ), 'The browser must block oversized combined uploads before submission and prevent the NIF submit handler from retrying them.' );
adam_registration_assert( str_contains( $javascript, 'file.size > uploadMax' ) && str_contains( $javascript, 'estimatedBody > postMax' ), 'The browser guard must cover both individual upload_max_filesize and combined post_max_size boundaries.' );
adam_registration_assert( str_contains( $forms, "'.jpg,.jpeg,.png,.webp'" ) && str_contains( $javascript, 'unsupportedFormat' ) && str_contains( $javascript, 'registrationUploadFormatMessage' ), 'Unsupported mobile formats such as HEIC/HEIF must be rejected before the multipart POST.' );
adam_registration_assert( str_contains( $forms, '$this->registration->register(' ), 'A valid submission must reach RegistrationService.' );
adam_registration_assert( str_contains( $forms, 'Registration validation failed' ) && str_contains( $forms, 'error_count' ), 'Validation failures must be logged without submitted PII.' );
adam_registration_assert( str_contains( $forms, 'private Logger $logger' ) && str_contains( $forms, 'Logger $logger' ) && str_contains( $plugin, 'MembershipForms( $settings, $members, $registration_service, $renewals, $teams, $logger, $private_document_repository, $private_document_storage )' ), 'MembershipForms must receive the logger and private document services used by its validation and upload paths.' );
adam_registration_assert( str_contains( $nif, 'is_valid' ), 'NIF checksum validation must remain active.' );

echo "Registration submission smoke tests passed.\n";
