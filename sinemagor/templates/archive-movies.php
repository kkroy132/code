<?php
/**
 * Sinemagor Archive Template
 * Used for: category pages, tag pages, and custom /movie-reviews/ page.
 * Override by placing in your theme as: sinemagor-archive.php
 */
defined('ABSPATH') || exit;

get_header();

$paged  = max(1, get_query_var('paged'));
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public archive filter (genre/year/sort query args), not a form submission.
$genre  = isset($_GET['genre']) ? sanitize_text_field(wp_unslash($_GET['genre'])) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see justification above.
$year   = isset($_GET['year'])  ? sanitize_text_field(wp_unslash($_GET['year']))  : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see justification above.
$sort   = isset($_GET['sort'])  ? sanitize_key(wp_unslash($_GET['sort']))         : 'date';

// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering the archive by plugin-defined movie meta is the page's core purpose.
$meta_query = [['key' => '_sinemagor_tmdb_id', 'compare' => 'EXISTS']];
if ($genre) $meta_query[] = ['key' => '_sinemagor_genre', 'value' => $genre, 'compare' => 'LIKE'];
if ($year)  $meta_query[] = ['key' => '_sinemagor_year',  'value' => $year];

$orderby = $sort === 'rating' ? 'meta_value_num' : 'date';
$meta_key_sort = $sort === 'rating' ? '_sinemagor_editor_rating' : '';

$query_args = [
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 18,
    'paged'          => $paged,
    'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering the archive by plugin-defined movie meta is the page's core purpose.
    'orderby'        => $orderby,
    'order'          => 'DESC',
];
// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- sorting by plugin-defined editor rating meta is an explicit user-facing option.
if ($meta_key_sort) $query_args['meta_key'] = $meta_key_sort;

$q      = new WP_Query($query_args);
$genres = Sinemagor_DB::get_genres();
$years  = Sinemagor_DB::get_years();
?>

<div class="sg-archive-wrap">

    <!-- Page Header -->
    <div class="sg-archive-header">
        <h1 class="sg-archive-title">🎬 Movie Reviews</h1>
        <p class="sg-archive-subtitle"><?php echo esc_html($q->found_posts); ?> reviews and counting</p>
    </div>

    <!-- Filter Bar -->
    <form class="sg-archive-filters" method="GET">
        <select name="genre" onchange="this.form.submit()">
            <option value="">All Genres</option>
            <?php foreach ($genres as $g): ?>
                <option value="<?php echo esc_attr($g); ?>" <?php selected($genre, $g); ?>><?php echo esc_html($g); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="year" onchange="this.form.submit()">
            <option value="">All Years</option>
            <?php foreach ($years as $y): ?>
                <option value="<?php echo esc_attr($y); ?>" <?php selected($year, $y); ?>><?php echo esc_html($y); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="sort" onchange="this.form.submit()">
            <option value="date"   <?php selected($sort, 'date');   ?>>Latest First</option>
            <option value="rating" <?php selected($sort, 'rating'); ?>>Highest Rated</option>
        </select>
    </form>

    <!-- Movie Grid -->
    <?php if ($q->have_posts()): ?>
    <div class="sg-archive-grid">
        <?php while ($q->have_posts()): $q->the_post(); ?>
            <?php
            $post_id = get_the_ID();
            $poster  = get_post_meta($post_id, '_sinemagor_poster',        true);
            $rating  = get_post_meta($post_id, '_sinemagor_editor_rating', true);
            $tmdb_r  = get_post_meta($post_id, '_sinemagor_tmdb_rating',   true);
            $genre_  = get_post_meta($post_id, '_sinemagor_genre',         true);
            $year_   = get_post_meta($post_id, '_sinemagor_year',          true);
            $img     = $poster ? Sinemagor_TMDB::image_url($poster, 'w342') : '';
            ?>
            <article class="sg-archive-card">
                <a href="<?php the_permalink(); ?>" class="sg-archive-card-link">
                    <div class="sg-archive-poster">
                        <?php if ($img): ?>
                            <img src="<?php echo esc_url($img); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy" />
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
                            <?php if ($year_): ?><span><?php echo esc_html($year_); ?></span><?php endif; ?>
                            <?php if ($genre_): ?><span><?php echo esc_html(explode(',', $genre_)[0]); ?></span><?php endif; ?>
                            <?php if ($tmdb_r): ?><span>TMDB <?php echo esc_html($tmdb_r); ?></span><?php endif; ?>
                        </div>
                    </div>
                </a>
            </article>
        <?php endwhile; wp_reset_postdata(); ?>
    </div>

    <!-- Pagination -->
    <div class="sg-archive-pagination">
        <?php
        echo wp_kses_post(paginate_links([
            'total'   => $q->max_num_pages,
            'current' => $paged,
            'format'  => '?paged=%#%',
            'prev_text' => '‹ Prev',
            'next_text' => 'Next ›',
        ]));
        ?>
    </div>

    <?php else: ?>
        <p class="sg-archive-empty">No reviews found. Try a different filter.</p>
    <?php endif; ?>

</div>

<?php get_footer(); ?>
