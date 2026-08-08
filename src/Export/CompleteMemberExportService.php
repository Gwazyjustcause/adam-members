<?php
/**
 * Complete member ZIP export service.
 *
 * @package AdamMembership\Export
 */

declare(strict_types=1);

namespace AdamMembership\Export;

use AdamMembership\Core\SettingsRepository;
use AdamMembership\Member\Member;
use WP_Error;
use ZipArchive;

/**
 * Builds complete, portable member archives for administrators.
 */
final class CompleteMemberExportService {
	/**
	 * Persisted keys for built-in form fields.
	 *
	 * @var array<string, string>
	 */
	private const FORM_FIELD_META_KEYS = array(
		'birth_date'                 => 'data_nascimento',
		'marital_status'             => 'estado_civil',
		'gender'                     => 'genero',
		'profession'                 => 'profissao',
		'birthplace'                 => 'naturalidade',
		'nationality'                => 'nacionalidade',
		'phone'                      => 'telefone',
		'telephone'                  => 'telefone_fixo',
		'address_line_1'             => 'morada',
		'address_line_2'             => 'morada_linha_2',
		'postcode'                   => 'codigo_postal',
		'city'                       => 'cidade',
		'municipality'               => 'municipio',
		'country'                    => 'pais',
		'citizen_card'               => 'cartao_cidadao',
		'document_expiry_date'       => 'documento_validade',
		'document_issuing_place'     => 'documento_local_emissao',
		'nif'                        => 'nif',
		'team'                       => 'equipa',
		'profile_photo'              => 'profile_photo',
		'payment_receipt'            => 'payment_receipt',
		'external_association_name'  => 'adam_external_association_name',
		'external_member_number'     => 'adam_external_member_number',
		'external_association_proof' => 'adam_external_association_proof',
	);

	/**
	 * Plugin settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Create the exporter.
	 *
	 * @param SettingsRepository $settings Plugin settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Build an archive. The caller must delete the returned path after streaming.
	 *
	 * @param array<int, Member> $members Members to export.
	 * @return array{path:string,filename:string}|WP_Error
	 * @throws \\RuntimeException When an archive component cannot be written.
	 */
	public function create_archive( array $members ): array|WP_Error {
		if ( array() === $members ) {
			return new WP_Error( 'adam_empty_complete_export', __( 'Não existem registos para exportar.', 'adam-membership' ) );
		}

		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error( 'adam_zip_unavailable', __( 'O servidor não tem suporte ZIP disponível.', 'adam-membership' ) );
		}

