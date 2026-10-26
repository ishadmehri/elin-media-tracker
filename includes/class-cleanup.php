<?php

namespace MediaTracker;

defined('ABSPATH') || exit;

/**
 * Acting on unreferenced media: the list itself, quarantine (files moved out of their public path; requests for
 * them are still answered and logged), restore, permanent delete, and "keep" for media used outside the site.
 * Attachments that share one file always move together. Nothing here touches media that has a reference.
 */
final class Cleanup
{
    public const META = '_mtrk_quarantine'; // ['lead', 'time', 'files', 'hits' => [kind => n], 'last', 'from']
    public const KEEP = '_mtrk_keep';
    private const MAP = 'mtrk_quarantine';  // option: upload-relative path => lead attachment id
    private const DIR = 'mtrk_quarantine_dir';
    private const PROBE = 'mtrk-probe-';
    private const CHUNK = 2000;

    // ---- the list ------------------------------------------------------------------------------------------------

    /**
     * One page of unreferenced media from the last full scan, not quarantined; with the total count and bytes.
     * @return array{items: array<int,int>, total: int, bytes: int} items: id => bytes on disk
     */
    public static function unused(int $page, int $per, string $type = '', string $order = 'largest'): array
    {
        global $wpdb;
        $last = Scanner::last();
        if (!$last) {
            return ['items' => [], 'total' => 0, 'bytes' => 0];
        }
        $refs = Scanner::table();
        $max = (int) $last['max_id'];
        $mime = ['image' => 'image/%', 'video' => 'video/%', 'audio' => 'audio/%', 'document' => 'application/%'][$type] ?? '';
        $where = "p.post_type = 'attachment' AND p.ID <= $max
            AND NOT EXISTS (SELECT 1 FROM $refs r WHERE r.attachment_id = p.ID)
            AND NOT EXISTS (SELECT 1 FROM $wpdb->postmeta q WHERE q.post_id = p.ID AND q.meta_key = '" . self::META . "')"
            . ($mime ? $wpdb->prepare(' AND p.post_mime_type LIKE %s', $mime) : '');

        // Bytes need each item's metadata; read it in chunks and keep only [id, date, bytes].
        $all = [];
        $cursor = 0;
        do {
            $rows = $wpdb->get_results("SELECT p.ID, p.post_date, m.meta_value FROM $wpdb->posts p
                LEFT JOIN $wpdb->postmeta m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
                WHERE $where AND p.ID > $cursor ORDER BY p.ID LIMIT " . self::CHUNK);
            foreach ($rows as $r) {
                $all[] = [(int) $r->ID, $r->post_date, self::bytes((int) $r->ID, maybe_unserialize($r->meta_value))];
            }
            $cursor = $rows ? (int) end($rows)->ID : 0;
        } while (count($rows) === self::CHUNK);

        usort($all, $order === 'largest' ? fn($a, $b) => $b[2] <=> $a[2]
            : ($order === 'oldest' ? fn($a, $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]] : fn($a, $b) => [$b[1], $b[0]] <=> [$a[1], $a[0]]));
        $items = [];
        foreach (array_slice($all, ($page - 1) * $per, $per) as [$id, , $bytes]) {
            $items[$id] = $bytes;
        }
        return ['items' => $items, 'total' => count($all), 'bytes' => array_sum(array_column($all, 2))];
    }

    /** Bytes of the main file and its sizes: from metadata (WP 6.0+), from disk when it isn't recorded. */
    public static function bytes(int $id, $meta): int
    {
        $file = (string) get_attached_file($id);
        $bytes = (int) ($meta['filesize'] ?? (is_file($file) ? filesize($file) : 0));
        foreach ((array) ($meta['sizes'] ?? []) as $s) {
            $bytes += (int) ($s['filesize'] ?? (@filesize(dirname($file) . '/' . ($s['file'] ?? '')) ?: 0));
        }
        return $bytes;
    }

