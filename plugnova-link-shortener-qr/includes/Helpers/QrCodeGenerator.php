<?php
/**
 * Renders QR codes to PNG/SVG using the vendored pure-PHP QR encoder, with support for
 * custom colors, module styles (square/rounded/circle), transparency and a brand logo band.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once QLQR_PLUGIN_DIR . 'includes/Vendor/QRCode/qrcode-lib.php';

/**
 * Class QrCodeGenerator
 */
final class QrCodeGenerator {

	/**
	 * Smallest accepted output size in pixels. Below this the modules of a typical short URL round
	 * down to under 2px each and the code stops scanning reliably.
	 */
	public const MIN_SIZE = 64;

	/**
	 * Largest accepted output size in pixels. 2000px is already far beyond print use and keeps the
	 * GD allocation (4 bytes per pixel, squared) around 16 MB — comfortably inside WordPress's
	 * default memory limit.
	 */
	public const MAX_SIZE = 2000;

	/**
	 * Human-readable reason the most recent generate_to_uploads() call failed, or null if it
	 * succeeded (or hasn't run yet). Exists purely so calling code can surface a useful message
	 * to the admin instead of a silent blank QR column.
	 *
	 * @var string|null
	 */
	private ?string $last_error = null;

	/**
	 * Get the reason the most recent call to generate_to_uploads() failed, if any.
	 *
	 * @return string|null
	 */
	public function get_last_error(): ?string {
		return $this->last_error;
	}

