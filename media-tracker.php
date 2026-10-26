<?php
/**
 * Plugin Name: Media Tracker
 * Description: پیدا کردن رسانه‌های بدون استفاده و نمایش جاهایی که هر رسانه در آن‌ها استفاده شده است.
 * Version: 0.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: ishadmehri
 * Text Domain: media-tracker
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

define('MTRK_VERSION', '0.1.1');
define('MTRK_FILE', __FILE__);

require __DIR__ . '/includes/class-scanner.php';
require __DIR__ . '/includes/class-admin.php';

register_activation_hook(__FILE__, ['MediaTracker\Scanner', 'install']);

// A deleted attachment or post takes its references with it, so results stay true between scans.
add_action('deleted_post', ['MediaTracker\Scanner', 'forget']);

if (is_admin()) {
    MediaTracker\Admin::init();
}
