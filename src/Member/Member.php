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
	 * Pending member status.
	 */
	public const STATUS_PENDING = 'Pendente';

	/**
	 * Active member status.
	 */
	public const STATUS_ACTIVE = 'Ativo';

	/**
	 * Rejected member status.
	 */
	public const STATUS_REJECTED = 'Rejeitado';

	/**
	 * Member field defaults.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULT_DATA = array(
		'estado'          => self::STATUS_PENDING,
		'numero_socio'    => '',
		'data_adesao'     => '',
		'validade_quota'  => '',
		'telefone'        => '',
		'nif'             => '',
		'cartao_cidadao'  => '',
		'data_nascimento' => '',
		'morada'          => '',
		'equipa'          => '',
		'profile_photo'   => '',
		'payment_receipt' => '',
	);

	/**
	 * WordPress user ID.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Cached WordPress user object.
	 *
	 * @var WP_User|null
	 */
	private ?WP_User $user = null;

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
	 * Get the backing WordPress user.
	 */
	public function user(): ?WP_User {
		if ( null === $this->user ) {
			$user = get_user_by( 'id', $this->user_id );

			$this->user = $user instanceof WP_User ? $user : null;
		}

		return $this->user;
	}

	/**
	 * Get the member email address.
	 */
	public function email(): string {
		$user = $this->user();

		return $user instanceof WP_User ? (string) $user->user_email : '';
	}

	/**
	 * Get the member full name.
	 */
	public function full_name(): string {
		$user = $this->user();

		if ( ! $user instanceof WP_User ) {
			return '';
		}

		$first_name = (string) get_user_meta( $this->user_id, 'first_name', true );
		$last_name  = (string) get_user_meta( $this->user_id, 'last_name', true );
		$full_name  = trim( $first_name . ' ' . $last_name );

		return '' !== $full_name ? $full_name : (string) $user->display_name;
	}

	/**
	 * Get the WordPress registration date.
	 */
	public function registration_date(): string {
		$user = $this->user();

		if ( ! $user instanceof WP_User ) {
			return '';
		}

		$timestamp = strtotime( (string) $user->user_registered );

		return false === $timestamp ? '' : wp_date( get_option( 'date_format' ), $timestamp );
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
			$data[ $field_name ] = $this->field( $field_name );
		}

		return $data;
	}

	/**
	 * Retrieve the member status.
	 */
	public function status(): string {
		$status = $this->field( 'estado' );

		return is_scalar( $status ) && '' !== (string) $status ? (string) $status : self::STATUS_PENDING;
	}

	/**
	 * Get a member field value.
	 *
	 * @param string $field_name Member field name.
	 * @return mixed
	 */
	public function field( string $field_name ): mixed {
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field_name, 'user_' . $this->user_id );

			if ( null !== $value && false !== $value ) {
				return $value;
			}
		}

		return get_user_meta( $this->user_id, $field_name, true );
	}

	/**
	 * Get a media URL stored on a member field.
	 *
	 * @param string $field_name Member media field name.
	 */
	public function media_url( string $field_name ): string {
		return $this->normalize_media_url( $this->field( $field_name ) );
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

	/**
	 * Normalize a stored media reference into a URL.
	 *
	 * @param mixed $value Stored media value.
	 */
	private function normalize_media_url( mixed $value ): string {
		if ( is_numeric( $value ) ) {
			$url = wp_get_attachment_url( absint( $value ) );

			return false !== $url ? $url : '';
		}

		if ( is_string( $value ) && wp_http_validate_url( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		foreach ( array( 'url', 'file_url', 'source_url' ) as $url_key ) {
			if ( isset( $value[ $url_key ] ) && is_string( $value[ $url_key ] ) && wp_http_validate_url( $value[ $url_key ] ) ) {
				return $value[ $url_key ];
			}
		}

		foreach ( array( 'ID', 'id', 'attachment_id' ) as $id_key ) {
			if ( isset( $value[ $id_key ] ) && is_numeric( $value[ $id_key ] ) ) {
				$url = wp_get_attachment_url( absint( $value[ $id_key ] ) );

				return false !== $url ? $url : '';
			}
		}

		return '';
	}
}
