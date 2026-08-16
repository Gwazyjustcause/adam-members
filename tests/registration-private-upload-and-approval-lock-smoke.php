<?php
/** Regression contracts for sensitive registration uploads and request approvals. */

declare(strict_types=1);

function adam_launch_safety_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); }
}

$forms = (string) file_get_contents( dirname( __DIR__ ) . '/src/Form/MembershipForms.php' );
$storage = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/PrivateDocumentStorage.php' );
$repository = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/PrivateDocumentRepository.php' );
$download = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/PrivateDocumentDownloadController.php' );
$renewal = (string) file_get_contents( dirname( __DIR__ ) . '/src/Member/RenewalService.php' );
$apd = (string) file_get_contents( dirname( __DIR__ ) . '/src/Member/ApdAssociationService.php' );
$member_area = (string) file_get_contents( dirname( __DIR__ ) . '/src/Member/MemberArea.php' );
$admin = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );
$history = (string) file_get_contents( dirname( __DIR__ ) . '/src/Document/MemberDocumentHistoryService.php' );

adam_launch_safety_assert( str_contains( $forms, 'process_private_upload' ) && str_contains( $forms, 'create_from_upload' ), 'Sensitive registration proofs use the existing private-document repository.' );
adam_launch_safety_assert( str_contains( $forms, "'registration' === " . '$form' ) && str_contains( $forms, "'active_key' => " . '$request_uuid' . " . ':' . sanitize_key( " . '$field' . ' )' ), 'Custom registration file fields use private storage with field-specific identity.' );
adam_launch_safety_assert( str_contains( $forms, 'process_upload( \'registration\', \'profile_photo\'' ), 'Profile photos retain the existing Media Library path.' );
adam_launch_safety_assert( str_contains( $member_area, 'replace_from_upload' ) && str_contains( $member_area, "'active_key' => " . '$reference' . " . ':' . sanitize_key( " . '$key' . ' )' ), 'Registration correction replacement uses the same private field identity.' );
adam_launch_safety_assert( str_contains( $member_area, "'profile_photo' === " . '$key' ) && str_contains( $member_area, 'media_handle_upload' ), 'Registration correction keeps profile photos public while custom files remain private.' );
adam_launch_safety_assert( str_contains( $admin, "str_starts_with( " . '$value' . ", 'private:' )" ) && str_contains( $admin, 'private_document_download_url' ), 'Administration resolves private custom values to protected download links.' );
adam_launch_safety_assert( str_contains( $history, 'private_item' ) && str_contains( $history, 'document_type' ), 'Private custom documents remain distinguishable in document history.' );
adam_launch_safety_assert( str_contains( $repository, "active_key = %s" ) && str_contains( $repository, 'superseded_by' ), 'Private replacements preserve the active field association and history.' );
adam_launch_safety_assert( ! str_contains( substr( $forms, strpos( $forms, "'registration', 'payment_receipt'" ) ?: 0, 250 ), 'media_handle_upload' ), 'New sensitive registration proofs do not use Media Library upload handling.' );
adam_launch_safety_assert( str_contains( $storage, 'getimagesize' ) && str_contains( $storage, 'application/pdf' ), 'Private storage validates supported PDF and image signatures.' );
adam_launch_safety_assert( str_contains( $repository, 'delete_identifier' ) && str_contains( $repository, 'create_from_upload' ), 'Private repository cleanup remains available when metadata persistence fails.' );
adam_launch_safety_assert( str_contains( $download, 'current_user_can' ) && str_contains( $download, 'Cache-Control: no-store, private' ), 'Private downloads remain capability- and no-store-protected.' );
adam_launch_safety_assert( str_contains( $renewal, 'acquire_request_lock' ) && str_contains( $renewal, 'STATUS_APPROVED === $request->status()' ), 'Renewal approval is request-locked and idempotent.' );
adam_launch_safety_assert( str_contains( $apd, 'acquire_approval_lock' ) && str_contains( $apd, 'STATUS_CONFIRMED === $request->status()' ), 'APD confirmation is request-locked and idempotent.' );

echo "Registration private-upload and approval-lock smoke tests passed.\n";
