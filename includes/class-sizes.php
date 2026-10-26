<?php

namespace MediaTracker;

defined('ABSPATH') || exit;

/**
 * Files of image sizes no theme or plugin registers any more (e.g. the previous theme's sizes). WordPress never
 * serves them again, but they stay on disk for as long as the image is used.
 *
 * A file is kept when a reference names it exactly (recorded by the scan), when a registered size of the same
 * image uses the same file (same dimensions), when it is the site icon, or when it isn't on local disk (offloaded).
 */
final class Sizes
{
    private const BATCH = 200;

    /**
     * One batch of attachments, by postmeta id. Report-only unless $delete; $names limits it to those sizes.
     * @return array{next: ?int, count: int, sizes: array<string,array{files:int,bytes:int,kept:int,dims:string}>}
     */
    public static function step(int $cursor, array $names = [], bool $delete = false): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_attachment_metadata' AND meta_id > %d ORDER BY meta_id LIMIT %d",
            $cursor,
            self::BATCH
        ));
        $registered = array_keys(wp_get_registered_image_subsizes());
        $icon = (int) get_option('site_icon');
        $out = [];

        // Pass 1: candidate files per attachment, so exact references are checked in one query per batch.
        $plan = [];
        $stems = [];
        foreach ($rows as $r) {
            $meta = maybe_unserialize($r->meta_value);
            if (!is_array($meta) || empty($meta['sizes']) || (int) $r->post_id === $icon || empty($meta['file'])) {
                continue;
            }
            $active = [wp_basename($meta['file'])];
            foreach ($meta['sizes'] as $name => $s) {
                if (self::active((string) $name, $registered)) {
                    $active[] = $s['file'] ?? '';
                }
            }
            $reldir = dirname($meta['file']);
            foreach ($meta['sizes'] as $name => $s) {
                if (self::active((string) $name, $registered) || empty($s['file']) || ($names && !in_array($name, $names, true))) {
                    continue;
                }
                $stem = ($reldir === '.' ? '' : "$reldir/") . $s['file'];
                $stems[] = $stem;
                $plan[(int) $r->post_id][] = [(string) $name, $s, $stem, in_array($s['file'], $active, true)];
            }
            $plan[(int) $r->post_id]['meta'] = $meta;
        }
        $referenced = Scanner::referenced($stems);

        // Pass 2: count (and delete) what is safe.
        $basedir = wp_upload_dir(null, false)['basedir'];
        foreach ($plan as $id => $sizes) {
            $meta = $sizes['meta'];
            unset($sizes['meta']);
            $done = [];
            foreach ($sizes as [$name, $s, $stem, $shared]) {
                $o = &$out[$name];
                $o = ($o ?? []) + ['files' => 0, 'bytes' => 0, 'kept' => 0, 'dims' => ($s['width'] ?? '?') . '×' . ($s['height'] ?? '?')];
                $path = "$basedir/$stem";
                if ($shared || isset($referenced[md5(Scanner::stem($stem))])) {
                    $o['kept']++;
                    continue;
                }
                if (!isset($done[$s['file']])) { // two old sizes with the same dimensions share one file
                    if (!is_file($path)) {
                        continue; // offloaded or already gone: leave the metadata alone
                    }
                    $done[$s['file']] = true;
                    $o['files']++;
                    $o['bytes'] += (int) filesize($path);
                    if ($delete) {
                        self::unlink($path);
                    }
                }
                unset($meta['sizes'][$name]);
            }
            unset($o);
            if ($delete && $done) {
                wp_update_attachment_metadata($id, $meta);
            }
        }

        return ['next' => $rows ? (int) end($rows)->meta_id : null, 'count' => count($rows), 'sizes' => $out];
    }

    private static function active(string $name, array $registered): bool
    {
        return in_array($name, $registered, true) || strpos($name, 'site_icon') === 0;
    }

    /** The file plus the WebP/AVIF copies optimization plugins put next to it. */
    private static function unlink(string $path): void
    {
        $bare = preg_replace('/\.[^.\/]+$/', '', $path);
        foreach ([$path, "$path.webp", "$path.avif", "$bare.webp", "$bare.avif"] as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
    }
}
