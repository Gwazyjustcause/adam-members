<?php
/**
 * Server-side digital card PNG renderer.
 *
 * @package AdamMembership\Member
 */

declare(strict_types=1);

namespace AdamMembership\Member;

use WP_Error;

final class CardImageRenderer {
	private const WIDTH = 1011;
	private const HEIGHT = 638;
	private const CACHE_VERSION = 'v1';

	public function available_engine(): string {
		if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
			return 'imagick';
		}

		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' ) ) {
			return 'gd';
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $card_data
	 * @param array<string, mixed> $presentation
	 * @return array{path:string,filename:string}
	 */
	public function cached_png( Member $member, array $card_data, array $presentation ): array|WP_Error {
		$engine = $this->available_engine();

		if ( '' === $engine ) {
			return new WP_Error(
				'adam_membership_card_renderer_unavailable',
				__( 'O servidor precisa de Imagick ou GD ativo para gerar o cartão em PNG.', 'adam-membership' )
			);
		}

		$cache_dir = $this->cache_directory();

		if ( is_wp_error( $cache_dir ) ) {
			return $cache_dir;
		}

		$signature = md5(
			wp_json_encode(
				array(
					'version'      => self::CACHE_VERSION,
					'engine'       => $engine,
					'member_id'    => $member->user_id(),
					'card_data'    => $card_data,
					'presentation' => $presentation,
				)
			)
		);

		$filename = sprintf( 'cartao-adam-%1$d-%2$s.png', $member->user_id(), $signature );
		$path     = trailingslashit( $cache_dir ) . $filename;

		if ( file_exists( $path ) && filesize( $path ) > 0 ) {
			return array(
				'path'     => $path,
				'filename' => $filename,
			);
		}

		$png_bytes = $this->render_png_bytes( $member, $card_data, $presentation );

		if ( is_wp_error( $png_bytes ) ) {
			return $png_bytes;
		}

		file_put_contents( $path, $png_bytes );

		return array(
			'path'     => $path,
			'filename' => $filename,
		);
	}

	/**
	 * @param array<string, mixed> $card_data
	 * @param array<string, mixed> $presentation
	 */
	private function render_png_bytes( Member $member, array $card_data, array $presentation ): string|WP_Error {
		$engine = $this->available_engine();

		if ( 'imagick' === $engine ) {
			return $this->render_with_imagick( $member, $card_data, $presentation );
		}

		if ( 'gd' === $engine ) {
			return $this->render_with_gd( $member, $card_data, $presentation );
		}

		return new WP_Error(
			'adam_membership_card_renderer_unavailable',
			__( 'O servidor precisa de Imagick ou GD ativo para gerar o cartão em PNG.', 'adam-membership' )
		);
	}

	/**
	 * @param array<string, mixed> $card_data
	 * @param array<string, mixed> $presentation
	 */
	private function render_with_imagick( Member $member, array $card_data, array $presentation ): string|WP_Error {
		try {
			$svg = $this->svg_markup( $member, $card_data, $presentation );

			$image = new \Imagick();
			$image->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
			$image->readImageBlob( $svg );
			$image->setImageFormat( 'png32' );

			$blob = $image->getImagesBlob();
			$image->clear();
			$image->destroy();

			return is_string( $blob ) && '' !== $blob
				? $blob
				: new WP_Error( 'adam_membership_card_png_empty', __( 'Nao foi possivel gerar a imagem PNG do cartão.', 'adam-membership' ) );
		} catch ( \Throwable $exception ) {
			return new WP_Error(
				'adam_membership_card_imagick_failed',
				sprintf(
					/* translators: %s: renderer exception message */
					__( 'Falha ao gerar o cartão com Imagick: %s', 'adam-membership' ),
					$exception->getMessage()
				)
			);
		}
	}

