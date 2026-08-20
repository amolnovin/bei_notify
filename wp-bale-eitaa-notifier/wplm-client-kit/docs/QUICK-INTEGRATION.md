# راهنمای سریع اتصال یک افزونه جدید

## 1. کپی پوشه کیت

پوشه `wplm-client-kit` را داخل افزونه خود قرار دهید:

```text
your-plugin/
├── your-plugin.php
└── wplm-client-kit/
```

## 2. کد برای افزونه لایسنس‌دار

```php
define('YOUR_PLUGIN_VERSION', '1.0.0');

require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-license-client.php';
require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-plugin-updater.php';

$license = new WPLM_Client_Kit_License([
    'api_base'       => 'https://amolnovin.ir/wp-json/wplm/v1',
    'product_slug'   => 'your-product-slug',
    'plugin_file'    => __FILE__,
    'plugin_name'    => 'نام افزونه شما',
    'license_option' => 'your_plugin_license_key',
    'status_option'  => 'your_plugin_license_status',
    'data_option'    => 'your_plugin_license_data',
]);

new WPLM_Client_Kit_Updater([
    'api_url'        => 'https://amolnovin.ir/wp-json/wplm/v1/updates/check',
    'plugin_file'    => __FILE__,
    'plugin_slug'    => 'your-plugin-folder',
    'product_slug'   => 'your-product-slug',
    'version'        => YOUR_PLUGIN_VERSION,
    'license_option' => 'your_plugin_license_key',
]);

add_action('plugins_loaded', function () use ($license) {
    if (!$license->is_active()) {
        return;
    }
    // اجرای امکانات اصلی افزونه
});
```

## 3. کد برای افزونه بدون لایسنس

```php
define('YOUR_PLUGIN_VERSION', '1.0.0');
require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-plugin-updater.php';

new WPLM_Client_Kit_Updater([
    'api_url'          => 'https://amolnovin.ir/wp-json/wplm/v1/updates/check',
    'plugin_file'      => __FILE__,
    'plugin_slug'      => 'your-plugin-folder',
    'product_slug'     => 'your-product-slug',
    'version'          => YOUR_PLUGIN_VERSION,
    'license_required' => false,
]);
```
