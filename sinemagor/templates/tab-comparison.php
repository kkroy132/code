<?php defined('ABSPATH') || exit; ?>

<div class="sg-section">

  <h3 class="sg-section-title">⚔️ Create a Comparison Post</h3>
  <p style="color:var(--sg-muted);margin-bottom:20px;font-size:.9rem">
    Compare two movies head-to-head. Generates an SEO-optimized "X vs Y" article automatically.
  </p>

  <!-- Movie Selector -->
  <div class="sg-vs-selector">

    <!-- Movie A -->
    <div class="sg-vs-pick" id="sg-pick-a">
      <div class="sg-vs-pick-label">Movie A</div>
      <input type="text" id="sg-vs-search-a" class="sg-input" placeholder="Search movie..." autocomplete="off" />
      <div class="sg-vs-dropdown" id="sg-vs-drop-a" style="display:none"></div>
      <div class="sg-vs-selected" id="sg-vs-sel-a" style="display:none">
        <img id="sg-vs-img-a" src="" alt="" loading="lazy" />
        <div>
          <div id="sg-vs-title-a" class="sg-vs-sel-title"></div>
          <div id="sg-vs-meta-a"  class="sg-vs-sel-meta"></div>
        </div>
        <button class="sg-vs-clear" data-side="a">✕</button>
      </div>
      <input type="hidden" id="sg-vs-id-a" value="" />
    </div>

    <div class="sg-vs-vs-badge">VS</div>

    <!-- Movie B -->
    <div class="sg-vs-pick" id="sg-pick-b">
      <div class="sg-vs-pick-label">Movie B</div>
      <input type="text" id="sg-vs-search-b" class="sg-input" placeholder="Search movie..." autocomplete="off" />
      <div class="sg-vs-dropdown" id="sg-vs-drop-b" style="display:none"></div>
      <div class="sg-vs-selected" id="sg-vs-sel-b" style="display:none">
        <img id="sg-vs-img-b" src="" alt="" loading="lazy" />
        <div>
          <div id="sg-vs-title-b" class="sg-vs-sel-title"></div>
          <div id="sg-vs-meta-b"  class="sg-vs-sel-meta"></div>
        </div>
        <button class="sg-vs-clear" data-side="b">✕</button>
      </div>
      <input type="hidden" id="sg-vs-id-b" value="" />
    </div>

  </div><!-- .sg-vs-selector -->

  <!-- Info note -->
  <div id="sg-vs-info" style="color:var(--sg-muted);font-size:.85rem;margin:12px 0;display:none">
    ✅ Both movies selected. Click Generate to create the comparison post.
  </div>

  <button id="sg-vs-generate-btn" class="sg-btn sg-btn--primary" style="display:none;margin-top:12px">
    ⚔️ Generate Comparison Post
  </button>

  <!-- Published comparisons table -->
  <div class="sg-library-section" style="margin-top:32px">
    <h3 class="sg-section-title">📋 Published Comparisons</h3>
    <div class="sg-table-wrap">
      <table class="sg-table">
        <thead>
          <tr><th>Title</th><th>Status</th><th>Date</th><th>Action</th></tr>
        </thead>
        <tbody id="sg-vs-posts-tbody">
          <tr><td colspan="4" class="sg-loading-row">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Modal -->
<div id="sg-vs-modal" class="sg-modal" style="display:none">
  <div class="sg-modal-backdrop"></div>
  <div class="sg-modal-box">
    <div class="sg-modal-header">
      <h3 id="sg-vs-modal-title">Generating Comparison...</h3>
      <button class="sg-modal-close" id="sg-vs-modal-close">✕</button>
    </div>
    <div class="sg-modal-body" id="sg-vs-modal-body">
      <div class="sg-spinner-wrap">
        <div class="sg-spinner"></div>
        <p>AI is writing the comparison...<br><small style="color:#7a7a9a">30–60 seconds</small></p>
      </div>
    </div>
    <div class="sg-modal-footer" id="sg-vs-modal-footer" style="display:none">
      <a id="sg-vs-view-btn" href="#" target="_blank" class="sg-btn sg-btn--primary">👁 View Post</a>
      <a id="sg-vs-edit-btn" href="#" target="_blank" class="sg-btn sg-btn--secondary">✏️ Edit Post</a>
      <button id="sg-vs-close-btn" class="sg-btn sg-btn--ghost">Close</button>
    </div>
  </div>
</div>

