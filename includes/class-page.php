<?php

namespace MediaTracker;

defined('ABSPATH') || exit;

/** The plugin's own screen (Media → ردیاب رسانه), in tabs. Unreferenced media comes first. */
final class Page
{
    private const PER_PAGE = 40;
    private const PER_PAGE_CHOICES = [20, 40, 100, 200, 500];

    public static function init(): void
    {
        add_action('wp_ajax_mtrk_scan', [self::class, 'ajax_scan']);
        add_action('wp_ajax_mtrk_sizes', [self::class, 'ajax_sizes']);
        add_action('wp_ajax_mtrk_orphans', [self::class, 'ajax_orphans']);
        add_action('wp_ajax_mtrk_act', [self::class, 'ajax_act']);
        add_action('wp_ajax_mtrk_preview', [self::class, 'ajax_preview']);
        add_action('wp_ajax_mtrk_dups', [self::class, 'ajax_dups']);
    }

    public static function url(string $tab, array $args = []): string
    {
        return add_query_arg(['page' => 'elin-media-tracker', 'tab' => $tab] + $args, admin_url('upload.php'));
    }

    public static function suspect_text(): string
    {
        $ids = (array) (Scanner::last()['suspect'] ?? []);
        return sprintf(
            /* translators: 1: number of images, 2: attachment ids */
            __('این اسکن قابل اعتماد نیست: %1$s تصویر شاخص (مثل %2$s) که حتماً استفاده می‌شوند، بدون ارجاع آمدند. پس قرنطینه و حذف غیرفعال است. اسکن را دوباره اجرا کنید؛ اگر باز تکرار شد، گزارش دهید.', 'elin-media-tracker'),
            number_format_i18n(count($ids)),
            '#' . implode('، #', array_slice($ids, 0, 5))
        );
    }

    public static function scope(array $last): string
    {
        $scope = $last['code'] ? __('دیتابیس + سورس کد', 'elin-media-tracker') : __('فقط دیتابیس', 'elin-media-tracker');
        return $last['tables'] ? $scope . __('، با جدول‌های افزونه‌ها', 'elin-media-tracker') : $scope;
    }

