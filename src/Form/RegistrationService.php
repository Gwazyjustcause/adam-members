<?php
/**
 * Native registration service.
 *
 * @package AdamMembership\Form
 */

declare(strict_types=1);

namespace AdamMembership\Form;

use AdamMembership\Helpers\Logger;
use AdamMembership\Member\HistoryService;
use AdamMembership\Member\Member;
use WP_Error;

/**
 * Creates pending member accounts from normalized registration payloads.
 */
final class RegistrationService {
	private Logger $logger;
	private HistoryService $history;

	public function __construct( Logger $logger, HistoryService $history ) {
		$this->logger  = $logger;
		$this->history = $history;
	}

	/**
	 * Register a pending member from normalized form data.
	 *
	 * @param array<string, mixed> $payload Registration payload.
	 * @param int                  $entry_id Optional legacy entry reference.
	 * @return Member|WP_Error
	 */
	public function register( array $payload, int $entry_id = 0 ): Member|WP_Error {
		$email = sanitize_email( (string) ( $payload['email'] ?? '' ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'adam_membership_invalid_email', __( 'O endereço de email submetido é inválido.', 'adam-membership' ) );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error( 'adam_membership_email_exists', __( 'Já existe uma conta com este endereço de email.', 'adam-membership' ) );
		}

		if ( username_exists( $email ) ) {
			return new WP_Error( 'adam_membership_username_exists', __( 'Este endereço de email já está a ser usado como nome de utilizador.', 'adam-membership' ) );
		}

		$user_id = $this->create_user( $payload, $email );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		wp_new_user_notification( (int) $user_id, null, 'user' );

		$member = new Member( (int) $user_id );
		$member->initialize( $this->build_member_data( $payload ) );

		$this->logger->info(
			'Inscrição nativa submetida.',
			array(
				'user_id' => $user_id,
				'mode'    => (string) ( $payload['membership_mode'] ?? '' ),
			)
		);
		$this->history->registration_submitted( $member, $entry_id );

		return $member;
	}

	/**
	 * Create the backing WordPress user.
	 *
	 * @param array<string, mixed> $payload Registration payload.
	 * @param string               $email Email.
	 * @return int|WP_Error
	 */
	private function create_user( array $payload, string $email ): int|WP_Error {
		$full_name    = sanitize_text_field( (string) ( $payload['full_name'] ?? '' ) );
		$name_parts   = preg_split( '/\s+/', trim( $full_name ) ) ?: array();
		$first_name   = sanitize_text_field( (string) array_shift( $name_parts ) );
		$last_name    = sanitize_text_field( implode( ' ', $name_parts ) );
		$display_name = '' !== trim( $full_name ) ? $full_name : $email;

		return wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'role'         => 'subscriber',
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => $display_name,
				'nickname'     => $display_name,
			)
		);
	}

	/**
	 * Build member meta from normalized registration data.
	 *
	 * @param array<string, mixed> $payload Registration payload.
	 * @return array<string, mixed>
	 */
	private function build_member_data( array $payload ): array {
		$mode = 'external_association' === (string) ( $payload['membership_mode'] ?? '' ) ? 'external_association' : 'adam_primary';

		return array(
			'estado'                         => Member::STATUS_PENDING,
			'numero_socio'                   => '',
			'data_adesao'                    => '',
			'validade_quota'                 => '',
			'telefone'                       => sanitize_text_field( (string) ( $payload['phone'] ?? '' ) ),
			'nif'                            => sanitize_text_field( (string) ( $payload['nif'] ?? '' ) ),
			'cartao_cidadao'                 => sanitize_text_field( (string) ( $payload['citizen_card'] ?? '' ) ),
			'data_nascimento'                => sanitize_text_field( (string) ( $payload['birth_date'] ?? '' ) ),
			'morada'                         => sanitize_text_field( (string) ( $payload['address_line_1'] ?? '' ) ),
			'morada_linha_2'                 => sanitize_text_field( (string) ( $payload['address_line_2'] ?? '' ) ),
			'cidade'                         => sanitize_text_field( (string) ( $payload['city'] ?? '' ) ),
			'municipio'                      => sanitize_text_field( (string) ( $payload['municipality'] ?? '' ) ),
			'codigo_postal'                  => sanitize_text_field( (string) ( $payload['postcode'] ?? '' ) ),
			'pais'                           => sanitize_text_field( (string) ( $payload['country'] ?? '' ) ),
			'equipa'                         => sanitize_text_field( (string) ( $payload['team'] ?? '' ) ),
			'adam_membership_origin'         => $mode,
			'adam_membership_fee'            => sanitize_text_field( (string) ( $payload['membership_fee'] ?? '' ) ),
			'adam_external_association_name' => sanitize_text_field( (string) ( $payload['external_association_name'] ?? '' ) ),
			'adam_external_member_number'    => sanitize_text_field( (string) ( $payload['external_member_number'] ?? '' ) ),
			'adam_external_association_proof' => $payload['external_association_proof'] ?? '',
			'profile_photo'                  => $payload['profile_photo'] ?? '',
			'payment_receipt'                => $payload['payment_receipt'] ?? '',
		) + $this->custom_field_payload( $payload );
	}

	/**
	 * Build custom field member meta payload.
	 *
	 * @param array<string, mixed> $payload Registration payload.
	 * @return array<string, mixed>
	 */
	private function custom_field_payload( array $payload ): array {
		$custom = array();
		$fields = isset( $payload['custom_fields'] ) && is_array( $payload['custom_fields'] ) ? $payload['custom_fields'] : array();

		foreach ( $fields as $field_key => $value ) {
			$raw_key = sanitize_key( (string) $field_key );
			$key     = str_starts_with( $raw_key, 'adam_custom_' ) ? $raw_key : sanitize_key( 'adam_custom_' . $raw_key );

			if ( '' === $key ) {
				continue;
			}

			$custom[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
		}

		return $custom;
	}
}
