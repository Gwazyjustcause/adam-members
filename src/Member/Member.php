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
	 * Expired member status.
	 */
	public const STATUS_EXPIRED = 'Expirado';

	/**
	 * Renewal pending member status.
	 */
	public const STATUS_RENEWAL_PENDING = 'Renovação em análise';

	/**
	 * Active quota status.
	 */
	public const QUOTA_ACTIVE = 'active';

	/**
	 * Expired quota status.
	 */
	public const QUOTA_EXPIRED = 'expired';

	/**
	 * Quota expiring within 30 days.
	 */
	public const QUOTA_EXPIRING_SOON = 'expiring_soon';

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
		'adam_founder_status' => '',
		'adam_founder_number' => '',
		'adam_loyalty_unlocked' => array(),
		'adam_active_title_reward' => '',
		'adam_active_card_theme' => '',
		'adam_active_card_frame' => '',
		'telefone'        => '',
		'nif'             => '',
		'cartao_cidadao'  => '',
		'data_nascimento' => '',
		'morada'          => '',
		'morada_linha_2'  => '',
		'codigo_postal'   => '',
		'cidade'          => '',
		'municipio'       => '',
		'pais'            => '',
		'contacto_emergencia' => '',
		'equipa'          => '',
		'adam_membership_origin' => 'adam_primary',
		'adam_membership_fee' => '',
		'adam_external_association_name' => '',
		'adam_external_member_number' => '',
		'adam_external_association_proof' => '',
		'profile_photo'   => '',
		'payment_receipt' => '',
		'motivo_rejeicao'     => '',
		'nota_rejeicao_admin' => '',
	);

	/**
	 * Member fields that must be read as canonical dates for logic.
	 *
	 * @var array<int, string>
	 */
	private const DATE_FIELDS = array(
		'data_adesao',
		'validade_quota',
		'data_nascimento',
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

		$timestamp = $this->registration_timestamp();

		return 0 === $timestamp ? '' : wp_date( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Get the WordPress registration timestamp.
	 */
	public function registration_timestamp(): int {
		$user = $this->user();

		if ( ! $user instanceof WP_User ) {
			return 0;
		}

		$timestamp = strtotime( (string) $user->user_registered );

		return false === $timestamp ? 0 : $timestamp;
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
			if ( ! array_key_exists( $field_name, self::DEFAULT_DATA ) && ! str_starts_with( (string) $field_name, 'adam_custom_' ) ) {
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
	 * Retrieve the saved membership status from the canonical member field.
	 *
	 * The stored source of truth is the "estado" field. Admin/member screens
	 * should use effective_status() when the lifecycle state matters.
	 */
	public function status(): string {
		$status = $this->field( 'estado' );

		return is_scalar( $status ) && '' !== (string) $status ? (string) $status : self::STATUS_PENDING;
	}

	/**
	 * Get the effective membership status for frontend and admin screens.
	 *
	 * This derives "Expirado" from an otherwise active record when the
	 * normalized quota expiry field indicates the quota is no longer current.
	 */
	public function effective_status(): string {
		if ( self::STATUS_ACTIVE === $this->status() && self::QUOTA_EXPIRED === $this->quota_status() ) {
			return self::STATUS_EXPIRED;
		}

		return $this->status();
	}

	/**
	 * Get the quota status.
	 */
	public function quota_status(): string {
		$timestamp = $this->quota_expiry_timestamp();

		if ( 0 === $timestamp ) {
			return self::QUOTA_EXPIRED;
		}

		$today = strtotime( wp_date( 'Y-m-d', current_time( 'timestamp' ) ) );

		if ( false === $today || $timestamp < $today ) {
			return self::QUOTA_EXPIRED;
		}

		if ( $timestamp <= strtotime( '+30 days', $today ) ) {
			return self::QUOTA_EXPIRING_SOON;
		}

		return self::QUOTA_ACTIVE;
	}

	/**
	 * Get the quota expiry timestamp.
	 */
	public function quota_expiry_timestamp(): int {
		$value = $this->field( 'validade_quota' );

		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		$date = trim( (string) $value );

		if ( '' === $date ) {
			return 0;
		}

		$timestamp = strtotime( $date );

		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Get the numeric portion of the ADAM member number for sorting and duplicate checks.
	 */
	public function member_number_value(): int {
		return self::member_number_numeric_value( (string) $this->field( 'numero_socio' ) );
	}

	/**
	 * Check whether the member is a permanent founding member.
	 */
	public function is_founder(): bool {
		$value = $this->field( 'adam_founder_status' );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'sim', 'true' ), true );
	}

	/**
	 * Get the founder number.
	 */
	public function founder_number(): int {
		return absint( $this->field( 'adam_founder_number' ) );
	}

	/**
	 * Get loyalty reward tiers unlocked for the member.
	 *
	 * @return array<int, string>
	 */
	public function loyalty_unlocked(): array {
		$value = $this->field( 'adam_loyalty_unlocked' );

		if ( is_array( $value ) ) {
			return array_values(
				array_filter(
					array_map(
						static fn ( mixed $item ): string => sanitize_key( (string) $item ),
						$value
					)
				)
			);
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );

			if ( is_array( $decoded ) ) {
				return array_values(
					array_filter(
						array_map(
							static fn ( mixed $item ): string => sanitize_key( (string) $item ),
							$decoded
						)
					)
				);
			}
		}

		return array();
	}

	/**
	 * Get the canonical join-date timestamp.
	 */
	public function join_date_timestamp(): int {
		$value = $this->field( 'data_adesao' );

		if ( ! is_scalar( $value ) ) {
			return 0;
		}

		$date = trim( (string) $value );

		if ( '' === $date ) {
			return 0;
		}

		$timestamp = strtotime( $date );

		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Extract a sortable numeric value from a member number.
	 *
	 * @param string $member_number Member number.
	 */
	public static function member_number_numeric_value( string $member_number ): int {
		if ( '' === trim( $member_number ) ) {
			return 0;
		}

		$digits = preg_replace( '/\D+/', '', $member_number );

		return null === $digits || '' === $digits ? 0 : absint( $digits );
	}
	
	/**
 	* Check if the member is pending.
	 */
	public function isPending(): bool {
	return self::STATUS_PENDING === $this->status();
	}

	/**
	 * Check if the member is active.
	 */
	public function isActive(): bool {
		return self::STATUS_ACTIVE === $this->effective_status();
	}

	/**
	 * Check if the member has been rejected.
	 */
	public function isRejected(): bool {
		return self::STATUS_REJECTED === $this->status();
	}

	/**
	 * Check if the member has expired.
	 */
	public function isExpired(): bool {
		return self::STATUS_EXPIRED === $this->effective_status();
	}

	/**
	 * Check if a renewal is waiting for admin review.
	 */
	public function isRenewalPending(): bool {
		return self::STATUS_RENEWAL_PENDING === $this->status();
	}

	/**
	 * Check if the member can start renewal from the member area.
	 */
	public function can_renew(): bool {
		if ( $this->isRejected() || $this->isRenewalPending() ) {
			return false;
		}

		return $this->isExpired() || self::QUOTA_EXPIRING_SOON === $this->quota_status();
	}

	/**
 	* Approve the member.
 	*/
	public function approve(): void {
	$this->update_field( 'estado', self::STATUS_ACTIVE );

		if ( '' === (string) $this->field( 'data_adesao' ) ) {
			$this->update_field(
				'data_adesao',
				current_time( 'Y-m-d' )
			);
		}
	}

	/**
 	* Reject the member.
 	*/
	public function reject( string $reason = '', string $note = '' ): void {
		$this->update_field( 'estado', self::STATUS_REJECTED );
		$this->update_field( 'motivo_rejeicao', $reason );
		$this->update_field( 'nota_rejeicao_admin', $note );
	}

	/**
 	* Set the member back to pending.
 	*/
	public function resetToPending(): void {
		$this->update_field( 'estado', self::STATUS_PENDING );
	}

	/**
	 * Get a member field value.
	 *
	 * @param string $field_name Member field name.
	 * @return mixed
	 */
	public function field( string $field_name ): mixed {
		if ( in_array( $field_name, self::DATE_FIELDS, true ) ) {
			return $this->date_field( $field_name );
		}

		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field_name, 'user_' . $this->user_id );

			if ( null !== $value && false !== $value ) {
				return $value;
			}
		}

		return get_user_meta( $this->user_id, $field_name, true );
	}

	/**
	 * Get a date field in canonical Y-m-d format where possible.
	 *
	 * @param string $field_name Member date field name.
	 */
	private function date_field( string $field_name ): string {
		$value = null;

		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field_name, 'user_' . $this->user_id, false );
		}

		if ( null === $value || false === $value || '' === $value ) {
			$value = get_user_meta( $this->user_id, $field_name, true );
		}

		return $this->normalize_date_value( $value );
	}

	/**
	 * Normalize common WordPress/ACF date values to Y-m-d.
	 *
	 * @param mixed $value Raw date value.
	 */
	private function normalize_date_value( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$date = trim( (string) $value );

		if ( '' === $date ) {
			return '';
		}

		if ( preg_match( '/^\d{8}$/', $date ) ) {
			return substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		if ( preg_match( '/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches ) && checkdate( absint( $matches[2] ), absint( $matches[1] ), absint( $matches[3] ) ) ) {
			return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
		}

		return '';
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

		if ( is_string( $value ) && $this->is_allowed_media_url( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		foreach ( array( 'url', 'file_url', 'source_url' ) as $url_key ) {
			if ( isset( $value[ $url_key ] ) && is_string( $value[ $url_key ] ) && $this->is_allowed_media_url( $value[ $url_key ] ) ) {
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

	/**
	 * Determine whether a media URL belongs to this WordPress site.
	 *
	 * @param string $url Media URL.
	 */
	private function is_allowed_media_url( string $url ): bool {
		if ( ! wp_http_validate_url( $url ) ) {
			return false;
		}

		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $url_host ) && is_string( $site_host ) && strtolower( $url_host ) === strtolower( $site_host );
	}
}
