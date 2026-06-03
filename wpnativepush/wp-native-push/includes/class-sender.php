<?php
defined( 'ABSPATH' ) || exit;

/**
 * Queues notifications and dispatches them in batches.
 *
 * Delivery strategy (in order of preference):
 *  1. Process the first batch IMMEDIATELY in the same PHP request (always works).
 *  2. Spawn a loopback cron request for remaining batches (works if host allows).
 *  3. WP-Cron recurring event as final fallback (works when site has traffic).
 */
class WNP_Sender {

    const BATCH_SIZE   = 50;
    const OPT_QUEUE    = 'wnp_send_queue';
    const OPT_LOG      = 'wnp_send_log';
    const OPT_LOCK     = 'wnp_batch_lock';
    const LOG_MAX_ROWS = 200;
    const LOCK_TIMEOUT = 90;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Enqueue a notification and immediately process the first batch
     * in the current PHP request — no cron dependency required.
     */
    public static function queue( array $notification ): void {
        $queue = get_option( self::OPT_QUEUE, [] );

        $queue[] = [
            'id'           => uniqid( 'wnp_', true ),
            'notification' => $notification,
            'total'        => WNP_Subscriber::count(),
            'sent'         => 0,
            'failed'       => 0,
            'offset'       => 0,
            'queued_at'    => time(),
            'status'       => 'pending',
        ];

        update_option( self::OPT_QUEUE, $queue );

        self::log( sprintf(
            '[%s] Queued "%s" for %d subscriber(s).',
            current_time( 'mysql' ),
            sanitize_text_field( $notification['title'] ),
            WNP_Subscriber::count()
        ) );

        // ── Strategy 1: Process first batch RIGHT NOW in this request ─────────
        // This always works regardless of hosting restrictions.
        // Uses shutdown hook so the HTTP response goes back to admin first.
        add_action( 'shutdown', [ __CLASS__, 'process_batch' ], 5 );

        // ── Strategy 2: Loopback spawn for subsequent batches ─────────────────
        // Works if the host allows self-referential HTTP requests.
        self::spawn_cron_now();

        // ── Strategy 3: Recurring WP-Cron as final fallback ───────────────────
        if ( ! wp_next_scheduled( 'wnp_process_batch' ) ) {
            wp_schedule_event( time(), 'wnp_every_minute', 'wnp_process_batch' );
        }
    }

    /**
     * Force-process the queue immediately (used by "Process Now" admin button
     * and also called via shutdown hook and WP-Cron).
     */
    public static function process_batch(): void {
        if ( ! self::acquire_lock() ) {
            return;
        }
        try {
            $has_more = self::do_process_batch();

            // If there are more batches remaining, try to spawn cron immediately.
            if ( $has_more ) {
                self::spawn_cron_now();
            }
        } finally {
            self::release_lock();
        }
    }

