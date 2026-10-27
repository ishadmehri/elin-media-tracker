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
        add_media_page(__('ردیاب رسانه', 'elin-media-tracker'), __('ردیاب رسانه', 'elin-media-tracker'), Scanner::CAP, 'elin-media-tracker', [Page::class, 'render']);
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
                'title' => __('محل‌های استفاده', 'elin-media-tracker'),
                'close' => __('بستن', 'elin-media-tracker'),
                'loading' => __('در حال بارگذاری…', 'elin-media-tracker'),
                'error' => __('خطا در ارتباط با سرور.', 'elin-media-tracker'),
                'done' => __('اسکن تمام شد.', 'elin-media-tracker'),
                'stopping' => __('در حال توقف…', 'elin-media-tracker'),
                /* translators: %s: number of images checked */
                'sizesChecking' => __('در حال بررسی… %s تصویر', 'elin-media-tracker'),
                'sizesNone' => __('سایز ثبت‌نشده‌ای پیدا نشد.', 'elin-media-tracker'),
                'mb' => __('مگابایت', 'elin-media-tracker'),
                'kb' => __('کیلوبایت', 'elin-media-tracker'),
                /* translators: 1: number of files, 2: size with unit */
                'sizesFound' => __('%1$s فایل، %2$s قابل حذف.', 'elin-media-tracker'),
                /* translators: 1: number of files, 2: size with unit */
                'sizesConfirm' => __('%1$s فایل (%2$s) برای همیشه حذف می‌شود. ادامه می‌دهید؟', 'elin-media-tracker'),
                /* translators: 1: number of files, 2: size with unit */
                'sizesDeleted' => __('%1$s فایل حذف شد (%2$s).', 'elin-media-tracker'),
                'preview' => __('پیش‌نمایش', 'elin-media-tracker'),
                'usageLabel' => __('وضعیت استفاده', 'elin-media-tracker'),
                'usageAll' => __('وضعیت استفاده: همه', 'elin-media-tracker'),
                'usageUsed' => __('استفاده‌شده', 'elin-media-tracker'),
                'usageUnused' => __('بدون ارجاع', 'elin-media-tracker'),
                'usageNew' => __('جدید، بدون ارجاع', 'elin-media-tracker'),
                /* translators: %s: number of items */
                'selected' => __('%s مورد انتخاب شده', 'elin-media-tracker'),
                /* translators: %s: number of media items */
                'confirmDelete' => __('%s رسانه و همه‌ی فایل‌هایش برای همیشه حذف می‌شود. این کار برگشت‌پذیر نیست. ادامه می‌دهید؟', 'elin-media-tracker'),
                /* translators: %s: number of files */
                'confirmFiles' => __('%s فایل برای همیشه حذف می‌شود. این کار برگشت‌پذیر نیست. ادامه می‌دهید؟', 'elin-media-tracker'),
                /* translators: %s: number of folders */
                'orphansChecking' => __('در حال بررسی… %s پوشه', 'elin-media-tracker'),
                'orphansNone' => __('فایل اضافه‌ای پیدا نشد.', 'elin-media-tracker'),
                /* translators: 1: number of files, 2: size with unit */
                'orphansFound' => __('%1$s فایل، %2$s قابل حذف.', 'elin-media-tracker'),
                /* translators: %s: attachment id */
                'orphanSize' => __('سایز اضافه‌ی پیوست #%s', 'elin-media-tracker'),
                'orphanFile' => __('خارج از کتابخانه', 'elin-media-tracker'),
                'orphanKept' => __('آدرسش جایی آمده؛ نگه داشته می‌شود', 'elin-media-tracker'),
                'more' => __('نمایش بیشتر', 'elin-media-tracker'),
                'probeOk' => __('درخواست فایل‌های قرنطینه به وردپرس می‌رسد: به بازدیدکننده تحویل داده می‌شوند و ثبت می‌شوند.', 'elin-media-tracker'),
                'probeFail' => __('درخواست فایل‌های ناموجود به وردپرس نمی‌رسد (احتمالاً یک قانون nginx/LiteSpeed برای فایل‌های تصویری، یا CDN). فایل قرنطینه‌شده برای بازدیدکننده خطای ۴۰۴ می‌دهد و بازدیدی ثبت نمی‌شود. با این وضعیت، قرنطینه فقط یک «حذف برگشت‌پذیر» است.', 'elin-media-tracker'),
            ],
        ]) . ';', 'before');
    }

    // ---- ajax ------------------------------------------------------------------------------------------------

    public static function ajax_usage(): void
    {
        self::guard();
        $id = absint($_POST['id'] ?? 0);
        if (get_post_type($id) !== 'attachment') {
            wp_send_json_error(__('رسانه پیدا نشد.', 'elin-media-tracker'), 404);
        }
        wp_send_json_success(['html' => self::usage_html($id)]);
    }

    public static function guard(): void
    {
        if (!check_ajax_referer('mtrk', false, false)) {
            wp_send_json_error(__('نشست منقضی شده است؛ صفحه را دوباره بارگذاری کنید.', 'elin-media-tracker'), 403);
        }
        if (!current_user_can(Scanner::CAP)) {
            wp_send_json_error(__('شما اجازه‌ی انجام این عملیات را ندارید.', 'elin-media-tracker'), 403);
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
                <?php esc_html_e('این رسانه در قرنطینه است: فایل‌هایش از مسیر اصلی برداشته شده‌اند و درخواست‌ها برایشان ثبت می‌شود.', 'elin-media-tracker'); ?>
                <a href="<?php echo esc_url(admin_url('upload.php?page=elin-media-tracker&tab=quarantine')); ?>"><?php esc_html_e('قرنطینه', 'elin-media-tracker'); ?></a>
            </p>
        <?php endif; ?>
        <?php if ($status === 'none') : ?>
            <p>
                <?php esc_html_e('هنوز اسکنی انجام نشده است.', 'elin-media-tracker'); ?>
                <a href="<?php echo esc_url(admin_url('upload.php?page=elin-media-tracker')); ?>"><?php esc_html_e('اسکن', 'elin-media-tracker'); ?></a>
            </p>
        <?php elseif ($status === 'new') : ?>
            <p><?php esc_html_e('این رسانه بعد از آخرین اسکن کامل آپلود شده و تا الان در نوشته‌ها، متاها و تنظیمات استفاده نشده است. جدول‌های افزونه‌ها و فایل‌های کد فقط با اسکن کامل بررسی می‌شوند.', 'elin-media-tracker'); ?></p>
        <?php elseif ($status === 'unused') : ?>
            <p><?php esc_html_e('در محدوده‌ی آخرین اسکن هیچ ارجاعی به این رسانه پیدا نشد.', 'elin-media-tracker'); ?></p>
        <?php else : ?>
            <ul class="mtrk-list">
                <?php foreach ($groups as $rows) : ?>
                    <li><?php echo self::item($rows); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($refs) >= 500) : ?>
                <p class="mtrk-note"><?php esc_html_e('فقط ۵۰۰ مورد اول نمایش داده شده است.', 'elin-media-tracker'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($last) : ?>
            <p class="mtrk-note">
                <?php
                printf(
                    /* translators: 1: date, 2: scope */
                    esc_html__('آخرین اسکن کامل: %1$s — %2$s. تغییرات بعد از آن در نوشته‌ها، متاها، دسته‌ها، کاربران و تنظیمات زنده ثبت می‌شود؛ جدول‌های افزونه‌ها و کد فقط با اسکن کامل. استفاده از بیرون سایت (ایمیل، شبکه‌های اجتماعی، سایت‌های دیگر) قابل تشخیص نیست.', 'elin-media-tracker'),
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
        $fields = implode('، ', array_map(fn($r) => self::field_label($r['source_key'], $r['how']), $rows));

        switch ($type) {
            case 'post':
                $post = get_post($sid);
                if (!$post) {
                    /* translators: %d: post id */
                    return esc_html(sprintf(__('نوشته‌ی حذف‌شده #%d', 'elin-media-tracker'), $sid));
                }
                $pto = get_post_type_object($post->post_type);
                $pso = get_post_status_object($post->post_status);
                $meta = [$pto ? $pto->labels->singular_name : $post->post_type, $pso ? $pso->label : $post->post_status, $fields];
                $view = is_post_type_viewable($post->post_type) && $post->post_status === 'publish'
                    ? sprintf(' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(get_permalink($post)), esc_html__('مشاهده', 'elin-media-tracker'))
                    : '';
                return self::line(get_the_title($post) ?: __('(بدون عنوان)', 'elin-media-tracker'), get_edit_post_link($post->ID, 'raw'), $meta) . $view;

            case 'term':
                $term = get_term($sid);
                if (!$term || is_wp_error($term)) {
                    /* translators: %d: term id */
                    return esc_html(sprintf(__('دسته‌ی حذف‌شده #%d', 'elin-media-tracker'), $sid));
                }
                $tax = get_taxonomy($term->taxonomy);
                return self::line($term->name, get_edit_term_link($term), [$tax ? $tax->labels->singular_name : $term->taxonomy, $fields]);

            case 'user':
                $user = get_userdata($sid);
                if (!$user) {
                    /* translators: %d: user id */
                    return esc_html(sprintf(__('کاربر حذف‌شده #%d', 'elin-media-tracker'), $sid));
                }
                return self::line($user->display_name, get_edit_user_link($sid), [__('کاربر', 'elin-media-tracker'), $fields]);

            case 'comment':
                $comment = get_comment($sid);
                /* translators: %d: comment id */
                $title = sprintf(__('دیدگاه #%d', 'elin-media-tracker'), $sid);
                return $comment
                    ? self::line($title, get_edit_comment_link($comment), [get_the_title((int) $comment->comment_post_ID), $fields])
                    : esc_html($title);

            case 'option':
                if (strpos($key, 'theme_mods_') === 0) {
                    return self::line(__('سفارشی‌سازی قالب', 'elin-media-tracker'), admin_url('customize.php'), ['<code>' . esc_html($key) . '</code>']);
                }
                if (strpos($key, 'widget_') === 0) {
                    return self::line(__('ابزارک‌ها', 'elin-media-tracker'), admin_url('widgets.php'), ['<code>' . esc_html($key) . '</code>']);
                }
                $named = ['site_icon' => __('نماد سایت', 'elin-media-tracker'), 'site_logo' => __('لوگوی سایت', 'elin-media-tracker')];
                return self::line($named[$key] ?? __('تنظیمات', 'elin-media-tracker'), '', ['<code>' . esc_html($key) . '</code>']);

            case 'file':
                return self::line(__('فایل کد', 'elin-media-tracker'), '', ['<code dir="ltr">' . esc_html($key) . '</code>']);

            case 'keep':
                return self::line(__('نگه‌داشته‌شده به دست شما', 'elin-media-tracker'), '', [esc_html__('برای استفاده‌ی بیرون از سایت علامت خورده است.', 'elin-media-tracker')])
                    . sprintf(' <button type="button" class="button-link" data-act="unkeep" data-id="%d">%s</button>', (int) $rows[0]['attachment_id'], esc_html__('برداشتن علامت', 'elin-media-tracker'));

            case 'shared':
                /* translators: %d: attachment id */
                $title = get_the_title($sid) ?: sprintf(__('پیوست #%d', 'elin-media-tracker'), $sid);
                return self::line($title, get_edit_post_link($sid, 'raw') ?: '', [esc_html__('همین فایل را دارد و استفاده شده است؛ حذف این پیوست فایل مشترک را پاک می‌کند و تصویر آن یکی هم می‌شکند.', 'elin-media-tracker')]);

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
            return self::line($title ?: sprintf(__('فرم #%d', 'elin-media-tracker'), $sid), admin_url(sprintf($url, $sid)), [esc_html($plugin)]);
        }
        /* translators: %d: row id */
        $row = $sid ? sprintf(__('ردیف #%d', 'elin-media-tracker'), $sid) : '';
        return self::line(__('جدول افزونه', 'elin-media-tracker'), '', array_filter(['<code dir="ltr">' . esc_html($key) . '</code>', esc_html($row)]));
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
            '' => __('محتوا', 'elin-media-tracker'),
            '_thumbnail_id' => __('تصویر شاخص', 'elin-media-tracker'),
            'thumbnail_id' => __('تصویر شاخص', 'elin-media-tracker'),
            '_product_image_gallery' => __('گالری محصول', 'elin-media-tracker'),
            '_elementor_data' => __('المنتور', 'elin-media-tracker'),
            'description' => __('توضیحات', 'elin-media-tracker'),
        ];
        $label = isset($labels[$key]) ? esc_html($labels[$key]) : '<code dir="ltr">' . esc_html($key) . '</code>';
        $how = $how === 'id' ? __('با شناسه', 'elin-media-tracker') : __('با آدرس', 'elin-media-tracker');
        return $label . ' <span class="mtrk-how">(' . esc_html($how) . ')</span>';
    }

    public static function badge(int $id, int $count, bool $button = true): string
    {
        $status = get_post_meta($id, Cleanup::META, true) ? 'quarantine' : Scanner::status($id, $count);
        $text = [
            'quarantine' => __('در قرنطینه', 'elin-media-tracker'),
            'none' => __('اسکن نشده', 'elin-media-tracker'),
            /* translators: %s: number of places */
            'used' => sprintf(__('%s محل استفاده', 'elin-media-tracker'), number_format_i18n($count)),
            'unused' => __('بدون ارجاع', 'elin-media-tracker'),
            'new' => __('جدید، بدون ارجاع', 'elin-media-tracker'),
        ][$status];
        return $button
            ? sprintf('<button type="button" class="mtrk-badge mtrk-%s mtrk-open" data-id="%d" aria-haspopup="dialog">%s</button>', $status, $id, esc_html($text))
            : sprintf('<span class="mtrk-badge mtrk-%s">%s</span>', $status, esc_html($text));
    }

    // ---- media library ---------------------------------------------------------------------------------------

    public static function column(array $cols): array
    {
        if (current_user_can(Scanner::CAP)) {
            $cols['mtrk'] = __('استفاده', 'elin-media-tracker');
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
            '' => __('وضعیت استفاده: همه', 'elin-media-tracker'),
            'used' => __('استفاده‌شده', 'elin-media-tracker'),
            'unused' => __('بدون ارجاع', 'elin-media-tracker'),
            'new' => __('جدید، بدون ارجاع', 'elin-media-tracker'),
        ];
        echo '<label for="mtrk-usage" class="screen-reader-text">' . esc_html__('وضعیت استفاده', 'elin-media-tracker') . '</label><select name="mtrk_usage" id="mtrk-usage">';
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
                'label' => __('استفاده', 'elin-media-tracker'),
                'input' => 'html',
                'html' => self::badge($post->ID, Scanner::counts([$post->ID])[$post->ID] ?? 0),
            ];
        }
        return $fields;
    }
}
