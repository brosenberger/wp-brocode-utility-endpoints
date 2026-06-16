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

use WP_CLI;
use WP_CLI_Command;

if (!defined('ABSPATH')) {
    exit;
}

function registerCliCommands(): void
{
    if (!class_exists('WP_CLI')) {
        return;
    }

    WP_CLI::add_command('brocode-utility', new class extends WP_CLI_Command {
        /**
         * Flush WordPress rewrite rules.
         *
         * ## EXAMPLES
         *
         *     wp brocode-utility flush-rewrites
         *
         * @subcommand flush-rewrites
         * @param array<int,string>   $args
         * @param array<string,mixed> $assocArgs
         */
        public function flush_rewrites(array $args, array $assocArgs): void
        {
            doFlushRewrites();
            WP_CLI::success(__('Rewrite rules flushed.', 'brocode-utility-endpoints'));
        }

        /**
         * Clear the active page cache.
         *
         * ## EXAMPLES
         *
         *     wp brocode-utility clear-cache
         *
         * @subcommand clear-cache
         * @param array<int,string>   $args
         * @param array<string,mixed> $assocArgs
         */
        public function clear_cache(array $args, array $assocArgs): void
        {
            $result = doClearCache();
            if ($result['none']) {
                WP_CLI::warning(__('No active page cache plugins found.', 'brocode-utility-endpoints'));
                return;
            }
            WP_CLI::success(sprintf(
                /* translators: %s: list of cleared cache names */
                __('Cache cleared: %s.', 'brocode-utility-endpoints'),
                implode(', ', $result['cleared'])
            ));
        }

        /**
         * Scan post content for a URL pattern.
         *
         * ## OPTIONS
         *
         * [--pattern=<pattern>]
         * : URL fragment to search for (default: ddev.site).
         *
         * ## EXAMPLES
         *
         *     wp brocode-utility scan-links
         *     wp brocode-utility scan-links --pattern=localhost
         *
         * @subcommand scan-links
         * @param array<int,string>   $args
         * @param array<string,mixed> $assocArgs
         */
        public function scan_links(array $args, array $assocArgs): void
        {
            $pattern = $assocArgs['pattern'] ?? 'ddev.site';
            $result  = doScanLinks($pattern);

            if (isset($result['error'])) {
                WP_CLI::error($result['error']);
                return;
            }

            $hits = $result['hits'];
            if ($hits === []) {
                WP_CLI::success(sprintf(
                    /* translators: %s: the search pattern */
                    __('No posts found containing "%s".', 'brocode-utility-endpoints'),
                    $pattern
                ));
                return;
            }

            WP_CLI::warning(sprintf(
                /* translators: 1: hit count, 2: pattern */
                __('%1$d post(s) found containing "%2$s":', 'brocode-utility-endpoints'),
                count($hits),
                $pattern
            ));

            $fields = ['ID', 'post_title', 'post_type', 'post_status', 'post_name'];
            \WP_CLI\Utils\format_items('table', $hits, $fields);
        }
    });
}
