<?php
/**
 * End-to-end check: creates fixture media and one reference of each kind, runs a full scan (DB + code + plugin
 * tables), asserts every status, then removes the fixtures. It truncates previous scan results.
 *
 * Run: wp eval-file wp-content/plugins/elin-media-tracker/tests/check.php
 */

use MediaTracker\Admin;
use MediaTracker\Cleanup;
use MediaTracker\Scanner;

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

global $wpdb;

$fail = 0;
$is = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
    $fail += $ok ? 0 : 1;
};

foreach ([
    '2024/05/a-300x200.jpg.webp' => '2024/05/a',
    '2024/05/a-scaled.jpg' => '2024/05/a',
    '2024/05/a-e1699999999999-150x150.jpg' => '2024/05/a',
    '/2024/05/My.Photo.JPG' => '2024/05/my',
] as $in => $want) {
    $is(Scanner::key($in) === $want, "key($in)");
}

// A single-byte process locale (Windows-1252, ISO-8859-x) must not corrupt UTF-8 names (strtolower did before PHP 8.2).
$locale = setlocale(LC_CTYPE, 0);
setlocale(LC_CTYPE, 'English_United States.1252', 'de_DE.ISO-8859-1', 'fr_FR.ISO-8859-1', 'C');
$is(mb_check_encoding(Scanner::key('2026/10/گل🌹-1.jpg'), 'UTF-8'), 'key: UTF-8 name survives a single-byte locale');
setlocale(LC_CTYPE, $locale);

