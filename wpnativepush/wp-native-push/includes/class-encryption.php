<?php
defined( 'ABSPATH' ) || exit;

/**
 * Web Push payload encryption — RFC 8291 (aes128gcm content encoding).
 *
 * Requires PHP 7.3+ for openssl_pkey_derive() and hash_hkdf().
 */
class WNP_Encryption {

    /**
     * Encrypt a JSON payload for delivery to one push subscriber.
     *
     * @param  string $payload    Raw JSON string to encrypt.
     * @param  array  $subscriber { public_key: base64url p256dh, auth_token: base64url auth }.
     * @return array{ ciphertext: string }
     * @throws \RuntimeException on any crypto failure.
     */
    public static function encrypt( string $payload, array $subscriber ): array {
        // ── 1. Decode subscriber's keys ──────────────────────────────────────
        $receiver_pub_raw = WNP_Vapid::b64url_decode( $subscriber['public_key'] );
        $auth_secret      = WNP_Vapid::b64url_decode( $subscriber['auth_token'] );

        // ── 2. Generate ephemeral sender key pair ────────────────────────────
        $sender_res = openssl_pkey_new( [
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ] );

        if ( ! $sender_res ) {
            throw new \RuntimeException( 'Could not generate sender EC key: ' . openssl_error_string() );
        }

        $sender_details = openssl_pkey_get_details( $sender_res );
        if ( ! $sender_details || empty( $sender_details['ec']['x'] ) || empty( $sender_details['ec']['y'] ) ) {
            throw new \RuntimeException( 'Could not extract EC key details: ' . openssl_error_string() );
        }
        $sender_pub_raw = "\x04"
            . str_pad( $sender_details['ec']['x'], 32, "\x00", STR_PAD_LEFT )
            . str_pad( $sender_details['ec']['y'], 32, "\x00", STR_PAD_LEFT );

        // ── 3. Import receiver public key ────────────────────────────────────
        $receiver_res = self::import_ec_public_key( $receiver_pub_raw );

        // ── 4. ECDH shared secret ────────────────────────────────────────────
        $shared_secret = openssl_pkey_derive( $receiver_res, $sender_res, 32 );
        if ( $shared_secret === false ) {
            throw new \RuntimeException( 'ECDH failed: ' . openssl_error_string() );
        }

        // ── 5. Key derivation (RFC 8291 §3.3) ───────────────────────────────
        $salt = random_bytes( 16 );

        $ikm_info = "WebPush: info\x00" . $receiver_pub_raw . $sender_pub_raw;
        $ikm      = hash_hkdf( 'sha256', $shared_secret, 32, $ikm_info, $auth_secret );

        $cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt );
        $nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt );

        // ── 6. AES-128-GCM encryption ────────────────────────────────────────
        $plaintext = $payload . "\x02";
        $tag       = '';
        $cipher    = openssl_encrypt(
            $plaintext,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );

        if ( $cipher === false ) {
            throw new \RuntimeException( 'AES-128-GCM encryption failed: ' . openssl_error_string() );
        }

        // ── 7. Build RFC 8291 content-coding header ──────────────────────────
        $content = $salt
            . pack( 'N', 4096 )
            . chr( strlen( $sender_pub_raw ) )
            . $sender_pub_raw
            . $cipher
            . $tag;

        return [ 'ciphertext' => $content ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Convert a raw uncompressed EC P-256 public key (65 bytes) into an
     * OpenSSL key resource via SubjectPublicKeyInfo DER wrapping.
     *
     * @param  string $raw_key  0x04 || x(32) || y(32)
     * @return \OpenSSLAsymmetricKey
     * @throws \RuntimeException
     */
    private static function import_ec_public_key( string $raw_key ) {
        $oid_ec_pub = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
        $oid_p256   = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

        $algorithm = "\x30" . chr( strlen( $oid_ec_pub ) + strlen( $oid_p256 ) )
                   . $oid_ec_pub . $oid_p256;

        $bitstring = "\x03" . chr( strlen( $raw_key ) + 1 ) . "\x00" . $raw_key;

        $spki = "\x30" . chr( strlen( $algorithm ) + strlen( $bitstring ) )
              . $algorithm . $bitstring;

        $pem = "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split( base64_encode( $spki ), 64, "\n" )
             . "-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public( $pem );
        if ( ! $key ) {
            throw new \RuntimeException(
                'Failed to import receiver EC public key: ' . openssl_error_string()
            );
        }

        return $key;
    }
}
