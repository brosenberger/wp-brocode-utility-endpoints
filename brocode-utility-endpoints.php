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
/**
 * Plugin Name: Brocode Utility Endpoints
 * Plugin URI:  https://github.com/brosenberger/wp-brocode-utility-endpoints
 * Description: Admin-authenticated REST endpoints and WP Abilities for site management — flush rewrites, clear cache, scan links, manage plugins, set Yoast SEO meta. Includes an admin UI and WP-CLI commands.
 * Version:     1.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Benjamin Rosenberger
 * Author URI:  https://brocode.at
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: brocode-utility-endpoints
 */

declare(strict_types=1);

namespace Brocode\UtilityEndpoints;

use WP_CLI;
use WP_CLI_Command;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

const ADMIN_HOOK = 'brocode_utility';
const SCAN_TRANSIENT_PREFIX = 'brocode_utility_scan_';

add_action('init', __NAMESPACE__ . '\\loadTextdomain');
add_action('init', __NAMESPACE__ . '\\registerCliCommands');
add_action('rest_api_init', __NAMESPACE__ . '\\registerRestRoutes');
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\registerAbilities');
add_action('admin_menu', __NAMESPACE__ . '\\registerAdminPage');
add_action('admin_post_' . ADMIN_HOOK . '_flush', __NAMESPACE__ . '\\handleAdminFlush');
add_action('admin_post_' . ADMIN_HOOK . '_clear_cache', __NAMESPACE__ . '\\handleAdminClearCache');
add_action('admin_post_' . ADMIN_HOOK . '_scan_links', __NAMESPACE__ . '\\handleAdminScanLinks');

/**
 * Compatibility shim — no-op on WP < 6.9 where wp_register_ability() does not exist.
 * Authoritative definition lives here; other brocode plugins copy the same 4-line guard
 * so each remains independently installable without a hard PHP dependency.
 */
if (!function_exists('brocode_register_ability')) {
    function brocode_register_ability(string $name, array $args): void
    {
        if (function_exists('wp_register_ability')) {
            wp_register_ability($name, $args);
        }
    }
}

