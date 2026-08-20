# تنظیم اختصاصی برای amolnovin.ir

این فایل نمونه تنظیمات آماده برای افزونه‌هایی است که قرار است از طریق سامانه فروش و لایسنس آمل نوین فعال و بروزرسانی شوند.

## APIهای تاییدشده

```text
License API Base:
https://amolnovin.ir/wp-json/wplm/v1

Update API URL:
https://amolnovin.ir/wp-json/wplm/v1/updates/check
```

## کد آماده استفاده

در فایل اصلی افزونه مشتری مقدارهای `PRODUCT-SLUG-IN-AMOLNOVIN` و `YOUR-PLUGIN-FOLDER` را تغییر دهید.

```php
define('MY_PLUGIN_VERSION', '1.0.0');

require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-license-client.php';
require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-plugin-updater.php';

$license = new WPLM_Client_Kit_License([
    'api_base'       => 'https://amolnovin.ir/wp-json/wplm/v1',
    'product_slug'   => 'PRODUCT-SLUG-IN-AMOLNOVIN',
    'plugin_file'    => __FILE__,
    'plugin_name'    => 'نام افزونه شما',
    'license_option' => 'your_plugin_license_key',
    'status_option'  => 'your_plugin_license_status',
    'data_option'    => 'your_plugin_license_data',
]);

new WPLM_Client_Kit_Updater([
    'api_url'        => 'https://amolnovin.ir/wp-json/wplm/v1/updates/check',
    'plugin_file'    => __FILE__,
    'plugin_slug'    => 'YOUR-PLUGIN-FOLDER',
    'product_slug'   => 'PRODUCT-SLUG-IN-AMOLNOVIN',
    'version'        => MY_PLUGIN_VERSION,
    'license_option' => 'your_plugin_license_key',
    'name'           => 'نام افزونه شما',
    'author'         => 'آمل نوین',
]);

add_action('plugins_loaded', function () use ($license) {
    if (!$license->is_active()) {
        return;
    }

    // اجرای امکانات اصلی افزونه شما
});
```

## نکات مهم

- آدرس‌ها باید حتماً با `https://` باشند.
- مقدار `product_slug` باید دقیقاً برابر شناسه محصول ثبت‌شده در پنل `wp-plugin-market-manager` در سایت amolnovin.ir باشد.
- مقدار `plugin_slug` معمولاً نام پوشه افزونه است.
- در محیط واقعی گزینه‌های `allow_insecure` و `require_ssl => false` را استفاده نکنید.
