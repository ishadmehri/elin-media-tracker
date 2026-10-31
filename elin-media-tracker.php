<?php
/**
 * Plugin Name: Elin Media Tracker
 * Plugin URI: https://github.com/ishadmehri/elin-media-tracker
 * Description: Find unreferenced media, see where each file is used, find duplicate files, and clean up safely with a reversible quarantine.
 * Version: 0.8.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ishadmehri
 * Author URI: https://github.com/ishadmehri
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: elin-media-tracker
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

define('MTRK_VERSION', '0.8.0');
define('MTRK_FILE', __FILE__);

require __DIR__ . '/includes/class-scanner.php';
require __DIR__ . '/includes/class-live.php';
require __DIR__ . '/includes/class-sizes.php';
require __DIR__ . '/includes/class-orphans.php';
require __DIR__ . '/includes/class-cleanup.php';
require __DIR__ . '/includes/class-duplicates.php';
require __DIR__ . '/includes/class-admin.php';
require __DIR__ . '/includes/class-page.php';

// The bundled Persian translation; translate.wordpress.org language packs win when present.
add_action('init', fn() => load_plugin_textdomain('elin-media-tracker', false, dirname(plugin_basename(MTRK_FILE)) . '/languages'));
register_activation_hook(__FILE__, ['MediaTracker\Scanner', 'install']);
add_action('plugins_loaded', ['MediaTracker\Scanner', 'upgrade']);
add_action('plugins_loaded', ['MediaTracker\Cleanup', 'serve'], 1); // requests for quarantined files
add_action('delete_attachment', ['MediaTracker\Cleanup', 'forget']);
add_action('added_post_meta', ['MediaTracker\Scanner', 'file_meta'], 10, 4); // keeps the attachments index current
add_action('updated_post_meta', ['MediaTracker\Scanner', 'file_meta'], 10, 4);

// A deleted attachment or post takes its references with it; saves are re-indexed live.
add_action('deleted_post', ['MediaTracker\Scanner', 'forget']);
MediaTracker\Live::init();

if (defined('WP_CLI') && WP_CLI) {
    require __DIR__ . '/includes/class-cli.php';
    WP_CLI::add_command('elin-media-tracker', MediaTracker\CLI::class);
}

if (is_admin()) {
    MediaTracker\Admin::init();
}
