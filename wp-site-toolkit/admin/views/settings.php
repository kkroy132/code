<?php
/**
 * Settings screen.
 *
 * @package WP_Site_Toolkit
 *
 * @var array $view Screen data.
 */

defined( 'ABSPATH' ) || exit;

WPSTK_Security::require_cap( 'manage' );
?>

<div class="wpstk-layout">
	<div class="wpstk-layout__main">
		<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post" class="wpstk-card">
			<?php
			settings_fields( WPSTK_Settings::GROUP );
			do_settings_sections( 'wpstk-settings' );
			submit_button( __( 'Save settings', 'wp-site-toolkit' ) );
			?>
		</form>

		<div class="wpstk-card">
			<h2 class="wpstk-card__title"><?php echo esc_html__( 'Stored data', 'wp-site-toolkit' ); ?></h2>
			<p><?php echo esc_html__( 'These actions affect only data created by WP Site Toolkit. Your posts, pages, media, users and settings from other plugins are never touched.', 'wp-site-toolkit' ); ?></p>

			<p>
				<a class="button wpstk-button-danger" data-wpstk-confirm="<?php echo esc_attr__( 'Remove every stored scan, finding and 404 log entry? This cannot be undone.', 'wp-site-toolkit' ); ?>" href="
				<?php
				echo esc_url(
					wp_nonce_url(
						add_query_arg( array( 'action' => 'wpstk_clear_data' ), admin_url( 'admin-post.php' ) ),
						'wpstk_clear_data'
					)
				);
				?>
				"><?php echo esc_html__( 'Clear all stored data', 'wp-site-toolkit' ); ?></a>

				<a class="button" data-wpstk-confirm="<?php echo esc_attr__( 'Restore every setting to its default value?', 'wp-site-toolkit' ); ?>" href="
				<?php
				echo esc_url(
					wp_nonce_url(
						add_query_arg( array( 'action' => 'wpstk_reset_settings' ), admin_url( 'admin-post.php' ) ),
						'wpstk_reset_settings'
					)
				);
				?>
				"><?php echo esc_html__( 'Reset settings to defaults', 'wp-site-toolkit' ); ?></a>
			</p>
		</div>
	</div>

	<div class="wpstk-layout__side">
		<div class="wpstk-card">
			<h2 class="wpstk-card__title"><?php echo esc_html__( 'What this plugin stores', 'wp-site-toolkit' ); ?></h2>
			<ul class="wpstk-list">
				<li><?php echo esc_html__( 'Scan summaries and scores.', 'wp-site-toolkit' ); ?></li>
				<li><?php echo esc_html__( 'Check results, including the titles and addresses of the affected pages.', 'wp-site-toolkit' ); ?></li>
				<li><?php echo esc_html__( '404 requests: the requested address, the referring URL, a hit counter and timestamps.', 'wp-site-toolkit' ); ?></li>
				<li><?php echo esc_html__( 'The settings on this page.', 'wp-site-toolkit' ); ?></li>
			</ul>
			<p><?php echo esc_html__( 'No IP addresses, user agents, visitor identifiers or page content are stored.', 'wp-site-toolkit' ); ?></p>
		</div>

		<div class="wpstk-card">
			<h2 class="wpstk-card__title"><?php echo esc_html__( 'Network requests', 'wp-site-toolkit' ); ?></h2>
			<p><?php echo esc_html__( 'During an audit the plugin makes requests to:', 'wp-site-toolkit' ); ?></p>
			<ul class="wpstk-list">
				<li><?php echo esc_html__( 'Your own site, to inspect rendered pages, the REST API, the sitemap and robots.txt.', 'wp-site-toolkit' ); ?></li>
				<li><?php echo esc_html__( 'The internal links found in your content, to see whether they still work.', 'wp-site-toolkit' ); ?></li>
				<li><?php echo esc_html__( 'External domains you link to — only when you switch "Check external links" on.', 'wp-site-toolkit' ); ?></li>
			</ul>
			<p><?php echo esc_html__( 'There are no other outbound connections: no analytics, no telemetry, no licence server and no third-party API.', 'wp-site-toolkit' ); ?></p>
		</div>

		<div class="wpstk-card">
			<h2 class="wpstk-card__title"><?php echo esc_html__( 'Uninstalling', 'wp-site-toolkit' ); ?></h2>
			<p>
				<?php
				if ( empty( $view['settings']['delete_data'] ) ) {
					echo esc_html__( 'Deleting the plugin will leave its tables and settings in place, so nothing is lost if you reinstall it. Tick the uninstall option on the left to have everything removed instead.', 'wp-site-toolkit' );
				} else {
					echo esc_html__( 'Deleting the plugin will also remove its tables, settings and scheduled events. Your posts, pages and media are not affected.', 'wp-site-toolkit' );
				}
				?>
			</p>
		</div>
	</div>
</div>
