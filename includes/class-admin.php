<?php

namespace MediaTracker;

defined('ABSPATH') || exit;

/** Media library integration (usage column, filter, modal) and the assets shared with the plugin page. */
final class Admin
{
    public static function init(): void
    {
        Page::init();
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_enqueue_media', [self::class, 'enqueue']);
        add_action('wp_ajax_mtrk_usage', [self::class, 'ajax_usage']);
        add_filter('manage_media_columns', [self::class, 'column']);
        add_filter('manage_upload_sortable_columns', fn($cols) => $cols + ['mtrk' => ['mtrk', false]]);
        add_filter('posts_clauses', [self::class, 'sort_clauses'], 10, 2);
        add_filter('ajax_query_attachments_args', [self::class, 'grid_query']);
        add_action('manage_media_custom_column', [self::class, 'cell'], 10, 2);
        add_action('restrict_manage_posts', [self::class, 'filter_select']);
        add_action('pre_get_posts', [self::class, 'filter_query']);
        add_filter('posts_where', [self::class, 'filter_where'], 10, 2);
        add_filter('attachment_fields_to_edit', [self::class, 'field'], 10, 2);
    }

    public static function menu(): void
    {
        add_media_page(__('Media Tracker', 'elin-media-tracker'), __('Media Tracker', 'elin-media-tracker'), Scanner::CAP, 'elin-media-tracker', [Page::class, 'render']);
    }

    public static function assets(string $hook): void
    {
        if (in_array($hook, ['upload.php', 'post.php', 'media_page_elin-media-tracker'], true)) {
            self::enqueue();
        }
        if ($hook === 'upload.php' && current_user_can(Scanner::CAP)) { // the grid's usage filter extends media-views
            wp_enqueue_script('mtrk-grid', plugins_url('assets/grid.js', MTRK_FILE), ['media-views', 'mtrk'], MTRK_VERSION, true);
        }
    }

    public static function enqueue(): void
    {
        if (!is_admin() || !current_user_can(Scanner::CAP) || wp_script_is('mtrk')) {
            return;
        }
        wp_enqueue_style('mtrk', plugins_url('assets/admin.css', MTRK_FILE), [], MTRK_VERSION);
        wp_enqueue_script('mtrk', plugins_url('assets/admin.js', MTRK_FILE), [], MTRK_VERSION, true);
        wp_add_inline_script('mtrk', 'window.mtrk = ' . wp_json_encode([
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mtrk'),
            'i18n' => [
                'title' => __('Where it is used', 'elin-media-tracker'),
                'close' => __('Close', 'elin-media-tracker'),
                'loading' => __('Loading…', 'elin-media-tracker'),
                'error' => __('Could not reach the server.', 'elin-media-tracker'),
                'done' => __('Scan finished.', 'elin-media-tracker'),
                'stopping' => __('Stopping…', 'elin-media-tracker'),
                /* translators: %s: number of images checked */
                'sizesChecking' => __('Checking… %s image(s)', 'elin-media-tracker'),
                'sizesNone' => __('No unregistered sizes found.', 'elin-media-tracker'),
                'mb' => __('MB', 'elin-media-tracker'),
                'kb' => __('KB', 'elin-media-tracker'),
                /* translators: 1: number of files, 2: size with unit */
                'sizesFound' => __('%1$s file(s), %2$s deletable.', 'elin-media-tracker'),
                /* translators: 1: number of files, 2: size with unit */
                'sizesConfirm' => __('%1$s file(s) (%2$s) will be deleted permanently. Continue?', 'elin-media-tracker'),
                /* translators: 1: number of files, 2: size with unit */
                'sizesDeleted' => __('%1$s file(s) deleted (%2$s).', 'elin-media-tracker'),
                'preview' => __('Preview', 'elin-media-tracker'),
                /* translators: %s: number of attachments left */
                'dupsSizes' => __('Comparing sizes… %s media left', 'elin-media-tracker'),
                /* translators: %s: number of files left */
                'dupsHashes' => __('Comparing the content of same-size files… %s file(s) left', 'elin-media-tracker'),
                'usageLabel' => __('Usage status', 'elin-media-tracker'),
                'usageAll' => __('Usage status: all', 'elin-media-tracker'),
                'usageUsed' => __('Used', 'elin-media-tracker'),
                'usageUnused' => __('Unreferenced', 'elin-media-tracker'),
                'usageNew' => __('New, unreferenced', 'elin-media-tracker'),
                /* translators: %s: number of items */
                'selected' => __('%s selected', 'elin-media-tracker'),
                /* translators: %s: number of media items */
                'confirmDelete' => __('%s media and all their files will be deleted permanently. This cannot be undone. Continue?', 'elin-media-tracker'),
                /* translators: %s: number of files */
                'confirmFiles' => __('%s file(s) will be deleted permanently. This cannot be undone. Continue?', 'elin-media-tracker'),
                /* translators: %s: number of folders */
                'orphansChecking' => __('Checking… %s folder(s)', 'elin-media-tracker'),
                'orphansNone' => __('No extra files found.', 'elin-media-tracker'),
                /* translators: 1: number of files, 2: size with unit */
                'orphansFound' => __('%1$s file(s), %2$s deletable.', 'elin-media-tracker'),
                /* translators: %s: attachment id */
                'orphanSize' => __('Extra size of attachment #%s', 'elin-media-tracker'),
                'orphanFile' => __('Outside the library', 'elin-media-tracker'),
                'noPreview' => __('No preview for this file type.', 'elin-media-tracker'),
                'fileLabel' => __('File', 'elin-media-tracker'),
                'sizeLabel' => __('Size', 'elin-media-tracker'),
                'openTab' =>__('Open in a new tab', 'elin-media-tracker'),
                'orphanKept' =>__('Its URL appears somewhere; it is kept', 'elin-media-tracker'),
                'more' => __('Show more', 'elin-media-tracker'),
                'probeOk' => __('Requests for quarantined files reach WordPress: visitors are served the file and the request is logged.', 'elin-media-tracker'),
                'probeFail' => __('Requests for missing files do not reach WordPress (probably an nginx/LiteSpeed rule for image files, or a CDN). A quarantined file returns a 404 to visitors and no hit is logged. In this setup, quarantine is only a reversible delete.', 'elin-media-tracker'),
            ],
        ]) . ';', 'before');
    }

