<?php
/**
 * Read-only Google Sheets API client for connection diagnostics.
 *
 * @package AdamMembership\GoogleSheets
 */

declare(strict_types=1);

namespace AdamMembership\GoogleSheets;

use AdamMembership\Core\SettingsRepository;
use WP_Error;

/**
 * Authenticates with a service account using WordPress HTTP and OpenSSL only.
 */
final class GoogleSheetsClient {
	private const READONLY_SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';
	private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';
	private SettingsRepository $settings;

	/**
	 * Create the client.
	 *
	 * @param SettingsRepository $settings Plugin settings.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
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

		$access_token = $this->access_token( $credentials );
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
			return $this->api_error( $status );
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
	private function access_token( array $credentials ): string|WP_Error {
		$now = time();
		$header = $this->base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = $this->base64url(
			wp_json_encode(
				array(
					'iss'   => $credentials['client_email'],
					'scope' => self::READONLY_SCOPE,
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

	/** Map HTTP failures without exposing provider response data. */
	private function api_error( int $status ): WP_Error {
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'adam_google_sheets_spreadsheet_not_found', __( 'A spreadsheet não foi encontrada ou não está partilhada com a conta de serviço.', 'adam-membership' ) );
		}
		if ( 404 === $status ) {
			return new WP_Error( 'adam_google_sheets_spreadsheet_not_found', __( 'A spreadsheet não foi encontrada ou não está partilhada com a conta de serviço.', 'adam-membership' ) );
		}

		return new WP_Error( 'adam_google_sheets_unavailable', __( 'Não foi possível contactar o Google Sheets neste momento. Tente novamente mais tarde.', 'adam-membership' ) );
	}
}