    /**
     * Try to spawn wp-cron.php via a non-blocking loopback HTTP request.
     * Safe to call even if loopback is blocked — failure is silent.
     */
    public static function spawn_cron_now(): void {
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            return;
        }
        if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
            return;
        }

        $cron_url = add_query_arg(
            'doing_wp_cron',
            sprintf( '%.22F', microtime( true ) ),
            site_url( 'wp-cron.php' )
        );

        wp_remote_post( $cron_url, [
            'timeout'    => 0.01,
            'blocking'   => false,
            'sslverify'  => false,   // avoid SSL errors on localhost/loopback
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            'headers'    => [ 'Content-type' => 'application/x-www-form-urlencoded' ],
        ] );
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    public static function get_queue(): array {
        return get_option( self::OPT_QUEUE, [] );
    }

    public static function get_log(): array {
        return array_reverse( get_option( self::OPT_LOG, [] ) );
    }

    /**
     * Diagnostic: check if WP-Cron loopback works on this host.
     * Returns true/false so the admin dashboard can show a warning.
     */
    public static function test_loopback(): bool {
        $response = wp_remote_get( site_url( 'wp-cron.php' ), [
            'timeout'   => 5,
            'blocking'  => true,
            'sslverify' => false,
        ] );
        return ! is_wp_error( $response );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Process one batch from the first pending job.
     *
     * @return bool TRUE if there are more batches left to process.
     */
    private static function do_process_batch(): bool {
        // Always re-fetch from DB inside the lock so we see the latest state.
        $queue = get_option( self::OPT_QUEUE, [] );

        if ( empty( $queue ) ) {
            return false;
        }

        $has_more = false;

        foreach ( $queue as &$job ) {
            if ( $job['status'] !== 'pending' ) {
                continue;
            }

            $job['status'] = 'processing';
            update_option( self::OPT_QUEUE, $queue );

            $batch = WNP_Subscriber::get_batch( self::BATCH_SIZE, $job['offset'] );

            if ( empty( $batch ) ) {
                $job['status'] = 'completed';
                update_option( self::OPT_QUEUE, $queue );
                self::log( sprintf(
                    '[%s] Job %s complete — sent %d, failed %d.',
                    current_time( 'mysql' ),
                    $job['id'],
                    $job['sent'],
                    $job['failed']
                ) );
                break;
            }

            $result = self::dispatch_batch( $job['notification'], $batch );

            $job['sent']   += $result['sent'];
            $job['failed'] += $result['failed'];
            $job['offset'] += self::BATCH_SIZE;
            $job['status']  = 'pending';

            update_option( self::OPT_QUEUE, $queue );

            // Signal whether there are more subscribers left to send to.
            $has_more = ( $job['offset'] < $job['total'] );
            break;
        }
        unset( $job );

        self::prune_queue( $queue );

        return $has_more;
    }

    // ── Process lock ──────────────────────────────────────────────────────────

    private static function acquire_lock(): bool {
        $lock = (int) get_option( self::OPT_LOCK, 0 );
        if ( $lock && ( time() - $lock ) < self::LOCK_TIMEOUT ) {
            return false;
        }
        $token = time();
        update_option( self::OPT_LOCK, $token );
        // Verify we actually won the lock (mitigates parallel-request race).
        return ( (int) get_option( self::OPT_LOCK, 0 ) === $token );
    }

    private static function release_lock(): void {
        delete_option( self::OPT_LOCK );
    }

    // ── Delivery ──────────────────────────────────────────────────────────────

    /**
     * @return array{ sent: int, failed: int }
     */
    private static function dispatch_batch( array $notification, array $subscribers ): array {
        $sent    = 0;
        $failed  = 0;
        $payload = (string) wp_json_encode( [
            'title' => $notification['title'],
            'body'  => $notification['body'],
            'icon'  => $notification['icon']  ?? '',
            'badge' => $notification['badge'] ?? '',
            'url'   => $notification['url']   ?? home_url(),
        ] );

        foreach ( $subscribers as $sub ) {
            try {
                $result = self::push( $payload, $sub );
                if ( $result['success'] ) {
                    $sent++;
                } else {
                    $failed++;
                    if ( in_array( $result['code'], [ 404, 410 ], true ) ) {
                        WNP_Subscriber::delete_by_endpoint( $sub['endpoint'] );
                        self::log( sprintf(
                            '[%s] Removed stale subscriber #%d (HTTP %d).',
                            current_time( 'mysql' ), $sub['id'], $result['code']
                        ) );
                    } else {
                        self::log( sprintf(
                            '[%s] Failed subscriber #%d — HTTP %d.',
                            current_time( 'mysql' ), $sub['id'], $result['code']
                        ) );
                    }
                }
            } catch ( \Throwable $e ) {
                $failed++;
                self::log( sprintf(
                    '[%s] Exception subscriber #%d: %s',
                    current_time( 'mysql' ), $sub['id'], $e->getMessage()
                ) );
            }
        }

        self::log( sprintf(
            '[%s] Batch done — sent %d, failed %d (offset %d).',
            current_time( 'mysql' ),
            $sent,
            $failed,
            0
        ) );

        return compact( 'sent', 'failed' );
    }

    /**
     * @return array{ success: bool, code: int }
     */
    private static function push( string $payload, array $subscriber ): array {
        $encrypted     = WNP_Encryption::encrypt( $payload, $subscriber );
        $vapid_headers = WNP_Vapid::get_headers( $subscriber['endpoint'] );

        $response = wp_remote_post( $subscriber['endpoint'], [
            'method'    => 'POST',
            'headers'   => array_merge( $vapid_headers, [
                'Content-Type'     => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL'              => '86400',
            ] ),
            'body'      => $encrypted['ciphertext'],
            'timeout'   => 15,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            self::log( sprintf(
                '[%s] WP_Error pushing to subscriber: %s',
                current_time( 'mysql' ),
                $response->get_error_message()
            ) );
            return [ 'success' => false, 'code' => 0 ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        return [
            'success' => in_array( $code, [ 200, 201, 202 ], true ),
            'code'    => $code,
        ];
    }

    private static function log( string $message ): void {
        $log   = get_option( self::OPT_LOG, [] );
        $log[] = $message;
        if ( count( $log ) > self::LOG_MAX_ROWS ) {
            $log = array_slice( $log, -self::LOG_MAX_ROWS );
        }
        update_option( self::OPT_LOG, $log );
    }

    private static function prune_queue( array $queue ): void {
        $cutoff = time() - DAY_IN_SECONDS;
        $clean  = array_values( array_filter( $queue, static function ( $job ) use ( $cutoff ) {
            return $job['status'] !== 'completed' || $job['queued_at'] > $cutoff;
        } ) );
        update_option( self::OPT_QUEUE, $clean );
    }
}

add_action( 'wnp_process_batch', [ 'WNP_Sender', 'process_batch' ] );
