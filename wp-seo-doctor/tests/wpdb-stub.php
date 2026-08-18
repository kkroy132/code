<?php
/**
 * A real $wpdb backed by MariaDB, plus the post-related WordPress functions the
 * plugin's database paths call. Enough to execute WP SEO Doctor's actual SQL
 * against a real engine without WordPress core.
 */

class WPSD_Test_WPDB {

    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $insert_id = 0;
    public $last_error = '';
    public $last_query = '';

    /** @var mysqli */
    private $link;

    /** @var array<int,array{sql:string,error:string}> */
    public array $failures = [];
    public int $query_count = 0;

    public function __construct(string $socket, string $database) {
        // wpdb checks return values rather than catching exceptions, so match
        // that behaviour instead of PHP 8's default of throwing.
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->link = new mysqli(null, 'root', '', $database, null, $socket);
        if ($this->link->connect_errno) {
            throw new RuntimeException('DB connect failed: ' . $this->link->connect_error);
        }
        $this->link->set_charset('utf8mb4');
    }

    public function get_charset_collate(): string {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function esc_like($text) {
        return addcslashes((string) $text, '_%\\');
    }

    /**
     * Mirrors wpdb::prepare closely enough for these queries: %s quoted,
     * %d integer, %f float, and a single array argument is unpacked.
     */
    public function prepare($query, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $args = array_values($args);

        $index = 0;
        $out = preg_replace_callback('/%[sdfF]/', function ($m) use (&$index, $args) {
            $value = $args[$index] ?? null;
            $index++;
            switch ($m[0]) {
                case '%d':
                    return (string) (int) $value;
                case '%f':
                case '%F':
                    return (string) (float) $value;
                default:
                    return "'" . $this->link->real_escape_string((string) $value) . "'";
            }
        }, $query);

        if ($index !== count($args)) {
            throw new RuntimeException(sprintf(
                "prepare() placeholder/argument mismatch: %d placeholders, %d args\n  SQL: %s",
                $index,
                count($args),
                preg_replace('/\s+/', ' ', substr($query, 0, 300))
            ));
        }

        return $out;
    }

    private function run(string $sql) {
        $this->last_query = $sql;
        $this->query_count++;
        $result = $this->link->query($sql);
        if ($result === false) {
            $this->last_error = $this->link->error;
            $this->failures[] = [
                'sql'   => preg_replace('/\s+/', ' ', substr($sql, 0, 400)),
                'error' => $this->link->error,
            ];
            return false;
        }
        $this->last_error = '';
        return $result;
    }

    public function query($sql) {
        $result = $this->run($sql);
        if ($result === false) {
            return false;
        }
        if ($result instanceof mysqli_result) {
            $rows = $result->num_rows;
            $result->free();
            return $rows;
        }
        $this->insert_id = $this->link->insert_id;
        return $this->link->affected_rows;
    }

    public function get_results($sql, $output = OBJECT) {
        $result = $this->run($sql);
        if (!$result instanceof mysqli_result) {
            return [];
        }
        $rows = [];
        while ($row = ($output === ARRAY_A ? $result->fetch_assoc() : $result->fetch_object())) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    public function get_row($sql, $output = OBJECT) {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function get_col($sql) {
        $rows = $this->get_results($sql, ARRAY_A);
        return array_map(static fn($r) => reset($r), $rows);
    }

    public function get_var($sql) {
        $row = $this->get_row($sql, ARRAY_A);
        return $row ? reset($row) : null;
    }

    public function insert($table, $data, $format = null) {
        $columns = [];
        $values = [];
        foreach ($data as $column => $value) {
            $columns[] = "`{$column}`";
            $values[] = $value === null ? 'NULL' : "'" . $this->link->real_escape_string((string) $value) . "'";
        }
        $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')';
        $result = $this->query($sql);
        $this->insert_id = $this->link->insert_id;
        return $result;
    }

    public function replace($table, $data, $format = null) {
        $columns = [];
        $values = [];
        foreach ($data as $column => $value) {
            $columns[] = "`{$column}`";
            $values[] = $value === null ? 'NULL' : "'" . $this->link->real_escape_string((string) $value) . "'";
        }
        $sql = "REPLACE INTO {$table} (" . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')';
        $result = $this->query($sql);
        $this->insert_id = $this->link->insert_id;
        return $result;
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $set = [];
        foreach ($data as $column => $value) {
            $set[] = "`{$column}` = " . ($value === null ? 'NULL' : "'" . $this->link->real_escape_string((string) $value) . "'");
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = "`{$column}` = '" . $this->link->real_escape_string((string) $value) . "'";
        }
        return $this->query("UPDATE {$table} SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $conditions));
    }

    public function delete($table, $where, $where_format = null) {
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = "`{$column}` = '" . $this->link->real_escape_string((string) $value) . "'";
        }
        return $this->query("DELETE FROM {$table} WHERE " . implode(' AND ', $conditions));
    }
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/** dbDelta stand-in: executes the CREATE TABLE straight through. */
function dbDelta($sql) {
    global $wpdb;
    $wpdb->query($sql);
    return [];
}

// ── Post-related stubs, backed by the real wp_posts / wp_postmeta tables ──

class WP_Post {
    public $ID = 0;
    public $post_title = '';
    public $post_content = '';
    public $post_excerpt = '';
    public $post_type = 'post';
    public $post_status = 'publish';
    public $post_name = '';
    public $post_date = '';
    public $post_modified = '';
    public $post_modified_gmt = '';

    public function __construct(array $row = []) {
        foreach ($row as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $key === 'ID' ? (int) $value : $value;
            }
        }
    }
}

/**
 * Cached like core: WP_Post::get_instance() consults the object cache before
 * querying, so repeated get_post() calls in one request are free. Querying
 * every time would inflate any measurement of per-post query cost.
 */
$GLOBALS['wpsd_post_cache'] = [];

function get_post($id) {
    global $wpdb;
    if ($id instanceof WP_Post) {
        return $id;
    }
    $id = (int) $id;
    if (array_key_exists($id, $GLOBALS['wpsd_post_cache'])) {
        return $GLOBALS['wpsd_post_cache'][$id];
    }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id), ARRAY_A);
    return $GLOBALS['wpsd_post_cache'][$id] = ($row ? new WP_Post($row) : null);
}

function clean_post_cache($id) {
    unset($GLOBALS['wpsd_post_cache'][(int) $id]);
}

function get_permalink($post) {
    $post = $post instanceof WP_Post ? $post : get_post($post);
    if (!$post) {
        return '';
    }
    return home_url('/' . $post->post_name . '/');
}

function get_the_title($id) {
    $post = get_post($id);
    return $post ? $post->post_title : '';
}

function get_the_excerpt($id) {
    $post = get_post($id);
    return $post ? $post->post_excerpt : '';
}

function get_post_field($field, $id) {
    $post = get_post($id);
    return $post && property_exists($post, $field) ? $post->$field : '';
}

function get_edit_post_link($id, $context = 'display') {
    return admin_url('post.php?post=' . (int) $id . '&action=edit');
}

function get_the_modified_date($format, $post) {
    $post = $post instanceof WP_Post ? $post : get_post($post);
    return $post ? $post->post_modified : '';
}

function mysql2date($format, $date) { return $date; }

function url_to_postid($url) {
    global $wpdb;
    $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
    if ($path === '') {
        return 0;
    }
    $slug = basename($path);
    return (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1", $slug));
}

function get_page_by_path($slug, $output = OBJECT, $types = ['post', 'page']) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1", $slug), ARRAY_A);
    return $row ? new WP_Post($row) : null;
}

/**
 * Mirrors core's meta cache: the first read for a post loads ALL of its meta in
 * one query and caches it. Querying per key instead would make any measurement
 * of query counts wrong by roughly the number of keys a caller tries.
 */
$GLOBALS['wpsd_meta_cache'] = [];

function get_post_meta($id, $key, $single = false) {
    global $wpdb;
    $id = (int) $id;

    if (!array_key_exists($id, $GLOBALS['wpsd_meta_cache'])) {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id),
            ARRAY_A
        );
        $cached = [];
        foreach ((array) $rows as $row) {
            $cached[$row['meta_key']] = $row['meta_value'];
        }
        $GLOBALS['wpsd_meta_cache'][$id] = $cached;
    }

    return $GLOBALS['wpsd_meta_cache'][$id][$key] ?? '';
}

