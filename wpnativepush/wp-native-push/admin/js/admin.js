/* global jQuery, wnpAdmin, wnpDK, wnpTS, wp, ajaxurl */

// ══════════════════════════════════════════════
//  TAB AJAX — No-reload tab navigation
// ══════════════════════════════════════════════
(function ($) {
    'use strict';

    var api        = window.wnpAdmin || {};
    var $tabBody   = null;   // .wnp-tab-body element
    var activeTab  = 'dashboard';
    var loading    = false;

    // ── Core loader ──────────────────────────────────────────────────────────

    /**
     * Load a tab's content via AJAX.
     *
     * @param {string} tab       Tab slug  (dashboard | compose | subscribers | settings)
     * @param {Object} params    Extra GET params to pass (filters, paged, search, etc.)
     * @param {string} pushUrl   Full URL to push to history (or null to skip pushState)
     */
    function loadTab(tab, params, pushUrl) {
        if (loading) return;
        loading = true;

        $tabBody = $tabBody || $('.wnp-tab-body');

        // Visual loading state — dim content, show spinner in active tab
        $tabBody.css({ opacity: 0.45, transition: 'opacity .15s' });
        $('.nav-tab').removeClass('nav-tab-active');
        $('.nav-tab[href*="tab=' + tab + '"]').addClass('nav-tab-active');

        // Build POST data — merge tab + filter params + nonce
        var data = $.extend({ action: 'wnp_load_tab', nonce: api.ajaxNonce, tab: tab }, params || {});

        $.post(api.ajaxUrl, data, function (res) {
            if (res && res.success) {
                $tabBody.html(res.data.html);
                $tabBody.css({ opacity: 1 });
                activeTab = tab;

                // Re-init compose page after AJAX load
                if (tab === 'compose' && typeof Compose !== 'undefined') {
                    setTimeout(function () { Compose.init(); }, 50);
                }
            } else {
                $tabBody.css({ opacity: 1 });
            }
        }).fail(function () {
            // Network error — fall back to full page load
            if (pushUrl) window.location.href = pushUrl;
            else $tabBody.css({ opacity: 1 });
        }).always(function () {
            loading = false;
        });

        // Update browser URL (no reload)
        if (pushUrl && window.history && window.history.pushState) {
            window.history.pushState({ tab: tab, params: params }, '', pushUrl);
        }
    }

    // ── Parse query string from a URL ────────────────────────────────────────

    function parseParams(url) {
        var out = {};
        var qs  = (url.split('?')[1] || '').split('&');
        qs.forEach(function (pair) {
            var p = pair.split('=');
            if (p[0]) out[decodeURIComponent(p[0])] = decodeURIComponent(p[1] || '');
        });
        return out;
    }

    // ── Tab link clicks ───────────────────────────────────────────────────────

    $(document).on('click', '.nav-tab', function (e) {
        var href = $(this).attr('href') || '';
        // Only intercept internal plugin links
        if (!href.includes('page=wp-native-push')) return;
        e.preventDefault();

        var params = parseParams(href);
        var tab    = params.tab || 'dashboard';
        // Strip page/tab — the rest are filter params
        var filters = $.extend({}, params);
        delete filters.page;
        delete filters.tab;

        loadTab(tab, filters, href);
    });

    // ── Internal plugin link clicks (filters, pagination) ────────────────────
    // Intercepts ANY <a> on the page that goes to page=wp-native-push

    $(document).on('click', 'a[href*="page=wp-native-push"]', function (e) {
        var href = $(this).attr('href') || '';

        // Let external actions pass through (admin-post.php for settings/CSV)
        if (href.includes('admin-post.php')) return;
        if (href.includes('wnp_clear_log'))  return;

        e.preventDefault();

        var params  = parseParams(href);
        var tab     = params.tab || activeTab || 'dashboard';
        var filters = $.extend({}, params);
        delete filters.page;
        delete filters.tab;

        loadTab(tab, filters, href);
    });

    // ── Subscriber search form ────────────────────────────────────────────────

    $(document).on('submit', '.wnp-sub-search-form, form[method="get"]', function (e) {
        var $form = $(this);
        // Only intercept search forms inside the plugin page
        if (!$form.find('input[name="page"][value="wp-native-push"]').length) return;
        // Skip forms with POST actions (settings, CSV, etc.)
        if ($form.attr('method') === 'post') return;

        e.preventDefault();

        var formData = {};
        $form.serializeArray().forEach(function (f) { formData[f.name] = f.value; });
        var tab = formData.tab || activeTab || 'subscribers';

        // Build the URL for pushState
        var url = $form.attr('action') || window.location.href;
        var qs  = $.param(formData);
        var pushUrl = url.split('?')[0] + '?' + qs;

        var filters = $.extend({}, formData);
        delete filters.page;
        delete filters.tab;

        loadTab(tab, filters, pushUrl);
    });

    // ── Browser back / forward ────────────────────────────────────────────────

    window.addEventListener('popstate', function (event) {
        if (event.state && event.state.tab) {
            loadTab(event.state.tab, event.state.params || {}, null);
        }
    });

    // ── Init: set current tab from URL ───────────────────────────────────────

    $(function () {
        $tabBody = $('.wnp-tab-body');
        var params = parseParams(window.location.href);
        activeTab  = params.tab || 'dashboard';

        // Push initial state so popstate works correctly
        if (window.history && window.history.replaceState) {
            window.history.replaceState({ tab: activeTab, params: params }, '', window.location.href);
        }
    });

}(jQuery));

