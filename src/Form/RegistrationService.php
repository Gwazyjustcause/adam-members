<?php
/**
 * Native registration service.
 *
 * @package AdamMembership\Form
 */

declare(strict_types=1);

namespace AdamMembership\Form;

use AdamMembership\Emails\EmailService;
use AdamMembership\Helpers\Logger;
use AdamMembership\Member\AccountSetup;
use AdamMembership\Member\HistoryService;
use AdamMembership\Member\Member;
use AdamMembership\Member\MemberRepository;
use AdamMembership\Member\NifValidator;
use AdamMembership\Team\TeamRepository;
use WP_Error;

/**
 * Creates pending member accounts from normalized registration payloads.
 */
final class RegistrationService {
	private Logger $logger;
	private HistoryService $history;
	private EmailService $email;
	private AccountSetup $account_setup;
	private TeamRepository $teams;

	/**
	 * Member repository.
	 *
	 * @var MemberRepository
	 */
	private MemberRepository $members;

	/**
	 * Create the registration service.
	 *
	 * @param Logger             $logger        Logger helper.
	 * @param HistoryService     $history       History service.
	 * @param EmailService       $email         Email service.
	 * @param AccountSetup       $account_setup Account setup service.
	 * @param TeamRepository     $teams         Team repository.
	 * @param MemberRepository   $members       Member repository.
	 */
	public function __construct( Logger $logger, HistoryService $history, EmailService $email, AccountSetup $account_setup, TeamRepository $teams, MemberRepository $members ) {
		$this->logger        = $logger;
		$this->history       = $history;
		$this->email         = $email;
		$this->account_setup = $account_setup;
		$this->teams         = $teams;
		$this->members       = $members;
	}

