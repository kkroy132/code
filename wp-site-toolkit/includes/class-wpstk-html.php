<?php
/**
 * Lightweight HTML inspection helpers.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extracts the handful of elements the audits care about.
 *
 * The parser is deliberately small: it never loads a DOM library and never
 * evaluates the markup, it only reads tags and attributes.
 *
 * @since 1.0.0
 */
class WPSTK_HTML {

	/**
	 * Returns the contents of the document title element.
	 *
	 * @param string $html Markup.
	 *
	 * @return string
	 */
	public static function get_title( $html ) {
		if ( preg_match( '#<title\b[^>]*>(.*?)</title>#is', $html, $matches ) ) {
			return self::clean_text( $matches[1] );
		}

		return '';
	}

	/**
	 * Returns every tag of a given name together with its attributes.
	 *
	 * @param string $html Markup.
	 * @param string $tag  Tag name, for example `meta` or `img`.
	 *
	 * @return array[] List of attribute maps.
	 */
	public static function get_tags( $html, $tag ) {
		$tag  = preg_quote( $tag, '#' );
		$out  = array();
		$hits = array();

		if ( ! preg_match_all( '#<' . $tag . '\b([^>]*)>#is', (string) $html, $hits ) ) {
			return $out;
		}

		foreach ( $hits[1] as $attribute_string ) {
			$out[] = self::parse_attributes( $attribute_string );
		}

		return $out;
	}

	/**
	 * Parses an attribute string into a lower-cased map.
	 *
	 * @param string $attribute_string Raw attribute string.
	 *
	 * @return array
	 */
	public static function parse_attributes( $attribute_string ) {
		$attributes = array();
		$matches    = array();

		$pattern = '#([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?#';

		if ( ! preg_match_all( $pattern, (string) $attribute_string, $matches, PREG_SET_ORDER ) ) {
			return $attributes;
		}

		foreach ( $matches as $match ) {
			$name = strtolower( $match[1] );

			if ( '' === $name ) {
				continue;
			}

			if ( isset( $match[4] ) && '' !== $match[4] ) {
				$value = $match[4];
			} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
				$value = $match[3];
			} elseif ( isset( $match[2] ) ) {
				$value = $match[2];
			} else {
				$value = '';
			}

			// A present-but-empty attribute stays in the map as an empty string, which
			// lets callers tell `alt=""` apart from a missing alt attribute.
			if ( ! isset( $attributes[ $name ] ) ) {
				$attributes[ $name ] = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
			}
		}

		return $attributes;
	}

	/**
	 * Returns the value of the first matching meta tag.
	 *
	 * @param string $html      Markup.
	 * @param string $key       Attribute value to match, for example `description`.
	 * @param string $attribute Attribute to match on: `name` or `property`.
	 *
	 * @return string|null Null when the tag is absent.
	 */
	public static function get_meta( $html, $key, $attribute = 'name' ) {
		foreach ( self::get_tags( $html, 'meta' ) as $meta ) {
			if ( ! isset( $meta[ $attribute ] ) ) {
				continue;
			}

			if ( strtolower( trim( $meta[ $attribute ] ) ) === strtolower( $key ) ) {
				return isset( $meta['content'] ) ? self::clean_text( $meta['content'] ) : '';
			}
		}

		return null;
	}

	/**
	 * Returns every canonical link href found in the document.
	 *
	 * @param string $html Markup.
	 *
	 * @return string[]
	 */
	public static function get_canonicals( $html ) {
		$found = array();

		foreach ( self::get_tags( $html, 'link' ) as $link ) {
			if ( empty( $link['rel'] ) || empty( $link['href'] ) ) {
				continue;
			}

			$rels = preg_split( '#\s+#', strtolower( trim( $link['rel'] ) ) );

			if ( is_array( $rels ) && in_array( 'canonical', $rels, true ) ) {
				$found[] = trim( $link['href'] );
			}
		}

		return $found;
	}

	/**
	 * Returns the heading levels used in the document, in document order.
	 *
	 * Headings inside header, nav and footer landmarks are still counted; the
	 * audit only reports on the overall structure.
	 *
	 * @param string $html Markup.
	 *
	 * @return array[] List of arrays with `level` and `text` keys.
	 */
	public static function get_headings( $html ) {
		$headings = array();
		$matches  = array();

		if ( ! preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', (string) $html, $matches, PREG_SET_ORDER ) ) {
			return $headings;
		}

		foreach ( $matches as $match ) {
			$headings[] = array(
				'level' => (int) $match[1],
				'text'  => self::clean_text( $match[2] ),
			);
		}

		return $headings;
	}

	/**
	 * Returns every href found in anchor tags.
	 *
	 * @param string $html Markup.
	 *
	 * @return string[]
	 */
	public static function get_links( $html ) {
		$links = array();

		foreach ( self::get_tags( $html, 'a' ) as $anchor ) {
			if ( isset( $anchor['href'] ) && '' !== trim( $anchor['href'] ) ) {
				$links[] = trim( $anchor['href'] );
			}
		}

		return $links;
	}

	/**
	 * Returns the body of the document when it can be isolated.
	 *
	 * @param string $html Markup.
	 *
	 * @return string
	 */
	public static function get_body( $html ) {
		if ( preg_match( '#<body\b[^>]*>(.*)</body>#is', (string) $html, $matches ) ) {
			return $matches[1];
		}

		return (string) $html;
	}

	/**
	 * Strips tags and normalises whitespace.
	 *
	 * @param string $text Raw text.
	 *
	 * @return string
	 */
	public static function clean_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '#\s+#u', ' ', $text );

		return trim( (string) $text );
	}
}
