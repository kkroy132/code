<?php
defined( 'ABSPATH' ) || exit;

/**
 * CRUD + filtering + stats for the wp_push_subscribers table.
 */
class WNP_Subscriber {

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'push_subscribers';
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    public static function save( array $data ) {
        global $wpdb;
        $inserted = $wpdb->insert(
            self::table(),
            [
                'endpoint'     => $data['endpoint'],
                'public_key'   => $data['public_key'],
                'auth_token'   => $data['auth_token'],
                'user_agent'   => sanitize_text_field( $data['user_agent']   ?? '' ),
                'country'      => strtoupper( sanitize_text_field( $data['country']      ?? '' ) ),
                'country_name' => sanitize_text_field( $data['country_name'] ?? '' ),
                'created_at'   => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        return $inserted ? $wpdb->insert_id : false;
    }

    public static function update_keys( string $endpoint, array $keys ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            self::table(),
            [
                'public_key' => sanitize_text_field( $keys['public_key'] ),
                'auth_token' => sanitize_text_field( $keys['auth_token'] ),
            ],
            [ 'endpoint' => $endpoint ],
            [ '%s', '%s' ],
            [ '%s' ]
        );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    public static function delete_by_endpoint( string $endpoint ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'endpoint' => $endpoint ], [ '%s' ] );
    }

