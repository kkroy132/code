<?php
defined( 'ABSPATH' ) || exit;

/**
 * VAPID key generation and JWT signing (RFC 8292 / ES256).
 *
 * Requires: PHP 7.3+, OpenSSL with prime256v1 (P-256) support.
 */
class WNP_Vapid {

    // ── Key Generation ────────────────────────────────────────────────────────

    /**
     * Generate a new VAPID EC P-256 key pair.
     *
     * @return array{ public_key: string, private_key: string }  Both base64url-encoded.
     * @throws \RuntimeException on OpenSSL failure.
     */
    public static function generate_keys(): array {
        $res = openssl_pkey_new( [
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ] );

        if ( ! $res ) {
            throw new \RuntimeException( 'OpenSSL EC key generation failed: ' . openssl_error_string() );
        }

        $details = openssl_pkey_get_details( $res );

        if ( ! isset( $details['ec']['x'], $details['ec']['y'], $details['ec']['d'] ) ) {
            throw new \RuntimeException( 'openssl_pkey_get_details did not return EC coordinates.' );
        }

        // Uncompressed public key: 0x04 || x(32) || y(32)
        $pub_raw = "\x04"
            . str_pad( $details['ec']['x'], 32, "\x00", STR_PAD_LEFT )
            . str_pad( $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );

        // BUG FIX #1 — Guard: public key must be exactly 65 bytes.
        if ( strlen( $pub_raw ) !== 65 ) {
            throw new \RuntimeException( 'Generated EC public key is not 65 bytes.' );
        }

        // Private scalar d (32 bytes)
        $priv_raw = str_pad( $details['ec']['d'], 32, "\x00", STR_PAD_LEFT );

        return [
            'public_key'  => self::b64url_encode( $pub_raw ),
            'private_key' => self::b64url_encode( $priv_raw ),
        ];
    }

    // ── Authorization Header ──────────────────────────────────────────────────

    /**
     * Build the VAPID Authorization header value for a given endpoint.
     *
     * @param  string $endpoint  The subscriber's push endpoint URL.
     * @return array{ Authorization: string }
     * @throws \RuntimeException if keys are missing or signing fails.
     */
    public static function get_headers( string $endpoint ): array {
        $pub_b64  = get_option( 'wnp_vapid_public_key' );
        $priv_b64 = get_option( 'wnp_vapid_private_key' );

        if ( ! $pub_b64 || ! $priv_b64 ) {
            throw new \RuntimeException( 'VAPID keys are not configured.' );
        }

        $subject = get_option(
            'wnp_vapid_subject',
            'mailto:admin@' . wp_parse_url( home_url(), PHP_URL_HOST )
        );

        // BUG FIX #10 — Guard against wp_parse_url() returning false.
        $parsed = wp_parse_url( $endpoint );
        if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            throw new \RuntimeException( 'Could not parse push endpoint URL: ' . $endpoint );
        }
        $audience = $parsed['scheme'] . '://' . $parsed['host'];

        // Build unsigned JWT.
        $header = self::b64url_encode( (string) wp_json_encode( [ 'typ' => 'JWT', 'alg' => 'ES256' ] ) );
        $claims = self::b64url_encode( (string) wp_json_encode( [
            'aud' => $audience,
            'exp' => time() + 43200,   // 12 hours
            'sub' => $subject,
        ] ) );

        $signing_input = $header . '.' . $claims;

        // Reconstruct EC private key PEM from stored raw bytes.
        $priv_raw = self::b64url_decode( $priv_b64 );
        $pub_raw  = self::b64url_decode( $pub_b64 );
        $pem      = self::build_ec_private_key_pem( $priv_raw, $pub_raw );
        $key      = openssl_pkey_get_private( $pem );

        if ( ! $key ) {
            throw new \RuntimeException( 'Failed to load VAPID private key: ' . openssl_error_string() );
        }

        // openssl_sign produces DER-encoded ECDSA; JWT ES256 needs raw r||s (64 bytes).
        if ( ! openssl_sign( $signing_input, $der_sig, $key, 'SHA256' ) ) {
            throw new \RuntimeException( 'VAPID JWT signing failed: ' . openssl_error_string() );
        }

        $raw_sig = self::der_sig_to_raw( $der_sig );
        $jwt     = $signing_input . '.' . self::b64url_encode( $raw_sig );

