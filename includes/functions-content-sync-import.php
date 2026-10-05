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
 * Import side of the content sync (see functions-content-sync.php for the neutral form).
 * One post per request, matched by type + slug. `expected_modified` is the post_modified_gmt
 * the caller last saw here; when the post changed since, nothing is written (409) — the
 * caller pulls first. Media must exist here already (media-ensure); missing paths → 422.
 */

function contentImport(WP_REST_Request $request): WP_REST_Response|\WP_Error
{
    $item = (array) $request->get_json_params();
    $type = sanitize_key((string) ($item['type'] ?? ''));
    $slug = sanitize_title((string) ($item['slug'] ?? ''));
    if (!post_type_exists($type) || $slug === '') {
        return new \WP_Error('brocode_bad_item', 'type and slug are required.', ['status' => 400]);
    }
    $existing = syncFindPost($type, $slug) ?? syncFindMediaByPath($type, $item);
    $conflict = syncConflict($existing, $item);
    if ($conflict !== null) {
        return $conflict;
    }
    $idMap = syncResolveMedia((array) ($item['media'] ?? []));
    if ($idMap instanceof \WP_Error) {
        return $idMap;
    }
    // A new media file exists now (media-ensure registered it under its path): update that one.
    $existing ??= syncFindMediaByPath($type, $item);
    if ($type === 'attachment' && !$existing instanceof WP_Post) {
        return new \WP_Error('brocode_sync_media_missing', 'Media file missing here; run media-ensure first.', ['status' => 422, 'paths' => array_values((array) ($item['media'] ?? []))]);
    }
    $postId = syncWritePost($existing, $type, $slug, $item, $idMap);
    if ($postId instanceof \WP_Error) {
        return $postId;
    }
    syncWriteMeta($postId, $type, (array) ($item['meta'] ?? []));
    syncWriteSeo($postId, (array) ($item['seo'] ?? []));
    syncWriteTerms($postId, (array) ($item['terms'] ?? []));
    syncWriteFeatured($postId, (string) ($item['featured'] ?? ''));

    clean_post_cache($postId);
    // Yoast caches each post's SEO data in its indexable table and rebuilds it on
    // wp_insert_post — which fired inside syncWritePost, BEFORE the meta above was written,
    // so the page would keep the old title/description. Fire it again now that all is saved.
    $post = get_post($postId);
    if ($post instanceof WP_Post) {
        do_action('wp_insert_post', $postId, $post, true);
    }

    return new WP_REST_Response(syncExportPost(get_post($postId)), $existing ? 200 : 201);
}

/**
 * A media file whose slug differs between sites is still the same file at the same path.
 *
 * @param array<string, mixed> $item
 */
function syncFindMediaByPath(string $type, array $item): ?WP_Post
{
    $paths = array_values((array) ($item['media'] ?? []));
    if ($type !== 'attachment' || count($paths) !== 1) {
        return null;
    }
    $id = syncAttachmentByPath((string) $paths[0]);

    return $id > 0 ? get_post($id) : null;
}

