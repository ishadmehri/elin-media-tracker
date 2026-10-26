<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mtrk_refs, {$wpdb->prefix}mtrk_paths");
foreach (['mtrk_job', 'mtrk_last_scan', 'mtrk_files', 'mtrk_lock', 'mtrk_db'] as $option) {
    delete_option($option);
}
