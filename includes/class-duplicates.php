<?php

namespace MediaTracker;

defined('ABSPATH') || exit;

/**
 * Attachments whose files have identical content (the same image uploaded more than once). Sizes are compared
 * first (from metadata, no disk reads); only files sharing a size get an MD5 of their content. Results live in
 * {prefix}mtrk_hashes, keyed by the file they were computed for, so full scans don't wipe them and a changed
 * file is recomputed. Attachments that share one file (media translations) are one file, not duplicates.
 */
final class Duplicates
{
    private const BUDGET = 8; // seconds per request

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mtrk_hashes';
    }

    /** One request of work. @return array{done: bool, phase: string, left: int} */
    public static function step(): array
    {
        global $wpdb;
        Scanner::ensure_index();
        $files = Scanner::files_table();
        $table = self::table();
        $deadline = microtime(true) + self::BUDGET;

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

        // 2. Content hashes, only where different files share a size.
        $todo = "FROM $table h JOIN $files f ON f.attachment_id = h.attachment_id AND f.file_hash = h.file_hash
            WHERE h.content_hash = '' AND h.file_size > 0 AND h.file_size IN (
                SELECT file_size FROM (SELECT file_size FROM $table WHERE file_size > 0 GROUP BY file_size HAVING COUNT(DISTINCT file_hash) > 1) s)";
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
        if ($rows) {
            return ['done' => false, 'phase' => 'hash', 'left' => (int) $wpdb->get_var("SELECT COUNT(*) $todo")];
        }
        update_option('mtrk_dups_at', time(), false);
        return ['done' => true, 'phase' => 'done', 'left' => 0];
    }

    /**
     * Groups of identical files, most wasted space first.
     * @return array{groups: array<int,array{ids:int[],size:int,copies:int}>, total: int, bytes: int}
     */
    public static function groups(int $page, int $per): array
    {
        global $wpdb;
        $files = Scanner::files_table();
        $table = self::table();
        $base = "FROM $table h JOIN $files f ON f.attachment_id = h.attachment_id AND f.file_hash = h.file_hash
            WHERE h.content_hash NOT IN ('', 'missing') GROUP BY h.content_hash HAVING COUNT(DISTINCT h.file_hash) > 1";
        $summary = $wpdb->get_row("SELECT COUNT(*) AS n, COALESCE(SUM(size * (copies - 1)), 0) AS bytes FROM (SELECT MAX(h.file_size) AS size, COUNT(DISTINCT h.file_hash) AS copies $base) g");
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT GROUP_CONCAT(h.attachment_id ORDER BY h.attachment_id) AS ids, MAX(h.file_size) AS size, COUNT(DISTINCT h.file_hash) AS copies
             $base ORDER BY MAX(h.file_size) * (COUNT(DISTINCT h.file_hash) - 1) DESC, MIN(h.attachment_id) LIMIT %d OFFSET %d",
            $per,
            ($page - 1) * $per
        ));
        $groups = [];
        foreach ($rows as $r) {
            $groups[] = ['ids' => array_map('intval', explode(',', $r->ids)), 'size' => (int) $r->size, 'copies' => (int) $r->copies];
        }
        return ['groups' => $groups, 'total' => (int) ($summary->n ?? 0), 'bytes' => (int) ($summary->bytes ?? 0)];
    }
}
