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
        '/indexnow-status',
        [
            'methods'             => 'GET',
            'callback'            => __NAMESPACE__ . '\\indexNowStatus',
            'permission_callback' => static fn() => current_user_can('manage_options'),
        ]
    );

    register_rest_route(
        'brocode/v1',
        '/llms-hits',
        [
            'methods'             => 'GET',
            'callback'            => __NAMESPACE__ . '\\llmsHits',
            'permission_callback' => static fn() => current_user_can('manage_options'),
            'args'                => [
                'days'  => [
                    'type'    => 'integer',
                    'default' => 90,
                    'minimum' => 1,
                    'maximum' => 3650,
                ],
                'limit' => [
                    'type'    => 'integer',
                    'default' => 50,
                    'minimum' => 0,
                    'maximum' => 500,
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
                        'metadesc-author-wpseo',
                        'title-author-wpseo',
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
        '/seo-term-meta',
        [
            'methods'             => 'POST',
            'callback'            => __NAMESPACE__ . '\\updateSeoTermMeta',
            'permission_callback' => static fn() => current_user_can('manage_options'),
            'args'                => [
                'taxonomy' => [
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => ['category', 'post_tag', 'brocode_topic'],
                ],
                'term_id'  => [
                    'required' => true,
                    'type'     => 'integer',
                    'minimum'  => 1,
                ],
                'metadesc' => ['required' => false, 'type' => 'string', 'maxLength' => 156],
                'title'    => ['required' => false, 'type' => 'string', 'maxLength' => 191],
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
 * Reads back what `brocode-indexnow` recorded for its last submission.
 *
 * The mu-plugin parks the outcome in an option precisely because production has
 * no shell - and that left the record unreadable on the one environment whose
 * submissions matter, which is the same shape of mistake as minting the key into
 * the database. This is the missing read path: `code` 200 or 202 means the
 * engines accepted the batch, `error` means the POST never completed, and a null
 * `last` means no submission has ever been recorded on this site.
 */
function indexNowStatus(): WP_REST_Response|\WP_Error
{
    if (!defined('Brocode\\IndexNow\\STATUS_OPTION')) {
        return new \WP_Error(
            'brocode_indexnow_missing',
            'brocode-indexnow is not loaded on this site.',
            ['status' => 404]
        );
    }

    return new WP_REST_Response(
        ['last' => get_option(constant('Brocode\\IndexNow\\STATUS_OPTION'), null)],
        200
    );
}

/**
 * Reads back the llms.txt hit log the brocode-llms-hits mu-plugin collects.
 *
 * Same shape and reason as indexNowStatus() above: production has no shell and
 * no access log of its own, so the only way to see this data is to have the
 * application hand it over through the channel the MCP already authenticates
 * on. The summary is the answer to "does anything read llms.txt"; the sample
 * rows are there so a surprising summary can be checked rather than believed.
 */
function llmsHits(WP_REST_Request $request): WP_REST_Response|\WP_Error
{
    if (!defined('Brocode\\LlmsHits\\SCHEMA_VERSION')) {
        return new \WP_Error(
            'brocode_llms_hits_missing',
            'brocode-llms-hits is not loaded on this site.',
            ['status' => 404]
        );
    }

    global $wpdb;

    $table = \Brocode\LlmsHits\tableName();

    // esc_like matters here: the table name contains underscores, and an
    // unescaped _ is a single-character LIKE wildcard (B.19).
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
        return new \WP_Error(
            'brocode_llms_hits_no_table',
            'brocode-llms-hits is loaded but its table does not exist yet; it is created on the first init after install.',
            ['status' => 503]
        );
    }

    $days  = (int) $request->get_param('days');
    $limit = (int) $request->get_param('limit');
    $since = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

    $byAgent = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT agent, kind, COUNT(*) AS hits, MIN(hit_at) AS first_seen, MAX(hit_at) AS last_seen
             FROM %i WHERE hit_at >= %s GROUP BY agent, kind ORDER BY hits DESC',
            $table,
            $since
        ),
        ARRAY_A
    );

    $totals = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT COUNT(*) AS hits, COUNT(DISTINCT agent) AS agents, MIN(hit_at) AS first_seen, MAX(hit_at) AS last_seen
             FROM %i WHERE hit_at >= %s',
            $table,
            $since
        ),
        ARRAY_A
    );

    $recent = [];
    if ($limit > 0) {
        $recent = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT hit_at, kind, path, agent, status, user_agent
                 FROM %i WHERE hit_at >= %s ORDER BY hit_at DESC LIMIT %d',
                $table,
                $since,
                $limit
            ),
            ARRAY_A
        );
    }

    // collecting_since is the whole log, not the window, so a caller can tell
    // "nothing asked in 90 days" apart from "this has only been running a week"
    // - which is the difference between a finding and a premature conclusion.
    return new WP_REST_Response(
        [
            'window_days'     => $days,
            'since'           => $since,
            'collecting_since' => $wpdb->get_var($wpdb->prepare('SELECT MIN(hit_at) FROM %i', $table)),
            'totals'          => $totals,
            'by_agent'        => $byAgent,
            'recent'          => $recent,
        ],
        200
    );
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

function updateSeoTermMeta(WP_REST_Request $request): WP_REST_Response|\WP_Error
{
    if (!defined('WPSEO_VERSION') || !class_exists('WPSEO_Taxonomy_Meta')) {
        return new \WP_Error('brocode_yoast_not_active', 'Yoast SEO is not active.', ['status' => 503]);
    }

    $taxonomy = (string) $request->get_param('taxonomy');
    $termId   = (int) $request->get_param('term_id');

    if (!get_term($termId, $taxonomy)) {
        return new \WP_Error('brocode_term_not_found', "Term {$termId} not found in {$taxonomy}.", ['status' => 404]);
    }

    $fieldMap = [
        'metadesc' => ['yoast_key' => 'wpseo_desc', 'max' => 156],
        'title'    => ['yoast_key' => 'wpseo_title', 'max' => 191],
    ];

    $updated = [];
    foreach ($fieldMap as $field => $config) {
        if (!$request->has_param($field)) {
            continue;
        }
        $value = mb_substr(sanitize_text_field((string) $request->get_param($field)), 0, $config['max']);
        // Use Yoast's own API so its sanitize_option filter and option_filter hooks run correctly.
        \WPSEO_Taxonomy_Meta::set_value($termId, $taxonomy, $config['yoast_key'], $value);
        $updated[$field] = $value;
    }

    if ($updated === []) {
        return new \WP_Error('brocode_missing_fields', 'At least one SEO field (metadesc, title) is required.', ['status' => 400]);
    }

    // Rebuild the Yoast indexable so the new meta description is reflected in page output.
    // Yoast uses a cached indexable table; without this, the <meta description> won't appear.
    $termObj = get_term($termId, $taxonomy);
    if ($termObj && !is_wp_error($termObj)) {
        do_action('edited_term', $termId, $termObj->term_taxonomy_id, $taxonomy);
    }

    return new WP_REST_Response(['taxonomy' => $taxonomy, 'term_id' => $termId, 'updated' => $updated], 200);
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
