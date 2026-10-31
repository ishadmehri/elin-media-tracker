# Roadmap / لیست توسعه

Ideas and open work, not promises. Move an item to the changelog in `readme.txt` when it ships.
ایده‌ها و کارهای باز؛ تعهد نیستند. هر کدام که منتشر شد، به changelog در `readme.txt` بروید.

## Planned / برنامه‌ریزی‌شده

- **Ignore list for leftovers of removed plugins.** A box on the Scan tab where you list tables and options to skip (for example `wp_revslider_slides`), so they stop counting as usage. Today only known caches and logs (Yoast indexables, Titan scanner, Rank Math analysis, Simple History, …) are skipped.
  فهرست نادیده‌گرفتن برای مانده‌ی افزونه‌های حذف‌شده: کادری در تب اسکن برای جدول‌ها و گزینه‌هایی که شمرده نشوند (مثل RevSlider).
- **Multisite.** Uninstall cleanup only handles a single site; make it loop over the network, and test the scan per site.
  پشتیبانی از multisite: پاک‌سازی هنگام حذف افزونه برای همه‌ی سایت‌های شبکه، و تست اسکن هر سایت.

## For the WordPress.org directory / برای مخزن وردپرس

- Remake the banner without the WordPress logo (the guidelines forbid implying official affiliation). / ساخت دوباره‌ی بنر بدون لوگوی وردپرس.
- Submit the ZIP at https://wordpress.org/plugins/developers/add/ and, once approved, upload `.wordpress-org/` to the SVN `assets` folder. / ثبت zip در مخزن و بعد از تأیید، بارگذاری `.wordpress-org/` در پوشه‌ی assets.
- Translations: the bundled Persian file is a fallback until translate.wordpress.org has one. / ترجمه‌ی فارسی در translate.wordpress.org.

## Testing / تست

- Run the suites on PHP 7.4 and 8.0 to 8.4 (only 8.2 so far). / اجرای تست‌ها روی PHP 7.4 و 8.0 تا 8.4 (فعلاً فقط 8.2).
- Automated UI tests (today the interface is checked by hand in a browser). / تست خودکار رابط (الان دستی در مرورگر چک می‌شود).

## Ideas, not decided / ایده‌های تصمیم‌نگرفته

- A button on the Duplicates tab to point every reference at one copy, so duplicates can be deleted when all copies are in use. / دکمه‌ی انتقال ارجاع‌ها به یک نسخه در تب تکراری‌ها.
- Pick "all but one" copy in each duplicate group automatically, and a `wp elin-media-tracker duplicates` command. / انتخاب خودکار «همه به‌جز یکی» در هر گروه تکراری و دستور WP-CLI برای تکراری‌ها.
- Warn before selecting every copy of a duplicate group. / هشدار قبل از انتخاب همه‌ی نسخه‌های یک گروه تکراری.
