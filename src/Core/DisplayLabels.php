<?php
/**
 * Human-readable labels for stored/internal values.
 *
 * @package AdamMembership\Core
 */

declare(strict_types=1);

namespace AdamMembership\Core;

final class DisplayLabels {
	/** @var array<string,string> */
	private const FIELDS = array(
		'full_name' => 'Nome completo',
		'birth_date' => 'Data de nascimento',
		'marital_status' => 'Estado civil',
		'genero' => 'Género',
		'gender' => 'Género',
		'estado_civil' => 'Estado civil',
		'profissao' => 'Profissão',
		'profession' => 'Profissão',
		'nacionalidade' => 'Nacionalidade',
		'nationality' => 'Nacionalidade',
		'naturalidade' => 'Naturalidade',
		'phone' => 'Telemóvel',
		'telephone' => 'Telefone',
		'email' => 'Email',
		'address_line_1' => 'Morada',
		'address_line_2' => 'Morada (linha 2)',
		'postcode' => 'Código postal',
		'city' => 'Localidade',
		'municipality' => 'Município',
		'country' => 'País',
		'nif' => 'NIF',
		'citizen_card' => 'BI / Cartão de Cidadão / Passaporte',
		'document_expiry_date' => 'Data de validade',
		'document_issuing_place' => 'Local de emissão',
		'team' => 'Equipa',
		'profile_photo' => 'Fotografia',
		'apd_name' => 'APD / Associação',
		'apd_association' => 'APD / Associação',
		'apd_member_number' => 'N.º de sócio APD',
		'numero_socio_apd' => 'N.º de sócio APD',
		'numero_socio' => 'Número de sócio',
		'membership_origin' => 'Origem da inscrição',
		'apd_management_status' => 'Gestão do APD',
	);

	/** @var array<string,string> */
	private const STATUSES = array(
		'pending' => 'Pendente',
		'active' => 'Ativo',
		'renewal_pending' => 'Renovação pendente',
		'expired' => 'Expirado',
		'pending_review' => 'Pendente de aprovação',
		'pending_payment' => 'Pendente de pagamento',
		'paid_awaiting_processing' => 'Pago / A aguardar processamento',
		'submitted_to_ana' => 'Submetido à ANA',
		'ana_confirmed' => 'ANA confirmada',
		'approved' => 'Aprovado',
		'rejected' => 'Rejeitado',
		'cancelled' => 'Cancelado',
		'external_association' => 'APD externa',
		'adam_primary' => 'ANA através da ADAM',
		'managed' => 'Gerido pela ADAM',
		'external' => 'Externo / não gerido pela ADAM',
	);

	/** @var array<string,array<string,string>> */
	private const VALUES = array(
		'genero' => array( 'masculino' => 'Masculino', 'feminino' => 'Feminino', 'outro' => 'Outro' ),
		'gender' => array( 'masculino' => 'Masculino', 'feminino' => 'Feminino', 'outro' => 'Outro' ),
		'estado_civil' => array( 'solteiro' => 'Solteiro', 'casado' => 'Casado', 'divorciado' => 'Divorciado', 'viuvo' => 'Viúvo', 'uniao_facto' => 'União de Facto' ),
		'marital_status' => array( 'solteiro' => 'Solteiro', 'casado' => 'Casado', 'divorciado' => 'Divorciado', 'viuvo' => 'Viúvo', 'uniao_facto' => 'União de Facto' ),
	);

	public static function field( string $value ): string {
		$key = sanitize_key( $value );
		return self::FIELDS[ $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $value ) );
	}

	public static function status( string $value ): string {
		$key = sanitize_key( $value );
		return self::STATUSES[ $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $value ) );
	}

	public static function value( string $field, mixed $value ): string {
		$text = is_scalar( $value ) ? (string) $value : '';
		$key = sanitize_key( $field );
		$value_key = sanitize_key( $text );
		return self::VALUES[ $key ][ $value_key ] ?? $text;
	}
}
