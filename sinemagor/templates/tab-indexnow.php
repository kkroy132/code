<?php
defined('ABSPATH') || exit;

$key   = Sinemagor_IndexNow::get_key();
$stats = Sinemagor_IndexNow::get_stats();
$log   = Sinemagor_IndexNow::get_log();
?>

<div class="sg-section">

  <!-- ── Stats Header ── -->
  <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px">
    <?php foreach ([
      ['⚡', 'Total Posts',    $stats['total']],
      ['✅', 'Submitted',       $stats['submitted']],
      ['⏳', 'Not Submitted',  $stats['not_submitted']],
      ['🟢', 'Success',        $stats['success']],
      ['❌', 'Failed',          $stats['failed']],
    ] as [$icon, $label, $val]): ?>
    <div style="background:#1a1a28;border:1px solid #2e2e45;border-radius:8px;padding:12px 20px;min-width:110px;text-align:center">
      <div style="font-size:1.3rem"><?php echo $icon; ?></div>
      <div style="font-size:1.3rem;font-weight:700;color:#e8b84b"><?php echo (int) $val; ?></div>
      <div style="font-size:.75rem;color:#7a7a9a"><?php echo esc_html($label); ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if (!$key): ?>
  <div class="notice notice-warning" style="margin-bottom:20px">
    <p>⚠ IndexNow key not set. <a href="<?php echo esc_url(admin_url('admin.php?page=sinemagor-settings')); ?>">Go to Settings → IndexNow</a> to generate and save your key.</p>
  </div>
  <?php endif; ?>

  <!-- ── Actions ── -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px">
    <button id="sg-in-bulk-btn" class="sg-btn sg-btn--primary" <?php echo !$key ? 'disabled' : ''; ?>>⚡ Submit All URLs to IndexNow</button>
    <button id="sg-in-clear-log-btn" class="sg-btn sg-btn--ghost">🗑 Clear Log</button>
  </div>
  <div id="sg-in-notice" style="margin-bottom:16px"></div>

  <!-- ── Filter ── -->
  <div class="sg-filter-bar" style="margin-bottom:16px">
    <select id="sg-in-filter" class="sg-select">
      <option value="all">All Posts</option>
      <option value="submitted">Submitted</option>
      <option value="not_submitted">Not Submitted</option>
    </select>
    <button id="sg-in-load-btn" class="sg-btn sg-btn--secondary">Load</button>
  </div>

  <!-- ── Posts Table ── -->
  <div class="sg-table-wrap">
    <table class="sg-table" id="sg-in-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>URL</th>
          <th>Published</th>
          <th>Submitted At</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="sg-in-tbody">
        <tr><td colspan="6" class="sg-loading-row">Click "Load" to fetch posts.</td></tr>
      </tbody>
    </table>
  </div>
  <div id="sg-in-pagination" class="sg-pagination"></div>

  <!-- ── Log ── -->
  <?php if ($log): ?>
  <div style="margin-top:28px">
    <h3 style="margin:0 0 10px;color:#aaa;font-size:.9rem;text-transform:uppercase;letter-spacing:1px">📋 Submission Log (last 200)</h3>
    <div style="background:#0f0f17;border:1px solid #2e2e45;border-radius:8px;padding:14px;max-height:300px;overflow-y:auto;font-family:monospace;font-size:.8rem;line-height:1.7" id="sg-in-log-wrap">
      <?php foreach ($log as $entry): ?>
      <div style="display:flex;gap:12px;border-bottom:1px solid #1e1e30;padding:4px 0">
        <span style="color:#555;flex-shrink:0"><?php echo esc_html($entry['time']); ?></span>
        <span style="color:#9090b0;flex:1;word-break:break-all"><?php echo esc_html($entry['url']); ?></span>
        <span style="color:<?php echo in_array($entry['indexnow'], [200, 202]) ? '#4caf50' : '#e57373'; ?>;flex-shrink:0">IN:<?php echo esc_html($entry['indexnow']); ?></span>
        <span style="color:<?php echo in_array($entry['bing'],     [200, 202]) ? '#4caf50' : '#e57373'; ?>;flex-shrink:0">Bing:<?php echo esc_html($entry['bing']); ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
