<?php
/** Complete member ZIP export smoke test. */
declare(strict_types=1);

$GLOBALS['adam_export_users']       = array();
$GLOBALS['adam_export_meta']        = array();
$GLOBALS['adam_export_attachments'] = array();
$GLOBALS['adam_export_upload_dir']  = sys_get_temp_dir() . '/adam-export-smoke-' . uniqid();
mkdir( $GLOBALS['adam_export_upload_dir'], 0777, true );

class WP_User {
	public int $ID;
	public string $user_login;
	public string $user_email;
	public string $display_name;
	public string $user_registered;
	public function __construct( int $id, string $name, string $email ) {
		$this->ID              = $id;
		$this->user_login      = strtolower( str_replace( ' ', '.', $name ) );
		$this->user_email      = $email;
		$this->display_name    = $name;
		$this->user_registered = '2026-08-01 12:00:00';
	}
}

class WP_Error {
	public function __construct( private string $code = '', private string $message = '' ) {}
	public function get_error_message(): string {
		return $this->message; }
}

function __( string $text, string $domain = '' ): string {
	return $text; }
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error; }
function absint( mixed $value ): int {
	return abs( (int) $value ); }
function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
function sanitize_text_field( mixed $value ): string {
	return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( mixed $value ): string {
	return trim( strip_tags( (string) $value ) ); }
function sanitize_email( string $value ): string {
	return $value; }
function apply_filters( string $hook, mixed $value ): mixed {
	return $value; }
function wp_kses_post( string $value ): string {
	return $value; }
function esc_url_raw( string $value ): string {
	return $value; }
function get_option( string $key, mixed $default = false ): mixed {
	if ( 'date_format' === $key ) {
		return 'd/m/Y'; }
	return $default;
}
function get_user_by( string $field, int $id ): WP_User|false {
	return $GLOBALS['adam_export_users'][ $id ] ?? false; }
function get_user_meta( int $id, string $key = '', bool $single = false ): mixed {
	$meta = $GLOBALS['adam_export_meta'][ $id ] ?? array();
	if ( '' === $key ) {
		$result = array();
		foreach ( $meta as $meta_key => $value ) {
			$result[ $meta_key ] = array( serialize_if_needed( $value ) ); }
		return $result;
	}
	return $meta[ $key ] ?? ( $single ? '' : array() );
}
function serialize_if_needed( mixed $value ): mixed {
	return is_array( $value ) ? serialize( $value ) : $value; }
function maybe_unserialize( mixed $value ): mixed {
	if ( ! is_string( $value ) ) {
		return $value; }
	$result = @unserialize( $value );
	return false === $result && 'b:0;' !== $value ? $value : $result;
}
function current_time( string $type ): int {
	return strtotime( '2026-08-08 10:00:00 UTC' );
}
function current_datetime(): DateTimeImmutable {
	return new DateTimeImmutable( '2026-08-08 10:00:00', new DateTimeZone( 'UTC' ) );
}
function wp_date( string $format, ?int $timestamp = null ): string {
	return date( $format, $timestamp ?? time() ); }
function wp_tempnam( string $filename = '' ): string|false {
	return tempnam( sys_get_temp_dir(), 'adam-export-' ); }
function wp_delete_file( string $path ): void {
	if ( is_file( $path ) ) {
		unlink( $path ); } }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags ); }
function wp_strip_all_tags( string $value ): string {
	return strip_tags( $value ); }
function get_attached_file( int $id ): string|false {
	return $GLOBALS['adam_export_attachments'][ $id ] ?? false; }
function attachment_url_to_postid( string $url ): int {
	return 0; }
function wp_get_upload_dir(): array {
	return array(
		'baseurl' => 'https://example.test/uploads',
		'basedir' => $GLOBALS['adam_export_upload_dir'],
	); }
function wp_normalize_path( string $path ): string {
	return str_replace( '\\', '/', $path ); }
function trailingslashit( string $path ): string {
	return rtrim( $path, '/\\' ) . '/'; }

require_once dirname( __DIR__ ) . '/src/Core/RegistrationFieldCatalog.php';
require_once dirname( __DIR__ ) . '/src/Core/SettingsRepository.php';
require_once dirname( __DIR__ ) . '/src/Member/Member.php';
require_once dirname( __DIR__ ) . '/src/Export/CompleteMemberExportService.php';

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Export\CompleteMemberExportService;
use AdamMembership\Member\Member;

function adam_export_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message ); }
}