	/**
	 * Generate a QR code and save it to the uploads directory, returning its attachment info.
	 *
	 * @param string               $data    The data to encode (typically the short URL).
	 * @param array<string, mixed> $options {
	 *     @type int    $size       Output image size in pixels (square). Default 300.
	 *     @type string $style      square|rounded|circle. Default 'square'.
	 *     @type string $fg_color   Hex foreground color. Default '#000000'.
	 *     @type string $bg_color   Hex background color. Default '#ffffff'.
	 *     @type bool   $transparent Whether the background should be transparent. Default false.
	 *     @type string $format     png|svg. Default 'png'.
	 *     @type int|null $logo_attachment_id Optional WP attachment ID for a brand logo, shown in
	 *           its own band above the QR code (never composited onto the modules themselves, so
	 *           it can never interfere with scanning).
	 * }
	 * @param string               $filename_base Base filename (without extension), e.g. the link's slug.
	 * @return array{path:string, url:string, format:string}|null Null on failure.
	 */
	public function generate_to_uploads( string $data, array $options, string $filename_base ): ?array {
		$this->last_error = null;

		if ( 'svg' !== ( $options['format'] ?? 'png' ) && ! extension_loaded( 'gd' ) ) {
			// No GD on this host: fall back to SVG, which needs no image library at all.
			$options['format'] = 'svg';
		}

		$options = wp_parse_args(
			$options,
			array(
				'size'                => 300,
				'style'               => 'square',
				'fg_color'            => '#000000',
				'bg_color'            => '#ffffff',
				'transparent'         => false,
				'format'              => 'png',
				'logo_attachment_id'  => null,
				'caption_text'        => '',
			)
		);

		/*
		 * Clamp the requested size to the same 64–2000px range the Settings field advertises.
		 * render_png() only enforced a lower bound, so an out-of-range value — a mistyped setting,
		 * or a "qr_size" sent straight to the REST endpoint, neither of which the browser's
		 * min/max attributes can constrain — reached imagecreatetruecolor() unbounded. The
		 * allocation is 4 bytes per pixel and grows with the square: size=20000 asks GD for a
		 * ~20000px image, about 1.5 GB, far past WordPress's 256 MB default memory ceiling. That
		 * is a fatal error on every single link save, not a slightly-too-big QR code.
		 */
		$options['size'] = max( self::MIN_SIZE, min( self::MAX_SIZE, (int) $options['size'] ) );

		$matrix = $this->encode_matrix( $data );
		if ( null === $matrix ) {
			$this->last_error = 'QR encoding failed (the vendored QR library threw an exception; check the destination URL length/characters).';
			$this->log_error( $this->last_error );
			return null;
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			$this->last_error = 'WordPress uploads directory is not writable: ' . $upload_dir['error'];
			$this->log_error( $this->last_error );
			return null;
		}

		$qr_subdir  = trailingslashit( $upload_dir['basedir'] ) . 'plugnova-link-shortener-qr';
		$qr_url_dir = trailingslashit( $upload_dir['baseurl'] ) . 'plugnova-link-shortener-qr';

		if ( ! file_exists( $qr_subdir ) ) {
			$created = wp_mkdir_p( $qr_subdir );
			if ( ! $created ) {
				$this->last_error = sprintf(
					'Could not create directory "%s". Check that wp-content/uploads is writable by the web server (usually permissions 755, owned by the same user PHP runs as).',
					$qr_subdir
				);
				$this->log_error( $this->last_error );
				return null;
			}
			// Protect the directory listing on servers without other safeguards.
			@file_put_contents( $qr_subdir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors
		}

		if ( ! wp_is_writable( $qr_subdir ) ) {
			$this->last_error = sprintf( 'Directory "%s" exists but is not writable by PHP.', $qr_subdir );
			$this->log_error( $this->last_error );
			return null;
		}

		$safe_name = sanitize_file_name( $filename_base ) . '-' . substr( md5( $data . wp_json_encode( $options ) ), 0, 8 );

		if ( 'svg' === $options['format'] ) {
			$svg     = $this->render_svg( $matrix, $options );
			$path    = $qr_subdir . '/' . $safe_name . '.svg';
			$written = file_put_contents( $path, $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			if ( false === $written ) {
				$this->last_error = sprintf( 'Failed to write SVG file to "%s".', $path );
				$this->log_error( $this->last_error );
				return null;
			}

			return array(
				'path'   => $path,
				'url'    => $qr_url_dir . '/' . $safe_name . '.svg',
				'format' => 'svg',
			);
		}

		$path = $qr_subdir . '/' . $safe_name . '.png';

		try {
			$this->render_png( $matrix, $options, $path );
		} catch ( \Throwable $e ) {
			$this->last_error = 'PNG rendering failed: ' . $e->getMessage();
			$this->log_error( $this->last_error );
			return null;
		}

		if ( ! file_exists( $path ) ) {
			$this->last_error = sprintf( 'PNG rendering silently failed and no file was written to "%s". This can happen if the GD extension is present but broken, or the disk is out of space.', $path );
			$this->log_error( $this->last_error );
			return null;
		}

		return array(
			'path'   => $path,
			'url'    => $qr_url_dir . '/' . $safe_name . '.png',
			'format' => 'png',
		);
	}

	/**
	 * Delete a previously generated QR image, given the URL stored on the link/bio page row.
	 *
	 * Every render writes a new file whose name embeds a hash of the encoded data and the render
	 * options, so changing a colour or regenerating leaves the old file behind forever. Callers
	 * pass the outgoing qr_image value here so exactly one file per row survives, and so a
	 * permanently deleted row takes its image with it.
	 *
	 * @param string|null $url Previously stored qr_image URL, or null/empty for nothing to do.
	 */
	public static function delete_generated( ?string $url ): void {
		if ( empty( $url ) ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return;
		}

		$qr_url_dir  = trailingslashit( $upload_dir['baseurl'] ) . 'plugnova-link-shortener-qr/';
		$qr_base_dir = trailingslashit( $upload_dir['basedir'] ) . 'plugnova-link-shortener-qr/';

		// Only ever touch files this class wrote: the URL must sit under our own uploads subfolder,
		// and the resolved filename must contain no path separators. Anything else is left alone.
		if ( ! str_starts_with( $url, $qr_url_dir ) ) {
			return;
		}

		$filename = basename( substr( $url, strlen( $qr_url_dir ) ) );
		if ( '' === $filename || ! in_array( strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ), array( 'png', 'svg' ), true ) ) {
			return;
		}

		$path = $qr_base_dir . $filename;
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Write a QR generation failure to the PHP error log when debug logging is enabled, so it
	 * shows up in wp-content/debug.log (or the host's PHP error log) for troubleshooting.
	 *
	 * @param string $message Error description.
	 */
	private function log_error( string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'Plugnova Link Shortener & QR: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, Squiz.PHP.DiscouragedFunctions.Discouraged -- only reached when WP_DEBUG_LOG is on, which is exactly when a QR failure needs to be traceable
		}
	}

	/**
	 * Encode arbitrary string data into a QR module matrix using the vendored encoder.
	 *
	 * @param string $data Data to encode.
	 * @return array<int, array<int, int>>|null Matrix of 0/1 values, or null on failure.
	 */
	private function encode_matrix( string $data ): ?array {
		try {
			// getMinimumQRCode() picks the smallest QR type/version that can hold the data
			// at the given error-correction level and calls make() internally.
			$qr = \QLQR_QRCode::getMinimumQRCode( $data, QLQR_QR_ERROR_CORRECT_LEVEL_M );

			$count  = $qr->getModuleCount();
			$matrix = array();

			for ( $row = 0; $row < $count; $row++ ) {
				$matrix[ $row ] = array();
				for ( $col = 0; $col < $count; $col++ ) {
					$matrix[ $row ][ $col ] = $qr->isDark( $row, $col ) ? 1 : 0;
				}
			}

			return $matrix;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Render a module matrix to a PNG file on disk using GD, honoring style/color/logo options.
	 *
	 * @param array<int, array<int, int>> $matrix  Module matrix.
	 * @param array<string, mixed>        $options Rendering options.
	 * @param string                      $path    Absolute destination path.
	 */
	private function render_png( array $matrix, array $options, string $path ): void {
		$count      = count( $matrix );
		$quiet_zone = 4; // Modules of quiet border, per QR spec recommendation.
		$total      = $count + ( $quiet_zone * 2 );
		$size       = max( 64, (int) $options['size'] );
		$module_px  = max( 1, (int) floor( $size / $total ) );
		$img_size   = $module_px * $total;

		$image = imagecreatetruecolor( $img_size, $img_size );

		list( $fg_r, $fg_g, $fg_b ) = $this->hex_to_rgb( $options['fg_color'] );
		list( $bg_r, $bg_g, $bg_b ) = $this->hex_to_rgb( $options['bg_color'] );

		if ( ! empty( $options['transparent'] ) ) {
			imagesavealpha( $image, true );
			$transparent = imagecolorallocatealpha( $image, 255, 255, 255, 127 );
			imagefill( $image, 0, 0, $transparent );
		} else {
			$bg = imagecolorallocate( $image, $bg_r, $bg_g, $bg_b );
			imagefill( $image, 0, 0, $bg );
		}

		$fg = imagecolorallocate( $image, $fg_r, $fg_g, $fg_b );
		$style = in_array( $options['style'], array( 'square', 'rounded', 'circle' ), true ) ? $options['style'] : 'square';

		for ( $row = 0; $row < $count; $row++ ) {
			for ( $col = 0; $col < $count; $col++ ) {
				if ( 0 === $matrix[ $row ][ $col ] ) {
					continue;
				}

				$x = ( $col + $quiet_zone ) * $module_px;
				$y = ( $row + $quiet_zone ) * $module_px;

				if ( 'circle' === $style ) {
					$radius = (int) floor( $module_px / 2 );
					imagefilledellipse( $image, $x + $radius, $y + $radius, $module_px, $module_px, $fg );
				} elseif ( 'rounded' === $style ) {
					$this->draw_rounded_module( $image, $x, $y, $module_px, $fg );
				} else {
					imagefilledrectangle( $image, $x, $y, $x + $module_px - 1, $y + $module_px - 1, $fg );
				}
			}
		}

		$canvas_height = $img_size;

		$caption = trim( (string) ( $options['caption_text'] ?? '' ) );
		if ( '' !== $caption ) {
			$image         = $this->append_caption_band( $image, $img_size, $caption, $fg_r, $fg_g, $fg_b, $bg_r, $bg_g, $bg_b, ! empty( $options['transparent'] ) );
			$canvas_height = imagesy( $image );
		}

		// The logo band is prepended last (after the caption, if any) so the final stacking order
		// top-to-bottom is always: logo, QR code, caption — matching how a printed flyer would lay
		// these out, and — more importantly — keeping the logo completely outside the QR code's
		// own module grid. A logo composited *onto* the modules (the previous behavior) can obscure
		// enough of the code that some scanners fail to read it, even at a modest size; a separate
		// band above the code is 100% scan-safe regardless of the logo's size or shape.
		if ( ! empty( $options['logo_attachment_id'] ) ) {
			$image = $this->prepend_logo_band( $image, $img_size, $canvas_height, (int) $options['logo_attachment_id'], $bg_r, $bg_g, $bg_b, ! empty( $options['transparent'] ) );
		}

		imagepng( $image, $path );
		imagedestroy( $image );
	}

	/**
	 * Return a new, taller image with the QR code on top and a centered caption band below it —
	 * used for QR codes meant to be embedded in videos/thumbnails (e.g. "Check Price on Amazon"),
	 * where the call-to-action needs to be part of the image itself, not just shown in wp-admin.
	 *
	 * @param \GdImage $qr_image     The rendered QR code image.
	 * @param int      $img_size     Width/height of the QR image in pixels.
	 * @param string   $caption      Caption text to render.
	 * @param int      $fg_r         Foreground red component (used for the text color).
	 * @param int      $fg_g         Foreground green component.
	 * @param int      $fg_b         Foreground blue component.
	 * @param int      $bg_r         Background red component (used for the caption band fill).
	 * @param int      $bg_g         Background green component.
	 * @param int      $bg_b         Background blue component.
	 * @param bool     $transparent  Whether the QR itself uses a transparent background.
	 * @return \GdImage
	 */
	private function append_caption_band( \GdImage $qr_image, int $img_size, string $caption, int $fg_r, int $fg_g, int $fg_b, int $bg_r, int $bg_g, int $bg_b, bool $transparent ): \GdImage {
		$band_height = max( 48, (int) round( $img_size * 0.16 ) );
		$canvas      = imagecreatetruecolor( $img_size, $img_size + $band_height );

		if ( $transparent ) {
			imagesavealpha( $canvas, true );
			$fill = imagecolorallocatealpha( $canvas, 255, 255, 255, 127 );
		} else {
			$fill = imagecolorallocate( $canvas, $bg_r, $bg_g, $bg_b );
		}
		imagefill( $canvas, 0, 0, $fill );

		imagecopy( $canvas, $qr_image, 0, 0, 0, 0, $img_size, $img_size );
		imagedestroy( $qr_image );

		$text_color   = imagecolorallocate( $canvas, $fg_r, $fg_g, $fg_b );
		$font_path    = QLQR_PLUGIN_DIR . 'assets/fonts/DejaVuSans-Bold.ttf';
		$caption_area_y = $img_size + (int) round( $band_height / 2 );

		if ( function_exists( 'imagettftext' ) && is_readable( $font_path ) ) {
			$max_width = $img_size * 0.86;
			$max_height = $band_height * 0.55;

			$font_size = $this->fit_ttf_font_size( $caption, $font_path, $max_width, $max_height );
			$caption   = $this->truncate_to_fit_ttf( $caption, $font_path, $font_size, $max_width );

			$bbox      = imagettfbbox( $font_size, 0, $font_path, $caption );
			$text_w    = abs( $bbox[4] - $bbox[0] );
			$text_h    = abs( $bbox[5] - $bbox[1] );
			$x         = (int) round( ( $img_size - $text_w ) / 2 );
			$y         = (int) round( $caption_area_y + ( $text_h / 2 ) );
			imagettftext( $canvas, $font_size, 0, $x, $y, $text_color, $font_path, $caption );
		} else {
			// Fallback when FreeType isn't compiled into GD: GD's built-in bitmap font (no external
			// file needed, works on every host, just less refined typographically).
			$gd_font = 5;
			$text_w  = imagefontwidth( $gd_font ) * strlen( $caption );
			$text_h  = imagefontheight( $gd_font );
			$x       = (int) round( ( $img_size - $text_w ) / 2 );
			$y       = (int) round( $caption_area_y - ( $text_h / 2 ) );
			imagestring( $canvas, $gd_font, max( 0, $x ), max( 0, $y ), $caption, $text_color );
		}

		return $canvas;
	}

	/**
	 * If the caption still doesn't fit at the given font size (can happen with very long text
	 * even at the minimum readable size), progressively shorten it with a trailing ellipsis
	 * until it does — guaranteeing the caption never overflows past the image edge.
	 *
	 * @param string $text      Caption text.
	 * @param string $font_path Absolute path to a .ttf file.
	 * @param float  $font_size Font size in points (already chosen by fit_ttf_font_size()).
	 * @param float  $max_width Maximum allowed text width in pixels.
	 * @return string Original or truncated caption.
	 */
	private function truncate_to_fit_ttf( string $text, string $font_path, float $font_size, float $max_width ): string {
		$bbox = imagettfbbox( $font_size, 0, $font_path, $text );
		if ( abs( $bbox[4] - $bbox[0] ) <= $max_width ) {
			return $text;
		}

		// Split into UTF-8 characters via PCRE's Unicode mode rather than mbstring, since mbstring
		// isn't guaranteed to be enabled on every shared host, and this correctly handles multi-byte
		// scripts (e.g. Bengali captions) without depending on it.
		preg_match_all( '/./us', $text, $matches );
		$chars = $matches[0];

		while ( count( $chars ) > 4 ) {
			array_pop( $chars );
			$candidate = rtrim( implode( '', $chars ) ) . '…';
			$bbox      = imagettfbbox( $font_size, 0, $font_path, $candidate );

			if ( abs( $bbox[4] - $bbox[0] ) <= $max_width ) {
				return $candidate;
			}
		}

		return implode( '', array_slice( $chars, 0, 4 ) ) . '…';
	}

	/**
	 * Binary-search a TTF font size that fits the given caption within a max width/height box,
	 * so long captions shrink instead of overflowing or getting clipped.
	 *
	 * @param string $text      Caption text.
	 * @param string $font_path Absolute path to a .ttf file.
	 * @param float  $max_width Maximum allowed text width in pixels.
	 * @param float  $max_height Maximum allowed text height in pixels.
	 * @return float Font size in points that fits, with a sane minimum floor.
	 */
	private function fit_ttf_font_size( string $text, string $font_path, float $max_width, float $max_height ): float {
		$size = min( 28.0, $max_height );

		while ( $size > 8 ) {
			$bbox   = imagettfbbox( $size, 0, $font_path, $text );
			$width  = abs( $bbox[4] - $bbox[0] );
			$height = abs( $bbox[5] - $bbox[1] );

			if ( $width <= $max_width && $height <= $max_height ) {
				break;
			}

			$size -= 1;
		}

		return max( 8.0, $size );
	}

	/**
	 * Draw a single QR module as a rounded square (approximated with a filled ellipse quadrant blend).
	 *
	 * @param \GdImage $image     GD image resource.
	 * @param int      $x         Top-left X.
	 * @param int      $y         Top-left Y.
	 * @param int      $module_px Module size in pixels.
	 * @param int      $color     GD color index.
	 */
	private function draw_rounded_module( \GdImage $image, int $x, int $y, int $module_px, int $color ): void {
		$corner_radius = max( 1, (int) floor( $module_px / 3 ) );

		imagefilledrectangle( $image, $x + $corner_radius, $y, $x + $module_px - $corner_radius - 1, $y + $module_px - 1, $color );
		imagefilledrectangle( $image, $x, $y + $corner_radius, $x + $module_px - 1, $y + $module_px - $corner_radius - 1, $color );

		imagefilledellipse( $image, $x + $corner_radius, $y + $corner_radius, $corner_radius * 2, $corner_radius * 2, $color );
		imagefilledellipse( $image, $x + $module_px - $corner_radius - 1, $y + $corner_radius, $corner_radius * 2, $corner_radius * 2, $color );
		imagefilledellipse( $image, $x + $corner_radius, $y + $module_px - $corner_radius - 1, $corner_radius * 2, $corner_radius * 2, $color );
		imagefilledellipse( $image, $x + $module_px - $corner_radius - 1, $y + $module_px - $corner_radius - 1, $corner_radius * 2, $corner_radius * 2, $color );
	}

	/**
	 * Return a new, taller image with a centered logo band on top and the given image (QR code,
	 * possibly already with a caption band appended below it) underneath — so the final stacking
	 * order is always logo → QR code → caption, matching a printed flyer layout. The logo never
	 * touches the QR code's own module grid, so unlike compositing it onto the modules directly,
	 * this can never make the code harder (or impossible) for a scanner to read.
	 *
	 * @param \GdImage $image         The image to place below the logo band (destroyed by this call).
	 * @param int      $width         Width of $image in pixels (and of the returned canvas).
	 * @param int      $height        Height of $image in pixels.
	 * @param int      $attachment_id Logo attachment ID.
	 * @param int      $bg_r          Background red component (used for the logo band fill).
	 * @param int      $bg_g          Background green component.
	 * @param int      $bg_b          Background blue component.
	 * @param bool     $transparent   Whether the QR itself uses a transparent background.
	 * @return \GdImage
	 */
	private function prepend_logo_band( \GdImage $image, int $width, int $height, int $attachment_id, int $bg_r, int $bg_g, int $bg_b, bool $transparent ): \GdImage {
		$logo_path = get_attached_file( $attachment_id );
		if ( ! $logo_path || ! file_exists( $logo_path ) ) {
			return $image;
		}

		$logo_info = getimagesize( $logo_path );
		if ( ! $logo_info ) {
			return $image;
		}

		$logo = match ( $logo_info[2] ) {
			IMAGETYPE_PNG  => imagecreatefrompng( $logo_path ),
			IMAGETYPE_JPEG => imagecreatefromjpeg( $logo_path ),
			IMAGETYPE_GIF  => imagecreatefromgif( $logo_path ),
			default        => null,
		};

		if ( ! $logo ) {
			return $image;
		}

		$band_height = max( 56, (int) round( $width * 0.22 ) );
		$canvas      = imagecreatetruecolor( $width, $height + $band_height );

		if ( $transparent ) {
			imagesavealpha( $canvas, true );
			$fill = imagecolorallocatealpha( $canvas, 255, 255, 255, 127 );
		} else {
			$fill = imagecolorallocate( $canvas, $bg_r, $bg_g, $bg_b );
		}
		imagefill( $canvas, 0, 0, $fill );

		// Scale the logo to fit within the band (preserving its aspect ratio, so non-square
		// logos aren't squashed), leaving a margin on every side.
		$logo_w     = imagesx( $logo );
		$logo_h     = imagesy( $logo );
		$max_logo_w = (int) round( $width * 0.6 );
		$max_logo_h = (int) round( $band_height * 0.72 );
		$scale      = min( $max_logo_w / $logo_w, $max_logo_h / $logo_h, 1 );
		$target_w   = max( 1, (int) round( $logo_w * $scale ) );
		$target_h   = max( 1, (int) round( $logo_h * $scale ) );

		$resized = imagecreatetruecolor( $target_w, $target_h );
		imagesavealpha( $resized, true );
		imagefill( $resized, 0, 0, imagecolorallocatealpha( $resized, 0, 0, 0, 127 ) );
		imagecopyresampled( $resized, $logo, 0, 0, 0, 0, $target_w, $target_h, $logo_w, $logo_h );

		$x = (int) round( ( $width - $target_w ) / 2 );
		$y = (int) round( ( $band_height - $target_h ) / 2 );
		imagecopy( $canvas, $resized, $x, $y, 0, 0, $target_w, $target_h );
		imagedestroy( $resized );
		imagedestroy( $logo );

		imagecopy( $canvas, $image, 0, $band_height, 0, 0, $width, $height );
		imagedestroy( $image );

		return $canvas;
	}

	/**
	 * Render a module matrix to an SVG string.
	 *
	 * @param array<int, array<int, int>> $matrix  Module matrix.
	 * @param array<string, mixed>        $options Rendering options.
	 * @return string
	 */
	private function render_svg( array $matrix, array $options ): string {
		$count      = count( $matrix );
		$quiet_zone = 4;
		$total      = $count + ( $quiet_zone * 2 );

		$fg = esc_attr( $options['fg_color'] );
		$bg = empty( $options['transparent'] ) ? esc_attr( $options['bg_color'] ) : 'none';

		$style  = in_array( $options['style'], array( 'square', 'rounded', 'circle' ), true ) ? $options['style'] : 'square';
		$radius = 'circle' === $style ? 0.5 : ( 'rounded' === $style ? 0.25 : 0 );

		$caption     = trim( (string) ( $options['caption_text'] ?? '' ) );
		$band_units  = '' !== $caption ? max( 6, (int) round( $total * 0.18 ) ) : 0;
		$view_height = $total + $band_units;

		$svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $view_height . '" shape-rendering="crispEdges">';
		$svg .= '<rect width="' . $total . '" height="' . $view_height . '" fill="' . $bg . '" />';

		for ( $row = 0; $row < $count; $row++ ) {
			for ( $col = 0; $col < $count; $col++ ) {
				if ( 0 === $matrix[ $row ][ $col ] ) {
					continue;
				}
				$x = $col + $quiet_zone;
				$y = $row + $quiet_zone;

				if ( $radius > 0 ) {
					$svg .= sprintf( '<rect x="%d" y="%d" width="1" height="1" rx="%s" ry="%s" fill="%s" />', $x, $y, $radius, $radius, $fg );
				} else {
					$svg .= sprintf( '<rect x="%d" y="%d" width="1" height="1" fill="%s" />', $x, $y, $fg );
				}
			}
		}

		if ( '' !== $caption && $band_units > 0 ) {
			// shape-rendering="crispEdges" (set at the <svg> level above) would make text render
			// jagged, so override it back to auto just for this element.
			$svg .= sprintf(
				'<text x="%s" y="%s" text-anchor="middle" dominant-baseline="middle" shape-rendering="auto" font-family="DejaVu Sans, Arial, sans-serif" font-weight="bold" font-size="%s" fill="%s">%s</text>',
				$total / 2,
				$total + ( $band_units / 2 ),
				$band_units * 0.5,
				$fg,
				esc_html( $caption )
			);
		}

		$svg .= '</svg>';

		return $svg;
	}

	/**
	 * Convert a "#rrggbb" hex color string to an [r, g, b] integer triple.
	 *
	 * @param string $hex Hex color, with or without leading '#'.
	 * @return array{0:int, 1:int, 2:int}
	 */
	private function hex_to_rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 0, 0, 0 );
		}

		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}
