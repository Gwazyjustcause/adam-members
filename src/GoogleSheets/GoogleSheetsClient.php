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

	/** Test the independent Gestão de Sócios destination without changing spreadsheet data. */
	public function test_gestao_connection(): true|WP_Error {
		$config = $this->settings->google_sheets_settings();
		if ( ! $config['enabled'] ) {
			return new WP_Error( 'adam_google_sheets_gestao_disabled', __( 'A integração Google Sheets está desativada.', 'adam-membership' ) );
		}
		$spreadsheet_id = trim( (string) ( $config['gestao_spreadsheet_id'] ?? '' ) );
		if ( '' === $spreadsheet_id ) {
			return new WP_Error( 'adam_google_sheets_gestao_spreadsheet_missing', __( 'O ID da folha Gestão de Sócios não está configurado.', 'adam-membership' ) );
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'adam_google_sheets_gestao_openssl_missing', __( 'A extensão OpenSSL do PHP não está disponível no servidor.', 'adam-membership' ) );
		}
		$url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id );
		$response = $this->request_json( 'GET', $url, array(), self::READONLY_SCOPE, array( 'fields' => 'spreadsheetId,sheets(properties(title,sheetId),tables(tableId,range))' ), '', 'gestao_connection_test', $spreadsheet_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		foreach ( (array) ( $response['sheets'] ?? array() ) as $sheet ) {
			if ( 'Gestão de Sócios' !== (string) ( $sheet['properties']['title'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $sheet['tables'] ?? array() ) as $table ) {
				$range = (array) ( $table['range'] ?? array() );
				$columns = absint( $range['endColumnIndex'] ?? 0 ) - absint( $range['startColumnIndex'] ?? 0 );
				if ( '' !== (string) ( $table['tableId'] ?? '' ) && $columns >= 8 ) {
					return true;
				}
			}
			return new WP_Error( 'adam_google_sheets_gestao_table_missing', __( 'A tabela nativa da Gestão de Sócios não foi encontrada ou não tem as oito colunas esperadas.', 'adam-membership' ) );
		}
		return new WP_Error( 'adam_google_sheets_gestao_sheet_missing', __( 'A página Gestão de Sócios não foi encontrada na spreadsheet configurada.', 'adam-membership' ) );
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
		return $this->request_values( 'GET', $range, array(), self::READONLY_SCOPE, array( 'valueRenderOption' => 'UNFORMATTED_VALUE' ), $request_id, 'read_values' );
	}

	/** Read values from a named worksheet without changing the configured Quotas worksheet. */
	public function read_sheet_values( string $sheet_name, string $range, string $request_id = '' ): array|WP_Error {
		return $this->request_values( 'GET', $range, array(), self::READONLY_SCOPE, array( 'valueRenderOption' => 'UNFORMATTED_VALUE' ), $request_id, 'read_sheet_values', $sheet_name );
	}

	/** Append a workflow row using INSERT_ROWS; never infer a destination from counts or row numbers. */
	public function append_workflow_row( string $sheet_name, array $row, string $request_id = '' ): array|WP_Error {
		$table = $this->workflow_table_metadata( $sheet_name, $request_id );
		if ( is_wp_error( $table ) ) { return $table; }
		$range = (array) $table['range'];
		$start = absint( $range['startRowIndex'] ?? 0 );
		$end = absint( $range['endRowIndex'] ?? 0 );
		$source = max( $start, $end - 1 );
		$target = $end;
		$requests = array(
			array( 'insertDimension' => array( 'range' => array( 'sheetId' => $table['sheetId'], 'dimension' => 'ROWS', 'startIndex' => $target, 'endIndex' => $target + 1 ), 'inheritFromBefore' => true ) ),
			array( 'copyPaste' => array( 'source' => array( 'sheetId' => $table['sheetId'], 'startRowIndex' => $source, 'endRowIndex' => $source + 1, 'startColumnIndex' => 0, 'endColumnIndex' => 8 ), 'destination' => array( 'sheetId' => $table['sheetId'], 'startRowIndex' => $target, 'endRowIndex' => $target + 1, 'startColumnIndex' => 0, 'endColumnIndex' => 8 ), 'pasteType' => 'PASTE_NORMAL' ) ),
			array( 'updateCells' => array( 'range' => array( 'sheetId' => $table['sheetId'], 'startRowIndex' => $target, 'endRowIndex' => $target + 1, 'startColumnIndex' => 0, 'endColumnIndex' => 8 ), 'rows' => array( array( 'values' => $this->workflow_cell_values( $row ) ) ), 'fields' => 'userEnteredValue' ) ),
			array( 'createDeveloperMetadata' => array( 'developerMetadata' => array( 'metadataKey' => 'adam_gestao_socios_request_id', 'metadataValue' => $request_id, 'visibility' => 'DOCUMENT', 'location' => array( 'dimensionRange' => array( 'sheetId' => $table['sheetId'], 'dimension' => 'ROWS', 'startIndex' => $target, 'endIndex' => $target + 1 ) ) ) ) ),
		);
		$expanded = $range;
		$expanded['endRowIndex'] = $end + 1;
		$requests[] = array( 'updateTable' => array( 'table' => array( 'tableId' => $table['tableId'], 'range' => $expanded ), 'fields' => 'range' ) );
		$spreadsheet_id = $this->gestao_spreadsheet_id();
		if ( is_wp_error( $spreadsheet_id ) ) { return $spreadsheet_id; }
		$result = $this->request_json( 'POST', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id ) . ':batchUpdate', array( 'requests' => $requests ), self::WRITE_SCOPE, array(), $request_id, 'append_gestao_de_socios', $spreadsheet_id );
		if ( is_wp_error( $result ) ) { return $result; }
		$after = $this->workflow_table_metadata( $sheet_name, $request_id );
		if ( is_wp_error( $after ) || absint( $after['range']['endRowIndex'] ?? 0 ) < $end + 1 ) {
			return new WP_Error( 'adam_google_sheets_table_expand_unconfirmed', __( 'A nova linha da Gestão de Sócios não foi confirmada dentro da tabela.', 'adam-membership' ) );
		}
		return array( 'row_number' => $target + 1, 'table' => $after );
	}

	/** Return request IDs recorded as row-level developer metadata. */
	public function workflow_request_ids( string $sheet_name, string $request_id = '' ): array|WP_Error {
		$spreadsheet_id = $this->gestao_spreadsheet_id();
		if ( is_wp_error( $spreadsheet_id ) ) { return $spreadsheet_id; }
		$response = $this->request_json( 'GET', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id ), array(), self::READONLY_SCOPE, array( 'fields' => 'developerMetadata(metadataKey,metadataValue)' ), $request_id, 'read_gestao_metadata', $spreadsheet_id );
		if ( is_wp_error( $response ) ) { return $response; }
		$ids = array();
		foreach ( (array) ( $response['developerMetadata'] ?? array() ) as $metadata ) {
			if ( 'adam_gestao_socios_request_id' === (string) ( $metadata['metadataKey'] ?? '' ) && '' !== (string) ( $metadata['metadataValue'] ?? '' ) ) { $ids[] = (string) $metadata['metadataValue']; }
		}
		return array_values( array_unique( $ids ) );
	}

	/** Check whether a request UUID still points to a live row-level metadata location. */
	public function workflow_request_exists( string $sheet_name, string $request_id = '', string $expected_quota_type = '', string $expected_member_name = '' ): bool|WP_Error {
		if ( '' === trim( $request_id ) ) { return false; }
		$spreadsheet_id = $this->gestao_spreadsheet_id();
		if ( is_wp_error( $spreadsheet_id ) ) { return $spreadsheet_id; }
		$response = $this->request_json(
			'GET',
			'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id ),
			array(),
			self::READONLY_SCOPE,
			array( 'fields' => 'developerMetadata(metadataKey,metadataValue,location),sheets(properties(title,sheetId))' ),
			$request_id,
			'check_gestao_metadata',
			$spreadsheet_id
		);
		if ( is_wp_error( $response ) ) { return $response; }
		$sheet_ids = array();
		foreach ( (array) ( $response['sheets'] ?? array() ) as $sheet ) {
			if ( $sheet_name === (string) ( $sheet['properties']['title'] ?? '' ) ) {
				$sheet_ids[] = absint( $sheet['properties']['sheetId'] ?? 0 );
			}
		}
		foreach ( (array) ( $response['developerMetadata'] ?? array() ) as $metadata ) {
			if ( 'adam_gestao_socios_request_id' !== (string) ( $metadata['metadataKey'] ?? '' ) || $request_id !== (string) ( $metadata['metadataValue'] ?? '' ) ) { continue; }
			$location = (array) ( $metadata['location']['dimensionRange'] ?? array() );
			if ( 'ROWS' !== (string) ( $location['dimension'] ?? '' ) || ! in_array( absint( $location['sheetId'] ?? 0 ), $sheet_ids, true ) || absint( $location['endIndex'] ?? 0 ) !== absint( $location['startIndex'] ?? 0 ) + 1 ) { continue; }
			$row_number = absint( $location['startIndex'] ?? 0 ) + 1;
			$row = $this->request_json( 'GET', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id ) . '/values/' . rawurlencode( $sheet_name . '!A' . $row_number . ':C' . $row_number ), array(), self::READONLY_SCOPE, array( 'valueRenderOption' => 'UNFORMATTED_VALUE' ), $request_id, 'check_gestao_metadata_row', $spreadsheet_id );
			if ( is_wp_error( $row ) ) { return $row; }
			$values = (array) ( $row['values'][0] ?? array() );
			if ( '' !== $expected_quota_type && $expected_quota_type !== (string) ( $values[1] ?? '' ) ) { continue; }
			if ( '' !== $expected_member_name && $expected_member_name !== (string) ( $values[2] ?? '' ) ) { continue; }
			return true;
		}
		return false;
	}

	/**
	 * Append one row to the configured worksheet.
	 *
	 * @param array<int, string|int|float> $row Ordered values for columns A:L.
	 * @return array<string, mixed>|WP_Error
	 */
	public function append_row( array $row ): array|WP_Error {
		return $this->request_values(
			'POST',
			'A:L',
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
	public function append_table_row( array $row, string $request_id = '', int $target_row = 0 ): array|WP_Error {
		$table = $this->table_metadata( $request_id, 'table_metadata_before_write' );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$table_range = (array) ( $table['range'] ?? array() );
		$table_start = absint( $table_range['startRowIndex'] ?? 0 );
		$table_end = absint( $table_range['endRowIndex'] ?? 0 );
		$target_api_row = $target_row > 0 ? $target_row - 1 : $table_end;
		if ( $target_api_row > $table_end ) {
			$target_api_row = $table_end;
		}
		if ( $target_api_row < $table_start ) {
			return new WP_Error( 'adam_google_sheets_table_target_invalid', __( 'A linha de destino da QuotasTable é inválida. A operação foi cancelada.', 'adam-membership' ) );
		}
		$values = $this->cell_values( $row );
		$requests = array();
		$expands_table = $target_api_row === $table_end;
		if ( $expands_table ) {
			$requests[] = array( 'insertDimension' => array( 'range' => array( 'sheetId' => $table['sheetId'], 'dimension' => 'ROWS', 'startIndex' => $table_end, 'endIndex' => $table_end + 1 ), 'inheritFromBefore' => true ) );
			$source_start = max( $table_start, $table_end - 1 );
			$requests[] = array( 'copyPaste' => array( 'source' => array( 'sheetId' => $table['sheetId'], 'startRowIndex' => $source_start, 'endRowIndex' => $source_start + 1, 'startColumnIndex' => 0, 'endColumnIndex' => 12 ), 'destination' => array( 'sheetId' => $table['sheetId'], 'startRowIndex' => $table_end, 'endRowIndex' => $table_end + 1, 'startColumnIndex' => 0, 'endColumnIndex' => 12 ), 'pasteType' => 'PASTE_NORMAL' ) );
		}
		$requests[] = array( 'updateCells' => array( 'range' => array( 'sheetId' => $table['sheetId'], 'startRowIndex' => $target_api_row, 'endRowIndex' => $target_api_row + 1, 'startColumnIndex' => 0, 'endColumnIndex' => 12 ), 'rows' => array( array( 'values' => $values ) ), 'fields' => 'userEnteredValue' ) );
		$requests = array_merge( $requests, $this->financial_format_requests( (int) $table['sheetId'], $target_api_row ) );
		if ( $expands_table ) {
			$expanded_range = $table_range;
			$expanded_range['endRowIndex'] = $table_end + 1;
			$requests[] = array( 'updateTable' => array( 'table' => array( 'tableId' => $table['tableId'], 'range' => $expanded_range ), 'fields' => 'range' ) );
		}
		$result = $this->request_json(
			'POST',
			'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $this->settings->google_sheets_settings()['spreadsheet_id'] ) . ':batchUpdate',
			array( 'requests' => $requests ),
			self::WRITE_SCOPE,
			array(),
			$request_id,
			$expands_table ? 'append_and_expand_table' : 'write_table_gap'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$after = $this->table_metadata( $request_id, 'table_metadata_after_write' );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		if ( $expands_table && absint( $after['range']['endRowIndex'] ?? 0 ) < $table_end + 1 ) {
			return new WP_Error( 'adam_google_sheets_table_expand_unconfirmed', __( 'A nova linha foi escrita, mas a expansão da QuotasTable não foi confirmada.', 'adam-membership' ) );
		}
		return array( 'table' => $after, 'row_number' => $target_api_row + 1 );
	}

	/** Update the current row identified by its canonical ID, never by a stored row number. */
	public function update_table_row( array $row, string $request_id = '' ): array|WP_Error {
		$expected = array_pad( array_values( $row ), 12, '' );
		if ( $request_id !== (string) $expected[10] ) {
			return new WP_Error( 'adam_google_sheets_id_mismatch', __( 'O ID canónico do movimento não coincide com a linha a atualizar.', 'adam-membership' ) );
		}
		$current = $this->read_values( 'A5:L', $request_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$row_number = 0;
		foreach ( (array) ( $current['values'] ?? array() ) as $index => $stored ) {
			$stored_row = array_pad( (array) $stored, 12, '' );
			if ( $request_id === (string) $stored_row[10] ) {
				$row_number = 5 + (int) $index;
				break;
			}
		}
		if ( 0 === $row_number ) {
			return new WP_Error( 'adam_google_sheets_row_missing', __( 'A linha do movimento não foi encontrada na spreadsheet. Pode repetir a operação.', 'adam-membership' ) );
		}
		$table = $this->table_metadata( $request_id, 'update_metadata' );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$requests = array_merge(
			array( array( 'updateCells' => array( 'range' => array( 'sheetId' => $table['sheetId'], 'startRowIndex' => $row_number - 1, 'endRowIndex' => $row_number, 'startColumnIndex' => 0, 'endColumnIndex' => 12 ), 'rows' => array( array( 'values' => $this->cell_values( $row ) ) ), 'fields' => 'userEnteredValue' ) ) ),
			$this->financial_format_requests( (int) $table['sheetId'], $row_number - 1 )
		);
		$result = $this->request_json(
			'POST',
			'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $this->settings->google_sheets_settings()['spreadsheet_id'] ) . ':batchUpdate',
			array( 'requests' => $requests ),
			self::WRITE_SCOPE,
			array(),
			$request_id,
			'update'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$after = $this->table_metadata( $request_id, 'update_confirmation' );
		return is_wp_error( $after ) ? $after : array( 'table' => $after, 'row_number' => $row_number );
	}

	/** Delete exactly the current row whose column K equals the canonical ID. */
	public function delete_table_row( string $request_id = '' ): array|WP_Error {
		if ( ! $this->is_configured() ) {
			return array( 'configured' => false, 'row_found' => false, 'deleted' => false );
		}
		$current = $this->read_values( 'A5:L', $request_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$row_number = 0;
		$matches = 0;
		foreach ( (array) ( $current['values'] ?? array() ) as $index => $stored ) {
			$stored_row = array_pad( (array) $stored, 12, '' );
			if ( $request_id === (string) $stored_row[10] ) {
				++$matches;
				$row_number = 5 + (int) $index;
			}
		}
		if ( 0 === $matches ) {
			return array( 'configured' => true, 'row_found' => false, 'deleted' => false );
		}
		if ( 1 !== $matches ) {
			return new WP_Error( 'adam_google_sheets_duplicate_movement_id', __( 'O ID canónico aparece mais do que uma vez na spreadsheet. A eliminação foi cancelada.', 'adam-membership' ) );
		}
		$table = $this->table_metadata( $request_id, 'delete_metadata' );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$api_row_index = $row_number - 1;
		$table_start = absint( $table['range']['startRowIndex'] ?? 0 );
		$table_end   = absint( $table['range']['endRowIndex'] ?? 0 );
		$inside_table = $table_end > $table_start && $api_row_index >= $table_start && $api_row_index < $table_end;
		$result = $this->request_json(
			'POST',
			'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $this->settings->google_sheets_settings()['spreadsheet_id'] ) . ':batchUpdate',
			array( 'requests' => array( array( 'deleteDimension' => array( 'range' => array( 'sheetId' => $table['sheetId'], 'dimension' => 'ROWS', 'startIndex' => $api_row_index, 'endIndex' => $api_row_index + 1 ) ) ) ) ),
			self::WRITE_SCOPE,
			array(),
			$request_id,
			'delete'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$confirmation = $this->read_values( 'A5:L', $request_id );
		if ( is_wp_error( $confirmation ) ) {
			return new WP_Error( 'adam_google_sheets_delete_unconfirmed', __( 'A linha foi solicitada para eliminação, mas não foi possível confirmar o resultado. O movimento local não foi eliminado.', 'adam-membership' ) );
		}
		foreach ( (array) ( $confirmation['values'] ?? array() ) as $stored ) {
			$stored_row = array_pad( (array) $stored, 12, '' );
			if ( $request_id === (string) $stored_row[10] ) {
				return new WP_Error( 'adam_google_sheets_delete_unconfirmed', __( 'A linha financeira continua presente. O movimento local não foi eliminado.', 'adam-membership' ) );
			}
		}
		$table_after = $this->table_metadata( $request_id, 'delete_confirmation_metadata' );
		if ( is_wp_error( $table_after ) ) {
			return new WP_Error( 'adam_google_sheets_delete_unconfirmed', __( 'A linha foi eliminada, mas não foi possível confirmar o intervalo atual da QuotasTable. O movimento local não foi eliminado.', 'adam-membership' ) );
		}
		$after_start = absint( $table_after['range']['startRowIndex'] ?? 0 );
		$after_end   = absint( $table_after['range']['endRowIndex'] ?? 0 );
		$expected_end = $inside_table ? $table_end - 1 : $table_end;
		if ( $after_start !== $table_start || $after_end !== $expected_end ) {
			return new WP_Error( 'adam_google_sheets_table_range_unconfirmed', __( 'A linha foi eliminada, mas o intervalo da QuotasTable não foi confirmado. O movimento local não foi eliminado.', 'adam-membership' ) );
		}
		return array( 'configured' => true, 'row_found' => true, 'deleted' => true, 'row_number' => $row_number, 'table' => $table_after );
	}

	/** Convert ordered A:L values to Sheets user-entered cell values. */
	private function cell_values( array $row ): array {
		$values = array();
		foreach ( array_values( $row ) as $index => $value ) {
			if ( in_array( $index, array( 3, 6 ), true ) ) {
				$values[] = array( 'userEnteredValue' => array( 'numberValue' => (float) $value ) );
			} elseif ( 7 === $index ) {
				$values[] = array( 'userEnteredValue' => array( 'numberValue' => $this->date_serial( (string) $value ) ) );
			} else {
				$values[] = array( 'userEnteredValue' => array( 'stringValue' => (string) $value ) );
			}
		}
		return $values;
	}

	private function date_serial( string $date ): float {
		$value = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
		if ( false === $value ) {
			return 0.0;
		}
		$origin = new \DateTimeImmutable( '1899-12-30', new \DateTimeZone( 'UTC' ) );
		return (float) $origin->diff( $value )->days;
	}

	/** Preserve the table's financial/date display contract without converting values to text. */
	private function financial_format_requests( int $sheet_id, int $row_index ): array {
		return array(
			array( 'repeatCell' => array( 'range' => array( 'sheetId' => $sheet_id, 'startRowIndex' => $row_index, 'endRowIndex' => $row_index + 1, 'startColumnIndex' => 6, 'endColumnIndex' => 7 ), 'cell' => array( 'userEnteredFormat' => array( 'numberFormat' => array( 'type' => 'CURRENCY', 'pattern' => '€ #,##0.00' ) ) ), 'fields' => 'userEnteredFormat.numberFormat' ) ),
			array( 'repeatCell' => array( 'range' => array( 'sheetId' => $sheet_id, 'startRowIndex' => $row_index, 'endRowIndex' => $row_index + 1, 'startColumnIndex' => 7, 'endColumnIndex' => 8 ), 'cell' => array( 'userEnteredFormat' => array( 'numberFormat' => array( 'type' => 'DATE', 'pattern' => 'dd/mm/yyyy' ) ) ), 'fields' => 'userEnteredFormat.numberFormat' ) ),
		);
	}


	/** Perform an authenticated Sheets values request without exposing responses. */
	private function request_values( string $method, string $range, array $body, string $scope, array $query = array(), string $request_id = '', string $stage = 'request_values', string $sheet_name = '' ): array|WP_Error {
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
		$sheet_name = '' !== $sheet_name ? $sheet_name : $config['sheet_name'];
		$url = add_query_arg( $query, 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $config['spreadsheet_id'] ) . '/values/' . rawurlencode( $sheet_name . '!' . $range ) );
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
				if ( self::TABLE_NAME === (string) ( $table['name'] ?? '' ) && '' !== (string) ( $table['tableId'] ?? '' ) && isset( $sheet['properties']['sheetId'] ) ) {
					return array( 'tableId' => (string) $table['tableId'], 'sheetId' => absint( $sheet['properties']['sheetId'] ), 'range' => (array) ( $table['range'] ?? array() ) );
				}
			}
		}
		return new WP_Error( 'adam_google_sheets_table_missing', __( 'A tabela QuotasTable não foi encontrada na página configurada.', 'adam-membership' ) );
	}

	/** Perform a generic authenticated JSON request without exposing response data. */
	private function request_json( string $method, string $url, array $body, string $scope, array $query = array(), string $request_id = '', string $stage = 'request_json', string $spreadsheet_id = '' ): array|WP_Error {
		$config = $this->settings->google_sheets_settings();
		$destination_id = '' !== trim( $spreadsheet_id ) ? trim( $spreadsheet_id ) : (string) $config['spreadsheet_id'];
		if ( ! $config['enabled'] || '' === $destination_id || ! function_exists( 'openssl_sign' ) ) {
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

	/** Resolve the first existing table on the separate operational worksheet. */
	private function workflow_table_metadata( string $sheet_name, string $request_id = '' ): array|WP_Error {
		$config = $this->settings->google_sheets_settings();
		$spreadsheet_id = $this->gestao_spreadsheet_id();
		if ( is_wp_error( $spreadsheet_id ) ) { return $spreadsheet_id; }
		$response = $this->request_json( 'GET', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode( $spreadsheet_id ), array(), self::READONLY_SCOPE, array( 'fields' => 'sheets(properties(title,sheetId),tables(tableId,range))' ), $request_id, 'gestao_table_metadata', $spreadsheet_id );
		if ( is_wp_error( $response ) ) { return $response; }
		foreach ( (array) ( $response['sheets'] ?? array() ) as $sheet ) {
			if ( $sheet_name !== (string) ( $sheet['properties']['title'] ?? '' ) ) { continue; }
			foreach ( (array) ( $sheet['tables'] ?? array() ) as $table ) {
				$range = (array) ( $table['range'] ?? array() );
				if ( '' !== (string) ( $table['tableId'] ?? '' ) && absint( $range['endColumnIndex'] ?? 0 ) - absint( $range['startColumnIndex'] ?? 0 ) >= 8 ) {
					return array( 'tableId' => (string) $table['tableId'], 'sheetId' => absint( $sheet['properties']['sheetId'] ?? 0 ), 'range' => $range );
				}
			}
		}
		return new WP_Error( 'adam_google_sheets_table_missing', __( 'A tabela da Gestão de Sócios não foi encontrada.', 'adam-membership' ) );
	}

	/** Resolve the independent operational destination without falling back to Quotas. */
	private function gestao_spreadsheet_id(): string|WP_Error {
		$config = $this->settings->google_sheets_settings();
		$id = trim( (string) ( $config['gestao_spreadsheet_id'] ?? '' ) );
		return '' !== $id ? $id : new WP_Error( 'adam_google_sheets_gestao_spreadsheet_missing', __( 'O ID da folha Gestão de Sócios não está configurado.', 'adam-membership' ) );
	}

	private function workflow_cell_values( array $row ): array {
		$values = array();
		foreach ( array_slice( array_pad( array_values( $row ), 8, '' ), 0, 8 ) as $value ) { $values[] = array( 'userEnteredValue' => array( 'stringValue' => (string) $value ) ); }
		return $values;
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
