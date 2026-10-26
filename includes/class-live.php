<?php

namespace MediaTracker;

defined('ABSPATH') || exit;

/**
 * Keeps the index current between full scans: whatever WordPress saves during a request (posts, meta, terms,
 * users, comments, options) is collected and re-read once at shutdown with the full scan's extractors.
 * Re-reading on save, rather than catching "media inserted", also catches media being removed.
 *
 * Not covered: plugin tables and code files (no save hooks), and front-end requests — view counters and the like
 * update meta on every page view, and re-indexing there would cost every visitor.
 */
final class Live
{
    private const NOISE_META = ['_edit_lock', '_edit_last', 'session_tokens'];
    private const NOISE_OPTIONS = ['cron', 'rewrite_rules'];

    /** @var array<string,array<int|string,true>> source type => ids (option names) */
    private static array $dirty = [];

    public static function init(): void
    {
        add_action('save_post', fn($id) => self::mark('post', $id));
        foreach (['post', 'term', 'user', 'comment'] as $type) {
            foreach (['added', 'updated', 'deleted'] as $op) {
                add_action("{$op}_{$type}_meta", fn($meta_id, $object_id, $key) => self::meta($type, (int) $object_id, (string) $key), 10, 3);
            }
        }
        add_action('created_term', fn($id) => self::mark('term', $id));
        add_action('edited_term', fn($id) => self::mark('term', $id));
        add_action('wp_insert_comment', fn($id) => self::mark('comment', $id));
        add_action('edit_comment', fn($id) => self::mark('comment', $id));
        add_action('added_option', fn($name, $value) => self::option((string) $name, $value), 10, 2);
        add_action('updated_option', fn($name, $old, $value) => self::option((string) $name, $value, $old), 10, 3);
        add_action('deleted_option', fn($name) => self::option((string) $name, null, true));
        add_action('shutdown', [self::class, 'flush']);
    }

    public static function flush(): void
    {
        $dirty = self::$dirty;
        self::$dirty = [];
        if ($dirty && self::tracking()) {
            Scanner::reindex(array_map('array_keys', $dirty));
        }
    }

    /** Admin, AJAX, REST, cron and CLI requests, once a full scan exists (or is running). */
    private static function tracking(): bool
    {
        $backend = is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI);
        return $backend && (Scanner::last() || Scanner::job());
    }

    private static function mark(string $type, $id): void
    {
        self::$dirty[$type][$id] = true;
    }

    private static function meta(string $type, int $id, string $key): void
    {
        if (!in_array($key, self::NOISE_META, true) && !($type === 'post' && wp_is_post_revision($id))) {
            self::mark($type, $id);
        }
    }

    /** Options churn constantly, so only values that could hold a reference (now or before) are re-read. */
    private static function option(string $name, $value, $old = null): void
    {
        if (strpos($name, '_transient_') !== false || strpos($name, 'mtrk_') === 0 || in_array($name, self::NOISE_OPTIONS, true)) {
            return;
        }
        if ($old === true || self::maybe_ref($name, $value) || self::maybe_ref($name, $old)) {
            self::mark('option', $name);
        }
    }

    private static function maybe_ref(string $name, $value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return false;
        }
        $s = is_scalar($value) ? (string) $value : serialize($value);
        static $field_options = null; // JetEngine options pages store bare ids
        $field_options = $field_options ?? Scanner::jet_engine()['options'];
        if (array_key_exists($name, $field_options)) {
            return true;
        }
        if (ctype_digit($s)) { // a bare id: only in options known to hold one, or ACF option fields
            return in_array($name, Scanner::ID_OPTIONS, true) || strpos($name, 'options_') === 0;
        }
        return (bool) preg_match('~uploads|(?:19|20)\d\d\\\\?/[01]\d|' . Scanner::MEDIA_KEY . '|custom_logo|site_logo|site_icon|"ids"~i', $s);
    }
}
