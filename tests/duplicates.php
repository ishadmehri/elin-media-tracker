<?php
/**
 * Duplicate files: identical content found, same size alone is not enough, twins are not duplicates, a changed
 * file is recomputed, and only unreferenced copies can go. Truncates scan results.
 *
 * Run: wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/duplicates.php
 */

use ElinwebUnusedMediaFinder\Cleanup;
use ElinwebUnusedMediaFinder\Duplicates;
use ElinwebUnusedMediaFinder\Orphans;

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

global $wpdb;
$fail = 0;
$made = [];
$is = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . "$what\n";
    $fail += $ok ? 0 : 1;
};
$dir = wp_upload_dir()['basedir'] . '/2026/10';
$media = function (string $name, string $content) use (&$made, $dir): int {
    $id = mtrk_fixture_media("$name.jpg", $made);
    file_put_contents("$dir/$name.jpg", $content);
    return $id;
};
$run = function (): void {
    do {
        $r = Duplicates::step();
    } while (!$r['done']);
};
$group_of = function (int $id): ?array {
    foreach (Duplicates::groups(1, 1000)['groups'] as $g) {
        if (in_array($id, $g['ids'], true)) {
            return $g;
        }
    }
    return null;
};

$same = str_repeat('A', 3000);
$used = $media('mtrk-dup-used', $same);                 // identical content, used as a featured image
$copy = $media('mtrk-dup-copy', $same);                 // identical content, unreferenced
$lookalike = $media('mtrk-dup-look', str_repeat('B', 3000)); // same size, different content
$unique = $media('mtrk-dup-unique', str_repeat('C', 4321));  // a size nobody else has
$twin_a = $media('mtrk-dup-twin', str_repeat('D', 5000));
$twin_b = wp_insert_attachment(['post_title' => 'mtrk twin', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], "$dir/mtrk-dup-twin.jpg");
$made[] = $twin_b;
$made[] = $p = wp_insert_post(['post_title' => 'mtrk dup', 'post_status' => 'publish']);
update_post_meta($p, '_thumbnail_id', $used);

try {
    mtrk_fixture_scan(false, false);
    $run();

    $g = $group_of($used);
    $is($g !== null && in_array($copy, $g['ids'], true) && $g['copies'] === 2, 'identical files form one group of 2 copies');
    $is($g !== null && !in_array($lookalike, $g['ids'], true), 'same size, different content: not a duplicate');
    $is($group_of($twin_a) === null, 'two attachments sharing one file are not duplicates');
    // (get_var returns null for an empty string, so count the measured-but-unhashed row instead)
    $is((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Duplicates::table() . " WHERE attachment_id = %d AND file_size > 0 AND content_hash = ''", $unique)) === 1, 'a file with a unique size is never hashed');

    $is(Cleanup::delete([$used])['ids'] === [], 'the used copy cannot be deleted');

    // The copy's file changes: its old hash is stale and must not keep it in the group.
    file_put_contents("$dir/mtrk-dup-copy2.jpg", str_repeat('E', 3000));
    update_attached_file($copy, "$dir/mtrk-dup-copy2.jpg");
    $run();
    $is($group_of($used) === null, 'a changed file is recomputed and leaves the group');

    update_attached_file($copy, "$dir/mtrk-dup-copy.jpg");
    $run();
    $is($group_of($used) !== null, 'changed back: grouped again');
    $is(Cleanup::delete([$copy])['ids'] === [$copy] && $group_of($used) === null, 'deleting the unreferenced copy dissolves the group');

    // Extra files (on disk, owned by no attachment) take part only when asked to.
    $rel = '2026/10/';
    file_put_contents("$dir/mtrk-dup-x1.jpg", $same);                    // stray copy of the used file
    file_put_contents("$dir/mtrk-dup-x2.jpg", $same);                    // a second one
    file_put_contents("$dir/mtrk-dup-x3.jpg", str_repeat('B', 3000));    // copy of the lookalike, but a post names it
    file_put_contents("$dir/mtrk-dup-x4.jpg", str_repeat('Z', 3100));    // nobody else's content
    $made[] = wp_insert_post(['post_title' => 'mtrk dup x', 'post_status' => 'publish', 'post_content' => '<img src="' . wp_upload_dir()['baseurl'] . '/' . $rel . 'mtrk-dup-x3.jpg">']);
    mtrk_fixture_scan(false, false);
    $go = function (bool $extras) {
        $r = Duplicates::step(true, $extras);
        while (!$r['done']) {
            $r = Duplicates::step();
        }
    };
    $go(false);
    $is($group_of($used) === null, 'extras off: a stray copy is not looked at');
    $go(true);
    $g = $group_of($used);
    $is($g !== null && $g['copies'] === 3 && array_keys($g['paths']) === [$rel . 'mtrk-dup-x1.jpg', $rel . 'mtrk-dup-x2.jpg'] || ($g && count($g['paths']) === 2 && $g['copies'] === 3), 'extras on: stray copies join the library file\'s group');
    $is($g !== null && !in_array(true, array_column($g['paths'], 'kept'), true), 'extras on: unreferenced stray copies are deletable');
    $g3 = $group_of($lookalike);
    $is($g3 !== null && ($g3['paths'][$rel . 'mtrk-dup-x3.jpg']['kept'] ?? null) === true, 'extras on: a stray copy whose URL a post names is kept');
    $in_group = array_merge(...array_map(fn($x) => array_keys($x['paths']), Duplicates::groups(1, 1000)['groups']));
    $is(!in_array($rel . 'mtrk-dup-x4.jpg', $in_group, true), 'extras on: an extra file with unique content is in no group');
    $is(Orphans::delete([$rel . 'mtrk-dup-x1.jpg'])['files'] === 1 && !file_exists("$dir/mtrk-dup-x1.jpg"), 'a stray copy can be deleted (the extra files delete path)');
    $go(false);
    $is(array_filter(array_column(Duplicates::groups(1, 1000)['groups'], 'paths')) === [] && !$wpdb->get_var("SHOW TABLES LIKE '" . Duplicates::xtable() . "'"), 'extras off again: the extra files table is gone');
} finally {
    foreach (array_reverse($made) as $id) {
        wp_delete_post($id, true);
    }
    foreach (glob("$dir/mtrk-dup-*") ?: [] as $f) {
        unlink($f);
    }
}

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
