<?php
/**
 * Selects which destination URL a visitor gets, for multi-destination rotating links.
 * Implements the three rotation methods: round robin, random, and weighted random.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

namespace QuickLinkQRPro\Helpers;

use QuickLinkQRPro\Models\Destination;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DestinationRotator
 */
final class DestinationRotator {

	/**
	 * Pick one destination from a list of active destinations, according to the given method.
	 *
	 * @param Destination[] $destinations   Active destinations only (caller is responsible for
	 *                                      filtering out disabled ones — this class doesn't
	 *                                      re-check status, so pre-filtered input keeps it simple
	 *                                      and testable).
	 * @param string         $method        round_robin|random|weighted_random.
	 * @param int            $rotation_cursor Current round-robin position (ignored by the other methods).
	 * @return array{destination: Destination|null, next_cursor: int}
	 */
	public static function pick( array $destinations, string $method, int $rotation_cursor = 0 ): array {
		$destinations = array_values( $destinations );

		if ( empty( $destinations ) ) {
			return array(
				'destination' => null,
				'next_cursor' => $rotation_cursor,
			);
		}

		return match ( $method ) {
			'random'          => self::pick_random( $destinations, $rotation_cursor ),
			'weighted_random' => self::pick_weighted_random( $destinations, $rotation_cursor ),
			default           => self::pick_round_robin( $destinations, $rotation_cursor ),
		};
	}

	/**
	 * Sequential rotation: visitor 1 -> destination 1, visitor 2 -> destination 2, ... looping
	 * back to the start. The cursor is the index of the destination to use *this* time; the
	 * caller persists next_cursor (mod count) back onto the link row for the next visitor.
	 *
	 * @param Destination[] $destinations Active destinations.
	 * @param int            $cursor      Current cursor value.
	 * @return array{destination: Destination, next_cursor: int}
	 */
	private static function pick_round_robin( array $destinations, int $cursor ): array {
		$count = count( $destinations );
		$index = $cursor % $count;

		return array(
			'destination' => $destinations[ $index ],
			'next_cursor' => ( $index + 1 ) % $count,
		);
	}

	/**
	 * Uniform random selection — every active destination has an equal chance regardless of weight.
	 *
	 * @param Destination[] $destinations Active destinations.
	 * @param int            $cursor      Passed through unchanged (round-robin cursor isn't used here).
	 * @return array{destination: Destination, next_cursor: int}
	 */
	private static function pick_random( array $destinations, int $cursor ): array {
		$index = random_int( 0, count( $destinations ) - 1 );

		return array(
			'destination' => $destinations[ $index ],
			'next_cursor' => $cursor,
		);
	}

	/**
	 * Weighted random selection: each destination's chance of being picked is proportional to
	 * its configured weight (e.g. weights 70/20/10 give roughly a 70%/20%/10% split over many
	 * visits). Destinations with a weight of 0 are effectively never picked unless every
	 * destination has weight 0, in which case this falls back to uniform random so a 0-weight
	 * link still redirects somewhere rather than silently failing.
	 *
	 * @param Destination[] $destinations Active destinations.
	 * @param int            $cursor      Passed through unchanged (round-robin cursor isn't used here).
	 * @return array{destination: Destination, next_cursor: int}
	 */
	private static function pick_weighted_random( array $destinations, int $cursor ): array {
		$total_weight = array_sum( array_map( static fn( Destination $d ): int => max( 0, $d->weight ), $destinations ) );

		if ( $total_weight <= 0 ) {
			return self::pick_random( $destinations, $cursor );
		}

		$roll     = random_int( 1, $total_weight );
		$running  = 0;

		foreach ( $destinations as $destination ) {
			$running += max( 0, $destination->weight );
			if ( $roll <= $running ) {
				return array(
					'destination' => $destination,
					'next_cursor' => $cursor,
				);
			}
		}

		// Defensive fallback — should be unreachable given the math above, but guarantees a
		// destination is always returned rather than null if floating-point/rounding edge cases
		// ever slipped through.
		return array(
			'destination' => $destinations[ count( $destinations ) - 1 ],
			'next_cursor' => $cursor,
		);
	}
}