    public static function render(): void
    {
        $last = Scanner::last();
        $quarantined = count(Cleanup::quarantined());
        $tabs = [
            'unused' => __('بدون ارجاع', 'elin-media-tracker'),
            'duplicates' => __('تکراری‌ها', 'elin-media-tracker'),
            'quarantine' => $quarantined ? sprintf('%s (%s)', __('قرنطینه', 'elin-media-tracker'), number_format_i18n($quarantined)) : __('قرنطینه', 'elin-media-tracker'),
            'orphans' => __('فایل‌های اضافه', 'elin-media-tracker'),
            'sizes' => __('سایزهای ثبت‌نشده', 'elin-media-tracker'),
            'scan' => __('اسکن', 'elin-media-tracker'),
        ];
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? ''));
        if (!isset($tabs[$tab])) {
            $tab = $last ? 'unused' : 'scan';
        }
        ?>
        <div class="wrap mtrk-page">
            <h1><?php esc_html_e('ردیاب رسانه', 'elin-media-tracker'); ?></h1>
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('بخش‌های ردیاب رسانه', 'elin-media-tracker'); ?>">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a href="<?php echo esc_url(self::url($key)); ?>" class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php self::done_notice(); ?>
            <?php if (!empty($last['suspect'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(self::suspect_text()); ?></p></div>
            <?php endif; ?>
            <?php
            if (!$last && $tab !== 'scan' && $tab !== 'quarantine') {
                printf(
                    '<p>%s <a href="%s">%s</a></p>',
                    esc_html__('این بخش به نتیجه‌ی یک اسکن کامل نیاز دارد.', 'elin-media-tracker'),
                    esc_url(self::url('scan')),
                    esc_html__('اجرای اسکن', 'elin-media-tracker')
                );
            } else {
                self::{"tab_$tab"}($last);
            }
            ?>
        </div>
        <?php
    }

    /** After an action the page reloads with ?done=act&n=&b=; say what happened. */
    private static function done_notice(): void
    {
        $act = sanitize_key(wp_unslash($_GET['done'] ?? ''));
        $n = absint($_GET['n'] ?? 0);
        $bytes = absint($_GET['b'] ?? 0);
        $messages = [
            /* translators: %s: number of media items */
            'quarantine' => __('%s رسانه به قرنطینه رفت.', 'elin-media-tracker'),
            /* translators: %s: number of media items */
            'restore' => __('%s رسانه به جای اصلی برگشت.', 'elin-media-tracker'),
            /* translators: %s: number of media items */
            'delete' => __('%s رسانه برای همیشه حذف شد.', 'elin-media-tracker'),
            /* translators: %s: number of media items */
            'keep' => __('%s رسانه نگه داشته می‌شود و از این لیست بیرون رفت.', 'elin-media-tracker'),
            /* translators: %s: number of media items */
            'unkeep' => __('علامت «نگه‌داشتن» از %s رسانه برداشته شد.', 'elin-media-tracker'),
        ];
        if (!isset($messages[$act])) {
            return;
        }
        $text = sprintf($messages[$act], number_format_i18n($n));
        if ($bytes) {
            /* translators: %s: size, e.g. 12 MB */
            $text .= ' ' . sprintf(__('فضای آزادشده: %s', 'elin-media-tracker'), size_format($bytes));
        }
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($text));
    }

    // ---- unreferenced ----------------------------------------------------------------------------------------

    private static function tab_unused(array $last): void
    {
        $type = sanitize_key(wp_unslash($_GET['type'] ?? ''));
        $order = sanitize_key(wp_unslash($_GET['order'] ?? 'largest'));
        $paged = max(1, absint($_GET['paged'] ?? 1));
        [$per, $view] = self::screen();
        $list = Cleanup::unused($paged, $per, $type, $order);
        ?>
        <p class="mtrk-lead">
            <?php
            printf(
                /* translators: 1: number of media items, 2: size */
                esc_html__('%1$s رسانه بدون ارجاع، روی هم %2$s.', 'elin-media-tracker'),
                '<strong>' . esc_html(number_format_i18n($list['total'])) . '</strong>',
                '<strong>' . esc_html(size_format($list['bytes']) ?: '0 B') . '</strong>'
            );
            ?>
        </p>
        <?php self::scope_notice($last); ?>
        <p class="description"><?php esc_html_e('استفاده از بیرون سایت (خبرنامه، شبکه‌های اجتماعی، سایت‌های دیگر) قابل تشخیص نیست. راه امن: اول قرنطینه کنید، چند هفته صبر کنید و فقط چیزی را حذف کنید که در این مدت درخواستی نداشته.', 'elin-media-tracker'); ?></p>

        <form method="get" class="mtrk-filters">
            <input type="hidden" name="page" value="elin-media-tracker">
            <input type="hidden" name="tab" value="unused">
            <label for="mtrk-type" class="screen-reader-text"><?php esc_html_e('نوع', 'elin-media-tracker'); ?></label>
            <select name="type" id="mtrk-type">
                <?php foreach (['' => __('همه‌ی انواع', 'elin-media-tracker'), 'image' => __('تصویر', 'elin-media-tracker'), 'video' => __('ویدیو', 'elin-media-tracker'), 'audio' => __('صوت', 'elin-media-tracker'), 'document' => __('سند', 'elin-media-tracker')] as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($type, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <label for="mtrk-order" class="screen-reader-text"><?php esc_html_e('ترتیب', 'elin-media-tracker'); ?></label>
            <select name="order" id="mtrk-order">
                <?php foreach (['largest' => __('بزرگ‌ترین اول', 'elin-media-tracker'), 'newest' => __('جدیدترین اول', 'elin-media-tracker'), 'oldest' => __('قدیمی‌ترین اول', 'elin-media-tracker')] as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($order, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php self::screen_fields($per, $view); ?>
            <button class="button"><?php esc_html_e('نمایش', 'elin-media-tracker'); ?></button>
        </form>

        <?php if (!$list['items']) : ?>
            <p><?php esc_html_e('رسانه‌ی بدون ارجاعی پیدا نشد.', 'elin-media-tracker'); ?></p>
            <?php return; ?>
        <?php endif; ?>

        <div class="mtrk-bulk" data-scope="unused">
            <label><input type="checkbox" class="mtrk-all"> <?php esc_html_e('انتخاب همه‌ی این صفحه', 'elin-media-tracker'); ?></label>
            <span class="mtrk-count" aria-live="polite"></span>
            <button type="button" class="button button-primary" data-act="quarantine" disabled><?php esc_html_e('قرنطینه', 'elin-media-tracker'); ?></button>
            <button type="button" class="button" data-act="keep" disabled><?php esc_html_e('نگه‌داشتن', 'elin-media-tracker'); ?></button>
            <button type="button" class="button mtrk-danger" data-act="delete" disabled><?php esc_html_e('حذف دائمی', 'elin-media-tracker'); ?></button>
        </div>
        <?php if ($view === 'list') : ?>
            <?php self::media_table($list['items']); ?>
        <?php else : ?>
            <ul class="mtrk-grid">
                <?php foreach ($list['items'] as $id => $bytes) : ?>
                    <?php $file = wp_basename((string) get_attached_file($id)); ?>
                    <li class="mtrk-item">
                        <input type="checkbox" class="mtrk-pick" value="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('انتخاب %s', 'elin-media-tracker'), $file)); ?>">
                        <button type="button" class="mtrk-thumb mtrk-preview" data-id="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('پیش‌نمایش %s', 'elin-media-tracker'), $file)); ?>" aria-haspopup="dialog">
                            <?php echo wp_get_attachment_image($id, 'medium', true, ['alt' => '', 'loading' => 'lazy']); ?>
                        </button>
                        <span class="mtrk-item-name" dir="auto"><?php echo esc_html($file); ?></span>
                        <span class="mtrk-item-meta"><?php echo esc_html(size_format($bytes) ?: '—'); ?> · <?php echo esc_html(get_the_date('', $id)); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
        self::pagination($list['total'], $per, $paged);
    }

    /**
     * Items per page and grid/list view: from the request when given (and then remembered for this user, like
     * WordPress screen options), otherwise the user's last choice.
     * @return array{0:int,1:string}
     */
    private static function screen(): array
    {
        $user = get_current_user_id();
        $per = absint($_GET['per_page'] ?? 0);
        if (in_array($per, self::PER_PAGE_CHOICES, true)) {
            update_user_meta($user, 'mtrk_per_page', $per);
        } else {
            $per = (int) get_user_meta($user, 'mtrk_per_page', true);
            $per = in_array($per, self::PER_PAGE_CHOICES, true) ? $per : self::PER_PAGE;
        }
        $view = sanitize_key(wp_unslash($_GET['view'] ?? ''));
        if (in_array($view, ['grid', 'list'], true)) {
            update_user_meta($user, 'mtrk_view', $view);
        } else {
            $view = get_user_meta($user, 'mtrk_view', true) === 'list' ? 'list' : 'grid';
        }
        return [$per, $view];
    }

    /** $view '' leaves the view choice out (lists that have only one layout). */
    private static function screen_fields(int $per, string $view): void
    {
        ?>
        <?php if ($view !== '') : ?>
            <label for="mtrk-view" class="screen-reader-text"><?php esc_html_e('نحوه‌ی نمایش', 'elin-media-tracker'); ?></label>
            <select name="view" id="mtrk-view">
                <option value="grid" <?php selected($view, 'grid'); ?>><?php esc_html_e('نمایش کارتی', 'elin-media-tracker'); ?></option>
                <option value="list" <?php selected($view, 'list'); ?>><?php esc_html_e('نمایش لیستی', 'elin-media-tracker'); ?></option>
            </select>
        <?php endif; ?>
        <label for="mtrk-per-page" class="screen-reader-text"><?php esc_html_e('تعداد در صفحه', 'elin-media-tracker'); ?></label>
        <select name="per_page" id="mtrk-per-page">
            <?php foreach (self::PER_PAGE_CHOICES as $n) : ?>
                <option value="<?php echo (int) $n; ?>" <?php selected($per, $n); ?>><?php echo esc_html(sprintf(/* translators: %s: number */ __('%s در صفحه', 'elin-media-tracker'), number_format_i18n($n))); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    private static function pagination(int $total, int $per, int $paged): void
    {
        $pages = (int) ceil($total / max(1, $per));
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([ // phpcs:ignore WordPress.Security.EscapeOutput
                'base' => add_query_arg('paged', '%#%', remove_query_arg(['done', 'n', 'b'])),
                'format' => '',
                'current' => $paged,
                'total' => $pages,
            ]) . '</div></div>';
        }
    }

    /** List view: one row per attachment, with its status when $status is set (duplicates). @param array<int,int> $items id => bytes */
    private static function media_table(array $items, bool $status = false, ?array $pickable = null): void
    {
        $counts = $status ? Scanner::counts(array_keys($items)) : [];
        ?>
        <table class="widefat striped mtrk-mtable">
            <thead><tr>
                <td class="check-column"></td>
                <th scope="col" class="mtrk-col-thumb"><span class="screen-reader-text"><?php esc_html_e('پیش‌نمایش', 'elin-media-tracker'); ?></span></th>
                <th scope="col"><?php esc_html_e('فایل', 'elin-media-tracker'); ?></th>
                <?php if ($status) : ?><th scope="col"><?php esc_html_e('وضعیت', 'elin-media-tracker'); ?></th><?php endif; ?>
                <th scope="col"><?php esc_html_e('نوع', 'elin-media-tracker'); ?></th>
                <th scope="col"><?php esc_html_e('حجم', 'elin-media-tracker'); ?></th>
                <th scope="col"><?php esc_html_e('آپلود', 'elin-media-tracker'); ?></th>
                <th scope="col"><?php esc_html_e('آپلودشده در', 'elin-media-tracker'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $id => $bytes) : ?>
                <?php
                $path = (string) get_post_meta($id, '_wp_attached_file', true);
                $file = wp_basename($path);
                $parent = (int) wp_get_post_parent_id($id);
                $can_pick = $pickable === null || in_array($id, $pickable, true);
                ?>
                <tr>
                    <th scope="row" class="check-column">
                        <?php if ($can_pick) : ?>
                            <input type="checkbox" class="mtrk-pick" value="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('انتخاب %s', 'elin-media-tracker'), $file)); ?>">
                        <?php endif; ?>
                    </th>
                    <td class="mtrk-col-thumb">
                        <button type="button" class="mtrk-thumb mtrk-preview" data-id="<?php echo (int) $id; ?>" aria-haspopup="dialog" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('پیش‌نمایش %s', 'elin-media-tracker'), $file)); ?>"><?php echo wp_get_attachment_image($id, [48, 48], true, ['alt' => '', 'loading' => 'lazy']); ?></button>
                    </td>
                    <td><strong dir="auto"><?php echo esc_html($file); ?></strong><br><bdi class="mtrk-from" dir="ltr"><?php echo esc_html(dirname($path) === '.' ? '/' : dirname($path)); ?></bdi></td>
                    <?php if ($status) : ?><td><?php echo Admin::badge($id, $counts[$id] ?? 0); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><?php endif; ?>
                    <td><?php echo esc_html((string) get_post_mime_type($id)); ?></td>
                    <td><?php echo esc_html(size_format($bytes) ?: '—'); ?></td>
                    <td><?php echo esc_html(get_the_date('', $id)); ?></td>
                    <td><?php echo $parent ? '<a href="' . esc_url((string) get_edit_post_link($parent, 'raw')) . '">' . esc_html(get_the_title($parent) ?: "#$parent") . '</a>' : '—'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ---- duplicates ------------------------------------------------------------------------------------------

    private static function tab_duplicates(array $last): void
    {
        $checked = (int) get_option('mtrk_dups_at');
        ?>
        <p class="description"><?php esc_html_e('فایل‌هایی با محتوای کاملاً یکسان که چند بار آپلود شده‌اند. اول حجم‌ها مقایسه می‌شوند و فقط فایل‌های هم‌حجم با هش محتوا (MD5) بررسی می‌شوند. پیوست‌هایی که از اول یک فایل مشترک دارند (مثل ترجمه‌های رسانه) تکراری حساب نمی‌شوند. فقط نسخه‌هایی را می‌شود انتخاب کرد که بدون ارجاع‌اند؛ اگر همه‌ی نسخه‌ها استفاده شده‌اند، اول ارجاع‌ها را به یک نسخه تغییر دهید.', 'elin-media-tracker'); ?></p>
        <div id="mtrk-dups">
            <p>
                <button type="button" class="button<?php echo $checked ? '' : ' button-primary'; ?> mtrk-dups-check"><?php echo $checked ? esc_html__('بررسی دوباره', 'elin-media-tracker') : esc_html__('بررسی تکراری‌ها', 'elin-media-tracker'); ?></button>
                <?php if ($checked) : ?>
                    <span class="mtrk-note"><?php echo esc_html(sprintf(/* translators: %s: date */ __('آخرین بررسی: %s', 'elin-media-tracker'), wp_date(get_option('date_format') . ' H:i', $checked))); ?></span>
                <?php endif; ?>
            </p>
            <p class="mtrk-dups-status" aria-live="polite"></p>
        </div>
        <?php
        if (!$checked) {
            return;
        }
        $paged = max(1, absint($_GET['paged'] ?? 1));
        [$per] = self::screen();
        $list = Duplicates::groups($paged, $per);
        ?>
        <p class="mtrk-lead">
            <?php
            printf(
                /* translators: 1: number of groups, 2: size */
                esc_html__('%1$s گروه فایل تکراری؛ نسخه‌های اضافه روی هم %2$s.', 'elin-media-tracker'),
                '<strong>' . esc_html(number_format_i18n($list['total'])) . '</strong>',
                '<strong>' . esc_html(size_format($list['bytes']) ?: '0 B') . '</strong>'
            );
            ?>
        </p>
        <?php if (!$list['groups']) : ?>
            <p><?php esc_html_e('فایل تکراری‌ای پیدا نشد.', 'elin-media-tracker'); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <form method="get" class="mtrk-filters">
            <input type="hidden" name="page" value="elin-media-tracker">
            <input type="hidden" name="tab" value="duplicates">
            <?php self::screen_fields($per, ''); ?>
            <button class="button"><?php esc_html_e('نمایش', 'elin-media-tracker'); ?></button>
        </form>
        <div class="mtrk-bulk" data-scope="duplicates">
            <span class="mtrk-count" aria-live="polite"></span>
            <button type="button" class="button button-primary" data-act="quarantine" disabled><?php esc_html_e('قرنطینه', 'elin-media-tracker'); ?></button>
            <button type="button" class="button mtrk-danger" data-act="delete" disabled><?php esc_html_e('حذف دائمی', 'elin-media-tracker'); ?></button>
        </div>
        <?php
        $ids = array_merge(...array_column($list['groups'], 'ids'));
        $counts = Scanner::counts($ids);
        foreach ($list['groups'] as $group) {
            // Only copies nothing references (and not kept or already quarantined) can be picked.
            $pickable = array_values(array_filter($group['ids'], fn($id) => empty($counts[$id]) && !get_post_meta($id, Cleanup::META, true)));
            ?>
            <section class="mtrk-dup">
                <h3><?php echo esc_html(sprintf(/* translators: 1: number of copies, 2: size of one copy */ __('%1$s نسخه، هر کدام %2$s', 'elin-media-tracker'), number_format_i18n($group['copies']), size_format($group['size']) ?: '—')); ?></h3>
                <?php if (!$pickable) : ?>
                    <p class="mtrk-note"><?php esc_html_e('همه‌ی نسخه‌ها استفاده شده‌اند؛ چیزی برای حذف امن نیست.', 'elin-media-tracker'); ?></p>
                <?php endif; ?>
                <?php self::media_table(array_fill_keys($group['ids'], $group['size']), true, $pickable); ?>
            </section>
            <?php
        }
        self::pagination($list['total'], $per, $paged);
    }

    // ---- quarantine ------------------------------------------------------------------------------------------

    private static function tab_quarantine(?array $last): void
    {
        $rows = Cleanup::quarantined();
        $kinds = [
            'site' => __('صفحه‌های همین سایت', 'elin-media-tracker'),
            'google' => __('گوگل', 'elin-media-tracker'),
            'external' => __('سایت‌های دیگر', 'elin-media-tracker'),
            'bot' => __('ربات‌ها', 'elin-media-tracker'),
            'direct' => __('مستقیم', 'elin-media-tracker'),
        ];
        ?>
        <p class="description"><?php esc_html_e('فایل‌های قرنطینه از مسیر اصلی برداشته شده‌اند. اگر کسی آن‌ها را بخواهد، افزونه فایل را تحویل می‌دهد و درخواست را ثبت می‌کند. چیزی که چند هفته درخواستی نداشته، حذفش امن است. درخواست از «صفحه‌های همین سایت» یعنی جایی هنوز به فایل لینک می‌دهد که اسکن پیدا نکرده: آن را برگردانید.', 'elin-media-tracker'); ?></p>
        <p class="mtrk-probe notice inline" data-url="<?php echo esc_url(Cleanup::probe_url()); ?>" aria-live="polite"><?php esc_html_e('در حال بررسی اینکه درخواست فایل‌های قرنطینه به وردپرس می‌رسد…', 'elin-media-tracker'); ?></p>
        <?php if (!$rows) : ?>
            <p><?php esc_html_e('چیزی در قرنطینه نیست.', 'elin-media-tracker'); ?></p>
            <?php return; ?>
        <?php endif; ?>
        <div class="mtrk-bulk" data-scope="quarantine">
            <label><input type="checkbox" class="mtrk-all"> <?php esc_html_e('انتخاب همه', 'elin-media-tracker'); ?></label>
            <span class="mtrk-count" aria-live="polite"></span>
            <button type="button" class="button button-primary" data-act="restore" disabled><?php esc_html_e('برگرداندن', 'elin-media-tracker'); ?></button>
            <button type="button" class="button mtrk-danger" data-act="delete" disabled><?php esc_html_e('حذف دائمی', 'elin-media-tracker'); ?></button>
        </div>
        <table class="widefat striped mtrk-qtable">
            <thead><tr>
                <td class="check-column"></td>
                <th scope="col"><?php esc_html_e('رسانه', 'elin-media-tracker'); ?></th>
                <th scope="col"><?php esc_html_e('در قرنطینه از', 'elin-media-tracker'); ?></th>
                <th scope="col"><?php esc_html_e('درخواست‌ها', 'elin-media-tracker'); ?></th>
                <th scope="col"><?php esc_html_e('آخرین درخواست', 'elin-media-tracker'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $id => $q) : ?>
                <?php
                $hits = (array) $q['hits'];
                $file = wp_basename((string) get_attached_file($id));
                ?>
                <tr class="<?php echo !empty($hits['site']) ? 'mtrk-warn' : ''; ?>">
                    <th scope="row" class="check-column"><input type="checkbox" class="mtrk-pick" value="<?php echo (int) $id; ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('انتخاب %s', 'elin-media-tracker'), $file)); ?>"></th>
                    <td class="mtrk-qmedia">
                        <button type="button" class="mtrk-thumb mtrk-preview" data-id="<?php echo (int) $id; ?>" aria-haspopup="dialog" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: file name */ __('پیش‌نمایش %s', 'elin-media-tracker'), $file)); ?>"><?php echo wp_get_attachment_image($id, [60, 60], true, ['alt' => '']); ?></button>
                        <span dir="auto"><?php echo esc_html($file); ?></span>
                    </td>
                    <td><?php echo esc_html(sprintf(/* translators: %s: time span */ __('%s پیش', 'elin-media-tracker'), human_time_diff((int) $q['time']))); ?></td>
                    <td>
                        <?php if (!$hits) : ?>
                            <?php esc_html_e('هیچ', 'elin-media-tracker'); ?>
                        <?php else : ?>
                            <?php foreach ($kinds as $kind => $label) : ?>
                                <?php if (!empty($hits[$kind])) : ?>
                                    <span class="mtrk-chip mtrk-chip-<?php echo esc_attr($kind); ?>"><?php echo esc_html($label . ': ' . number_format_i18n($hits[$kind])); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($q['last']) : ?>
                            <?php echo esc_html(sprintf(/* translators: %s: time span */ __('%s پیش', 'elin-media-tracker'), human_time_diff((int) $q['last']))); ?>
                            <?php if ($q['from']) : ?><br><bdi class="mtrk-from" dir="ltr"><?php echo esc_html(wp_parse_url($q['from'], PHP_URL_HOST) . wp_parse_url($q['from'], PHP_URL_PATH)); ?></bdi><?php endif; ?>
                        <?php else : ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ---- orphan files ----------------------------------------------------------------------------------------

    private static function tab_orphans(array $last): void
    {
        ?>
        <p class="description"><?php esc_html_e('فایل‌هایی روی دیسک که هیچ رسانه‌ای مالکشان نیست: سایزهای قدیمی یک تصویر که دیگر در اطلاعاتش ثبت نیستند (مثلاً بعد از ساخت دوباره‌ی بندانگشتی‌ها) و فایل‌هایی که هیچ‌وقت وارد کتابخانه نشده‌اند (آپلود با FTP، ایمپورت). فقط پوشه‌هایی خوانده می‌شوند که رسانه دارند؛ پوشه‌های افزونه‌ها (مثل فایل‌های فرم‌ها) دست نمی‌خورند.', 'elin-media-tracker'); ?></p>
        <?php if ((int) ($last['paths'] ?? 0) < 2) : ?>
            <p><?php esc_html_e('برای این بخش، اسکن را با این نسخه دوباره اجرا کنید.', 'elin-media-tracker'); ?> <a href="<?php echo esc_url(self::url('scan')); ?>"><?php esc_html_e('اسکن', 'elin-media-tracker'); ?></a></p>
            <?php return; ?>
        <?php endif; ?>
        <?php self::scope_notice($last); ?>
        <div id="mtrk-orphans" data-base="<?php echo esc_url(wp_upload_dir(null, false)['baseurl']); ?>">
            <p><button type="button" class="button mtrk-orphans-check"><?php esc_html_e('بررسی فایل‌های اضافه', 'elin-media-tracker'); ?></button></p>
            <p class="mtrk-orphans-status" aria-live="polite"></p>
            <div class="mtrk-orphans-result" hidden>
                <div class="mtrk-bulk">
                    <label><input type="checkbox" class="mtrk-orphans-all"> <?php esc_html_e('انتخاب همه‌ی موارد قابل حذف', 'elin-media-tracker'); ?></label>
                    <span class="mtrk-count" aria-live="polite"></span>
                    <button type="button" class="button mtrk-danger mtrk-orphans-delete" disabled><?php esc_html_e('حذف دائمی', 'elin-media-tracker'); ?></button>
                </div>
                <table class="widefat striped">
                    <thead><tr>
                        <td class="check-column"></td>
                        <th scope="col"><?php esc_html_e('فایل', 'elin-media-tracker'); ?></th>
                        <th scope="col"><?php esc_html_e('نوع', 'elin-media-tracker'); ?></th>
                        <th scope="col"><?php esc_html_e('حجم', 'elin-media-tracker'); ?></th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
                <p><button type="button" class="button mtrk-orphans-more" hidden></button></p>
            </div>
        </div>
        <?php
    }

    // ---- unregistered sizes ----------------------------------------------------------------------------------

    private static function tab_sizes(array $last): void
    {
        ?>
        <p class="description"><?php esc_html_e('فایل‌هایی که برای سایزهایی ساخته شده‌اند که دیگر هیچ قالب یا افزونه‌ای ثبتشان نکرده (مثلاً سایزهای قالب قبلی). وردپرس دیگر آن‌ها را تحویل نمی‌دهد، ولی تا وقتی تصویر استفاده می‌شود روی دیسک می‌مانند. فایلی که آدرس دقیقش جایی آمده، یا با یک سایز فعال یکی است، نگه داشته می‌شود.', 'elin-media-tracker'); ?></p>
        <?php if (empty($last['paths'])) : ?>
            <p><?php esc_html_e('برای این بخش، اسکن را با این نسخه دوباره اجرا کنید.', 'elin-media-tracker'); ?> <a href="<?php echo esc_url(self::url('scan')); ?>"><?php esc_html_e('اسکن', 'elin-media-tracker'); ?></a></p>
            <?php return; ?>
        <?php endif; ?>
        <?php self::scope_notice($last); ?>
        <div id="mtrk-sizes">
            <p><button type="button" class="button mtrk-sizes-check"><?php esc_html_e('بررسی سایزها', 'elin-media-tracker'); ?></button></p>
            <p class="mtrk-sizes-status" aria-live="polite"></p>
            <div class="mtrk-sizes-result" hidden>
                <table class="widefat striped mtrk-sizes-table">
                    <thead><tr>
                        <td class="check-column"><input type="checkbox" class="mtrk-sizes-all" aria-label="<?php esc_attr_e('انتخاب همه', 'elin-media-tracker'); ?>"></td>
                        <th scope="col"><?php esc_html_e('سایز', 'elin-media-tracker'); ?></th>
                        <th scope="col"><?php esc_html_e('ابعاد', 'elin-media-tracker'); ?></th>
                        <th scope="col"><?php esc_html_e('فایل قابل حذف', 'elin-media-tracker'); ?></th>
                        <th scope="col"><?php esc_html_e('حجم', 'elin-media-tracker'); ?></th>
                        <th scope="col"><?php esc_html_e('نگه‌داشته‌شده', 'elin-media-tracker'); ?></th>
                    </tr></thead>
                    <tbody></tbody>
                </table>
                <p class="description"><?php esc_html_e('حذف برگشت‌پذیر نیست؛ قبل از آن بکاپ بگیرید. نسخه‌های WebP/AVIF همان فایل‌ها هم حذف می‌شوند.', 'elin-media-tracker'); ?></p>
                <p><button type="button" class="button button-primary mtrk-sizes-clean" disabled><?php esc_html_e('حذف فایل‌های سایزهای انتخاب‌شده', 'elin-media-tracker'); ?></button></p>
            </div>
        </div>
        <?php
    }

    // ---- scan ------------------------------------------------------------------------------------------------

    private static function tab_scan(?array $last): void
    {
        $job = Scanner::job();
        if ($last) {
            $summary = Scanner::summary();
            $library = admin_url('upload.php?mode=list&mtrk_usage=');
            ?>
            <p>
                <?php
                printf(
                    /* translators: 1: date, 2: scope, 3: duration */
                    esc_html__('آخرین اسکن: %1$s — %2$s — مدت: %3$s', 'elin-media-tracker'),
                    esc_html(wp_date(get_option('date_format') . ' H:i', $last['time'])),
                    esc_html(self::scope($last)),
                    esc_html(human_time_diff(0, max(1, $last['secs'])))
                );
                ?>
            </p>
            <div class="mtrk-cards">
                <a class="mtrk-card mtrk-card-unused" href="<?php echo esc_url(self::url('unused')); ?>"><strong><?php echo esc_html(number_format_i18n($summary['unused'])); ?></strong><?php esc_html_e('بدون ارجاع', 'elin-media-tracker'); ?></a>
                <a class="mtrk-card" href="<?php echo esc_url($library . 'used'); ?>"><strong><?php echo esc_html(number_format_i18n($summary['used'])); ?></strong><?php esc_html_e('استفاده‌شده', 'elin-media-tracker'); ?></a>
                <a class="mtrk-card" href="<?php echo esc_url($library . 'new'); ?>"><strong><?php echo esc_html(number_format_i18n($summary['new'])); ?></strong><?php esc_html_e('جدید، بدون ارجاع', 'elin-media-tracker'); ?></a>
            </div>
            <p class="description"><?php esc_html_e('بعد از اسکن، تغییرات نوشته‌ها، متاها، دسته‌ها، کاربران و تنظیمات زنده ثبت می‌شود؛ جدول‌های افزونه‌ها و فایل‌های کد فقط با اسکن کامل به‌روز می‌شوند.', 'elin-media-tracker'); ?></p>
            <?php
        } elseif (!$job) {
            echo '<p>' . esc_html__('هنوز اسکنی انجام نشده است.', 'elin-media-tracker') . '</p>';
        }
        ?>
        <h2><?php esc_html_e('اسکن جدید', 'elin-media-tracker'); ?></h2>
        <form id="mtrk-form" <?php echo $job ? 'hidden' : ''; ?>>
            <fieldset>
                <legend class="screen-reader-text"><?php esc_html_e('محدوده‌ی اسکن', 'elin-media-tracker'); ?></legend>
                <p>
                    <label><input type="radio" name="code" value="0"> <strong><?php esc_html_e('فقط دیتابیس', 'elin-media-tracker'); ?></strong></label><br>
                    <span class="description"><?php esc_html_e('سریع. نوشته‌ها و برگه‌ها، متاها (المنتور، ووکامرس، ACF)، تنظیمات و ابزارک‌ها، دسته‌ها، کاربران و دیدگاه‌ها.', 'elin-media-tracker'); ?></span>
                </p>
                <p>
                    <label><input type="radio" name="code" value="1" checked> <strong><?php esc_html_e('دیتابیس + سورس کد', 'elin-media-tracker'); ?></strong></label><br>
                    <span class="description"><?php esc_html_e('به‌علاوه‌ی فایل‌های قالب فعال، افزونه‌های فعال، mu-plugins و فایل‌های CSS/JS داخل پوشه‌ی uploads (مثل CSS ساخته‌شده‌ی المنتور). قبل از هر حذفی این گزینه را اجرا کنید.', 'elin-media-tracker'); ?></span>
                </p>
                <p>
                    <label><input type="checkbox" name="tables" value="1" checked> <?php esc_html_e('جدول‌های اختصاصی افزونه‌ها هم بررسی شوند', 'elin-media-tracker'); ?></label><br>
                    <span class="description"><?php esc_html_e('مثل اسلایدرها و فرم‌سازها. جدول‌های لاگ، سشن و کش کنار گذاشته می‌شوند.', 'elin-media-tracker'); ?></span>
                </p>
            </fieldset>
            <p class="description"><?php esc_html_e('نتیجه‌ی اسکن قبلی پاک می‌شود. اگر وسط اسکن صفحه را ببندید، دفعه‌ی بعد از همان‌جا ادامه پیدا می‌کند.', 'elin-media-tracker'); ?></p>
            <?php submit_button(__('شروع اسکن', 'elin-media-tracker'), 'primary', 'submit', false); ?>
        </form>
        <div id="mtrk-progress" <?php echo $job ? '' : 'hidden'; ?> data-running="<?php echo esc_attr(wp_json_encode($job ? Scanner::progress($job) : null)); ?>">
            <progress max="100" value="0"></progress>
            <p class="mtrk-label" aria-live="polite"></p>
            <button type="button" class="button mtrk-cancel"><?php esc_html_e('توقف اسکن', 'elin-media-tracker'); ?></button>
            <button type="button" class="button button-primary mtrk-retry" hidden><?php esc_html_e('ادامه', 'elin-media-tracker'); ?></button>
        </div>
        <?php
    }

    private static function scope_notice(array $last): void
    {
        if (!$last['code']) {
            printf(
                '<div class="notice notice-warning inline"><p>%s <a href="%s">%s</a></p></div>',
                esc_html__('آخرین اسکن «فقط دیتابیس» بود؛ آدرس‌هایی که مستقیم در کد قالب یا افزونه نوشته شده‌اند بررسی نشده‌اند. قبل از حذف، اسکن «دیتابیس + سورس کد» را اجرا کنید.', 'elin-media-tracker'),
                esc_url(self::url('scan')),
                esc_html__('اسکن', 'elin-media-tracker')
            );
        }
    }

    // ---- preview ---------------------------------------------------------------------------------------------

    private static function preview_html(int $id): string
    {
        $file = (string) get_attached_file($id);
        $meta = wp_get_attachment_metadata($id);
        $mime = (string) get_post_mime_type($id);
        $url = (string) wp_get_attachment_url($id);
        $quarantined = (bool) get_post_meta($id, Cleanup::META, true);
        $kept = (bool) get_post_meta($id, Cleanup::KEEP, true);
        $count = Scanner::counts([$id])[$id] ?? 0;
        $parent = (int) wp_get_post_parent_id($id);
        $twins = array_diff(Cleanup::group_ids($id), [$id]);

        ob_start();
        ?>
        <div class="mtrk-preview-layout">
        <div class="mtrk-preview-media">
            <?php if (strpos($mime, 'image/') === 0) : ?>
                <?php echo wp_get_attachment_image($id, 'large', false, ['alt' => '']); ?>
            <?php elseif (strpos($mime, 'video/') === 0) : ?>
                <video src="<?php echo esc_url($url); ?>" controls preload="metadata"></video>
            <?php elseif (strpos($mime, 'audio/') === 0) : ?>
                <audio src="<?php echo esc_url($url); ?>" controls preload="metadata"></audio>
            <?php else : ?>
                <?php echo wp_get_attachment_image($id, 'thumbnail', true, ['alt' => '']); ?>
            <?php endif; ?>
        </div>
        <div class="mtrk-preview-info">
        <dl class="mtrk-details">
            <dt><?php esc_html_e('وضعیت', 'elin-media-tracker'); ?></dt>
            <dd><?php echo Admin::badge($id, $count, false); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
            <dt><?php esc_html_e('فایل', 'elin-media-tracker'); ?></dt>
            <dd><bdi dir="ltr"><?php echo esc_html((string) get_post_meta($id, '_wp_attached_file', true)); ?></bdi></dd>
            <dt><?php esc_html_e('حجم روی دیسک', 'elin-media-tracker'); ?></dt>
            <dd><?php echo esc_html(size_format(Cleanup::bytes($id, $meta)) ?: '—'); ?><?php echo !empty($meta['sizes']) ? esc_html(sprintf(/* translators: %s: number of sizes */ __(' (با %s سایز)', 'elin-media-tracker'), number_format_i18n(count($meta['sizes'])))) : ''; ?></dd>
            <?php if (!empty($meta['width'])) : ?>
                <dt><?php esc_html_e('ابعاد', 'elin-media-tracker'); ?></dt>
                <dd><bdi dir="ltr"><?php echo (int) $meta['width'] . '×' . (int) $meta['height']; ?></bdi></dd>
            <?php endif; ?>
            <dt><?php esc_html_e('آپلود', 'elin-media-tracker'); ?></dt>
            <dd><?php echo esc_html(get_the_date('', $id)); ?></dd>
            <?php if ($parent) : ?>
                <dt><?php esc_html_e('آپلودشده در', 'elin-media-tracker'); ?></dt>
                <dd><a href="<?php echo esc_url((string) get_edit_post_link($parent, 'raw')); ?>"><?php echo esc_html(get_the_title($parent) ?: "#$parent"); ?></a> <span class="mtrk-note"><?php esc_html_e('(فقط محل آپلود است، نه استفاده)', 'elin-media-tracker'); ?></span></dd>
            <?php endif; ?>
            <?php if ($twins) : ?>
                <dt><?php esc_html_e('فایل مشترک', 'elin-media-tracker'); ?></dt>
                <dd><?php echo esc_html(sprintf(/* translators: %s: attachment ids */ __('با پیوست %s؛ با هم جابه‌جا یا حذف می‌شوند.', 'elin-media-tracker'), '#' . implode('، #', $twins))); ?></dd>
            <?php endif; ?>
        </dl>
        <div class="mtrk-actions">
            <?php if ($quarantined) : ?>
                <button type="button" class="button button-primary" data-act="restore" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('برگرداندن', 'elin-media-tracker'); ?></button>
                <button type="button" class="button mtrk-danger" data-act="delete" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('حذف دائمی', 'elin-media-tracker'); ?></button>
            <?php elseif ($kept) : ?>
                <button type="button" class="button" data-act="unkeep" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('برداشتن علامت نگه‌داشتن', 'elin-media-tracker'); ?></button>
            <?php elseif (!$count && Scanner::last()) : ?>
                <button type="button" class="button button-primary" data-act="quarantine" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('قرنطینه', 'elin-media-tracker'); ?></button>
                <button type="button" class="button" data-act="keep" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('نگه‌داشتن', 'elin-media-tracker'); ?></button>
                <button type="button" class="button mtrk-danger" data-act="delete" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('حذف دائمی', 'elin-media-tracker'); ?></button>
            <?php else : ?>
                <button type="button" class="button mtrk-open" data-id="<?php echo (int) $id; ?>"><?php esc_html_e('محل‌های استفاده', 'elin-media-tracker'); ?></button>
            <?php endif; ?>
            <a class="button-link" href="<?php echo esc_url((string) get_edit_post_link($id, 'raw')); ?>"><?php esc_html_e('ویرایش رسانه', 'elin-media-tracker'); ?></a>
        </div>
        </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // ---- ajax ------------------------------------------------------------------------------------------------

    public static function ajax_scan(): void
    {
        Admin::guard();
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
        if ($do === 'start') {
            $started = Scanner::start(!empty($_POST['code']), !empty($_POST['tables']));
            isset($started['error']) ? wp_send_json_error($started['error'], 500) : wp_send_json_success($started);
        }
        if ($do === 'step') {
            wp_send_json_success(Scanner::run_step());
        }
        if ($do === 'cancel') {
            Scanner::cancel();
            wp_send_json_success();
        }
        wp_send_json_error(__('درخواست نامعتبر است.', 'elin-media-tracker'), 400);
    }

    public static function ajax_sizes(): void
    {
        Admin::guard();
        if (empty(Scanner::last()['paths'])) {
            wp_send_json_error(__('اول یک اسکن کامل اجرا کنید.', 'elin-media-tracker'), 409);
        }
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
        $names = array_map('sanitize_text_field', (array) wp_unslash($_POST['names'] ?? []));
        if ($do === 'clean' && !$names) {
            wp_send_json_error(__('هیچ سایزی انتخاب نشده است.', 'elin-media-tracker'), 400);
        }
        if ($do === 'clean' && !Scanner::trusted()) {
            wp_send_json_error(self::suspect_text(), 409);
        }
        wp_send_json_success(Sizes::step(absint($_POST['cursor'] ?? 0), $names, $do === 'clean'));
    }

    public static function ajax_orphans(): void
    {
        Admin::guard();
        if ((int) (Scanner::last()['paths'] ?? 0) < 2) {
            wp_send_json_error(__('اسکن را با این نسخه دوباره اجرا کنید.', 'elin-media-tracker'), 409);
        }
        if (sanitize_key(wp_unslash($_POST['do'] ?? '')) === 'delete') {
            if (!Scanner::trusted()) {
                wp_send_json_error(self::suspect_text(), 409);
            }
            // Paths are only looked up: Orphans::delete() deletes nothing its own fresh listing doesn't show.
            $paths = array_map(fn($p) => (string) $p, (array) wp_unslash($_POST['paths'] ?? []));
            wp_send_json_success(Orphans::delete($paths));
        }
        wp_send_json_success(Orphans::step(absint($_POST['cursor'] ?? 0)));
    }

    public static function ajax_dups(): void
    {
        Admin::guard();
        wp_send_json_success(Duplicates::step());
    }

    public static function ajax_act(): void
    {
        Admin::guard();
        $act = sanitize_key(wp_unslash($_POST['act'] ?? ''));
        $ids = array_filter(array_map('absint', (array) ($_POST['ids'] ?? [])));
        if (!$ids) {
            wp_send_json_error(__('چیزی انتخاب نشده است.', 'elin-media-tracker'), 400);
        }
        if (in_array($act, ['quarantine', 'delete'], true) && !Scanner::trusted()) {
            wp_send_json_error(self::suspect_text(), 409);
        }
        $bytes = 0;
        switch ($act) {
            case 'quarantine':
                $done = Cleanup::quarantine($ids);
                break;
            case 'restore':
                $done = Cleanup::restore($ids);
                break;
            case 'delete':
                $out = Cleanup::delete($ids);
                $done = $out['ids'];
                $bytes = $out['bytes'];
                break;
            case 'keep':
            case 'unkeep':
                $done = Cleanup::keep($ids, $act === 'keep');
                break;
            default:
                wp_send_json_error(__('درخواست نامعتبر است.', 'elin-media-tracker'), 400);
        }
        wp_send_json_success(['done' => $done, 'bytes' => $bytes]);
    }

    public static function ajax_preview(): void
    {
        Admin::guard();
        $id = absint($_POST['id'] ?? 0);
        if (get_post_type($id) !== 'attachment') {
            wp_send_json_error(__('رسانه پیدا نشد.', 'elin-media-tracker'), 404);
        }
        wp_send_json_success(['html' => self::preview_html($id), 'title' => get_the_title($id) ?: wp_basename((string) get_attached_file($id))]);
    }
}