	/**
	 * Validate and normalize a registration NIF.
	 *
	 * @param mixed $value Submitted NIF.
	 * @return string|WP_Error
	 */
	public function validate_nif( mixed $value ): string|WP_Error {
		$nif = NifValidator::normalize( $value );

		if ( ! NifValidator::is_valid( $nif ) ) {
			return new WP_Error(
				'adam_membership_invalid_nif',
				__( 'O NIF introduzido não é válido. Verifique o número e tente novamente.', 'adam-membership' )
			);
		}

		if ( $this->members->nif_exists( $nif ) ) {
			return $this->duplicate_nif_error();
		}

		return $nif;
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
		$nif   = $this->validate_nif( $payload['nif'] ?? '' );

		if ( $nif instanceof WP_Error ) {
			return $nif;
		}

		$payload['nif'] = $nif;

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'adam_membership_invalid_email', __( 'O endereço de email submetido é inválido.', 'adam-membership' ) );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error( 'adam_membership_email_exists', __( 'Já existe uma conta com este endereço de email.', 'adam-membership' ) );
		}

		if ( username_exists( $email ) ) {
			return new WP_Error( 'adam_membership_username_exists', __( 'Este endereço de email já está a ser usado como nome de utilizador.', 'adam-membership' ) );
		}

		$payload = $this->resolve_team( $payload );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$lock = $this->acquire_nif_lock( $nif );

		if ( $lock instanceof WP_Error ) {
			return $lock;
		}

		try {
			if ( $this->members->nif_exists( $nif ) ) {
				return $this->duplicate_nif_error();
			}

			$user_id = $this->create_user( $payload, $email );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$member = new Member( (int) $user_id );
			$member->initialize( $this->build_member_data( $payload ) );
		} finally {
			$this->release_nif_lock( $lock );
		}

		$user = get_user_by( 'ID', (int) $user_id );

		if ( $user instanceof \WP_User ) {
			$setup_link = $this->account_setup->issue_setup_link( $user );

			if ( $this->email->send_registration_received_email( $member, $setup_link ) ) {
				$this->history->account_setup_link_sent( $member );
			} else {
				$this->logger->error(
					'Falha ao enviar o email de definição de acesso após a inscrição.',
					array(
						'user_id' => $user_id,
						'email'   => wp_hash( (string) $user->user_email ),
					)
				);
			}
		}

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
	 * Return the canonical duplicate-NIF error.
	 */
	private function duplicate_nif_error(): WP_Error {
		return new WP_Error(
			'adam_membership_duplicate_nif',
			__( 'Já existe uma inscrição associada a este NIF. Se pretende renovar a sua quota ou atualizar os seus dados, utilize o formulário de renovação em vez de criar uma nova inscrição.', 'adam-membership' )
		);
	}

	/**
	 * Acquire an atomic, short-lived lock for a NIF registration.
	 *
	 * @param string $nif Normalized NIF.
	 * @return array{key:string,token:string}|WP_Error
	 */
	private function acquire_nif_lock( string $nif ): array|WP_Error {
		$key     = 'adam_membership_nif_lock_' . hash( 'sha256', $nif );
		$token   = wp_generate_uuid4();
		$payload = array(
			'token'      => $token,
			'created_at' => time(),
		);

		if ( add_option( $key, $payload, '', false ) ) {
			return array(
				'key'   => $key,
				'token' => $token,
			);
		}

		$current = get_option( $key, array() );

		if ( is_array( $current ) && absint( $current['created_at'] ?? 0 ) < time() - 120 ) {
			delete_option( $key );

			if ( add_option( $key, $payload, '', false ) ) {
				return array(
					'key'   => $key,
					'token' => $token,
				);
			}
		}

		if ( $this->members->nif_exists( $nif ) ) {
			return $this->duplicate_nif_error();
		}

		return new WP_Error(
			'adam_membership_nif_check_in_progress',
			__( 'Não foi possível confirmar o NIF neste momento. Aguarde alguns segundos e tente novamente.', 'adam-membership' )
		);
	}

	/**
	 * Release a NIF registration lock owned by this request.
	 *
	 * @param array{key:string,token:string} $lock Lock details.
	 */
	private function release_nif_lock( array $lock ): void {
		$current = get_option( $lock['key'], array() );

		if ( is_array( $current ) && hash_equals( $lock['token'], (string) ( $current['token'] ?? '' ) ) ) {
			delete_option( $lock['key'] );
		}
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
			'telefone_fixo'                  => sanitize_text_field( (string) ( $payload['telephone'] ?? '' ) ),
			'nif'                            => sanitize_text_field( (string) ( $payload['nif'] ?? '' ) ),
			'cartao_cidadao'                 => sanitize_text_field( (string) ( $payload['citizen_card'] ?? '' ) ),
			'documento_validade'             => sanitize_text_field( (string) ( $payload['document_expiry_date'] ?? '' ) ),
			'documento_local_emissao'        => sanitize_text_field( (string) ( $payload['document_issuing_place'] ?? '' ) ),
			'data_nascimento'                => sanitize_text_field( (string) ( $payload['birth_date'] ?? '' ) ),
			'estado_civil'                   => sanitize_text_field( (string) ( $payload['marital_status'] ?? '' ) ),
			'genero'                         => sanitize_text_field( (string) ( $payload['gender'] ?? '' ) ),
			'profissao'                      => sanitize_text_field( (string) ( $payload['profession'] ?? '' ) ),
			'naturalidade'                   => sanitize_text_field( (string) ( $payload['birthplace'] ?? '' ) ),
			'nacionalidade'                  => sanitize_text_field( (string) ( $payload['nationality'] ?? '' ) ),
			'morada'                         => sanitize_text_field( (string) ( $payload['address_line_1'] ?? '' ) ),
			'morada_linha_2'                 => sanitize_text_field( (string) ( $payload['address_line_2'] ?? '' ) ),
			'cidade'                         => sanitize_text_field( (string) ( $payload['city'] ?? '' ) ),
			'municipio'                      => sanitize_text_field( (string) ( $payload['municipality'] ?? '' ) ),
			'codigo_postal'                  => sanitize_text_field( (string) ( $payload['postcode'] ?? '' ) ),
			'pais'                           => sanitize_text_field( (string) ( $payload['country'] ?? '' ) ),
			'equipa'                         => sanitize_text_field( (string) ( $payload['team'] ?? '' ) ),
			'team_id'                        => absint( $payload['team_id'] ?? 0 ),
			'adam_membership_origin'         => $mode,
			'adam_apd_management_status'     => 'adam_primary' === $mode ? Member::APD_PENDING : Member::APD_EXTERNAL,
			'adam_membership_fee'            => sanitize_text_field( (string) ( $payload['membership_fee'] ?? '' ) ),
			'adam_external_association_name' => sanitize_text_field( (string) ( $payload['external_association_name'] ?? '' ) ),
			'adam_external_member_number'    => sanitize_text_field( (string) ( $payload['external_member_number'] ?? '' ) ),
			'adam_external_association_proof' => $payload['external_association_proof'] ?? '',
			'profile_photo'                  => $payload['profile_photo'] ?? '',
			'payment_receipt'                => $payload['payment_receipt'] ?? '',
		) + $this->custom_field_payload( $payload );
	}

	/**
	 * Resolve the optional submitted team through the canonical repository.
	 *
	 * @param array<string, mixed> $payload Registration payload.
	 * @return array<string, mixed>|WP_Error
	 */
	private function resolve_team( array $payload ): array|WP_Error {
		$selection = $this->teams->resolve_selection( (string) ( $payload['team'] ?? '' ) );

		if ( null === $selection ) {
			return new WP_Error(
				'adam_membership_team_unavailable',
				__( 'Não foi possível guardar a equipa indicada. Tente novamente.', 'adam-membership' )
			);
		}

		$payload['team']    = $selection['name'];
		$payload['team_id'] = $selection['team_id'];

		return $payload;
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