$posts = [];
$media = function (string $file) use (&$posts): int {
    $id = wp_insert_post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => "mtrk $file", 'post_mime_type' => 'image/jpeg']);
    update_post_meta($id, '_wp_attached_file', "2026/10/$file");
    update_post_meta($id, '_wp_attachment_metadata', ['file' => "2026/10/$file", 'sizes' => []]);
    $posts[] = $id;
    return $id;
};
$post = function (array $args) use (&$posts): int {
    $id = wp_insert_post($args + ['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'mtrk fixture']);
    $posts[] = $id;
    return $id;
};

$base = wp_upload_dir()['baseurl'];
$cdn = 'https://cdn.example.com';
$expect = [ // attachment id => [expected used?, what]
    ($a = $media('mtrk-a.jpg')) => [true, 'URL with size suffix in content'],
    ($b = $media('mtrk-b.jpg')) => [true, 'featured image'],
    ($c = $media('mtrk-c.jpg')) => [true, 'wp-image-N class only'],
    ($d = $media('mtrk-d.jpg')) => [true, '[gallery ids]'],
    ($e = $media('mtrk-e.jpg')) => [true, 'WooCommerce product gallery'],
    ($f = $media('mtrk-f.jpg')) => [true, 'Elementor id (URL on a CDN)'],
    ($g = $media('عکس-g.jpg')) => [true, 'Persian name, \u-escaped in Elementor JSON'],
    ($h = $media('mtrk-h.jpg')) => [true, 'ACF image field'],
    ($i = $media('mtrk-i.jpg')) => [true, 'serialized widget option'],
    ($j = $media('mtrk-j.jpg')) => [true, 'term meta thumbnail_id'],
    ($k = $media('mtrk-k.jpg')) => [true, 'relative path in uploads CSS'],
    ($l = $media('mtrk-l.jpg')) => [true, 'plugin table'],
    ($m = $media('mtrk-m.jpg')) => [true, 'core image block id'],
    ($rev = $media('mtrk-rev.jpg')) => [false, 'only in a revision'],
    ($num = $media('mtrk-num.jpg')) => [false, 'same number in an ACF number field'],
    ($self = $media('mtrk-self.jpg')) => [false, 'only in its own meta'],
    ($none = $media('mtrk-none.jpg')) => [false, 'nowhere'],
    ($logged = $media('mtrk-logged.jpg')) => [false, 'only in a security log option, a scanner option, Yoast indexables and a log post type'],
    ($twin_a = $media('mtrk-twin.jpg')) => [true, 'shared file: the one used (by id only)'],
    ($twin_b = $media('mtrk-twin.jpg')) => [true, 'shared file: its twin pointing at the same file'],
    ($idle_a = $media('mtrk-idle.jpg')) => [false, 'shared file, neither used (1)'],
    ($idle_b = $media('mtrk-idle.jpg')) => [false, 'shared file, neither used (2)'],
];

$p1 = $post(['post_content' => implode("\n", [
    "<p><img src=\"$base/2026/10/mtrk-a-300x200.jpg\"></p>",
    "<img class=\"wp-image-$c\" src=\"$cdn/c.jpg\">",
    "[gallery ids=\"$d, 999999\"]",
    "<!-- wp:image {\"id\":$m,\"sizeSlug\":\"large\"} -->",
    "<figure class=\"wp-block-image\"><img src=\"$cdn/m.jpg\" alt=\"\"/></figure>",
    '<!-- /wp:image -->',
])]);
update_post_meta($p1, '_thumbnail_id', $b);
update_post_meta($p1, '_elementor_data', wp_slash(wp_json_encode([[
    'id' => 'a1b2c3d',
    'elType' => 'widget',
    'settings' => ['image' => ['url' => "$cdn/f.jpg", 'id' => $f], 'bg' => ['url' => "$base/2026/10/عکس-g.jpg", 'id' => '']],
]])));
$posts[] = wp_insert_post(['post_type' => 'acf-field', 'post_status' => 'publish', 'post_name' => 'field_mtrkimg', 'post_excerpt' => 'hero', 'post_content' => serialize(['type' => 'image'])]);
$posts[] = wp_insert_post(['post_type' => 'acf-field', 'post_status' => 'publish', 'post_name' => 'field_mtrknum', 'post_excerpt' => 'rating', 'post_content' => serialize(['type' => 'number'])]);
update_post_meta($p1, 'hero', (string) $h);
update_post_meta($p1, '_hero', 'field_mtrkimg');
update_post_meta($p1, 'rating', (string) $num);
update_post_meta($p1, '_rating', 'field_mtrknum');
update_post_meta($self, '_mtrk_note', "$base/2026/10/mtrk-self.jpg");

$p2 = $post(['post_type' => 'product', 'post_status' => 'draft']);
update_post_meta($p2, '_product_image_gallery', "$e,999998");
update_post_meta($p2, '_thumbnail_id', $twin_a);

$r = $post(['post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $p1, 'post_name' => "$p1-revision-v1", 'post_content' => "<img src=\"$base/2026/10/mtrk-rev.jpg\">"]);
add_metadata('post', $r, '_elementor_data', wp_slash(wp_json_encode([['settings' => ['image' => ['id' => $rev]]]])));

update_option('mtrkfix_widget', [2 => ['attachment_id' => $i, 'url' => "$cdn/i.jpg"]], false);
$itsec = get_option('itsec_file_change_latest', null); // a real site may have it: restore afterwards
update_option('itsec_file_change_latest', ['changed' => ["$base/2026/10/mtrk-logged.jpg" => ['h' => 'x']]], false);
$post(['post_type' => 'mtrkfix_logs', 'post_content' => "file changed: $base/2026/10/mtrk-logged.jpg"]);
$titan = get_option('titan_scanner', null);
update_option('titan_scanner', ['files' => ["$base/2026/10/mtrk-logged.jpg"]], false);
$yoast = $wpdb->prefix . 'yoast_indexable'; // Yoast's own cache of every post, the attachment's own url included
$made_yoast = !$wpdb->get_var("SHOW TABLES LIKE '$yoast'");
if ($made_yoast) {
    $wpdb->query("CREATE TABLE `$yoast` (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, permalink longtext, open_graph_image longtext)");
    $wpdb->insert($yoast, ['permalink' => "$base/2026/10/mtrk-logged.jpg", 'open_graph_image' => "$base/2026/10/mtrk-logged.jpg"]);
}
$term = wp_insert_term('mtrk fixture ' . time(), 'category');
update_term_meta($term['term_id'], 'thumbnail_id', $j);

$css = wp_upload_dir()['basedir'] . '/mtrk-fixture.css';
file_put_contents($css, '.x{background:url(../2026/10/mtrk-k.jpg)}');

$table = $wpdb->prefix . 'mtrkfix_slides';
$wpdb->query("CREATE TABLE `$table` (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, data longtext)");
$wpdb->insert($table, ['data' => serialize(['bg' => "$base/2026/10/mtrk-l.jpg"])]);

try {
    mtrk_fixture_scan();
    mtrk_fixture_assert($expect, $fail);
    $sources = array_column(Scanner::refs($a), 'source_id');
    $is($sources === [(string) $p1], 'refs point at the right post');

    // The usage dialog must survive a source with no edit link (a term of a taxonomy without an edit screen).
    $lc = wp_insert_term('mtrk lc ' . time(), 'link_category');
    try {
        $item = new ReflectionMethod(Admin::class, 'item');
        $item->setAccessible(true); // needed before PHP 8.1
        $item->invoke(null, [['attachment_id' => $a, 'source_type' => 'term', 'source_id' => (int) $lc['term_id'], 'source_key' => 'thumbnail_id', 'how' => 'id']]);
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    wp_delete_term((int) $lc['term_id'], 'link_category');
    $is($ok, 'usage dialog: a source without an edit link does not break it');

    // Safety guard: a scan that loses featured images must not be trusted with deletions.
    $is(Scanner::trusted() && Scanner::missed_featured() === [], 'guard: a clean scan is trusted, no featured image missed');
    $wpdb->delete(Scanner::table(), ['attachment_id' => $b]);
    $is(Scanner::missed_featured() === [$b], 'guard: a featured image without references is caught');
    $last = Scanner::last();
    $last['suspect'] = [$b];
    update_option('mtrk_last_scan', $last, false);
    $is(!Scanner::trusted() && Cleanup::delete([$none])['ids'] === [] && Cleanup::quarantine([$none]) === [], 'guard: a suspect scan refuses delete and quarantine');
    $wpdb->query('DROP TABLE ' . Scanner::files_table()); // the index failing on a host
    $before = Scanner::last();
    $is(isset(Scanner::start(false, false)['error']) && Scanner::last() === $before, 'guard: a broken index stops the scan and keeps the previous result');
    Scanner::install();
    mtrk_fixture_scan(false, false);
    $is(Scanner::trusted(), 'guard: the next clean scan is trusted again');
} finally {
    foreach (array_reverse($posts) as $id) {
        wp_delete_post($id, true);
    }
    delete_option('mtrkfix_widget');
    $itsec === null ? delete_option('itsec_file_change_latest') : update_option('itsec_file_change_latest', $itsec, false);
    $titan === null ? delete_option('titan_scanner') : update_option('titan_scanner', $titan, false);
    $made_yoast && $wpdb->query("DROP TABLE IF EXISTS `$yoast`");
    wp_delete_term($term['term_id'], 'category');
    @unlink($css);
    $wpdb->query("DROP TABLE IF EXISTS `$table`");
}

$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Scanner::table() . ' WHERE attachment_id IN (' . implode(',', array_keys($expect)) . ')');
$is($left === 0, 'deleting fixtures removed their refs');

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