		$filename = 'ADAM_Export_' . current_datetime()->format( 'Y-m-d' ) . '.zip';
		$zip_path = wp_tempnam( $filename );
		if ( ! is_string( $zip_path ) || '' === $zip_path ) {
			return new WP_Error( 'adam_export_temp_failed', __( 'Não foi possível criar o ficheiro temporário de exportação.', 'adam-membership' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->delete_file( $zip_path );
			return new WP_Error( 'adam_export_zip_failed', __( 'Não foi possível criar o ficheiro ZIP.', 'adam-membership' ) );
		}

		$used_folders    = array();
		$temporary_files = array();
		try {
			foreach ( $members as $member ) {
				if ( ! $member instanceof Member ) {
					continue;
				}

				$folder         = $this->unique_name( $this->member_folder_name( $member ), $used_folders );
				$used_folders[] = $folder;
				$zip->addEmptyDir( $folder );

				$xlsx = $this->build_spreadsheet( $member );
				if ( is_wp_error( $xlsx ) ) {
					throw new \RuntimeException( $xlsx->get_error_message() );
				}

				$temporary_files[] = $xlsx;
				if ( ! $zip->addFile( $xlsx, $folder . '/Informacao.xlsx' ) ) {
					throw new \RuntimeException( __( 'Não foi possível adicionar a informação do sócio ao ZIP.', 'adam-membership' ) );
				}

				$this->add_documents( $zip, $member, $folder );
			}

			if ( ! $zip->close() ) {
				throw new \RuntimeException( __( 'Não foi possível finalizar o ficheiro ZIP.', 'adam-membership' ) );
			}

			foreach ( $temporary_files as $temporary_file ) {
				$this->delete_file( $temporary_file );
			}
		} catch ( \Throwable $exception ) {
			$zip->close();
			foreach ( $temporary_files as $temporary_file ) {
				$this->delete_file( $temporary_file );
			}
			$this->delete_file( $zip_path );
			return new WP_Error( 'adam_complete_export_failed', $exception->getMessage() );
		}

		return array(
			'path'     => $zip_path,
			'filename' => $filename,
		);
	}

	/**
	 * Return all safe stored information, including future/custom user fields.
	 *
	 * @param Member $member Member record.
	 * @return array<int, array{section:string,label:string,value:string,key:string}>
	 */
	public function information_rows( Member $member ): array {
		$user = $member->user();
		$rows = array(
			$this->row( 'Informação pessoal', 'Nome Completo', $member->full_name(), 'full_name' ),
			$this->row( 'Contacto', 'Email', $member->email(), 'email' ),
			$this->row( 'Registo', 'Data de Registo', $member->registration_date(), 'user_registered' ),
		);

		if ( null !== $user ) {
			$rows[] = $this->row( 'Registo', 'Nome de Utilizador', (string) $user->user_login, 'user_login' );
			$rows[] = $this->row( 'Registo', 'Nome de Apresentação', (string) $user->display_name, 'display_name' );
		}

		$labels   = $this->field_labels();
		$included = array();
		foreach ( $member->data() as $key => $value ) {
			$key        = (string) $key;
			$included[] = $key;
			$rows[]     = $this->row( $this->field_section( $key ), $labels[ $key ] ?? $this->humanize_key( $key ), $this->stringify( $value ), $key );
		}

		$meta = get_user_meta( $member->user_id() );
		if ( is_array( $meta ) ) {
			ksort( $meta, SORT_NATURAL | SORT_FLAG_CASE );
			foreach ( $meta as $key => $values ) {
				$key = (string) $key;
				if ( in_array( $key, $included, true ) || ! $this->safe_meta_key( $key ) ) {
					continue;
				}
				$value  = is_array( $values ) && 1 === count( $values ) ? reset( $values ) : $values;
				$rows[] = $this->row( $this->field_section( $key ), $labels[ $key ] ?? $this->humanize_key( $key ), $this->stringify( maybe_unserialize( $value ) ), $key );
			}
		}

		return $rows;
	}

	/**
	 * Build an XLSX information file for one member.
	 *
	 * @param Member $member Member record.
	 */
	private function build_spreadsheet( Member $member ): string|WP_Error {
		$path = wp_tempnam( 'Informacao.xlsx' );
		if ( ! is_string( $path ) || '' === $path ) {
			return new WP_Error( 'adam_xlsx_temp_failed', __( 'Não foi possível criar a folha de cálculo temporária.', 'adam-membership' ) );
		}

		$xlsx = new ZipArchive();
		if ( true !== $xlsx->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$this->delete_file( $path );
			return new WP_Error( 'adam_xlsx_failed', __( 'Não foi possível criar a folha de cálculo.', 'adam-membership' ) );
		}

		$xlsx->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>' );
		$xlsx->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$xlsx->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Informação" sheetId="1" r:id="rId1"/></sheets></workbook>' );
		$xlsx->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
		$xlsx->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF17365D"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>' );
		$xlsx->addFromString( 'xl/worksheets/sheet1.xml', $this->worksheet_xml( $this->information_rows( $member ) ) );

		if ( ! $xlsx->close() ) {
			$this->delete_file( $path );
			return new WP_Error( 'adam_xlsx_close_failed', __( 'Não foi possível finalizar a folha de cálculo.', 'adam-membership' ) );
		}

		return $path;
	}

	/**
	 * Add the member's uploaded documents to the archive.
	 *
	 * @param ZipArchive $zip Archive being built.
	 * @param Member     $member Member record.
	 * @param string     $folder Member folder within the archive.
	 */
	private function add_documents( ZipArchive $zip, Member $member, string $folder ): void {
		$used = array( 'Informacao.xlsx' );
		foreach ( $this->document_fields() as $document ) {
			$path = $this->media_path( $member->field( $document['storage_key'] ) );
			if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			$name      = $this->safe_component( $document['label'], 'Documento' ) . ( '' !== $extension ? '.' . $extension : '' );
			$name      = $this->unique_name( $name, $used );
			$used[]    = $name;
			$zip->addFile( $path, $folder . '/' . $name );
		}
	}

	/**
	 * Get all configured upload fields.
	 *
	 * @return array<int, array{storage_key:string,label:string}>
	 */
	private function document_fields(): array {
		$settings  = $this->settings->membership_form_settings();
		$documents = array();
		foreach ( array( 'registration_fields', 'renewal_fields' ) as $settings_key ) {
			$fields = isset( $settings[ $settings_key ] ) && is_array( $settings[ $settings_key ] ) ? $settings[ $settings_key ] : array();
			foreach ( $fields as $field_key => $config ) {
				if ( ! is_string( $field_key ) || ! is_array( $config ) || 'file' !== (string) ( $config['type'] ?? '' ) ) {
					continue;
				}
				$storage_key               = ! empty( $config['locked'] ) ? ( self::FORM_FIELD_META_KEYS[ $field_key ] ?? $field_key ) : 'adam_custom_' . sanitize_key( $field_key );
				$documents[ $storage_key ] = array(
					'storage_key' => $storage_key,
					'label'       => (string) ( $config['label'] ?? $this->humanize_key( $field_key ) ),
				);
			}
		}
		return array_values( $documents );
	}

	/**
	 * Get spreadsheet labels for stored fields.
	 *
	 * @return array<string, string>
	 */
	private function field_labels(): array {
		$labels   = array(
			'estado'                         => 'Estado',
			'numero_socio'                   => 'Número de Sócio',
			'data_adesao'                    => 'Data de Adesão',
			'validade_quota'                 => 'Validade da Quota',
			'adam_membership_origin'         => 'Tipo de Sócio',
			'adam_external_association_name' => 'APD / Associação Externa',
			'adam_external_member_number'    => 'ANA / Número na Associação',
			'contacto_emergencia'            => 'Contacto de Emergência',
		);
		$settings = $this->settings->membership_form_settings();
		foreach ( array( 'registration_fields', 'renewal_fields' ) as $settings_key ) {
			$fields = isset( $settings[ $settings_key ] ) && is_array( $settings[ $settings_key ] ) ? $settings[ $settings_key ] : array();
			foreach ( $fields as $field_key => $config ) {
				if ( ! is_string( $field_key ) || ! is_array( $config ) ) {
					continue;
				}
				$meta_key            = ! empty( $config['locked'] ) ? ( self::FORM_FIELD_META_KEYS[ $field_key ] ?? $field_key ) : 'adam_custom_' . sanitize_key( $field_key );
				$labels[ $meta_key ] = (string) ( $config['label'] ?? $this->humanize_key( $field_key ) );
			}
		}
		return $labels;
	}

	/**
	 * Resolve a stored media reference to a local path.
	 *
	 * @param mixed $value Stored media reference.
	 */
	private function media_path( mixed $value ): string {
		if ( is_array( $value ) ) {
			foreach ( array( 'ID', 'id', 'attachment_id' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) {
					return $this->attachment_path( absint( $value[ $key ] ) );
				}
			}
			foreach ( array( 'url', 'file_url', 'source_url' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) ) {
					return $this->local_url_path( $value[ $key ] );
				}
			}
		}
		if ( is_numeric( $value ) ) {
			return $this->attachment_path( absint( $value ) );
		}
		return is_string( $value ) ? $this->local_url_path( $value ) : '';
	}

