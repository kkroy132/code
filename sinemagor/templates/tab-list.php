<?php defined('ABSPATH') || exit;
$genres  = Sinemagor_DB::get_genres();
$years   = Sinemagor_DB::get_years();
?>

<div class="sg-section">

  <!-- ── Generator Form ── -->
  <div class="sg-list-form-wrap">
    <h3 class="sg-section-title">⚡ Create a New List Post</h3>

    <div class="sg-list-form">

      <!-- Row 1: Template -->
      <div class="sg-form-row">
        <label class="sg-form-label">Template</label>
        <select id="sg-list-template" class="sg-select sg-select--wide">
          <?php foreach (Sinemagor_List_Generator::TEMPLATES as $val => $label): ?>
            <option value="<?php echo esc_attr($val); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Row 2: Filters -->
      <div class="sg-form-row sg-form-row--cols">
        <div>
          <label class="sg-form-label">Genre</label>
          <select id="sg-list-genre" class="sg-select">
            <option value="">All Genres</option>
            <?php foreach ($genres as $g): ?>
              <option value="<?php echo esc_attr($g); ?>"><?php echo esc_html($g); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="sg-form-label">Year</label>
          <select id="sg-list-year" class="sg-select">
            <option value="">All Years</option>
            <?php foreach ($years as $y): ?>
              <option value="<?php echo esc_attr($y); ?>"><?php echo esc_html($y); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="sg-form-label">Count</label>
          <select id="sg-list-count" class="sg-select">
            <?php foreach ([5,10,15,20] as $n): ?>
              <option value="<?php echo esc_attr($n); ?>" <?php selected($n, 10); ?>><?php echo esc_html($n); ?> Movies</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="sg-form-label">Min Rating</label>
          <select id="sg-list-rating" class="sg-select">
            <option value="0">Any Rating</option>
            <option value="6">6.0+</option>
            <option value="7">7.0+</option>
            <option value="7.5" selected>7.5+</option>
            <option value="8">8.0+</option>
            <option value="8.5">8.5+</option>
          </select>
        </div>
      </div>

      <!-- Extra fields (shown based on template) -->
      <div id="sg-list-extra" class="sg-form-row sg-form-row--cols" style="display:none">
        <div id="sg-extra-director-wrap" style="display:none">
          <label class="sg-form-label">Director Name</label>
          <input type="text" id="sg-list-director" class="sg-input" placeholder="e.g. Christopher Nolan" />
        </div>
        <div id="sg-extra-platform-wrap" style="display:none">
          <label class="sg-form-label">Platform</label>
          <select id="sg-list-platform" class="sg-select">
            <option value="Netflix">Netflix</option>
            <option value="Amazon Prime">Amazon Prime</option>
            <option value="Disney+">Disney+</option>
            <option value="Apple TV+">Apple TV+</option>
            <option value="HBO Max">HBO Max</option>
            <option value="Hulu">Hulu</option>
          </select>
        </div>
        <div id="sg-extra-movie-wrap" style="display:none">
          <label class="sg-form-label">Reference Movie</label>
          <input type="text" id="sg-list-movie-ref" class="sg-input" placeholder="e.g. Inception" />
        </div>
      </div>

      <!-- Title preview -->
      <div class="sg-form-row">
        <label class="sg-form-label">Post Title <span style="color:#6b7280;font-weight:400">(auto-generated, editable)</span></label>
        <input type="text" id="sg-list-custom-title" class="sg-input" placeholder="Loading preview..." />
      </div>

      <!-- Generate button -->
      <div class="sg-form-row">
        <button id="sg-list-load-movies-btn" class="sg-btn sg-btn--secondary">
          📋 Preview Movies
        </button>
        <button id="sg-list-generate-btn" class="sg-btn sg-btn--primary" style="display:none">
          ⚡ Generate &amp; Publish List Post
        </button>
      </div>

    </div><!-- .sg-list-form -->

    <!-- Movie preview grid -->
    <div id="sg-list-preview-wrap" style="display:none;margin-top:24px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 style="margin:0;color:#1e1e1e" id="sg-list-preview-title">Movies in this list:</h4>
        <label style="color:#6b7280;font-size:.85rem">
          <input type="checkbox" id="sg-list-select-all" checked /> Select All
        </label>
      </div>
      <div id="sg-list-movie-grid" class="sg-list-movie-grid"></div>
      <div style="margin-top:14px;color:#6b7280;font-size:.85rem" id="sg-list-warning"></div>
    </div>

  </div><!-- .sg-list-form-wrap -->

  <!-- ── Generated Posts Table ── -->
  <div class="sg-library-section" style="margin-top:32px">
    <h3 class="sg-section-title">📋 Published List Posts</h3>
    <div class="sg-table-wrap">
      <table class="sg-table" id="sg-list-posts-table">
        <thead>
          <tr>
            <th>Title</th>
            <th>Template</th>
            <th>Movies</th>
            <th>Status</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="sg-list-posts-tbody">
          <tr><td colspan="6" class="sg-loading-row">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ── Result Modal ── -->
<div id="sg-list-modal" class="sg-modal" style="display:none">
  <div class="sg-modal-backdrop"></div>
  <div class="sg-modal-box">
    <div class="sg-modal-header">
      <h3 id="sg-list-modal-title">Generating List Post...</h3>
      <button class="sg-modal-close" id="sg-list-modal-close">✕</button>
    </div>
    <div class="sg-modal-body" id="sg-list-modal-body">
      <div class="sg-spinner-wrap">
        <div class="sg-spinner"></div>
        <p>AI is writing your list post...<br><small style="color:#6b7280">This may take 30-60 seconds</small></p>
      </div>
    </div>
    <div class="sg-modal-footer" id="sg-list-modal-footer" style="display:none">
      <a id="sg-list-view-btn"  href="#" target="_blank" class="sg-btn sg-btn--primary">👁 View Post</a>
      <a id="sg-list-edit-btn"  href="#" target="_blank" class="sg-btn sg-btn--secondary">✏️ Edit Post</a>
      <button id="sg-list-close-btn" class="sg-btn sg-btn--ghost">Close</button>
    </div>
  </div>
</div>