function loadTextdomain(): void
{
    load_plugin_textdomain(
        'brocode-utility-endpoints',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}

// ---------------------------------------------------------------------------
// REST API
// ---------------------------------------------------------------------------

function registerRestRoutes(): void
{
    register_rest_route(
        'brocode/v1',
        '/flush-rewrites',
        [
            'methods'             => 'POST',
            'callback'            => __NAMESPACE__ . '\\flushRewrites',
            'permission_callback' => static fn() => current_user_can('manage_options'),
        ]
    );

    register_rest_route(
        'brocode/v1',
        '/clear-cache',
        [
            'methods'             => 'POST',
            'callback'            => __NAMESPACE__ . '\\clearCache',
            'permission_callback' => static fn() => current_user_can('manage_options'),
        ]
    );

    register_rest_route(
        'brocode/v1',
        '/scan-links',
        [
            'methods'             => 'GET',
            'callback'            => __NAMESPACE__ . '\\scanInternalLinks',
            'permission_callback' => static fn() => current_user_can('manage_options'),
            'args'                => [
                'pattern' => [
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => 'ddev.site',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]
    );

    register_rest_route(
        'brocode/v1',
        '/manage-plugin',
        [
            'methods'             => 'POST',
            'callback'            => __NAMESPACE__ . '\\managePlugin',
            'permission_callback' => static fn() => current_user_can('activate_plugins'),
            'args'                => [
                'plugin' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'action' => [
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => ['activate', 'deactivate', 'delete'],
                ],
            ],
        ]
    );

    register_rest_route(
        'brocode/v1',
        '/seo-meta/(?P<id>\d+)',
        [
            'methods'             => 'POST',
            'callback'            => __NAMESPACE__ . '\\updateSeoMeta',
            'permission_callback' => static function (WP_REST_Request $request): bool {
                return current_user_can('edit_post', (int) $request['id']);
            },
            'args'                => [
                'focuskw'  => ['required' => false, 'type' => 'string', 'maxLength' => 191],
                'title'    => ['required' => false, 'type' => 'string', 'maxLength' => 191],
                'metadesc' => ['required' => false, 'type' => 'string', 'maxLength' => 156],
            ],
        ]
    );
}

// ---------------------------------------------------------------------------
// WP Abilities (WP 6.9+)
// ---------------------------------------------------------------------------

function registerAbilities(): void
{
    brocode_register_ability('brocode/flush-rewrites', [
        'label'               => 'Flush rewrite rules',
        'description'         => 'Regenerates the WordPress permalink structure (flush_rewrite_rules). Run after registering new CPTs or changing permalink settings.',
        'type'                => 'action',
        'permission_callback' => static fn() => current_user_can('manage_options'),
        'callback'            => static fn(array $params): array => doFlushRewrites(),
        'input_schema'        => ['type' => 'object', 'properties' => []],
    ]);

    brocode_register_ability('brocode/clear-cache', [
        'label'               => 'Clear page cache',
        'description'         => 'Clears the active page cache (Cache Enabler, W3 Total Cache, or WP Super Cache). Returns which cache layers were cleared.',
        'type'                => 'action',
        'permission_callback' => static fn() => current_user_can('manage_options'),
        'callback'            => static fn(array $params): array => doClearCache(),
        'input_schema'        => ['type' => 'object', 'properties' => []],
    ]);

    brocode_register_ability('brocode/scan-links', [
        'label'               => 'Scan internal links',
        'description'         => 'Find all published posts and pages whose content contains a given URL pattern. Useful for detecting leftover localhost or staging URLs.',
        'type'                => 'action',
        'permission_callback' => static fn() => current_user_can('manage_options'),
        'callback'            => static function (array $params): array {
            return doScanLinks($params['pattern'] ?? 'ddev.site');
        },
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'pattern' => [
                    'type'        => 'string',
                    'description' => 'URL fragment to search for (minimum 3 characters; no SQL wildcards % \' ").',
                    'default'     => 'ddev.site',
                ],
            ],
        ],
    ]);

    brocode_register_ability('brocode/manage-plugin', [
        'label'               => 'Manage plugin',
        'description'         => 'Activate, deactivate, or delete a WordPress plugin by slug or basename. Delete also requires delete_plugins capability.',
        'type'                => 'action',
        'permission_callback' => static fn() => current_user_can('activate_plugins'),
        'callback'            => static function (array $params): array {
            $request = new WP_REST_Request('POST');
            $request->set_param('plugin', $params['plugin'] ?? '');
            $request->set_param('action', $params['action'] ?? '');
            $result = managePlugin($request);
            if ($result instanceof \WP_Error) {
                return ['error' => $result->get_error_message(), 'code' => $result->get_error_code()];
            }
            return $result->get_data();
        },
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'plugin' => ['type' => 'string', 'description' => 'Plugin slug ("akismet") or basename ("akismet/akismet.php").'],
                'action' => ['type' => 'string', 'enum' => ['activate', 'deactivate', 'delete']],
            ],
            'required'   => ['plugin', 'action'],
        ],
    ]);

    brocode_register_ability('brocode/set-seo-meta', [
        'label'               => 'Set Yoast SEO meta',
        'description'         => 'Write Yoast SEO focus keyword, title, and meta description for a post. Requires Yoast SEO to be active and edit_post permission on the target.',
        'type'                => 'action',
        'permission_callback' => static fn() => current_user_can('edit_posts'),
        'callback'            => static function (array $params): array {
            $postId = (int) ($params['post_id'] ?? 0);
            $request = new WP_REST_Request('POST');
            $request->set_url_params(['id' => (string) $postId]);
            foreach (['focuskw', 'title', 'metadesc'] as $field) {
                if (isset($params[$field])) {
                    $request->set_param($field, $params[$field]);
                }
            }
            $result = updateSeoMeta($request);
            if ($result instanceof \WP_Error) {
                return ['error' => $result->get_error_message(), 'code' => $result->get_error_code()];
            }
            return $result->get_data();
        },
        'input_schema'        => [
            'type'       => 'object',
            'properties' => [
                'post_id'  => ['type' => 'integer', 'description' => 'ID of the post or page to update.'],
                'focuskw'  => ['type' => 'string', 'description' => 'Yoast focus keyword (max 191 chars).'],
                'title'    => ['type' => 'string', 'description' => 'Yoast SEO title override (max 191 chars).'],
                'metadesc' => ['type' => 'string', 'description' => 'Yoast meta description (max 156 chars).'],
            ],
            'required'   => ['post_id'],
        ],
    ]);
}