	/**
	 * Resolve an attachment ID to a local path.
	 *
	 * @param int $id Attachment ID.
	 */
	private function attachment_path( int $id ): string {
		$path = get_attached_file( $id );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Resolve a local uploads URL without a network request.
	 *
	 * @param string $url Uploaded file URL.
	 */
	private function local_url_path( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$id = attachment_url_to_postid( $url );
		if ( 0 < $id ) {
			return $this->attachment_path( $id );
		}
		$uploads = wp_get_upload_dir();
		$baseurl = (string) ( $uploads['baseurl'] ?? '' );
		$basedir = (string) ( $uploads['basedir'] ?? '' );
		if ( '' === $baseurl || '' === $basedir || ! str_starts_with( $url, $baseurl . '/' ) ) {
			return '';
		}
		$relative = str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, rawurldecode( substr( $url, strlen( $baseurl ) + 1 ) ) );
		$path     = wp_normalize_path( $basedir . DIRECTORY_SEPARATOR . $relative );
		$base     = trailingslashit( wp_normalize_path( $basedir ) );
		return str_starts_with( $path, $base ) ? $path : '';
	}

	/**
	 * Build the portable folder name for a member.
	 *
	 * @param Member $member Member record.
	 */
	private function member_folder_name( Member $member ): string {
		$number = trim( (string) $member->field( 'numero_socio' ) );
		$name   = $this->safe_component( $member->full_name(), 'Sem_Nome' );
		if ( '' === $number ) {
			return 'Pending_' . $name;
		}
		if ( preg_match( '/^\d+$/', $number ) ) {
			$number = 'ADAM-' . str_pad( $number, 4, '0', STR_PAD_LEFT );
		}
		return $this->safe_component( $number, 'ADAM' ) . '_' . $name;
	}

	/**
	 * Sanitize a ZIP path component while preserving Unicode names.
	 *
	 * @param string $value Raw name.
	 * @param string $fallback Fallback name.
	 */
	private function safe_component( string $value, string $fallback ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		$value = preg_replace( '/[<>:"\/\\|?*\x00-\x1F]+/u', ' ', $value ) ?? '';
		$value = preg_replace( '/\s+/u', '_', $value ) ?? '';
		$value = trim( $value, " ._\t\n\r\0\x0B" );
		return '' !== $value ? $value : $fallback;
	}

