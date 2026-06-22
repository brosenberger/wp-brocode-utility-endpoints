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

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
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
        '/seo-archive-option',
        [
            'methods'             => 'POST',
            'callback'            => __NAMESPACE__ . '\\updateSeoArchiveOption',
            'permission_callback' => static fn() => current_user_can('manage_options'),
            'args'                => [
                'key'   => [
                    'required'          => true,
                    'type'              => 'string',
                    'enum'              => [
                        'metadesc-ptarchive-brocode_repo',
                        'title-ptarchive-brocode_repo',
                        'metadesc-ptarchive-post',
                        'title-ptarchive-post',
                    ],
                    'sanitize_callback' => 'sanitize_key',
                ],
                'value' => [
                    'required'          => true,
                    'type'              => 'string',
                    'maxLength'         => 320,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]
    );

    register_rest_route(
        'brocode/v1',
        '/post-meta/(?P<id>\d+)/(?P<key>[a-z_]+)',
        [
            'methods'             => 'DELETE',
            'callback'            => __NAMESPACE__ . '\\deletePostMeta',
            'permission_callback' => static function (WP_REST_Request $request): bool {
                return current_user_can('edit_post', (int) $request['id']);
            },
            'args'                => [
                'id'  => ['required' => true, 'type' => 'integer'],
                'key' => [
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => ['featured'],
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

    if ($plugin === plugin_basename(BUE_PLUGIN_FILE) && $action !== 'activate') {
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

/** @return WP_REST_Response|\WP_Error */
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
        $rawValue  = (string) $request->get_param($field);
        $sanitizer = $config['sanitizer'];
        $value     = $sanitizer($rawValue);
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

function deletePostMeta(WP_REST_Request $request): WP_REST_Response|\WP_Error
{
    $postId = (int) $request['id'];
    $key    = sanitize_key((string) $request['key']);

    if (!get_post($postId)) {
        return new \WP_Error('brocode_post_not_found', 'Post not found.', ['status' => 404]);
    }

    delete_post_meta($postId, $key);

    return new WP_REST_Response(['id' => $postId, 'key' => $key, 'deleted' => true], 200);
}

function updateSeoArchiveOption(WP_REST_Request $request): WP_REST_Response|\WP_Error
{
    if (!defined('WPSEO_VERSION')) {
        return new \WP_Error('brocode_yoast_not_active', 'Yoast SEO is not active.', ['status' => 503]);
    }

    $key   = (string) $request->get_param('key');
    $value = mb_substr((string) $request->get_param('value'), 0, 320);

    $titles         = (array) get_option('wpseo_titles', []);
    $titles[$key]   = $value;
    update_option('wpseo_titles', $titles);

    return new WP_REST_Response(['key' => $key, 'value' => $value], 200);
}