<script>
(function($){
  var movies = [], timerA, timerB;

  // Load all published movies once
  $.post(sinemagor.ajax_url, {action:'sg_comparison_movies', nonce:sinemagor.nonce, search:''}, function(res){
    if(res.success) movies = res.data;
    loadVsPosts();
  });

  function search(query, side) {
    var filtered = movies.filter(function(m){
      return m.title.toLowerCase().indexOf(query.toLowerCase()) !== -1;
    }).slice(0, 8);
    renderDropdown(filtered, side);
  }

  function renderDropdown(items, side) {
    var drop = $('#sg-vs-drop-' + side);
    if(!items.length){ drop.hide(); return; }
    var html = items.map(function(m){
      return '<div class="sg-vs-drop-item" data-id="'+m.id+'" data-side="'+side+'">'
        + (m.poster_thumb ? '<img src="'+m.poster_thumb+'" loading="lazy"/>' : '<span class="sg-vs-no-thumb">🎬</span>')
        + '<div><div class="sg-vs-drop-title">'+esc(m.title)+'</div>'
        + '<div class="sg-vs-drop-meta">'+(m.year||'')+'&nbsp;·&nbsp;⭐'+(m.tmdb_rating||'—')+'</div>'
        + '</div></div>';
    }).join('');
    drop.html(html).show();
  }

  function selectMovie(id, side) {
    var m = movies.find(function(x){ return x.id == id; });
    if(!m) return;
    $('#sg-vs-id-' + side).val(id);
    $('#sg-vs-img-'   + side).attr('src', m.poster_thumb || '');
    $('#sg-vs-title-' + side).text(m.title);
    $('#sg-vs-meta-'  + side).text((m.year||'') + ' · ⭐' + (m.tmdb_rating||'—') + ' · ' + (m.genre||'').split(',')[0]);
    $('#sg-vs-sel-'   + side).show();
    $('#sg-vs-search-'+ side).hide();
    $('#sg-vs-drop-'  + side).hide();
    checkBothSelected();
  }

  function checkBothSelected() {
    var a = $('#sg-vs-id-a').val(), b = $('#sg-vs-id-b').val();
    if(a && b && a !== b) {
      $('#sg-vs-info').show();
      $('#sg-vs-generate-btn').show();
    } else {
      $('#sg-vs-info').hide();
      $('#sg-vs-generate-btn').hide();
    }
  }

  // Search inputs
  $('#sg-vs-search-a').on('input', function(){ clearTimeout(timerA); var q=this.value; timerA=setTimeout(function(){ if(q.length>1) search(q,'a'); else $('#sg-vs-drop-a').hide(); },250); });
  $('#sg-vs-search-b').on('input', function(){ clearTimeout(timerB); var q=this.value; timerB=setTimeout(function(){ if(q.length>1) search(q,'b'); else $('#sg-vs-drop-b').hide(); },250); });

  // Select from dropdown
  $(document).on('click', '.sg-vs-drop-item', function(){
    selectMovie($(this).data('id'), $(this).data('side'));
  });

  // Clear selection
  $(document).on('click', '.sg-vs-clear', function(){
    var side = $(this).data('side');
    $('#sg-vs-id-'    + side).val('');
    $('#sg-vs-sel-'   + side).hide();
    $('#sg-vs-search-'+ side).show().val('').focus();
    checkBothSelected();
  });

  // Close dropdown on outside click
  $(document).on('click', function(e){
    if(!$(e.target).closest('.sg-vs-pick').length) {
      $('.sg-vs-dropdown').hide();
    }
  });

  // Generate
  $('#sg-vs-generate-btn').on('click', function(){
    var a = $('#sg-vs-id-a').val(), b = $('#sg-vs-id-b').val();
    if(!a || !b){ alert('Select two different movies.'); return; }
    openVsModal('Generating Comparison Post...');
    $.post(sinemagor.ajax_url, {
      action: 'sg_comparison_generate',
      nonce:  sinemagor.nonce,
      movie_a: a,
      movie_b: b,
    }, function(res){
      if(res.success){
        var d = res.data;
        $('#sg-vs-modal-title').text('✅ ' + (d.status==='publish'?'Published':'Draft saved') + '!');
        $('#sg-vs-modal-body').html('<div class="sg-modal-success"><div class="sg-success-icon">⚔️</div><p><strong>'+esc(d.title)+'</strong></p></div>');
        $('#sg-vs-view-btn').attr('href', d.post_url);
        $('#sg-vs-edit-btn').attr('href', d.edit_url);
        $('#sg-vs-modal-footer').show();
        loadVsPosts();
      } else {
        $('#sg-vs-modal-title').text('❌ Error');
        $('#sg-vs-modal-body').html('<div class="sg-modal-error">'+esc(res.data)+'</div>');
        $('#sg-vs-modal-footer').show();
      }
    });
  });

  // Modal close
  $('#sg-vs-modal-close, #sg-vs-close-btn, #sg-vs-modal .sg-modal-backdrop').on('click', function(){ $('#sg-vs-modal').hide(); });

  function openVsModal(title){
    $('#sg-vs-modal-title').text(title);
    $('#sg-vs-modal-body').html('<div class="sg-spinner-wrap"><div class="sg-spinner"></div><p>AI is writing the comparison...<br><small style="color:#7a7a9a">30–60 seconds</small></p></div>');
    $('#sg-vs-modal-footer').hide();
    $('#sg-vs-modal').show();
  }

  function loadVsPosts(){
    var posts = <?php
      $vp = get_posts(['post_type'=>'post','post_status'=>'any','posts_per_page'=>30,'meta_query'=>[['key'=>'_sinemagor_comparison','value'=>1]]]);
      echo wp_json_encode(array_map(fn($p)=>['title'=>$p->post_title,'status'=>$p->post_status,'date'=>get_the_date('Y-m-d',$p),'edit_url'=>get_edit_post_link($p->ID,'raw'),'view_url'=>get_permalink($p->ID)], $vp));
    ?>;
    if(!posts.length){ $('#sg-vs-posts-tbody').html('<tr><td colspan="4" class="sg-empty">No comparison posts yet.</td></tr>'); return; }
    var html = posts.map(function(p){
      return '<tr><td class="sg-td-title">'+esc(p.title)+'</td>'
        +'<td><span class="sg-badge sg-badge--'+(p.status==='publish'?'green':'gray')+'">'+p.status+'</span></td>'
        +'<td style="color:var(--sg-muted);font-size:.85rem">'+p.date+'</td>'
        +'<td><a href="'+esc(p.edit_url)+'" class="sg-link" target="_blank">Edit</a>'
        +(p.view_url?' · <a href="'+esc(p.view_url)+'" class="sg-link" target="_blank">View</a>':'')
        +'</td></tr>';
    }).join('');
    $('#sg-vs-posts-tbody').html(html);
  }

  function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

})(jQuery);
</script>