    /** Quarantined groups, one row per group (its lead). @return array<int,array> lead id => quarantine record */
    public static function quarantined(): array
    {
        global $wpdb;
        $out = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s ORDER BY meta_id DESC", self::META)) as $r) {
            $q = maybe_unserialize($r->meta_value);
            if (is_array($q) && (int) ($q['lead'] ?? 0) === (int) $r->post_id) {
                $out[(int) $r->post_id] = $q;
            }
        }
        return $out;
    }

    // ---- actions ---------------------------------------------------------------------------------------------

    /** @return int[] ids quarantined (group members included) */
    public static function quarantine(array $ids): array
    {
        $map = (array) get_option(self::MAP, []);
        $base = self::basedir();
        $dir = self::qdir();
        $done = [];
        foreach (self::groups($ids) as $group) {
            if (!self::unreferenced($group) || get_post_meta($group[0], self::META, true)) {
                continue;
            }
            $moved = [];
            foreach (self::group_files($group) as $rel) {
                wp_mkdir_p(dirname("$dir/$rel"));
                if (@rename("$base/$rel", "$dir/$rel")) {
                    $moved[] = $rel;
                    $map[$rel] = $group[0];
                }
            }
            foreach ($group as $id) {
                update_post_meta($id, self::META, ['lead' => $group[0], 'time' => time(), 'files' => $moved, 'hits' => [], 'last' => 0, 'from' => '']);
                $done[] = $id;
            }
        }
        update_option(self::MAP, $map, false);
        return $done;
    }

    /** @return int[] ids restored */
    public static function restore(array $ids): array
    {
        $map = (array) get_option(self::MAP, []);
        $base = self::basedir();
        $dir = self::qdir();
        $done = [];
        foreach (self::groups($ids) as $group) {
            $q = get_post_meta($group[0], self::META, true);
            if (!is_array($q)) {
                continue;
            }
            foreach ((array) $q['files'] as $rel) {
                if (!file_exists("$base/$rel")) { // never overwrite a file uploaded since under the same name
                    wp_mkdir_p(dirname("$base/$rel"));
                    @rename("$dir/$rel", "$base/$rel");
                }
                unset($map[$rel]);
            }
            foreach ($group as $id) {
                delete_post_meta($id, self::META);
                $done[] = $id;
            }
        }
        update_option(self::MAP, $map, false);
        return $done;
    }

    /**
     * Permanent delete: attachment posts and all their files. Only for quarantined or still unreferenced groups.
     * @return array{ids: int[], bytes: int}
     */
    public static function delete(array $ids): array
    {
        $map = (array) get_option(self::MAP, []);
        $dir = self::qdir();
        $out = ['ids' => [], 'bytes' => 0];
        foreach (self::groups($ids) as $group) {
            $q = get_post_meta($group[0], self::META, true);
            if (is_array($q)) {
                foreach ((array) $q['files'] as $rel) {
                    $out['bytes'] += (int) @filesize("$dir/$rel");
                    wp_delete_file("$dir/$rel");
                    unset($map[$rel]);
                }
            } elseif (self::unreferenced($group)) {
                $base = self::basedir();
                foreach (self::group_files($group) as $rel) { // WordPress would leave the WebP/AVIF copies behind
                    $out['bytes'] += (int) @filesize("$base/$rel");
                    wp_delete_file("$base/$rel");
                }
            } else {
                continue;
            }
            foreach ($group as $id) {
                wp_delete_attachment($id, true);
                $out['ids'][] = $id;
            }
        }
        update_option(self::MAP, $map, false);
        return $out;
    }

    /** Marks media used outside the site (newsletters, other sites) as used, or unmarks it. */
    public static function keep(array $ids, bool $on): array
    {
        global $wpdb;
        $done = [];
        foreach (self::groups($ids) as $group) {
            foreach ($group as $id) {
                $on ? update_post_meta($id, self::KEEP, 1) : delete_post_meta($id, self::KEEP);
                $wpdb->delete(Scanner::table(), ['attachment_id' => $id, 'source_type' => 'keep']);
                if ($on) {
                    $wpdb->insert(Scanner::table(), ['attachment_id' => $id, 'source_type' => 'keep', 'source_id' => 0, 'source_key' => '', 'how' => 'id']);
                }
                $done[] = $id;
            }
        }
        return $done;
    }

    /** An attachment deleted elsewhere (media library) takes its quarantined files and map entries with it. */
    public static function forget(int $id): void
    {
        $q = get_post_meta($id, self::META, true);
        if (!is_array($q) || (int) $q['lead'] !== $id) {
            return;
        }
        $map = (array) get_option(self::MAP, []);
        foreach ((array) $q['files'] as $rel) {
            wp_delete_file(self::qdir() . "/$rel");
            unset($map[$rel]);
        }
        update_option(self::MAP, $map, false);
    }

    // ---- requests for quarantined files ----------------------------------------------------------------------

    /**
     * Requests for missing upload files reach WordPress (nginx try_files / Apache rewrite). For a quarantined file,
     * log the hit and answer it from quarantine so nothing breaks while you wait. Hooked very early.
     */
    public static function serve(): void
    {
        $match = self::match((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if ($match === 'probe') {
            status_header(204);
            header('X-Mtrk: ok');
            exit;
        }
        if (!$match) {
            return;
        }
        [$lead, $file] = $match;
        self::hit($lead, (string) ($_SERVER['HTTP_REFERER'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        status_header(200);
        header('Content-Type: ' . (wp_check_filetype($file)['type'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: private, max-age=300'); // a CDN must not keep serving it after you decide
        readfile($file);
        exit;
    }

    /** @return array{0:int,1:string}|string|null [lead id, quarantine file], 'probe', or null when not ours */
    public static function match(string $uri)
    {
        $path = rawurldecode((string) wp_parse_url($uri, PHP_URL_PATH));
        if (!preg_match('/\.[a-z0-9]{2,5}$/i', $path) || substr($path, -4) === '.php') {
            return null; // a page, not a file: the cheap exit for nearly every request
        }
        $base = (string) wp_parse_url(wp_upload_dir(null, false)['baseurl'], PHP_URL_PATH);
        if ($base === '' || strpos($path, "$base/") !== 0) {
            return null;
        }
        $rel = substr($path, strlen($base) + 1);
        if (strpos(wp_basename($rel), self::PROBE) === 0) {
            return 'probe';
        }
        $map = get_option(self::MAP);
        if (!is_array($map) || !isset($map[$rel]) || !is_file(self::qdir() . "/$rel")) {
            return null;
        }
        return [(int) $map[$rel], self::qdir() . "/$rel"];
    }

    /** Kinds: google (Google search/images), site (one of your pages: a reference the scan missed), external, bot, direct. */
    public static function hit(int $lead, string $referer, string $agent): void
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $from = (string) wp_parse_url($referer, PHP_URL_HOST);
        if ($from === $host && strpos($referer, '/wp-admin/') !== false) {
            return; // your own admin screens (previews in this plugin, the media library)
        }
        if (preg_match('/bot|crawl|spider|slurp/i', $agent)) {
            $kind = 'bot';
        } elseif ($from === '') {
            $kind = 'direct';
        } elseif ($from === $host) {
            $kind = 'site';
        } else {
            $kind = preg_match('/(^|\.)google\./', $from) ? 'google' : 'external';
        }
        $q = get_post_meta($lead, self::META, true);
        if (!is_array($q)) {
            return;
        }
        // ponytail: one meta write per request; a quarantined file that gets many requests should be restored anyway.
        $q['hits'][$kind] = ($q['hits'][$kind] ?? 0) + 1;
        $q['last'] = time();
        if ($referer !== '') {
            $q['from'] = esc_url_raw($referer);
        }
        update_post_meta($lead, self::META, $q);
    }

    public static function probe_url(): string
    {
        return wp_upload_dir(null, false)['baseurl'] . '/' . self::PROBE . wp_generate_password(8, false) . '.jpg';
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    /** Upload-relative paths of every file of these attachments that exists, with WebP/AVIF copies. */
    public static function group_files(array $group): array
    {
        $base = self::basedir();
        $out = [];
        foreach ($group as $id) {
            $file = ltrim(wp_normalize_path((string) get_post_meta($id, '_wp_attached_file', true)), '/');
            if ($file === '') {
                continue;
            }
            $dir = dirname($file) === '.' ? '' : dirname($file) . '/';
            $names = [wp_basename($file)];
            $meta = wp_get_attachment_metadata($id);
            if (is_array($meta)) {
                $names[] = $meta['original_image'] ?? '';
                foreach ((array) ($meta['sizes'] ?? []) as $s) {
                    $names[] = $s['file'] ?? '';
                }
            }
            foreach ((array) get_post_meta($id, '_wp_attachment_backup_sizes', true) as $s) {
                $names[] = is_array($s) ? ($s['file'] ?? '') : '';
            }
            foreach (array_filter($names) as $name) {
                foreach (self::copies("$base/$dir$name") as $abs) {
                    $out[substr($abs, strlen($base) + 1)] = true;
                }
            }
        }
        return array_keys($out);
    }

    /** The file plus the WebP/AVIF copies optimization plugins put next to it: those that exist. */
    public static function copies(string $path): array
    {
        $bare = preg_replace('/\.[^.\/]+$/', '', $path);
        return array_values(array_filter(array_unique([$path, "$path.webp", "$path.avif", "$bare.webp", "$bare.avif"]), 'is_file'));
    }

    /** @return int[] the attachments sharing $id's file, itself included */
    public static function group_ids(int $id): array
    {
        return self::groups([$id])[0] ?? [$id];
    }

    /** @return int[][] the groups (attachments sharing a file) these ids belong to, each sorted, lead first */
    private static function groups(array $ids): array
    {
        global $wpdb;
        $out = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if (get_post_type($id) !== 'attachment') {
                continue;
            }
            $group = Scanner::twins($id);
            $out[$group[0]] = $group;
        }
        return array_values($out);
    }

    private static function unreferenced(array $group): bool
    {
        if (!Scanner::last()) {
            return false;
        }
        $counts = Scanner::counts($group);
        foreach ($group as $id) {
            if (($counts[$id] ?? 0) > 0) {
                return false;
            }
        }
        return true;
    }

    private static function basedir(): string
    {
        return untrailingslashit(wp_normalize_path(wp_upload_dir(null, false)['basedir']));
    }

    /** uploads/mtrk-quarantine-<random>: not guessable, and closed to direct requests where .htaccess works. */
    private static function qdir(): string
    {
        $name = get_option(self::DIR);
        if (!$name) {
            $name = 'mtrk-quarantine-' . strtolower(wp_generate_password(12, false));
            update_option(self::DIR, $name, false);
        }
        $dir = self::basedir() . "/$name";
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents("$dir/index.php", "<?php // Silence is golden.\n");
            file_put_contents("$dir/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        return $dir;
    }
}