    /**
     * Bulk delete by array of IDs.
     *
     * @param  int[] $ids
     * @return int   Number of rows deleted.
     */
    public static function bulk_delete( array $ids ): int {
        global $wpdb;
        if ( empty( $ids ) ) return 0;

        $ids        = array_map( 'absint', $ids );
        $ids        = array_filter( $ids );
        if ( empty( $ids ) ) return 0;

        $tbl        = self::table();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$tbl}` WHERE id IN ({$placeholders})", ...$ids ) );
    }

    // ── Read (standard) ───────────────────────────────────────────────────────

    public static function count(): int {
        global $wpdb;
        $tbl = self::table();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tbl}`" );
    }

    public static function get_all( int $per_page = 50, int $offset = 0 ): array {
        global $wpdb;
        $tbl = self::table();
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `{$tbl}` ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ),
            ARRAY_A
        );
    }

    public static function get_batch( int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $tbl = self::table();
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `{$tbl}` ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ),
            ARRAY_A
        );
    }

    public static function endpoint_exists( string $endpoint ): bool {
        global $wpdb;
        $tbl = self::table();
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$tbl}` WHERE endpoint = %s", $endpoint ) );
    }

    // ── Read (filtered) ───────────────────────────────────────────────────────

    /**
     * Get filtered subscriber list.
     *
     * @param array $args {
     *   search:  string   — search in user_agent or endpoint
     *   browser: string   — chrome|firefox|edge|safari|other|all
     *   device:  string   — desktop|mobile|all
     *   days:    int      — 0=all, 7=last 7 days, 30=last 30 days, 90=last 90 days
     *   per_page:int
     *   offset:  int
     * }
     */
    public static function get_filtered( array $args = [] ): array {
        global $wpdb;
        $tbl = self::table();

        $search  = sanitize_text_field( $args['search']  ?? '' );
        $browser = sanitize_key( $args['browser'] ?? 'all' );
        $device  = sanitize_key( $args['device']  ?? 'all' );
        $days    = absint( $args['days']    ?? 0 );
        $country = strtoupper( sanitize_text_field( $args['country'] ?? '' ) );
        $per     = absint( $args['per_page'] ?? 50 );
        $offset  = absint( $args['offset']   ?? 0 );

        $where  = [];
        $params = [];

        if ( $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(user_agent LIKE %s OR endpoint LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        if ( $days > 0 ) {
            $where[]  = 'created_at >= %s';
            $params[] = gmdate( 'Y-m-d H:i:s', time() - ( absint( $days ) * DAY_IN_SECONDS ) );
        }

        if ( $country ) {
            $where[]  = 'country = %s';
            $params[] = $country;
        }

        $sql = "SELECT * FROM `{$tbl}`";
        if ( $where ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }
        $sql .= ' ORDER BY created_at DESC';

        // Fetch all matching rows first (for browser/device PHP-side filter).
        $rows = $params
            ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) // phpcs:ignore
            : $wpdb->get_results( $sql, ARRAY_A );

        // PHP-side browser/device filter (user_agent is not indexed).
        if ( $browser !== 'all' || $device !== 'all' ) {
            $rows = array_values( array_filter( $rows, static function ( $row ) use ( $browser, $device ) {
                $ua  = strtolower( $row['user_agent'] ?? '' );
                $b   = self::detect_browser( $ua );
                $dev = self::detect_device( $ua );

                if ( $browser !== 'all' && $b !== $browser ) return false;
                if ( $device  !== 'all' && $dev !== $device )  return false;
                return true;
            } ) );
        }

        // Return total count + paginated slice.
        $total  = count( $rows );
        $sliced = array_slice( $rows, $offset, $per );
        return [ 'total' => $total, 'rows' => $sliced ];
    }

    /**
     * Count filtered (without pagination) — for display.
     */
    public static function count_filtered( array $args = [] ): int {
        return self::get_filtered( array_merge( $args, [ 'per_page' => PHP_INT_MAX, 'offset' => 0 ] ) )['total'];
    }

    // ── Stats ──────────────────────────────────────────────────────────────────

    /**
     * Browser + device breakdown counts.
     *
     * @return array {
     *   total:   int,
     *   desktop: int, mobile: int,
     *   chrome:  int, firefox: int, edge: int, safari: int, other: int,
     *   by_day:  array  last-30-day daily counts [ 'Y-m-d' => int ]
     * }
     */
    public static function get_stats(): array {
        global $wpdb;
        $tbl  = self::table();
        $rows = $wpdb->get_results( "SELECT user_agent, created_at FROM `{$tbl}`", ARRAY_A );

        $stats = [
            'total'   => count( $rows ),
            'desktop' => 0,
            'mobile'  => 0,
            'chrome'  => 0,
            'firefox' => 0,
            'edge'    => 0,
            'safari'  => 0,
            'other'   => 0,
            'by_day'  => [],
        ];

        $cutoff = strtotime( '-29 days midnight' );

        foreach ( $rows as $row ) {
            $ua  = strtolower( $row['user_agent'] ?? '' );
            $dev = self::detect_device( $ua );
            $br  = self::detect_browser( $ua );

            $stats[ $dev ]++;
            $stats[ $br  ]++;

            // Daily growth (last 30 days).
            $ts = strtotime( $row['created_at'] );
            if ( $ts >= $cutoff ) {
                $day = gmdate( 'Y-m-d', $ts );
                $stats['by_day'][ $day ] = ( $stats['by_day'][ $day ] ?? 0 ) + 1;
            }
        }

        // Fill missing days with 0 so chart has a continuous axis.
        for ( $i = 29; $i >= 0; $i-- ) {
            $day = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
            if ( ! isset( $stats['by_day'][ $day ] ) ) {
                $stats['by_day'][ $day ] = 0;
            }
        }
        ksort( $stats['by_day'] );

        return $stats;
    }

    // ── Export ─────────────────────────────────────────────────────────────────

    /**
     * Get all subscribers as a flat array suitable for CSV export.
     */
    public static function get_all_for_export(): array {
        global $wpdb;
        $tbl = self::table();
        return $wpdb->get_results(
            "SELECT id, endpoint, user_agent, created_at FROM `{$tbl}` ORDER BY created_at DESC",
            ARRAY_A
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public static function detect_browser( string $ua ): string {
        $ua = strtolower( $ua );
        if ( str_contains( $ua, 'edg/' ) || str_contains( $ua, 'edge' ) )    return 'edge';
        if ( str_contains( $ua, 'firefox' ) || str_contains( $ua, 'fxios' ) ) return 'firefox';
        if ( str_contains( $ua, 'opr/' ) || str_contains( $ua, 'opera' ) )    return 'other';
        if ( str_contains( $ua, 'chrome' ) || str_contains( $ua, 'crios' ) )  return 'chrome';
        if ( str_contains( $ua, 'safari' ) )                                   return 'safari';
        return 'other';
    }

    public static function detect_device( string $ua ): string {
        $ua = strtolower( $ua );
        if ( str_contains( $ua, 'mobile' ) || str_contains( $ua, 'android' ) ||
             str_contains( $ua, 'iphone' ) || str_contains( $ua, 'ipad' ) ) {
            return 'mobile';
        }
        return 'desktop';
    }

    /** Emoji icon for detected browser. */
    public static function browser_icon( string $ua ): string {
        $map = [
            'chrome'  => '🟡',
            'firefox' => '🦊',
            'edge'    => '🌐',
            'safari'  => '🧭',
            'other'   => '🌍',
        ];
        return $map[ self::detect_browser( strtolower( $ua ) ) ] ?? '🌍';
    }

    /** Emoji icon for detected device. */
    public static function device_icon( string $ua ): string {
        return self::detect_device( strtolower( $ua ) ) === 'mobile' ? '📱' : '🖥';
    }

    // ── Country detection ──────────────────────────────────────────────────────

    /**
     * Detect country from an IP address.
     * Priority: Cloudflare header → ip-api.com → empty.
     *
     * @return array{ code: string, name: string }
     */
    public static function detect_country( string $ip ): array {
        // 1. Cloudflare — instant, no API call needed.
        if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
            $code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
            if ( strlen( $code ) === 2 && $code !== 'XX' && $code !== 'T1' ) {
                return [ 'code' => $code, 'name' => self::country_name( $code ) ];
            }
        }

        // 2. Skip private/reserved IPs (localhost, LAN, etc.).
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return [ 'code' => '', 'name' => '' ];
        }

        // 3. ip-api.com — free tier, no API key, 45 req/min limit.
        $response = wp_remote_get(
            'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=countryCode,country',
            [ 'timeout' => 3, 'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) ]
        );

        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if (
                is_array( $body ) &&
                isset( $body['status'] ) && $body['status'] === 'success' &&
                ! empty( $body['countryCode'] ) && strlen( $body['countryCode'] ) === 2
            ) {
                $code = strtoupper( $body['countryCode'] );
                $name = sanitize_text_field( $body['country'] ?? self::country_name( $code ) );
                return [ 'code' => $code, 'name' => $name ];
            }
        }

        return [ 'code' => '', 'name' => '' ];
    }

    /**
     * Unicode flag emoji from ISO 3166-1 alpha-2 country code.
     * Each letter → Regional Indicator Symbol (U+1F1E6 = A).
     */
    public static function flag_emoji( string $code ): string {
        $code = strtoupper( trim( $code ) );
        if ( strlen( $code ) !== 2 ) return '🌍';
        $flag = '';
        foreach ( str_split( $code ) as $c ) {
            $flag .= mb_chr( 0x1F1E6 + ( ord( $c ) - ord( 'A' ) ), 'UTF-8' );
        }
        return $flag;
    }

    /**
     * Country stats grouped by country code (top 20).
     *
     * @return array  [ 'BD' => [ 'name', 'flag', 'count' ], … ]
     */
    public static function get_country_stats(): array {
        global $wpdb;
        $tbl  = self::table();
        $rows = $wpdb->get_results(
            "SELECT country, country_name, COUNT(*) AS cnt
             FROM `{$tbl}`
             WHERE country != ''
             GROUP BY country, country_name
             ORDER BY cnt DESC
             LIMIT 20",
            ARRAY_A
        );

        $result = [];
        foreach ( $rows as $row ) {
            $code            = strtoupper( $row['country'] );
            $result[ $code ] = [
                'name'  => $row['country_name'] ?: self::country_name( $code ),
                'flag'  => self::flag_emoji( $code ),
                'count' => (int) $row['cnt'],
            ];
        }
        return $result;
    }

    /**
     * Get all unique country codes stored in DB (for filter dropdown).
     */
    public static function get_country_list(): array {
        global $wpdb;
        $tbl = self::table();
        return $wpdb->get_results(
            "SELECT DISTINCT country, country_name
             FROM `{$tbl}`
             WHERE country != ''
             ORDER BY country_name ASC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Built-in country name lookup — common 200+ countries.
     * Falls back to the code itself if not found.
     */
    public static function country_name( string $code ): string {
        static $map = [
            'AD'=>'Andorra','AE'=>'United Arab Emirates','AF'=>'Afghanistan',
            'AG'=>'Antigua & Barbuda','AL'=>'Albania','AM'=>'Armenia',
            'AO'=>'Angola','AR'=>'Argentina','AT'=>'Austria','AU'=>'Australia',
            'AZ'=>'Azerbaijan','BA'=>'Bosnia & Herzegovina','BB'=>'Barbados',
            'BD'=>'Bangladesh','BE'=>'Belgium','BF'=>'Burkina Faso','BG'=>'Bulgaria',
            'BH'=>'Bahrain','BJ'=>'Benin','BN'=>'Brunei','BO'=>'Bolivia',
            'BR'=>'Brazil','BS'=>'Bahamas','BT'=>'Bhutan','BW'=>'Botswana',
            'BY'=>'Belarus','BZ'=>'Belize','CA'=>'Canada','CD'=>'DR Congo',
            'CF'=>'Central African Republic','CG'=>'Congo','CH'=>'Switzerland',
            'CI'=>"Côte d'Ivoire",'CL'=>'Chile','CM'=>'Cameroon','CN'=>'China',
            'CO'=>'Colombia','CR'=>'Costa Rica','CU'=>'Cuba','CV'=>'Cape Verde',
            'CY'=>'Cyprus','CZ'=>'Czech Republic','DE'=>'Germany','DJ'=>'Djibouti',
            'DK'=>'Denmark','DM'=>'Dominica','DO'=>'Dominican Republic',
            'DZ'=>'Algeria','EC'=>'Ecuador','EE'=>'Estonia','EG'=>'Egypt',
            'ER'=>'Eritrea','ES'=>'Spain','ET'=>'Ethiopia','FI'=>'Finland',
            'FJ'=>'Fiji','FR'=>'France','GA'=>'Gabon','GB'=>'United Kingdom',
            'GD'=>'Grenada','GE'=>'Georgia','GH'=>'Ghana','GM'=>'Gambia',
            'GN'=>'Guinea','GQ'=>'Equatorial Guinea','GR'=>'Greece',
            'GT'=>'Guatemala','GW'=>'Guinea-Bissau','GY'=>'Guyana',
            'HN'=>'Honduras','HR'=>'Croatia','HT'=>'Haiti','HU'=>'Hungary',
            'ID'=>'Indonesia','IE'=>'Ireland','IL'=>'Israel','IN'=>'India',
            'IQ'=>'Iraq','IR'=>'Iran','IS'=>'Iceland','IT'=>'Italy',
            'JM'=>'Jamaica','JO'=>'Jordan','JP'=>'Japan','KE'=>'Kenya',
            'KG'=>'Kyrgyzstan','KH'=>'Cambodia','KI'=>'Kiribati','KM'=>'Comoros',
            'KN'=>'Saint Kitts & Nevis','KP'=>'North Korea','KR'=>'South Korea',
            'KW'=>'Kuwait','KZ'=>'Kazakhstan','LA'=>'Laos','LB'=>'Lebanon',
            'LC'=>'Saint Lucia','LI'=>'Liechtenstein','LK'=>'Sri Lanka',
            'LR'=>'Liberia','LS'=>'Lesotho','LT'=>'Lithuania','LU'=>'Luxembourg',
            'LV'=>'Latvia','LY'=>'Libya','MA'=>'Morocco','MC'=>'Monaco',
            'MD'=>'Moldova','ME'=>'Montenegro','MG'=>'Madagascar',
            'MH'=>'Marshall Islands','MK'=>'North Macedonia','ML'=>'Mali',
            'MM'=>'Myanmar','MN'=>'Mongolia','MR'=>'Mauritania','MT'=>'Malta',
            'MU'=>'Mauritius','MV'=>'Maldives','MW'=>'Malawi','MX'=>'Mexico',
            'MY'=>'Malaysia','MZ'=>'Mozambique','NA'=>'Namibia','NE'=>'Niger',
            'NG'=>'Nigeria','NI'=>'Nicaragua','NL'=>'Netherlands','NO'=>'Norway',
            'NP'=>'Nepal','NR'=>'Nauru','NZ'=>'New Zealand','OM'=>'Oman',
            'PA'=>'Panama','PE'=>'Peru','PG'=>'Papua New Guinea',
            'PH'=>'Philippines','PK'=>'Pakistan','PL'=>'Poland','PT'=>'Portugal',
            'PW'=>'Palau','PY'=>'Paraguay','QA'=>'Qatar','RO'=>'Romania',
            'RS'=>'Serbia','RU'=>'Russia','RW'=>'Rwanda','SA'=>'Saudi Arabia',
            'SB'=>'Solomon Islands','SC'=>'Seychelles','SD'=>'Sudan',
            'SE'=>'Sweden','SG'=>'Singapore','SI'=>'Slovenia','SK'=>'Slovakia',
            'SL'=>'Sierra Leone','SM'=>'San Marino','SN'=>'Senegal',
            'SO'=>'Somalia','SR'=>'Suriname','SS'=>'South Sudan',
            'ST'=>'São Tomé & Príncipe','SV'=>'El Salvador','SY'=>'Syria',
            'SZ'=>'Eswatini','TD'=>'Chad','TG'=>'Togo','TH'=>'Thailand',
            'TJ'=>'Tajikistan','TL'=>'Timor-Leste','TM'=>'Turkmenistan',
            'TN'=>'Tunisia','TO'=>'Tonga','TR'=>'Turkey','TT'=>'Trinidad & Tobago',
            'TV'=>'Tuvalu','TZ'=>'Tanzania','UA'=>'Ukraine','UG'=>'Uganda',
            'US'=>'United States','UY'=>'Uruguay','UZ'=>'Uzbekistan',
            'VA'=>'Vatican City','VC'=>'Saint Vincent & the Grenadines',
            'VE'=>'Venezuela','VN'=>'Vietnam','VU'=>'Vanuatu','WS'=>'Samoa',
            'YE'=>'Yemen','ZA'=>'South Africa','ZM'=>'Zambia','ZW'=>'Zimbabwe',
        ];
        return $map[ strtoupper( $code ) ] ?? $code;
    }
}
