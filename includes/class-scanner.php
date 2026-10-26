<?php

namespace MediaTracker;

defined('ABSPATH') || exit;

/**
 * Reads the database (and, if asked, theme/plugin code) once, pulls out every reference to a media file
 * and stores it in one table. "Is this unused?" and "where is this used?" are both answered from that table,
 * so the cost grows with the size of the content, not with media × content.
 */
final class Scanner
{
    public const CAP = 'manage_options';

    private const JOB = 'mtrk_job';
    private const LAST = 'mtrk_last_scan';
    private const FILES = 'mtrk_files';
    private const LOCK = 'mtrk_lock';
    private const ROWS = 300;                // rows per batch
    private const BYTES = 8 * MB_IN_BYTES;   // ...and at most this much text, so page-builder JSON can't exhaust memory
    private const BUDGET = 8;                // seconds of work per AJAX request
    private const MAX_FILE = MB_IN_BYTES;
    private const CODE_EXT = '/\.(php|css|js|json|html?|twig|scss|less)$/i';
    private const SKIP_DIRS = ['node_modules', 'vendor', '.git'];
    private const ACF_TYPES = ['image', 'file', 'gallery'];
    private const META = [ // table => [source type, owner column, primary key]
        'postmeta' => ['post', 'post_id', 'meta_id'],
        'termmeta' => ['term', 'term_id', 'meta_id'],
        'usermeta' => ['user', 'user_id', 'umeta_id'],
        'commentmeta' => ['comment', 'comment_id', 'meta_id'],
    ];
    private const ID_META = ['_thumbnail_id', 'thumbnail_id'];
    private const ID_OPTIONS = ['site_icon', 'site_logo', 'woocommerce_placeholder_image'];
    private const SKIP_META = "'_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes'";

