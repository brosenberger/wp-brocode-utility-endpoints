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

use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Content sync: export a post in an environment-neutral form, import it into another
 * environment, and make sure the media it references exists there.
 *
 * Neutral form: the site URL becomes {{home}}; attachment IDs stay as they are in the source
 * but travel with a `media` map (ID → uploads-relative path), so the importer can translate
 * them to the target's IDs; post references in meta (declared through the
 * `brocode_content_sync_post_refs` filter) and page parents travel as slugs.
 */

const SYNC_MEDIA_BLOCKS = ['core/image', 'core/cover', 'core/video', 'core/audio', 'core/file', 'core/media-text', 'core/gallery'];

function registerContentSyncRoutes(): void
{
    register_rest_route('brocode/v1', '/content-export', [
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\contentExport',
        'permission_callback' => static fn() => current_user_can('edit_others_posts'),
        'args'                => [
            'type'    => ['required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key'],
            'slug'    => ['required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_title'],
            'summary' => ['required' => false, 'type' => 'boolean', 'default' => false],
        ],
    ]);
    register_rest_route('brocode/v1', '/content-import', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\contentImport',
        'permission_callback' => static fn() => current_user_can('edit_others_posts'),
    ]);
    register_rest_route('brocode/v1', '/media-ensure', [
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\mediaEnsure',
        'permission_callback' => static fn() => current_user_can('upload_files'),
        'args'                => [
            'path' => ['required' => true, 'type' => 'string'],
        ],
    ]);
}

function contentExport(WP_REST_Request $request): WP_REST_Response|\WP_Error
{
    $type = (string) $request['type'];
    if (!post_type_exists($type)) {
        return new \WP_Error('brocode_unknown_type', 'Unknown post type.', ['status' => 400]);
    }
    $query = [
        'post_type'        => $type,
        'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
        'numberposts'      => -1,
        'orderby'          => 'ID',
        'order'            => 'ASC',
        'suppress_filters' => true,
    ];
    if ((string) $request['slug'] !== '') {
        $query['name'] = (string) $request['slug'];
    }
    $summary = (bool) $request['summary'];
    $items   = array_map(
        static fn(WP_Post $post): array => $summary ? syncSummary($post) : syncExportPost($post),
        get_posts($query)
    );

    return new WP_REST_Response([
        'home'         => untrailingslashit(home_url()),
        'uploads_base' => untrailingslashit((string) wp_get_upload_dir()['baseurl']),
        'items'        => $items,
    ], 200);
}

/**
 * @return array<string, mixed>
 */
function syncSummary(WP_Post $post): array
{
    return ['id' => $post->ID, 'slug' => $post->post_name, 'status' => $post->post_status, 'modified_gmt' => $post->post_modified_gmt];
}

/**
 * @return array<string, mixed>
 */
function syncExportPost(WP_Post $post): array
{
    $content = (string) $post->post_content;
    $thumb   = (int) get_post_thumbnail_id($post);
    $ids     = syncReferencedAttachments($content);
    if ($thumb > 0) {
        $ids[] = $thumb;
    }

    return syncSummary($post) + [
        'type'       => $post->post_type,
        'title'      => $post->post_title,
        'date'       => $post->post_date,
        'parent'     => $post->post_parent > 0 ? get_page_uri($post->post_parent) : '',
        'menu_order' => (int) $post->menu_order,
        'template'   => (string) get_page_template_slug($post),
        'excerpt'    => $post->post_excerpt,
        'featured'   => $thumb > 0 ? (string) get_post_meta($thumb, '_wp_attached_file', true) : '',
        // Maps stay JSON objects even when empty (an empty PHP array would encode as []).
        'meta'       => (object) syncExportMeta($post),
        'seo'        => (object) syncExportSeo($post->ID),
        'terms'      => (object) syncExportTerms($post),
        'media'      => (object) syncMediaMap($ids),
        'content'    => syncNeutralUrls($content),
    ];
}

/** The site URL, plain and JSON-escaped (https:\/\/…), becomes {{home}}. */
function syncNeutralUrls(string $content): string
{
    $home = untrailingslashit(home_url());

    return str_replace([$home, str_replace('/', '\\/', $home)], ['{{home}}', '{{home}}'], $content);
}

/** {{home}} back to this site's URL (plain everywhere; JSON attributes accept unescaped slashes). */
function syncLocalUrls(string $content): string
{
    return str_replace('{{home}}', untrailingslashit(home_url()), $content);
}