	/**
	 * Make a file or folder name unique.
	 *
	 * @param string             $name Candidate name.
	 * @param array<int, string> $used Existing names.
	 */
	private function unique_name( string $name, array $used ): string {
		if ( ! in_array( $name, $used, true ) ) {
			return $name;
		}
		$extension = pathinfo( $name, PATHINFO_EXTENSION );
		$base      = '' !== $extension ? substr( $name, 0, -1 - strlen( $extension ) ) : $name;
		$counter   = 2;
		do {
			$candidate = $base . '_' . $counter . ( '' !== $extension ? '.' . $extension : '' );
			++$counter;
		} while ( in_array( $candidate, $used, true ) );
		return $candidate;
	}

	/**
	 * Decide whether user metadata is safe to export.
	 *
	 * @param string $key Metadata key.
	 */
	private function safe_meta_key( string $key ): bool {
		if ( '' === $key || str_starts_with( $key, '_' ) ) {
			return false;
		}
		if ( preg_match( '/(^|_)(capabilities|user_level|session_tokens|password|pass|secret|token)(_|$)/i', $key ) ) {
			return false;
		}

		return str_starts_with( $key, 'adam_' );
	}

	/**
	 * Resolve the spreadsheet section for a field.
	 *
	 * @param string $key Stored field key.
	 */
	private function field_section( string $key ): string {
		return match ( $key ) {
			'data_nascimento', 'estado_civil', 'genero', 'profissao', 'naturalidade', 'nacionalidade' => 'Informação pessoal',
			'telefone', 'telefone_fixo', 'contacto_emergencia' => 'Contacto',
			'morada', 'morada_linha_2', 'codigo_postal', 'cidade', 'municipio', 'pais' => 'Morada',
			'nif', 'cartao_cidadao', 'documento_validade', 'documento_local_emissao' => 'Identificação',
			'estado', 'numero_socio', 'data_adesao', 'validade_quota', 'equipa', 'team_id', 'adam_membership_origin', 'adam_membership_fee', 'adam_external_association_name', 'adam_external_member_number' => 'Associação',
			'profile_photo', 'payment_receipt', 'adam_external_association_proof' => 'Documentos',
			default => str_starts_with( $key, 'adam_custom_' ) ? 'Campos personalizados' : 'Dados adicionais',
		};
	}

	/**
	 * Convert a stored value to readable spreadsheet text.
	 *
	 * @param mixed $value Stored value.
	 */
	private function stringify( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'Sim' : 'Não';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		return false === $json ? '' : $json;
	}

	/**
	 * Build one spreadsheet row.
	 *
	 * @param string $section Section label.
	 * @param string $label Field label.
	 * @param string $value Field value.
	 * @param string $key Internal field key.
	 * @return array{section:string,label:string,value:string,key:string}
	 */
	private function row( string $section, string $label, string $value, string $key ): array {
		return array(
			'section' => $section,
			'label'   => $label,
			'value'   => $value,
			'key'     => $key,
		);
	}

	/**
	 * Turn a machine key into a readable fallback label.
	 *
	 * @param string $key Stored field key.
	 */
	private function humanize_key( string $key ): string {
		$key = preg_replace( '/^(adam_custom_|adam_)/', '', $key ) ?? $key;
		return ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
	}

	/**
	 * Build the worksheet XML document.
	 *
	 * @param array<int, array{section:string,label:string,value:string,key:string}> $rows Information rows.
	 */
	private function worksheet_xml( array $rows ): string {
		$data = array( array( 'Secção', 'Campo', 'Valor', 'Chave Interna' ) );
		foreach ( $rows as $row ) {
			$data[] = array( $row['section'], $row['label'], $row['value'], $row['key'] );
		}
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="24" customWidth="1"/><col min="2" max="2" width="34" customWidth="1"/><col min="3" max="3" width="70" customWidth="1"/><col min="4" max="4" width="32" customWidth="1"/></cols><sheetData>';
		foreach ( $data as $index => $columns ) {
			$row_number = $index + 1;
			$style      = 1 === $row_number ? ' s="1"' : '';
			$xml       .= '<row r="' . $row_number . '">';
			foreach ( $columns as $column => $value ) {
				$reference = chr( 65 + $column ) . $row_number;
				$xml      .= '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $this->xml_text( (string) $value ) . '</t></is></c>';
			}
			$xml .= '</row>';
		}
		return $xml . '</sheetData><autoFilter ref="A1:D1"/></worksheet>';
	}

	/**
	 * Escape and limit text for an XLSX inline string.
	 *
	 * @param string $value Cell value.
	 */
	private function xml_text( string $value ): string {
		$value = preg_replace( '/[^\P{C}\t\n\r]/u', '', $value ) ?? '';
		$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 32767 ) : substr( $value, 0, 32767 );
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Delete a temporary file.
	 *
	 * @param string $path Temporary path.
	 */
	private function delete_file( string $path ): void {
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
