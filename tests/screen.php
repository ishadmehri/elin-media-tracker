<?php
/**
 * Page::screen() remembers per-page and view only with a valid 'mtrk_screen' nonce from a user who may manage media;
 * without one the request is shown as asked but nothing is saved (no CSRF on the user's preferences).
 *
 * Run: wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/screen.php
 */

use ElinwebUnusedMediaFinder\Page;

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

$fail = 0;
$is = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
    $fail += $ok ? 0 : 1;
};

$screen = new ReflectionMethod(Page::class, 'screen');
$screen->setAccessible(true); // needed before PHP 8.1
$admin = get_current_user_id();
$get = $_GET;
$saved = [];
foreach (['mtrk_per_page', 'mtrk_view'] as $key) {
    $saved[$key] = metadata_exists('user', $admin, $key) ? get_user_meta($admin, $key, true) : null;
}
$subscriber = wp_insert_user(['user_login' => 'mtrk_screen_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);

$meta = fn() => [(int) get_user_meta($admin, 'mtrk_per_page', true), (string) get_user_meta($admin, 'mtrk_view', true)];

try {
    update_user_meta($admin, 'mtrk_per_page', 20);
    update_user_meta($admin, 'mtrk_view', 'grid');

    $_GET = ['per_page' => '100', 'view' => 'list'];
    $is($screen->invoke(null) === [100, 'list'], 'no nonce: the request is shown as asked');
    $is($meta() === [20, 'grid'], 'no nonce: nothing saved');

    $_GET['_wpnonce'] = 'bogus';
    $screen->invoke(null);
    $is($meta() === [20, 'grid'], 'invalid nonce: nothing saved');

    $_GET['_wpnonce'] = wp_create_nonce('mtrk'); // the AJAX nonce is not the screen nonce
    $screen->invoke(null);
    $is($meta() === [20, 'grid'], 'nonce for another action: nothing saved');

    $_GET['_wpnonce'] = wp_create_nonce('mtrk_screen');
    $is($screen->invoke(null) === [100, 'list'], 'valid nonce: the request is shown');
    $is($meta() === [100, 'list'], 'valid nonce: saved');

    $_GET = [];
    $is($screen->invoke(null) === [100, 'list'], 'no arguments: the saved choice is used');

    $_GET = ['per_page' => '7', 'view' => 'table', '_wpnonce' => wp_create_nonce('mtrk_screen')];
    $is($screen->invoke(null) === [100, 'list'] && $meta() === [100, 'list'], 'values outside the choices are ignored, even with a nonce');

    // A user without the capability, with a nonce that is valid for that user, still saves nothing.
    wp_set_current_user($subscriber);
    $_GET = ['per_page' => '200', 'view' => 'grid', '_wpnonce' => wp_create_nonce('mtrk_screen')];
    $screen->invoke(null);
    $is(!metadata_exists('user', $subscriber, 'mtrk_per_page') && !metadata_exists('user', $subscriber, 'mtrk_view'), 'no capability: nothing saved');
} finally {
    $_GET = $get;
    wp_set_current_user($admin);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($subscriber);
    foreach ($saved as $key => $value) {
        $value === null ? delete_user_meta($admin, $key) : update_user_meta($admin, $key, $value);
    }
}

$is($meta() === [(int) $saved['mtrk_per_page'], (string) $saved['mtrk_view']], 'user meta restored');

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
