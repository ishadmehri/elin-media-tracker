<?php
/**
 * Unregistered-size cleanup with real files on disk: what is reported, what is deleted, what must stay.
 * Truncates scan results.
 *
 * Run: wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/sizes.php
 */

use ElinwebUnusedMediaFinder\Sizes;

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

$fail = 0;
$made = [];
$is = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . "$what\n";
    $fail += $ok ? 0 : 1;
};
$walk = function (array $names, bool $delete): array {
    $total = [];
    $cursor = 0;
    do {
        $r = Sizes::step($cursor, $names, $delete);
        foreach ($r['sizes'] as $name => $s) {
            $total[$name] = $total[$name] ?? ['files' => 0, 'bytes' => 0, 'kept' => 0];
            foreach (['files', 'bytes', 'kept'] as $k) {
                $total[$name][$k] += $s[$k];
            }
        }
        $cursor = $r['next'];
    } while ($cursor !== null);
    return $total;
};

$id = mtrk_fixture_media('mtrk-sz.jpg', $made);
$dir = dirname((string) get_attached_file($id));
$size = fn(string $dims) => ['file' => "mtrk-sz-$dims.jpg", 'width' => (int) $dims, 'height' => (int) explode('x', $dims)[1], 'mime-type' => 'image/jpeg'];
foreach (['150x150', '750x500', '360x240', '768x512', '1170x600', '32x32'] as $dims) {
    file_put_contents("$dir/mtrk-sz-$dims.jpg", str_repeat('x', 1000));
}
file_put_contents("$dir/mtrk-sz-750x500.jpg.webp", 'x');
$meta = wp_get_attachment_metadata($id);
$meta['sizes'] = [
    'thumbnail' => $size('150x150'),     // registered
    'mtrk-old' => $size('750x500'),      // old theme size: delete (and its .webp)
    'mtrk-linked' => $size('360x240'),   // old, but a post names this exact file: keep
    'medium_large' => $size('768x512'),  // registered...
    'mtrk-twin' => $size('768x512'),     // ...old size with the same dimensions shares the file: keep
    'mtrk-a' => $size('1170x600'),       // two old sizes on one file: one file deleted, both entries removed
    'mtrk-b' => $size('1170x600'),
    'mtrk-offloaded' => $size('400x400'), // not on local disk: leave it alone
    'site_icon-32' => $size('32x32'),    // site icon sizes are never registered: keep
];
wp_update_attachment_metadata($id, $meta);
$made[] = wp_insert_post(['post_title' => 'mtrk sizes', 'post_status' => 'publish', 'post_content' => '<img src="' . dirname((string) wp_get_attachment_url($id)) . '/mtrk-sz-360x240.jpg">']);

$names = ['mtrk-old', 'mtrk-linked', 'mtrk-twin', 'mtrk-a', 'mtrk-b', 'mtrk-offloaded', 'site_icon-32'];
try {
    mtrk_fixture_scan(false, false);

    $report = $walk($names, false);
    $is(($report['mtrk-old']['files'] ?? 0) === 1 && $report['mtrk-old']['bytes'] === 1000, 'report: old size is deletable (1 file, 1000 bytes)');
    $is(($report['mtrk-linked']['kept'] ?? 0) === 1 && $report['mtrk-linked']['files'] === 0, 'report: exactly referenced file is kept');
    $is(($report['mtrk-twin']['kept'] ?? 0) === 1 && $report['mtrk-twin']['files'] === 0, 'report: file shared with medium_large is kept');
    $is(($report['mtrk-a']['files'] ?? 0) + ($report['mtrk-b']['files'] ?? 0) === 1, 'report: two old sizes on one file count once');
    $is(($report['mtrk-offloaded']['files'] ?? 0) === 0, 'report: file not on disk is not counted');
    $is(!isset($report['site_icon-32']), 'report: site icon size is not listed');
    $is(file_exists("$dir/mtrk-sz-750x500.jpg"), 'report deletes nothing');

    $walk($names, true);
    $sizes = wp_get_attachment_metadata($id)['sizes'];
    $is(!file_exists("$dir/mtrk-sz-750x500.jpg") && !file_exists("$dir/mtrk-sz-750x500.jpg.webp"), 'clean: old size and its .webp deleted');
    $is(!file_exists("$dir/mtrk-sz-1170x600.jpg") && !isset($sizes['mtrk-a']) && !isset($sizes['mtrk-b']), 'clean: shared old file deleted, both entries removed');
    $is(!isset($sizes['mtrk-old']), 'clean: metadata entry removed');
    foreach (['150x150', '360x240', '768x512', '32x32'] as $dims) {
        $is(file_exists("$dir/mtrk-sz-$dims.jpg"), "clean: kept mtrk-sz-$dims.jpg");
    }
    $is(file_exists((string) get_attached_file($id)), 'clean: original kept');
    $is(isset($sizes['thumbnail'], $sizes['medium_large'], $sizes['mtrk-linked'], $sizes['mtrk-twin'], $sizes['mtrk-offloaded'], $sizes['site_icon-32']), 'clean: kept entries still in metadata');
    $again = $walk($names, false);
    $is(array_sum(array_column($again, 'files')) === 0, 'clean: a second report finds nothing left to delete');
} finally {
    foreach (array_reverse($made) as $post) {
        wp_delete_post($post, true);
    }
    foreach (glob("$dir/mtrk-sz*") ?: [] as $file) {
        unlink($file);
    }
}

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
