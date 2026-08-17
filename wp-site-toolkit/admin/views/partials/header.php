<?php
/**
 * Shared screen header.
 *
 * @package WP_Site_Toolkit
 *
 * @var array $view Screen data.
 */

defined( 'ABSPATH' ) || exit;

$wpstk_page   = $view['page'];
$wpstk_notice = $view['notice'];
?>
<h1 class="wpstk-heading">
	<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
	<?php echo esc_html__( 'WP Site Toolkit', 'wp-site-toolkit' ); ?>
	<span class="wpstk-heading__page"><?php echo esc_html( $wpstk_page['title'] ); ?></span>
</h1>

<?php if ( is_array( $wpstk_notice ) ) : ?>
	<div class="notice notice-<?php echo esc_attr( $wpstk_notice['type'] ); ?> is-dismissible">
		<p><?php echo esc_html( $wpstk_notice['message'] ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $view['running']['running'] ) && 'audit' !== $wpstk_page['view'] ) : ?>
	<div class="notice notice-info">
		<p>
			<?php echo esc_html__( 'An audit is currently running.', 'wp-site-toolkit' ); ?>
			<a href="<?php echo esc_url( WPSTK_Admin::page_url( 'wp-site-toolkit-audit' ) ); ?>">
				<?php echo esc_html__( 'View progress', 'wp-site-toolkit' ); ?>
			</a>
		</p>
	</div>
<?php endif; 
