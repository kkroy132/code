<?php
/**
 * Sinemagor single movie post template.
 * Used when the theme does not override it.
 */
defined('ABSPATH') || exit;
get_header();

while (have_posts()) :
    the_post();

    $post_id   = get_the_ID();
    $cast_json = get_post_meta($post_id, '_sinemagor_cast',          true);
    $trailer   = get_post_meta($post_id, '_sinemagor_trailer_key',   true);
    $poster    = get_post_meta($post_id, '_sinemagor_poster',        true);
    $faqs_raw  = get_post_meta($post_id, '_sinemagor_faqs',          true);
    $wtw_raw   = get_post_meta($post_id, '_sinemagor_wtw',           true);

    $cast    = $cast_json ? (json_decode($cast_json, true) ?: []) : [];
    $faqs    = $faqs_raw  ? (json_decode($faqs_raw,  true) ?: []) : [];
    $wtw     = $wtw_raw   ? (json_decode($wtw_raw,   true) ?: []) : [];
?>

<article id="post-<?php the_ID(); ?>" <?php post_class('sg-single-movie'); ?>>

    <div class="sg-post-content">

        <?php the_content(); ?>

        <!-- ── Cast Grid ── -->
        <?php if (!empty($cast)): ?>
        <section class="sg-section-cast">
            <h2>Full Cast</h2>
            <div class="sg-cast-grid">
                <?php foreach ($cast as $member):
                    $profile = !empty($member['profile'])
                        ? Sinemagor_TMDB::image_url($member['profile'], 'w92')
                        : '';
                ?>
                <div class="sg-cast-card">
                    <?php if ($profile): ?>
                        <img src="<?php echo esc_url($profile); ?>"
                             alt="<?php echo esc_attr($member['name'] ?? ''); ?>"
                             loading="lazy" width="70" height="70" />
                    <?php else: ?>
                        <div style="width:70px;height:70px;border-radius:50%;background:#2e2e45;display:flex;align-items:center;justify-content:center;font-size:1.5rem">🎭</div>
                    <?php endif; ?>
                    <div class="sg-cast-name"><?php echo esc_html($member['name'] ?? ''); ?></div>
                    <?php if (!empty($member['character'])): ?>
                    <div class="sg-cast-name" style="opacity:.6"><?php echo esc_html($member['character']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Trailer ── -->
        <?php if ($trailer): ?>
        <section class="sg-section-trailer">
            <h2>Official Trailer</h2>
            <div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:10px">
                <iframe src="<?php echo esc_url(Sinemagor_TMDB::trailer_embed($trailer)); ?>"
                        style="position:absolute;inset:0;width:100%;height:100%;border:0"
                        allowfullscreen loading="lazy"
                        title="<?php the_title_attribute(); ?> — Official Trailer"></iframe>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── Where to Watch ── -->
        <?php if (!empty($wtw)): ?>
        <section class="sg-section-wtw">
            <h2>Where to Watch</h2>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                <?php foreach ($wtw as $service):
                    if (empty($service['url']) || empty($service['name'])) continue; ?>
                <a href="<?php echo esc_url($service['url']); ?>"
                   target="_blank" rel="nofollow noopener"
                   style="display:inline-flex;align-items:center;gap:8px;background:#1a1a28;border:1px solid #2e2e45;border-radius:8px;padding:8px 14px;text-decoration:none;color:#e0e0f0;font-size:.9rem">
                    <?php if (!empty($service['logo'])): ?>
                        <img src="<?php echo esc_url($service['logo']); ?>"
                             alt="" width="20" height="20" style="border-radius:4px" />
                    <?php endif; ?>
                    <?php echo esc_html($service['name']); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ── FAQ ── -->
        <?php if (!empty($faqs)): ?>
        <section class="sg-section-faq" itemscope itemtype="https://schema.org/FAQPage">
            <h2>Frequently Asked Questions</h2>
            <div class="sg-faq-list">
                <?php foreach ($faqs as $i => $faq):
                    if (empty($faq['question']) || empty($faq['answer'])) continue; ?>
                <div class="sg-faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
                    <button class="sg-faq-q" itemprop="name"
                            onclick="this.closest('.sg-faq-item').classList.toggle('open')"
                            aria-expanded="false">
                        <?php echo esc_html($faq['question']); ?>
                        <span class="sg-faq-arrow">▾</span>
                    </button>
                    <div class="sg-faq-a" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                        <div itemprop="text"><?php echo wp_kses_post($faq['answer']); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <style>
        .sg-faq-item{border:1px solid #2e2e45;border-radius:8px;margin-bottom:8px;overflow:hidden}
        .sg-faq-q{width:100%;background:#1a1a28;border:none;color:#e0e0f0;padding:14px 16px;text-align:left;cursor:pointer;font-size:.95rem;display:flex;justify-content:space-between;align-items:center;gap:12px}
        .sg-faq-a{display:none;padding:14px 16px;color:#9090b0;font-size:.9rem;line-height:1.7;background:#0f0f17}
        .sg-faq-item.open .sg-faq-a{display:block}
        .sg-faq-item.open .sg-faq-arrow{transform:rotate(180deg)}
        .sg-faq-arrow{transition:transform .2s;flex-shrink:0}
        </style>
        <?php endif; ?>

    </div><!-- .sg-post-content -->

</article>

<?php
endwhile;
get_footer();
