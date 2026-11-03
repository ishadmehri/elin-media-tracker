<?php
/**
 * The unreferenced list and the actions on it: quarantine, request logging, restore, delete, keep.
 * Truncates scan results.
 *
 * Run: wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/cleanup.php
 */

use ElinwebUnusedMediaFinder\Cleanup;
use ElinwebUnusedMediaFinder\Scanner;

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

$fail = 0;
$made = [];
$is = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . "$what\n";
    $fail += $ok ? 0 : 1;
};
$status = fn(int $id): string => Scanner::status($id, Scanner::counts([$id])[$id] ?? 0);
$base = wp_upload_dir()['basedir'] . '/2026/10';
$path = fn(int $id): string => wp_parse_url((string) wp_get_attachment_url($id), PHP_URL_PATH);
$with_size = function (string $name, int $bytes) use (&$made, $base): int {
    $id = mtrk_fixture_media("$name.jpg", $made);
    file_put_contents("$base/$name.jpg", str_repeat('x', $bytes));
    file_put_contents("$base/$name-150x150.jpg", 'x');
    file_put_contents("$base/$name.jpg.webp", 'x');
    $meta = wp_get_attachment_metadata($id);
    unset($meta['filesize']);
    $meta['sizes'] = ['thumbnail' => ['file' => "$name-150x150.jpg", 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg']];
    wp_update_attachment_metadata($id, $meta);
    return $id;
};

$a = $with_size('mtrk-cl-a', 5000);   // unreferenced, biggest
$b = $with_size('mtrk-cl-b', 3000);   // used
$c = $with_size('mtrk-cl-c', 1000);   // unreferenced, deleted directly
$k = $with_size('mtrk-cl-k', 4000);   // kept
$t1 = $with_size('mtrk-cl-twin', 2000);
$t2 = wp_insert_attachment(['post_title' => 'mtrk twin 2', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], "$base/mtrk-cl-twin.jpg");
wp_update_attachment_metadata($t2, wp_get_attachment_metadata($t1));
$made[] = $t2;
$made[] = wp_insert_post(['post_title' => 'mtrk cleanup', 'post_status' => 'publish', 'post_content' => '<img src="' . wp_get_attachment_url($b) . '">']);

try {
    mtrk_fixture_scan(false, false);
    Cleanup::keep([$k], true);
    $is($status($k) === 'used', 'keep: marked media counts as used');

    // The list: ours only, largest first.
    $ours = fn(array $items) => array_values(array_intersect(array_keys($items), [$a, $b, $c, $k, $t1, $t2]));
    $list = Cleanup::unused(1, 500, 'image', 'largest');
    $is($ours($list['items']) === [$a, $t1, $t2, $c] || $ours($list['items']) === [$a, $t2, $t1, $c], 'list: unreferenced only (no used, no kept), largest first');
    $is($list['items'][$a] === 5001, 'list: bytes = main file + its sizes, from disk when metadata lacks them');

    // Quarantine.
    $is(Cleanup::quarantine([$b]) === [], 'quarantine: refuses used media');
    $is(Cleanup::quarantine([$a]) === [$a], 'quarantine: unreferenced media');
    $q = get_post_meta($a, Cleanup::META, true);
    $is(!file_exists("$base/mtrk-cl-a.jpg") && !file_exists("$base/mtrk-cl-a-150x150.jpg") && !file_exists("$base/mtrk-cl-a.jpg.webp"), 'quarantine: main, size and webp copy moved out');
    $is(count($q['files']) === 3, 'quarantine: 3 files recorded');
    $is(!array_key_exists($a, Cleanup::unused(1, 500)['items']), 'list: quarantined media leaves the list');

    // Requests for it.
    $match = Cleanup::match($path($a));
    $is(is_array($match) && $match[0] === $a && is_file($match[1]), 'request: a quarantined path is recognised and found');
    $is(Cleanup::match($path($b)) === null, 'request: other files are not touched');
    $is(Cleanup::match(wp_parse_url(Cleanup::probe_url(), PHP_URL_PATH)) === 'probe', 'request: probe answered');
    $is(Cleanup::match('/some-page/') === null, 'request: pages exit early');
    $home = home_url('/');
    Cleanup::hit($a, 'https://www.google.com/', 'Mozilla');
    Cleanup::hit($a, $home . 'some-post/', 'Mozilla');
    Cleanup::hit($a, 'https://other.example/', 'Mozilla');
    Cleanup::hit($a, '', 'Googlebot-Image/1.0');
    Cleanup::hit($a, '', 'Mozilla');
    Cleanup::hit($a, admin_url('upload.php'), 'Mozilla');
    $hits = get_post_meta($a, Cleanup::META, true)['hits'];
    $is($hits === ['google' => 1, 'site' => 1, 'external' => 1, 'bot' => 1, 'direct' => 1], 'request: hits sorted by kind, admin previews not counted');

    // Restore.
    $is(Cleanup::restore([$a]) === [$a] && file_exists("$base/mtrk-cl-a.jpg") && file_exists("$base/mtrk-cl-a.jpg.webp"), 'restore: files back in place');
    $is(Cleanup::match($path($a)) === null && !get_post_meta($a, Cleanup::META, true), 'restore: no longer quarantined');

    // Twins move together; delete from quarantine removes both posts and files.
    $is(Cleanup::quarantine([$t2]) === [$t1, $t2], 'twins: quarantining one takes both');
    $is(!file_exists("$base/mtrk-cl-twin.jpg"), 'twins: shared file moved once');
    $out = Cleanup::delete([$t1]);
    $is($out['ids'] === [$t1, $t2] && !get_post($t1) && !get_post($t2), 'delete: twins deleted together');
    $is(Cleanup::match('/wp-content/uploads/2026/10/mtrk-cl-twin.jpg') === null, 'delete: quarantine map cleared');

    // Direct delete, and refusal for used media.
    $is(Cleanup::delete([$b])['ids'] === [], 'delete: refuses used media');
    $out = Cleanup::delete([$c]);
    $is($out['ids'] === [$c] && !get_post($c), 'delete: unreferenced media deleted');
    $is($out['bytes'] === 1002, 'delete: reports bytes freed (main, size and webp copy)');
    foreach (['mtrk-cl-c.jpg', 'mtrk-cl-c-150x150.jpg', 'mtrk-cl-c.jpg.webp'] as $f) {
        $is(!file_exists("$base/$f"), "delete: $f gone");
    }

    // Deleting a quarantined attachment from the media library cleans its quarantine files.
    Cleanup::quarantine([$a]);
    $qfile = Cleanup::match($path($a))[1];
    wp_delete_attachment($a, true);
    $is(!file_exists($qfile), 'media library delete: quarantined files removed too');

    // Keep survives a rescan; unkeep releases it.
    mtrk_fixture_scan(false, false);
    $is($status($k) === 'used', 'keep: survives a full rescan');
    Cleanup::keep([$k], false);
    $is($status($k) === 'unused', 'keep: unmarking makes it unreferenced again');
} finally {
    foreach (array_reverse($made) as $id) {
        wp_delete_post($id, true);
    }
    foreach (glob("$base/mtrk-cl-*") ?: [] as $f) {
        unlink($f);
    }
}

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
