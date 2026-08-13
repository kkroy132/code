<?php
/**
 * CSV and PDF export.
 *
 * The PDF writer is a minimal, dependency-free PDF 1.4 generator: enough to
 * produce a clean paginated table with the core fonts, without shipping a
 * multi-megabyte library.
 *
 * @package WP_SEO_Doctor
 */

defined('ABSPATH') || exit;

class WPSD_Export {

    /**
     * Write one CSV row.
     *
     * The separator/enclosure/escape arguments are passed explicitly: PHP 8.4
     * deprecates relying on the default escape character, and an empty escape
     * is the RFC 4180-conformant behaviour.
     *
     * @param resource         $handle
     * @param array<int,mixed> $fields
     */
    private static function put_row($handle, array $fields): void {
        fputcsv($handle, $fields, ',', '"', '');
    }

    public static function init(): void {
        add_action('admin_post_wpsd_export', [self::class, 'handle_export']);
    }

    /**
     * admin-post handler for both CSV and PDF downloads.
     */
    public static function handle_export(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to export reports.', 'wp-seo-doctor'), '', ['response' => 403]);
        }

        check_admin_referer('wpsd_export');

        $report = isset($_GET['report']) ? sanitize_key(wp_unslash($_GET['report'])) : 'audit';
        $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : 'csv';
        $limit  = isset($_GET['limit']) ? absint(wp_unslash($_GET['limit'])) : 1000;

        $data = WPSD_Reports::build($report, ['limit' => $limit]);

        if ($format === 'pdf') {
            self::stream_pdf($data);
        }

