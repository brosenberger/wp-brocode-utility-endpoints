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

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compatibility shim — no-op on WP < 6.9 where wp_register_ability() does not exist.
 * Guarded by function_exists() so each brocode plugin can include its own copy without
 * causing a fatal error; the first definition loaded wins (all copies are identical).
 */
if (!function_exists('brocode_register_ability')) {
    function brocode_register_ability(string $name, array $args): void
    {
        if (function_exists('wp_register_ability')) {
            wp_register_ability($name, $args);
        }
    }
}

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
