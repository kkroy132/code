<?php defined('ABSPATH') || exit; ?>

<div class="sg-section">

  <!-- ── Queue Progress Bar (hidden until bulk generation starts) ── -->
  <div id="sg-queue-bar" style="display:none;background:#ffffff;border:1px solid #dcdcde;border-radius:10px;padding:16px 20px;margin-bottom:20px">
    <div class="sg-queue-info" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px">
      <div>
        <strong style="color:#1e1e1e">⚡ Bulk Generation Running...</strong>
        <span id="sg-queue-text" style="color:#6b7280;margin-left:12px;font-size:.85rem">0 / 0 completed</span>
      </div>
      <button id="sg-queue-clear-btn" class="sg-btn sg-btn--ghost sg-btn--sm">Cancel Queue</button>
    </div>
    <div style="height:6px;background:#dcdcde;border-radius:3px">
      <div id="sg-progress-fill" style="height:100%;background:#c9972c;border-radius:3px;width:0%;transition:width .4s"></div>
    </div>
    <div id="sg-queue-errors" style="margin-top:8px;font-size:.8rem;color:#e57373"></div>
  </div>

  <!-- ── Filter Bar ── -->
  <div class="sg-filter-bar">
    <input type="text" id="sg-rev-search" placeholder="Search title..." class="sg-input" />
    <select id="sg-rev-status" class="sg-select">
      <option value="">All Status</option>
      <option value="pending">Pending</option>
      <option value="draft">Draft</option>
      <option value="published">Published</option>
    </select>
    <select id="sg-rev-genre" class="sg-select">
      <option value="">All Genres</option>
      <option value="Action">Action</option>
      <option value="Adventure">Adventure</option>
      <option value="Animation">Animation</option>
      <option value="Comedy">Comedy</option>
      <option value="Crime">Crime</option>
      <option value="Drama">Drama</option>
      <option value="Fantasy">Fantasy</option>
      <option value="Horror">Horror</option>
      <option value="Mystery">Mystery</option>
      <option value="Romance">Romance</option>
      <option value="Science Fiction">Sci-Fi</option>
      <option value="Thriller">Thriller</option>
    </select>
    <select id="sg-rev-year" class="sg-select">
      <option value="">All Years</option>
      <?php for ($y = date('Y'); $y >= 1990; $y--): ?>
        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
      <?php endfor; ?>
    </select>
    <button id="sg-rev-filter-btn" class="sg-btn sg-btn--secondary">Filter</button>
  </div>

  <!-- ── Stats bar ── -->
  <div id="sg-rev-stats" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:12px;font-size:.85rem;color:#6b7280;">
    <span>📦 Total loaded: <strong id="sg-rev-total">—</strong></span>
    <span>✅ Published: <strong id="sg-rev-count-published">—</strong></span>
    <span>📝 Pending/Draft: <strong id="sg-rev-count-pending">—</strong></span>
  </div>

  <!-- ── Bulk Actions ── -->
  <div class="sg-bulk-bar">
    <label><input type="checkbox" id="sg-rev-check-all" /> Select All</label>
    <span id="sg-rev-selected-count">0 selected</span>
    <button id="sg-bulk-generate-btn" class="sg-btn sg-btn--primary">⚡ Bulk Generate Selected</button>
  </div>

  <!-- ── Review Table ── -->
  <div class="sg-table-wrap">
    <table class="sg-table" id="sg-review-table">
      <thead>
        <tr>
          <th width="30"><input type="checkbox" id="sg-rev-select-all" /></th>
          <th width="60">Poster</th>
          <th>Title</th>
          <th>Year</th>
          <th>Genre</th>
          <th>TMDB ⭐</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="sg-review-tbody">
        <tr><td colspan="8" class="sg-loading-row">Loading...</td></tr>
      </tbody>
    </table>
  </div>
  <div id="sg-rev-pagination" class="sg-pagination"></div>

</div>

<!-- ── Generation Modal ── -->
<div id="sg-modal" style="display:none;position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center">
  <div class="sg-modal-backdrop" style="position:absolute;inset:0;background:rgba(0,0,0,.7)"></div>
  <div class="sg-modal-box" style="position:relative;background:#ffffff;border:1px solid #dcdcde;border-radius:12px;width:520px;max-width:90vw;padding:28px;z-index:1">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
      <h2 id="sg-modal-title" style="margin:0;font-size:1.1rem;color:#1e1e1e"></h2>
      <button id="sg-modal-close" style="background:none;border:none;color:#6b7280;font-size:1.4rem;cursor:pointer;line-height:1">×</button>
    </div>
    <div id="sg-modal-body"></div>
    <div id="sg-modal-footer" style="display:none;margin-top:20px;display:none;gap:10px;justify-content:flex-end">
      <a id="sg-view-post-btn" href="#" target="_blank" class="sg-btn sg-btn--primary">View Post</a>
      <a id="sg-edit-post-btn" href="#" target="_blank" class="sg-btn sg-btn--secondary">Edit Post</a>
      <button id="sg-modal-close-btn" class="sg-btn sg-btn--ghost">Close</button>
    </div>
  </div>
</div>