/**
 * Registered, REST-visible meta of the post type; post references become slugs.
 *
 * @return array<string, mixed>
 */
function syncExportMeta(WP_Post $post): array
{
    $refs = syncPostRefs($post->post_type);
    $meta = [];
    foreach (get_registered_meta_keys('post', $post->post_type) as $key => $args) {
        if (empty($args['show_in_rest'])) {
            continue;
        }
        $value = get_post_meta($post->ID, $key, (bool) $args['single']);
        if (isset($refs[$key])) {
            $ref   = $value ? get_post((int) $value) : null;
            $value = $ref instanceof WP_Post ? $ref->post_name : '';
        }
        $meta[$key] = $value;
    }
    ksort($meta);

    return $meta;
}

/**
 * @return array<string, string>
 */
function syncExportSeo(int $postId): array
{
    $seo = [];
    foreach (['title', 'metadesc', 'focuskw'] as $field) {
        $value = (string) get_post_meta($postId, '_yoast_wpseo_' . $field, true);
        if ($value !== '') {
            $seo[$field] = $value;
        }
    }

    return $seo;
}

/**
 * @return array<string, list<string>>
 */
function syncExportTerms(WP_Post $post): array
{
    $terms = [];
    foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
        $slugs = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'slugs']);
        if (is_array($slugs) && $slugs !== []) {
            sort($slugs);
            $terms[$taxonomy] = array_values($slugs);
        }
    }

    return $terms;
}

/**
 * Meta keys holding a post ID, per post type: ['wurf' => ['mutter' => 'hund', …]].
 *
 * @return array<string, string>
 */
function syncPostRefs(string $postType): array
{
    $refs = apply_filters('brocode_content_sync_post_refs', []);

    return is_array($refs) && isset($refs[$postType]) && is_array($refs[$postType]) ? $refs[$postType] : [];
}

/**
 * Attachment IDs referenced in block attributes and classic markup.
 *
 * @return list<int>
 */
function syncReferencedAttachments(string $content): array
{
    $ids = [];
    syncTranslateIds($content, static function (int $id) use (&$ids): int {
        $ids[] = $id;

        return $id;
    });

    return array_values(array_unique(array_filter($ids, static fn(int $id): bool => get_post_type($id) === 'attachment')));
}

/**
 * @param list<int> $ids
 * @return array<string, string> id => uploads-relative path
 */
function syncMediaMap(array $ids): array
{
    $map = [];
    foreach (array_unique($ids) as $id) {
        $path = (string) get_post_meta($id, '_wp_attached_file', true);
        if ($path !== '') {
            $map[(string) $id] = $path;
        }
    }
    ksort($map, SORT_NATURAL);

    return $map;
}

/**
 * Runs $map over every attachment ID reference and returns the rewritten content: media
 * block attributes (id, ids, mediaId), wp-image-N / data-id / attachment_N in markup.
 *
 * @param callable(int): int $map
 */
function syncTranslateIds(string $content, callable $map): string
{
    $content = (string) preg_replace_callback(
        '/<!-- wp:([a-z0-9\/-]+) (\{.*?\}) (\/?)-->/s',
        static fn(array $m): string => syncTranslateBlockComment($m, $map),
        $content
    );

    return (string) preg_replace_callback(
        '/(wp-image-|data-id="|attachment_)(\d+)/',
        static fn(array $m): string => $m[1] . $map((int) $m[2]),
        $content
    );
}

/**
 * @param array<int, string>  $m   regex match: full, name, json attrs, self-closing slash
 * @param callable(int): int  $map
 */
function syncTranslateBlockComment(array $m, callable $map): string
{
    $name = str_contains($m[1], '/') ? $m[1] : 'core/' . $m[1];
    if (!in_array($name, SYNC_MEDIA_BLOCKS, true)) {
        return $m[0];
    }
    $attrs = json_decode($m[2], true);
    if (!is_array($attrs)) {
        return $m[0];
    }
    $before = $attrs;
    foreach (['id', 'mediaId'] as $key) {
        if (isset($attrs[$key]) && is_int($attrs[$key])) {
            $attrs[$key] = $map($attrs[$key]);
        }
    }
    if (isset($attrs['ids']) && is_array($attrs['ids'])) {
        $attrs['ids'] = array_map(static fn($id) => is_int($id) ? $map($id) : $id, $attrs['ids']);
    }
    if ($attrs === $before) {
        return $m[0];
    }

    return sprintf('<!-- wp:%s %s %s-->', $m[1], serialize_block_attributes($attrs), $m[3]);
}