// ---------------------------------------------------------------------------
// Core logic (shared by REST, admin handlers, and WP-CLI)
// ---------------------------------------------------------------------------

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

// ---------------------------------------------------------------------------
// REST callbacks (thin wrappers around core logic)
// ---------------------------------------------------------------------------

function flushRewrites(): WP_REST_Response
{
    return new WP_REST_Response(doFlushRewrites(), 200);
}

function clearCache(): WP_REST_Response
{
    return new WP_REST_Response(doClearCache(), 200);
}

function scanInternalLinks(WP_REST_Request $request): WP_REST_Response
{
    $result = doScanLinks((string) $request->get_param('pattern'));
    $status  = isset($result['error']) ? 400 : 200;
    return new WP_REST_Response($result, $status);
}

/**
 * Activate / deactivate / delete a plugin via core WP-Admin functions, so prod
 * (no WP-CLI) can be managed through the same admin-authenticated REST channel
 * the MCP uses. `delete` removes the plugin files via WP_Filesystem.
 */
function managePlugin(WP_REST_Request $request): WP_REST_Response|\WP_Error
{
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $action = (string) $request->get_param('action');
    $plugin = resolvePluginBasename(ltrim((string) $request->get_param('plugin'), '/'));

    if (!array_key_exists($plugin, get_plugins())) {
        return new \WP_Error('brocode_plugin_not_found', 'Plugin not installed: ' . $plugin, ['status' => 404]);
    }

    if ($plugin === plugin_basename(__FILE__) && $action !== 'activate') {
        return new \WP_Error('brocode_plugin_self', 'Refusing to ' . $action . ' the host plugin.', ['status' => 400]);
    }

    if ($action === 'activate') {
        $result = activate_plugin($plugin);
        if (is_wp_error($result)) {
            return new \WP_Error('brocode_plugin_activate_failed', $result->get_error_message(), ['status' => 500]);
        }
        return new WP_REST_Response(['plugin' => $plugin, 'action' => 'activate', 'active' => is_plugin_active($plugin)], 200);
    }

    if ($action === 'deactivate') {
        deactivate_plugins($plugin);
        return new WP_REST_Response(['plugin' => $plugin, 'action' => 'deactivate', 'active' => is_plugin_active($plugin)], 200);
    }

    return deletePlugin($plugin);
}

/**
 * @return WP_REST_Response|\WP_Error
 */
