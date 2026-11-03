<?php

namespace Elinweb\UnusedMediaFinder;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- This class works on the plugin's own index tables, which only exist here (no core API, so no cache to use).
// Every dynamic part of a query is a table name built from $wpdb->prefix, an int cast with (int)/intval/absint, an md5 hash,
// or a fragment already built with $wpdb->prepare(). Identifiers cannot be placeholders before WordPress 6.2 (minimum is 6.0).

/**
 * Attachments whose files have identical content (the same image uploaded more than once). Sizes are compared
 * first (from metadata, no disk reads); only files sharing a size get an MD5 of their content. Results live in
 * {prefix}mtrk_hashes, keyed by the file they were computed for, so full scans don't wipe them and a changed
 * file is recomputed. Attachments that share one file (media translations) are one file, not duplicates.
 *
 * Optionally the extra files (on disk, owned by no attachment; see Orphans) take part too: they are listed into
 * {prefix}mtrk_xhashes for the length of one check, and a stray copy of a library file shows up in its group.
 */
final class Duplicates
{
    private const BUDGET = 8; // seconds per request
    private const RUN = 'mtrk_dups_run'; // the check in progress: [extras?, next folder cursor or null]
    public const WITH_EXTRAS = 'mtrk_dups_x'; // the last finished check included extra files

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mtrk_hashes';
    }

    public static function xtable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mtrk_xhashes';
    }

    /** Created on first use: most sites never turn the option on. */
    private static function install_x(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta('CREATE TABLE ' . self::xtable() . " (
            path_hash char(32) NOT NULL,
            path varchar(500) NOT NULL DEFAULT '',
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            content_hash varchar(32) NOT NULL DEFAULT '',
            kept tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (path_hash),
            KEY file_size (file_size),
            KEY content_hash (content_hash)
        ) " . $wpdb->get_charset_collate() . ';');
    }

    /** One request of work. @return array{done: bool, phase: string, left: int} */
    public static function step(bool $start = false, bool $extras = false): array
    {
        global $wpdb;
        Scanner::ensure_index();
        $files = Scanner::files_table();
        $table = self::table();
        $xt = self::xtable();
        $deadline = microtime(true) + self::BUDGET;

        if ($start) {
            $wpdb->query("DROP TABLE IF EXISTS $xt");
            if ($extras) {
                self::install_x();
            }
            update_option(self::RUN, ['x' => $extras, 'cursor' => $extras ? 0 : null], false);
        }
        $run = (array) get_option(self::RUN, []) + ['x' => false, 'cursor' => null];

        // 1. Sizes of attachments not measured yet (or whose file changed since).
        $missing = "FROM $files f LEFT JOIN $table h ON h.attachment_id = f.attachment_id AND h.file_hash = f.file_hash WHERE h.attachment_id IS NULL";
        do {
            $rows = $wpdb->get_results("SELECT f.attachment_id, f.file_hash $missing LIMIT 500", ARRAY_N);
            if ($rows) {
                update_meta_cache('post', array_column($rows, 0)); // one query for the batch's metadata
            }
            $values = [];
            foreach ($rows as [$id, $file_hash]) {
                $meta = wp_get_attachment_metadata((int) $id);
                $path = (string) get_attached_file((int) $id);
                $size = (int) ($meta['filesize'] ?? 0) ?: (is_file($path) ? (int) filesize($path) : 0);
                $values[] = $wpdb->prepare('(%d,%s,%d,%s)', $id, $file_hash, $size, '');
            }
            if ($values) {
                $wpdb->query("REPLACE INTO $table (attachment_id, file_hash, file_size, content_hash) VALUES " . implode(',', $values));
            }
        } while ($rows && microtime(true) < $deadline);
        if ($rows) {
            return ['done' => false, 'phase' => 'size', 'left' => (int) $wpdb->get_var("SELECT COUNT(*) $missing")];
        }

        // 2. The extra files, folder by folder (the same walk as the Extra files tab).
        while ($run['x'] && $run['cursor'] !== null) {
            $batch = Orphans::step((int) $run['cursor']);
            $values = [];
            foreach ($batch['files'] as $f) {
                if ($f['bytes'] > 0) {
                    $values[] = $wpdb->prepare('(%s,%s,%d,%s,%d)', md5($f['path']), $f['path'], $f['bytes'], '', $f['kept'] ? 1 : 0);
                }
            }
            if ($values) {
                $wpdb->query("REPLACE INTO $xt (path_hash, path, file_size, content_hash, kept) VALUES " . implode(',', $values));
            }
            $run['cursor'] = $batch['next'];
            update_option(self::RUN, $run, false);
            if ($run['cursor'] !== null && microtime(true) >= $deadline) {
                return ['done' => false, 'phase' => 'list', 'left' => max(0, $batch['total'] - (int) $run['cursor'])];
            }
        }

        // 3. Content hashes, only where different files share a size (an extra file also matches a library size).
        $shared = "SELECT file_size FROM (SELECT file_size FROM $table WHERE file_size > 0 GROUP BY file_size HAVING COUNT(DISTINCT file_hash) > 1) s"
            . ($run['x'] ? " UNION SELECT file_size FROM $xt" : '');
        $todo = "FROM $table h JOIN $files f ON f.attachment_id = h.attachment_id AND f.file_hash = h.file_hash
            WHERE h.content_hash = '' AND h.file_size > 0 AND h.file_size IN ($shared)";
        do {
            $rows = $wpdb->get_results("SELECT h.attachment_id, h.file_hash $todo LIMIT 50", ARRAY_N);
            foreach ($rows as [$id, $file_hash]) {
                $path = (string) get_attached_file((int) $id);
                $md5 = is_file($path) ? md5_file($path) : false;
                // Twins share the file: hash it once for all of them. "missing" stops retrying absent files.
                $wpdb->update($table, ['content_hash' => $md5 ?: 'missing'], ['file_hash' => $file_hash]);
                if (microtime(true) >= $deadline) {
                    break;
                }
            }
        } while ($rows && microtime(true) < $deadline);
        $left = $rows ? (int) $wpdb->get_var("SELECT COUNT(*) $todo") : 0;

        if (!$rows && $run['x']) {
            $base = wp_upload_dir(null, false)['basedir'];
            $xtodo = "FROM $xt WHERE content_hash = '' AND file_size IN (
                SELECT file_size FROM (SELECT file_size FROM $xt GROUP BY file_size HAVING COUNT(*) > 1) a
                UNION SELECT file_size FROM $table WHERE file_size > 0)";
            do {
                $rows = $wpdb->get_results("SELECT path_hash, path $xtodo LIMIT 50", ARRAY_N);
                foreach ($rows as [$hash, $path]) {
                    $md5 = is_file("$base/$path") ? md5_file("$base/$path") : false;
                    $wpdb->update($xt, ['content_hash' => $md5 ?: 'missing'], ['path_hash' => $hash]);
                    if (microtime(true) >= $deadline) {
                        break;
                    }
                }
            } while ($rows && microtime(true) < $deadline);
            $left = $rows ? (int) $wpdb->get_var("SELECT COUNT(*) $xtodo") : 0;
        }
        if ($rows) {
            return ['done' => false, 'phase' => 'hash', 'left' => $left];
        }
        update_option('mtrk_dups_at', time(), false);
        update_option(self::WITH_EXTRAS, $run['x'] ? 1 : 0, false);
        return ['done' => true, 'phase' => 'done', 'left' => 0];
    }

    /**
     * Groups of identical files, most wasted space first. "paths" are extra files (only with the option).
     * @return array{groups: array<int,array{ids:int[],paths:array<string,array{bytes:int,kept:bool}>,size:int,copies:int}>, total: int, bytes: int}
     */
    public static function groups(int $page, int $per): array
    {
        global $wpdb;
        $files = Scanner::files_table();
        $table = self::table();
        $xt = self::xtable();
        $x = (bool) get_option(self::WITH_EXTRAS) && $wpdb->get_var("SHOW TABLES LIKE '$xt'");
        $wpdb->query('SET SESSION group_concat_max_len = 1048576');
        $lib = "SELECT h.content_hash AS ch, h.file_hash AS fk, h.file_size AS sz, h.attachment_id AS aid, '' AS p
            FROM $table h JOIN $files f ON f.attachment_id = h.attachment_id AND f.file_hash = h.file_hash
            WHERE h.content_hash NOT IN ('', 'missing')";
        $all = $x ? "$lib UNION ALL SELECT content_hash, path_hash, file_size, 0, path FROM $xt WHERE content_hash NOT IN ('', 'missing')" : $lib;
        $base = "FROM ($all) u GROUP BY ch HAVING COUNT(DISTINCT fk) > 1";
        $summary = $wpdb->get_row("SELECT COUNT(*) AS n, COALESCE(SUM(size * (copies - 1)), 0) AS bytes FROM (SELECT MAX(sz) AS size, COUNT(DISTINCT fk) AS copies $base) g");
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT GROUP_CONCAT(IF(aid > 0, aid, NULL) ORDER BY aid) AS ids, GROUP_CONCAT(IF(aid = 0, p, NULL) SEPARATOR '" . chr(31) . "') AS paths,
                    MAX(sz) AS size, COUNT(DISTINCT fk) AS copies
             $base ORDER BY MAX(sz) * (COUNT(DISTINCT fk) - 1) DESC, MIN(IF(aid > 0, aid, 4294967295)) LIMIT %d OFFSET %d",
            $per,
            ($page - 1) * $per
        ));
        $groups = [];
        foreach ($rows as $r) {
            $paths = [];
            foreach ($r->paths === null ? [] : explode(chr(31), $r->paths) as $p) {
                $paths[$p] = ['bytes' => (int) $r->size, 'kept' => false];
            }
            $groups[] = ['ids' => $r->ids === null ? [] : array_map('intval', explode(',', $r->ids)), 'paths' => $paths, 'size' => (int) $r->size, 'copies' => (int) $r->copies];
        }
        $all_paths = array_merge(...array_map(fn($g) => array_keys($g['paths']), $groups ?: [[ 'paths' => [] ]]));
        if ($all_paths) { // whether a reference names the extra file (then it is not offered for deletion)
            $kept = $wpdb->get_col('SELECT path FROM ' . $xt . ' WHERE kept = 1 AND path_hash IN (' . implode(',', array_map(fn($p) => "'" . md5($p) . "'", $all_paths)) . ')');
            foreach ($groups as &$g) {
                foreach ($kept as $p) {
                    if (isset($g['paths'][$p])) {
                        $g['paths'][$p]['kept'] = true;
                    }
                }
            }
            unset($g);
        }
        return ['groups' => $groups, 'total' => (int) ($summary->n ?? 0), 'bytes' => (int) ($summary->bytes ?? 0)];
    }
}
