<?php
/**
 * Sinemagor Category Archive Template
 * Used for sg-by-genre, sg-by-director, sg-by-cast, sg-by-language archives.
 */
defined('ABSPATH') || exit;

// Detect category type
$term      = get_queried_object();
$cat_type  = $term ? get_term_meta($term->term_id, 'sg_cat_type', true) : '';
$cat_name  = $term ? $term->name  : '';
$cat_desc  = $term ? $term->description : '';
$post_count= $term ? (int) $term->count  : 0;

// Type labels and icons
$type_info = [
    'director' => ['icon' => '🎬', 'label' => 'Director', 'desc' => "All movies directed by {$cat_name}"],
    'cast'     => ['icon' => '🎭', 'label' => 'Actor/Actress', 'desc' => "All movies starring {$cat_name}"],
    'genre'    => ['icon' => '🎭', 'label' => 'Genre', 'desc' => "Best {$cat_name} movies reviewed"],
    'language' => ['icon' => '🌍', 'label' => 'Language', 'desc' => "{$cat_name} language movies"],
    'parent'   => ['icon' => '📁', 'label' => 'Category', 'desc' => "Browse movies by category"],
];
$info = $type_info[$cat_type] ?? ['icon' => '🎬', 'label' => 'Category', 'desc' => "Movies in {$cat_name}"];

$paged = max(1, get_query_var('paged'));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public archive sort query arg, not a form submission.
$sort  = isset($_GET['sort']) ? sanitize_key(wp_unslash($_GET['sort'])) : 'date';

$query_args = [
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 18,
    'paged'          => $paged,
    'cat'            => $term ? $term->term_id : 0,
];

if ($sort === 'rating') {
    $query_args['meta_key'] = '_sinemagor_editor_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- sorting by plugin-defined editor rating meta is an explicit user-facing option.
    $query_args['orderby']  = 'meta_value_num';
    $query_args['order']    = 'DESC';
} else {
    $query_args['orderby'] = 'date';
    $query_args['order']   = 'DESC';
}

$q = new WP_Query($query_args);

get_header();
?>

<div class="sg-cat-wrap">

    <!-- Category Hero -->
    <div class="sg-cat-hero">
        <div class="sg-cat-hero-inner">
            <div class="sg-cat-icon"><?php echo esc_html($info['icon']); ?></div>
            <div class="sg-cat-hero-text">
                <div class="sg-cat-type-badge"><?php echo esc_html($info['label']); ?></div>
                <h1 class="sg-cat-title"><?php echo esc_html($cat_name); ?></h1>
                <p class="sg-cat-desc"><?php echo esc_html($cat_desc ?: $info['desc']); ?></p>
                <div class="sg-cat-stats">
                    <span>💽 <?php echo esc_html($post_count); ?> Review<?php echo esc_html($post_count !== 1 ? 's' : ''); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Sort bar -->
    <div class="sg-cat-controls">
        <div class="sg-cat-controls-inner">
            <span style="color:var(--sg-muted);font-size:.85rem">Sort by:</span>
            <a href="?sort=date"   class="sg-sort-btn <?php echo $sort === 'date'   ? 'active' : ''; ?>">Latest</a>
            <a href="?sort=rating" class="sg-sort-btn <?php echo $sort === 'rating' ? 'active' : ''; ?>">⭐ Highest Rated</a>
        </div>
    </div>

    <!-- Movie Grid -->
    <div class="sg-cat-grid-wrap">
        <?php if ($q->have_posts()): ?>
        <div class="sg-archive-grid sg-cat-grid">
            <?php while ($q->have_posts()): $q->the_post(); ?>
                <?php
                $pid     = get_the_ID();
                $poster  = get_post_meta($pid, '_sinemagor_poster',        true);
                $rating  = get_post_meta($pid, '_sinemagor_editor_rating', true);
                $year    = get_post_meta($pid, '_sinemagor_year',          true);
                $genre   = get_post_meta($pid, '_sinemagor_genre',         true);
                $tmdb_r  = get_post_meta($pid, '_sinemagor_tmdb_rating',   true);
                $img     = $poster ? Sinemagor_TMDB::image_url($poster, 'w342') : '';
                ?>
                <article class="sg-archive-card">
                    <a href="<?php the_permalink(); ?>" class="sg-archive-card-link">
                        <div class="sg-archive-poster">
                            <?php if ($img): ?>
                                <img src="<?php echo esc_url($img); ?>"
                                     alt="<?php the_title_attribute(); ?>"
                                     loading="lazy"
                                     width="342" height="513" />
                            <?php else: ?>
                                <div class="sg-archive-no-poster">🎬</div>
                            <?php endif; ?>
                            <?php if ($rating): ?>
                                <div class="sg-archive-rating">⭐ <?php echo esc_html($rating); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="sg-archive-card-body">
                            <h2 class="sg-archive-card-title"><?php the_title(); ?></h2>
                            <div class="sg-archive-card-meta">
                                <?php if ($year): ?><span><?php echo esc_html($year); ?></span><?php endif; ?>
                                <?php if ($genre): ?><span><?php echo esc_html(explode(',', $genre)[0]); ?></span><?php endif; ?>
                                <?php if ($tmdb_r): ?><span>TMDB <?php echo esc_html($tmdb_r); ?></span><?php endif; ?>
                            </div>
                        </div>
                    </a>
                </article>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>

        <!-- Pagination -->
        <div class="sg-archive-pagination" style="padding:0 20px 40px">
            <?php echo wp_kses_post(paginate_links([
                'total'     => $q->max_num_pages,
                'current'   => $paged,
                'prev_text' => '‹ Prev',
                'next_text' => 'Next ›',
                'add_args'  => $sort !== 'date' ? ['sort' => $sort] : [],
            ])); ?>
        </div>

        <?php else: ?>
            <p class="sg-archive-empty">No reviews found in this category yet.</p>
        <?php endif; ?>
    </div>

</div>

<?php get_footer(); ?>