function deletePlugin(string $plugin)
{
    if (!current_user_can('delete_plugins')) {
        return new \WP_Error('brocode_forbidden', 'Not allowed to delete plugins.', ['status' => 403]);
    }
    if (is_plugin_active($plugin)) {
        deactivate_plugins($plugin);
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();

    $result = delete_plugins([$plugin]);
    if (is_wp_error($result)) {
        return new \WP_Error('brocode_plugin_delete_failed', $result->get_error_message(), ['status' => 500]);
    }
    if ($result === null) {
        return new \WP_Error('brocode_plugin_delete_fs', 'Filesystem credentials required; could not delete files.', ['status' => 500]);
    }

    return new WP_REST_Response(['plugin' => $plugin, 'action' => 'delete', 'deleted' => true], 200);
}

/**
 * Resolve a bare slug ("foo") to its main plugin file ("foo/foo.php"); pass
 * through values that already include a directory separator.
 */
function resolvePluginBasename(string $plugin): string
{
    if (str_contains($plugin, '/')) {
        return $plugin;
    }
    foreach (array_keys(get_plugins()) as $file) {
        if (strpos($file, $plugin . '/') === 0) {
            return $file;
        }
    }
    return $plugin;
}

function updateSeoMeta(WP_REST_Request $request): WP_REST_Response|\WP_Error
{
    if (!defined('WPSEO_VERSION')) {
        return new \WP_Error(
            'brocode_yoast_not_active',
            'Yoast SEO is not active; SEO meta cannot be written.',
            ['status' => 503]
        );
    }

    $postId = (int) $request['id'];
    if ($postId <= 0) {
        return new \WP_Error('brocode_invalid_post_id', 'Invalid post id.', ['status' => 400]);
    }

    if (!get_post($postId)) {
        return new \WP_Error('brocode_post_not_found', 'Post not found.', ['status' => 404]);
    }

    if (!current_user_can('edit_post', $postId)) {
        return new \WP_Error('brocode_forbidden', 'Not allowed to edit this post.', ['status' => 403]);
    }

    $metaMap = [
        'focuskw'  => ['key' => '_yoast_wpseo_focuskw', 'sanitizer' => 'sanitize_text_field', 'max' => 191],
        'title'    => ['key' => '_yoast_wpseo_title', 'sanitizer' => 'sanitize_text_field', 'max' => 191],
        'metadesc' => ['key' => '_yoast_wpseo_metadesc', 'sanitizer' => 'sanitize_textarea_field', 'max' => 156],
    ];

    $updated = [];
    foreach ($metaMap as $field => $config) {
        if (!$request->has_param($field)) {
            continue;
        }
        $rawValue = (string) $request->get_param($field);
        $sanitizer = $config['sanitizer'];
        $value = $sanitizer($rawValue);
        if (mb_strlen($value) > $config['max']) {
            $value = mb_substr($value, 0, $config['max']);
        }
        update_post_meta($postId, $config['key'], $value);
        $updated[$field] = $value;
    }

    if ($updated === []) {
        return new \WP_Error('brocode_missing_fields', 'At least one SEO field is required.', ['status' => 400]);
    }

    return new WP_REST_Response(['id' => $postId, 'updated' => $updated], 200);
}

// ---------------------------------------------------------------------------
// Admin UI
// ---------------------------------------------------------------------------

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

    $result = doClearCache();
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

    $transientKey = SCAN_TRANSIENT_PREFIX . get_current_user_id();
    set_transient($transientKey, $result, 60);

    wp_safe_redirect(adminPageUrl(['brocode_scanned' => '1']));
    exit;
}

function renderAdminPage(): void
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    $flushed    = !empty($_GET['brocode_flushed']);
    $cleared    = isset($_GET['brocode_cleared']) ? sanitize_text_field((string) $_GET['brocode_cleared']) : null;
    $scanned    = !empty($_GET['brocode_scanned']);
    // phpcs:enable

    $transientKey = SCAN_TRANSIENT_PREFIX . get_current_user_id();
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
            <input type="hidden" name="action" value="<?php echo esc_attr(ADMIN_HOOK . '_flush'); ?>">
            <?php wp_nonce_field('brocode_utility_flush'); ?>
            <?php submit_button(__('Flush rewrite rules', 'brocode-utility-endpoints'), 'secondary', 'submit', false); ?>
        </form>

        <hr>

        <h2><?php esc_html_e('Clear Page Cache', 'brocode-utility-endpoints'); ?></h2>
        <p><?php esc_html_e('Clears the full page cache for any active cache plugin (Cache Enabler, W3 Total Cache, WP Super Cache). Always run this after deploying theme or plugin changes.', 'brocode-utility-endpoints'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(ADMIN_HOOK . '_clear_cache'); ?>">
            <?php wp_nonce_field('brocode_utility_clear_cache'); ?>
            <?php submit_button(__('Clear page cache', 'brocode-utility-endpoints'), 'secondary', 'submit', false); ?>
        </form>

        <hr>

        <h2><?php esc_html_e('Scan Internal Links', 'brocode-utility-endpoints'); ?></h2>
        <p><?php esc_html_e('Search all published post content for a URL pattern. Useful for finding leftover localhost or staging URLs before they reach production.', 'brocode-utility-endpoints'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(ADMIN_HOOK . '_scan_links'); ?>">
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

// ---------------------------------------------------------------------------
// WP-CLI
// ---------------------------------------------------------------------------

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
            WP_CLI\Utils\format_items('table', $hits, $fields);
        }
    });
}