        return [
            'Authorization' => 'vapid t=' . $jwt . ', k=' . $pub_b64,
        ];
    }

    // ── DER / PEM Helpers ─────────────────────────────────────────────────────

    /**
     * Build an RFC 5915 ECPrivateKey PEM from raw key bytes.
     *
     * @param string $d   32-byte private scalar.
     * @param string $pub 65-byte uncompressed public key (0x04||x||y).
     */
    private static function build_ec_private_key_pem( string $d, string $pub ): string {
        $d = str_pad( $d, 32, "\x00", STR_PAD_LEFT );

        // OID prime256v1 = 1.2.840.10045.3.1.7
        $oid_p256 = "\x2a\x86\x48\xce\x3d\x03\x01\x07";

        $version     = "\x02\x01\x01";                // INTEGER 1
        $private_oct = "\x04\x20" . $d;               // OCTET STRING, 32 bytes

        // [0] EXPLICIT OID — context tag 0xA0, length = 2 (tag+len) + 8 (OID) = 10
        $params = "\xa0\x0a\x06\x08" . $oid_p256;

        // BUG FIX #1 — compute pub_bitstr length dynamically instead of hardcoding.
        // BIT STRING: 0x00 (unused bits byte) || pub_key
        $bitstring_content = "\x00" . $pub;
        $bitstring         = "\x03" . self::der_len( $bitstring_content ) . $bitstring_content;

        // [1] EXPLICIT BIT STRING
        $pub_explicit = "\xa1" . self::der_len( $bitstring ) . $bitstring;

        $contents = $version . $private_oct . $params . $pub_explicit;
        $der      = "\x30" . self::der_len( $contents ) . $contents;

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split( base64_encode( $der ), 64, "\n" )
            . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * Convert DER ECDSA signature to raw 64-byte r||s (JWT ES256).
     *
     * BUG FIX #2 — Properly parse DER length bytes instead of guessing.
     * P-256 sigs are always < 128 bytes total so single-byte length is
     * guaranteed in practice, but correct parsing prevents edge-case failures.
     */
    private static function der_sig_to_raw( string $der ): string {
        $pos = 0;

        // Read SEQUENCE tag (0x30).
        if ( ord( $der[ $pos++ ] ) !== 0x30 ) {
            throw new \RuntimeException( 'DER signature: expected SEQUENCE tag.' );
        }

        // Parse SEQUENCE length.
        $pos += self::der_read_length( $der, $pos );

        // Read INTEGER tag for r (0x02).
        if ( ord( $der[ $pos++ ] ) !== 0x02 ) {
            throw new \RuntimeException( 'DER signature: expected INTEGER tag for r.' );
        }
        $r_len  = self::der_read_length_value( $der, $pos );
        $pos   += self::der_length_bytes( $der, $pos );
        $r      = substr( $der, $pos, $r_len );
        $pos   += $r_len;

        // Read INTEGER tag for s (0x02).
        if ( ord( $der[ $pos++ ] ) !== 0x02 ) {
            throw new \RuntimeException( 'DER signature: expected INTEGER tag for s.' );
        }
        $s_len  = self::der_read_length_value( $der, $pos );
        $pos   += self::der_length_bytes( $der, $pos );
        $s      = substr( $der, $pos, $s_len );

        // Strip DER positive-integer leading 0x00 padding.
        $r = ltrim( $r, "\x00" );
        $s = ltrim( $s, "\x00" );

        // Each must be exactly 32 bytes (zero-padded on the left if short).
        return str_pad( $r, 32, "\x00", STR_PAD_LEFT )
             . str_pad( $s, 32, "\x00", STR_PAD_LEFT );
    }

    // ── DER length helpers ────────────────────────────────────────────────────

    /**
     * Return the integer value of a DER-encoded length at position $pos in $data.
     */
    private static function der_read_length_value( string $data, int $pos ): int {
        $first = ord( $data[ $pos ] );
        if ( $first < 0x80 ) {
            return $first;
        }
        $num_bytes = $first & 0x7f;
        $value     = 0;
        for ( $i = 1; $i <= $num_bytes; $i++ ) {
            $value = ( $value << 8 ) | ord( $data[ $pos + $i ] );
        }
        return $value;
    }

    /**
     * Return the number of bytes consumed by a DER length field at position $pos.
     */
    private static function der_length_bytes( string $data, int $pos ): int {
        $first = ord( $data[ $pos ] );
        if ( $first < 0x80 ) {
            return 1;
        }
        return 1 + ( $first & 0x7f );
    }

    /**
     * Read a DER length at $pos and return the number of bytes to advance $pos by
     * (i.e., the size of the length field itself — NOT the value).
     * Used when we want to skip past a length field.
     */
    private static function der_read_length( string $data, int $pos ): int {
        return self::der_length_bytes( $data, $pos );
    }

    /**
     * Encode a DER length field (supports multi-byte for large payloads).
     */
    private static function der_len( string $data ): string {
        $n = strlen( $data );
        if ( $n < 0x80 ) {
            return chr( $n );
        }
        $bytes = '';
        while ( $n > 0 ) {
            $bytes = chr( $n & 0xff ) . $bytes;
            $n   >>= 8;
        }
        return chr( 0x80 | strlen( $bytes ) ) . $bytes;
    }

    // ── Base64url ─────────────────────────────────────────────────────────────

    public static function b64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    public static function b64url_decode( string $data ): string {
        $pad = strlen( $data ) % 4;
        if ( $pad ) {
            $data .= str_repeat( '=', 4 - $pad );
        }
        $decoded = base64_decode( strtr( $data, '-_', '+/' ), true );
        if ( $decoded === false ) {
            throw new \InvalidArgumentException( 'Invalid base64url-encoded data.' );
        }
        return $decoded;
    }
}
