<?php
/**
 * Registration catalogue smoke tests.
 *
 * @package AdamMembership\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/Core/RegistrationFieldCatalog.php';
require_once dirname( __DIR__ ) . '/src/Core/SettingsRepository.php';

use AdamMembership\Core\RegistrationFieldCatalog;
use AdamMembership\Core\SettingsRepository;

/** Return simulated saved settings. */
function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['adam_catalog_options'][ $key ] ?? $default;
}

/** Sanitize a test text value. */
function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

/** Sanitize a test textarea value. */
function sanitize_textarea_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

/** Sanitize a test key. */
function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ?? '' );
}

/** Return a positive test integer. */
function absint( mixed $value ): int {
	return abs( (int) $value );
}

/**
 * Assert a registration catalogue condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   Failure message.
 */
function adam_registration_catalog_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$professions = explode( "\n", RegistrationFieldCatalog::profession_sector_options() );
$countries   = explode( "\n", RegistrationFieldCatalog::nationality_country_options() );

adam_registration_catalog_assert( 48 === count( $professions ), 'The profession catalogue must contain all 48 requested sectors.' );
adam_registration_catalog_assert( count( $professions ) === count( array_unique( $professions ) ), 'The profession catalogue contains duplicate sectors.' );
adam_registration_catalog_assert( ! in_array( 'Outra', $professions, true ), 'The profession catalogue must not contain Outra.' );
adam_registration_catalog_assert( 'Agricultura / Florestas / Pesca' === $professions[0], 'The profession catalogue is not alphabetized.' );
adam_registration_catalog_assert( 'Transportes / Logística' === $professions[47], 'The profession catalogue is not alphabetized.' );

adam_registration_catalog_assert( 249 === count( $countries ), 'The nationality catalogue must contain all 249 ISO 3166-1 entries.' );
adam_registration_catalog_assert( count( $countries ) === count( array_unique( $countries ) ), 'The nationality catalogue contains duplicate countries.' );
adam_registration_catalog_assert( in_array( 'Portugal', $countries, true ), 'Portugal is missing from the nationality catalogue.' );
adam_registration_catalog_assert( in_array( 'Timor-Leste', $countries, true ), 'Timor-Leste is missing from the nationality catalogue.' );
adam_registration_catalog_assert( 'Afeganistão' === $countries[0], 'The nationality catalogue is not alphabetized.' );
adam_registration_catalog_assert( 'Zimbabué' === $countries[248], 'The nationality catalogue is not alphabetized.' );

$form_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Form/MembershipForms.php' );

adam_registration_catalog_assert( str_contains( $form_source, 'render_searchable_select_field' ), 'The catalogues are not rendered as searchable selects.' );
adam_registration_catalog_assert( str_contains( $form_source, 'validate_catalog_field' ), 'Closed catalogue values are not validated server-side.' );
adam_registration_catalog_assert( str_contains( $form_source, "? 'Portugal'" ), 'Portugal is not the fresh-registration default.' );

$GLOBALS['adam_catalog_options']['adam_membership_form_settings'] = array(
	'registration_fields' => array(
		'profession'  => array(
			'options' => "Programador\nOutra",
			'help'    => 'Introduza outra profissão.',
		),
		'nationality' => array(
			'options' => "Portugal\nEspanha",
		),
	),
);
$settings = ( new SettingsRepository() )->membership_form_settings();

adam_registration_catalog_assert( RegistrationFieldCatalog::profession_sector_options() === $settings['registration_fields']['profession']['options'], 'Saved legacy professions can override the canonical catalogue.' );
adam_registration_catalog_assert( RegistrationFieldCatalog::nationality_country_options() === $settings['registration_fields']['nationality']['options'], 'Saved legacy nationalities can override the ISO catalogue.' );
adam_registration_catalog_assert( ! str_contains( $settings['registration_fields']['profession']['help'], 'outra' ), 'Legacy free-text profession help remains visible.' );

echo "Registration catalogue smoke tests passed.\n";
