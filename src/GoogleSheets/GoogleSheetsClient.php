<?php
/**
 * Read-only Google Sheets API client for connection diagnostics.
 *
 * @package AdamMembership\GoogleSheets
 */

declare(strict_types=1);

namespace AdamMembership\GoogleSheets;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Helpers\Logger;
use WP_Error;

/**
 * Authenticates with a service account using WordPress HTTP and OpenSSL only.
 */
final class GoogleSheetsClient {
	private const TABLE_NAME = 'QuotasTable';
	private const READONLY_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';
	private const WRITE_SCOPE    = 'https://www.googleapis.com/auth/spreadsheets';
	private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';
	private SettingsRepository $settings;
	private ?Logger $logger;

	/**
	 * Create the client.
	 *
	 * @param SettingsRepository $settings Plugin settings.
	 */
	public function __construct( SettingsRepository $settings, ?Logger $logger = null ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Test credentials, spreadsheet access and worksheet existence without writes.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection(): true|WP_Error {
		$config = $this->settings->google_sheets_settings();

		if ( ! $config['enabled'] ) {
			return new WP_Error( 'adam_google_sheets_disabled', __( 'A integração Google Sheets está desativada.', 'adam-membership' ) );
		}

		if ( '' === $config['spreadsheet_id'] ) {
			return new WP_Error( 'adam_google_sheets_spreadsheet_missing', __( 'O ID da spreadsheet não está configurado.', 'adam-membership' ) );
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'adam_google_sheets_openssl_missing', __( 'A extensão OpenSSL do PHP não está disponível no servidor.', 'adam-membership' ) );
		}

		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$access_token = $this->access_token( $credentials, self::READONLY_SCOPE, '', 'authentication' );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$url = add_query_arg(
			array(
				'fields' => 'spreadsheetId,sheets.properties.title',
			),
			'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $config['spreadsheet_id'] )
		);
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'adam_google_sheets_unavailable', __( 'Não foi possível contactar o Google Sheets neste momento. Tente novamente mais tarde.', 'adam-membership' ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return $this->api_error( $status, '', 'connection_test', wp_remote_retrieve_body( $response ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'adam_google_sheets_unavailable', __( 'A resposta do Google Sheets não pôde ser validada.', 'adam-membership' ) );
		}

		foreach ( (array) ( $body['sheets'] ?? array() ) as $sheet ) {
			if ( $config['sheet_name'] === (string) ( $sheet['properties']['title'] ?? '' ) ) {
				return true;
			}
		}

		return new WP_Error( 'adam_google_sheets_sheet_missing', __( 'A página configurada não foi encontrada na spreadsheet.', 'adam-membership' ) );
	}

	/** Return whether an approved movement should attempt Google synchronization. */
	public function is_configured(): bool {
		$config = $this->settings->google_sheets_settings();
		return ! empty( $config['enabled'] ) && '' !== trim( (string) ( $config['spreadsheet_id'] ?? '' ) ) && '' !== trim( (string) ( $config['sheet_name'] ?? '' ) );
	}

	/**
	 * Read a range from the configured worksheet.
	 *
	 * @param string $range A1 range relative to the configured worksheet.
	 * @return array<string, mixed>|WP_Error
	 */
	public function read_values( string $range, string $request_id = '' ): array|WP_Error {
		return $this->request_values( 'GET', $range, array(), self::READONLY_SCOPE, array( 'valueRenderOption' => 'FORMULA' ), $request_id, 'read_values' );
	}

	/**
	 * Append one row to the configured worksheet.
	 *
	 * @param array<int, string|int|float> $row Ordered values for columns A:K.
	 * @return array<string, mixed>|WP_Error
	 */
	public function append_row( array $row ): array|WP_Error {
		return $this->request_values(
			'POST',
			'A:K',
			array(
				'values' => array( array_values( $row ) ),
			),
			self::WRITE_SCOPE,
			array(
				'valueInputOption'      => 'USER_ENTERED',
				'insertDataOption'      => 'INSERT_ROWS',
				'includeValuesInResponse' => 'false',
			)
		);
	}

	/** Append into the real Google Sheets table, not merely into a formatted range. */
	public function append_table_row( array $row, string $request_id = '' ): array|WP_Error {
		$table = $this->table_metadata( $request_id, 'table_metadata_before_write' );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$values = array();
		foreach ( array_values( $row ) as $index => $value ) {
			$values[] = array( 'userEnteredValue' => in_array( $index, array( 2, 5 ), true ) ? array( 'numberValue' => (float) $value ) : array( 'stringValue' => (string) $value ) );
		}
		$result = $this->request_json(
			'POST',
			'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $this->settings->google_sheets_settings()['spreadsheet_id'] ) . ':batchUpdate',
			array( 'requests' => array( array( 'appendCells' => array( 'tableId' => $table['tableId'], 'rows' => array( array( 'values' => $values ) ), 'fields' => 'userEnteredValue' ) ) ) ),
			self::WRITE_SCOPE,
			$request_id,
			'append'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$after = $this->table_metadata( $request_id, 'table_metadata_after_write' );
		return is_wp_error( $after ) ? $after : array( 'table' => $after );
	}


	/** Perform an authenticated Sheets values request without exposing responses. */
	private function request_values( string $method, string $range, array $body, string $scope, array $query = array(), string $request_id = '', string $stage = 'request_values' ): array|WP_Error {
		$config = $this->settings->google_sheets_settings();
		if ( ! $config['enabled'] || '' === $config['spreadsheet_id'] ) {
			return new WP_Error( 'adam_google_sheets_not_configured', __( 'A integração Google Sheets não está configurada.', 'adam-membership' ) );
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'adam_google_sheets_openssl_missing', __( 'A extensão OpenSSL do PHP não está disponível no servidor.', 'adam-membership' ) );
		}
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		$token = $this->access_token( $credentials, $scope, $request_id, 'authentication' );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url = add_query_arg( $query, 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $config['spreadsheet_id'] ) . '/values/' . rawurlencode( $config['sheet_name'] . '!' . $range ) );
		$args = array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ),
		);
		if ( 'POST' === $method || 'PUT' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
			$response = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_request( $url, array_merge( $args, array( 'method' => 'PUT' ) ) );
		} else {
			$response = wp_remote_get( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			$this->log_diagnostic( $request_id, $stage, $response );
			return new WP_Error( 'adam_google_sheets_unavailable', __( 'Não foi possível contactar o Google Sheets neste momento.', 'adam-membership' ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return $this->api_error( $status, $request_id, $stage, wp_remote_retrieve_body( $response ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array();
	}

	/** Read the real table object and its current range from spreadsheet metadata. */
	private function table_metadata( string $request_id = '', string $stage = 'table_metadata' ): array|WP_Error {
		$config = $this->settings->google_sheets_settings();
		$response = $this->request_json( 'GET', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $config['spreadsheet_id'] ), array(), self::READONLY_SCOPE, array(), $request_id, $stage );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		foreach ( (array) ( $response['sheets'] ?? array() ) as $sheet ) {
			if ( $config['sheet_name'] !== (string) ( $sheet['properties']['title'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $sheet['tables'] ?? array() ) as $table ) {
				if ( self::TABLE_NAME === (string) ( $table['name'] ?? '' ) && '' !== (string) ( $table['tableId'] ?? '' ) ) {
					return array( 'tableId' => (string) $table['tableId'], 'range' => (array) ( $table['range'] ?? array() ) );
				}
			}
		}
		return new WP_Error( 'adam_google_sheets_table_missing', __( 'A tabela QuotasTable não foi encontrada na página configurada.', 'adam-membership' ) );
	}

	/** Perform a generic authenticated JSON request without exposing response data. */
	private function request_json( string $method, string $url, array $body, string $scope, array $query = array(), string $request_id = '', string $stage = 'request_json' ): array|WP_Error {
		$config = $this->settings->google_sheets_settings();
		if ( ! $config['enabled'] || '' === $config['spreadsheet_id'] || ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'adam_google_sheets_not_configured', __( 'A integração Google Sheets não está configurada.', 'adam-membership' ) );
		}
		$credentials = $this->credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}
		$token = $this->access_token( $credentials, $scope, $request_id, 'authentication' );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$url = add_query_arg( $query, $url );
		$args = array( 'method' => $method, 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json' ) );
		if ( array() !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			$status = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$this->log_diagnostic( $request_id, $stage, is_wp_error( $response ) ? $response : null, $status, is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response ) );
			return new WP_Error( 'adam_google_sheets_unavailable', __( 'Não foi possível contactar o Google Sheets neste momento.', 'adam-membership' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Load and validate server-only credentials.
	 *
	 * @return array{client_email:string,private_key:string,token_uri:string}|WP_Error
	 */
	private function credentials(): array|WP_Error {
		$raw = '';
		if ( defined( 'ADAM_GOOGLE_SERVICE_ACCOUNT_JSON' ) ) {
			$raw = (string) constant( 'ADAM_GOOGLE_SERVICE_ACCOUNT_JSON' );
		} elseif ( false !== getenv( 'ADAM_GOOGLE_SERVICE_ACCOUNT_JSON' ) ) {
			$raw = (string) getenv( 'ADAM_GOOGLE_SERVICE_ACCOUNT_JSON' );
		}

		if ( '' === trim( $raw ) ) {
			return new WP_Error( 'adam_google_sheets_credentials_missing', __( 'As credenciais da conta de serviço não estão configuradas no servidor.', 'adam-membership' ) );
		}

		if ( is_file( $raw ) ) {
			$raw = (string) file_get_contents( $raw );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || '' === trim( (string) ( $data['client_email'] ?? '' ) ) || '' === trim( (string) ( $data['private_key'] ?? '' ) ) || '' === trim( (string) ( $data['token_uri'] ?? '' ) ) ) {
			return new WP_Error( 'adam_google_sheets_credentials_invalid', __( 'As credenciais da conta de serviço são inválidas ou estão incompletas.', 'adam-membership' ) );
		}

		return array(
			'client_email' => (string) $data['client_email'],
			'private_key'  => (string) $data['private_key'],
			'token_uri'    => (string) $data['token_uri'],
		);
	}

	/**
	 * Exchange a signed JWT for a short-lived access token.
	 *
	 * @param array{client_email:string,private_key:string,token_uri:string} $credentials Credentials.
	 * @return string|WP_Error
	 */
	private function access_token( array $credentials, string $scope = self::READONLY_SCOPE, string $request_id = '', string $stage = 'authentication' ): string|WP_Error {
		$now = time();
		$header = $this->base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = $this->base64url(
			wp_json_encode(
				array(
					'iss'   => $credentials['client_email'],
					'scope' => $scope,
					'aud'   => $credentials['token_uri'],
					'exp'   => $now + 3600,
					'iat'   => $now,
				)
			)
		);
		$unsigned = $header . '.' . $claims;
		$signature = '';
		if ( ! openssl_sign( $unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'adam_google_sheets_authentication_failed', __( 'A autenticação Google falhou. Confirme a configuração da conta de serviço.', 'adam-membership' ) );
		}

		$response = wp_remote_post(
			$credentials['token_uri'],
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned . '.' . $this->base64url( $signature ) ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$status = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
			$this->log_diagnostic( $request_id, $stage, is_wp_error( $response ) ? $response : null, $status, is_wp_error( $response ) ? '' : wp_remote_retrieve_body( $response ) );
			return new WP_Error( 'adam_google_sheets_authentication_failed', __( 'A autenticação Google falhou. Confirme a configuração da conta de serviço.', 'adam-membership' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$token = is_array( $data ) ? (string) ( $data['access_token'] ?? '' ) : '';
		return '' !== $token ? $token : new WP_Error( 'adam_google_sheets_authentication_failed', __( 'A autenticação Google falhou. Confirme a configuração da conta de serviço.', 'adam-membership' ) );
	}

	/** Encode binary or JSON data for JWT transport. */
	private function base64url( string|false $value ): string {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/** Record a safe synchronization failure discovered by the orchestration layer. */
	public function log_failure( string $request_id, string $stage, WP_Error $error ): void {
		$this->log_diagnostic( $request_id, $stage, $error );
	}

	/** Record a safe synchronization exception without exposing its payload. */
	public function log_exception( string $request_id, string $stage, \Throwable $exception ): void {
		$this->log_diagnostic( $request_id, $stage, null, 0, $exception->getMessage(), get_class( $exception ) );
	}

	/** Write a bounded, secret-free technical diagnostic. */
	private function log_diagnostic( string $request_id, string $stage, ?WP_Error $error = null, int $status = 0, string $technical = '', string $exception_class = '' ): void {
		if ( null === $this->logger ) {
			return;
		}
		$provider_code = '';
		$provider_status = '';
		$decoded = json_decode( $technical, true );
		if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
			$provider = is_array( $decoded['error'] ) ? $decoded['error'] : array( 'value' => $decoded['error'] );
			$provider_code = sanitize_key( (string) ( $provider['status'] ?? $provider['reason'] ?? $provider['code'] ?? '' ) );
			$provider_status = sanitize_key( (string) ( $provider['status'] ?? '' ) );
		}
		$message = $error instanceof WP_Error ? $error->get_error_message() : $technical;
		$message = $this->sanitize_diagnostic_message( $message );
		$this->logger->error( 'Google Sheets financial synchronization diagnostic.', array( 'timestamp' => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ), 'operation' => 'financial_sync', 'request_id' => $request_id, 'stage' => sanitize_key( $stage ), 'exception_class' => sanitize_text_field( $exception_class ), 'http_status' => $status, 'error_code' => $error instanceof WP_Error ? sanitize_key( (string) $error->get_error_code() ) : '', 'google_error_code' => $provider_code, 'google_error_status' => $provider_status, 'technical_message' => $message ) );
	}

	/** Remove secrets, paths, URLs and oversized opaque values before logging. */
	private function sanitize_diagnostic_message( string $message ): string {
		$message = preg_replace( '/(?:authorization|bearer|access[_ -]?token|private[_ -]?key|client[_ -]?secret|jwt|assertion|credentials?)\s*[:=]\s*[^\s,;]+/i', '[redacted]', $message ) ?? '';
		$message = preg_replace( '#https?://[^\s]+#i', '[url-redacted]', $message ) ?? $message;
		$message = preg_replace( '#(?:[A-Za-z]:[\\/]|/(?:home|var|srv|www)/)[^\s"]+#i', '[path-redacted]', $message ) ?? $message;
		$message = preg_replace( '/[A-Za-z0-9+\/_=-]{80,}/', '[opaque-value-redacted]', $message ) ?? $message;
		return function_exists( 'mb_substr' ) ? mb_substr( trim( $message ), 0, 300 ) : substr( trim( $message ), 0, 300 );
	}

	/** Map HTTP failures without exposing provider response data. */
	private function api_error( int $status, string $request_id = '', string $stage = 'api_request', string $body = '' ): WP_Error {
		$this->log_diagnostic( $request_id, $stage, null, $status, $body );
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'adam_google_sheets_spreadsheet_not_found', __( 'A spreadsheet não foi encontrada ou não está partilhada com a conta de serviço.', 'adam-membership' ) );
		}
		if ( 404 === $status ) {
			return new WP_Error( 'adam_google_sheets_spreadsheet_not_found', __( 'A spreadsheet não foi encontrada ou não está partilhada com a conta de serviço.', 'adam-membership' ) );
		}

		return new WP_Error( 'adam_google_sheets_unavailable', __( 'Não foi possível contactar o Google Sheets neste momento. Tente novamente mais tarde.', 'adam-membership' ) );
	}
}
