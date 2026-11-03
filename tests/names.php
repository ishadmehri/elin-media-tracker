<?php
/**
 * Non-Latin file names: real uploads (media_handle_sideload, so WordPress sanitizes and NFC-normalizes the name
 * as it does for the upload form), each referenced in exactly one encoding form. Truncates scan results.
 *
 * Run: wp eval-file wp-content/plugins/elinweb-unused-media-finder/tests/names.php
 */

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$fail = 0;
$made = [];
$expect = [];
$upload = function (string $name) use (&$made): int {
    $tmp = wp_tempnam($name);
    imagejpeg(imagecreatetruecolor(2, 2), $tmp);
    $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], 0);
    if (is_wp_error($id)) {
        WP_CLI::error($id->get_error_message());
    }
    $made[] = $id;
    return $id;
};
// Each form takes the URL WordPress gives for the attachment and writes it the way some editor or tool would.
$forms = [
    'raw' => fn(string $u) => $u,
    'percent-encoded, sized' => fn(string $u) => implode('/', array_map('rawurlencode', explode('/', preg_replace('/\.jpg$/i', '-300x200.jpg', $u)))),
    'NFD (decomposed)' => fn(string $u) => Normalizer::normalize($u, Normalizer::FORM_D),
    'HTML entities' => fn(string $u) => mb_encode_numericentity($u, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'), // last item is a bit mask
    'JSON \u-escaped' => fn(string $u) => $u, // stored through wp_json_encode below
];
$names = ['آموزش عکس‌ها.jpg', 'صورة.jpg', '图片.jpg', 'Фото.JPG', 'گل🌹.jpg'];

$content = [];
$json = [];
foreach ($names as $name) {
    foreach ($forms as $form => $encode) {
        $id = $upload($name);
        $ref = $encode((string) wp_get_attachment_url($id));
        $expect[$id] = [true, "$form: " . wp_basename((string) get_attached_file($id))];
        if ($form === 'JSON \u-escaped') {
            $json[] = ['id' => 'x' . $id, 'settings' => ['image' => ['url' => $ref, 'id' => '']]];
        } else {
            $content[] = '<img src="' . $ref . '">';
        }
    }
}
$unused = $upload('بدون-استفاده.jpg');
$expect[$unused] = [false, 'Persian name, referenced nowhere'];

$p = wp_insert_post(['post_title' => 'mtrk names', 'post_status' => 'publish', 'post_content' => implode("\n", $content)]);
$made[] = $p;
update_post_meta($p, '_elementor_data', wp_slash(wp_json_encode($json)));
echo 'sample JSON: ' . substr((string) get_post_meta($p, '_elementor_data', true), 0, 120) . "\n";

try {
    mtrk_fixture_scan(false, false);
    mtrk_fixture_assert($expect, $fail);
} finally {
    foreach (array_reverse($made) as $id) {
        wp_delete_post($id, true);
    }
}

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