	/**
	 * @param array<string, mixed> $card_data
	 * @param array<string, mixed> $presentation
	 */
	private function render_with_gd( Member $member, array $card_data, array $presentation ): string|WP_Error {
		$image = imagecreatetruecolor( self::WIDTH, self::HEIGHT );

		if ( false === $image ) {
			return new WP_Error( 'adam_membership_card_gd_failed', __( 'Nao foi possivel criar a imagem GD do cartão.', 'adam-membership' ) );
		}

		imageantialias( $image, true );

		$greenDark = $this->gd_color( $image, '#143826' );
		$greenMid  = $this->gd_color( $image, '#1f5a33' );
		$greenLite = $this->gd_color( $image, '#4e7f63', 68 );
		$white     = $this->gd_color( $image, '#ffffff' );
		$panel     = $this->gd_color( $image, '#557f63', 38 );

		imagefill( $image, 0, 0, $greenDark );
		for ( $y = 0; $y < self::HEIGHT; $y++ ) {
			$ratio = $y / max( 1, self::HEIGHT - 1 );
			$line  = imagecolorallocate(
				$image,
				(int) round( 20 + ( 31 - 20 ) * $ratio ),
				(int) round( 56 + ( 90 - 56 ) * $ratio ),
				(int) round( 38 + ( 51 - 38 ) * $ratio )
			);
			imageline( $image, 0, $y, self::WIDTH, $y, $line );
		}

		for ( $x = -self::HEIGHT; $x < self::WIDTH; $x += 28 ) {
			imageline( $image, $x, 0, $x + self::HEIGHT, self::HEIGHT, $greenLite );
		}

		$this->gd_rounded_rect( $image, 28, 20, 160, 86, 18, $white, true );
		$this->gd_rounded_rect( $image, 28, 162, 144, 224, 20, $greenLite, false, 4 );
		$this->gd_rounded_rect( $image, 861, 158, 122, 170, 20, $white, true );
		$this->gd_rounded_rect( $image, 180, 208, 160, 42, 18, $greenLite, true );
		$this->gd_rounded_rect( $image, 182, 256, 162, 52, 18, $this->gd_color( $image, '#2f6a47', 6 ), true );
		$this->gd_rounded_rect( $image, 176, 434, 118, 46, 18, $greenLite, true );
		$this->gd_rounded_rect( $image, 28, 480, 322, 86, 18, $panel, true );
		$this->gd_rounded_rect( $image, 366, 480, 322, 86, 18, $panel, true );
		$this->gd_rounded_rect( $image, 704, 480, 279, 86, 18, $panel, true );
		$this->gd_rounded_rect( $image, 894, 22, 88, 42, 18, $this->gd_color( $image, '#dcfce7' ), true );

		$this->gd_draw_image( $image, $this->image_binary_from_url( (string) $card_data['association_logo'] ), 34, 26, 148, 74 );
		$this->gd_draw_image( $image, $this->qr_binary( $member, (string) $card_data['qr_image_url'] ), 872, 170, 100, 100 );
		$this->gd_draw_image( $image, $this->image_binary_from_member_field( $member, 'profile_photo' ), 34, 168, 132, 212 );

		if ( '' === $this->image_binary_from_member_field( $member, 'profile_photo' ) ) {
			imagestring( $image, 5, 86, 258, (string) $card_data['initials'], $white );
		}

		imagestring( $image, 3, 204, 34, 'ASSOCIACAO DESPORTIVA', $white );
		imagestring( $image, 5, 204, 64, (string) $card_data['association_name'], $white );
		imagestring( $image, 4, 920, 34, (string) $card_data['status'], $greenMid );
		imagestring( $image, 3, 210, 176, 'NOME DO SOCIO', $white );
		imagestring( $image, 5, 214, 274, strtoupper( (string) $card_data['member_name'] ), $white );
		imagestring( $image, 4, 210, 446, (string) $card_data['member_number_ui'], $white );
		imagestring( $image, 4, 196, 219, strtoupper( (string) ( $presentation['active_title']['name'] ?? __( 'Titulo ativo', 'adam-membership' ) ) ), $white );
		imagestring( $image, 3, 886, 284, 'VALIDAR CARTAO', $greenMid );

		imagestring( $image, 3, 40, 496, 'N. DE SOCIO', $white );
		imagestring( $image, 5, 40, 528, (string) $card_data['member_number_ui'], $white );
		imagestring( $image, 3, 378, 496, 'DATA DE ADESAO', $white );
		imagestring( $image, 5, 378, 528, (string) $card_data['joined_date'], $white );
		imagestring( $image, 3, 716, 496, 'VALIDO ATE', $white );
		imagestring( $image, 5, 716, 528, (string) $card_data['expiry_date'], $white );
		imagestring( $image, 4, 40, 600, 'AIRSOFTMONDEGO.PT', $white );
		imagestring( $image, 4, 834, 600, 'CARTAO DIGITAL ADAM', $white );

		ob_start();
		imagepng( $image );
		$png = (string) ob_get_clean();
		imagedestroy( $image );

		return '' !== $png
			? $png
			: new WP_Error( 'adam_membership_card_gd_failed', __( 'Nao foi possivel gerar a imagem PNG do cartão.', 'adam-membership' ) );
	}

