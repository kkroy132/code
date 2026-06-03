<?php defined('ABSPATH') || exit; ?>

<div class="sg-section">

  <!-- ── Filter Bar ── -->
  <div class="sg-filter-bar">
    <input type="text" id="sg-search" placeholder="Search movie title..." class="sg-input" />
    <select id="sg-genre-filter" class="sg-select">
      <option value="">All Genres</option>
      <option value="28">Action</option><option value="12">Adventure</option>
      <option value="16">Animation</option><option value="35">Comedy</option>
      <option value="80">Crime</option><option value="18">Drama</option>
      <option value="14">Fantasy</option><option value="27">Horror</option>
      <option value="9648">Mystery</option><option value="10749">Romance</option>
      <option value="878">Sci-Fi</option><option value="53">Thriller</option>
    </select>
    <select id="sg-year-filter" class="sg-select">
      <option value="">All Years</option>
      <?php for ($y = date('Y'); $y >= 1990; $y--): ?>
        <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
      <?php endfor; ?>
    </select>
    <select id="sg-lang-filter" class="sg-select">
      <option value="">All Languages</option>
      <option value="en">English</option><option value="ko">Korean</option>
      <option value="ja">Japanese</option><option value="fr">French</option>
      <option value="hi">Hindi</option><option value="es">Spanish</option>
    </select>
    <select id="sg-sort-filter" class="sg-select">
      <option value="popularity.desc">Most Popular</option>
      <option value="top_rated">🏆 TMDB Top Rated</option>
      <option value="vote_average.desc">⭐ Highest Rated (300+ votes)</option>
      <option value="release_date.desc">Newest</option>
      <option value="revenue.desc">Top Grossing</option>
    </select>
    <select id="sg-count-filter" class="sg-select">
      <option value="20">20 movies</option>
      <option value="40">40 movies</option>
      <option value="60">60 movies</option>
      <option value="100" selected>100 movies</option>
      <option value="150">150 movies</option>
      <option value="200">200 movies</option>
    </select>
    <button id="sg-discover-btn" class="sg-btn sg-btn--primary">🔍 Discover</button>
  </div>

  <!-- ── Bulk Actions ── -->
  <div class="sg-bulk-bar" style="display:none;" id="sg-lib-bulk-bar">
    <label><input type="checkbox" id="sg-select-all-discover" /> Select All</label>
    <span id="sg-selected-count">0 selected</span>
    <button id="sg-add-selected-btn" class="sg-btn sg-btn--success">+ Add to Library</button>
    <button id="sg-clear-selection-btn" class="sg-btn sg-btn--ghost">Clear</button>
  </div>

  <!-- ── Add progress bar (shown while adding) ── -->
  <div id="sg-add-progress-bar" style="display:none;background:#1a1a28;border:1px solid #2e2e45;border-radius:10px;padding:14px 18px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
      <strong style="color:#e0e0f0">➕ Adding to Library...</strong>
      <span id="sg-add-progress-text" style="color:#aaa;font-size:.85rem">0 / 0</span>
    </div>
    <div style="height:6px;background:#2e2e45;border-radius:3px;">
      <div id="sg-add-progress-fill" style="height:100%;background:#4caf50;border-radius:3px;width:0%;transition:width .3s;"></div>
    </div>
    <div id="sg-add-progress-detail" style="margin-top:6px;font-size:.8rem;color:#aaa;"></div>
  </div>

  <!-- ── TMDB Results Grid ── -->
  <div id="sg-discover-results" class="sg-movie-grid"></div>
  <div id="sg-discover-pagination" class="sg-pagination"></div>

  <!-- ── Library Table ── -->
  <div class="sg-library-section">
    <h3 class="sg-section-title" style="cursor:pointer;user-select:none;" id="sg-lib-toggle">
      📚 Your Library <span id="sg-lib-count-badge" style="font-size:.8rem;color:#aaa;font-weight:normal;margin-left:8px;"></span>
      <span id="sg-lib-chevron" style="font-size:.85rem;color:#666;margin-left:6px;">▼</span>
    </h3>
    <div id="sg-lib-body">

    <div class="sg-filter-bar">
      <input type="text" id="sg-lib-search" placeholder="Search library..." class="sg-input" />
      <select id="sg-lib-status" class="sg-select">
        <option value="">All Status</option>
        <option value="pending">Pending</option>
        <option value="draft">Draft</option>
        <option value="published">Published</option>
      </select>
      <button id="sg-lib-filter-btn" class="sg-btn sg-btn--secondary">Filter</button>
    </div>

    <div class="sg-bulk-bar" id="sg-lib-bulk-bar2">
      <label><input type="checkbox" id="sg-lib-select-all" /> Select All</label>
      <span id="sg-lib-selected-count">0 selected</span>
      <button id="sg-lib-delete-btn" class="sg-btn sg-btn--danger">🗑 Delete Selected</button>
    </div>

    <div class="sg-table-wrap">
      <table class="sg-table" id="sg-library-table">
        <thead>
          <tr>
            <th width="30"><input type="checkbox" id="sg-lib-check-all" /></th>
            <th width="60">Poster</th>
            <th>Title</th>
            <th>Year</th>
            <th>Genre</th>
            <th>TMDB ⭐</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="sg-library-tbody">
          <tr><td colspan="8" class="sg-loading-row">Loading library...</td></tr>
        </tbody>
      </table>
    </div>
    <div id="sg-lib-pagination" class="sg-pagination"></div>
    </div><!-- /#sg-lib-body -->
  </div>

</div>
