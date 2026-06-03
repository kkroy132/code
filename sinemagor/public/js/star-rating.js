(function () {
    'use strict';
    var widget = document.getElementById('sg-rating-widget');
    if (!widget) return;

    var postId  = widget.getAttribute('data-post');
    var stars   = widget.querySelectorAll('.sg-star');
    var summary = widget.querySelector('.sg-rating-avg');
    var countEl = widget.querySelector('.sg-rating-count');
    var yourEl  = widget.querySelector('.sg-your-vote');

    // Hover effect
    stars.forEach(function (star, idx) {
        star.addEventListener('mouseenter', function () {
            stars.forEach(function (s, i) {
                s.classList.toggle('sg-star--hover', i <= idx);
            });
        });
        star.addEventListener('mouseleave', function () {
            stars.forEach(function (s) { s.classList.remove('sg-star--hover'); });
        });

        // Click = submit
        star.addEventListener('click', function () {
            var val = parseInt(this.getAttribute('data-val'));
            submitRating(val);
        });
    });

    function submitRating(val) {
        var fd = new FormData();
        fd.append('action',  'sg_submit_rating');
        fd.append('nonce',   sg_rating.nonce);
        fd.append('post_id', postId);
        fd.append('rating',  val);

        fetch(sg_rating.ajax_url, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) return;
                var data = res.data;

                // Update stars
                stars.forEach(function (s, i) {
                    s.classList.toggle('sg-star--on', i < val);
                });

                // Update summary
                if (summary) summary.textContent = '⭐ ' + data.avg + ' / 10';
                if (countEl) countEl.textContent  = '(' + Number(data.total).toLocaleString() + ' votes)';
                if (yourEl)  yourEl.textContent   = 'Your rating: ' + val + '/10';
                else {
                    var p = document.createElement('p');
                    p.className = 'sg-your-vote';
                    p.textContent = 'Your rating: ' + val + '/10';
                    widget.appendChild(p);
                }
            });
    }
})();
