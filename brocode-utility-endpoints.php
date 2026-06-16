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
 * Description: Admin-authenticated REST endpoints and WP Abilities for site management — flush rewrites, clear cache, scan links, manage plugins, set Yoast SEO meta.
 * Version:     1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Benjamin Rosenberger
 * Author URI:  https://brocode.at
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 */

declare(strict_types=1);

namespace Brocode\UtilityEndpoints;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', __NAMESPACE__ . '\\registerRestRoutes');
add_action('init', __NAMESPACE__ . '\\registerAbilities');

/**
 * Compatibility shim — no-op on WP < 6.9 where wp_register_ability() does not exist.
 * Authoritative definition lives here; image-optimizer and future plugins copy the
 * same 4-line guard so each plugin remains independently installable without a
 * hard PHP dependency just for the shim.
 */
if (!function_exists('brocode_register_ability')) {
    function brocode_register_ability(string $name, array $args): void
    {
        if (function_exists('wp_register_ability')) {
            wp_register_ability($name, $args);
        }
    }
}

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
                'focuskw' => ['required' => false, 'type' => 'string', 'maxLength' => 191],
                'title'   => ['required' => false, 'type' => 'string', 'maxLength' => 191],
                'metadesc' => ['required' => false, 'type' => 'string', 'maxLength' => 156],
            ],
        ]
    );
}

function registerAbilities(): void
{
    brocode_register_ability('brocode/flush-rewrites', [
        'label'               => 'Flush rewrite rules',
        'description'         => 'Regenerates the WordPress permalink structure (flush_rewrite_rules). Run after registering new CPTs or changing permalink settings.',
        'type'                => 'action',
        'permission_callback' => static fn() => current_user_can('manage_options'),
        'callback'            => static fn(array $params): array => flushRewrites()->get_data(),
        'input_schema'        => ['type' => 'object', 'properties' => []],
    ]);

    brocode_register_ability('brocode/clear-cache', [
        'label'               => 'Clear page cache',
        'description'         => 'Clears the active page cache (Cache Enabler, W3 Total Cache, or WP Super Cache). Returns which cache layers were cleared.',
        'type'                => 'action',
        'permission_callback' => static fn() => current_user_can('manage_options'),
        'callback'            => static fn(array $params): array => clearCache()->get_data(),
        'input_schema'        => ['type' => 'object', 'properties' => []],
    ]);

    brocode_register_ability('brocode/scan-links', [
        'label'               => 'Scan internal links',
        'description'         => 'Find all published posts and pages whose content contains a given URL pattern. Useful for detecting leftover localhost or staging URLs.',
        'type'                => 'action',
        'permission_callback' => static fn() => current_user_can('manage_options'),
        'callback'            => static function (array $params): array {
            $request = new WP_REST_Request('GET');
            $request->set_param('pattern', $params['pattern'] ?? 'ddev.site');
            return scanInternalLinks($request)->get_data();
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

function flushRewrites(): WP_REST_Response
{
    flush_rewrite_rules(false);
    return new WP_REST_Response(['flushed' => true], 200);
}

function clearCache(): WP_REST_Response
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

    return new WP_REST_Response(['cleared' => $cleared, 'none' => $cleared === []], 200);
}

function scanInternalLinks(WP_REST_Request $request): WP_REST_Response
{
    global $wpdb;
    $pattern = $request->get_param('pattern');
    if (strlen($pattern) < 3 || preg_match('/[%_\'"]/', $pattern)) {
        return new WP_REST_Response(['error' => 'Invalid pattern.'], 400);
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

    return new WP_REST_Response(['pattern' => $pattern, 'hits' => $rows ?? []], 200);
}

/**
 * Activate / deactivate / delete a plugin via core WP-Admin functions, so prod
 * (no WP-CLI) can be managed through the same admin-authenticated REST channel
 * the MCP uses. `delete` removes the plugin files via WP_Filesystem — this is
 * how stale plugin directories left behind by a rename are pruned on prod.
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