function update_post_meta($id, $key, $value) {
    global $wpdb;
    $id = (int) $id;
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
        $id,
        $key
    ));
    unset($GLOBALS['wpsd_meta_cache'][$id]);
    return $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $key, 'meta_value' => $value]);
}

function maybe_unserialize($v) { return $v; }
function get_post_thumbnail_id($id) { return 0; }
function wp_get_post_terms($id, $tax, $args = []) { return []; }
function attachment_url_to_postid($url) { return 0; }

function wp_update_post($data, $wp_error = false) {
    global $wpdb;
    // Core's wp_insert_post() unslashes its input; mirror that so callers
    // that forget wp_slash() visibly lose their backslashes here too.
    $data = wp_unslash($data);
    $id = (int) ($data['ID'] ?? 0);
    if (!$id) {
        return 0;
    }
    unset($data['ID']);
    $wpdb->update($wpdb->posts, $data, ['ID' => $id]);
    clean_post_cache($id);
    return $id;
}

function get_posts($args = []) {
    global $wpdb;
    $limit = (int) ($args['posts_per_page'] ?? 10);
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_status = 'publish' ORDER BY post_modified DESC LIMIT %d", $limit),
        ARRAY_A
    );
    $posts = array_map(static fn($r) => new WP_Post($r), $rows);
    return ($args['fields'] ?? '') === 'ids' ? array_map(static fn($p) => $p->ID, $posts) : $posts;
}

function wp_count_posts($type = 'post') {
    global $wpdb;
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
        $type
    ));
    return (object) ['publish' => $count];
}

function wp_is_post_revision($id) { return false; }
function wp_is_post_autosave($id) { return false; }
function wp_next_scheduled($hook, $args = []) { return false; }
function wp_schedule_event() { return true; }
function wp_schedule_single_event() { return true; }
function wp_clear_scheduled_hook() { return true; }
function wp_get_scheduled_event($hook) { return false; }
function wp_cache_get($k, $g = '') { return false; }
function wp_cache_set($k, $v, $g = '', $e = 0) { return true; }
function wp_cache_delete($k, $g = '') { return true; }
function human_time_diff($from, $to = null) { return '2 days'; }
function wp_mail() { return true; }
function do_action_stub() {}
