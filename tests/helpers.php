<?php
/** Shared by the eval-file checks: fixture media, a synchronous scan, and PASS/FAIL output. */

use MediaTracker\Scanner;

defined('ABSPATH') || exit;

wp_set_current_user((int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0));

/** A real attachment with a real 2×2 file in uploads/2026/10, so plugins that touch the file don't choke. */
function mtrk_fixture_media(string $file, array &$made): int
{
    $dir = wp_upload_dir('2026/10');
    wp_mkdir_p($dir['path']);
    $path = $dir['path'] . '/' . $file;
    if (function_exists('imagecreatetruecolor')) {
        imagejpeg(imagecreatetruecolor(2, 2), $path);
    } else {
        file_put_contents($path, 'x');
    }
    $id = wp_insert_attachment(['post_title' => "mtrk $file", 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], $path);
    update_post_meta($id, '_wp_attachment_metadata', ['file' => "2026/10/$file", 'width' => 2, 'height' => 2, 'sizes' => []]);
    $made[] = $id;
    return $id;
}

function mtrk_fixture_scan(bool $code = true, bool $tables = true): void
{
    $started = microtime(true);
    Scanner::start($code, $tables);
    do {
        $p = Scanner::run_step();
    } while (empty($p['done']));
    printf("scan took %.1fs\n", microtime(true) - $started);
}

/** @param array<int,array{0:bool,1:string}> $expect attachment id => [expected used?, what] */
function mtrk_fixture_assert(array $expect, int &$fail): void
{
    $counts = Scanner::counts(array_keys($expect));
    foreach ($expect as $id => [$used, $what]) {
        $ok = Scanner::status($id, $counts[$id] ?? 0) === ($used ? 'used' : 'unused');
        echo ($ok ? 'PASS ' : 'FAIL ') . ($used ? 'used:   ' : 'unused: ') . $what . "\n";
        if (getenv('MTRK_VERBOSE')) { // where each reference was found
            foreach (Scanner::refs($id) as $r) {
                echo "       {$r['source_type']} #{$r['source_id']} {$r['source_key']} ({$r['how']})\n";
            }
        }
        $fail += $ok ? 0 : 1;
    }
}
