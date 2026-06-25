<?php
/**
 * Member model.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use WP_User;

/**
 * Represents an ADAM member backed by a WordPress user.
 */
final class Member {
	/**
	 * Default member status.
	 */
	public const STATUS_PENDING = 'Pendente';

	/**
	 * Member field defaults.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULT_DATA = array(
		'estado'            => self::STATUS_PENDING,
		'numero_socio'      => '',
		'data_adesao'       => '',
		'validade_quota'    => '',
		'telefone'          => '',
		'nif'               => '',
		'cartao_cidadao'    => '',
		'data_nascimento'   => '',
		'morada'            => '',
		'equipa'            => '',
		'profile_photo'     => '',
		'payment_receipt'   => '',
	);

	/**
	 * WordPress user ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Create a member model for a WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function __construct( int $user_id ) {
		$this->user_id = $user_id;
	}

	/**
	 * Load a member by WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function load( int $user_id ): ?self {
		$user = get_user_by( 'id', $user_id );

		return $user instanceof WP_User ? new self( $user_id ) : null;
	}

	/**
	 * Get the member WordPress user ID.
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Initialize member data with defaults and submitted values.
	 *
	 * @param array<string, mixed> $data Member data to store.
	 */
	public function initialize( array $data ): void {
		$this->save( array_merge( self::DEFAULT_DATA, $data ) );
	}

	/**
	 * Save member information.
	 *
	 * @param array<string, mixed> $data Member data keyed by member field name.
	 */
	public function save( array $data ): void {
		foreach ( $data as $field_name => $value ) {
			if ( ! array_key_exists( $field_name, self::DEFAULT_DATA ) ) {
				continue;
			}

			$this->update_field( (string) $field_name, $value );
		}
	}

	/**
	 * Load all known member information.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$data = array();

		foreach ( array_keys( self::DEFAULT_DATA ) as $field_name ) {
			$data[ $field_name ] = $this->get_field( $field_name );
		}

		return $data;
	}

	/**
	 * Retrieve the member status.
	 */
	public function status(): string {
		$status = $this->get_field( 'estado' );

		return is_scalar( $status ) && '' !== (string) $status ? (string) $status : self::STATUS_PENDING;
	}

	/**
	 * Get a member field value.
	 *
	 * @param string $field_name Member field name.
	 * @return mixed
	 */
	private function get_field( string $field_name ): mixed {
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field_name, 'user_' . $this->user_id );

			if ( null !== $value && false !== $value ) {
				return $value;
			}
		}

		return get_user_meta( $this->user_id, $field_name, true );
	}

	/**
	 * Update a member field value.
	 *
	 * @param string $field_name Member field name.
	 * @param mixed  $value      Member field value.
	 */
	private function update_field( string $field_name, mixed $value ): void {
		if ( function_exists( 'update_field' ) ) {
			update_field( $field_name, $value, 'user_' . $this->user_id );
			return;
		}

		update_user_meta( $this->user_id, $field_name, $value );
	}
}
