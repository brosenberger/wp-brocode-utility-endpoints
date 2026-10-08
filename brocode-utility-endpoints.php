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
 * Description: Admin-authenticated REST endpoints and WP Abilities for site management — flush rewrites, clear cache, scan links, manage plugins, set Yoast SEO meta, export/import content for a two-way git sync. Includes an admin UI and WP-CLI commands.
 * Version:     1.3.1
 * Requires at least: 6.5
 * Tested up to: 6.8
 * Requires PHP: 8.1
 * Author:      Benjamin Rosenberger
 * Author URI:  https://brocode.at
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: brocode-utility-endpoints
 * Domain Path: /languages
 */

declare(strict_types=1);

namespace Brocode\UtilityEndpoints;

if (!defined('ABSPATH')) {
    exit;
}

define('BUE_PLUGIN_FILE', __FILE__);
define('BUE_ADMIN_HOOK', 'brocode_utility');
define('BUE_SCAN_TRANSIENT_PREFIX', 'brocode_utility_scan_');

require_once __DIR__ . '/includes/functions-core.php';
require_once __DIR__ . '/includes/functions-rest.php';
require_once __DIR__ . '/includes/functions-content-sync.php';
require_once __DIR__ . '/includes/functions-content-sync-import.php';
require_once __DIR__ . '/includes/functions-abilities.php';
require_once __DIR__ . '/includes/functions-admin.php';
require_once __DIR__ . '/includes/functions-cli.php';

add_action('init', __NAMESPACE__ . '\\loadTextdomain');
add_action('init', __NAMESPACE__ . '\\registerCliCommands');
add_action('rest_api_init', __NAMESPACE__ . '\\registerRestRoutes');
add_action('rest_api_init', __NAMESPACE__ . '\\registerContentSyncRoutes');
add_action('init', __NAMESPACE__ . '\\registerSyncAttachmentMeta');
add_action('wp_abilities_api_categories_init', __NAMESPACE__ . '\\brocode_register_ability_category');
add_action('wp_abilities_api_init', __NAMESPACE__ . '\\registerAbilities');
add_action('admin_menu', __NAMESPACE__ . '\\registerAdminPage');
add_action('admin_post_' . BUE_ADMIN_HOOK . '_flush', __NAMESPACE__ . '\\handleAdminFlush');
add_action('admin_post_' . BUE_ADMIN_HOOK . '_clear_cache', __NAMESPACE__ . '\\handleAdminClearCache');
add_action('admin_post_' . BUE_ADMIN_HOOK . '_scan_links', __NAMESPACE__ . '\\handleAdminScanLinks');

function loadTextdomain(): void
{
    load_plugin_textdomain(
        'brocode-utility-endpoints',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