/* global jQuery, wnpAdmin, wnpDK, wnpTS, wp */
(function($){
'use strict';
var api = window.wnpAdmin || {};

// ══════════════════════════════════════════════
//  COMPOSE PAGE
// ══════════════════════════════════════════════
if($('.wnp-cl').length){

// ── Card collapse / expand ───────────────────
$(document).on('click','.wnp-c__h', function(){
    var id = $(this).data('c');
    var $b = $('#wnp-c-'+id);
    $(this).toggleClass('wnp-c__h--open');
    $b.slideToggle(200);
});
// Open content card by default
$('[data-c="content"]').addClass('wnp-c__h--open');
$('#wnp-c-content').show();

// ── Character counters ───────────────────────
function cnt(id,max){
    var v=$('#'+id).val().length;
    var $c=$('#'+id+'-cnt');
    $c.text(v+' / '+max).removeClass('wnp-cnt--w wnp-cnt--d');
    if(v>=max) $c.addClass('wnp-cnt--d');
    else if(v>=max*.8) $c.addClass('wnp-cnt--w');
}
$('#wnp-title').on('input',function(){ cnt('wnp-title',60); updPrev(); });
$('#wnp-body').on('input', function(){ cnt('wnp-body',120); updPrev(); });
$('#wnp-url').on('input',  function(){ updUTM(); });

// ── Media pickers ────────────────────────────
$(document).on('click','.wnp-mc', function(){
    if(typeof wp==='undefined'||!wp.media) return;
    var f=$(this).data('f');
    var frame=wp.media({title:'Select Image',button:{text:'Use this image'},multiple:false,library:{type:'image'}});
    frame.on('select',function(){
        var att=frame.state().get('selection').first().toJSON();
        $('#wnp-'+f).val(att.url);
        $('#wnp-thumb-'+f).html('<img src="'+att.url+'" alt="" />');
        $('[data-f="'+f+'"].wnp-mr').show();
        updPrev();
    });
    frame.open();
});
$(document).on('click','.wnp-mr', function(){
    var f=$(this).data('f');
    $('#wnp-'+f).val('');
    $('#wnp-thumb-'+f).html('<span>&#x1F5BC;</span>');
    $(this).hide();
    updPrev();
});

// ── Emoji ────────────────────────────────────
var emojiFor=null;
$(document).on('click','.wnp-emj',function(e){
    e.stopPropagation();
    emojiFor=$(this).data('for');
    var off=$(this).offset();
    $('#wnp-ep').css({top: off.top+28, left: Math.min(off.left, $(window).width()-260)}).toggleClass('open');
});
$(document).on('click','.wnp-ep__b',function(){
    var em=$(this).data('e');
    var $t=$('#'+emojiFor);
    if($t.length){
        var pos=$t[0].selectionStart||$t.val().length;
        var v=$t.val();
        $t.val(v.slice(0,pos)+em+v.slice(pos)).trigger('input');
    }
    $('#wnp-ep').removeClass('open');
});
$(document).on('click',function(e){
    if(!$(e.target).closest('#wnp-ep,.wnp-emj').length) $('#wnp-ep').removeClass('open');
});

// ── Audience ─────────────────────────────────
$(document).on('click','.wnp-ao',function(){
    $('.wnp-ao').removeClass('wnp-ao--on');
    $(this).addClass('wnp-ao--on').find('input').prop('checked',true);
    var aud=$(this).find('input').val();
    $('#wnp-cat-wrap').toggle(aud==='category');
    $('#wnp-tag-wrap').toggle(aud==='tag');
    // Estimate reach
    var reach=wnpTS;
    var cntEl=$(this).find('.wnp-ao__c').text();
    if(aud!=='all'&&cntEl) reach=parseInt(cntEl,10)||0;
    var pct=wnpTS>0?Math.round(reach/wnpTS*100):0;
    $('#wnp-rb-fill').css('width',pct+'%');
    $('#wnp-rb-num').text(reach);
    $('#pv-reach').text(reach);
    $('#wnp-reach-pill').text(reach+' subscribers');
});

// ── Delivery ─────────────────────────────────
$(document).on('click','.wnp-rc',function(){
    var $opts=$(this).closest('.wnp-del-opts, .wnp-test-opts');
    $opts.find('.wnp-rc').removeClass('wnp-rc--on');
    $(this).addClass('wnp-rc--on').find('input').prop('checked',true);
    if($(this).closest('.wnp-del-opts').length){
        var v=$(this).find('input').val();
        $('#wnp-sched').slideToggle(180,function(){});
        if(v!=='scheduled') $('#wnp-sched').hide();
    }
});

// Fix: handle delivery toggle properly
$(document).on('click','.wnp-del-opts .wnp-rc',function(){
    var v=$(this).find('input').val();
    if(v==='scheduled') $('#wnp-sched').slideDown(180);
    else $('#wnp-sched').slideUp(180);
});

// ── Pills ─────────────────────────────────────
$(document).on('click','.wnp-pill',function(){
    $(this).closest('.wnp-pills').find('.wnp-pill').removeClass('wnp-pill--on');
    $(this).addClass('wnp-pill--on').find('input').prop('checked',true);
});

// ── Post search ──────────────────────────────
var psTimer;
$('#wnp-ps').on('input',function(){
    clearTimeout(psTimer);
    var q=$(this).val().trim();
    if(q.length<2){$('#wnp-ps-results').hide();return;}
    psTimer=setTimeout(function(){searchPosts(q);},350);
});
function searchPosts(q){
    $('#wnp-ps-spin').show();
    $.getJSON(window.location.origin+'/wp-json/wp/v2/posts',{search:q,per_page:8,_embed:1},function(posts){
        var html='';
        posts.forEach(function(p){
            var img='';
            try{img=p._embedded['wp:featuredmedia'][0].source_url;}catch(e){}
            var exc=p.excerpt.rendered.replace(/<[^>]+>/g,'').substring(0,80)+'…';
            html+='<div class="wnp-pri" data-url="'+p.link+'" data-exc="'+exc.replace(/"/g,'&quot;')+'">'+
                (img?'<img src="'+img+'" alt="" />':'<img src="" alt="" style="background:#f0f0f1" />')+
                '<div><div class="wnp-pri__t">'+p.title.rendered+'</div><div class="wnp-pri__s">Post</div></div></div>';
        });
        if(!html) html='<div style="padding:12px;color:#646970;font-size:13px">No results found.</div>';
        $('#wnp-ps-results').html(html).show();
        $('#wnp-ps-spin').hide();
    }).fail(function(){$('#wnp-ps-spin').hide();});
}
$(document).on('click','.wnp-pri',function(){
    var title=$(this).find('.wnp-pri__t').text();
    var url=$(this).data('url');
    var img=$(this).find('img').attr('src')||'';
    var exc=$(this).data('exc')||'';
    $('#wnp-title').val(title).trigger('input');
    $('#wnp-url').val(url).trigger('input');
    if(img){$('#wnp-icon').val(img);$('#wnp-thumb-icon').html('<img src="'+img+'" alt="" />');}
    $('#wnp-pss-img').attr('src',img);
    $('#wnp-pss-title').text(title);
    $('#wnp-pss-exc').text(exc);
    $('#wnp-ps-results').hide();
    $('#wnp-ps-selected').show();
    $('#wnp-ps').val('');
    updPrev();
});
$('#wnp-pss-clear').on('click',function(){$('#wnp-ps-selected').hide();$('#wnp-ps').val('').focus();});
$(document).on('click',function(e){if(!$(e.target).closest('#wnp-c-post').length)$('#wnp-ps-results').hide();});

// ── Action buttons → preview ─────────────────
$('#wnp-a1l,#wnp-a1u,#wnp-a2l,#wnp-a2u').on('input', updPrev);

// ── UTM ──────────────────────────────────────
$('#wnp-utm-source,#wnp-utm-medium,#wnp-utm-campaign,#wnp-utm-content').on('input',updUTM);
updUTM();
function updUTM(){
    var url=$('#wnp-url').val().trim()||window.location.origin;
    var src=$('#wnp-utm-source').val().trim();
    var med=$('#wnp-utm-medium').val().trim();
    var cam=$('#wnp-utm-campaign').val().trim();
    var con=$('#wnp-utm-content').val().trim();
    var p=[];
    if(src) p.push('utm_source='+encodeURIComponent(src));
    if(med) p.push('utm_medium='+encodeURIComponent(med));
    if(cam) p.push('utm_campaign='+encodeURIComponent(cam));
    if(con) p.push('utm_content='+encodeURIComponent(con));
    var $el=$('#wnp-utm-prev');
    if(!p.length){$el.removeClass('on').text('');return;}
    $el.addClass('on').text(url+(url.includes('?')?'&':'?')+p.join('&'));
}
function buildURL(){
    var url=$('#wnp-url').val().trim()||window.location.origin;
    var src=$('#wnp-utm-source').val().trim();
    var med=$('#wnp-utm-medium').val().trim();
    var cam=$('#wnp-utm-campaign').val().trim();
    var con=$('#wnp-utm-content').val().trim();
    var p=[];
    if(src) p.push('utm_source='+encodeURIComponent(src));
    if(med) p.push('utm_medium='+encodeURIComponent(med));
    if(cam) p.push('utm_campaign='+encodeURIComponent(cam));
    if(con) p.push('utm_content='+encodeURIComponent(con));
    return p.length? url+(url.includes('?')?'&':'?')+p.join('&') : url;
}

// ── Preview tabs ─────────────────────────────
$(document).on('click','.wnp-pvt',function(){
    $('.wnp-pvt').removeClass('wnp-pvt--on');
    $(this).addClass('wnp-pvt--on');
    $('.wnp-pv__view').removeClass('wnp-pv__view--on');
    $('#wnp-pv-'+$(this).data('pv')).addClass('wnp-pv__view--on');
});

// ── Live preview update ──────────────────────
function updPrev(){
    var title=$('#wnp-title').val().trim()||'Notification Title';
    var msg=$('#wnp-body').val().trim()||'Your message appears here\u2026';
    var icon=$('#wnp-icon').val().trim();
    var img=$('#wnp-image').val().trim();
    var b1=$('#wnp-a1l').val().trim();
    var b2=$('#wnp-a2l').val().trim();
    var fallback=(api.pluginUrl||'')+'/admin/img/bell-placeholder.svg';
    var src=icon||fallback;

    // Update all preview icons
    $('#pvci,#pvai,#pvei').attr('src',src);
    // Titles & messages
    $('#pvct,#pvat,#pvet').text(title);
    $('#pvcg,#pvag,#pveg').text(msg);
    // Hero image
    if(img){$('#pvci2').attr('src',img).show();$('#pvai2').attr('src',img).show();}
    else   {$('#pvci2,#pvai2').hide();}
    // Action buttons
    var hasBtn=b1||b2;
    if(hasBtn){
        $('#pvca').show(); $('#pvca1').text(b1).toggle(!!b1); $('#pvca2').text(b2).toggle(!!b2);
        $('#pvaa').show(); $('#pvaa1').text(b1).toggle(!!b1); $('#pvaa2').text(b2).toggle(!!b2);
    } else {
        $('#pvca,#pvaa').hide();
    }
}
updPrev();

// ── Test notification ────────────────────────
function sendTest(){
    var title=$('#wnp-title').val().trim();
    var body=$('#wnp-body').val().trim();
    if(!title||!body){showMsg('Please fill Title and Message first.','err');return;}
    var limit=$('input[name="test_target"]:checked').val()==='all'?3:1;
    var $b=$('#wnp-test-btn');
    $b.prop('disabled',true).text('Sending\u2026');
    fetch(api.restUrl+'/test',{
        method:'POST',
        headers:{'Content-Type':'application/json','X-WP-Nonce':api.nonce},
        body:JSON.stringify({title:title,body:body,icon:$('#wnp-icon').val(),url:buildURL(),limit:limit})
    }).then(function(r){return r.json();}).then(function(d){
        var $n=$('#wnp-test-msg');
        $n.removeClass('wnp-imok wnp-imerr').addClass(d.success?'wnp-imok':'wnp-imerr')
          .text(d.success?'Test sent to '+d.sent+' device(s)!':'Error: '+(d.error||'Failed')).show();
        setTimeout(function(){$n.fadeOut();},5000);
    }).finally(function(){$b.prop('disabled',false).text('\u{1F9EA} Send Test Push');});
}
$('#wnp-test-btn,#wnp-btn-test-f').on('click',sendTest);

// ── Footer ───────────────────────────────────
function fbMsg(txt,cls){
    $('#wnp-fb-msg').attr('class',cls||'').text(txt);
    if(cls==='ok'||cls==='err') setTimeout(function(){$('#wnp-fb-msg').text('').attr('class','');},5000);
}

$('#wnp-btn-draft').on('click',function(){
    try{localStorage.setItem(wnpDK,JSON.stringify({title:$('#wnp-title').val(),body:$('#wnp-body').val(),url:$('#wnp-url').val(),icon:$('#wnp-icon').val(),ts:Date.now()}));}catch(e){}
    fbMsg('\uD83D\uDCBE Draft saved!','ok');
});

$('#wnp-btn-sched').on('click',function(){
    $('[data-c="delivery"]').addClass('wnp-c__h--open');
    $('#wnp-c-delivery').slideDown(200);
    $('.wnp-del-opts .wnp-rc').last().trigger('click');
    $('html,body').animate({scrollTop:$('[data-c="delivery"]').offset().top-60},400);
});

$('#wnp-btn-send').on('click',sendNow);

async function sendNow(){
    var title=$('#wnp-title').val().trim();
    var body=$('#wnp-body').val().trim();
    if(!title){fbMsg('Title is required.','err');return;}
    if(!body) {fbMsg('Message is required.','err');return;}
    var $b=$('#wnp-btn-send');
    $b.prop('disabled',true);
    $('#wnp-spin-ico').text('\u23F3');
    fbMsg('Queuing\u2026','');
    var payload={
        title:title, body:body,
        icon:$('#wnp-icon').val().trim(), badge:$('#wnp-badge').val().trim(),
        image:$('#wnp-image').val().trim(), url:buildURL(),
        audience:$('input[name="audience"]:checked').val()||'all',
        delivery:$('input[name="delivery"]:checked').val()||'now',
        action1_label:$('#wnp-a1l').val().trim(), action1_url:$('#wnp-a1u').val().trim(),
        action2_label:$('#wnp-a2l').val().trim(), action2_url:$('#wnp-a2u').val().trim(),
        priority:$('#wnp-priority').val()||'normal',
        ttl:parseInt($('#wnp-ttl').val(),10)||86400,
    };
    if(payload.delivery==='scheduled'){
        payload.scheduled_date=$('#wnp-sd').val();
        payload.scheduled_time=$('#wnp-st').val();
        payload.scheduled_tz=$('#wnp-stz').val();
    }
    try{
        var res=await fetch(api.restUrl+'/send',{method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':api.nonce},body:JSON.stringify(payload)});
        var d=await res.json();
        if(res.ok){
            fbMsg('\uD83D\uDE80 '+(d.message||'Sent!'),'ok');
            try{localStorage.removeItem(wnpDK);}catch(e){}
            $('#wnp-title').val('').trigger('input');
            $('#wnp-body').val('').trigger('input');
        } else {
            fbMsg('\u274C '+(d.error||'Error'),'err');
        }
    }catch(e){fbMsg('\u274C '+e.message,'err');}
    finally{$b.prop('disabled',false);$('#wnp-spin-ico').text('\uD83D\uDE80');}
}

// ── Restore draft ────────────────────────────
(function(){
    try{
        var d=JSON.parse(localStorage.getItem(wnpDK)||'null');
        if(!d||Date.now()-d.ts>86400000) return;
        if(d.title) $('#wnp-title').val(d.title).trigger('input');
        if(d.body)  $('#wnp-body').val(d.body).trigger('input');
        if(d.url)   $('#wnp-url').val(d.url);
        if(d.icon)  $('#wnp-icon').val(d.icon);
        fbMsg('\uD83D\uDCCB Draft restored.','ok');
    }catch(e){}
})();

// ── History ──────────────────────────────────
$('#wnp-hist-s').on('input',function(){
    var q=$(this).val().toLowerCase();
    $('#wnp-hist-tb tr').each(function(){$(this).toggle(!q||$(this).data('title').includes(q));});
});
$(document).on('click','.wnp-hd',function(){
    var n=$(this).data('n');
    if(!n) return;
    if(n.title) $('#wnp-title').val(n.title).trigger('input');
    if(n.body)  $('#wnp-body').val(n.body).trigger('input');
    if(n.icon)  {$('#wnp-icon').val(n.icon);updPrev();}
    if(n.url)   $('#wnp-url').val(n.url);
    $('html,body').animate({scrollTop:0},400);
    fbMsg('\uD83D\uDCCB Duplicated — review and send.','ok');
});

function showMsg(txt,cls){
    var $n=$('#wnp-compose-notice');
    $n.attr('class','wnp-cn '+(cls==='err'?'err':'ok')).text(txt).show();
    setTimeout(function(){$n.fadeOut();},5000);
}

// ── Fix footer left for folded sidebar ───────
function fixFooter(){
    var folded=$('body').hasClass('folded');
    $('#wnp-fb').css('left',folded?'36px':'160px');
}
fixFooter();
$('body').on('click','#collapse-button',function(){setTimeout(fixFooter,300);});

} // end if compose page

// ══════════════════════════════════════════════
//  SUBSCRIBERS PAGE
// ══════════════════════════════════════════════
$('#wnp-sub-tbody').on('click','.wnp-del-btn',async function(){
    var $b=$(this), id=parseInt($b.data('id'),10);
    if(!id||!confirm('Delete this subscriber?')) return;
    $b.prop('disabled',true).text('\u2026');
    try{
        var r=await fetch(api.restUrl+'/subscribers/'+id,{method:'DELETE',headers:{'X-WP-Nonce':api.nonce}});
        if(r.ok){var $row=$('#wnp-row-'+id);$row.css({opacity:0,transition:'opacity .3s'});setTimeout(function(){$row.remove();},320);}
        else{var dd=await r.json().catch(function(){return{};});alert(dd.error||'Failed.');$b.prop('disabled',false).text('\uD83D\uDDD1 Delete');}
    }catch(e){alert(e.message);$b.prop('disabled',false).text('\uD83D\uDDD1 Delete');}
});

// ══════════════════════════════════════════════
//  SETTINGS PAGE
// ══════════════════════════════════════════════
$('#popup_title').on('input',function(){$('#preview-popup-title').text($(this).val()||'Stay Updated!');});
$('#popup_body').on('input', function(){$('#preview-popup-body').text($(this).val()||'Get notified.');});
$('#wnp-copy-key').on('click',function(){
    var t=$('#wnp-pubkey').text().trim();
    if(!t||!navigator.clipboard) return;
    navigator.clipboard.writeText(t).then(function(){var $b=$('#wnp-copy-key'),o=$b.text();$b.text('\u2705 Copied!');setTimeout(function(){$b.text(o);},2000);});
});
$('#wnp-pubkey').on('click',function(){$('#wnp-copy-key').trigger('click');});

// ══════════════════════════════════════════════
//  DASHBOARD — Process Now
// ══════════════════════════════════════════════
setTimeout(function(){$('.notice.is-dismissible:not(.notice-error)').fadeOut(600);},5000);

}(jQuery));


// ══════════════════════════════════════════════
//  SUBSCRIBERS PAGE — New Features
// ══════════════════════════════════════════════
(function($){
'use strict';
var api = window.wnpAdmin || {};

// Only run on subscribers tab — check for elements that exist in new HTML
var isSubPage = $('#wnp-sub-tbody').length || $('#wnp-cb-all').length || $('.wnp-filter-btn').length;
if (!isSubPage) return;

// ── Dropdown open/close ──────────────────────────────────────────────────────
// Uses direct display toggle on the menu element — no CSS class dependency.

var openDd = null; // track which dropdown is open

$(document).on('click', '.wnp-filter-btn', function(e) {
    e.stopPropagation();
    var dd   = $(this).data('dd');
    var $menu = $('#wnp-ddm-' + dd);

    // Close any open dropdown first
    if (openDd && openDd !== dd) {
        $('#wnp-ddm-' + openDd).hide();
    }

    if ($menu.is(':visible')) {
        $menu.hide();
        openDd = null;
    } else {
        $menu.show();
        openDd = dd;
    }
});

// Close dropdown when clicking anywhere else
$(document).on('click', function(e) {
    if (openDd && !$(e.target).closest('[id^="wnp-dd-"]').length) {
        $('#wnp-ddm-' + openDd).hide();
        openDd = null;
    }
});

// ── Select All checkbox ──────────────────────────────────────────────────────
$(document).on('change', '#wnp-cb-all', function() {
    var checked = $(this).is(':checked');
    $('.wnp-cb').prop('checked', checked);
    updateBulkBtn();
});

$(document).on('change', '.wnp-cb', function() {
    var total   = $('.wnp-cb').length;
    var checked = $('.wnp-cb:checked').length;
    $('#wnp-cb-all').prop('indeterminate', checked > 0 && checked < total)
                    .prop('checked', checked === total && total > 0);
    updateBulkBtn();
});

function updateBulkBtn() {
    var n   = $('.wnp-cb:checked').length;
    var $b  = $('#wnp-bulk-del');
    $('#wnp-sel-count').text(n);
    if (n > 0) {
        $b.prop('disabled', false)
          .css({ opacity: 1, cursor: 'pointer' });
    } else {
        $b.prop('disabled', true)
          .css({ opacity: 0.4, cursor: 'not-allowed' });
    }
}

// ── Bulk delete ──────────────────────────────────────────────────────────────
$(document).on('click', '#wnp-bulk-del', async function() {
    var ids = [];
    $('.wnp-cb:checked').each(function() { ids.push(parseInt($(this).val(), 10)); });
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' subscriber(s)? This cannot be undone.')) return;

    var $b = $(this);
    $b.prop('disabled', true).text('Deleting…');

    try {
        var r = await fetch(api.restUrl + '/subscribers/bulk-delete', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': api.nonce },
            body:    JSON.stringify({ ids: ids }),
        });
        var d = await r.json();
        if (r.ok && d.success) {
            ids.forEach(function(id) {
                var $row = $('#wnp-row-' + id);
                $row.css({ background: '#fef2f2', transition: 'opacity .3s', opacity: 0 });
                setTimeout(function() { $row.remove(); updateBulkBtn(); }, 350);
            });
            $('#wnp-cb-all').prop('checked', false).prop('indeterminate', false);
        } else {
            alert(d.error || 'Bulk delete failed.');
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
    $b.prop('disabled', false).html('🗑 Delete Selected (<span id="wnp-sel-count">0</span>)');
});

// ── Single delete ────────────────────────────────────────────────────────────
$(document).on('click', '.wnp-del-btn', async function() {
    var $b  = $(this);
    var id  = parseInt($b.data('id'), 10);
    if (!id || !confirm('Delete this subscriber?')) return;
    $b.prop('disabled', true).text('…');
    try {
        var r = await fetch(api.restUrl + '/subscribers/' + id, {
            method:  'DELETE',
            headers: { 'X-WP-Nonce': api.nonce },
        });
        if (r.ok) {
            var $row = $('#wnp-row-' + id);
            $row.css({ background: '#fef2f2', opacity: 1 });
            $row.animate({ opacity: 0 }, 300, function() {
                $row.remove();
                updateBulkBtn();
            });
        } else {
            var dd = await r.json().catch(function() { return {}; });
            alert(dd.error || 'Delete failed.');
            $b.prop('disabled', false).html('🗑');
        }
    } catch (e) {
        alert(e.message);
        $b.prop('disabled', false).html('🗑');
    }
});

}(jQuery));

// ══════════════════════════════════════════════
//  DASHBOARD — AJAX Process Now & Clear Log
// ══════════════════════════════════════════════
(function ($) {
    'use strict';
    var api = window.wnpAdmin || {};

    // ── Process Now button ────────────────────────────────────────────────────
    $(document).on('click', '#wnp-process-now-btn, .wnp-process-job', async function () {
        var $b = $(this);
        $b.prop('disabled', true).text('⏳ Processing…');

        try {
            var r = await fetch(api.restUrl + '/process-now', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': api.nonce },
                body:    JSON.stringify({}),
            });
            var d = await r.json();

            // Reload the dashboard tab content via AJAX
            if (typeof loadTab === 'function') {
                loadTab('dashboard', {}, window.location.href);
            } else {
                // Fallback: reload page
                window.location.reload();
            }
        } catch (e) {
            alert('Error: ' + e.message);
            $b.prop('disabled', false).html('⚡ Process Now');
        }
    });

    // ── Clear Log button ──────────────────────────────────────────────────────
    $(document).on('click', '#wnp-clear-log-btn', async function () {
        var $b = $(this);
        $b.prop('disabled', true);

        try {
            // Delete log via direct option delete — use a simple REST approach
            // We'll just reload the tab to reflect cleared log after deleting
            await fetch(api.ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    'action=wnp_clear_log&nonce=' + encodeURIComponent(api.ajaxNonce),
            });

            if (typeof loadTab === 'function') {
                loadTab('dashboard', {}, window.location.href);
            } else {
                window.location.reload();
            }
        } catch (e) {
            $b.prop('disabled', false);
        }
    });

}(jQuery));
