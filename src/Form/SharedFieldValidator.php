<?php
declare(strict_types=1);

namespace AdamMembership\Form;

use AdamMembership\Member\NifValidator;
use WP_Error;

/** Shared value validation for configured membership fields. */
final class SharedFieldValidator {
	/** @param array<string,string> $mimes */
	public static function validate_upload( mixed $file, array $mimes, bool $required = false ): true|WP_Error {
		if ( ! is_array( $file ) || UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return $required ? new WP_Error( 'adam_file_required', 'É necessário enviar o ficheiro.' ) : true;
		}
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_OK ) ) { return new WP_Error( 'adam_file_upload', 'Não foi possível carregar o ficheiro.' ); }
		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$type = wp_check_filetype( $name, $mimes );
		if ( empty( $type['type'] ) ) { return new WP_Error( 'adam_file_type', 'O tipo de ficheiro enviado não é permitido.' ); }
		return true;
	}
	/** @param array<string,mixed> $config */
	public static function validate( string $field, mixed $value, array $config, bool $required = false ): true|WP_Error {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		$label = (string) ( $config['label'] ?? $field );
		if ( 'citizen_card' === $field ) {
			return IdentificationValidator::validate( $value, true );
		}
		if ( $required && '' === $value ) {
			return new WP_Error( 'adam_field_required', sprintf( 'O campo "%s" é obrigatório.', $label ) );
		}
		if ( '' === $value ) { return true; }

		$type = (string) ( $config['type'] ?? 'text' );
		if ( 'email' === $type && ! is_email( $value ) ) {
			return new WP_Error( 'adam_invalid_email', 'Introduza um endereço de email válido.' );
		}
		if ( 'date' === $type && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) || false === strtotime( $value ) ) ) {
			return new WP_Error( 'adam_invalid_date', sprintf( 'O campo "%s" tem um formato de data inválido.', $label ) );
		}
		if ( in_array( $field, array( 'nif', 'tax_id' ), true ) && ! NifValidator::is_valid( NifValidator::normalize( $value ) ) ) {
			return new WP_Error( 'adam_invalid_nif', 'O NIF introduzido não é válido. Verifique o número e tente novamente.' );
		}
		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			$options = self::parse_options( (string) ( $config['options'] ?? '' ) );
			if ( ! array_key_exists( $value, $options ) ) {
				return new WP_Error( 'adam_invalid_option', sprintf( 'Selecione uma opção válida para o campo "%s".', $label ) );
			}
		}
		return true;
	}

	/** @return array<string,string> */
	public static function parse_options( string $raw ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) { continue; }
			$parts = explode( '|', $line, 2 );
			$key = trim( (string) $parts[0] );
			if ( '' !== $key ) { $out[ $key ] = trim( (string) ( $parts[1] ?? $key ) ); }
		}
		return $out;
	}
}
