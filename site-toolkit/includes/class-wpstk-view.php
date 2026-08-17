<?php
/**
 * Reusable admin markup.
 *
 * @package WP_Site_Toolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small rendering helpers shared by the admin views.
 *
 * Everything printed here is escaped at the point of output.
 *
 * @since 1.0.0
 */
class WPSTK_View {

	/**
	 * Prints a status badge.
	 *
	 * @param string $status Status key.
	 *
	 * @return void
	 */
	public static function badge( $status ) {
		printf(
			'<span class="wpstk-badge wpstk-badge--%1$s">%2$s</span>',
			esc_attr( WPSTK_Admin::status_class( $status ) ),
			esc_html( WPSTK_Check::status_label( $status ) )
		);
	}

	/**
	 * Prints a score card.
	 *
	 * @param array $args {
	 *     @type string   $title    Card title.
	 *     @type int|null $score    Score between 0 and 100.
	 *     @type string   $subtitle Line under the score.
	 *     @type string   $icon     Dashicon class.
	 *     @type string   $url      Optional link target.
	 *     @type array    $counts   Optional status counts.
	 *     @type bool     $large    Whether to render the large variant.
	 * }
	 *
	 * @return void
	 */
	public static function score_card( $args ) {
		$defaults = array(
			'title'    => '',
			'score'    => null,
			'subtitle' => '',
			'icon'     => '',
			'url'      => '',
			'counts'   => array(),
			'large'    => false,
		);

		$args  = array_merge( $defaults, $args );
		$score = null === $args['score'] ? null : (int) $args['score'];
		$class = WPSTK_Admin::score_class( $score );

		$classes = 'wpstk-card wpstk-score-card wpstk-score-card--' . $class;

		if ( $args['large'] ) {
			$classes .= ' wpstk-score-card--large';
		}

		echo '<div class="' . esc_attr( $classes ) . '">';

		echo '<div class="wpstk-score-card__head">';

		if ( '' !== $args['icon'] ) {
			echo '<span class="dashicons ' . esc_attr( $args['icon'] ) . '" aria-hidden="true"></span>';
		}

		echo '<h3 class="wpstk-score-card__title">';

		if ( '' !== $args['url'] ) {
			echo '<a href="' . esc_url( $args['url'] ) . '">' . esc_html( $args['title'] ) . '</a>';
		} else {
			echo esc_html( $args['title'] );
		}

		echo '</h3>';
		echo '</div>';

		echo '<p class="wpstk-score-card__value">';

		if ( null === $score ) {
			echo '<span class="wpstk-score-card__number">&mdash;</span>';
		} else {
			echo '<span class="wpstk-score-card__number">' . esc_html( number_format_i18n( $score ) ) . '</span>';
			echo '<span class="wpstk-score-card__total"> / 100</span>';
		}

		echo '</p>';

		self::meter( $score );

		if ( '' !== $args['subtitle'] ) {
			echo '<p class="wpstk-score-card__subtitle">' . esc_html( $args['subtitle'] ) . '</p>';
		}

		if ( ! empty( $args['counts'] ) ) {
			self::count_row( $args['counts'] );
		}

		echo '</div>';
	}

	/**
	 * Prints a score meter.
	 *
	 * @param int|null $score Score.
	 *
	 * @return void
	 */
	public static function meter( $score ) {
		$class = WPSTK_Admin::score_class( null === $score ? null : (int) $score );
		$width = null === $score ? 0 : max( 0, min( 100, (int) $score ) );

		if ( null === $score ) {
			$label = __( 'Not checked yet', 'site-toolkit' );
		} else {
			$label = sprintf(
				/* translators: %d: score out of 100. */
				__( 'Score: %d out of 100', 'site-toolkit' ),
				(int) $score
			);
		}

		printf(
			'<div class="wpstk-meter" role="img" aria-label="%1$s"><span class="wpstk-meter__fill wpstk-meter__fill--%2$s" style="width:%3$d%%"></span></div>',
			esc_attr( $label ),
			esc_attr( $class ),
			(int) $width
		);
	}