	private function cache_directory(): string|WP_Error {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'adam_membership_card_cache_dir', (string) $uploads['error'] );
		}

		$directory = trailingslashit( (string) $uploads['basedir'] ) . 'adam-membership/cards';

		if ( ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'adam_membership_card_cache_dir', __( 'Nao foi possivel preparar a cache do cartão.', 'adam-membership' ) );
		}

		return $directory;
	}

	private function image_binary_from_member_field( Member $member, string $field ): string {
		$value = $member->field( $field );

		if ( is_numeric( $value ) ) {
			$path = get_attached_file( absint( $value ) );

			if ( is_string( $path ) && file_exists( $path ) ) {
				$contents = file_get_contents( $path );
				return is_string( $contents ) ? $contents : '';
			}
		}

		return $this->image_binary_from_url( $member->media_url( $field ) );
	}

	private function image_binary_from_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$path = $this->local_path_from_url( $url );

		if ( '' !== $path && file_exists( $path ) ) {
			$contents = file_get_contents( $path );
			return is_string( $contents ) ? $contents : '';
		}

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		return is_string( $body ) ? $body : '';
	}

	private function local_path_from_url( string $url ): string {
		$uploads = wp_upload_dir();
		$baseurl = (string) ( $uploads['baseurl'] ?? '' );
		$basedir = (string) ( $uploads['basedir'] ?? '' );

		if ( '' !== $baseurl && str_starts_with( $url, $baseurl ) ) {
			return $basedir . str_replace( $baseurl, '', $url );
		}

		$home = home_url( '/' );

		if ( str_starts_with( $url, $home ) ) {
			$relative = ltrim( str_replace( $home, '', $url ), '/' );
			$path     = ABSPATH . str_replace( '/', DIRECTORY_SEPARATOR, $relative );

			return file_exists( $path ) ? $path : '';
		}

		return '';
	}

	private function qr_binary( Member $member, string $qr_url ): string {
		$binary = $this->image_binary_from_url( $qr_url );

		if ( '' !== $binary ) {
			return $binary;
		}

		$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 220 220">
<rect width="220" height="220" fill="#ffffff"/>
<g fill="#0f2f1e">
<rect x="16" y="16" width="56" height="56"/><rect x="24" y="24" width="40" height="40" fill="#fff"/><rect x="32" y="32" width="24" height="24"/>
<rect x="148" y="16" width="56" height="56"/><rect x="156" y="24" width="40" height="40" fill="#fff"/><rect x="164" y="32" width="24" height="24"/>
<rect x="16" y="148" width="56" height="56"/><rect x="24" y="156" width="40" height="40" fill="#fff"/><rect x="32" y="164" width="24" height="24"/>
</g>
</svg>
SVG;

		return $this->svg_to_png_bytes( $svg );
	}

	private function svg_to_png_bytes( string $svg ): string {
		if ( 'imagick' === $this->available_engine() ) {
			try {
				$image = new \Imagick();
				$image->setBackgroundColor( new \ImagickPixel( 'transparent' ) );
				$image->readImageBlob( $svg );
				$image->setImageFormat( 'png32' );
				$blob = $image->getImagesBlob();
				$image->clear();
				$image->destroy();
				return is_string( $blob ) ? $blob : '';
			} catch ( \Throwable ) {
				return '';
			}
		}

		return '';
	}

	private function gd_draw_image( $canvas, string $binary, int $x, int $y, int $width, int $height ): void {
		if ( '' === $binary ) {
			return;
		}

		$image = imagecreatefromstring( $binary );

		if ( false === $image ) {
			return;
		}

		imagecopyresampled(
			$canvas,
			$image,
			$x,
			$y,
			0,
			0,
			$width,
			$height,
			imagesx( $image ),
			imagesy( $image )
		);

		imagedestroy( $image );
	}

	private function gd_color( $image, string $hex, int $alpha = 0): int {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return imagecolorallocatealpha(
			$image,
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
			max( 0, min( 127, $alpha ) )
		);
	}

	private function gd_rounded_rect( $image, int $x, int $y, int $width, int $height, int $radius, int $color, bool $filled, int $thickness = 1 ): void {
		if ( $filled ) {
			imagefilledrectangle( $image, $x + $radius, $y, $x + $width - $radius, $y + $height, $color );
			imagefilledrectangle( $image, $x, $y + $radius, $x + $width, $y + $height - $radius, $color );
			imagefilledellipse( $image, $x + $radius, $y + $radius, $radius * 2, $radius * 2, $color );
			imagefilledellipse( $image, $x + $width - $radius, $y + $radius, $radius * 2, $radius * 2, $color );
			imagefilledellipse( $image, $x + $radius, $y + $height - $radius, $radius * 2, $radius * 2, $color );
			imagefilledellipse( $image, $x + $width - $radius, $y + $height - $radius, $radius * 2, $radius * 2, $color );
			return;
		}

		imagesetthickness( $image, $thickness );
		imageline( $image, $x + $radius, $y, $x + $width - $radius, $y, $color );
		imageline( $image, $x + $radius, $y + $height, $x + $width - $radius, $y + $height, $color );
		imageline( $image, $x, $y + $radius, $x, $y + $height - $radius, $color );
		imageline( $image, $x + $width, $y + $radius, $x + $width, $y + $height - $radius, $color );
		imagearc( $image, $x + $radius, $y + $radius, $radius * 2, $radius * 2, 180, 270, $color );
		imagearc( $image, $x + $width - $radius, $y + $radius, $radius * 2, $radius * 2, 270, 360, $color );
		imagearc( $image, $x + $radius, $y + $height - $radius, $radius * 2, $radius * 2, 90, 180, $color );
		imagearc( $image, $x + $width - $radius, $y + $height - $radius, $radius * 2, $radius * 2, 0, 90, $color );
		imagesetthickness( $image, 1 );
	}

	/**
	 * @param array<string, mixed> $card_data
	 * @param array<string, mixed> $presentation
	 */
	private function svg_markup( Member $member, array $card_data, array $presentation ): string {
		$style          = is_array( $presentation['custom_style'] ?? null ) ? (array) $presentation['custom_style'] : array();
		$member_name    = esc_html( strtoupper( (string) $card_data['member_name'] ) );
		$member_number  = esc_html( (string) $card_data['member_number_ui'] );
		$joined_date    = esc_html( (string) $card_data['joined_date'] );
		$expiry_date    = esc_html( (string) $card_data['expiry_date'] );
		$status         = esc_html( (string) $card_data['status'] );
		$title_name     = esc_html( (string) ( $presentation['active_title']['name'] ?? __( 'Titulo ativo', 'adam-membership' ) ) );
		$logo_data_uri  = $this->binary_to_data_uri( $this->image_binary_from_url( (string) $card_data['association_logo'] ), 'image/png' );
		$photo_binary   = $this->image_binary_from_member_field( $member, 'profile_photo' );
		$photo_data_uri = '' !== $photo_binary ? $this->binary_to_data_uri( $photo_binary, $this->mime_from_binary( $photo_binary ) ) : '';
		$qr_binary      = $this->qr_binary( $member, (string) $card_data['qr_image_url'] );
		$qr_data_uri    = '' !== $qr_binary ? $this->binary_to_data_uri( $qr_binary, 'image/png' ) : '';
		$background_uri = $this->binary_data_uri_from_style_url( (string) ( $style['background_image_url'] ?? '' ) );
		$art_uri        = $this->binary_data_uri_from_style_url( (string) ( $style['image_url'] ?? '' ) );
		$background     = $this->svg_background( $style );
		$pattern        = $this->svg_pattern_markup( $style );
		$frame          = $this->svg_frame_markup( $style );
		$title_badge    = $this->svg_title_badge_markup( $title_name, $presentation );
		$initials       = esc_html( (string) $card_data['initials'] );

		return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1011" height="638" viewBox="0 0 1011 638">
<defs>
{$background['defs']}
{$pattern['defs']}
{$frame['defs']}
</defs>
<rect x="0" y="0" width="1011" height="638" rx="28" fill="{$background['fill']}"/>
<rect x="12" y="12" width="987" height="614" rx="22" fill="rgba(255,255,255,0.02)"/>
{$frame['markup']}
{$pattern['markup']}
SVG
			. ( '' !== $background_uri ? '<image href="' . esc_attr( $background_uri ) . '" x="12" y="12" width="987" height="614" preserveAspectRatio="xMidYMid slice" opacity="0.18"/>' : '' )
			. ( '' !== $art_uri ? '<image href="' . esc_attr( $art_uri ) . '" x="740" y="120" width="220" height="220" preserveAspectRatio="xMidYMid meet" opacity="0.16"/>' : '' )
			. <<<SVG
<circle cx="880" cy="560" r="170" fill="rgba(255,255,255,0.08)"/>
<rect x="34" y="26" width="148" height="74" rx="18" fill="#ffffff"/>
SVG
			. ( '' !== $logo_data_uri ? '<image href="' . esc_attr( $logo_data_uri ) . '" x="42" y="30" width="132" height="66" preserveAspectRatio="xMidYMid meet"/>' : '' )
			. <<<SVG
<text x="204" y="44" fill="#ffffff" font-size="24" font-weight="700" font-family="Arial, sans-serif">ASSOCIACAO DESPORTIVA</text>
<text x="204" y="80" fill="#ffffff" font-size="38" font-weight="700" font-family="Arial, sans-serif">{$this->xml( (string) $card_data['association_name'] )}</text>
<rect x="894" y="22" width="88" height="42" rx="18" fill="#dcfce7"/>
<text x="938" y="50" text-anchor="middle" fill="#14532d" font-size="24" font-weight="700" font-family="Arial, sans-serif">{$status}</text>
SVG
			. ( '' !== $photo_data_uri
				? '<rect x="34" y="168" width="132" height="212" rx="20" fill="rgba(255,255,255,0.16)"/><image href="' . esc_attr( $photo_data_uri ) . '" x="34" y="168" width="132" height="212" preserveAspectRatio="xMidYMid slice" clip-path="inset(0 round 20px)"/>'
				: '<rect x="34" y="168" width="132" height="212" rx="20" fill="rgba(255,255,255,0.18)" stroke="rgba(255,255,255,0.82)" stroke-width="4"/><text x="100" y="292" text-anchor="middle" fill="#ffffff" font-size="86" font-weight="700" font-family="Arial, sans-serif">' . $initials . '</text>'
			)
			. <<<SVG
<text x="210" y="180" fill="#ffffff" font-size="24" font-weight="700" font-family="Arial, sans-serif">NOME DO SOCIO</text>
{$title_badge}
<text x="180" y="336" fill="#ffffff" font-size="76" font-weight="700" font-family="Arial, sans-serif">{$member_name}</text>
<rect x="176" y="434" width="118" height="46" rx="18" fill="rgba(255,255,255,0.18)"/>
<text x="235" y="464" text-anchor="middle" fill="#ffffff" font-size="24" font-weight="700" font-family="Arial, sans-serif">{$member_number}</text>
<rect x="861" y="158" width="122" height="170" rx="20" fill="#ffffff"/>
SVG
			. ( '' !== $qr_data_uri ? '<image href="' . esc_attr( $qr_data_uri ) . '" x="872" y="170" width="100" height="100" preserveAspectRatio="xMidYMid meet"/>' : '' )
			. <<<SVG
<text x="922" y="298" text-anchor="middle" fill="#14532d" font-size="22" font-weight="700" font-family="Arial, sans-serif">VALIDAR CARTAO</text>
<rect x="28" y="480" width="322" height="86" rx="18" fill="rgba(255,255,255,0.18)"/>
<rect x="366" y="480" width="322" height="86" rx="18" fill="rgba(255,255,255,0.18)"/>
<rect x="704" y="480" width="279" height="86" rx="18" fill="rgba(255,255,255,0.18)"/>
<text x="48" y="510" fill="#ffffff" font-size="22" font-weight="700" font-family="Arial, sans-serif">N. DE SOCIO</text>
<text x="48" y="548" fill="#ffffff" font-size="34" font-weight="700" font-family="Arial, sans-serif">{$member_number}</text>
<text x="386" y="510" fill="#ffffff" font-size="22" font-weight="700" font-family="Arial, sans-serif">DATA DE ADESAO</text>
<text x="386" y="548" fill="#ffffff" font-size="34" font-weight="700" font-family="Arial, sans-serif">{$joined_date}</text>
<text x="724" y="510" fill="#ffffff" font-size="22" font-weight="700" font-family="Arial, sans-serif">VALIDO ATE</text>
<text x="724" y="548" fill="#ffffff" font-size="34" font-weight="700" font-family="Arial, sans-serif">{$expiry_date}</text>
<text x="40" y="602" fill="#ffffff" font-size="24" font-weight="700" font-family="Arial, sans-serif">AIRSOFTMONDEGO.PT</text>
<text x="835" y="602" fill="#ffffff" font-size="24" font-weight="700" font-family="Arial, sans-serif">CARTAO DIGITAL ADAM</text>
</svg>
SVG;
	}

	/**
	 * @param array<string, mixed> $style
	 * @return array{defs:string,fill:string}
	 */
	private function svg_background( array $style ): array {
		$primary   = $this->sanitize_hex( (string) ( $style['background_color'] ?? '#143826' ), '#143826' );
		$secondary = $this->sanitize_hex( (string) ( $style['background_color_secondary'] ?? '#1f5a33' ), '#1f5a33' );
		$tertiary  = $this->sanitize_hex( (string) ( $style['background_color_tertiary'] ?? '#102033' ), '#102033' );
		$angle     = max( 0, min( 360, (int) ( $style['gradient_angle'] ?? 135 ) ) );
		$x2        = 50 + 50 * cos( deg2rad( $angle ) );
		$y2        = 50 + 50 * sin( deg2rad( $angle ) );

		return array(
			'defs' => sprintf(
				'<linearGradient id="adamCardBackground" x1="0%%" y1="0%%" x2="%1$s%%" y2="%2$s%%"><stop offset="0%%" stop-color="%3$s"/><stop offset="52%%" stop-color="%4$s"/><stop offset="100%%" stop-color="%5$s"/></linearGradient>',
				(string) round( $x2, 2 ),
				(string) round( $y2, 2 ),
				$primary,
				$secondary,
				$tertiary
			),
			'fill' => 'url(#adamCardBackground)',
		);
	}

	/**
	 * @param array<string, mixed> $style
	 * @return array{defs:string,markup:string}
	 */
	private function svg_pattern_markup( array $style ): array {
		$pattern = sanitize_key( (string) ( $style['pattern'] ?? 'grid' ) );
		$color   = $this->sanitize_hex( (string) ( $style['pattern_color'] ?? '#86efac' ), '#86efac' );
		$base    = $this->sanitize_hex( (string) ( $style['pattern_background_color'] ?? '#143826' ), '#143826' );
		$opacity = max( 0, min( 100, (int) ( $style['pattern_opacity'] ?? 18 ) ) ) / 100;
		$size    = max( 16, (int) ( $style['pattern_scale'] ?? 24 ) );

		if ( 'none' === $pattern ) {
			return array( 'defs' => '', 'markup' => '' );
		}

		$defs = '';

		if ( 'carbon' === $pattern ) {
			$defs = sprintf(
				'<pattern id="adamCardPattern" patternUnits="userSpaceOnUse" width="%1$d" height="%1$d"><rect width="%1$d" height="%1$d" fill="%2$s"/><path d="M0 %1$d L%1$d 0 M-%3$d %3$d L%3$d -%3$d M%3$d %4$d L%4$d %3$d" stroke="%5$s" stroke-width="4" opacity="0.35"/></pattern>',
				$size,
				$base,
				(int) round( $size / 2 ),
				$size + (int) round( $size / 2 ),
				$color
			);
		} elseif ( 'diagonal' === $pattern ) {
			$defs = sprintf(
				'<pattern id="adamCardPattern" patternUnits="userSpaceOnUse" width="%1$d" height="%1$d"><rect width="%1$d" height="%1$d" fill="%2$s"/><path d="M0 %1$d L%1$d 0" stroke="%3$s" stroke-width="6" opacity="0.32"/></pattern>',
				$size,
				$base,
				$color
			);
		} elseif ( 'dots' === $pattern ) {
			$defs = sprintf(
				'<pattern id="adamCardPattern" patternUnits="userSpaceOnUse" width="%1$d" height="%1$d"><rect width="%1$d" height="%1$d" fill="%2$s"/><circle cx="%3$d" cy="%3$d" r="4" fill="%4$s" opacity="0.42"/></pattern>',
				$size,
				$base,
				(int) round( $size / 2 ),
				$color
			);
		} else {
			$defs = sprintf(
				'<pattern id="adamCardPattern" patternUnits="userSpaceOnUse" width="%1$d" height="%1$d"><rect width="%1$d" height="%1$d" fill="%2$s"/><path d="M0 0 H%1$d M0 0 V%1$d" stroke="%3$s" stroke-width="3" opacity="0.24"/></pattern>',
				$size,
				$base,
				$color
			);
		}

		return array(
			'defs'   => $defs,
			'markup' => sprintf( '<rect x="12" y="12" width="987" height="614" rx="22" fill="url(#adamCardPattern)" opacity="%s"/>', (string) $opacity ),
		);
	}

	/**
	 * @param array<string, mixed> $style
	 * @return array{defs:string,markup:string}
	 */
	private function svg_frame_markup( array $style ): array {
		$thickness = max( 0, min( 16, (int) ( $style['frame_thickness'] ?? 0 ) ) );
		$preset    = sanitize_key( (string) ( $style['frame_style'] ?? 'none' ) );

		if ( $thickness <= 0 || 'none' === $preset ) {
			return array( 'defs' => '', 'markup' => '' );
		}

		$color1 = $this->sanitize_hex( (string) ( $style['frame_color'] ?? '#ffffff' ), '#ffffff' );
		$color2 = $this->sanitize_hex( (string) ( $style['frame_highlight_color'] ?? '#d8dce3' ), '#d8dce3' );
		$color3 = $this->sanitize_hex( (string) ( $style['frame_gradient_color_3'] ?? '#146aff' ), '#146aff' );
		$angle  = max( 0, min( 360, (int) ( $style['frame_gradient_angle'] ?? 135 ) ) );
		$x2     = 50 + 50 * cos( deg2rad( $angle ) );
		$y2     = 50 + 50 * sin( deg2rad( $angle ) );

		if ( 'gradient' === $preset ) {
			return array(
				'defs' => sprintf(
					'<linearGradient id="adamFrameGradient" x1="0%%" y1="0%%" x2="%1$s%%" y2="%2$s%%"><stop offset="0%%" stop-color="%3$s"/><stop offset="50%%" stop-color="%4$s"/><stop offset="100%%" stop-color="%5$s"/></linearGradient>',
					(string) round( $x2, 2 ),
					(string) round( $y2, 2 ),
					$color1,
					$color2,
					$color3
				),
				'markup' => sprintf( '<rect x="1" y="1" width="1009" height="636" rx="28" fill="none" stroke="url(#adamFrameGradient)" stroke-width="%d"/>', $thickness ),
			);
		}

		if ( 'metallic' === $preset ) {
			return array(
				'defs' => sprintf(
					'<linearGradient id="adamFrameMetallic" x1="0%%" y1="0%%" x2="100%%" y2="100%%"><stop offset="0%%" stop-color="%1$s"/><stop offset="48%%" stop-color="%2$s"/><stop offset="100%%" stop-color="%1$s"/></linearGradient>',
					$color1,
					$color2
				),
				'markup' => sprintf(
					'<rect x="1" y="1" width="1009" height="636" rx="28" fill="none" stroke="url(#adamFrameMetallic)" stroke-width="%1$d"/><rect x="%2$d" y="%2$d" width="%3$d" height="%4$d" rx="24" fill="none" stroke="%5$s" stroke-width="2" opacity="0.72"/>',
					$thickness,
					6,
					999,
					626,
					$color2
				),
			);
		}

		return array(
			'defs' => '',
			'markup' => sprintf( '<rect x="1" y="1" width="1009" height="636" rx="28" fill="none" stroke="%1$s" stroke-width="%2$d"/>', $color1, $thickness ),
		);
	}

	/**
	 * @param array<string, mixed> $presentation
	 */
	private function svg_title_badge_markup( string $title_name, array $presentation ): string {
		$badge = is_array( $presentation['active_title_badge_style'] ?? null ) ? (array) $presentation['active_title_badge_style'] : array();
		$background = $this->sanitize_hex( (string) ( $badge['background_color'] ?? '#36523f' ), '#36523f' );
		$text       = $this->sanitize_hex( (string) ( $badge['text_color'] ?? '#ffffff' ), '#ffffff' );
		$border     = $this->sanitize_hex( (string) ( $badge['border_color'] ?? '#86efac' ), '#86efac' );
		$icon       = $this->sanitize_hex( (string) ( $badge['icon_color'] ?? '#2f4b3b' ), '#2f4b3b' );
		$iconHi     = $this->sanitize_hex( (string) ( $badge['icon_highlight_color'] ?? '#ffffff' ), '#ffffff' );

		return sprintf(
			'<rect x="182" y="256" width="162" height="52" rx="18" fill="%1$s" stroke="%2$s" stroke-width="2"/><circle cx="203" cy="282" r="12" fill="%3$s"/><circle cx="198" cy="277" r="6" fill="%4$s" opacity="0.82"/><text x="228" y="290" fill="%5$s" font-size="24" font-weight="700" font-family="Arial, sans-serif">%6$s</text>',
			$background,
			$border,
			$icon,
			$iconHi,
			$text,
			$title_name
		);
	}

	private function binary_data_uri_from_style_url( string $url ): string {
		$binary = $this->image_binary_from_url( $url );

		if ( '' === $binary ) {
			return '';
		}

		return $this->binary_to_data_uri( $binary, $this->mime_from_binary( $binary ) );
	}

	private function binary_to_data_uri( string $binary, string $mime ): string {
		return 'data:' . $mime . ';base64,' . base64_encode( $binary );
	}

	private function mime_from_binary( string $binary ): string {
		$finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;

		if ( false !== $finfo ) {
			$mime = finfo_buffer( $finfo, $binary );
			finfo_close( $finfo );

			if ( is_string( $mime ) && '' !== $mime ) {
				return $mime;
			}
		}

		return 'image/png';
	}

	private function sanitize_hex( string $color, string $fallback ): string {
		$color = trim( $color );

		if ( preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
			return $color;
		}

		if ( preg_match( '/^#[0-9a-fA-F]{3}$/', $color ) ) {
			return '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}

		return $fallback;
	}

	private function xml( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}
}
