<?php

namespace MediaTracker;

defined('ABSPATH') || exit;

/** Scan page, AJAX endpoints, and the usage column/filter/modal in the media library. */
final class Admin
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_enqueue_media', [self::class, 'enqueue']);
        add_action('wp_ajax_mtrk_scan', [self::class, 'ajax_scan']);
        add_action('wp_ajax_mtrk_usage', [self::class, 'ajax_usage']);
        add_action('wp_ajax_mtrk_sizes', [self::class, 'ajax_sizes']);
        add_filter('manage_media_columns', [self::class, 'column']);
        add_action('manage_media_custom_column', [self::class, 'cell'], 10, 2);
        add_action('restrict_manage_posts', [self::class, 'filter_select']);
        add_action('pre_get_posts', [self::class, 'filter_query']);
        add_filter('posts_where', [self::class, 'filter_where'], 10, 2);
        add_filter('attachment_fields_to_edit', [self::class, 'field'], 10, 2);
    }

    public static function menu(): void
    {
        add_media_page(__('ردیاب رسانه', 'media-tracker'), __('ردیاب رسانه', 'media-tracker'), Scanner::CAP, 'media-tracker', [self::class, 'page']);
    }

    public static function assets(string $hook): void
    {
        if (in_array($hook, ['upload.php', 'post.php', 'media_page_media-tracker'], true)) {
            self::enqueue();
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
                'title' => __('محل‌های استفاده', 'media-tracker'),
                'close' => __('بستن', 'media-tracker'),
                'loading' => __('در حال بارگذاری…', 'media-tracker'),
                'error' => __('خطا در ارتباط با سرور.', 'media-tracker'),
                'done' => __('اسکن تمام شد.', 'media-tracker'),
                'stopping' => __('در حال توقف…', 'media-tracker'),
                /* translators: %s: number of images checked */
                'sizesChecking' => __('در حال بررسی… %s تصویر', 'media-tracker'),
                'sizesNone' => __('سایز ثبت‌نشده‌ای پیدا نشد.', 'media-tracker'),
                'mb' => __('مگابایت', 'media-tracker'),
                /* translators: 1: number of files, 2: size in MB */
                'sizesFound' => __('%1$s فایل، %2$s مگابایت قابل حذف.', 'media-tracker'),
                /* translators: 1: number of files, 2: size in MB */
                'sizesConfirm' => __('%1$s فایل (%2$s مگابایت) برای همیشه حذف می‌شود. ادامه می‌دهید؟', 'media-tracker'),
                /* translators: 1: number of files, 2: size in MB */
                'sizesDeleted' => __('%1$s فایل حذف شد (%2$s مگابایت).', 'media-tracker'),
            ],
        ]) . ';', 'before');
    }

    // ---- scan page -------------------------------------------------------------------------------------------

    public static function page(): void
    {
        $last = Scanner::last();
        $summary = Scanner::summary();
        $job = Scanner::job();
        $list = admin_url('upload.php?mode=list&mtrk_usage=');
        ?>
        <div class="wrap mtrk-page">
            <h1><?php esc_html_e('ردیاب رسانه', 'media-tracker'); ?></h1>

            <?php if ($last) : ?>
                <p>
                    <?php
                    printf(
                        /* translators: 1: date, 2: scope, 3: duration */
                        esc_html__('آخرین اسکن: %1$s — %2$s — مدت: %3$s', 'media-tracker'),
                        esc_html(wp_date(get_option('date_format') . ' H:i', $last['time'])),
                        esc_html(self::scope($last)),
                        esc_html(human_time_diff(0, max(1, $last['secs'])))
                    );
                    ?>
                </p>
                <div class="mtrk-cards">
                    <a class="mtrk-card" href="<?php echo esc_url($list . 'used'); ?>"><strong><?php echo esc_html(number_format_i18n($summary['used'])); ?></strong><?php esc_html_e('استفاده‌شده', 'media-tracker'); ?></a>
                    <a class="mtrk-card mtrk-card-unused" href="<?php echo esc_url($list . 'unused'); ?>"><strong><?php echo esc_html(number_format_i18n($summary['unused'])); ?></strong><?php esc_html_e('بدون ارجاع', 'media-tracker'); ?></a>
                    <a class="mtrk-card" href="<?php echo esc_url($list . 'new'); ?>"><strong><?php echo esc_html(number_format_i18n($summary['new'])); ?></strong><?php esc_html_e('جدید، بدون ارجاع', 'media-tracker'); ?></a>
                </div>
                <p class="description"><?php esc_html_e('«بدون ارجاع» یعنی در محدوده‌ی اسکن ارجاعی پیدا نشد. بعد از اسکن، تغییرات نوشته‌ها، متاها، دسته‌ها، کاربران و تنظیمات زنده ثبت می‌شود؛ جدول‌های افزونه‌ها و فایل‌های کد فقط با اسکن کامل به‌روز می‌شوند. استفاده از بیرون سایت (ایمیل، شبکه‌های اجتماعی، سایت‌های دیگر) قابل تشخیص نیست؛ قبل از حذف، بکاپ بگیرید.', 'media-tracker'); ?></p>
            <?php elseif (!$job) : ?>
                <p><?php esc_html_e('هنوز اسکنی انجام نشده است.', 'media-tracker'); ?></p>
            <?php endif; ?>

            <h2><?php esc_html_e('اسکن جدید', 'media-tracker'); ?></h2>
            <form id="mtrk-form" <?php echo $job ? 'hidden' : ''; ?>>
                <fieldset>
                    <legend class="screen-reader-text"><?php esc_html_e('محدوده‌ی اسکن', 'media-tracker'); ?></legend>
                    <p>
                        <label><input type="radio" name="code" value="0" checked> <strong><?php esc_html_e('فقط دیتابیس', 'media-tracker'); ?></strong></label><br>
                        <span class="description"><?php esc_html_e('سریع. نوشته‌ها و برگه‌ها، متاها (المنتور، ووکامرس، ACF)، تنظیمات و ابزارک‌ها، دسته‌ها، کاربران و دیدگاه‌ها.', 'media-tracker'); ?></span>
                    </p>
                    <p>
                        <label><input type="radio" name="code" value="1"> <strong><?php esc_html_e('دیتابیس + سورس کد', 'media-tracker'); ?></strong></label><br>
                        <span class="description"><?php esc_html_e('به‌علاوه‌ی فایل‌های قالب فعال، افزونه‌های فعال، mu-plugins و فایل‌های CSS/JS داخل پوشه‌ی uploads (مثل CSS ساخته‌شده‌ی المنتور).', 'media-tracker'); ?></span>
                    </p>
                    <p>
                        <label><input type="checkbox" name="tables" value="1" checked> <?php esc_html_e('جدول‌های اختصاصی افزونه‌ها هم بررسی شوند', 'media-tracker'); ?></label><br>
                        <span class="description"><?php esc_html_e('مثل اسلایدرها و فرم‌سازها. جدول‌های لاگ، سشن و کش کنار گذاشته می‌شوند.', 'media-tracker'); ?></span>
                    </p>
                </fieldset>
                <p class="description"><?php esc_html_e('نتیجه‌ی اسکن قبلی پاک می‌شود. اگر وسط اسکن صفحه را ببندید، دفعه‌ی بعد از همان‌جا ادامه پیدا می‌کند.', 'media-tracker'); ?></p>
                <?php submit_button(__('شروع اسکن', 'media-tracker'), 'primary', 'submit', false); ?>
            </form>

            <div id="mtrk-progress" <?php echo $job ? '' : 'hidden'; ?> data-running="<?php echo esc_attr(wp_json_encode($job ? Scanner::progress($job) : null)); ?>">
                <progress max="100" value="0"></progress>
                <p class="mtrk-label" aria-live="polite"></p>
                <button type="button" class="button mtrk-cancel"><?php esc_html_e('توقف اسکن', 'media-tracker'); ?></button>
                <button type="button" class="button button-primary mtrk-retry" hidden><?php esc_html_e('ادامه', 'media-tracker'); ?></button>
            </div>

            <h2><?php esc_html_e('سایزهای ثبت‌نشده', 'media-tracker'); ?></h2>
            <p class="description"><?php esc_html_e('فایل‌هایی که برای سایزهایی ساخته شده‌اند که دیگر هیچ قالب یا افزونه‌ای ثبتشان نکرده (مثلاً سایزهای قالب قبلی). وردپرس دیگر آن‌ها را تحویل نمی‌دهد، ولی تا وقتی تصویر استفاده می‌شود روی دیسک می‌مانند. فایلی که آدرس دقیقش جایی آمده، یا با یک سایز فعال یکی است، نگه داشته می‌شود.', 'media-tracker'); ?></p>
            <?php if (empty($last['paths'])) : ?>
                <p><?php esc_html_e('برای این بخش اول یک اسکن کامل اجرا کنید.', 'media-tracker'); ?></p>
            <?php else : ?>
                <?php if (!$last['code']) : ?>
                    <div class="notice notice-warning inline"><p><?php esc_html_e('آخرین اسکن «فقط دیتابیس» بود؛ آدرس‌هایی که مستقیم در کد قالب یا افزونه نوشته شده‌اند بررسی نشده‌اند. قبل از حذف، اسکن «دیتابیس + سورس کد» را اجرا کنید.', 'media-tracker'); ?></p></div>
                <?php endif; ?>
                <div id="mtrk-sizes">
                    <p><button type="button" class="button mtrk-sizes-check"><?php esc_html_e('بررسی سایزها', 'media-tracker'); ?></button></p>
                    <p class="mtrk-sizes-status" aria-live="polite"></p>
                    <div class="mtrk-sizes-result" hidden>
                        <table class="widefat striped mtrk-sizes-table">
                            <thead><tr>
                                <td class="check-column"><input type="checkbox" class="mtrk-sizes-all" aria-label="<?php esc_attr_e('انتخاب همه', 'media-tracker'); ?>"></td>
                                <th scope="col"><?php esc_html_e('سایز', 'media-tracker'); ?></th>
                                <th scope="col"><?php esc_html_e('ابعاد', 'media-tracker'); ?></th>
                                <th scope="col"><?php esc_html_e('فایل قابل حذف', 'media-tracker'); ?></th>
                                <th scope="col"><?php esc_html_e('حجم', 'media-tracker'); ?></th>
                                <th scope="col"><?php esc_html_e('نگه‌داشته‌شده', 'media-tracker'); ?></th>
                            </tr></thead>
                            <tbody></tbody>
                        </table>
                        <p class="description"><?php esc_html_e('حذف برگشت‌پذیر نیست؛ قبل از آن بکاپ بگیرید. نسخه‌های WebP/AVIF همان فایل‌ها هم حذف می‌شوند.', 'media-tracker'); ?></p>
                        <p><button type="button" class="button button-primary mtrk-sizes-clean" disabled><?php esc_html_e('حذف فایل‌های سایزهای انتخاب‌شده', 'media-tracker'); ?></button></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function scope(array $last): string
    {
        $scope = $last['code'] ? __('دیتابیس + سورس کد', 'media-tracker') : __('فقط دیتابیس', 'media-tracker');
        return $last['tables'] ? $scope . __('، با جدول‌های افزونه‌ها', 'media-tracker') : $scope;
    }

    // ---- ajax ------------------------------------------------------------------------------------------------

    public static function ajax_scan(): void
    {
        self::guard();
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
        if ($do === 'start') {
            wp_send_json_success(Scanner::start(!empty($_POST['code']), !empty($_POST['tables'])));
        }
        if ($do === 'step') {
            wp_send_json_success(Scanner::run_step());
        }
        if ($do === 'cancel') {
            Scanner::cancel();
            wp_send_json_success();
        }
        wp_send_json_error(__('درخواست نامعتبر است.', 'media-tracker'), 400);
    }

    public static function ajax_usage(): void
    {
        self::guard();
        $id = absint($_POST['id'] ?? 0);
        if (get_post_type($id) !== 'attachment') {
            wp_send_json_error(__('رسانه پیدا نشد.', 'media-tracker'), 404);
        }
        wp_send_json_success(['html' => self::usage_html($id)]);
    }

    public static function ajax_sizes(): void
    {
        self::guard();
        if (empty(Scanner::last()['paths'])) {
            wp_send_json_error(__('اول یک اسکن کامل اجرا کنید.', 'media-tracker'), 409);
        }
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
        $names = array_map('sanitize_text_field', (array) wp_unslash($_POST['names'] ?? []));
        if ($do === 'clean' && !$names) {
            wp_send_json_error(__('هیچ سایزی انتخاب نشده است.', 'media-tracker'), 400);
        }
        wp_send_json_success(Sizes::step(absint($_POST['cursor'] ?? 0), $names, $do === 'clean'));
    }

    private static function guard(): void
    {
        if (!check_ajax_referer('mtrk', false, false)) {
            wp_send_json_error(__('نشست منقضی شده است؛ صفحه را دوباره بارگذاری کنید.', 'media-tracker'), 403);
        }
        if (!current_user_can(Scanner::CAP)) {
            wp_send_json_error(__('شما اجازه‌ی انجام این عملیات را ندارید.', 'media-tracker'), 403);
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
        <?php if ($status === 'none') : ?>
            <p>
                <?php esc_html_e('هنوز اسکنی انجام نشده است.', 'media-tracker'); ?>
                <a href="<?php echo esc_url(admin_url('upload.php?page=media-tracker')); ?>"><?php esc_html_e('اسکن', 'media-tracker'); ?></a>
            </p>
        <?php elseif ($status === 'new') : ?>
            <p><?php esc_html_e('این رسانه بعد از آخرین اسکن کامل آپلود شده و تا الان در نوشته‌ها، متاها و تنظیمات استفاده نشده است. جدول‌های افزونه‌ها و فایل‌های کد فقط با اسکن کامل بررسی می‌شوند.', 'media-tracker'); ?></p>
        <?php elseif ($status === 'unused') : ?>
            <p><?php esc_html_e('در محدوده‌ی آخرین اسکن هیچ ارجاعی به این رسانه پیدا نشد.', 'media-tracker'); ?></p>
        <?php else : ?>
            <ul class="mtrk-list">
                <?php foreach ($groups as $rows) : ?>
                    <li><?php echo self::item($rows); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($refs) >= 500) : ?>
                <p class="mtrk-note"><?php esc_html_e('فقط ۵۰۰ مورد اول نمایش داده شده است.', 'media-tracker'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($last) : ?>
            <p class="mtrk-note">
                <?php
                printf(
                    /* translators: 1: date, 2: scope */
                    esc_html__('آخرین اسکن کامل: %1$s — %2$s. تغییرات بعد از آن در نوشته‌ها، متاها، دسته‌ها، کاربران و تنظیمات زنده ثبت می‌شود؛ جدول‌های افزونه‌ها و کد فقط با اسکن کامل. استفاده از بیرون سایت (ایمیل، شبکه‌های اجتماعی، سایت‌های دیگر) قابل تشخیص نیست.', 'media-tracker'),
                    esc_html(wp_date(get_option('date_format') . ' H:i', $last['time'])),
                    esc_html(self::scope($last))
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
                    return esc_html(sprintf(__('نوشته‌ی حذف‌شده #%d', 'media-tracker'), $sid));
                }
                $pto = get_post_type_object($post->post_type);
                $pso = get_post_status_object($post->post_status);
                $meta = [$pto ? $pto->labels->singular_name : $post->post_type, $pso ? $pso->label : $post->post_status, $fields];
                $view = is_post_type_viewable($post->post_type) && $post->post_status === 'publish'
                    ? sprintf(' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(get_permalink($post)), esc_html__('مشاهده', 'media-tracker'))
                    : '';
                return self::line(get_the_title($post) ?: __('(بدون عنوان)', 'media-tracker'), get_edit_post_link($post->ID, 'raw'), $meta) . $view;

            case 'term':
                $term = get_term($sid);
                if (!$term || is_wp_error($term)) {
                    /* translators: %d: term id */
                    return esc_html(sprintf(__('دسته‌ی حذف‌شده #%d', 'media-tracker'), $sid));
                }
                $tax = get_taxonomy($term->taxonomy);
                return self::line($term->name, get_edit_term_link($term), [$tax ? $tax->labels->singular_name : $term->taxonomy, $fields]);

            case 'user':
                $user = get_userdata($sid);
                if (!$user) {
                    /* translators: %d: user id */
                    return esc_html(sprintf(__('کاربر حذف‌شده #%d', 'media-tracker'), $sid));
                }
                return self::line($user->display_name, get_edit_user_link($sid), [__('کاربر', 'media-tracker'), $fields]);

            case 'comment':
                $comment = get_comment($sid);
                /* translators: %d: comment id */
                $title = sprintf(__('دیدگاه #%d', 'media-tracker'), $sid);
                return $comment
                    ? self::line($title, get_edit_comment_link($comment), [get_the_title((int) $comment->comment_post_ID), $fields])
                    : esc_html($title);

            case 'option':
                if (strpos($key, 'theme_mods_') === 0) {
                    return self::line(__('سفارشی‌سازی قالب', 'media-tracker'), admin_url('customize.php'), ['<code>' . esc_html($key) . '</code>']);
                }
                if (strpos($key, 'widget_') === 0) {
                    return self::line(__('ابزارک‌ها', 'media-tracker'), admin_url('widgets.php'), ['<code>' . esc_html($key) . '</code>']);
                }
                $named = ['site_icon' => __('نماد سایت', 'media-tracker'), 'site_logo' => __('لوگوی سایت', 'media-tracker')];
                return self::line($named[$key] ?? __('تنظیمات', 'media-tracker'), '', ['<code>' . esc_html($key) . '</code>']);

            case 'file':
                return self::line(__('فایل کد', 'media-tracker'), '', ['<code dir="ltr">' . esc_html($key) . '</code>']);

            case 'shared':
                /* translators: %d: attachment id */
                $title = get_the_title($sid) ?: sprintf(__('پیوست #%d', 'media-tracker'), $sid);
                return self::line($title, get_edit_post_link($sid, 'raw') ?: '', [esc_html__('همین فایل را دارد و استفاده شده است؛ حذف این پیوست فایل مشترک را پاک می‌کند و تصویر آن یکی هم می‌شکند.', 'media-tracker')]);

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
            return self::line($title ?: sprintf(__('فرم #%d', 'media-tracker'), $sid), admin_url(sprintf($url, $sid)), [esc_html($plugin)]);
        }
        /* translators: %d: row id */
        $row = $sid ? sprintf(__('ردیف #%d', 'media-tracker'), $sid) : '';
        return self::line(__('جدول افزونه', 'media-tracker'), '', array_filter(['<code dir="ltr">' . esc_html($key) . '</code>', esc_html($row)]));
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
            '' => __('محتوا', 'media-tracker'),
            '_thumbnail_id' => __('تصویر شاخص', 'media-tracker'),
            'thumbnail_id' => __('تصویر شاخص', 'media-tracker'),
            '_product_image_gallery' => __('گالری محصول', 'media-tracker'),
            '_elementor_data' => __('المنتور', 'media-tracker'),
            'description' => __('توضیحات', 'media-tracker'),
        ];
        $label = isset($labels[$key]) ? esc_html($labels[$key]) : '<code dir="ltr">' . esc_html($key) . '</code>';
        $how = $how === 'id' ? __('با شناسه', 'media-tracker') : __('با آدرس', 'media-tracker');
        return $label . ' <span class="mtrk-how">(' . esc_html($how) . ')</span>';
    }

    private static function badge(int $id, int $count, bool $button = true): string
    {
        $status = Scanner::status($id, $count);
        $text = [
            'none' => __('اسکن نشده', 'media-tracker'),
            /* translators: %s: number of places */
            'used' => sprintf(__('%s محل استفاده', 'media-tracker'), number_format_i18n($count)),
            'unused' => __('بدون ارجاع', 'media-tracker'),
            'new' => __('جدید، بدون ارجاع', 'media-tracker'),
        ][$status];
        return $button
            ? sprintf('<button type="button" class="mtrk-badge mtrk-%s mtrk-open" data-id="%d" aria-haspopup="dialog">%s</button>', $status, $id, esc_html($text))
            : sprintf('<span class="mtrk-badge mtrk-%s">%s</span>', $status, esc_html($text));
    }

    // ---- media library ---------------------------------------------------------------------------------------

    public static function column(array $cols): array
    {
        if (current_user_can(Scanner::CAP)) {
            $cols['mtrk'] = __('استفاده', 'media-tracker');
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
            '' => __('وضعیت استفاده: همه', 'media-tracker'),
            'used' => __('استفاده‌شده', 'media-tracker'),
            'unused' => __('بدون ارجاع', 'media-tracker'),
            'new' => __('جدید، بدون ارجاع', 'media-tracker'),
        ];
        echo '<label for="mtrk-usage" class="screen-reader-text">' . esc_html__('وضعیت استفاده', 'media-tracker') . '</label><select name="mtrk_usage" id="mtrk-usage">';
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
                'label' => __('استفاده', 'media-tracker'),
                'input' => 'html',
                'html' => self::badge($post->ID, Scanner::counts([$post->ID])[$post->ID] ?? 0),
            ];
        }
        return $fields;
    }
}