$photo   = $GLOBALS['adam_export_upload_dir'] . '/joao-original.jpg';
$receipt = $GLOBALS['adam_export_upload_dir'] . '/pagamento-original.pdf';
file_put_contents( $photo, 'JPEG-BYTES' );
file_put_contents( $receipt, '%PDF-ORIGINAL' );
$GLOBALS['adam_export_attachments'][900] = $photo;
$GLOBALS['adam_export_users'][42]        = new WP_User( 42, 'João Silva', 'joao@example.test' );
$GLOBALS['adam_export_users'][77]        = new WP_User( 77, 'Maria Santos', 'maria@example.test' );
$GLOBALS['adam_export_meta'][42]         = array(
	'first_name'               => 'João',
	'last_name'                => 'Silva',
	'estado'                   => Member::STATUS_PENDING,
	'numero_socio'             => '',
	'data_nascimento'          => '1990-05-04',
	'telefone'                 => '910000000',
	'profile_photo'            => 900,
	'payment_receipt'          => 'https://example.test/uploads/pagamento-original.pdf',
	'adam_custom_future_field' => 'Valor futuro',
	'session_tokens'           => 'SECRET',
	'wp_capabilities'          => 'SECRET-CAP',
);
$GLOBALS['adam_export_meta'][77]         = array(
	'first_name'      => 'Maria',
	'last_name'       => 'Santos',
	'estado'          => Member::STATUS_ACTIVE,
	'numero_socio'    => 'ADAM-0002',
	'profile_photo'   => '',
	'payment_receipt' => '',
);
copy( $receipt, $GLOBALS['adam_export_upload_dir'] . '/pagamento-original.pdf' );

$admin_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/AdminController.php' );
adam_export_assert(
	str_contains( $admin_source, "public function handle_export_complete_zip(): void {\n\t\t\$this->ensure_can_manage();\n\t\tcheck_admin_referer( 'adam_membership_export_complete_zip' );" ),
	'Complete exports are not protected by administrator capability and nonce checks.'
);
adam_export_assert( str_contains( $admin_source, "'pending' === \$scope" ), 'The all-pending export mode is missing.' );
adam_export_assert( str_contains( $admin_source, "'approved' === \$scope" ), 'The all-approved export mode is missing.' );
adam_export_assert( str_contains( $admin_source, "wp_delete_file( \$path );" ), 'The streamed ZIP is not deleted after download.' );
adam_export_assert( str_contains( $admin_source, 'Exportar Registos Completos (ZIP)' ), 'The requested administrator export option is missing.' );
$service = new CompleteMemberExportService( new SettingsRepository() );
$result  = $service->create_archive( array( new Member( 42 ), new Member( 77 ) ) );
adam_export_assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'Archive failed.' );
adam_export_assert( 'ADAM_Export_2026-08-08.zip' === $result['filename'], 'Archive filename is incorrect.' );

$zip = new ZipArchive();
adam_export_assert( true === $zip->open( $result['path'] ), 'Archive cannot be opened.' );
$names = array();
for ( $index = 0; $index < $zip->numFiles; ++$index ) {
	$names[] = $zip->getNameIndex( $index ); }
adam_export_assert( in_array( 'Pending_João_Silva/Informacao.xlsx', $names, true ), 'Pending spreadsheet path is missing.' );
adam_export_assert( in_array( 'Pending_João_Silva/Fotografia.jpg', $names, true ), 'Photo path is missing or extension changed.' );
adam_export_assert( in_array( 'Pending_João_Silva/Comprovativo_de_pagamento.pdf', $names, true ), 'Receipt path is missing or extension changed.' );
adam_export_assert( in_array( 'ADAM-0002_Maria_Santos/Informacao.xlsx', $names, true ), 'Approved member folder is missing.' );
adam_export_assert( 'JPEG-BYTES' === $zip->getFromName( 'Pending_João_Silva/Fotografia.jpg' ), 'Photo bytes were converted.' );
adam_export_assert( '%PDF-ORIGINAL' === $zip->getFromName( 'Pending_João_Silva/Comprovativo_de_pagamento.pdf' ), 'Receipt bytes were converted.' );

$xlsx_bytes = $zip->getFromName( 'Pending_João_Silva/Informacao.xlsx' );
$zip->close();
$xlsx_path = tempnam( sys_get_temp_dir(), 'adam-xlsx-check-' );
file_put_contents( $xlsx_path, $xlsx_bytes );
$xlsx = new ZipArchive();
adam_export_assert( true === $xlsx->open( $xlsx_path ), 'Informacao.xlsx is not a valid Office ZIP.' );
$sheet = (string) $xlsx->getFromName( 'xl/worksheets/sheet1.xml' );
$xlsx->close();
adam_export_assert( str_contains( $sheet, 'Valor futuro' ), 'Future/custom fields are not exported.' );
adam_export_assert( str_contains( $sheet, 'Data de Nascimento' ), 'Configured Portuguese labels are not exported.' );
adam_export_assert( ! str_contains( $sheet, 'SECRET' ), 'Sensitive session/capability metadata leaked into XLSX.' );
adam_export_assert( ! str_contains( $sheet, 'WORDPRESS-PREFERENCE' ), 'Unrelated WordPress preferences leaked into XLSX.' );

unlink( $xlsx_path );
wp_delete_file( $result['path'] );
unlink( $photo );
unlink( $GLOBALS['adam_export_upload_dir'] . '/pagamento-original.pdf' );
rmdir( $GLOBALS['adam_export_upload_dir'] );

echo "Complete member ZIP export smoke tests passed.\n";
