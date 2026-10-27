<?php
/**
 * Plugin Name: Elin Media Tracker
 * Description: پیدا کردن رسانه‌های بدون استفاده و نمایش جاهایی که هر رسانه در آن‌ها استفاده شده است.
 * Version: 0.5.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ishadmehri
 * Text Domain: elin-media-tracker
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

define('MTRK_VERSION', '0.5.1');
define('MTRK_FILE', __FILE__);

require __DIR__ . '/includes/class-scanner.php';
require __DIR__ . '/includes/class-live.php';
require __DIR__ . '/includes/class-sizes.php';
require __DIR__ . '/includes/class-orphans.php';
require __DIR__ . '/includes/class-cleanup.php';
require __DIR__ . '/includes/class-admin.php';
require __DIR__ . '/includes/class-page.php';

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