    // ---- ajax ------------------------------------------------------------------------------------------------

    public static function ajax_usage(): void
    {
        self::guard();
        $id = absint($_POST['id'] ?? 0);
        if (get_post_type($id) !== 'attachment') {
            wp_send_json_error(__('Media not found.', 'elin-media-tracker'), 404);
        }
        wp_send_json_success(['html' => self::usage_html($id)]);
    }

    public static function guard(): void
    {
        if (!check_ajax_referer('mtrk', false, false)) {
            wp_send_json_error(__('Your session has expired; reload the page.', 'elin-media-tracker'), 403);
        }
        if (!current_user_can(Scanner::CAP)) {
            wp_send_json_error(__('You are not allowed to do this.', 'elin-media-tracker'), 403);
        }
    }

    // ---- usage modal -----------------------------------------------------------------------------------------

    private static function usage_html(int $id): string
    {
        $refs = Scanner::refs($id);
        $status = Scanner::status($id, count($refs));
        $last = Scanner::last();
        $groups = [];
        foreach ($refs as $r) {
            $owned = in_array($r['source_type'], ['post', 'term', 'user', 'comment'], true);
            $groups[$owned ? $r['source_type'] . '|' . $r['source_id'] : $r['source_type'] . '|' . $r['source_id'] . '|' . $r['source_key']][] = $r;
        }

        ob_start();
        ?>
        <div class="mtrk-head">
            <?php echo wp_get_attachment_image($id, [60, 60], true); ?>
            <div>
                <strong><?php echo esc_html(get_the_title($id) ?: wp_basename((string) get_attached_file($id))); ?></strong><br>
                <?php echo self::badge($id, count($groups), false); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
        </div>
        <?php if (get_post_meta($id, Cleanup::META, true)) : ?>
            <p>
                <?php esc_html_e('This media is quarantined: its files were moved out of their original path and requests for them are logged.', 'elin-media-tracker'); ?>
                <a href="<?php echo esc_url(admin_url('upload.php?page=elin-media-tracker&tab=quarantine')); ?>"><?php esc_html_e('Quarantine', 'elin-media-tracker'); ?></a>
            </p>
        <?php endif; ?>
        <?php if ($status === 'none') : ?>
            <p>
                <?php esc_html_e('No scan has been run yet.', 'elin-media-tracker'); ?>
                <a href="<?php echo esc_url(admin_url('upload.php?page=elin-media-tracker')); ?>"><?php esc_html_e('Scan', 'elin-media-tracker'); ?></a>
            </p>
        <?php elseif ($status === 'new') : ?>
            <p><?php esc_html_e('This media was uploaded after the last full scan and has not been used in posts, meta or settings since. Plugin tables and code files are only checked by a full scan.', 'elin-media-tracker'); ?></p>
        <?php elseif ($status === 'unused') : ?>
            <p><?php esc_html_e('No reference to this media was found within the scope of the last scan.', 'elin-media-tracker'); ?></p>
        <?php else : ?>
            <ul class="mtrk-list">
                <?php foreach ($groups as $rows) : ?>
                    <li><?php echo self::item($rows); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($refs) >= 500) : ?>
                <p class="mtrk-note"><?php esc_html_e('Only the first 500 items are shown.', 'elin-media-tracker'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($last) : ?>
            <p class="mtrk-note">
                <?php
                printf(
                    /* translators: 1: date, 2: scope */
                    esc_html__('Last full scan: %1$s — %2$s. Changes since then to posts, meta, terms, users and settings are recorded live; plugin tables and code only by a full scan. Use outside the site (email, social media, other sites) cannot be detected.', 'elin-media-tracker'),
                    esc_html(wp_date(get_option('date_format') . ' H:i', $last['time'])),
                    esc_html(Page::scope($last))
                );
                ?>
            </p>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    /** One line of the modal: the object (post, term, ...) with a link, and the fields that hold the reference. */
    private static function item(array $rows): string
    {
        $type = $rows[0]['source_type'];
        $sid = (int) $rows[0]['source_id'];
        $key = $rows[0]['source_key'];
        $fields = implode(wp_get_list_item_separator(), array_map(fn($r) => self::field_label($r['source_key'], $r['how']), $rows));

        switch ($type) {
            case 'post':
                $post = get_post($sid);
                if (!$post) {
                    /* translators: %d: post id */
                    return esc_html(sprintf(__('Deleted post #%d', 'elin-media-tracker'), $sid));
                }
                $pto = get_post_type_object($post->post_type);
                $pso = get_post_status_object($post->post_status);
                $meta = [$pto ? $pto->labels->singular_name : $post->post_type, $pso ? $pso->label : $post->post_status, $fields];
                $view = is_post_type_viewable($post->post_type) && $post->post_status === 'publish'
                    ? sprintf(' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(get_permalink($post)), esc_html__('View', 'elin-media-tracker'))
                    : '';
                return self::line(get_the_title($post) ?: __('(no title)', 'elin-media-tracker'), get_edit_post_link($post->ID, 'raw'), $meta) . $view;

            case 'term':
                $term = get_term($sid);
                if (!$term || is_wp_error($term)) {
                    /* translators: %d: term id */
                    return esc_html(sprintf(__('Deleted term #%d', 'elin-media-tracker'), $sid));
                }
                $tax = get_taxonomy($term->taxonomy);
                return self::line($term->name, get_edit_term_link($term), [$tax ? $tax->labels->singular_name : $term->taxonomy, $fields]);

            case 'user':
                $user = get_userdata($sid);
                if (!$user) {
                    /* translators: %d: user id */
                    return esc_html(sprintf(__('Deleted user #%d', 'elin-media-tracker'), $sid));
                }
                return self::line($user->display_name, get_edit_user_link($sid), [__('User', 'elin-media-tracker'), $fields]);

            case 'comment':
                $comment = get_comment($sid);
                /* translators: %d: comment id */
                $title = sprintf(__('Comment #%d', 'elin-media-tracker'), $sid);
                return $comment
                    ? self::line($title, get_edit_comment_link($comment), [get_the_title((int) $comment->comment_post_ID), $fields])
                    : esc_html($title);

            case 'option':
                if (strpos($key, 'theme_mods_') === 0) {
                    return self::line(__('Theme customizer', 'elin-media-tracker'), admin_url('customize.php'), ['<code>' . esc_html($key) . '</code>']);
                }
                if (strpos($key, 'widget_') === 0) {
                    return self::line(__('Widgets', 'elin-media-tracker'), admin_url('widgets.php'), ['<code>' . esc_html($key) . '</code>']);
                }
                $named = ['site_icon' => __('Site icon', 'elin-media-tracker'), 'site_logo' => __('Site logo', 'elin-media-tracker')];
                return self::line($named[$key] ?? __('Settings', 'elin-media-tracker'), '', ['<code>' . esc_html($key) . '</code>']);

            case 'file':
                return self::line(__('Code file', 'elin-media-tracker'), '', ['<code dir="ltr">' . esc_html($key) . '</code>']);

            case 'keep':
                return self::line(__('Kept by you', 'elin-media-tracker'), '', [esc_html__('Marked as used outside the site.', 'elin-media-tracker')])
                    . sprintf(' <button type="button" class="button-link" data-act="unkeep" data-id="%d">%s</button>', (int) $rows[0]['attachment_id'], esc_html__('Remove the mark', 'elin-media-tracker'));

            case 'shared':
                /* translators: %d: attachment id */
                $title = get_the_title($sid) ?: sprintf(__('Attachment #%d', 'elin-media-tracker'), $sid);
                return self::line($title, get_edit_post_link($sid, 'raw') ?: '', [esc_html__('It has the same file and is in use; deleting this attachment deletes the shared file and breaks the other one\'s image too.', 'elin-media-tracker')]);

            default:
                return self::plugin_row($key, $sid);
        }
    }

    /** Plugin tables we know get a name and an edit link; the rest show table.column and the row id. */
    private static function plugin_row(string $key, int $sid): string
    {
        global $wpdb;
        $known = [ // table without prefix => [plugin, table holding the title, edit url]
            'gf_form_meta' => ['Gravity Forms', 'gf_form', 'admin.php?page=gf_edit_forms&id=%d'],
            'fluentform_forms' => ['Fluent Forms', 'fluentform_forms', 'admin.php?page=fluent_forms&route=editor&form_id=%d'],
        ];
        $table = (string) strtok($key, '.');
        $name = strpos($table, $wpdb->prefix) === 0 ? substr($table, strlen($wpdb->prefix)) : '';
        if ($sid && isset($known[$name])) {
            [$plugin, $titles, $url] = $known[$name];
            $title = $wpdb->get_var($wpdb->prepare("SELECT title FROM `{$wpdb->prefix}$titles` WHERE id = %d", $sid));
            /* translators: %d: form id */
            return self::line($title ?: sprintf(__('Form #%d', 'elin-media-tracker'), $sid), admin_url(sprintf($url, $sid)), [esc_html($plugin)]);
        }
        /* translators: %d: row id */
        $row = $sid ? sprintf(__('Row #%d', 'elin-media-tracker'), $sid) : '';
        return self::line(__('Plugin table', 'elin-media-tracker'), '', array_filter(['<code dir="ltr">' . esc_html($key) . '</code>', esc_html($row)]));
    }

    /** $meta parts are already escaped HTML. */
    private static function line(string $title, string $url, array $meta): string
    {
        $title = $url ? sprintf('<a href="%s">%s</a>', esc_url($url), esc_html($title)) : esc_html($title);
        return '<strong>' . $title . '</strong><br><span class="mtrk-meta">' . implode(' · ', array_filter($meta)) . '</span>';
    }

    private static function field_label(string $key, string $how): string
    {
        $labels = [
            '' => __('Content', 'elin-media-tracker'),
            '_thumbnail_id' => __('Featured image', 'elin-media-tracker'),
            'thumbnail_id' => __('Featured image', 'elin-media-tracker'),
            '_product_image_gallery' => __('Product gallery', 'elin-media-tracker'),
            '_elementor_data' => __('Elementor', 'elin-media-tracker'),
            'description' => __('Description', 'elin-media-tracker'),
        ];
        $label = isset($labels[$key]) ? esc_html($labels[$key]) : '<code dir="ltr">' . esc_html($key) . '</code>';
        $how = $how === 'id' ? __('By ID', 'elin-media-tracker') : __('By URL', 'elin-media-tracker');
        return $label . ' <span class="mtrk-how">(' . esc_html($how) . ')</span>';
    }

    public static function badge(int $id, int $count, bool $button = true): string
    {
        $status = get_post_meta($id, Cleanup::META, true) ? 'quarantine' : Scanner::status($id, $count);
        $text = [
            'quarantine' => __('Quarantined', 'elin-media-tracker'),
            'none' => __('Not scanned', 'elin-media-tracker'),
            /* translators: %s: number of places */
            'used' => sprintf(__('Used in %s place(s)', 'elin-media-tracker'), number_format_i18n($count)),
            'unused' => __('Unreferenced', 'elin-media-tracker'),
            'new' => __('New, unreferenced', 'elin-media-tracker'),
        ][$status];
        return $button
            ? sprintf('<button type="button" class="mtrk-badge mtrk-%s mtrk-open" data-id="%d" aria-haspopup="dialog">%s</button>', $status, $id, esc_html($text))
            : sprintf('<span class="mtrk-badge mtrk-%s">%s</span>', $status, esc_html($text));
    }

    // ---- media library ---------------------------------------------------------------------------------------

    public static function column(array $cols): array
    {
        if (current_user_can(Scanner::CAP)) {
            $cols['mtrk'] = __('Usage', 'elin-media-tracker');
        }
        return $cols;
    }

    public static function cell(string $col, int $id): void
    {
        if ($col !== 'mtrk') {
            return;
        }
        static $counts = null;
        if ($counts === null) { // one query for the whole page
            global $wp_query;
            $counts = Scanner::counts(wp_list_pluck($wp_query->posts ?? [], 'ID'));
        }
        echo self::badge($id, $counts[$id] ?? 0); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public static function filter_select($post_type): void
    {
        if ($post_type !== 'attachment' || !current_user_can(Scanner::CAP)) {
            return;
        }
        $current = sanitize_key(wp_unslash($_GET['mtrk_usage'] ?? ''));
        $options = [
            '' => __('Usage status: all', 'elin-media-tracker'),
            'used' => __('Used', 'elin-media-tracker'),
            'unused' => __('Unreferenced', 'elin-media-tracker'),
            'new' => __('New, unreferenced', 'elin-media-tracker'),
        ];
        echo '<label for="mtrk-usage" class="screen-reader-text">' . esc_html__('Usage status', 'elin-media-tracker') . '</label><select name="mtrk_usage" id="mtrk-usage">';
        foreach ($options as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($current, $value, false), esc_html($label));
        }
        echo '</select>';
    }

    public static function filter_query(\WP_Query $q): void
    {
        global $pagenow;
        if ($q->is_main_query() && $pagenow === 'upload.php' && !empty($_GET['mtrk_usage']) && current_user_can(Scanner::CAP)) {
            $q->set('mtrk_usage', sanitize_key(wp_unslash($_GET['mtrk_usage'])));
        }
    }

    /** List view sorted by the usage column: fewest places first (ascending), so unreferenced media leads. */
    public static function sort_clauses(array $clauses, \WP_Query $q): array
    {
        global $wpdb;
        if ($q->get('orderby') !== 'mtrk' || !is_admin() || !current_user_can(Scanner::CAP)) {
            return $clauses;
        }
        $clauses['join'] .= ' LEFT JOIN (SELECT attachment_id, COUNT(*) AS n FROM ' . Scanner::table() . " GROUP BY attachment_id) mtrk ON mtrk.attachment_id = $wpdb->posts.ID";
        $dir = strtoupper((string) $q->get('order')) === 'DESC' ? 'DESC' : 'ASC';
        $clauses['orderby'] = "COALESCE(mtrk.n, 0) $dir, $wpdb->posts.post_date DESC";
        return $clauses;
    }

    /** Grid view: WordPress drops unknown query args before this filter, so the usage filter comes from the request. */
    public static function grid_query(array $query): array
    {
        $usage = sanitize_key(wp_unslash($_REQUEST['query']['mtrk_usage'] ?? ''));
        if ($usage && current_user_can(Scanner::CAP)) {
            $query['mtrk_usage'] = $usage;
        }
        return $query;
    }

    public static function filter_where(string $where, \WP_Query $q): string
    {
        $usage = $q->get('mtrk_usage');
        return $usage ? $where . Scanner::where($usage) : $where;
    }

    /** Grid view / media modal: the same badge in the attachment details panel. */
    public static function field(array $fields, \WP_Post $post): array
    {
        if (current_user_can(Scanner::CAP)) {
            $fields['mtrk'] = [
                'label' => __('Usage', 'elin-media-tracker'),
                'input' => 'html',
                'html' => self::badge($post->ID, Scanner::counts([$post->ID])[$post->ID] ?? 0),
            ];
        }
        return $fields;
    }
}
