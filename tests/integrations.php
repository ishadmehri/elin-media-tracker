<?php
/**
 * Integration check against the real plugins on the dev site: Elementor (+ Pro loop item, dynamic tags, V4 atomic),
 * ACF or SCF (Pro-only field types run when available), Gravity Forms, Fluent Forms, WPForms, Contact Form 7.
 * Content is created through each plugin's own API, so what gets scanned is the plugin's real storage format.
 * Missing plugins are skipped. It truncates previous scan results.
 *
 * Run: wp eval-file wp-content/plugins/media-tracker/tests/integrations.php
 */

defined('ABSPATH') || exit;

require __DIR__ . '/helpers.php';

global $wpdb;

$fail = 0;
$made = [];
$expect = [];
$cleanup = [];
$cdn = 'https://cdn.example.com/x.jpg';
$media = function (string $name, bool $used, string $what, string $dir = '2026/10') use (&$made, &$expect): int {
    $id = mtrk_fixture_media("mtrk-$name.jpg", $made, $dir);
    $expect[$id] = [$used, $what];
    return $id;
};
$post = function (array $args) use (&$made): int {
    $id = wp_insert_post($args + ['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'mtrk fixture']);
    $made[] = $id;
    return $id;
};
$url = fn(int $id): string => (string) wp_get_attachment_url($id);
$is = function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
    $fail += $ok ? 0 : 1;
};
$active = (array) get_option('active_plugins', []);

try {
    // ---- ACF / SCF -------------------------------------------------------------------------------------------
    if (function_exists('acf_import_field_group')) {
        $pro = (bool) acf_get_field_type('repeater'); // ACF free has no repeater/gallery/flexible/options/blocks; SCF has them
        echo (in_array('secure-custom-fields/secure-custom-fields.php', $active, true) ? 'SCF ' : 'ACF ') . ACF_VERSION . ($pro ? ' (pro field types)' : ' (free field types)') . "\n";

        $fields = [
            ['key' => 'field_mtrk_img', 'name' => 'mtrk_img', 'label' => 'img', 'type' => 'image', 'return_format' => 'url'],
            ['key' => 'field_mtrk_file', 'name' => 'mtrk_file', 'label' => 'file', 'type' => 'file'],
            ['key' => 'field_mtrk_num', 'name' => 'mtrk_num', 'label' => 'num', 'type' => 'number'],
            ['key' => 'field_mtrk_wys', 'name' => 'mtrk_wys', 'label' => 'wys', 'type' => 'wysiwyg'],
            ['key' => 'field_mtrk_grp', 'name' => 'mtrk_grp', 'label' => 'grp', 'type' => 'group', 'sub_fields' => [
                ['key' => 'field_mtrk_grp_img', 'name' => 'img', 'label' => 'img', 'type' => 'image'],
            ]],
        ];
        if ($pro) {
            $fields[] = ['key' => 'field_mtrk_gal', 'name' => 'mtrk_gal', 'label' => 'gal', 'type' => 'gallery'];
            $fields[] = ['key' => 'field_mtrk_rep', 'name' => 'mtrk_rep', 'label' => 'rep', 'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_mtrk_rep_img', 'name' => 'img', 'label' => 'img', 'type' => 'image'],
            ]];
            $fields[] = ['key' => 'field_mtrk_flex', 'name' => 'mtrk_flex', 'label' => 'flex', 'type' => 'flexible_content', 'layouts' => [
                'layout_mtrk' => ['key' => 'layout_mtrk', 'name' => 'hero', 'label' => 'hero', 'display' => 'block', 'sub_fields' => [
                    ['key' => 'field_mtrk_flex_img', 'name' => 'img', 'label' => 'img', 'type' => 'image'],
                ]],
            ]];
        }
        $group = acf_import_field_group([
            'key' => 'group_mtrk',
            'title' => 'mtrk',
            'fields' => $fields,
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
        ]);
        $cleanup[] = fn() => acf_delete_field_group($group['ID']);

        $p = $post(['post_title' => 'mtrk acf post']);
        update_field('field_mtrk_img', $media('acf-img', true, 'ACF image field'), $p);
        update_field('field_mtrk_file', $media('acf-file', true, 'ACF file field'), $p);
        update_field('field_mtrk_num', $media('acf-num', false, 'ACF number field holding the same number'), $p);
        update_field('field_mtrk_grp', ['field_mtrk_grp_img' => $media('acf-grp', true, 'ACF group sub field')], $p);
        update_field('field_mtrk_wys', '<p><img src="' . $url($media('acf-wys', true, 'ACF wysiwyg <img>')) . '"></p>', $p);

        $term = wp_insert_term('mtrk acf ' . uniqid(), 'category');
        $cleanup[] = fn() => wp_delete_term($term['term_id'], 'category');
        update_field('field_mtrk_img', $media('acf-term', true, 'ACF field on a term'), 'term_' . $term['term_id']);

        $uid = get_current_user_id();
        update_field('field_mtrk_img', $media('acf-user', true, 'ACF field on a user'), "user_$uid");
        $cleanup[] = fn() => delete_field('field_mtrk_img', "user_$uid");

        if ($pro) {
            update_field('field_mtrk_gal', [$media('acf-gal1', true, 'ACF gallery, 1st'), $media('acf-gal2', true, 'ACF gallery, 2nd')], $p);
            update_field('field_mtrk_rep', [['field_mtrk_rep_img' => $media('acf-rep', true, 'ACF repeater row')]], $p);
            update_field('field_mtrk_flex', [['acf_fc_layout' => 'hero', 'field_mtrk_flex_img' => $media('acf-flex', true, 'ACF flexible content layout')]], $p);
            update_field('field_mtrk_img', $media('acf-opt', true, 'ACF options page'), 'option');
            $cleanup[] = fn() => delete_field('field_mtrk_img', 'option');
            // ACF blocks keep their values in the block comment, next to the field keys.
            $block = ['name' => 'acf/mtrk-hero', 'data' => ['img' => $media('acf-block', true, 'ACF block in post content'), '_img' => 'field_mtrk_img'], 'mode' => 'preview'];
            $post(['post_content' => '<!-- wp:acf/mtrk-hero ' . wp_json_encode($block, JSON_UNESCAPED_SLASHES) . ' /-->']);
        }
    } else {
        echo "SKIP ACF/SCF (not active)\n";
    }

    // ---- Elementor Pro: loop item + loop grid page -----------------------------------------------------------
    if (class_exists('\ElementorPro\Plugin')) {
        $el = \Elementor\Plugin::$instance;
        $static = $media('el-static', true, 'Elementor image widget in a loop item');
        $bg = $media('el-bg', true, 'Elementor container background in a loop item');
        $fallback = $media('el-fallback', true, 'Elementor dynamic ACF tag fallback (URL-encoded, CDN url)');
        $atomic = $media('el-atomic', true, 'Elementor V4 atomic image (id only)');
        $tag = $el->dynamic_tags->tag_data_to_tag_text('d1n4m1c', 'acf-image', ['key' => 'field_mtrk_img:mtrk_img', 'fallback' => ['url' => $cdn, 'id' => $fallback]]);

        $loop = $el->documents->create('loop-item', ['post_title' => 'mtrk loop item', 'post_status' => 'publish']);
        $made[] = $loop->get_main_id();
        $loop->save(['elements' => [[
            'id' => 'c0nt41n',
            'elType' => 'container',
            'settings' => ['background_background' => 'classic', 'background_image' => ['url' => $url($bg), 'id' => $bg]],
            'elements' => [
                ['id' => 'w1dg3t1', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => ['image' => ['url' => $url($static), 'id' => $static]], 'elements' => []],
                ['id' => 'w1dg3t2', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => ['__dynamic__' => ['image' => $tag]], 'elements' => []],
            ],
        ]]]);

        $page = $el->documents->create('wp-page', ['post_title' => 'mtrk loop page', 'post_status' => 'publish']);
        $made[] = $page->get_main_id();
        $page->save(['elements' => [[
            'id' => 'c0nt41m',
            'elType' => 'container',
            'settings' => [],
            'elements' => [
                ['id' => 'l00pgr1', 'elType' => 'widget', 'widgetType' => 'loop-grid', 'settings' => ['template_id' => $loop->get_main_id(), 'posts_post_type' => 'post'], 'elements' => []],
                ['id' => '4t0m1c1', 'elType' => 'widget', 'widgetType' => 'e-image', 'version' => '0.0', 'styles' => [], 'settings' => ['image' => [
                    '$$type' => 'image',
                    'value' => [
                        'src' => ['$$type' => 'image-src', 'value' => ['id' => ['$$type' => 'image-attachment-id', 'value' => $atomic], 'url' => null]],
                        'size' => ['$$type' => 'string', 'value' => 'full'],
                    ],
                ]], 'elements' => []],
            ],
        ]]]);
        $is($tag !== '' && strpos((string) get_post_meta($loop->get_main_id(), '_elementor_data', true), 'acf-image') !== false, 'fixture: dynamic tag saved in loop item');
        $is(strpos((string) get_post_meta($page->get_main_id(), '_elementor_data', true), 'image-attachment-id') !== false, 'fixture: atomic image saved');
    } else {
        echo "SKIP Elementor Pro (not active)\n";
    }

    // ---- Gravity Forms ---------------------------------------------------------------------------------------
    if (class_exists('GFAPI')) {
        $html = $media('gf-html', true, 'Gravity Forms HTML field <img>');
        $choice = $media('gf-choice', true, 'Gravity Forms image choice (attachment_id, CDN url)');
        $form_id = GFAPI::add_form(['title' => 'mtrk ' . uniqid(), 'fields' => [
            ['type' => 'html', 'id' => 1, 'label' => 'html', 'content' => '<img src="' . $url($html) . '">'],
            ['type' => 'image_choice', 'id' => 2, 'label' => 'pick', 'choices' => [['text' => 'A', 'value' => 'a', 'attachment_id' => $choice, 'file_url' => $cdn]]],
        ]]);
        $is(!is_wp_error($form_id), 'fixture: Gravity Forms form saved');
        $cleanup[] = fn() => is_wp_error($form_id) || GFAPI::delete_form($form_id);
    } else {
        echo "SKIP Gravity Forms (not active)\n";
    }

    // ---- Fluent Forms ----------------------------------------------------------------------------------------
    $ff_table = $wpdb->prefix . 'fluentform_forms';
    if (in_array('fluentform/fluentform.php', $active, true)) {
        $ff = $media('ff-html', true, 'Fluent Forms custom HTML');
        $now = current_time('mysql');
        $wpdb->insert($ff_table, [
            'title' => 'mtrk',
            'status' => 'published',
            'type' => 'form',
            'form_fields' => wp_json_encode(['fields' => [['index' => 0, 'element' => 'custom_html', 'attributes' => [], 'settings' => ['html_codes' => '<p><img src="' . $url($ff) . '"></p>']]]]),
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $ff_id = $wpdb->insert_id;
        $cleanup[] = fn() => $wpdb->delete($ff_table, ['id' => $ff_id]);
    } else {
        echo "SKIP Fluent Forms (not active)\n";
    }

    // ---- WPForms ---------------------------------------------------------------------------------------------
    if (function_exists('wpforms')) {
        $desc = $media('wpf-desc', true, 'WPForms form description <img>');
        $pick = $media('wpf-choice', true, 'WPForms image choice');
        $forms = wpforms()->obj('form');
        $wpf = $forms->add('mtrk form');
        $made[] = $wpf;
        $forms->update($wpf, [
            'id' => $wpf,
            'field_id' => 2,
            'settings' => ['form_title' => 'mtrk form', 'form_desc' => '<img src="' . $url($desc) . '">'],
            'fields' => [1 => ['id' => 1, 'type' => 'radio', 'label' => 'pick', 'choices_images' => '1', 'choices' => [1 => ['label' => 'A', 'value' => '', 'image' => $url($pick)]]]],
        ]);
    } else {
        echo "SKIP WPForms (not active)\n";
    }

    // ---- WooCommerce downloadable products -------------------------------------------------------------------
    if (class_exists('WC_Product_Simple')) {
        $protected = 'woocommerce_uploads/2026/10'; // where the product "Downloadable files" uploader puts files
        $simple = new WC_Product_Simple();
        $simple->set_name('mtrk downloadable');
        $simple->set_downloadable(true);
        $simple->set_virtual(true);
        $file1 = $url($media('wc-protected-a1b2c3', true, 'Woo download in woocommerce_uploads', $protected));
        $file2 = $url($media('wc-library', true, 'Woo download from the regular media library'));
        // Woo also takes paths instead of URLs. Server paths only count as absolute when they start with "/", which
        // a Windows dev box can't give, so use the site-relative form; both contain wp-content/uploads/.
        $abs = $media('wc-path', true, 'Woo download given as a path, not a URL', $protected);
        // Saving downloads as an admin auto-approves their folders; remove only the rules this run adds.
        $rules = $wpdb->prefix . 'wc_product_download_directories';
        $last_rule = (int) $wpdb->get_var("SELECT MAX(url_id) FROM $rules");
        $cleanup[] = fn() => $wpdb->query($wpdb->prepare("DELETE FROM $rules WHERE url_id > %d", $last_rule));
        $downloads = [];
        $path = '/' . ltrim(str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path((string) get_attached_file($abs))), '/');
        foreach ([$file1, $file2, $path] as $i => $file) {
            $d = new WC_Product_Download();
            $d->set_id(wp_generate_uuid4());
            $d->set_name("file $i");
            $d->set_file($file);
            $downloads[] = $d;
        }
        $simple->set_downloads($downloads);
        $made[] = $simple->save();

        $variable = new WC_Product_Variable();
        $variable->set_name('mtrk variable downloadable');
        $made[] = $variable->save();
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($variable->get_id());
        $variation->set_downloadable(true);
        $d = new WC_Product_Download();
        $d->set_id(wp_generate_uuid4());
        $d->set_name('variation file');
        $d->set_file($url($media('wc-variation', true, 'Woo download on a variation', $protected)));
        $variation->set_downloads([$d]);
        $made[] = $variation->save();

        $media('wc-orphan', false, 'file in woocommerce_uploads, not attached to a product', $protected);
        foreach ([$simple->get_id(), $variation->get_id()] as $pid) {
            $is(count(wc_get_product($pid)->get_downloads()) > 0, "fixture: downloads saved on product #$pid");
        }
    } else {
        echo "SKIP WooCommerce (not active)\n";
    }

    // ---- Meta Box (fields registered in code) -----------------------------------------------------------------
    if (function_exists('rwmb_get_registry')) {
        $box = rwmb_get_registry('meta_box')->make(['id' => 'mtrkfix_mb', 'post_types' => ['post'], 'fields' => [
            ['id' => 'mtrk_mb_single', 'type' => 'single_image'],
            ['id' => 'mtrk_mb_gallery', 'type' => 'image_advanced'],
            ['id' => 'mtrk_mb_num', 'type' => 'number'],
            ['id' => 'mtrk_mb_group', 'type' => 'group', 'clone' => true, 'fields' => [['id' => 'img', 'type' => 'single_image'], ['id' => 'num', 'type' => 'number']]],
        ]]);
        $box->register_fields();
        $mb = $post(['post_title' => 'mtrk meta box']);
        rwmb_set_meta($mb, 'mtrk_mb_single', $media('mb-single', true, 'Meta Box single image'));
        rwmb_set_meta($mb, 'mtrk_mb_gallery', [$media('mb-gal1', true, 'Meta Box image_advanced, 1st'), $media('mb-gal2', true, 'Meta Box image_advanced, 2nd')]);
        rwmb_set_meta($mb, 'mtrk_mb_num', $media('mb-num', false, 'Meta Box number field with the same number'));
        rwmb_set_meta($mb, 'mtrk_mb_group', [['img' => $media('mb-group', true, 'Meta Box image inside a cloned group'), 'num' => $media('mb-gnum', false, 'Meta Box number inside the group')]]);
    } else {
        echo "SKIP Meta Box (not active)\n";
    }

    // ---- Pods ------------------------------------------------------------------------------------------------
    if (function_exists('pods_api')) {
        $api = pods_api();
        $api->save_pod(['name' => 'mtrkfix_book', 'type' => 'post_type', 'storage' => 'meta', 'label' => 'mtrk book']);
        $cleanup[] = fn() => $api->delete_pod(['name' => 'mtrkfix_book']);
        $api->save_field(['pod' => 'mtrkfix_book', 'name' => 'mtrk_cover', 'label' => 'cover', 'type' => 'file', 'file_format_type' => 'single']);
        $api->save_field(['pod' => 'mtrkfix_book', 'name' => 'mtrk_pages', 'label' => 'pages', 'type' => 'file', 'file_format_type' => 'multi']);
        $made[] = pods('mtrkfix_book')->add([
            'post_title' => 'mtrk book',
            'post_status' => 'publish',
            'mtrk_cover' => $media('pods-cover', true, 'Pods single file field'),
            'mtrk_pages' => [$media('pods-p1', true, 'Pods multi file field, 1st'), $media('pods-p2', true, 'Pods multi file field, 2nd')],
        ]);
    } else {
        echo "SKIP Pods (not active)\n";
    }

    // ---- JetEngine: premium, so its stored config (format as on a real site) stands in for the plugin -------
    $jet = get_option('jet_engine_meta_boxes', null);
    update_option('jet_engine_meta_boxes', ['meta-mtrk' => ['args' => ['object_type' => 'post', 'allowed_post_type' => ['post']], 'meta_fields' => [
        ['name' => 'mtrk_je_img', 'type' => 'media', 'value_format' => 'id'],
        ['name' => 'mtrk_je_both', 'type' => 'media', 'value_format' => 'both'],
        ['name' => 'mtrk_je_gal', 'type' => 'gallery', 'value_format' => 'id'],
        ['name' => 'mtrk_je_num', 'type' => 'number'],
        ['name' => 'mtrk_je_rep', 'type' => 'repeater', 'repeater-fields' => [['name' => 'pic', 'type' => 'media'], ['name' => 'label', 'type' => 'text']]],
    ]]], false);
    $cleanup[] = fn() => $jet === null ? delete_option('jet_engine_meta_boxes') : update_option('jet_engine_meta_boxes', $jet, false);
    $je = $post(['post_title' => 'mtrk jetengine']);
    update_post_meta($je, 'mtrk_je_img', (string) $media('je-img', true, 'JetEngine media field (id)'));
    update_post_meta($je, 'mtrk_je_both', ['id' => $media('je-both', true, 'JetEngine media field (id + CDN url)'), 'url' => $cdn]);
    update_post_meta($je, 'mtrk_je_gal', $media('je-g1', true, 'JetEngine gallery "1,2", 1st') . ',' . $media('je-g2', true, 'JetEngine gallery "1,2", 2nd'));
    update_post_meta($je, 'mtrk_je_num', (string) $media('je-num', false, 'JetEngine number field with the same number'));
    update_post_meta($je, 'mtrk_je_rep', ['item-0' => ['pic' => (string) $media('je-rep', true, 'JetEngine media inside a repeater'), 'label' => '7']]);

    // JetEngine's own tables (schema as in its includes/core/db.php, 3.9): post types, options pages, CCTs.
    $jet_types = $wpdb->prefix . 'jet_post_types';
    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $jet_types))) {
        $wpdb->query("CREATE TABLE `$jet_types` (id bigint(20) NOT NULL AUTO_INCREMENT, slug text, status text, labels longtext, args longtext, meta_fields longtext, PRIMARY KEY (id))");
        $cleanup[] = fn() => $wpdb->query("DROP TABLE IF EXISTS `$jet_types`");
    }
    $jet_row = function (string $slug, string $status, array $args, array $fields) use ($wpdb, $jet_types, &$cleanup): void {
        $wpdb->insert($jet_types, ['slug' => $slug, 'status' => $status, 'labels' => serialize(['name' => $slug]), 'args' => serialize($args), 'meta_fields' => serialize($fields)]);
        $id = $wpdb->insert_id;
        $cleanup[] = fn() => $wpdb->delete($jet_types, ['id' => $id]);
    };
    $jet_row('mtrkfix_cpt', 'publish', [], [['name' => 'mtrk_je_cpt_img', 'type' => 'media', 'value_format' => 'id']]);
    update_post_meta($je, 'mtrk_je_cpt_img', (string) $media('je-cpt', true, 'JetEngine post type field (id)'));

    $jet_row('mtrkfix-settings', 'page', ['storage_type' => 'default', 'option_prefix' => true], [
        ['name' => 'logo', 'type' => 'media', 'value_format' => 'id'],
        ['name' => 'banners', 'type' => 'gallery', 'value_format' => 'id'],
        ['name' => 'slides', 'type' => 'repeater', 'repeater-fields' => [['name' => 'pic', 'type' => 'media'], ['name' => 'title', 'type' => 'text']]],
        ['name' => 'count', 'type' => 'number'],
    ]);
    update_option('mtrkfix-settings', [
        'logo' => (string) $media('je-opt-logo', true, 'JetEngine options page media (default storage)'),
        'banners' => $media('je-opt-b1', true, 'JetEngine options page gallery, 1st') . ',' . $media('je-opt-b2', true, 'JetEngine options page gallery, 2nd'),
        'slides' => ['item-0' => ['pic' => (string) $media('je-opt-rep', true, 'JetEngine options page repeater media'), 'title' => '5']],
        'count' => (string) $media('je-opt-num', false, 'JetEngine options page number with the same number'),
    ], false);
    $cleanup[] = fn() => delete_option('mtrkfix-settings');

    $jet_row('mtrkfix-sep', 'page', ['storage_type' => 'separate', 'option_prefix' => true], [['name' => 'hero', 'type' => 'media', 'value_format' => 'both']]);
    update_option('mtrkfix-sep_hero', ['id' => $media('je-sep', true, 'JetEngine options page, separate storage, id + CDN url'), 'url' => $cdn], false);
    $cleanup[] = fn() => delete_option('mtrkfix-sep_hero');

    $jet_row('mtrkfix_books', 'content-type', ['slug' => 'mtrkfix_books'], [
        ['name' => 'cover', 'type' => 'media', 'value_format' => 'id'],
        ['name' => 'photos', 'type' => 'gallery', 'value_format' => 'id'],
        ['name' => 'note', 'type' => 'text'],
    ]);
    $cct = $wpdb->prefix . 'jet_cct_mtrkfix_books';
    $wpdb->query("CREATE TABLE `$cct` (_ID bigint(20) NOT NULL AUTO_INCREMENT, cct_status text, cover text, photos longtext, note text, PRIMARY KEY (_ID))");
    $cleanup[] = fn() => $wpdb->query("DROP TABLE IF EXISTS `$cct`");
    $wpdb->insert($cct, [
        'cct_status' => 'publish',
        'cover' => (string) $media('je-cct', true, 'JetEngine CCT media column'),
        'photos' => $media('je-cct-g1', true, 'JetEngine CCT gallery column, 1st') . ',' . $media('je-cct-g2', true, 'JetEngine CCT gallery column, 2nd'),
        'note' => (string) $media('je-cct-num', false, 'JetEngine CCT text column with the same number'),
    ]);

    // ---- Smart Slider 3: stores uploads paths as $upload$/... in its own table --------------------------------
    $slides = $wpdb->prefix . 'nextend2_smartslider3_slides';
    if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $slides))) {
        $wpdb->query("CREATE TABLE `$slides` (id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, params longtext)");
        $cleanup[] = fn() => $wpdb->query("DROP TABLE IF EXISTS `$slides`");
        $ss = $media('ss', true, 'Smart Slider $upload$ path');
        $wpdb->insert($slides, ['params' => wp_json_encode(['backgroundImage' => '$upload$' . '/2026/10/mtrk-ss.jpg'])]);
    }

    // ---- Contact Form 7 --------------------------------------------------------------------------------------
    if (class_exists('WPCF7_ContactForm')) {
        $c7 = $media('cf7', true, 'Contact Form 7 form markup <img>');
        $cf = WPCF7_ContactForm::get_template(['title' => 'mtrk cf7']);
        $cf->set_properties(['form' => '<img src="' . $url($c7) . '"> [submit "Send"]']);
        $made[] = $cf->save();
    } else {
        echo "SKIP Contact Form 7 (not active)\n";
    }

    $media('nowhere', false, 'nowhere');

    mtrk_fixture_scan();
    mtrk_fixture_assert($expect, $fail);
} finally {
    foreach (array_reverse($cleanup) as $fn) {
        $fn();
    }
    foreach (array_reverse($made) as $id) {
        wp_delete_post($id, true);
    }
}

$fail ? WP_CLI::error("$fail failed") : WP_CLI::success('all passed');
