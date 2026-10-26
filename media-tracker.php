<?php
/**
 * Plugin Name: Media Tracker
 * Description: پیدا کردن رسانه‌های بدون استفاده و نمایش جاهایی که هر رسانه در آن‌ها استفاده شده است.
 * Version: 0.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ishadmehri
 * Text Domain: media-tracker
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

define('MTRK_VERSION', '0.3.0');
define('MTRK_FILE', __FILE__);

require __DIR__ . '/includes/class-scanner.php';
require __DIR__ . '/includes/class-live.php';
require __DIR__ . '/includes/class-sizes.php';
require __DIR__ . '/includes/class-orphans.php';
require __DIR__ . '/includes/class-admin.php';

register_activation_hook(__FILE__, ['MediaTracker\Scanner', 'install']);
add_action('plugins_loaded', ['MediaTracker\Scanner', 'upgrade']);

// A deleted attachment or post takes its references with it; saves are re-indexed live.
add_action('deleted_post', ['MediaTracker\Scanner', 'forget']);
MediaTracker\Live::init();

if (is_admin()) {
    MediaTracker\Admin::init();
}