	/**
	 * Prints a compact row of status counts.
	 *
	 * @param array $counts Counts keyed by status.
	 *
	 * @return void
	 */
	public static function count_row( $counts ) {
		$order = array( 'critical', 'warning', 'recommendation', 'passed', 'skipped' );

		echo '<ul class="wpstk-counts">';

		foreach ( $order as $status ) {
			$value = isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;

			printf(
				'<li class="wpstk-counts__item wpstk-counts__item--%1$s"><span class="wpstk-counts__value">%2$s</span> <span class="wpstk-counts__label">%3$s</span></li>',
				esc_attr( $status ),
				esc_html( number_format_i18n( $value ) ),
				esc_html( WPSTK_Check::status_label( $status ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * Prints the summary tiles shown above a set of results.
	 *
	 * @param array $counts Counts keyed by status.
	 *
	 * @return void
	 */
	public static function summary_tiles( $counts ) {
		$tiles = array(
			'critical'       => __( 'Critical issues', 'site-toolkit' ),
			'warning'        => __( 'Warnings', 'site-toolkit' ),
			'recommendation' => __( 'Recommendations', 'site-toolkit' ),
			'passed'         => __( 'Passed checks', 'site-toolkit' ),
			'skipped'        => __( 'Not checked', 'site-toolkit' ),
		);

		echo '<div class="wpstk-tiles">';

		foreach ( $tiles as $status => $label ) {
			printf(
				'<div class="wpstk-tile wpstk-tile--%1$s"><span class="wpstk-tile__value">%2$s</span><span class="wpstk-tile__label">%3$s</span></div>',
				esc_attr( $status ),
				esc_html( number_format_i18n( isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0 ) ),
				esc_html( $label )
			);
		}

		echo '</div>';
	}

	/**
	 * Prints a list of check results.
	 *
	 * @param array[] $checks       Check results.
	 * @param bool    $show_section Whether to print the section name for each check.
	 *
	 * @return void
	 */
	public static function check_list( $checks, $show_section = false ) {
		if ( empty( $checks ) ) {
			self::empty_state( __( 'Nothing to show here yet.', 'site-toolkit' ) );

			return;
		}

		$audit = wpstk()->audit();

		echo '<div class="wpstk-checks">';

		foreach ( $checks as $check ) {
			$status = WPSTK_Admin::status_class( $check['status'] );
			$module = $audit->get_module( $check['module'] );

			echo '<details class="wpstk-check wpstk-check--' . esc_attr( $status ) . '"' . ( in_array( $status, array( 'critical', 'warning' ), true ) ? ' open' : '' ) . '>';

			echo '<summary class="wpstk-check__summary">';
			self::badge( $check['status'] );
			echo '<span class="wpstk-check__label">' . esc_html( $check['label'] ) . '</span>';

			if ( $show_section && $module ) {
				echo '<span class="wpstk-check__section">' . esc_html( $module->get_label() ) . '</span>';
			}

			if ( (int) $check['items_total'] > 0 ) {
				echo '<span class="wpstk-check__count">' . esc_html(
					sprintf(
						/* translators: %s: number of affected items. */
						_n( '%s item', '%s items', (int) $check['items_total'], 'site-toolkit' ),
						number_format_i18n( (int) $check['items_total'] )
					)
				) . '</span>';
			}

			echo '</summary>';

			echo '<div class="wpstk-check__body">';

			if ( '' !== $check['summary'] ) {
				echo '<p class="wpstk-check__finding"><strong>' . esc_html__( 'What was found:', 'site-toolkit' ) . '</strong> ' . esc_html( $check['summary'] ) . '</p>';
			}

			if ( '' !== $check['why'] ) {
				echo '<p class="wpstk-check__why"><strong>' . esc_html__( 'Why it matters:', 'site-toolkit' ) . '</strong> ' . esc_html( $check['why'] ) . '</p>';
			}

			if ( '' !== $check['action'] ) {
				echo '<p class="wpstk-check__action"><strong>' . esc_html__( 'What to do:', 'site-toolkit' ) . '</strong> ' . esc_html( $check['action'] ) . '</p>';
			}

			if ( '' !== $check['note'] ) {
				echo '<p class="wpstk-check__note">' . esc_html( $check['note'] ) . '</p>';
			}

			self::item_table( $check );

			echo '</div>';
			echo '</details>';
		}

		echo '</div>';
	}

	/**
	 * Prints the affected items of a check.
	 *
	 * @param array $check Check result.
	 *
	 * @return void
	 */
	public static function item_table( $check ) {
		if ( empty( $check['items'] ) ) {
			return;
		}

		echo '<div class="wpstk-table-wrap">';
		echo '<table class="wp-list-table widefat striped wpstk-items">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Item', 'site-toolkit' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Detail', 'site-toolkit' ) . '</th>';
		echo '<th scope="col" class="wpstk-items__actions">' . esc_html__( 'Action', 'site-toolkit' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $check['items'] as $item ) {
			echo '<tr>';

			echo '<td>';
			echo '<span class="wpstk-items__label">' . esc_html( $item['label'] ) . '</span>';

			if ( '' !== $item['url'] ) {
				echo '<br /><a class="wpstk-items__url" href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( WPSTK_Content::shorten( $item['url'], 80 ) ) . '</a>';
			}

			echo '</td>';

			echo '<td>' . esc_html( $item['detail'] ) . '</td>';

			echo '<td class="wpstk-items__actions">';

			if ( '' !== $item['link'] ) {
				printf(
					'<a class="button button-small" href="%1$s">%2$s</a>',
					esc_url( $item['link'] ),
					esc_html( '' !== $item['link_label'] ? $item['link_label'] : __( 'View', 'site-toolkit' ) )
				);
			} elseif ( '' !== $item['url'] ) {
				printf(
					'<a class="button button-small" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
					esc_url( $item['url'] ),
					esc_html__( 'Open', 'site-toolkit' )
				);
			} else {
				echo '&mdash;';
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		$shown = count( $check['items'] );
		$total = (int) $check['items_total'];

		if ( $total > $shown ) {
			echo '<p class="wpstk-check__more">' . esc_html(
				sprintf(
					/* translators: 1: number shown, 2: total number. */
					__( 'Showing %1$s of %2$s affected items.', 'site-toolkit' ),
					number_format_i18n( $shown ),
					number_format_i18n( $total )
				)
			) . '</p>';
		}
	}

	/**
	 * Prints an empty state message.
	 *
	 * @param string $message Message.
	 * @param string $button  Optional button label.
	 * @param string $url     Optional button URL.
	 *
	 * @return void
	 */
	public static function empty_state( $message, $button = '', $url = '' ) {
		echo '<div class="wpstk-empty">';
		echo '<p>' . esc_html( $message ) . '</p>';

		if ( '' !== $button && '' !== $url ) {
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html( $button ) . '</a></p>';
		}

		echo '</div>';
	}

	/**
	 * Prints the audit runner markup.
	 *
	 * @param array $running Progress payload for a running audit.
	 * @param bool  $compact Whether to render the compact variant.
	 *
	 * @return void
	 */
	public static function runner( $running, $compact = false ) {
		$is_running = ! empty( $running['running'] );
		$can_run    = WPSTK_Security::can( 'run' );

		echo '<div class="wpstk-runner' . ( $compact ? ' wpstk-runner--compact' : '' ) . '" data-wpstk-runner data-running="' . esc_attr( $is_running ? '1' : '0' ) . '">';

		echo '<div class="wpstk-runner__controls">';

		if ( $can_run ) {
			printf(
				'<button type="button" class="button button-primary button-hero wpstk-runner__start" data-wpstk-start%1$s>%2$s</button>',
				$is_running ? ' disabled="disabled"' : '',
				esc_html__( 'Run Full Site Audit', 'site-toolkit' )
			);

			printf(
				'<button type="button" class="button wpstk-runner__cancel" data-wpstk-cancel%1$s>%2$s</button>',
				$is_running ? '' : ' hidden="hidden"',
				esc_html__( 'Cancel', 'site-toolkit' )
			);
		} else {
			echo '<p class="description">' . esc_html__( 'You can view results, but you do not have permission to start an audit.', 'site-toolkit' ) . '</p>';
		}

		echo '</div>';

		echo '<div class="wpstk-runner__progress"' . ( $is_running ? '' : ' hidden="hidden"' ) . ' data-wpstk-progress>';
		echo '<div class="wpstk-meter wpstk-meter--progress"><span class="wpstk-meter__fill wpstk-meter__fill--good" style="width:' . esc_attr( (float) ( isset( $running['percent'] ) ? $running['percent'] : 0 ) ) . '%" data-wpstk-bar></span></div>';
		echo '<p class="wpstk-runner__status" data-wpstk-status aria-live="polite">' . esc_html( isset( $running['label'] ) ? $running['label'] : '' ) . '</p>';
		echo '</div>';

		echo '<p class="wpstk-runner__message" data-wpstk-message hidden="hidden" aria-live="polite"></p>';

		echo '</div>';
	}
}
