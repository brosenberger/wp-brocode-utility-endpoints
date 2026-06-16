<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */

declare(strict_types=1);

namespace Brocode\UtilityEndpoints;

if (!defined('ABSPATH')) {
    exit;
}

/** @return array{flushed: bool} */
function doFlushRewrites(): array
{
    flush_rewrite_rules(false);
    return ['flushed' => true];
}

/** @return array{cleared: list<string>, none: bool} */
function doClearCache(): array
{
    $cleared = [];

    if (has_action('cache_enabler_clear_complete_cache')) {
        do_action('cache_enabler_clear_complete_cache');
        $cleared[] = 'cache_enabler';
    }

    if (function_exists('w3tc_flush_all')) {
        w3tc_flush_all();
        $cleared[] = 'w3tc';
    }

    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
        $cleared[] = 'wp_super_cache';
    }

    return ['cleared' => $cleared, 'none' => $cleared === []];
}

/**
 * @return array{pattern: string, hits: list<array<string,string>>}|array{error: string}
 */
function doScanLinks(string $pattern): array
{
    global $wpdb;

    if (strlen($pattern) < 3 || preg_match('/[%_\'"]/', $pattern)) {
        return ['error' => __('Invalid pattern — must be at least 3 characters and contain no SQL wildcards.', 'brocode-utility-endpoints')];
    }

    $like = '%' . $wpdb->esc_like($pattern) . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_title, post_type, post_status, post_name
             FROM {$wpdb->posts}
             WHERE post_content LIKE %s
               AND post_status NOT IN ('trash','auto-draft')
               AND post_type NOT IN ('revision','nav_menu_item','wp_navigation')
             ORDER BY post_type, ID",
            $like
        ),
        ARRAY_A
    );

    return ['pattern' => $pattern, 'hits' => $rows ?? []];
}