function syncFindPost(string $type, string $slug): ?WP_Post
{
    $statuses = syncStatuses($type);
    $posts    = get_posts(['post_type' => $type, 'name' => $slug, 'post_status' => $statuses, 'numberposts' => 1, 'suppress_filters' => true]);
    if ($posts !== []) {
        return $posts[0];
    }
    // A draft without a stored slug is known by the one syncSlug() derives from its title.
    foreach (get_posts(['post_type' => $type, 'post_status' => ['draft', 'pending'], 'numberposts' => -1, 'suppress_filters' => true]) as $draft) {
        if ($draft->post_name === '' && syncSlug($draft) === $slug) {
            return $draft;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $item
 */
function syncConflict(?WP_Post $existing, array $item): ?\WP_Error
{
    $expected = (string) ($item['expected_modified'] ?? '');
    if (!$existing instanceof WP_Post || !empty($item['force']) || $expected === $existing->post_modified_gmt) {
        return null;
    }
    // No expected_modified but the slug exists here: new in git, made here too — never overwrite blind.
    $message = $expected === ''
        ? 'A post with this slug exists here but was never synced; pull first or force.'
        : 'The post changed here since the last sync; pull first.';

    return new \WP_Error('brocode_sync_conflict', $message, [
        'status'       => 409,
        'modified_gmt' => $existing->post_modified_gmt,
    ]);
}

/**
 * @param array<string, string> $media source id => uploads-relative path
 * @return array<int, int>|\WP_Error source id => local id
 */
function syncResolveMedia(array $media): array|\WP_Error
{
    $map     = [];
    $missing = [];
    foreach ($media as $sourceId => $path) {
        $localId = syncAttachmentByPath((string) $path);
        if ($localId === 0) {
            $missing[] = (string) $path;
            continue;
        }
        $map[(int) $sourceId] = $localId;
    }
    if ($missing !== []) {
        return new \WP_Error('brocode_sync_media_missing', 'Media missing here; run media-ensure first.', ['status' => 422, 'paths' => $missing]);
    }

    return $map;
}

function syncAttachmentByPath(string $path): int
{
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- exact lookup on an indexed meta key; no API for it.
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s AND p.post_type = 'attachment' LIMIT 1",
        $path
    ));
}

/**
 * @param array<string, mixed> $item
 * @param array<int, int>      $idMap
 */
function syncWritePost(?WP_Post $existing, string $type, string $slug, array $item, array $idMap): int|\WP_Error
{
    $content = syncTranslateIds(syncLocalUrls((string) ($item['content'] ?? '')), static fn(int $id): int => $idMap[$id] ?? $id);
    $parent  = (string) ($item['parent'] ?? '') !== '' ? get_page_by_path((string) $item['parent'], OBJECT, $type) : null;
    $postarr = [
        'ID'           => $existing?->ID ?? 0,
        'post_type'    => $type,
        'post_name'    => $slug,
        'post_status'  => $type === 'attachment' ? 'inherit' : sanitize_key((string) ($item['status'] ?? 'draft')),
        'post_title'   => (string) ($item['title'] ?? ''),
        'post_excerpt' => (string) ($item['excerpt'] ?? ''),
        'post_content' => $content,
        'menu_order'   => (int) ($item['menu_order'] ?? 0),
    ];
    if ($type !== 'attachment') {
        $postarr['post_parent'] = $parent instanceof WP_Post ? $parent->ID : 0;
    }
    // A template the active theme lacks (e.g. a classic theme's page-templates/… file under a
    // block theme) makes wp_insert_post fail AFTER saving the post. Keep the stored one instead.
    $template = (string) ($item['template'] ?? '');
    if ($template === '' || isset(wp_get_theme()->get_page_templates(null, $type)[$template])) {
        $postarr['page_template'] = $template;
    }
    if ((string) ($item['date'] ?? '') !== '') {
        $postarr['post_date'] = (string) $item['date'];
    }

    // Both unslash; content with backslashes (JSON in block attributes) must be slashed.
    // Updates go through wp_update_post, which keeps every field not sent (author, comment
    // status, a media file's MIME type) — wp_insert_post with an ID resets them to defaults.
    return $existing instanceof WP_Post ? wp_update_post(wp_slash($postarr), true) : wp_insert_post(wp_slash($postarr), true);
}

/**
 * @param array<string, mixed> $meta
 */
function syncWriteMeta(int $postId, string $type, array $meta): void
{
    $registered = get_registered_meta_keys('post', $type);
    $refs       = syncPostRefs($type);
    foreach ($meta as $key => $value) {
        if (!isset($registered[$key]) || empty($registered[$key]['show_in_rest'])) {
            continue;
        }
        if (isset($refs[$key])) {
            $ref   = $value !== '' ? get_page_by_path((string) $value, OBJECT, $refs[$key]) : null;
            $value = $ref instanceof WP_Post ? $ref->ID : 0;
        }
        $value = syncMapStrings($value, __NAMESPACE__ . '\\syncLocalUrls');
        if (!empty($registered[$key]['single'])) {
            // Empty means "not set": some flags are checked by the key's existence alone.
            if ($value === '' || $value === null) {
                delete_post_meta($postId, $key);
            } else {
                update_post_meta($postId, $key, wp_slash($value));
            }
            continue;
        }
        delete_post_meta($postId, $key);
        foreach ((array) $value as $row) {
            add_post_meta($postId, $key, wp_slash($row));
        }
    }
}

/**
 * @param array<string, string> $seo
 */
function syncWriteSeo(int $postId, array $seo): void
{
    foreach (['title', 'metadesc', 'focuskw'] as $field) {
        if (isset($seo[$field]) && $seo[$field] !== '') {
            update_post_meta($postId, '_yoast_wpseo_' . $field, wp_slash((string) $seo[$field]));
        } else {
            delete_post_meta($postId, '_yoast_wpseo_' . $field);
        }
    }
}

/**
 * @param array<string, list<string>> $terms
 */
function syncWriteTerms(int $postId, array $terms): void
{
    foreach (get_object_taxonomies((string) get_post_type($postId)) as $taxonomy) {
        $slugs = array_map('sanitize_title', (array) ($terms[$taxonomy] ?? []));
        foreach ($slugs as $slug) {
            if (!term_exists($slug, $taxonomy)) {
                wp_insert_term($slug, $taxonomy, ['slug' => $slug]);
            }
        }
        wp_set_object_terms($postId, $slugs, $taxonomy);
    }
}

function syncWriteFeatured(int $postId, string $path): void
{
    $attachmentId = $path !== '' ? syncAttachmentByPath($path) : 0;
    if ($attachmentId > 0) {
        set_post_thumbnail($postId, $attachmentId);
    } else {
        delete_post_thumbnail($postId);
    }
}

/**
 * Makes sure an attachment exists for an uploads-relative path, keeping that path (no
 * year/month re-filing). Uses the uploaded file when one is sent, else a file already on disk.
 */
function mediaEnsure(WP_REST_Request $request): WP_REST_Response|\WP_Error
{
    $path = syncSafeUploadsPath((string) $request['path']);
    if ($path === null) {
        return new \WP_Error('brocode_bad_path', 'Invalid uploads path.', ['status' => 400]);
    }
    $existing = syncAttachmentByPath($path);
    if ($existing > 0) {
        return new WP_REST_Response(['id' => $existing, 'path' => $path, 'created' => false], 200);
    }
    $file   = trailingslashit((string) wp_get_upload_dir()['basedir']) . $path;
    $placed = syncPlaceUpload($request->get_file_params()['file'] ?? null, $file);
    if ($placed instanceof \WP_Error) {
        return $placed;
    }

    return syncRegisterAttachment($file, $path);
}

function syncSafeUploadsPath(string $path): ?string
{
    $path  = ltrim(str_replace('\\', '/', $path), '/');
    $check = wp_check_filetype(basename($path));
    if ($path === '' || str_contains($path, '..') || $check['type'] === false || validate_file($path) !== 0) {
        return null;
    }

    return $path;
}

/**
 * @param array<string, mixed>|null $upload one entry of $_FILES
 */
function syncPlaceUpload(?array $upload, string $file): true|\WP_Error
{
    if (!$upload) {
        return file_exists($file) ? true : new \WP_Error('brocode_media_no_file', 'No file sent and none on disk.', ['status' => 400]);
    }
    $tmp   = (string) ($upload['tmp_name'] ?? '');
    $check = wp_check_filetype_and_ext($tmp, basename($file));
    if (!is_uploaded_file($tmp) || $check['type'] === false) {
        return new \WP_Error('brocode_media_bad_file', 'Upload missing or file type not allowed.', ['status' => 400]);
    }
    if (!wp_mkdir_p(dirname($file)) || !move_uploaded_file($tmp, $file)) {
        return new \WP_Error('brocode_media_write', 'Could not write the file.', ['status' => 500]);
    }
    chmod($file, 0644);

    return true;
}

function syncRegisterAttachment(string $file, string $path): WP_REST_Response|\WP_Error
{
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $type = wp_check_filetype(basename($file));
    $id   = wp_insert_attachment([
        'post_mime_type' => (string) $type['type'],
        'post_title'     => sanitize_text_field(pathinfo($file, PATHINFO_FILENAME)),
        'post_status'    => 'inherit',
    ], $file, 0, true);
    if ($id instanceof \WP_Error) {
        return $id;
    }
    update_post_meta($id, '_wp_attached_file', $path);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));

    return new WP_REST_Response(['id' => $id, 'path' => $path, 'created' => true], 201);
}
