<?php
/**
 * Canonical member-information fields available to approval corrections.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

use AdamMembership\Member\Member;

/** Keeps approval correction field names, labels and storage mappings consistent. */
final class CorrectionFieldCatalog {
	/** @var array<string,string> */
	private const LABELS = array(
		'full_name' => 'Nome completo', 'birth_date' => 'Data de nascimento', 'marital_status' => 'Estado civil',
		'gender' => 'Género', 'profession' => 'Profissão', 'birthplace' => 'Naturalidade', 'nationality' => 'Nacionalidade',
		'email' => 'Email', 'phone' => 'Telemóvel', 'telephone' => 'Telefone', 'address_line_1' => 'Morada completa',
		'address_line_2' => 'Apartamento, suite, etc.', 'postcode' => 'Código postal', 'city' => 'Localidade',
		'municipality' => 'Município', 'country' => 'País', 'citizen_card' => 'BI / Cartão de Cidadão', 'document_expiry_date' => 'Data de validade do documento',
		'document_issuing_place' => 'Local de emissão', 'nif' => 'NIF', 'team' => 'Equipa', 'profile_photo' => 'Fotografia',
		'external_association_name' => 'Nome da associação / APD', 'external_member_number' => 'Número de sócio APD',
		'external_association_proof' => 'Comprovativo de associação',
	);

	/** @var array<string,string> */
	private const STORAGE = array(
		'full_name' => 'nome', 'birth_date' => 'data_nascimento', 'marital_status' => 'estado_civil', 'gender' => 'genero',
		'profession' => 'profissao', 'birthplace' => 'naturalidade', 'nationality' => 'nacionalidade', 'phone' => 'telefone',
		'telephone' => 'telefone_fixo', 'address_line_1' => 'morada', 'address_line_2' => 'morada_linha_2', 'postcode' => 'codigo_postal',
		'city' => 'cidade', 'municipality' => 'municipio', 'country' => 'pais', 'citizen_card' => 'cartao_cidadao',
		'document_expiry_date' => 'documento_validade', 'document_issuing_place' => 'documento_local_emissao', 'nif' => 'nif',
		'team' => 'equipa', 'profile_photo' => 'profile_photo', 'external_association_name' => 'adam_external_association_name',
		'external_member_number' => 'adam_external_member_number', 'external_association_proof' => 'adam_external_association_proof',
	);

	/** @return array<string,array<string,mixed>> */
	public static function definitions( array $settings ): array {
		$configs = (array) ( $settings['registration_fields'] ?? array() );
		$out = array();
		foreach ( $configs as $key => $config ) {
			$key = sanitize_key( (string) $key );
			if ( 'privacy_acceptance' === $key || ! is_array( $config ) || empty( $config['enabled'] ) || ! isset( self::LABELS[ $key ] ) ) { continue; }
			$config['label'] = '' !== trim( (string) ( $config['label'] ?? '' ) ) ? (string) $config['label'] : self::LABELS[ $key ];
			$config['type'] = (string) ( $config['type'] ?? ( in_array( $key, array( 'profile_photo', 'external_association_proof' ), true ) ? 'file' : 'text' ) );
			$out[ $key ] = $config;
		}
		return $out;
	}

	/** @return array<string,string> */
	public static function labels(): array { return self::LABELS; }

	public static function label( string $key ): string { return self::LABELS[ sanitize_key( $key ) ] ?? self::LABELS['full_name']; }

	public static function storage_key( string $key ): string { return self::STORAGE[ sanitize_key( $key ) ] ?? sanitize_key( $key ); }

	public static function canonical_key( string $key ): string {
		$key = sanitize_key( $key );
		$canonical = array_search( $key, self::STORAGE, true );
		return false === $canonical ? $key : (string) $canonical;
	}

	public static function is_file( string $key ): bool { return in_array( sanitize_key( $key ), array( 'profile_photo', 'external_association_proof', 'payment_receipt' ), true ); }

	public static function value( Member $member, string $key ): mixed {
		$key = sanitize_key( $key );
		return 'full_name' === $key ? $member->full_name() : ( 'email' === $key ? $member->email() : $member->field( self::storage_key( $key ) ) );
	}

	/** @return array<string,array<int,string>> */
	public static function groups(): array {
		return array(
			'Informação pessoal' => array( 'full_name', 'birth_date', 'marital_status', 'gender', 'profession', 'birthplace', 'nationality' ),
			'Contacto e morada' => array( 'email', 'phone', 'telephone', 'address_line_1', 'address_line_2', 'postcode', 'city', 'municipality', 'country' ),
			'Identificação' => array( 'citizen_card', 'document_expiry_date', 'document_issuing_place', 'nif' ),
			'Associação / equipa' => array( 'team', 'external_association_name', 'external_member_number' ),
			'Documentos' => array( 'profile_photo', 'external_association_proof', 'payment_receipt' ),
		);
	}
}
