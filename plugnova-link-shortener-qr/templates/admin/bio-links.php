<?php
/**
 * Smart Bio Link management admin page view. The table body and modal are driven by assets/js/admin.js.
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
		<?php esc_html_e( 'Bio Links', 'plugnova-link-shortener-qr' ); ?>
		<button type="button" class="page-title-action" id="qlqr-bio-open-create-modal">
			<?php esc_html_e( 'Add New', 'plugnova-link-shortener-qr' ); ?>
		</button>
		<button type="button" class="page-title-action" id="qlqr-bio-import-template-btn">
			<?php esc_html_e( 'Import Template', 'plugnova-link-shortener-qr' ); ?>
		</button>
		<input type="file" id="qlqr-bio-import-template-file" accept="application/json,.json" style="display:none;" />
	</h1>
	<p class="description"><?php esc_html_e( 'A Smart Bio Link is a single mobile-friendly landing page listing several of your links — like a "link in bio" page — with its own short URL, QR code, and per-button click tracking.', 'plugnova-link-shortener-qr' ); ?></p>

	<?php if ( $qlqr_at_bio_page_limit ) : ?>
		<div class="notice notice-warning is-dismissible qlqr-limit-notice" data-qlqr-notice-key="bio-links-limit">
			<p>
				<?php
				printf(
					esc_html(
						/* translators: %d: the free plan's Bio Page limit */
						_n(
							"You're using the %d Bio Page included on the free plan.",
							"You're using all %d Bio Pages included on the free plan.",
							(int) $qlqr_at_bio_page_limit['limit'],
							'plugnova-link-shortener-qr'
						)
					),
					(int) $qlqr_at_bio_page_limit['limit']
				);
				?>
				<a href="<?php echo esc_url( qlqr_fs()->get_upgrade_url() ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Upgrade to Pro for unlimited Bio Pages.', 'plugnova-link-shortener-qr' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<div class="qlqr-toolbar">
		<input type="search" id="qlqr-bio-search" placeholder="<?php esc_attr_e( 'Search title or slug…', 'plugnova-link-shortener-qr' ); ?>" />

		<select id="qlqr-bio-filter-status">
			<option value="all"><?php esc_html_e( 'All statuses', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="active"><?php esc_html_e( 'Active', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="disabled"><?php esc_html_e( 'Disabled', 'plugnova-link-shortener-qr' ); ?></option>
			<option value="trash"><?php esc_html_e( 'Trash', 'plugnova-link-shortener-qr' ); ?></option>
		</select>
	</div>

	<table class="widefat striped" id="qlqr-bio-table">
		<thead>
			<tr>
				<th class="qlqr-col-serial">#</th>
				<th><?php esc_html_e( 'Title', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'Public URL', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'QR', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'Links', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'Views', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'Emails', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'Status', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'Created', 'plugnova-link-shortener-qr' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'plugnova-link-shortener-qr' ); ?></th>
			</tr>
		</thead>
		<tbody id="qlqr-bio-tbody">
			<tr><td colspan="10"><?php esc_html_e( 'Loading…', 'plugnova-link-shortener-qr' ); ?></td></tr>
		</tbody>
	</table>

	<div class="qlqr-pagination" id="qlqr-bio-pagination"></div>
</div>

<!-- Create / Edit modal -->
<div id="qlqr-bio-modal" class="qlqr-modal" style="display:none;" aria-hidden="true">
	<div class="qlqr-modal-inner qlqr-bio-modal-inner">
		<button type="button" class="qlqr-modal-close" id="qlqr-bio-modal-close" aria-label="<?php esc_attr_e( 'Close', 'plugnova-link-shortener-qr' ); ?>">&times;</button>
		<h2 id="qlqr-bio-modal-title"><?php esc_html_e( 'Create Bio Page', 'plugnova-link-shortener-qr' ); ?></h2>

		<div id="qlqr-bio-template-picker-wrap">
			<p class="description"><?php esc_html_e( 'Start from a ready-made look (you can still change anything below):', 'plugnova-link-shortener-qr' ); ?></p>
			<div class="qlqr-bio-template-picker" id="qlqr-bio-template-picker"></div>
		</div>

	<div class="qlqr-bio-modal-columns">
	<div class="qlqr-bio-form-col">
		<p class="submit" style="text-align:right;margin-top:0;">
			<button type="button" class="button" id="qlqr-bio-export-template-btn"><?php esc_html_e( 'Export as Template', 'plugnova-link-shortener-qr' ); ?></button>
		</p>
		<form id="qlqr-bio-form">
			<input type="hidden" id="qlqr-bio-f-edit-id" value="" />
			<p>
				<label for="qlqr-bio-f-title"><?php esc_html_e( 'Title', 'plugnova-link-shortener-qr' ); ?> *</label>
				<input type="text" id="qlqr-bio-f-title" name="title" placeholder="<?php esc_attr_e( 'Jane Doe', 'plugnova-link-shortener-qr' ); ?>" required />
			</p>
			<p>
				<label for="qlqr-bio-f-slug"><?php esc_html_e( 'Custom Slug (optional, random if blank)', 'plugnova-link-shortener-qr' ); ?></label>
				<input type="text" id="qlqr-bio-f-slug" name="custom_slug" placeholder="janedoe" />
				<span class="description" id="qlqr-bio-f-slug-locked-note" style="display:none;"><?php esc_html_e( 'The page slug can\'t be changed after creation, so already-shared links and printed QR codes keep working.', 'plugnova-link-shortener-qr' ); ?></span>
			</p>
			<p>
				<label for="qlqr-bio-f-bio-text"><?php esc_html_e( 'Bio Text (optional)', 'plugnova-link-shortener-qr' ); ?></label>
				<textarea id="qlqr-bio-f-bio-text" name="bio_text" rows="2" maxlength="500" style="width:100%;box-sizing:border-box;"></textarea>
			</p>
			<p>
				<label for="qlqr-bio-f-avatar"><?php esc_html_e( 'Avatar Image (optional)', 'plugnova-link-shortener-qr' ); ?></label>
				<br />
				<span id="qlqr-bio-f-avatar-preview-wrap" style="display:none;">
					<img id="qlqr-bio-f-avatar-preview" src="" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:50%;border:1px solid #dcdcde;vertical-align:middle;margin-right:8px;" />
				</span>
				<button type="button" class="button" id="qlqr-bio-f-avatar-select"><?php esc_html_e( 'Select from Media Library', 'plugnova-link-shortener-qr' ); ?></button>
				<button type="button" class="button" id="qlqr-bio-f-avatar-remove" style="display:none;"><?php esc_html_e( 'Remove', 'plugnova-link-shortener-qr' ); ?></button>
				<br /><br />
				<input type="url" id="qlqr-bio-f-avatar" name="avatar_url" placeholder="https://example.com/avatar.jpg" style="width:100%;box-sizing:border-box;" />
				<span class="description"><?php esc_html_e( 'Pick an image already uploaded to this site, or paste any external image URL.', 'plugnova-link-shortener-qr' ); ?></span>
			</p>

			<div class="qlqr-form-row">
				<p>
					<label for="qlqr-bio-f-theme-color"><?php esc_html_e( 'Theme Color', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="color" id="qlqr-bio-f-theme-color" name="theme_color" value="#2271b1" />
				</p>
				<p>
					<label for="qlqr-bio-f-theme-preset"><?php esc_html_e( 'Theme', 'plugnova-link-shortener-qr' ); ?></label>
					<select id="qlqr-bio-f-theme-preset" name="theme_preset">
						<option value="light"><?php esc_html_e( 'Light', 'plugnova-link-shortener-qr' ); ?></option>
						<option value="dark"><?php esc_html_e( 'Dark', 'plugnova-link-shortener-qr' ); ?></option>
						<option value="gradient"><?php esc_html_e( 'Gradient', 'plugnova-link-shortener-qr' ); ?></option>
						<option value="minimal"><?php esc_html_e( 'Minimal', 'plugnova-link-shortener-qr' ); ?></option>
					</select>
				</p>
				<p>
					<label for="qlqr-bio-f-button-style"><?php esc_html_e( 'Button Style', 'plugnova-link-shortener-qr' ); ?></label>
					<select id="qlqr-bio-f-button-style" name="button_style">
						<option value="rounded"><?php esc_html_e( 'Rounded', 'plugnova-link-shortener-qr' ); ?></option>
						<option value="square"><?php esc_html_e( 'Square', 'plugnova-link-shortener-qr' ); ?></option>
						<option value="pill"><?php esc_html_e( 'Pill', 'plugnova-link-shortener-qr' ); ?></option>
					</select>
				</p>
				<p>
					<label for="qlqr-bio-f-status"><?php esc_html_e( 'Status', 'plugnova-link-shortener-qr' ); ?></label>
					<select id="qlqr-bio-f-status" name="status">
						<option value="active"><?php esc_html_e( 'Active', 'plugnova-link-shortener-qr' ); ?></option>
						<option value="disabled"><?php esc_html_e( 'Disabled', 'plugnova-link-shortener-qr' ); ?></option>
					</select>
				</p>
			</div>

			<h3><?php esc_html_e( 'Campaign Scheduling (optional)', 'plugnova-link-shortener-qr' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Restrict the whole page to a date range — useful for a limited-time campaign page. Leave both blank to always show the page.', 'plugnova-link-shortener-qr' ); ?></p>
			<div class="qlqr-form-row">
				<p>
					<label for="qlqr-bio-f-starts-at"><?php esc_html_e( 'Show From', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="datetime-local" id="qlqr-bio-f-starts-at" name="starts_at" />
				</p>
				<p>
					<label for="qlqr-bio-f-ends-at"><?php esc_html_e( 'Show Until', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="datetime-local" id="qlqr-bio-f-ends-at" name="ends_at" />
				</p>
			</div>

			<h3><?php esc_html_e( 'Advanced (optional)', 'plugnova-link-shortener-qr' ); ?></h3>
			<p>
				<label for="qlqr-bio-f-password"><?php esc_html_e( 'Password', 'plugnova-link-shortener-qr' ); ?></label>
				<input type="text" id="qlqr-bio-f-password" name="password" />
				<span class="description" id="qlqr-bio-f-password-note" style="display:none;"><?php esc_html_e( 'Leave blank to keep the current password.', 'plugnova-link-shortener-qr' ); ?></span>
				<label id="qlqr-bio-f-clear-password-wrap" style="display:none; font-weight:normal; margin-top:4px;">
					<input type="checkbox" id="qlqr-bio-f-clear-password" name="clear_password" /> <?php esc_html_e( 'Remove password protection', 'plugnova-link-shortener-qr' ); ?>
				</label>
			</p>
			<h3><?php esc_html_e( 'Announcement Bar (optional)', 'plugnova-link-shortener-qr' ); ?></h3>
			<p class="description"><?php esc_html_e( 'A dismissible strip shown above the page — good for a promo code or a quick notice.', 'plugnova-link-shortener-qr' ); ?></p>
			<p>
				<label><input type="checkbox" id="qlqr-bio-f-announcement-enabled" name="announcement_enabled" /> <?php esc_html_e( 'Show announcement bar', 'plugnova-link-shortener-qr' ); ?></label>
			</p>
			<div id="qlqr-bio-f-announcement-fields-wrap" style="display:none;">
				<p>
					<label for="qlqr-bio-f-announcement-text"><?php esc_html_e( 'Text', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="text" id="qlqr-bio-f-announcement-text" name="announcement_text" maxlength="255" placeholder="<?php esc_attr_e( 'e.g. 20% off this week only — use code SAVE20', 'plugnova-link-shortener-qr' ); ?>" />
				</p>
				<p>
					<label for="qlqr-bio-f-announcement-url"><?php esc_html_e( 'Link URL (optional)', 'plugnova-link-shortener-qr' ); ?></label>
					<input type="url" id="qlqr-bio-f-announcement-url" name="announcement_url" placeholder="https://example.com/sale" />
				</p>
				<div class="qlqr-form-row">
					<p>
						<label for="qlqr-bio-f-announcement-bg"><?php esc_html_e( 'Background Color', 'plugnova-link-shortener-qr' ); ?></label>
						<input type="color" id="qlqr-bio-f-announcement-bg" name="announcement_bg_color" value="#2271b1" />
					</p>
					<p>
						<label for="qlqr-bio-f-announcement-text-color"><?php esc_html_e( 'Text Color', 'plugnova-link-shortener-qr' ); ?></label>
						<input type="color" id="qlqr-bio-f-announcement-text-color" name="announcement_text_color" value="#ffffff" />
					</p>
				</div>
			</div>

			<h3><?php esc_html_e( 'Countdown Timer (optional)', 'plugnova-link-shortener-qr' ); ?></h3>
			<p class="description"><?php esc_html_e( 'A live countdown shown above the links — good for a sale deadline or a launch date. Hidden automatically once the target time passes.', 'plugnova-link-shortener-qr' ); ?></p>
			<p>
				<label><input type="checkbox" id="qlqr-bio-f-countdown-enabled" name="countdown_enabled" /> <?php esc_html_e( 'Show countdown timer', 'plugnova-link-shortener-qr' ); ?></label>
			</p>
			<div id="qlqr-bio-f-countdown-fields-wrap" style="display:none;">
				<div class="qlqr-form-row">
					<p>
						<label for="qlqr-bio-f-countdown-label"><?php esc_html_e( 'Label (optional)', 'plugnova-link-shortener-qr' ); ?></label>
						<input type="text" id="qlqr-bio-f-countdown-label" name="countdown_label" maxlength="190" placeholder="<?php esc_attr_e( 'e.g. Sale ends in', 'plugnova-link-shortener-qr' ); ?>" />
					</p>
					<p>
						<label for="qlqr-bio-f-countdown-target"><?php esc_html_e( 'Counts Down To', 'plugnova-link-shortener-qr' ); ?></label>
						<input type="datetime-local" id="qlqr-bio-f-countdown-target" name="countdown_target_at" />
					</p>
				</div>
			</div>

			<h3><?php esc_html_e( 'Social Icons (optional)', 'plugnova-link-shortener-qr' ); ?></h3>
			<p class="description"><?php esc_html_e( 'A row of small circular icons shown near the top of the page. Any icon can instead be flagged "Floating" to render as a fixed contact button (e.g. WhatsApp/Call) that stays on screen while visitors scroll.', 'plugnova-link-shortener-qr' ); ?></p>
			<div id="qlqr-bio-socials-repeater"></div>
			<p>
				<button type="button" class="button" id="qlqr-bio-add-social"><?php esc_html_e( '+ Add Social Icon', 'plugnova-link-shortener-qr' ); ?></button>
			</p>

			<h3><?php esc_html_e( 'Email Capture (optional)', 'plugnova-link-shortener-qr' ); ?></h3>
			<p>
				<label><input type="checkbox" id="qlqr-bio-f-email-capture" name="email_capture_enabled" /> <?php esc_html_e( 'Require an email address before visitors can see the links', 'plugnova-link-shortener-qr' ); ?></label>
			</p>
			<p id="qlqr-bio-f-email-heading-wrap" style="display:none;">
				<label for="qlqr-bio-f-email-heading"><?php esc_html_e( 'Gate Heading (optional)', 'plugnova-link-shortener-qr' ); ?></label>
				<input type="text" id="qlqr-bio-f-email-heading" name="email_capture_heading" placeholder="<?php esc_attr_e( 'Enter your email to see the links', 'plugnova-link-shortener-qr' ); ?>" />
			</p>

			<h3><?php esc_html_e( 'Links', 'plugnova-link-shortener-qr' ); ?></h3>
			<p class="description"><?php esc_html_e( 'The buttons shown on the page, in this order. Each has its own click counter, an optional visibility schedule, and optional Country/Device targeting. Use "+ Add Group" to bundle several links under a collapsible accordion header.', 'plugnova-link-shortener-qr' ); ?></p>
			<div id="qlqr-bio-links-repeater"></div>
			<p>
				<button type="button" class="button" id="qlqr-bio-add-link"><?php esc_html_e( '+ Add Link', 'plugnova-link-shortener-qr' ); ?></button>
				<button type="button" class="button" id="qlqr-bio-add-group"><?php esc_html_e( '+ Add Group', 'plugnova-link-shortener-qr' ); ?></button>
				<button type="button" class="button" id="qlqr-bio-add-donation"><?php esc_html_e( '+ Add Donation Button', 'plugnova-link-shortener-qr' ); ?></button>
			</p>

			<div id="qlqr-bio-form-error" class="qlqr-form-error" style="display:none;"></div>

			<p class="submit">
				<button type="submit" class="button button-primary" id="qlqr-bio-form-submit-btn"><?php esc_html_e( 'Create Bio Page', 'plugnova-link-shortener-qr' ); ?></button>
			</p>
		</form>
	</div>

	<div class="qlqr-bio-preview-col">
		<p class="description qlqr-bio-preview-label"><?php esc_html_e( 'Live Preview', 'plugnova-link-shortener-qr' ); ?></p>
		<div class="qlqr-bio-phone-frame">
			<div id="qlqr-bio-preview" class="qlqr-bio-preview"></div>
		</div>
	</div>
	</div>
	</div>
</div>

<!-- Bio page analytics modal -->
<div id="qlqr-bio-analytics-modal" class="qlqr-modal" style="display:none;" aria-hidden="true">
	<div class="qlqr-modal-inner qlqr-analytics-inner">
		<button type="button" class="qlqr-modal-close" id="qlqr-bio-analytics-close" aria-label="<?php esc_attr_e( 'Close', 'plugnova-link-shortener-qr' ); ?>">&times;</button>
		<h2 id="qlqr-bio-analytics-title"></h2>
		<p id="qlqr-bio-analytics-url" class="description"></p>

		<div class="qlqr-analytics-toolbar">
			<label for="qlqr-bio-analytics-days"><?php esc_html_e( 'Date range', 'plugnova-link-shortener-qr' ); ?>
				<select id="qlqr-bio-analytics-days">
					<option value="7"><?php esc_html_e( 'Last 7 days', 'plugnova-link-shortener-qr' ); ?></option>
					<option value="30" selected><?php esc_html_e( 'Last 30 days', 'plugnova-link-shortener-qr' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 days', 'plugnova-link-shortener-qr' ); ?></option>
				</select>
			</label>
			<label class="qlqr-analytics-bots-toggle"><input type="checkbox" id="qlqr-bio-analytics-include-bots" /> <?php esc_html_e( 'Include bot traffic', 'plugnova-link-shortener-qr' ); ?></label>
			<button type="button" class="button" id="qlqr-bio-analytics-export-csv"><?php esc_html_e( 'Export CSV', 'plugnova-link-shortener-qr' ); ?></button>
		</div>

		<div class="qlqr-analytics-summary">
			<div class="qlqr-analytics-stat">
				<span class="qlqr-card-label"><?php esc_html_e( 'Views', 'plugnova-link-shortener-qr' ); ?></span>
				<span class="qlqr-card-value" id="qlqr-bio-analytics-total">0</span>
			</div>
			<div class="qlqr-analytics-stat">
				<span class="qlqr-card-label"><?php esc_html_e( 'Unique Views', 'plugnova-link-shortener-qr' ); ?></span>
				<span class="qlqr-card-value" id="qlqr-bio-analytics-unique">0</span>
			</div>
			<div class="qlqr-analytics-stat">
				<span class="qlqr-card-label"><?php esc_html_e( 'QR Views', 'plugnova-link-shortener-qr' ); ?></span>
				<span class="qlqr-card-value" id="qlqr-bio-analytics-qr">0</span>
			</div>
			<div class="qlqr-analytics-stat">
				<span class="qlqr-card-label"><?php esc_html_e( 'Direct Views', 'plugnova-link-shortener-qr' ); ?></span>
				<span class="qlqr-card-value" id="qlqr-bio-analytics-direct">0</span>
			</div>
			<div class="qlqr-analytics-stat">
				<span class="qlqr-card-label"><?php esc_html_e( 'Emails Captured', 'plugnova-link-shortener-qr' ); ?></span>
				<span class="qlqr-card-value" id="qlqr-bio-analytics-emails">0</span>
			</div>
			<div class="qlqr-analytics-stat" id="qlqr-bio-analytics-bots-stat" title="<?php esc_attr_e( 'Detected bot/crawler visits — always excluded from the numbers above unless \"Include bot traffic\" is checked.', 'plugnova-link-shortener-qr' ); ?>">
				<span class="qlqr-card-label"><?php esc_html_e( 'Bot Traffic', 'plugnova-link-shortener-qr' ); ?></span>
				<span class="qlqr-card-value" id="qlqr-bio-analytics-bots">0</span>
			</div>
		</div>

		<div class="qlqr-qr-split-wrap">
			<span class="qlqr-qr-split-label"><?php esc_html_e( 'QR Scan vs. Direct', 'plugnova-link-shortener-qr' ); ?></span>
			<div class="qlqr-qr-split-bar" id="qlqr-bio-analytics-qr-split"></div>
		</div>

		<h3><?php esc_html_e( 'Views Over Time', 'plugnova-link-shortener-qr' ); ?></h3>
		<canvas id="qlqr-bio-analytics-chart" height="90"></canvas>

		<div class="qlqr-analytics-grid">
			<div>
				<h3><?php esc_html_e( 'Top Buttons by Clicks', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-bio-analytics-buttons"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'Countries', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-bio-analytics-countries"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'Devices', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-bio-analytics-devices"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'Browsers', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-bio-analytics-browsers"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'Operating Systems', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-bio-analytics-os"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'Referrers', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-bio-analytics-referrers"></table>
			</div>
			<div>
				<h3><?php esc_html_e( 'UTM Campaigns', 'plugnova-link-shortener-qr' ); ?></h3>
				<table class="widefat striped" id="qlqr-bio-analytics-utm"></table>
			</div>
		</div>
	</div>
</div>
<?php /* The QR lightbox is provided page-wide by templates/admin/tabs.php — see the note there. */ ?>
