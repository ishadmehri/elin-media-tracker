<?php
/**
 * Live index check: after one full scan, changes saved through WordPress APIs must move statuses without a rescan.
 * Live::flush() normally runs at shutdown; it is called here so the results can be read. Truncates scan results.
 *
 * Run: wp eval-file wp-content/plugins/media-tracker/tests/live.php
 */

use MediaTracker\Live;
use MediaTracker\Scanner;

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

$fail = 0;
$made = [];
$status = fn(int $id): string => Scanner::status($id, Scanner::counts([$id])[$id] ?? 0);
$is = function (string $want, int $id, string $what) use (&$fail, $status): void {
    $got = $status($id);
    echo ($got === $want ? 'PASS ' : "FAIL (got $got) ") . "$want: $what\n";
    $fail += $got === $want ? 0 : 1;
};
$url = fn(int $id): string => (string) wp_get_attachment_url($id);

$removed = mtrk_fixture_media('mtrk-live-removed.jpg', $made);
$featured = mtrk_fixture_media('mtrk-live-featured.jpg', $made);
$widget = mtrk_fixture_media('mtrk-live-option.jpg', $made);
$cat = mtrk_fixture_media('mtrk-live-term.jpg', $made);
$p = wp_insert_post(['post_title' => 'mtrk live a', 'post_status' => 'publish', 'post_content' => '<img src="' . $url($removed) . '">']);
$q = wp_insert_post(['post_title' => 'mtrk live b', 'post_status' => 'publish']);
$term = wp_insert_term('mtrk live ' . uniqid(), 'category');
array_push($made, $p, $q);

try {
    mtrk_fixture_scan(false, false);
    $is('used', $removed, 'baseline: in post content');
    $is('unused', $featured, 'baseline: nowhere yet');

    wp_update_post(['ID' => $p, 'post_content' => '<p>no image any more</p>']);
    update_post_meta($q, '_thumbnail_id', $featured);
    update_option('mtrkfix_live', [3 => ['attachment_id' => $widget]], false);
    update_term_meta($term['term_id'], 'thumbnail_id', $cat);
    $new = mtrk_fixture_media('mtrk-live-new.jpg', $made);
    $idle = mtrk_fixture_media('mtrk-live-idle.jpg', $made);
    wp_update_post(['ID' => $q, 'post_content' => '<img src="' . $url($new) . '">']);
    Live::flush();

    $is('unused', $removed, 'removed from post content');
    $is('used', $featured, 'set as featured image');
    $is('used', $widget, 'saved in a serialized option');
    $is('used', $cat, 'term meta thumbnail_id');
    $is('used', $new, 'uploaded after the scan, then inserted');
    $is('new', $idle, 'uploaded after the scan, never inserted');

    delete_post_meta($q, '_thumbnail_id');
    delete_option('mtrkfix_live');
    Live::flush();
    $is('unused', $featured, 'featured image removed');
    $is('unused', $widget, 'option deleted');

    wp_delete_post($q, true);
    Live::flush();
    $is('new', $new, 'its only post deleted');
} finally {
    foreach (array_reverse($made) as $id) {
        wp_delete_post($id, true);
    }
    delete_option('mtrkfix_live');
    wp_delete_term($term['term_id'], 'category');
    Live::flush();
}

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