jQuery(function($){
    var currentPage = 1;

    function loadPosts(page, filter) {
        page   = page   || 1;
        filter = filter || $('#sg-in-filter').val() || 'all';
        currentPage = page;
        $('#sg-in-tbody').html('<tr><td colspan="6" class="sg-loading-row">Loading...</td></tr>');

        $.post(sinemagor.ajax_url, {
            action: 'sg_indexnow_posts',
            nonce:  sinemagor.nonce,
            page:   page,
            filter: filter,
        }, function(res){
            if (!res.success) { $('#sg-in-tbody').html('<tr><td colspan="6">Error loading posts.</td></tr>'); return; }
            var rows = res.data.rows;
            if (!rows.length) {
                $('#sg-in-tbody').html('<tr><td colspan="6" class="sg-empty">No posts found.</td></tr>');
            } else {
                var html = rows.map(function(r){
                    var sc = (r.status === 'success') ? '#4caf50' : (r.status === 'failed' ? '#e57373' : '#7a7a9a');
                    return '<tr data-pid="'+r.id+'">'
                        +'<td><a href="'+r.url+'" target="_blank" class="sg-link" style="max-width:220px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+escHtml(r.title)+'</a></td>'
                        +'<td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.8rem"><a href="'+r.url+'" target="_blank" rel="noopener" style="color:#7a7a9a">'+escHtml(r.url)+'</a></td>'
                        +'<td style="white-space:nowrap">'+escHtml(r.date)+'</td>'
                        +'<td style="white-space:nowrap;font-size:.82rem">'+escHtml(r.submitted_at || '—')+'</td>'
                        +'<td><span style="color:'+sc+'">'+escHtml(r.status.replace('_',' '))+'</span></td>'
                        +'<td><button class="sg-btn sg-btn--ghost sg-btn--sm sg-in-submit-btn" data-pid="'+r.id+'">Submit</button></td>'
                        +'</tr>';
                }).join('');
                $('#sg-in-tbody').html(html);
            }
            renderInPagination(page, res.data.pages);
        });
    }

    function renderInPagination(current, total) {
        if (total <= 1) { $('#sg-in-pagination').empty(); return; }
        var html = '';
        if (current > 1) html += '<button class="sg-page-btn" data-page="'+(current-1)+'">‹ Prev</button>';
        for (var i = Math.max(1,current-2); i <= Math.min(total,current+2); i++) {
            html += '<button class="sg-page-btn'+(i===current?' active':'')+'" data-page="'+i+'">'+i+'</button>';
        }
        if (current < total) html += '<button class="sg-page-btn" data-page="'+(current+1)+'">Next ›</button>';
        $('#sg-in-pagination').html(html).off('click').on('click','.sg-page-btn',function(){
            loadPosts(parseInt($(this).data('page')));
        });
    }

    function notice(msg, ok) {
        var cls = ok ? 'notice-success' : 'notice-error';
        $('#sg-in-notice').html('<div class="notice '+cls+'"><p>'+msg+'</p></div>');
        setTimeout(function(){ $('#sg-in-notice').empty(); }, 5000);
    }

    function escHtml(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    $('#sg-in-load-btn').on('click', function(){ loadPosts(1); });

    $('#sg-in-bulk-btn').on('click', function(){
        if (!confirm('Submit all published Sinemagor post URLs to IndexNow and Bing?')) return;
        var btn = $(this).text('Submitting...').prop('disabled', true);
        $.post(sinemagor.ajax_url, { action:'sg_indexnow_bulk', nonce:sinemagor.nonce }, function(res){
            btn.text('⚡ Submit All URLs to IndexNow').prop('disabled', false);
            if (res.success) {
                notice('✅ Submitted '+res.data.submitted+' URLs → IndexNow: '+res.data.indexnow+' | Bing: '+res.data.bing, true);
                loadPosts(currentPage);
            } else {
                notice('❌ '+(res.data||'Error'), false);
            }
        }).fail(function(){ btn.text('⚡ Submit All URLs to IndexNow').prop('disabled', false); notice('❌ Request failed.', false); });
    });

    $(document).on('click', '.sg-in-submit-btn', function(){
        var btn = $(this).text('...').prop('disabled', true);
        var pid = $(this).data('pid');
        $.post(sinemagor.ajax_url, { action:'sg_indexnow_submit', nonce:sinemagor.nonce, post_id:pid }, function(res){
            btn.text('Submit').prop('disabled', false);
            if (res.success) {
                notice('✅ Submitted → IndexNow: '+res.data.indexnow+' | Bing: '+res.data.bing, true);
                loadPosts(currentPage);
            } else {
                notice('❌ '+(res.data||'Error'), false);
            }
        }).fail(function(){ btn.text('Submit').prop('disabled', false); });
    });

    $('#sg-in-clear-log-btn').on('click', function(){
        if (!confirm('Clear the submission log?')) return;
        $.post(sinemagor.ajax_url, { action:'sg_indexnow_clear_log', nonce:sinemagor.nonce }, function(res){
            if (res.success) { $('#sg-in-log-wrap').parent().slideUp(); notice('Log cleared.', true); }
        });
    });
});
</script>