    /** @var array<string,int[]> normalized upload path => attachment ids */
    private array $keys = [];
    /** @var array<int,true> */
    private array $ids = [];
    /** @var string[] */
    private array $patterns;
    /** @var array<string,true> ACF field keys whose value is a media id */
    private array $acf;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mtrk_refs';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta('CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            source_type varchar(20) NOT NULL,
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_key varchar(255) NOT NULL DEFAULT '',
            how varchar(4) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY ref (attachment_id,source_type,source_id,source_key(150)),
            KEY source (source_type,source_id)
        ) " . $wpdb->get_charset_collate() . ';');
    }

    /** Normalized uploads path: "2024/05/a-300x200.jpg.webp", "2024/05/a-scaled.jpg" and "2024/05/a.jpg" all become "2024/05/a". */
    public static function key(string $path): string
    {
        $path = strtolower(ltrim(wp_normalize_path($path), '/'));
        $path = preg_replace('/(\.[a-z0-9]{2,5}){1,2}$/', '', $path);
        return preg_replace('/(-\d+x\d+|-scaled|-rotated|-e\d{13})+$/', '', $path);
    }

    public function __construct()
    {
        global $wpdb;
        // ponytail: rebuilt on every request (one indexed query); cache it if 100k+ attachment sites feel slow.
        foreach ($wpdb->get_results("SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file'", ARRAY_N) as [$id, $file]) {
            $this->ids[(int) $id] = true;
            $this->keys[self::key((string) $file)][] = (int) $id;
        }

        $chars = '[^\s"\'<>()\[\]{}|?#,;*]';
        $ext = '\.[a-z0-9]{2,5}(?![a-z0-9])';
        $this->patterns = ["~(?<![0-9])((?:19|20)[0-9]{2}/[01][0-9]/$chars+$ext)~i"];
        $base = trim((string) wp_parse_url(wp_upload_dir(null, false)['baseurl'], PHP_URL_PATH), '/');
        if ($base !== '') {
            $this->patterns[] = '~' . preg_quote($base, '~') . "/($chars+$ext)~i";
        }
        $this->acf = $this->acf_media_fields();
    }

    // ---- job -------------------------------------------------------------------------------------------------

    public static function start(bool $code, bool $tables): array
    {
        global $wpdb;
        $steps = ['posts', 'postmeta', 'termmeta', 'usermeta', 'commentmeta', 'options', 'terms', 'comments'];
        if ($tables) {
            foreach (self::extra_tables() as $table) {
                $steps[] = "table:$table";
            }
        }
        if ($code) {
            $steps[] = 'files';
        }

        $wpdb->query('TRUNCATE TABLE ' . self::table());
        delete_option(self::LAST);
        $job = [
            'steps' => $steps,
            'i' => 0,
            'cursor' => 0,
            'code' => $code,
            'tables' => $tables,
            'started' => time(),
            'max_id' => (int) $wpdb->get_var("SELECT MAX(ID) FROM $wpdb->posts WHERE post_type = 'attachment'"),
        ];
        update_option(self::JOB, $job, false);
        return self::progress($job);
    }

    /** Works through batches for BUDGET seconds. Batches are idempotent (INSERT IGNORE), so a retried request is harmless. */
    public static function run_step(): array
    {
        $job = self::job();
        if (!$job) {
            return ['done' => true];
        }
        if (!add_option(self::LOCK, time(), '', false)) {
            if (time() - (int) get_option(self::LOCK) < 60) {
                return ['busy' => true] + self::progress($job);
            }
            update_option(self::LOCK, time(), false); // a request died holding the lock
        }

        try {
            $scanner = new self();
            $deadline = microtime(true) + self::BUDGET;
            do {
                $next = $scanner->batch($job['steps'][$job['i']], $job);
                if ($next === null) {
                    $job['i']++;
                    $job['cursor'] = 0;
                    unset($job['total']);
                } else {
                    $job['cursor'] = $next;
                }
                update_option(self::JOB, $job, false);
            } while ($job['i'] < count($job['steps']) && microtime(true) < $deadline);

            if ($job['i'] >= count($job['steps'])) {
                update_option(self::LAST, [
                    'time' => time(),
                    'max_id' => $job['max_id'],
                    'code' => $job['code'],
                    'tables' => $job['tables'],
                    'secs' => time() - $job['started'],
                ], false);
                delete_option(self::JOB);
                delete_option(self::FILES);
                return ['done' => true];
            }
            return self::progress($job);
        } finally {
            delete_option(self::LOCK);
        }
    }

    public static function cancel(): void
    {
        delete_option(self::JOB);
        delete_option(self::FILES);
        delete_option(self::LOCK);
    }

    public static function job(): ?array
    {
        $job = get_option(self::JOB);
        return is_array($job) ? $job : null;
    }

    public static function last(): ?array
    {
        $last = get_option(self::LAST);
        return is_array($last) ? $last : null;
    }

    public static function progress(array $job): array
    {
        $n = count($job['steps']);
        $frac = empty($job['total']) ? 0 : min(1, $job['cursor'] / $job['total']);
        return [
            'done' => false,
            'percent' => (int) floor(($job['i'] + $frac) / $n * 100),
            'label' => self::label($job['steps'][min($job['i'], $n - 1)]),
        ];
    }

    private static function label(string $step): string
    {
        $labels = [
            'posts' => __('محتوای نوشته‌ها', 'media-tracker'),
            'postmeta' => __('متای نوشته‌ها', 'media-tracker'),
            'termmeta' => __('متای دسته‌ها', 'media-tracker'),
            'usermeta' => __('متای کاربران', 'media-tracker'),
            'commentmeta' => __('متای دیدگاه‌ها', 'media-tracker'),
            'options' => __('تنظیمات', 'media-tracker'),
            'terms' => __('توضیحات دسته‌ها', 'media-tracker'),
            'comments' => __('دیدگاه‌ها', 'media-tracker'),
            'files' => __('فایل‌های کد', 'media-tracker'),
        ];
        /* translators: %s: database table name */
        return $labels[$step] ?? sprintf(__('جدول %s', 'media-tracker'), substr($step, 6));
    }

    // ---- results ---------------------------------------------------------------------------------------------

    /** @return array<int,int> attachment id => number of places (a post with three fields is one place), one query */
    public static function counts(array $ids): array
    {
        global $wpdb;
        $ids = array_filter(array_map('intval', $ids));
        if (!$ids) {
            return [];
        }
        $out = [];
        $place = "source_type, source_id, IF(source_type IN ('post', 'term', 'user', 'comment'), '', source_key)";
        foreach ($wpdb->get_results("SELECT attachment_id, COUNT(DISTINCT $place) FROM " . self::table() . ' WHERE attachment_id IN (' . implode(',', $ids) . ') GROUP BY attachment_id', ARRAY_N) as [$id, $n]) {
            $out[(int) $id] = (int) $n;
        }
        return $out;
    }

    /** none = never scanned, new = uploaded after the last scan, used, unused. */
    public static function status(int $id, int $count): string
    {
        $last = self::last();
        if (!$last) {
            return 'none';
        }
        if ($id > $last['max_id']) {
            return 'new';
        }
        return $count ? 'used' : 'unused';
    }

    public static function refs(int $id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT source_type, source_id, source_key, how FROM ' . self::table() . ' WHERE attachment_id = %d ORDER BY source_type, source_id, source_key LIMIT 500', $id), ARRAY_A);
    }

    public static function summary(): array
    {
        global $wpdb;
        $last = self::last();
        if (!$last) {
            return [];
        }
        $max = (int) $last['max_id'];
        $scanned = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND ID <= $max");
        $used = (int) $wpdb->get_var('SELECT COUNT(DISTINCT r.attachment_id) FROM ' . self::table() . " r JOIN $wpdb->posts p ON p.ID = r.attachment_id");
        return [
            'used' => $used,
            'unused' => $scanned - $used,
            'new' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND ID > $max"),
        ];
    }

    /** WHERE fragment for the media library "usage" filter. */
    public static function where(string $usage): string
    {
        global $wpdb;
        $last = self::last();
        if (!$last) {
            return ' AND 1=0';
        }
        $max = (int) $last['max_id'];
        $in = "$wpdb->posts.ID IN (SELECT attachment_id FROM " . self::table() . ')';
        switch ($usage) {
            case 'used':
                return " AND $in";
            case 'unused':
                return " AND $wpdb->posts.ID <= $max AND NOT $in";
            case 'new':
                return " AND $wpdb->posts.ID > $max";
        }
        return '';
    }

    public static function forget(int $post_id): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . " WHERE attachment_id = %d OR (source_type = 'post' AND source_id = %d)", $post_id, $post_id));
    }

    // ---- batches ---------------------------------------------------------------------------------------------

    /** Processes one batch of $step; returns the next cursor, or null when the step is finished. */
    private function batch(string $step, array &$job): ?int
    {
        global $wpdb;
        $cursor = (int) $job['cursor'];
        if ($step === 'posts') {
            return $this->posts($cursor, $job);
        }
        if (isset(self::META[$step])) {
            return $this->meta($step, $cursor, $job);
        }
        if ($step === 'options') {
            return $this->options($cursor, $job);
        }
        if ($step === 'terms') {
            return $this->scan_table($wpdb->term_taxonomy, $cursor, $job, ['term', 'term_taxonomy_id', 'term_id', ['description'], 'description']);
        }
        if ($step === 'comments') {
            return $this->scan_table($wpdb->comments, $cursor, $job, ['comment', 'comment_ID', 'comment_ID', ['comment_content'], '']);
        }
        if ($step === 'files') {
            return $this->files($cursor, $job);
        }
        return $this->scan_table(substr($step, 6), $cursor, $job, null);
    }

    private function posts(int $cursor, array &$job): ?int
    {
        global $wpdb;
        $rows = $this->rows($wpdb->posts, 'ID', 't.post_content, t.post_excerpt', 'LENGTH(t.post_content) + LENGTH(t.post_excerpt)',
            "AND t.post_type NOT IN ('revision', 'attachment') AND t.post_status <> 'auto-draft'", $cursor, $job);
        $refs = [];
        foreach ($rows as $r) {
            $text = $r['post_content'] . "\n" . $r['post_excerpt'];
            $this->add($refs, $this->in_text($text) + $this->in_content($text), 'post', (int) $r['pk'], '');
        }
        $this->save($refs);
        return $rows ? (int) end($rows)['pk'] : null;
    }

    private function meta(string $name, int $cursor, array &$job): ?int
    {
        global $wpdb;
        [$type, $owner, $pk] = self::META[$name];
        $table = $wpdb->$name;
        $where = 'AND t.meta_key NOT IN (' . self::SKIP_META . ')';
        if ($name === 'postmeta') {
            $where .= " AND NOT EXISTS (SELECT 1 FROM $wpdb->posts p WHERE p.ID = t.post_id AND p.post_type = 'revision')";
        }
        $rows = $this->rows($table, $pk, "t.`$owner` AS sid, t.meta_key AS k, t.meta_value AS v", 'LENGTH(t.meta_value)', $where, $cursor, $job);
        $acf = $this->acf_ids($table, $owner, 'meta_key', 'meta_value', $rows);
        $refs = [];
        foreach ($rows as $i => $r) {
            $v = (string) $r['v'];
            $this->add($refs, $this->in_text($v) + $this->in_meta($r['k'], $v) + ($acf[$i] ?? []), $type, (int) $r['sid'], $r['k']);
        }
        $this->save($refs);
        return $rows ? (int) end($rows)['pk'] : null;
    }

    private function options(int $cursor, array &$job): ?int
    {
        global $wpdb;
        $rows = $this->rows($wpdb->options, 'option_id', '0 AS sid, t.option_name AS k, t.option_value AS v', 'LENGTH(t.option_value)',
            "AND t.option_name NOT LIKE '%\\_transient\\_%' AND t.option_name NOT LIKE 'mtrk\\_%'", $cursor, $job);
        $acf = $this->acf_ids($wpdb->options, '', 'option_name', 'option_value', $rows);
        $refs = [];
        foreach ($rows as $i => $r) {
            $v = (string) $r['v'];
            $ids = $this->in_serialized($v);
            if (in_array($r['k'], self::ID_OPTIONS, true)) {
                $ids[] = $v;
            }
            $this->add($refs, $this->in_text($v) + $this->only($ids) + ($acf[$i] ?? []), 'option', 0, $r['k']);
        }
        $this->save($refs);
        return $rows ? (int) end($rows)['pk'] : null;
    }

    /**
     * A table read for upload paths only.
     * $cfg: [source type, primary key, owner column, columns, source key]; null = discover it (plugin tables).
     */
    private function scan_table(string $table, int $cursor, array &$job, ?array $cfg): ?int
    {
        global $wpdb;
        $cfg = $cfg ?? self::discover($table);
        if (!$cfg) {
            return null;
        }
        [$type, $pk, $owner, $cols, $key] = $cfg;
        $list = implode(', ', array_map(fn($c) => "t.`$c`", $cols));
        if ($pk) {
            $size = implode(' + ', array_map(fn($c) => "COALESCE(LENGTH(t.`$c`), 0)", $cols));
            $rows = $this->rows($table, $pk, ($owner ? "t.`$owner` AS sid, " : '') . $list, $size, '', $cursor, $job);
            $next = $rows ? (int) end($rows)['pk'] : null;
        } else {
            // ponytail: OFFSET paging for tables without a numeric primary key; slow only on huge keyless tables.
            if (!isset($job['total'])) {
                $job['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
            }
            $rows = $wpdb->get_results("SELECT $list FROM `$table` t LIMIT " . self::ROWS . " OFFSET $cursor", ARRAY_A);
            $next = count($rows) === self::ROWS ? $cursor + self::ROWS : null;
        }
        $refs = [];
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $v = (string) $r[$c];
                $this->add($refs, $this->in_text($v) + $this->only($this->in_serialized($v)), $type, (int) ($r['sid'] ?? $r['pk'] ?? 0), $key ?? "$table.$c");
            }
        }
        $this->save($refs);
        return $next;
    }

    private function files(int $cursor, array &$job): ?int
    {
        if ($cursor === 0) {
            update_option(self::FILES, self::code_files(), false);
        }
        $files = (array) get_option(self::FILES, []);
        $job['total'] = count($files);
        $end = min(count($files), $cursor + 100);
        $root = wp_normalize_path(WP_CONTENT_DIR) . '/';
        $refs = [];
        for ($i = $cursor; $i < $end; $i++) {
            $file = $files[$i];
            if (@filesize($file) > self::MAX_FILE || ($text = @file_get_contents($file)) === false) {
                continue;
            }
            $this->add($refs, $this->in_text($text), 'file', 0, str_replace($root, '', $file));
        }
        $this->save($refs);
        return $end < count($files) ? $end : null;
    }

    /** Next rows after $cursor by primary key: at most ROWS rows and about BYTES of text. */
    private function rows(string $table, string $pk, string $cols, string $size, string $where, int $cursor, array &$job): array
    {
        global $wpdb;
        if (!isset($job['total'])) {
            $job['total'] = (int) $wpdb->get_var("SELECT MAX(`$pk`) FROM `$table`");
        }
        $sizes = $wpdb->get_results("SELECT t.`$pk`, $size FROM `$table` t WHERE t.`$pk` > $cursor $where ORDER BY t.`$pk` LIMIT " . self::ROWS, ARRAY_N);
        $ids = [];
        $bytes = 0;
        foreach ($sizes as [$id, $n]) {
            $ids[] = (int) $id;
            $bytes += (int) $n;
            if ($bytes > self::BYTES) {
                break;
            }
        }
        if (!$ids) {
            return [];
        }
        return $wpdb->get_results("SELECT t.`$pk` AS pk, $cols FROM `$table` t WHERE t.`$pk` IN (" . implode(',', $ids) . ") ORDER BY t.`$pk`", ARRAY_A);
    }

    private function add(array &$refs, array $found, string $type, int $sid, string $key): void
    {
        foreach ($found as $id => $how) {
            if ($type === 'post' && $id === $sid) {
                continue; // an attachment's own data
            }
            $refs[] = [$id, $type, $sid, $key, $how];
        }
    }

    private function save(array $refs): void
    {
        global $wpdb;
        foreach (array_chunk($refs, 300) as $chunk) {
            $values = array_map(fn($r) => $wpdb->prepare('(%d,%s,%d,%s,%s)', $r[0], $r[1], $r[2], mb_substr($r[3], 0, 255), $r[4]), $chunk);
            $wpdb->query('INSERT IGNORE INTO ' . self::table() . ' (attachment_id, source_type, source_id, source_key, how) VALUES ' . implode(',', $values));
        }
    }

    // ---- extraction ------------------------------------------------------------------------------------------

    /** Upload paths in any text: URLs (absolute, relative, CDN), JSON-escaped, percent-encoded or \u-escaped. */
    private function in_text(string $text): array
    {
        if (stripos($text, '%2F') !== false) { // URL-encoded storage, e.g. Elementor dynamic tag settings
            $text .= "\n" . urldecode($text);
        }
        if (strpos($text, '\/') !== false) {
            $text = str_replace('\/', '/', $text);
        }
        $found = [];
        foreach ($this->patterns as $re) {
            if (!preg_match_all($re, $text, $m)) {
                continue;
            }
            foreach (array_unique($m[1]) as $path) {
                if (strpos($path, '%') !== false) {
                    $path = rawurldecode($path);
                }
                if (strpos($path, '\u') !== false) {
                    $path = (string) json_decode('"' . $path . '"');
                }
                foreach ($this->keys[self::key($path)] ?? [] as $id) {
                    $found[$id] = 'url';
                }
            }
        }
        return $found;
    }

    /** Ids in post content: image classes, core media blocks, gallery-style shortcodes. */
    private function in_content(string $c): array
    {
        $ids = [];
        if (preg_match_all('/\bwp-image-(\d+)/', $c, $m)) {
            $ids = $m[1];
        }
        if (preg_match_all('~<!-- wp:(?:core/)?(?:image|cover|media-text|video|audio|file|gallery) (\{.*?\}) /?-->~s', $c, $m)) {
            foreach ($m[1] as $json) {
                if (preg_match_all('/"(?:id|mediaId)":(\d+)|"ids":\[([\d,]+)\]/', $json, $mm)) {
                    $ids = array_merge($ids, $mm[1], explode(',', implode(',', $mm[2])));
                }
            }
        }
        if (preg_match_all('/\[[\w-]+\s[^\]]*?\b(?:ids|include|image|images|attachment_id|image_id)\s*=\s*["\']?([\d, ]+)/i', $c, $m)) {
            foreach ($m[1] as $list) {
                $ids = array_merge($ids, explode(',', $list));
            }
        }
        return $this->only($ids) + $this->in_acf_blocks($c);
    }

    /** ACF blocks keep field values in the block comment: {"data":{"img":12,"_img":"field_abc"}} or {"data":{"field_abc":12}}. */
    private function in_acf_blocks(string $c): array
    {
        if (!$this->acf || strpos($c, '<!-- wp:acf/') === false || !preg_match_all('~<!-- wp:acf/[\w-]+ (\{.*?\}) /?-->~s', $c, $m)) {
            return [];
        }
        $ids = [];
        foreach ($m[1] as $json) {
            $data = json_decode($json, true)['data'] ?? [];
            foreach (is_array($data) ? $data : [] as $k => $v) {
                $field = strpos((string) $k, 'field_') === 0 ? $k : ($data["_$k"] ?? '');
                if (is_string($field) && isset($this->acf[$field])) {
                    $ids = array_merge($ids, array_filter((array) $v, 'is_scalar'));
                }
            }
        }
        return $this->only($ids);
    }

    private function in_meta(string $key, string $v): array
    {
        $ids = $this->in_serialized($v);
        if (in_array($key, self::ID_META, true)) {
            $ids[] = $v;
        } elseif ($key === '_product_image_gallery') {
            $ids = array_merge($ids, explode(',', $v));
        } elseif ($key === '_elementor_data') {
            if (strpos($v, 'elementor-tag') !== false) {
                $v .= urldecode($v); // dynamic tag settings (fallback images) are URL-encoded JSON
            }
            // Classic controls: {"url":..,"id":12} (element ids are quoted strings). V4 atomic: image-attachment-id.
            if (preg_match_all('/"id":(\d+)|"image-attachment-id","value":(\d+)/', $v, $m)) {
                $ids = array_merge($ids, $m[1], $m[2]);
            }
        }
        return $this->only($ids);
    }

    /** Ids under well-known keys inside serialized or JSON values (widgets, theme mods, avatars, galleries). */
    private function in_serialized(string $v): array
    {
        if (strpos($v, '"') === false) {
            return [];
        }
        $ids = [];
        if (preg_match_all('/"(?:attachment_id|media_id|image_id|thumbnail_id|custom_logo|site_logo|site_icon)"(?:;(?:i:|s:\d+:")|\s*:\s*"?)(\d+)/', $v, $m)) {
            $ids = $m[1];
        }
        if (preg_match_all('/"ids";a:\d+:\{([^}]*)\}/', $v, $m)) {
            foreach ($m[1] as $list) {
                if (preg_match_all('/i:\d+;(?:i:|s:\d+:")(\d+)/', $list, $mm)) {
                    $ids = array_merge($ids, $mm[1]);
                }
            }
        }
        return $ids;
    }

    /**
     * ACF stores image/file/gallery fields as bare ids; the sibling "_name" row holds the field key, and the field
     * key tells the type. Without that check every number field would look like a media id.
     * $owner = '' for the options table.
     */
    private function acf_ids(string $table, string $owner, string $key, string $val, array $rows): array
    {
        global $wpdb;
        if (!$this->acf) {
            return [];
        }
        $cands = [];
        foreach ($rows as $i => $r) {
            if (($r['k'][0] ?? '_') !== '_' && preg_match('/^(\d+|a:\d+:\{.*\})$/s', (string) $r['v'])) {
                $cands[$i] = $owner === '' ? $wpdb->prepare('%s', '_' . $r['k']) : $wpdb->prepare('(%d,%s)', $r['sid'], '_' . $r['k']);
            }
        }
        if (!$cands) {
            return [];
        }
        $select = ($owner === '' ? '0' : "`$owner`") . " AS sid, `$key` AS k, `$val` AS v";
        $where = ($owner === '' ? "`$key`" : "(`$owner`, `$key`)") . ' IN (' . implode(',', array_unique($cands)) . ')';
        $fields = [];
        foreach ($wpdb->get_results("SELECT $select FROM `$table` WHERE $where", ARRAY_A) as $f) {
            if (isset($this->acf[$f['v']])) {
                $fields[$f['sid'] . '|' . $f['k']] = true;
            }
        }
        $out = [];
        foreach (array_keys($cands) as $i) {
            $r = $rows[$i];
            if (isset($fields[$r['sid'] . '|_' . $r['k']])) {
                $out[$i] = $this->only((array) maybe_unserialize($r['v']));
            }
        }
        return $out;
    }

    private function acf_media_fields(): array
    {
        global $wpdb;
        $keys = [];
        foreach ($wpdb->get_results("SELECT post_name, post_content FROM $wpdb->posts WHERE post_type = 'acf-field'") as $f) {
            $s = maybe_unserialize($f->post_content);
            if (in_array($s['type'] ?? '', self::ACF_TYPES, true)) {
                $keys[$f->post_name] = true;
            }
        }
        if (function_exists('acf_get_local_fields')) { // fields registered in PHP
            foreach ((array) acf_get_local_fields() as $f) {
                if (in_array($f['type'] ?? '', self::ACF_TYPES, true)) {
                    $keys[$f['key']] = true;
                }
            }
        }
        return $keys;
    }

    private function only(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($this->ids[$id])) {
                $out[$id] = 'id';
            }
        }
        return $out;
    }

    // ---- discovery -------------------------------------------------------------------------------------------

    private static function extra_tables(): array
    {
        global $wpdb;
        $core = array_merge(array_values($wpdb->tables('all')), [self::table()]);
        $skip = apply_filters('mtrk_skip_tables', '/_(log|logs|sessions|cache)$|actionscheduler_|wc_admin_|_lookup$|_stats$/i');
        $out = [];
        foreach ($wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%')) as $t) {
            $other_site = is_multisite() && preg_match('/^' . preg_quote($wpdb->base_prefix, '/') . '\d+_/', $t) && $wpdb->prefix === $wpdb->base_prefix;
            if (!in_array($t, $core, true) && !preg_match($skip, $t) && !$other_site) {
                $out[] = $t;
            }
        }
        return $out;
    }

    private static function discover(string $table): ?array
    {
        global $wpdb;
        $pk = null;
        $pri = 0;
        $cols = [];
        foreach ($wpdb->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A) as $c) {
            if ($c['Key'] === 'PRI') {
                $pri++;
                if (preg_match('/^(tiny|small|medium|big)?int/i', $c['Type'])) {
                    $pk = $c['Field'];
                }
            } elseif (preg_match('/char|text|json/i', $c['Type'])) {
                $cols[] = $c['Field'];
            }
        }
        return $cols ? ['table', $pri === 1 ? $pk : null, null, $cols, null] : null;
    }

    /** Active theme(s), active plugins, mu-plugins, and code files (css/js/...) inside uploads. */
    private static function code_files(): array
    {
        $dirs = [get_stylesheet_directory(), get_template_directory(), WPMU_PLUGIN_DIR, wp_upload_dir(null, false)['basedir']];
        $self = plugin_basename(MTRK_FILE);
        $plugins = array_merge((array) get_option('active_plugins', []), array_keys((array) get_site_option('active_sitewide_plugins', [])));
        foreach ($plugins as $p) {
            if ($p !== $self) {
                $dirs[] = WP_PLUGIN_DIR . '/' . (strpos($p, '/') ? dirname($p) : $p);
            }
        }
        $files = [];
        foreach (array_unique(array_map('wp_normalize_path', $dirs)) as $dir) {
            if (is_file($dir)) {
                $files[] = $dir;
                continue;
            }
            if (!is_dir($dir)) {
                continue;
            }
            try {
                $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    fn($f) => $f->isDir() ? !in_array($f->getFilename(), self::SKIP_DIRS, true) : (bool) preg_match(self::CODE_EXT, $f->getFilename())
                ));
                foreach ($it as $f) {
                    $files[] = wp_normalize_path($f->getPathname());
                }
            } catch (\UnexpectedValueException $e) {
                continue; // unreadable directory
            }
        }
        return array_values(array_unique($files));
    }
}
