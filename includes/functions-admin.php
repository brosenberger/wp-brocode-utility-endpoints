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

function registerAdminPage(): void
{
    add_options_page(
        __('Brocode Utility Endpoints', 'brocode-utility-endpoints'),
        __('Brocode Utilities', 'brocode-utility-endpoints'),
        'manage_options',
        'brocode-utility-endpoints',
        __NAMESPACE__ . '\\renderAdminPage'
    );
}

function adminPageUrl(array $extra = []): string
{
    return add_query_arg(
        array_merge(['page' => 'brocode-utility-endpoints'], $extra),
        admin_url('options-general.php')
    );
}

function handleAdminFlush(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'brocode-utility-endpoints'));
    }
    check_admin_referer('brocode_utility_flush');

    doFlushRewrites();
    wp_safe_redirect(adminPageUrl(['brocode_flushed' => '1']));
    exit;
}

function handleAdminClearCache(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'brocode-utility-endpoints'));
    }
    check_admin_referer('brocode_utility_clear_cache');

    $result  = doClearCache();
    $cleared = implode(',', $result['cleared']);
    wp_safe_redirect(adminPageUrl(['brocode_cleared' => $cleared !== '' ? $cleared : 'none']));
    exit;
}

function handleAdminScanLinks(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'brocode-utility-endpoints'));
    }
    check_admin_referer('brocode_utility_scan_links');

    $pattern = sanitize_text_field((string) ($_POST['pattern'] ?? 'ddev.site'));
    $result  = doScanLinks($pattern);

    $transientKey = BUE_SCAN_TRANSIENT_PREFIX . get_current_user_id();
    set_transient($transientKey, $result, 60);

    wp_safe_redirect(adminPageUrl(['brocode_scanned' => '1']));
    exit;
}

