<?php
/**
 * Content fingerprints, kept in the database instead of in PHP memory.
 *
 * Duplicate detection used to load the whole corpus on every scan: one index
 * issued a query per post to read its SEO meta, the other serialised every
 * post's shingles into a single transient. On 2,000 posts that measured 16,001
 * queries and a 1.7 MB payload, which crosses the default 16 MB
 * max_allowed_packet somewhere around 20,000 posts — and when it does, the
 * transient write simply fails and duplicate detection stops working silently.
 *
 * Each post now writes its own fingerprint row while it is being scanned, and
 * every duplicate lookup is a single indexed query no matter how large the site
 * is.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Fingerprints {

    /**
     * Largest sketch kept per post.
     *
     * Sampling a fixed fraction of the hash space was tried first and broke on
     * short or repetitive posts: a page with 90 distinct shingles could sample
     * zero, making it uncomparable. Keeping the k smallest hashes instead
     * guarantees every post has a sketch, bounds storage at k rows, and is
     * exact for any post with fewer than k distinct shingles.
     */
    const MAX_SHINGLES = 128;

    /** Shingle length in words. Matches WPSD_Helpers::shingles(). */
    const SHINGLE_SIZE = 4;

    /** Below this many sampled shingles a similarity score is noise. */
    const MIN_SHINGLES = 4;

    /**
     * Record (or refresh) one post's fingerprint.
     */
    public static function store(WP_Post $post): void {
        global $wpdb;

        $id = (int) $post->ID;
        if ($id <= 0) {
            return;
        }

        $title       = WPSD_Helpers::get_seo_title($id);
        $description = WPSD_Helpers::get_seo_description($id);
        $content     = WPSD_Helpers::rendered_content($post);

        $title_value = trim($title['value']);
        // An excerpt fallback is not an authored description, so it must not
        // count as a duplicate of another post's excerpt.
        $desc_value  = $description['source'] === 'excerpt' ? '' : trim($description['value']);

        $wpdb->replace(WPSD_DB::table('fingerprints'), [
            'post_id'    => $id,
            'post_type'  => $post->post_type,
            'title_hash' => $title_value !== '' ? md5(mb_strtolower($title_value)) : '',
            'desc_hash'  => $desc_value !== '' ? md5(mb_strtolower($desc_value)) : '',
            'word_count' => WPSD_Helpers::word_count($content),
            'updated_at' => WPSD_Helpers::now(),
        ]);

        self::store_shingles($id, $content);
    }

    /**
     * Replace a post's sampled shingles.
     */
    private static function store_shingles(int $post_id, string $content): void {
        global $wpdb;

        $table = WPSD_DB::table('shingles');
        $wpdb->delete($table, ['post_id' => $post_id]);

        $sampled = self::sample(WPSD_Helpers::shingles($content, self::SHINGLE_SIZE));
        if (!$sampled) {
            return;
        }

        // One multi-row insert: a per-shingle insert would put this right back
        // into the per-post query storm it is meant to remove.
        $rows = [];
        $args = [];
        foreach ($sampled as $shingle) {
            $rows[] = '(%d,%s)';
            $args[] = $post_id;
            $args[] = $shingle;
        }

        $sql = "INSERT IGNORE INTO {$table} (post_id, shingle) VALUES " . implode(',', $rows);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
        $wpdb->query($wpdb->prepare($sql, $args));
    }

    /**
     * Reduce a shingle set to its k smallest hashes.
     *
     * Selecting by hash value rather than at random means two posts sharing a
     * passage keep the same shingles from it, which is what makes the overlap
     * measurable at all. Posts under the cap keep everything, so their score
     * is exact; longer posts are compared on the matching low region of the
     * hash space, which is accurate precisely where it matters — near-identical
     * pages share nearly all of their smallest hashes.
     *
     * @param array<string,bool> $shingles Keyed by full md5.
     * @return array<int,string> Truncated hashes to store.
     */
    private static function sample(array $shingles): array {
        $hashes = array_map(static fn($hash) => substr($hash, 0, 16), array_keys($shingles));
        if (!$hashes) {
            return [];
        }

        sort($hashes, SORT_STRING);

        return array_slice($hashes, 0, self::MAX_SHINGLES);
    }

    public static function forget(int $post_id): void {
        global $wpdb;
        $wpdb->delete(WPSD_DB::table('fingerprints'), ['post_id' => $post_id]);
        $wpdb->delete(WPSD_DB::table('shingles'), ['post_id' => $post_id]);
    }

    /**
     * Drop fingerprints for posts that no longer exist or are no longer public.
     */
    public static function prune(): void {
        global $wpdb;

        $fingerprints = WPSD_DB::table('fingerprints');
        $shingles     = WPSD_DB::table('shingles');

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query(
            "DELETE f FROM {$fingerprints} f
             LEFT JOIN {$wpdb->posts} p ON p.ID = f.post_id
             WHERE p.ID IS NULL OR p.post_status <> 'publish'"
        );
        $wpdb->query(
            "DELETE s FROM {$shingles} s
             LEFT JOIN {$fingerprints} f ON f.post_id = s.post_id
             WHERE f.post_id IS NULL"
        );
        // phpcs:enable
    }

    // ──────────────────────────────────────────────────────── duplicates ──

    /**
     * Groups of posts sharing an SEO title.
     *
     * @return array<int,array{hash:string,post_ids:array<int,int>}>
     */
    public static function duplicate_titles(): array {
        return self::duplicate_groups('title_hash');
    }

    /**
     * Groups of posts sharing a meta description.
     *
     * @return array<int,array{hash:string,post_ids:array<int,int>}>
     */
    public static function duplicate_descriptions(): array {
        return self::duplicate_groups('desc_hash');
    }

    /**
     * @return array<int,array{hash:string,post_ids:array<int,int>}>
     */
    private static function duplicate_groups(string $column): array {
        global $wpdb;

        if (!in_array($column, ['title_hash', 'desc_hash'], true)) {
            return [];
        }

        $table = WPSD_DB::table('fingerprints');

        // GROUP_CONCAT keeps this to one round trip. The default 1024-byte
        // limit would truncate a large duplicate group, so it is raised for
        // this statement only.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('SET SESSION group_concat_max_len = 1000000');
        $rows = $wpdb->get_results(
            "SELECT {$column} AS hash, GROUP_CONCAT(post_id) AS ids, COUNT(*) AS total
             FROM {$table}
             WHERE {$column} <> ''
             GROUP BY {$column}
             HAVING total > 1
             ORDER BY total DESC
             LIMIT 500"
        );
        // phpcs:enable

        $out = [];
        foreach ((array) $rows as $row) {
            $out[] = [
                'hash'     => (string) $row->hash,
                'post_ids' => array_map('intval', explode(',', (string) $row->ids)),
            ];
        }

        return $out;
    }

    /**
     * Other posts whose SEO title matches this one's.
     *
     * @return array<int,int>
     */
    public static function posts_sharing_title(int $post_id): array {
        return self::posts_sharing($post_id, 'title_hash');
    }

    /**
     * @return array<int,int>
     */
    public static function posts_sharing_description(int $post_id): array {
        return self::posts_sharing($post_id, 'desc_hash');
    }

    /**
     * @return array<int,int>
     */
    private static function posts_sharing(int $post_id, string $column): array {
        global $wpdb;

        if (!in_array($column, ['title_hash', 'desc_hash'], true)) {
            return [];
        }

        $table = WPSD_DB::table('fingerprints');

        $sql = "SELECT other.post_id
                FROM {$table} mine
                JOIN {$table} other
                  ON other.{$column} = mine.{$column}
                 AND other.post_id <> mine.post_id
                WHERE mine.post_id = %d
                  AND mine.{$column} <> ''
                LIMIT 100";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $post_id)));
    }

    /**
     * Posts whose body overlaps this one's, by estimated Jaccard similarity.
     *
     * The whole comparison happens in one indexed query: join the post's
     * shingles against everyone else's, count the overlap per post, and divide
     * by the union.
     *
     * @return array<int,array{post_id:int,similarity:float,shared:int}>
     */
    public static function similar_to(int $post_id, float $threshold = 0.75, int $limit = 10): array {
        global $wpdb;

        $shingles = WPSD_DB::table('shingles');

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $mine = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$shingles} WHERE post_id = %d",
            $post_id
        ));

        if ($mine < self::MIN_SHINGLES) {
            // Too little sampled text for the estimate to mean anything.
            return [];
        }

        // similarity = shared / (mine + theirs - shared)
        $sql = "SELECT theirs.post_id,
                       COUNT(*) AS shared,
                       totals.total AS their_total
                FROM {$shingles} mine
                JOIN {$shingles} theirs
                  ON theirs.shingle = mine.shingle
                 AND theirs.post_id <> mine.post_id
                JOIN (
                    SELECT post_id, COUNT(*) AS total FROM {$shingles} GROUP BY post_id
                ) AS totals ON totals.post_id = theirs.post_id
                WHERE mine.post_id = %d
                GROUP BY theirs.post_id, totals.total
                HAVING shared >= %d
                ORDER BY shared DESC
                LIMIT %d";

        // Cheap pre-filter: a post cannot clear the threshold without at least
        // this much overlap, and it keeps the sort off the long tail.
        $minimum_shared = max(1, (int) floor($mine * $threshold * 0.5));

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $post_id, $minimum_shared, $limit * 4));

        $out = [];
        foreach ((array) $rows as $row) {
            $shared = (int) $row->shared;
            $union  = $mine + (int) $row->their_total - $shared;
            if ($union <= 0) {
                continue;
            }

            $similarity = $shared / $union;
            if ($similarity < $threshold) {
                continue;
            }

            $out[] = [
                'post_id'    => (int) $row->post_id,
                'similarity' => round($similarity, 4),
                'shared'     => $shared,
            ];
        }

        usort($out, static fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($out, 0, $limit);
    }

    /**
     * Fingerprint published posts that do not have a row yet.
     *
     * A scan populates the table as it goes, but the Content SEO screen has to
     * work before the first scan too — returning "no duplicates" because the
     * table happens to be empty would be a lie. Bounded per call so a fresh
     * install on a large site fills in over a few requests rather than timing
     * out on one.
     *
     * @return int Number of posts fingerprinted.
     */
    public static function ensure_built(int $limit = 300): int {
        global $wpdb;

        $table        = self::table_name();
        $types        = WPSD_Helpers::auditable_post_types();
        $placeholders = WPSD_DB::in_placeholders($types, '%s');

        $sql = "SELECT p.ID FROM {$wpdb->posts} p
                LEFT JOIN {$table} f ON f.post_id = p.ID
                WHERE p.post_status = 'publish'
                  AND p.post_type IN ({$placeholders})
                  AND f.post_id IS NULL
                ORDER BY p.post_modified DESC
                LIMIT %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col($wpdb->prepare($sql, array_merge($types, [max(1, $limit)])));

        $built = 0;
        foreach ((array) $ids as $id) {
            $post = get_post((int) $id);
            if ($post) {
                self::store($post);
                $built++;
            }
        }

        return $built;
    }

    private static function table_name(): string {
        return WPSD_DB::table('fingerprints');
    }

    /**
     * Whether any fingerprints exist yet. Checks fall back to computing on the
     * fly when a scan has not populated the table.
     */
    /**
     * How many posts a shingle may appear on before it counts as boilerplate.
     *
     * Scaled to the corpus: 2% of fingerprinted posts, with a floor so a small
     * site does not exclude everything and a ceiling so a large one still
     * discards genuine template text.
     */
    public static function common_shingle_cutoff(): int {
        $posts = WPSD_DB::count('fingerprints');
        if ($posts <= 0) {
            return 1;
        }

        /**
         * Filter the document-frequency cutoff for duplicate detection.
         *
         * @param int $cutoff Maximum posts a shingle may appear on.
         * @param int $posts  Fingerprinted posts.
         */
        return (int) apply_filters(
            'wpsd_common_shingle_cutoff',
            max(3, min(50, (int) ceil($posts * 0.02))),
            $posts
        );
    }

    public static function has_data(): bool {
        return WPSD_DB::count('fingerprints') > 0;
    }

    public static function has_post(int $post_id): bool {
        return WPSD_DB::count('fingerprints', 'post_id = %d', [$post_id]) > 0;
    }

    /**
     * @return array<string,int>
     */
    public static function stats(): array {
        return [
            'posts'    => WPSD_DB::count('fingerprints'),
            'shingles' => WPSD_DB::count('shingles'),
        ];
    }
}
