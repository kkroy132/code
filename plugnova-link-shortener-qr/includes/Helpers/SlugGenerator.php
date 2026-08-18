<?php
/**
 * Generates and validates short-link slugs.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

use QuickLinkQRPro\Database\HasSlugLookup;
use QuickLinkQRPro\Database\LinksRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlugGenerator
 *
 * Generic short-slug generator/validator. Defaults to checking uniqueness against the links
 * table, but accepts any HasSlugLookup repository — e.g. BioPagesRepository — so other slugged
 * entities (Smart Bio Link pages) can reuse the same alphabet/reserved-word rules instead of
 * duplicating them.
 */
final class SlugGenerator {

	/**
	 * Characters used for random slug generation. Excludes ambiguous characters (0/O, 1/l/I).
	 */
	private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';

	/**
	 * Shortest slug length this generator will produce, and the floor the "Random Slug Length"
	 * setting is clamped to.
	 */
	public const MIN_LENGTH = 3;

	/**
	 * Longest slug length this generator will produce, and the ceiling the "Random Slug Length"
	 * setting is clamped to.
	 */
	public const MAX_LENGTH = 32;

	/**
	 * Repository used to check slug uniqueness.
	 *
	 * @var HasSlugLookup
	 */
	private HasSlugLookup $repository;

	/**
	 * Constructor.
	 *
	 * @param HasSlugLookup|null $repository Optional repository override; defaults to LinksRepository.
	 */
	public function __construct( ?HasSlugLookup $repository = null ) {
		$this->repository = $repository ?? new LinksRepository();
	}

	/**
	 * Generate a random, guaranteed-unique slug of the configured length.
	 *
	 * @param int $length Desired slug length.
	 * @return string
	 */
	public function generate_unique( int $length = 6 ): string {
		$length = max( self::MIN_LENGTH, min( self::MAX_LENGTH, $length ) );

		do {
			$slug = $this->random_string( $length );
		} while ( $this->repository->slug_exists( $slug ) );

		return $slug;
	}

	/**
	 * Sanitize and validate a user-supplied custom slug.
	 *
	 * @param string $slug Raw slug input.
	 * @return string Sanitized slug (may be empty if invalid).
	 */
	public function sanitize_custom_slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		$slug = preg_replace( '/[^a-z0-9\-_]/', '', $slug ) ?? '';

		return substr( $slug, 0, 190 );
	}

	/**
	 * Validate a custom slug against format rules and reserved words.
	 *
	 * @param string $slug Sanitized slug.
	 * @return bool|string True if valid, or a translated error message string if invalid.
	 */
	public function validate( string $slug ): bool|string {
		if ( '' === $slug ) {
			return __( 'Slug cannot be empty.', 'plugnova-link-shortener-qr' );
		}

		if ( strlen( $slug ) < 2 ) {
			return __( 'Slug must be at least 2 characters long.', 'plugnova-link-shortener-qr' );
		}

		if ( ! preg_match( '/^[a-z0-9\-_]+$/', $slug ) ) {
			return __( 'Slug may only contain lowercase letters, numbers, hyphens and underscores.', 'plugnova-link-shortener-qr' );
		}

		$reserved = array( 'wp-admin', 'wp-content', 'wp-includes', 'feed', 'sitemap', 'api', 'go', 'r', 'link' );
		if ( in_array( $slug, $reserved, true ) ) {
			return __( 'This slug is reserved and cannot be used.', 'plugnova-link-shortener-qr' );
		}

		return true;
	}

	/**
	 * Cryptographically-suitable random string from the safe alphabet.
	 *
	 * @param int $length String length.
	 * @return string
	 */
	private function random_string( int $length ): string {
		$alphabet_length = strlen( self::ALPHABET );
		$result          = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$result .= self::ALPHABET[ random_int( 0, $alphabet_length - 1 ) ];
		}

		return $result;
	}
}
