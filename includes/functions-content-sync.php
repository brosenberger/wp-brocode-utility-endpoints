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

/** Statuses a synced post can have; media files ("attachment") only exist as "inherit". */
function syncStatuses(string $type): array
{
    return $type === 'attachment' ? ['inherit'] : ['publish', 'draft', 'pending', 'private', 'future'];
}

/** Alt text is the one core image field kept in meta; register it so it travels with the file. */
function registerSyncAttachmentMeta(): void
{
    register_post_meta('attachment', '_wp_attachment_image_alt', [
        'type'          => 'string',
        'single'        => true,
        'show_in_rest'  => true,
        'auth_callback' => static fn(bool $allowed, string $key, int $postId): bool => current_user_can('edit_post', $postId),
    ]);
}

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
        'post_status'      => syncStatuses($type),
        'numberposts'      => -1,
        'orderby'          => 'ID',
        'order'            => 'ASC',
        'suppress_filters' => true,
    ];
    $slug  = (string) $request['slug'];
    $posts = $slug !== '' ? array_filter([syncFindPost($type, $slug)]) : get_posts($query);
    $summary = (bool) $request['summary'];
    $items   = array_map(
        static fn(WP_Post $post): array => $summary ? syncSummary($post) : syncExportPost($post),
        array_values($posts)
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
    return ['id' => $post->ID, 'slug' => syncSlug($post), 'status' => $post->post_status, 'modified_gmt' => $post->post_modified_gmt];
}

/**
 * The post's slug, or for a draft that WordPress has not given one yet the slug it would get
 * from its title — without it, several such drafts would share an empty file name.
 */
function syncSlug(WP_Post $post): string
{
    if ($post->post_name !== '') {
        return $post->post_name;
    }

    return sanitize_title($post->post_title) ?: 'post-' . $post->ID;
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
    $isMedia = $post->post_type === 'attachment';
    if ($isMedia) {
        $ids[] = $post->ID; // a media file carries its own path: identity across sites, upload on push
    }

    return syncSummary($post) + [
        'type'       => $post->post_type,
        'title'      => $post->post_title,
        'date'       => $post->post_date,
        // A media file's parent is the post it was uploaded to: environment noise, not content.
        'parent'     => !$isMedia && $post->post_parent > 0 ? get_page_uri($post->post_parent) : '',
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
        // Meta can hold full site URLs too (e.g. Page Links To's _links_to).
        $meta[$key] = syncMapStrings($value, __NAMESPACE__ . '\\syncNeutralUrls');
    }
    ksort($meta);

    return $meta;
}

/**
 * Applies $map to every string in a meta value (scalars and arrays of them).
 *
 * @param callable(string): string $map
 */
function syncMapStrings(mixed $value, callable $map): mixed
{
    if (is_string($value)) {
        return $map($value);
    }

    return is_array($value) ? array_map(static fn($v) => syncMapStrings($v, $map), $value) : $value;
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
