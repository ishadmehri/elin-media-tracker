<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__ . '/includes/class-scanner.php';
require_once __DIR__ . '/includes/class-cleanup.php';

global $wpdb;

// Quarantined files go back first: uninstalling must not leave media broken.
MediaTracker\Cleanup::restore(array_keys(MediaTracker\Cleanup::quarantined()));
$qdir = get_option('mtrk_quarantine_dir');
if ($qdir) {
    $dir = wp_upload_dir(null, false)['basedir'] . '/' . $qdir;
    if (is_dir($dir)) {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mtrk_refs, {$wpdb->prefix}mtrk_paths, {$wpdb->prefix}mtrk_files, {$wpdb->prefix}mtrk_hashes, {$wpdb->prefix}mtrk_xhashes");
foreach (['mtrk_job', 'mtrk_last_scan', 'mtrk_files', 'mtrk_lock', 'mtrk_db', 'mtrk_files_ready', 'mtrk_dups_at', 'mtrk_dups_run', 'mtrk_dups_x', 'mtrk_quarantine', 'mtrk_quarantine_dir'] as $option) {
    delete_option($option);
}
delete_post_meta_by_key('_mtrk_quarantine');
delete_post_meta_by_key('_mtrk_keep');
delete_metadata('user', 0, 'mtrk_per_page', '', true);
delete_metadata('user', 0, 'mtrk_view', '', true);
