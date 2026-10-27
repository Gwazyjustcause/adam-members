<?php
/** Render the production card and section template with synthetic member data. */
declare(strict_types=1);

function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return esc_attr( $value ); }
function esc_url( $value ) { return $value; }
function esc_url_raw( $value ) { return $value; }
function esc_html_e( $value, $domain = '' ) { echo esc_html( $value ); }
function esc_attr_e( $value, $domain = '' ) { echo esc_attr( $value ); }
function __( $value, $domain = '' ) { return $value; }
function wp_kses_post( $value ) { return $value; }
function sanitize_html_class( $value ) { return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ); }
function sanitize_key( $value ) { return strtolower( sanitize_html_class( $value ) ); }

require __DIR__ . '/../src/Member/Member.php';
require __DIR__ . '/../src/Member/CardService.php';

$cards = ( new ReflectionClass( AdamMembership\Member\CardService::class ) )->newInstanceWithoutConstructor();
$svg = static fn( $content ) => 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160">' . $content . '</svg>' );
$card_data = array(
	'association_name' => 'Associação Desportiva de Airsoft do Mondego',
	'association_logo' => $svg( '<rect width="160" height="160" fill="white"/><text x="10" y="90" fill="#365314" font-size="44" font-weight="bold">ADAM</text>' ),
	'photo_url' => '', 'initials' => 'MS',
	'member_name' => 'Maria Alexandra de Albuquerque e Silva',
	'member_number_ui' => 'ADAM-000123', 'status' => 'Ativo',
	'joined_date' => '01/01/2020', 'expiry_date' => '31/12/2026',
	'qr_image_url' => $svg( '<rect width="160" height="160" fill="white"/><path d="M10 10h45v45H10z M105 10h45v45h-45z M10 105h45v45H10z M70 70h20v20H70z M105 105h45v45h-45z" fill="black"/>' ),
);
$card_presentation = array(
	'allow_data_uris' => true,
	'founder_badge' => 'Sócio fundador',
	'active_title' => array( 'name' => 'Guardião da Associação do Mondego', 'rarity' => 'rare' ),
);
$validation_url = '#validation';
$qr_image_url = $card_data['qr_image_url'];
$download_filename = 'cartao-adam-test.png';

// Execute the actual section template so markup changes are covered as well.
$source = file_get_contents( __DIR__ . '/../src/Member/MemberArea.php' );
$start = strpos( $source, '<section class="adam-card adam-digital-card-section"' );
$end = strpos( $source, '</section>', $start ) + strlen( '</section>' );
$template = substr( $source, $start, $end - $start );
$render = function () use ( $template, $card_data, $card_presentation, $validation_url, $qr_image_url, $download_filename ) {
	eval( '?>' . $template );
};
echo '<!doctype html><html lang="pt"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><body style="margin:0;font-family:Arial,sans-serif;background:#f4f7f3"><main class="adam-member-area adam-member-dashboard"><section class="adam-member-hero"><div><p class="adam-eyebrow">Área do sócio</p><h2>Olá, Maria</h2><p>O teu espaço na ADAM.</p></div><div class="adam-hero-status"><span class="adam-badge active">Ativo</span></div></section>';
$renderer = new class( $cards ) {
	public function __construct( public $cards ) {}
};
$render->call( $renderer );
echo '</main></body></html>';