function renderAdminPage(): void
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $flushed = !empty($_GET['brocode_flushed']);
    $cleared = isset($_GET['brocode_cleared']) ? sanitize_text_field((string) $_GET['brocode_cleared']) : null;
    $scanned = !empty($_GET['brocode_scanned']);
    // phpcs:enable

    $transientKey = BUE_SCAN_TRANSIENT_PREFIX . get_current_user_id();
    $scanResult   = $scanned ? get_transient($transientKey) : false;
    if ($scanResult !== false) {
        delete_transient($transientKey);
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Brocode Utility Endpoints', 'brocode-utility-endpoints'); ?></h1>

        <?php if ($flushed) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e('Rewrite rules flushed successfully.', 'brocode-utility-endpoints'); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($cleared !== null) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                <?php
                if ($cleared === 'none') {
                    esc_html_e('No active page cache plugins found — nothing to clear.', 'brocode-utility-endpoints');
                } else {
                    echo esc_html(sprintf(
                        /* translators: %s: comma-separated list of cleared cache names */
                        __('Page cache cleared: %s.', 'brocode-utility-endpoints'),
                        str_replace(',', ', ', $cleared)
                    ));
                }
                ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if (is_array($scanResult) && isset($scanResult['error'])) : ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html($scanResult['error']); ?></p>
            </div>
        <?php endif; ?>

        <h2><?php esc_html_e('Flush Rewrite Rules', 'brocode-utility-endpoints'); ?></h2>
        <p><?php esc_html_e('Regenerates the WordPress permalink structure. Run after adding or renaming custom post types, taxonomies, or changing permalink settings.', 'brocode-utility-endpoints'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(BUE_ADMIN_HOOK . '_flush'); ?>">
            <?php wp_nonce_field('brocode_utility_flush'); ?>
            <?php submit_button(__('Flush rewrite rules', 'brocode-utility-endpoints'), 'secondary', 'submit', false); ?>
        </form>

        <hr>

        <h2><?php esc_html_e('Clear Page Cache', 'brocode-utility-endpoints'); ?></h2>
        <p><?php esc_html_e('Clears the full page cache for any active cache plugin (Cache Enabler, W3 Total Cache, WP Super Cache). Always run this after deploying theme or plugin changes.', 'brocode-utility-endpoints'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(BUE_ADMIN_HOOK . '_clear_cache'); ?>">
            <?php wp_nonce_field('brocode_utility_clear_cache'); ?>
            <?php submit_button(__('Clear page cache', 'brocode-utility-endpoints'), 'secondary', 'submit', false); ?>
        </form>

        <hr>

        <h2><?php esc_html_e('Scan Internal Links', 'brocode-utility-endpoints'); ?></h2>
        <p><?php esc_html_e('Search all published post content for a URL pattern. Useful for finding leftover localhost or staging URLs before they reach production.', 'brocode-utility-endpoints'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(BUE_ADMIN_HOOK . '_scan_links'); ?>">
            <?php wp_nonce_field('brocode_utility_scan_links'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="brocode-scan-pattern"><?php esc_html_e('URL pattern', 'brocode-utility-endpoints'); ?></label></th>
                    <td>
                        <input
                            type="text"
                            id="brocode-scan-pattern"
                            name="pattern"
                            value="<?php echo esc_attr(is_array($scanResult) && isset($scanResult['pattern']) ? $scanResult['pattern'] : 'ddev.site'); ?>"
                            class="regular-text"
                        >
                        <p class="description"><?php esc_html_e('Minimum 3 characters. No SQL wildcards (%, \', ").', 'brocode-utility-endpoints'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Scan', 'brocode-utility-endpoints'), 'secondary', 'submit', false); ?>
        </form>

        <?php if (is_array($scanResult) && isset($scanResult['hits'])) : ?>
            <?php $hits = $scanResult['hits']; ?>
            <?php if ($hits === []) : ?>
                <div class="notice notice-success is-dismissible" style="margin-top:1em">
                    <p><?php echo esc_html(sprintf(
                        /* translators: %s: the search pattern */
                        __('No posts found containing "%s".', 'brocode-utility-endpoints'),
                        $scanResult['pattern']
                    )); ?></p>
                </div>
            <?php else : ?>
                <div class="notice notice-warning" style="margin-top:1em">
                    <p><?php echo esc_html(sprintf(
                        /* translators: 1: hit count, 2: pattern */
                        __('%1$d post(s) found containing "%2$s":', 'brocode-utility-endpoints'),
                        count($hits),
                        $scanResult['pattern']
                    )); ?></p>
                </div>
                <table class="widefat striped" style="max-width:900px">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'brocode-utility-endpoints'); ?></th>
                            <th><?php esc_html_e('Title', 'brocode-utility-endpoints'); ?></th>
                            <th><?php esc_html_e('Post type', 'brocode-utility-endpoints'); ?></th>
                            <th><?php esc_html_e('Status', 'brocode-utility-endpoints'); ?></th>
                            <th><?php esc_html_e('Slug', 'brocode-utility-endpoints'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hits as $hit) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $hit['ID']); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $hit['ID'])); ?>">
                                        <?php echo esc_html($hit['post_title']); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($hit['post_type']); ?></td>
                                <td><?php echo esc_html($hit['post_status']); ?></td>
                                <td><?php echo esc_html($hit['post_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>

        <hr>

        <h2><?php esc_html_e('REST API Reference', 'brocode-utility-endpoints'); ?></h2>
        <p><?php esc_html_e('All endpoints require admin authentication (Application Password recommended). Two endpoints are MCP/programmatic only — no admin UI.', 'brocode-utility-endpoints'); ?></p>
        <table class="widefat striped" style="max-width:900px">
            <thead>
                <tr>
                    <th><?php esc_html_e('Method', 'brocode-utility-endpoints'); ?></th>
                    <th><?php esc_html_e('Endpoint', 'brocode-utility-endpoints'); ?></th>
                    <th><?php esc_html_e('Description', 'brocode-utility-endpoints'); ?></th>
                    <th><?php esc_html_e('Capability', 'brocode-utility-endpoints'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $endpoints = [
                    ['POST', '/brocode/v1/flush-rewrites', __('Flush rewrite rules', 'brocode-utility-endpoints'), 'manage_options'],
                    ['POST', '/brocode/v1/clear-cache', __('Clear page cache', 'brocode-utility-endpoints'), 'manage_options'],
                    ['GET',  '/brocode/v1/scan-links?pattern=…', __('Scan post content for URL pattern', 'brocode-utility-endpoints'), 'manage_options'],
                    ['GET',  '/brocode/v1/llms-hits?days=…&limit=…', __('Read the llms.txt / .md endpoint hit log', 'brocode-utility-endpoints'), 'manage_options'],
                    ['POST', '/brocode/v1/manage-plugin', __('Activate / deactivate / delete a plugin', 'brocode-utility-endpoints'), 'activate_plugins'],
                    ['POST', '/brocode/v1/seo-meta/{id}', __('Set Yoast SEO focus keyword, title, meta description', 'brocode-utility-endpoints'), 'edit_post'],
                ];
                foreach ($endpoints as [$method, $path, $desc, $cap]) : ?>
                    <tr>
                        <td><code><?php echo esc_html($method); ?></code></td>
                        <td><code><?php echo esc_html($path); ?></code></td>
                        <td><?php echo esc_html($desc); ?></td>
                        <td><code><?php echo esc_html($cap); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