        self::stream_csv($data);
    }

    /**
     * @param array<string,mixed> $report
     */
    public static function stream_csv(array $report): void {
        $filename = self::filename($report, 'csv');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $handle = fopen('php://output', 'w');
        if (!$handle) {
            wp_die(esc_html__('Could not open the output stream.', 'wp-seo-doctor'));
        }

        // BOM so Excel opens UTF-8 correctly.
        fwrite($handle, "\xEF\xBB\xBF");

        self::put_row($handle, [(string) $report['title']]);
        self::put_row($handle, [
            /* translators: %s: site name */
            sprintf(__('Site: %s', 'wp-seo-doctor'), (string) $report['site']),
            (string) $report['url'],
        ]);
        self::put_row($handle, [
            /* translators: %s: date and time */
            sprintf(__('Generated: %s', 'wp-seo-doctor'), (string) $report['generated']),
        ]);
        self::put_row($handle, ['']);

        foreach ((array) $report['meta'] as $label => $value) {
            self::put_row($handle, [(string) $label, (string) $value]);
        }
        self::put_row($handle, ['']);

        if (!empty($report['columns'])) {
            self::put_row($handle, array_map('strval', (array) $report['columns']));
        }
        foreach ((array) $report['rows'] as $row) {
            self::put_row($handle, array_map('strval', (array) $row));
        }

        fclose($handle);
        exit;
    }

    /**
     * @param array<string,mixed> $report
     */
    public static function stream_pdf(array $report): void {
        $pdf      = self::render_pdf($report);
        $filename = self::filename($report, 'pdf');

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF payload.
        echo $pdf;
        exit;
    }

    private static function filename(array $report, string $extension): string {
        $slug = sanitize_title((string) $report['title']);
        return sprintf('%s-%s.%s', $slug !== '' ? $slug : 'wpsd-report', gmdate('Y-m-d'), $extension);
    }

    // ─────────────────────────────────────────────────────── PDF writer ──

    /** A4 portrait, in PostScript points. */
    const PAGE_WIDTH  = 595.28;
    const PAGE_HEIGHT = 841.89;
    const MARGIN      = 40.0;

    /**
     * Render a report structure as a PDF document.
     *
     * @param array<string,mixed> $report
     */
    public static function render_pdf(array $report): string {
        $columns    = array_map('strval', (array) $report['columns']);
        $rows       = (array) $report['rows'];
        $col_widths = self::column_widths($columns, $rows);

        $pages   = [];
        $content = '';
        $y       = self::PAGE_HEIGHT - self::MARGIN;

        // ── Title block ──
        $content .= self::text_op(self::MARGIN, $y, (string) $report['title'], 18, true);
        $y       -= 24;
        $content .= self::text_op(self::MARGIN, $y, (string) $report['site'] . ' — ' . (string) $report['url'], 10);
        $y       -= 14;
        $content .= self::text_op(
            self::MARGIN,
            $y,
            sprintf(
                /* translators: %s: date and time */
                __('Generated %s', 'wp-seo-doctor'),
                (string) $report['generated']
            ),
            9
        );
        $y -= 26;

        // ── Summary metrics ──
        foreach ((array) $report['meta'] as $label => $value) {
            if ($y < self::MARGIN + 40) {
                $pages[] = $content;
                $content = '';
                $y       = self::PAGE_HEIGHT - self::MARGIN;
            }
            $content .= self::text_op(self::MARGIN, $y, (string) $label . ': ', 10, true);
            $content .= self::text_op(self::MARGIN + 150, $y, (string) $value, 10);
            $y       -= 14;
        }

        $y -= 12;

        // ── Table ──
        if ($columns) {
            $content .= self::header_row($columns, $col_widths, $y);
            $y       -= 18;
        }

        foreach ($rows as $row) {
            if ($y < self::MARGIN + 24) {
                $pages[] = $content;
                $content = '';
                $y       = self::PAGE_HEIGHT - self::MARGIN;
                if ($columns) {
                    $content .= self::header_row($columns, $col_widths, $y);
                    $y       -= 18;
                }
            }

            $x = self::MARGIN;
            foreach (array_values((array) $row) as $index => $cell) {
                $width    = $col_widths[$index] ?? 80.0;
                $content .= self::text_op($x, $y, self::fit((string) $cell, $width, 8), 8);
                $x       += $width;
            }
            $y -= 12;
        }

        $pages[] = $content;

        return self::assemble($pages);
    }

    /**
     * @param array<int,string> $columns
     * @param array<int,float>  $widths
     */
    private static function header_row(array $columns, array $widths, float $y): string {
        $out = '';
        $x   = self::MARGIN;

        foreach ($columns as $index => $label) {
            $width = $widths[$index] ?? 80.0;
            $out  .= self::text_op($x, $y, self::fit($label, $width, 9), 9, true);
            $x    += $width;
        }

        // Rule under the header.
        $out .= sprintf(
            "0.6 w 0.6 0.6 0.6 RG %.2F %.2F m %.2F %.2F l S\n",
            self::MARGIN,
            $y - 4,
            self::PAGE_WIDTH - self::MARGIN,
            $y - 4
        );

        return $out;
    }

    /**
     * Distribute the printable width across columns, weighted by content.
     *
     * @param array<int,string> $columns
     * @param array<int,array>  $rows
     * @return array<int,float>
     */
    private static function column_widths(array $columns, array $rows): array {
        $count = count($columns);
        if ($count === 0) {
            return [];
        }

        $available = self::PAGE_WIDTH - (self::MARGIN * 2);

        // Weight each column by the longest value it holds, sampled from the
        // first 100 rows so a huge report does not scan everything.
        $weights = [];
        foreach ($columns as $index => $label) {
            $weights[$index] = max(8, mb_strlen($label));
        }
        foreach (array_slice($rows, 0, 100) as $row) {
            foreach (array_values((array) $row) as $index => $cell) {
                if (isset($weights[$index])) {
                    $weights[$index] = max($weights[$index], min(60, mb_strlen((string) $cell)));
                }
            }
        }

        $total  = max(1, array_sum($weights));
        $widths = [];
        foreach ($weights as $index => $weight) {
            $widths[$index] = round($available * ($weight / $total), 2);
        }

        return $widths;
    }

    /** Truncate a cell to whatever fits its column at the given font size. */
    private static function fit(string $text, float $width, float $font_size): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        // Helvetica averages ~0.5em per character.
        $max = (int) max(1, floor($width / ($font_size * 0.5)) - 1);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    private static function text_op(float $x, float $y, string $text, float $size = 10, bool $bold = false): string {
        return sprintf(
            "BT /%s %.1F Tf 0 0 0 rg %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1',
            $size,
            $x,
            $y,
            self::escape_pdf_text($text)
        );
    }

    /**
     * The core fonts are single-byte (WinAnsi), so transliterate and escape.
     */
    private static function escape_pdf_text(string $text): string {
        if (function_exists('iconv')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- undecodable bytes are dropped deliberately.
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        } else {
            $text = remove_accents($text);
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text);
    }

    /**
     * Wrap page content streams into a complete PDF file.
     *
     * @param array<int,string> $pages
     */
    private static function assemble(array $pages): string {
        $pages = array_values(array_filter($pages, static fn($p) => $p !== ''));
        if (!$pages) {
            $pages = [self::text_op(self::MARGIN, self::PAGE_HEIGHT - self::MARGIN, __('No data.', 'wp-seo-doctor'))];
        }

        $objects   = [];
        $page_count = count($pages);

        // 1: Catalog, 2: Pages, 3: Helvetica, 4: Helvetica-Bold,
        // then two objects per page (page dict + content stream).
        $page_ids = [];
        for ($i = 0; $i < $page_count; $i++) {
            $page_ids[] = 5 + ($i * 2);
        }

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = sprintf(
            "<< /Type /Pages /Count %d /Kids [%s] >>",
            $page_count,
            implode(' ', array_map(static fn($id) => "{$id} 0 R", $page_ids))
        );
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        foreach ($pages as $index => $stream) {
            $page_id    = $page_ids[$index];
            $content_id = $page_id + 1;

            $objects[$page_id] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $content_id
            );
            $objects[$content_id] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($stream),
                $stream
            );
        }

        ksort($objects);

        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf         .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref_offset = strlen($pdf);
        $max_id      = max(array_keys($objects));

        $pdf .= "xref\n0 " . ($max_id + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $max_id; $id++) {
            $pdf .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                // Unused slots must still appear in the table.
                : "0000000000 65535 f \n";
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF",
            $max_id + 1,
            $xref_offset
        );

        return $pdf;
    }

    /**
     * URL for an export link.
     */
    public static function url(string $report, string $format = 'csv', int $limit = 1000): string {
        return wp_nonce_url(
            add_query_arg([
                'action' => 'wpsd_export',
                'report' => $report,
                'format' => $format,
                'limit'  => $limit,
            ], admin_url('admin-post.php')),
            'wpsd_export'
        );
    }
}
