<?php
/**
 * Files on disk that no attachment owns: what is listed, what is deletable, what must never be touched.
 * Truncates scan results.
 *
 * Run: wp eval-file wp-content/plugins/media-tracker/tests/orphans.php
 */

use MediaTracker\Orphans;

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

$fail = 0;
$made = [];
$is = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . "$what\n";
    $fail += $ok ? 0 : 1;
};
$base = wp_upload_dir()['basedir'];
$dir = "$base/2026/10";
$plugin_dir = "$base/mtrk-plugin-uploads"; // like gravity_forms/: no attachments in it
wp_mkdir_p($plugin_dir);
$write = fn(string $path) => file_put_contents($path, str_repeat('x', 500));

$id = mtrk_fixture_media('mtrk-orph-scaled.jpg', $made);
$meta = wp_get_attachment_metadata($id);
$meta['original_image'] = 'mtrk-orph.jpg';
$meta['sizes'] = ['thumbnail' => ['file' => 'mtrk-orph-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg']];
wp_update_attachment_metadata($id, $meta);
update_post_meta($id, '_wp_attachment_backup_sizes', ['full-orig' => ['file' => 'mtrk-orph-bak.jpg', 'width' => 2, 'height' => 2]]);
foreach (['mtrk-orph.jpg', 'mtrk-orph-150x150.jpg', 'mtrk-orph-bak.jpg', 'mtrk-orph-scaled.jpg.webp', 'mtrk-orph-150x150.webp',
          'mtrk-orph-640x480.jpg', 'mtrk-orph-640x480.webp', 'mtrk-orph-800x600.jpg', 'mtrk-ftp.jpg', 'mtrk-ftp-linked.png', 'mtrk-x.php'] as $f) {
    $write("$dir/$f");
}
$write("$plugin_dir/mtrk-form-upload.jpg");
$url = dirname((string) wp_get_attachment_url($id));
$made[] = wp_insert_post(['post_title' => 'mtrk orphans', 'post_status' => 'publish', 'post_content' => "<img src=\"$url/mtrk-orph-800x600.jpg\"><img src=\"$url/mtrk-ftp-linked.png\">"]);

try {
    mtrk_fixture_scan(false, false);

    $listed = [];
    $cursor = 0;
    do {
        $r = Orphans::step($cursor);
        foreach ($r['files'] as $f) {
            if (strpos($f['path'], 'mtrk-') !== false) {
                $listed[wp_basename($f['path'])] = $f;
            }
        }
        $cursor = $r['next'];
    } while ($cursor !== null);

    $is(($listed['mtrk-orph-640x480.jpg']['type'] ?? '') === 'size' && $listed['mtrk-orph-640x480.jpg']['owner'] === $id && !$listed['mtrk-orph-640x480.jpg']['kept'], 'leftover size: listed, owner found, deletable');
    $is(($listed['mtrk-orph-640x480.webp']['type'] ?? '') === 'size', 'leftover size webp copy: listed too');
    $is(($listed['mtrk-orph-800x600.jpg']['kept'] ?? false) === true, 'leftover size named in a post: kept');
    $is(($listed['mtrk-ftp.jpg']['type'] ?? '') === 'file' && !$listed['mtrk-ftp.jpg']['kept'] && $listed['mtrk-ftp.jpg']['bytes'] === 500, 'file not in library: listed, deletable, 500 bytes');
    $is(($listed['mtrk-ftp-linked.png']['kept'] ?? false) === true, 'file not in library but named in a post: kept');
    foreach (['mtrk-orph-scaled.jpg' => 'main file', 'mtrk-orph.jpg' => 'original before -scaled', 'mtrk-orph-150x150.jpg' => 'registered size',
              'mtrk-orph-bak.jpg' => 'image editor backup', 'mtrk-orph-scaled.jpg.webp' => 'webp copy of main', 'mtrk-orph-150x150.webp' => 'webp copy of a size',
              'mtrk-x.php' => 'php file', 'mtrk-form-upload.jpg' => 'file in a folder without attachments'] as $name => $what) {
        $is(!isset($listed[$name]), "not listed: $what");
    }

    $out = Orphans::delete(['2026/10/mtrk-orph-640x480.jpg', '2026/10/mtrk-ftp.jpg', '2026/10/mtrk-ftp-linked.png', '2026/10/mtrk-orph-scaled.jpg', '../wp-config.php', 'mtrk-plugin-uploads/mtrk-form-upload.jpg']);
    $is($out['files'] === 2 && $out['bytes'] === 1000 && $out['skipped'] === 4, 'delete: only the 2 deletable paths, the other 4 refused');
    $is(!file_exists("$dir/mtrk-orph-640x480.jpg") && !file_exists("$dir/mtrk-ftp.jpg"), 'delete: files gone');
    foreach (['mtrk-ftp-linked.png', 'mtrk-orph-scaled.jpg', 'mtrk-orph-800x600.jpg', 'mtrk-orph.jpg'] as $f) {
        $is(file_exists("$dir/$f"), "delete: kept $f");
    }
    $is(file_exists("$plugin_dir/mtrk-form-upload.jpg") && file_exists(ABSPATH . 'wp-config.php'), 'delete: refused paths untouched');
} finally {
    foreach (array_reverse($made) as $post) {
        wp_delete_post($post, true);
    }
    foreach (array_merge(glob("$dir/mtrk-orph*") ?: [], glob("$dir/mtrk-ftp*") ?: [], glob("$dir/mtrk-x.php") ?: [], glob("$plugin_dir/*") ?: []) as $f) {
        unlink($f);
    }
    @rmdir($plugin_dir);
}

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
