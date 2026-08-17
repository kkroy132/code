<?php
/**
 * Redirects tab within the Links section.
 *
 * @package WP_Site_Toolkit
 *
 * @var array $view Screen data.
 */

defined( 'ABSPATH' ) || exit;

$wpstk_can_manage     = WPSTK_Security::can( 'manage' );
$wpstk_edit_id        = (int) WPSTK_Security::get_query_arg( 'edit_id', '0' );
$wpstk_editing        = $wpstk_edit_id > 0 ? WPSTK_Redirects::get( $wpstk_edit_id ) : null;
$wpstk_prefill_source = WPSTK_Security::get_query_arg( 'source', '' );

$wpstk_redirect_paged = max( 1, (int) WPSTK_Security::get_query_arg( 'paged', '1' ) );
$wpstk_redirect_per   = 25;
$wpstk_redirect_rows  = WPSTK_Redirects::get_all( $wpstk_redirect_per, ( $wpstk_redirect_paged - 1 ) * $wpstk_redirect_per );
$wpstk_redirect_total = WPSTK_Redirects::count_all();
$wpstk_redirect_pages = (int) ceil( $wpstk_redirect_total / $wpstk_redirect_per );
?>

<?php if ( $wpstk_can_manage ) : ?>
<div class="wpstk-card">
	<h2 class="wpstk-card__title">
		<?php echo $wpstk_editing ? esc_html__( 'Edit redirect', 'site-toolkit' ) : esc_html__( 'Add a redirect', 'site-toolkit' ); ?>
	</h2>
	<p class="description"><?php echo esc_html__( 'Send visitors and search engines from an old address on this site to a new one. Redirects are checked on every front-end request, so keep the list to addresses you actually need.', 'site-toolkit' ); ?></p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpstk-redirect-form">
		<?php wp_nonce_field( 'wpstk_save_redirect' ); ?>
		<input type="hidden" name="action" value="wpstk_save_redirect" />
		<input type="hidden" name="redirect_id" value="<?php echo esc_attr( $wpstk_editing ? (int) $wpstk_editing['id'] : 0 ); ?>" />

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="wpstk-source-path"><?php echo esc_html__( 'From this address', 'site-toolkit' ); ?></label></th>
					<td>
						<code><?php echo esc_html( untrailingslashit( home_url() ) ); ?></code>
						<input type="text" id="wpstk-source-path" name="source_path" class="regular-text code" placeholder="/old-page/" value="<?php echo esc_attr( $wpstk_editing ? $wpstk_editing['source_path'] : $wpstk_prefill_source ); ?>" required="required" />
						<p class="description"><?php echo esc_html__( 'A path on this site, starting with a slash.', 'site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpstk-target-url"><?php echo esc_html__( 'To this address', 'site-toolkit' ); ?></label></th>
					<td>
						<input type="text" id="wpstk-target-url" name="target_url" class="regular-text code" placeholder="/new-page/ or https://example.com/" value="<?php echo esc_attr( $wpstk_editing ? $wpstk_editing['target_url'] : '' ); ?>" required="required" />
						<p class="description"><?php echo esc_html__( 'A path on this site or a full web address.', 'site-toolkit' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpstk-status-code"><?php echo esc_html__( 'Redirect type', 'site-toolkit' ); ?></label></th>
					<td>
						<?php $wpstk_current_code = $wpstk_editing ? (int) $wpstk_editing['status_code'] : 301; ?>
						<select id="wpstk-status-code" name="status_code">
							<option value="301" <?php selected( 301, $wpstk_current_code ); ?>><?php echo esc_html__( '301 — Permanent', 'site-toolkit' ); ?></option>
							<option value="302" <?php selected( 302, $wpstk_current_code ); ?>><?php echo esc_html__( '302 — Temporary', 'site-toolkit' ); ?></option>
							<option value="307" <?php selected( 307, $wpstk_current_code ); ?>><?php echo esc_html__( '307 — Temporary (method preserved)', 'site-toolkit' ); ?></option>
						</select>
						<p class="description"><?php echo esc_html__( 'Use 301 when the old address is gone for good. Search engines update their index faster for 301s.', 'site-toolkit' ); ?></p>
					</td>
				</tr>
				<?php if ( $wpstk_editing ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Enabled', 'site-toolkit' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( 1, (int) $wpstk_editing['enabled'] ); ?> />
							<?php echo esc_html__( 'This redirect is active', 'site-toolkit' ); ?>
						</label>
					</td>
				</tr>
				<?php else : ?>
					<input type="hidden" name="enabled" value="1" />
				<?php endif; ?>
			</tbody>
		</table>

		<p>
			<button type="submit" class="button button-primary"><?php echo $wpstk_editing ? esc_html__( 'Save changes', 'site-toolkit' ) : esc_html__( 'Add redirect', 'site-toolkit' ); ?></button>
			<?php if ( $wpstk_editing ) : ?>
				<a class="button" href="<?php echo esc_url( WPSTK_Admin::page_url( 'site-toolkit-links', array( 'tab' => 'redirects' ) ) ); ?>"><?php echo esc_html__( 'Cancel', 'site-toolkit' ); ?></a>
			<?php endif; ?>
		</p>
	</form>
</div>
<?php endif; ?>

<div class="wpstk-card">
	<h2 class="wpstk-card__title"><?php echo esc_html__( 'Redirects', 'site-toolkit' ); ?></h2>

	<?php if ( empty( $wpstk_redirect_rows ) ) : ?>
		<?php WPSTK_View::empty_state( __( 'No redirects have been created yet.', 'site-toolkit' ) ); ?>
	<?php else : ?>
		<div class="wpstk-table-wrap">
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'From', 'site-toolkit' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'To', 'site-toolkit' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Type', 'site-toolkit' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Hits', 'site-toolkit' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Status', 'site-toolkit' ); ?></th>
						<?php if ( $wpstk_can_manage ) : ?>
							<th scope="col"><?php echo esc_html__( 'Actions', 'site-toolkit' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wpstk_redirect_rows as $wpstk_row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $wpstk_row['source_path'] ); ?></code></td>
							<td><code><?php echo esc_html( WPSTK_Content::shorten( $wpstk_row['target_url'], 60 ) ); ?></code></td>
							<td><?php echo esc_html( (string) (int) $wpstk_row['status_code'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $wpstk_row['hits'] ) ); ?></td>
							<td>
								<?php if ( ! empty( $wpstk_row['enabled'] ) ) : ?>
									<span class="wpstk-badge wpstk-badge--passed"><?php echo esc_html__( 'Active', 'site-toolkit' ); ?></span>
								<?php else : ?>
									<span class="wpstk-badge wpstk-badge--skipped"><?php echo esc_html__( 'Disabled', 'site-toolkit' ); ?></span>
								<?php endif; ?>
							</td>
							<?php if ( $wpstk_can_manage ) : ?>
								<td>
									<a class="button button-small" href="<?php echo esc_url( WPSTK_Admin::page_url( 'site-toolkit-links', array( 'tab' => 'redirects', 'edit_id' => (int) $wpstk_row['id'] ) ) ); ?>">
										<?php echo esc_html__( 'Edit', 'site-toolkit' ); ?>
									</a>
									<a class="button button-small" href="
									<?php
									echo esc_url(
										wp_nonce_url(
											add_query_arg(
												array(
													'action'      => 'wpstk_toggle_redirect',
													'redirect_id' => (int) $wpstk_row['id'],
													'enabled'     => empty( $wpstk_row['enabled'] ) ? 1 : 0,
												),
												admin_url( 'admin-post.php' )
											),
											'wpstk_toggle_redirect'
										)
									);
									?>
									"><?php echo empty( $wpstk_row['enabled'] ) ? esc_html__( 'Enable', 'site-toolkit' ) : esc_html__( 'Disable', 'site-toolkit' ); ?></a>
									<a class="button button-small wpstk-button-danger" data-wpstk-confirm="<?php echo esc_attr__( 'Delete this redirect?', 'site-toolkit' ); ?>" href="
									<?php
									echo esc_url(
										wp_nonce_url(
											add_query_arg(
												array(
													'action'      => 'wpstk_delete_redirect',
													'redirect_id' => (int) $wpstk_row['id'],
												),
												admin_url( 'admin-post.php' )
											),
											'wpstk_delete_redirect'
										)
									);
									?>
									"><?php echo esc_html__( 'Delete', 'site-toolkit' ); ?></a>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( $wpstk_redirect_pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => WPSTK_Admin::page_url( 'site-toolkit-links', array( 'tab' => 'redirects' ) ) . '%_%',
							'format'    => '&paged=%#%',
							'current'   => $wpstk_redirect_paged,
							'total'     => $wpstk_redirect_pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
