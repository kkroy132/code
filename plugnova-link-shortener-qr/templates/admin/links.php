<?php
/**
 * Links management admin page view. The table body and modal are driven by assets/js/admin.js.
 *
 * @package QuickLinkQRPro
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap qlqr-wrap">
	<h1 class="qlqr-page-title">
		<?php esc_html_e( 'Links', 'plugnova-link-shortener-qr' ); ?>
		<button type="button" class="page-title-action" id="qlqr-open-create-modal">
			<?php esc_html_e( 'Add New', 'plugnova-link-shortener-qr' ); ?>
		</button>
	</h1>

	<?php if ( $qlqr_near_link_limit ) : ?>
		<div class="notice notice-warning is-dismissible qlqr-limit-notice" data-qlqr-notice-key="links-limit">
			<p>
				<?php
				printf(
					/* translators: 1: current active link count, 2: free plan's link limit */
					esc_html__( "You're using %1\$d of %2\$d links on the free plan.", 'plugnova-link-shortener-qr' ),
					(int) $qlqr_near_link_limit['active'],
					(int) $qlqr_near_link_limit['limit']
				);
				?>
				<a href="<?php echo esc_url( qlqr_fs()->get_upgrade_url() ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Upgrade to Pro for unlimited links.', 'plugnova-link-shortener-qr' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<div class="qlqr-toolbar qlqr-quick-add-bar">
		<input type="url" id="qlqr-quick-add-url" placeholder="<?php esc_attr_e( 'Paste a URL and press Enter to create a link instantly…', 'plugnova-link-shortener-qr' ); ?>" />
		<button type="button" class="button button-primary" id="qlqr-quick-add-btn"><?php esc_html_e( 'Quick Add', 'plugnova-link-shortener-qr' ); ?></button>
		<span id="qlqr-quick-add-result"></span>
	</div>

	<div class="qlqr-toolbar">
		<input type="search" id="qlqr-search" placeholder="<?php esc_attr_e( 'Search title, URL, or slug…', 'plugnova-link-shortener-qr' ); ?>" />

		<select id="qlqr-filter-status">
			<option value="all"><?php esc_html_e( 'All statuses', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="active"><?php esc_html_e( 'Active', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="disabled"><?php esc_html_e( 'Disabled', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="trash"><?php esc_html_e( 'Trash', 'plugnova-link-shortener-qr' ); ?></option>
		</select>

		<select id="qlqr-filter-category">
			<option value=""><?php esc_html_e( 'All categories', 'plugnova-link-shortener-qr' ); ?></option>
		</select>

		<select id="qlqr-filter-link-status">
			<option value="all"><?php esc_html_e( 'Any link health', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="broken"><?php esc_html_e( 'Broken only', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="ok"><?php esc_html_e( 'OK only', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="unknown"><?php esc_html_e( 'Not checked yet', 'plugnova-link-shortener-qr' ); ?></option>
		</select>

		<select id="qlqr-filter-color">
			<option value=""><?php esc_html_e( 'Any color', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="#d63638"><?php esc_html_e( 'Red', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="#dba617"><?php esc_html_e( 'Amber', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="#1a7f37"><?php esc_html_e( 'Green', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="#2271b1"><?php esc_html_e( 'Blue', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="#8c30f5"><?php esc_html_e( 'Purple', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="#e56fae"><?php esc_html_e( 'Pink', 'plugnova-link-shortener-qr' ); ?></option>
		</select>

		<input type="text" id="qlqr-filter-tag" placeholder="<?php esc_attr_e( 'Filter by tag…', 'plugnova-link-shortener-qr' ); ?>" />

		<label class="qlqr-filter-favorite-toggle">
			<input type="checkbox" id="qlqr-filter-favorite" /> <?php esc_html_e( 'Favorites only', 'plugnova-link-shortener-qr' ); ?>
		</label>

		<label class="qlqr-filter-date-label">
			<?php esc_html_e( 'From', 'plugnova-link-shortener-qr' ); ?>
			<input type="date" id="qlqr-filter-date-from" />
		</label>
		<label class="qlqr-filter-date-label">
			<?php esc_html_e( 'To', 'plugnova-link-shortener-qr' ); ?>
			<input type="date" id="qlqr-filter-date-to" />
		</label>

		<button type="button" class="button" id="qlqr-recheck-all"><?php esc_html_e( 'Check All Links', 'plugnova-link-shortener-qr' ); ?></button>
		<button type="button" class="button" id="qlqr-open-import-modal"><?php esc_html_e( 'Import', 'plugnova-link-shortener-qr' ); ?></button>
		<button type="button" class="button" id="qlqr-export-csv"><?php esc_html_e( 'Export CSV', 'plugnova-link-shortener-qr' ); ?></button>
	</div>

	<div class="qlqr-bulk-bar">
		<select id="qlqr-bulk-action">
			<option value=""><?php esc_html_e( 'Bulk actions…', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="enable"><?php esc_html_e( 'Enable', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="disable"><?php esc_html_e( 'Disable', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="regenerate_qr"><?php esc_html_e( 'Regenerate QR Code', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="set_category"><?php esc_html_e( 'Set Category…', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="add_tag"><?php esc_html_e( 'Add Tag…', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="set_redirect_type"><?php esc_html_e( 'Set Redirect Type…', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="trash"><?php esc_html_e( 'Move to Trash', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="restore"><?php esc_html_e( 'Restore from Trash', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="delete"><?php esc_html_e( 'Delete Permanently', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="export_selected"><?php esc_html_e( 'Export Selected as CSV', 'plugnova-link-shortener-qr' ); ?></option>
		</select>
		<input type="text" id="qlqr-bulk-value" style="display:none;" list="qlqr-category-options" placeholder="<?php esc_attr_e( 'Value…', 'plugnova-link-shortener-qr' ); ?>" />
		<?php /* Its own control rather than the free-text box above: the redirect type is a choice of three, and typing it invites typos. */ ?>
		<select id="qlqr-bulk-redirect-type" style="display:none;">
			<option value="302" selected><?php esc_html_e( '302 — recommended, counts every click', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="301"><?php esc_html_e( '301 — permanent, browsers cache it', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="307"><?php esc_html_e( '307 — temporary, preserves the request method', 'plugnova-link-shortener-qr' ); ?></option>
		</select>
		<button type="button" class="button" id="qlqr-bulk-apply" disabled><?php esc_html_e( 'Apply', 'plugnova-link-shortener-qr' ); ?></button>
		<span class="description" id="qlqr-bulk-selected-count"></span>
	</div>

	<?php /* Wrapper so a narrow screen scrolls this fourteen-column table sideways instead of wrapping its rows — see .qlqr-table-scroll in assets/css/admin.css. */ ?>
	<div class="qlqr-table-scroll">
	<table class="widefat striped" id="qlqr-links-table">
		<thead>
			<tr>
				<th class="qlqr-col-select"><input type="checkbox" id="qlqr-select-all" /></th>
				<th class="qlqr-col-serial">#</th>
				<th class="qlqr-col-fav"></th>
				<th class="qlqr-col-score" title="<?php esc_attr_e( 'Smart Link Score', 'plugnova-link-shortener-qr' ); ?>">⭐</th>
				<th class="qlqr-col-title" data-orderby="title"><?php esc_html_e( 'Title', 'plugnova-link-shortener-qr' ); ?></th>
				<th class="qlqr-col-short" data-orderby="short_slug"><?php esc_html_e( 'Short URL', 'plugnova-link-shortener-qr' ); ?></th>
				<th class="qlqr-col-destination"><?php esc_html_e( 'Destination', 'plugnova-link-shortener-qr' ); ?></th>
				<th class="qlqr-col-category"><?php esc_html_e( 'Category', 'plugnova-link-shortener-qr' ); ?></th>
				<th class="qlqr-col-qr"><?php esc_html_e( 'QR', 'plugnova-link-shortener-qr' ); ?></th>
				<th class="qlqr-col-clicks" data-orderby="total_clicks"><?php esc_html_e( 'Clicks', 'plugnova-link-shortener-qr' ); ?></th>
				<th class="qlqr-col-trend" title="<?php esc_attr_e( 'Clicks — Last 7 Days', 'plugnova-link-shortener-qr' ); ?>"><?php esc_html_e( 'Trend', 'plugnova-link-shortener-qr' ); ?></th>
				<th class="qlqr-col-status"><?php esc_html_e( 'Status', 'plugnova-link-shortener-qr' ); ?></th>
				<th class="qlqr-col-created" data-orderby="created_at"><?php esc_html_e( 'Created', 'plugnova-link-shortener-qr' ); ?></th>
				<th class="qlqr-row-actions"><?php esc_html_e( 'Actions', 'plugnova-link-shortener-qr' ); ?></th>
			</tr>
		</thead>
		<tbody id="qlqr-links-tbody">
			<tr><td colspan="14"><?php esc_html_e( 'Loading…', 'plugnova-link-shortener-qr' ); ?></td></tr>
		</tbody>
	</table>
	</div>

	<div class="qlqr-pagination" id="qlqr-pagination"></div>
</div>

<!-- Create / Edit modal -->
<div id="qlqr-modal" class="qlqr-modal" style="display:none;" aria-hidden="true">
	<div class="qlqr-modal-inner">
		<button type="button" class="qlqr-modal-close" id="qlqr-modal-close" aria-label="<?php esc_attr_e( 'Close', 'plugnova-link-shortener-qr' ); ?>">&times;</button>
		<h2 id="qlqr-modal-title"><?php esc_html_e( 'Create Short Link', 'plugnova-link-shortener-qr' ); ?></h2>

		<form id="qlqr-create-form">
			<input type="hidden" id="qlqr-f-edit-id" value="" />
			<p>
				<label for="qlqr-f-title"><?php esc_html_e( 'Title (optional)', 'plugnova-link-shortener-qr' ); ?></label>
				<input type="text" id="qlqr-f-title" name="title" />
			</p>
			<p>
				<label for="qlqr-f-category"><?php esc_html_e( 'Category (optional)', 'plugnova-link-shortener-qr' ); ?></label>
				<input type="text" id="qlqr-f-category" name="category" list="qlqr-category-options" placeholder="<?php esc_attr_e( 'e.g. Affiliate, Social, Campaign 2025', 'plugnova-link-shortener-qr' ); ?>" />
				<datalist id="qlqr-category-options"></datalist>
			</p>
			<p>
				<label>
					<?php esc_html_e( 'Destination Type', 'plugnova-link-shortener-qr' ); ?>
					<?php if ( ! qlqr_fs()->can_use_premium_code() ) : ?>
						<span class="qlqr-pro-badge">
							<?php esc_html_e( 'Pro feature', 'plugnova-link-shortener-qr' ); ?> —
							<a href="<?php echo esc_url( qlqr_fs()->get_upgrade_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro', 'plugnova-link-shortener-qr' ); ?></a>
						</span>
					<?php endif; ?>
				</label>
				<span class="qlqr-radio-group">
					<label class="qlqr-radio-inline">
						<input type="radio" name="destination_type" id="qlqr-f-dest-type-single" value="single" checked />
						<?php esc_html_e( 'Single Destination URL', 'plugnova-link-shortener-qr' ); ?>
					</label>
					<label class="qlqr-radio-inline">
						<input type="radio" name="destination_type" id="qlqr-f-dest-type-multiple" value="multiple" <?php disabled( ! qlqr_fs()->can_use_premium_code() ); ?> />
						<?php esc_html_e( 'Multiple Destination URLs', 'plugnova-link-shortener-qr' ); ?>
					</label>
				</span>
				<?php if ( ! qlqr_fs()->can_use_premium_code() ) : ?>
					<span class="description"><?php esc_html_e( 'Any destinations below are shown for reference but are not currently active on the free plan — visitors always get the first destination.', 'plugnova-link-shortener-qr' ); ?></span>
				<?php endif; ?>
			</p>

			<p id="qlqr-f-single-destination-wrap">
				<label for="qlqr-f-destination"><?php esc_html_e( 'Destination URL', 'plugnova-link-shortener-qr' ); ?> *</label>
				<input type="url" id="qlqr-f-destination" name="destination_url" placeholder="https://example.com/page" />
			</p>

			<div id="qlqr-f-multi-destination-wrap" style="display:none;" class="<?php echo qlqr_fs()->can_use_premium_code() ? '' : 'qlqr-pro-locked'; ?>">
				<div class="qlqr-form-row">
					<p>
						<label for="qlqr-f-rotation-method"><?php esc_html_e( 'Rotation Method', 'plugnova-link-shortener-qr' ); ?></label>
						<select id="qlqr-f-rotation-method" name="rotation_method" <?php disabled( ! qlqr_fs()->can_use_premium_code() ); ?>>
							<option value="round_robin"><?php esc_html_e( 'Round Robin', 'plugnova-link-shortener-qr' ); ?></option>
							<option value="random"><?php esc_html_e( 'Random', 'plugnova-link-shortener-qr' ); ?></option>
							<option value="weighted_random"><?php esc_html_e( 'Weighted Random', 'plugnova-link-shortener-qr' ); ?></option>
						</select>
					</p>
					<p>
						<label for="qlqr-f-fallback-url"><?php esc_html_e( 'Fallback URL (optional)', 'plugnova-link-shortener-qr' ); ?></label>
						<input type="url" id="qlqr-f-fallback-url" name="fallback_url" placeholder="https://example.com/default" <?php disabled( ! qlqr_fs()->can_use_premium_code() ); ?> />
					</p>
				</div>
				<p class="description"><?php esc_html_e( 'Used if every destination below is disabled. Leave blank to show a "not available" page instead.', 'plugnova-link-shortener-qr' ); ?></p>

				<label><?php esc_html_e( 'Destinations', 'plugnova-link-shortener-qr' ); ?></label>
				<div id="qlqr-destinations-repeater"></div>
				<p>
					<button type="button" class="button" id="qlqr-add-destination" <?php disabled( ! qlqr_fs()->can_use_premium_code() ); ?>><?php esc_html_e( '+ Add Destination', 'plugnova-link-shortener-qr' ); ?></button>
					<button type="button" class="button" id="qlqr-bulk-dest-toggle" <?php disabled( ! qlqr_fs()->can_use_premium_code() ); ?>><?php esc_html_e( 'Paste a List…', 'plugnova-link-shortener-qr' ); ?></button>
				</p>

				<div id="qlqr-bulk-dest-panel" style="display:none;">
					<label for="qlqr-bulk-dest-input"><?php esc_html_e( 'One URL per line', 'plugnova-link-shortener-qr' ); ?></label>
					<textarea id="qlqr-bulk-dest-input" rows="6" placeholder="https://example.com/offer-a&#10;https://example.com/offer-b&#10;https://example.com/offer-c"></textarea>
					<p class="description"><?php esc_html_e( 'Commas and spaces work as separators too. Duplicates and anything already in the list are skipped, and a missing https:// is filled in — the summary tells you exactly what happened.', 'plugnova-link-shortener-qr' ); ?></p>
					<p>
						<button type="button" class="button button-primary" id="qlqr-bulk-dest-add"><?php esc_html_e( 'Add to List', 'plugnova-link-shortener-qr' ); ?></button>
						<span id="qlqr-bulk-dest-result" class="description"></span>
					</p>
				</div>
			</div>

			<p id="qlqr-f-slug-wrap">
				<label for="qlqr-f-slug"><?php esc_html_e( 'Custom Slug (optional, random if blank)', 'plugnova-link-shortener-qr' ); ?></label>
				<input type="text" id="qlqr-f-slug" name="custom_slug" placeholder="my-offer" />
				<span class="description" id="qlqr-f-slug-locked-note" style="display:none;"><?php esc_html_e( 'The short URL slug can\'t be changed after creation, so existing shared links and printed QR codes keep working.', 'plugnova-link-shortener-qr' ); ?></span>
			</p>

			<div class="qlqr-form-row">
				<p>
					<label for="qlqr-f-redirect"><?php esc_html_e( 'Redirect Type', 'plugnova-link-shortener-qr' ); ?></label>
					<select id="qlqr-f-redirect" name="redirect_type">
						<option value="301">301 (<?php esc_html_e( 'Permanent', 'plugnova-link-shortener-qr' ); ?>)</option>
						<option value="302">302 (<?php esc_html_e( 'Temporary', 'plugnova-link-shortener-qr' ); ?>)</option>
						<option value="307">307 (<?php esc_html_e( 'Temporary, method preserved', 'plugnova-link-shortener-qr' ); ?>)</option>
					</select>
				</p>
				<p>
					<label for="qlqr-f-status"><?php esc_html_e( 'Status', 'plugnova-link-shortener-qr' ); ?></label>
					<select id="qlqr-f-status" name="status">
						<option value="active"><?php esc_html_e( 'Active', 'plugnova-link-shortener-qr' ); ?></option>
						<option value="disabled"><?php esc_html_e( 'Disabled', 'plugnova-link-shortener-qr' ); ?></option>
					</select>
				</p>
			</div>

			<h3><?php esc_html_e( 'QR Code Style', 'plugnova-link-shortener-qr' ); ?></h3>
			<div class="qlqr-form-row">
				<p>
					<label for="qlqr-f-qr-style"><?php esc_html_e( 'Style', 'plugnova-link-shortener-qr' ); ?></label>
					<select id="qlqr-f-qr-style" name="qr_style">
						<option value="square"><?php esc_html_e( 'Square', 'plugnova-link-shortener-qr' ); ?></option>
						<option value="rounded"><?php esc_html_e( 'Rounded', 'plugnova-link-shortener-qr' ); ?></option>
						<option value="circle"><?php esc_html_e( 'Circle (Dots)', 'plugnova-link-shortener-qr' ); ?></option>
					</select>
				</p>
				<p>
					<label for="qlqr-f-qr-fg"><?php esc_html_e( 'Foreground', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="color" id="qlqr-f-qr-fg" name="qr_fg_color" value="#000000" />
				</p>
				<p>
					<label for="qlqr-f-qr-bg"><?php esc_html_e( 'Background', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="color" id="qlqr-f-qr-bg" name="qr_bg_color" value="#ffffff" />
				</p>
			</div>
			<p>
				<label><input type="checkbox" id="qlqr-f-qr-transparent" name="qr_transparent" /> <?php esc_html_e( 'Transparent background', 'plugnova-link-shortener-qr' ); ?></label>
			</p>
			<p>
				<label for="qlqr-f-qr-caption"><?php esc_html_e( 'Caption Text (optional, baked into the QR image itself)', 'plugnova-link-shortener-qr' ); ?></label>
				<input type="text" id="qlqr-f-qr-caption" name="qr_caption_text" maxlength="100" placeholder="<?php esc_attr_e( 'e.g. Check Price on Amazon', 'plugnova-link-shortener-qr' ); ?>" />
				<span class="description"><?php esc_html_e( 'Shown as a caption band under the QR code — useful for video/thumbnail use. English text only (the bundled font doesn\'t include Bengali glyphs yet).', 'plugnova-link-shortener-qr' ); ?></span>
			</p>
			<p>
				<label><?php esc_html_e( 'Brand Logo (optional, shown above the QR code)', 'plugnova-link-shortener-qr' ); ?></label>
				<input type="hidden" id="qlqr-f-qr-logo-id" name="qr_logo_id" value="" />
				<span id="qlqr-f-qr-logo-preview-wrap" style="display:none;">
					<img id="qlqr-f-qr-logo-preview" src="" alt="" style="width:48px;height:48px;object-fit:contain;border:1px solid #dcdcde;border-radius:4px;vertical-align:middle;margin-right:8px;" />
				</span>
				<button type="button" class="button" id="qlqr-f-qr-logo-select"><?php esc_html_e( 'Select Logo', 'plugnova-link-shortener-qr' ); ?></button>
				<button type="button" class="button" id="qlqr-f-qr-logo-remove" style="display:none;"><?php esc_html_e( 'Remove', 'plugnova-link-shortener-qr' ); ?></button>
				<span class="description"><?php esc_html_e( 'Shown in its own band above the QR code (not overlapping the code itself), so scanning is never affected.', 'plugnova-link-shortener-qr' ); ?></span>
			</p>

			<h3><?php esc_html_e( 'Advanced', 'plugnova-link-shortener-qr' ); ?></h3>
			<div class="qlqr-form-row">
				<p>
					<label for="qlqr-f-expires"><?php esc_html_e( 'Expires At (optional)', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="datetime-local" id="qlqr-f-expires" name="expires_at" />
				</p>
				<p>
					<label for="qlqr-f-limit"><?php esc_html_e( 'Click Limit (optional)', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="number" id="qlqr-f-limit" name="click_limit" min="1" />
				</p>
				<p>
					<label for="qlqr-f-password"><?php esc_html_e( 'Password (optional)', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="text" id="qlqr-f-password" name="password" />
					<span class="description" id="qlqr-f-password-note" style="display:none;"><?php esc_html_e( 'Leave blank to keep the current password.', 'plugnova-link-shortener-qr' ); ?></span>
					<label id="qlqr-f-clear-password-wrap" style="display:none; font-weight:normal; margin-top:4px;">
						<input type="checkbox" id="qlqr-f-clear-password" name="clear_password" /> <?php esc_html_e( 'Remove password protection', 'plugnova-link-shortener-qr' ); ?>
					</label>
				</p>
			</div>

			<div class="qlqr-form-row">
				<p>
					<label for="qlqr-f-utm-source"><?php esc_html_e( 'UTM Source', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="text" id="qlqr-f-utm-source" name="utm_source" />
				</p>
				<p>
					<label for="qlqr-f-utm-medium"><?php esc_html_e( 'UTM Medium', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="text" id="qlqr-f-utm-medium" name="utm_medium" />
				</p>
				<p>
					<label for="qlqr-f-utm-campaign"><?php esc_html_e( 'UTM Campaign', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="text" id="qlqr-f-utm-campaign" name="utm_campaign" />
				</p>
			</div>

			<p>
				<label><input type="checkbox" id="qlqr-f-nofollow" name="nofollow" /> <?php esc_html_e( 'rel="nofollow"', 'plugnova-link-shortener-qr' ); ?></label>
				&nbsp;&nbsp;
				<label><input type="checkbox" id="qlqr-f-sponsored" name="sponsored" /> <?php esc_html_e( 'rel="sponsored"', 'plugnova-link-shortener-qr' ); ?></label>
				&nbsp;&nbsp;
				<label><input type="checkbox" id="qlqr-f-new-tab" name="new_tab" checked /> <?php esc_html_e( 'Open in new tab', 'plugnova-link-shortener-qr' ); ?></label>
			</p>

			<p>
				<label for="qlqr-f-notes"><?php esc_html_e( 'Campaign Notes (optional, admin-only)', 'plugnova-link-shortener-qr' ); ?></label>
				<textarea id="qlqr-f-notes" name="notes" rows="2" style="width:100%;box-sizing:border-box;" placeholder="<?php esc_attr_e( 'e.g. Running as part of the Q3 influencer campaign, paused after Aug 15.', 'plugnova-link-shortener-qr' ); ?>"></textarea>
			</p>

			<p>
				<label><?php esc_html_e( 'Color Label (optional)', 'plugnova-link-shortener-qr' ); ?></label>
				<input type="hidden" id="qlqr-f-color-label" name="color_label" value="" />
				<span class="qlqr-color-swatches" id="qlqr-color-swatches">
					<button type="button" class="qlqr-color-swatch qlqr-color-swatch-none" data-color="" title="<?php esc_attr_e( 'No color', 'plugnova-link-shortener-qr' ); ?>">✕</button>
					<button type="button" class="qlqr-color-swatch" data-color="#d63638" style="background:#d63638;" title="Red"></button>
					<button type="button" class="qlqr-color-swatch" data-color="#dba617" style="background:#dba617;" title="Amber"></button>
					<button type="button" class="qlqr-color-swatch" data-color="#1a7f37" style="background:#1a7f37;" title="Green"></button>
					<button type="button" class="qlqr-color-swatch" data-color="#2271b1" style="background:#2271b1;" title="Blue"></button>
					<button type="button" class="qlqr-color-swatch" data-color="#8c30f5" style="background:#8c30f5;" title="Purple"></button>
					<button type="button" class="qlqr-color-swatch" data-color="#e56fae" style="background:#e56fae;" title="Pink"></button>
				</span>
				<span class="description"><?php esc_html_e( 'Shown as a colored bar on the left edge of the row in the Links table.', 'plugnova-link-shortener-qr' ); ?></span>
			</p>

			<h3>
				<?php esc_html_e( 'Geo & Device Targeting (optional)', 'plugnova-link-shortener-qr' ); ?>
				<?php if ( ! qlqr_fs()->can_use_premium_code() ) : ?>
					<span class="qlqr-pro-badge">
						<?php esc_html_e( 'Pro feature', 'plugnova-link-shortener-qr' ); ?> —
						<a href="<?php echo esc_url( qlqr_fs()->get_upgrade_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade to Pro', 'plugnova-link-shortener-qr' ); ?></a>
					</span>
				<?php endif; ?>
			</h3>
			<p class="description">
				<?php esc_html_e( 'Send visitors to a different URL based on their country or device type. Rules are checked top to bottom — the first match wins. Visitors who match no rule get the destination(s) configured above.', 'plugnova-link-shortener-qr' ); ?>
				<?php if ( ! qlqr_fs()->can_use_premium_code() ) : ?>
					<strong><?php esc_html_e( 'Any rules below are shown for reference but are not currently active on the free plan — visitors always get the destination(s) configured above.', 'plugnova-link-shortener-qr' ); ?></strong>
				<?php endif; ?>
			</p>
			<div id="qlqr-targeting-repeater" class="<?php echo qlqr_fs()->can_use_premium_code() ? '' : 'qlqr-pro-locked'; ?>"></div>
			<p>
				<button type="button" class="button" id="qlqr-add-targeting-rule" <?php disabled( ! qlqr_fs()->can_use_premium_code() ); ?>><?php esc_html_e( '+ Add Rule', 'plugnova-link-shortener-qr' ); ?></button>
			</p>

			<h3><?php esc_html_e( 'Keyword Auto-Linking (optional)', 'plugnova-link-shortener-qr' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Occurrences of these words or phrases in your posts and pages are turned into a link to this short link automatically — no manual linking needed. Existing links and HTML markup are never touched.', 'plugnova-link-shortener-qr' ); ?>
				<?php if ( ! get_option( 'qlqr_keyword_autolink_enabled', 1 ) ) : ?>
					<strong><?php esc_html_e( 'Auto-linking is currently turned off in Settings.', 'plugnova-link-shortener-qr' ); ?></strong>
				<?php endif; ?>
			</p>
			<div id="qlqr-keywords-repeater"></div>
			<p>
				<button type="button" class="button" id="qlqr-add-keyword"><?php esc_html_e( '+ Add Keyword', 'plugnova-link-shortener-qr' ); ?></button>
			</p>

			<div id="qlqr-form-error" class="qlqr-form-error" style="display:none;"></div>

			<p class="submit">
				<button type="submit" class="button button-primary" id="qlqr-form-submit-btn"><?php esc_html_e( 'Create Link', 'plugnova-link-shortener-qr' ); ?></button>
			</p>
		</form>
	</div>
</div>

<?php /* The QR lightbox previously here now lives in templates/admin/tabs.php, shared with the Bio Links panel — a modal inside this panel would be unreachable while another tab is active. */ ?>

<!-- Import / Migrate modal -->
<div id="qlqr-import-modal" class="qlqr-modal" style="display:none;" aria-hidden="true">
	<div class="qlqr-modal-inner">
		<button type="button" class="qlqr-modal-close" id="qlqr-import-modal-close" aria-label="<?php esc_attr_e( 'Close', 'plugnova-link-shortener-qr' ); ?>">&times;</button>
		<h2><?php esc_html_e( 'Import Links', 'plugnova-link-shortener-qr' ); ?></h2>

		<h3><?php esc_html_e( 'From a CSV File', 'plugnova-link-shortener-qr' ); ?></h3>
		<p class="description"><?php esc_html_e( 'A header row is required with a "destination_url" column; "title", "custom_slug", and "category" columns are optional.', 'plugnova-link-shortener-qr' ); ?></p>
		<form id="qlqr-import-csv-form">
			<p>
				<input type="file" id="qlqr-import-csv-file" accept=".csv,text/csv" required />
			</p>
			<p>
				<label><input type="checkbox" id="qlqr-import-skip-duplicates" checked /> <?php esc_html_e( 'Skip rows whose destination URL already exists', 'plugnova-link-shortener-qr' ); ?></label>
			</p>
			<p class="submit">
				<button type="submit" class="button button-primary" id="qlqr-import-csv-submit"><?php esc_html_e( 'Import CSV', 'plugnova-link-shortener-qr' ); ?></button>
			</p>
		</form>

		<hr />

		<h3><?php esc_html_e( 'From Another Plugin', 'plugnova-link-shortener-qr' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Best-effort migration — please spot-check the imported links afterward, since other plugins\' data formats vary by version.', 'plugnova-link-shortener-qr' ); ?></p>
		<div id="qlqr-migrate-sources">
			<p class="description"><?php esc_html_e( 'Checking for data from other plugins…', 'plugnova-link-shortener-qr' ); ?></p>
		</div>

		<div id="qlqr-import-result" style="display:none;margin-top:16px;"></div>
	</div>
</div>

<!-- Live Destination Preview modal -->
<div id="qlqr-preview-modal" class="qlqr-modal" style="display:none;" aria-hidden="true">
	<div class="qlqr-modal-inner qlqr-preview-inner">
		<button type="button" class="qlqr-modal-close" id="qlqr-preview-close" aria-label="<?php esc_attr_e( 'Close', 'plugnova-link-shortener-qr' ); ?>">&times;</button>
		<h2>👁️ <?php esc_html_e( 'Live Destination Preview', 'plugnova-link-shortener-qr' ); ?></h2>
		<p class="description">
			<span id="qlqr-preview-url-text" class="qlqr-truncate"></span>
			<a href="#" id="qlqr-preview-open-tab" target="_blank" rel="noopener"><?php esc_html_e( 'Open in new tab ↗', 'plugnova-link-shortener-qr' ); ?></a>
		</p>
		<p class="description"><?php esc_html_e( 'If the preview below stays blank, that site blocks being shown inside another page — use "Open in new tab" instead.', 'plugnova-link-shortener-qr' ); ?></p>
		<iframe id="qlqr-preview-iframe" class="qlqr-preview-iframe" src="" title="<?php esc_attr_e( 'Destination preview', 'plugnova-link-shortener-qr' ); ?>"></iframe>
	</div>
</div>

<!-- Smart Link Score breakdown modal -->
<div id="qlqr-score-modal" class="qlqr-modal" style="display:none;" aria-hidden="true">
	<div class="qlqr-modal-inner qlqr-score-inner">
		<button type="button" class="qlqr-modal-close" id="qlqr-score-close" aria-label="<?php esc_attr_e( 'Close', 'plugnova-link-shortener-qr' ); ?>">&times;</button>
		<h2>⭐ <?php esc_html_e( 'Smart Link Score', 'plugnova-link-shortener-qr' ); ?></h2>
		<p id="qlqr-score-title" class="description"></p>

		<div class="qlqr-score-summary">
			<span id="qlqr-score-grade" class="qlqr-score-grade-badge"></span>
			<span id="qlqr-score-percentage" class="qlqr-score-percentage"></span>
		</div>

		<ul id="qlqr-score-checklist" class="qlqr-score-checklist"></ul>
	</div>
</div>

<!-- Per-link analytics modal -->
<div id="qlqr-analytics-modal" class="qlqr-modal" style="display:none;" aria-hidden="true">
	<div class="qlqr-modal-inner qlqr-analytics-inner">
		<button type="button" class="qlqr-modal-close" id="qlqr-analytics-close" aria-label="<?php esc_attr_e( 'Close', 'plugnova-link-shortener-qr' ); ?>">&times;</button>
		<h2 id="qlqr-analytics-title"></h2>
		<p id="qlqr-analytics-url" class="description"></p>

		<div class="qlqr-analytics-toolbar">
			<label for="qlqr-analytics-days"><?php esc_html_e( 'Date range', 'plugnova-link-shortener-qr' ); ?>
				<select id="qlqr-analytics-days">
					<option value="7"><?php esc_html_e( 'Last 7 days', 'plugnova-link-shortener-qr' ); ?></option>
					<option value="30" selected><?php esc_html_e( 'Last 30 days', 'plugnova-link-shortener-qr' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 days', 'plugnova-link-shortener-qr' ); ?></option>
				</select>
			</label>
			<label class="qlqr-analytics-bots-toggle"><input type="checkbox" id="qlqr-analytics-include-bots" /> <?php esc_html_e( 'Include bot traffic', 'plugnova-link-shortener-qr' ); ?></label>
			<button type="button" class="button" id="qlqr-analytics-export-csv"><?php esc_html_e( 'Export CSV', 'plugnova-link-shortener-qr' ); ?></button>
		</div>

		<div class="qlqr-analytics-summary">
			<div class="qlqr-analytics-stat">
				<span class="qlqr-card-label"><?php esc_html_e( 'Clicks', 'plugnova-link-shortener-qr' ); ?></span>
				<span class="qlqr-card-value" id="qlqr-analytics-total">0</span>
			</div>
			<div class="qlqr-analytics-stat">
				<span class="qlqr-card-label"><?php esc_html_e( 'Unique Clicks', 'plugnova-link-shortener-qr' ); ?></span>
				<span class="qlqr-card-value" id="qlqr-analytics-unique">0</span>
			</div>
			<div class="qlqr-analytics-stat">
				<span class="qlqr-card-label"><?php esc_html_e( 'QR Scans', 'plugnova-link-shortener-qr' ); ?></span>
				<span class="qlqr-card-value" id="qlqr-analytics-qr-scans">0</span>
			</div>
			<div class="qlqr-analytics-stat" id="qlqr-analytics-bots-stat" title="<?php esc_attr_e( 'Detected bot/crawler visits — always excluded from the numbers above unless \"Include bot traffic\" is checked.', 'plugnova-link-shortener-qr' ); ?>">
				<span class="qlqr-card-label"><?php esc_html_e( 'Bot Traffic', 'plugnova-link-shortener-qr' ); ?></span>
				<span class="qlqr-card-value" id="qlqr-analytics-bots">0</span>
			</div>
		</div>

		<div id="qlqr-analytics-destinations-wrap" style="display:none;">
			<h3><?php esc_html_e( 'Destination Statistics', 'plugnova-link-shortener-qr' ); ?></h3>
			<p class="description" id="qlqr-analytics-rotation-method"></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Destination URL', 'plugnova-link-shortener-qr' ); ?></th>
						<th><?php esc_html_e( 'Clicks', 'plugnova-link-shortener-qr' ); ?></th>
						<th><?php esc_html_e( '%', 'plugnova-link-shortener-qr' ); ?></th>
						<th><?php esc_html_e( 'Status', 'plugnova-link-shortener-qr' ); ?></th>
					</tr>
				</thead>
				<tbody id="qlqr-analytics-destinations"></tbody>
			</table>
		</div>

		<h3><?php esc_html_e( 'Clicks Over Time', 'plugnova-link-shortener-qr' ); ?></h3>
		<canvas id="qlqr-analytics-chart" height="90"></canvas>

		<div class="qlqr-analytics-grid">
			<div>
				<h3><?php esc_html_e( 'Devices', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-analytics-devices"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'Browsers', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-analytics-browsers"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'Operating Systems', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-analytics-os"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'Countries', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-analytics-countries"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'Referrers', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-analytics-referrers"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'UTM Campaigns', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-analytics-utm"></table>
			</div>
		</div>
	</div>
</div>
