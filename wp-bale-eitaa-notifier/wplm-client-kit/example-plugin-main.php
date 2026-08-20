<?php
/**
 * Plugin Name: نمونه افزونه قابل فروش
 * Description: نمونه اتصال افزونه مشتری به wp-plugin-market-manager برای قفل لایسنس و بروزرسانی خودکار.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MY_SELLABLE_PLUGIN_VERSION', '1.0.0');

require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-license-client.php';
require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-plugin-updater.php';

$my_plugin_license = new WPLM_Client_Kit_License([
    'api_base'       => 'https://amolnovin.ir/wp-json/wplm/v1',
    'product_slug'   => 'your-product-slug',
    'plugin_file'    => __FILE__,
    'plugin_name'    => 'نمونه افزونه قابل فروش',
    'license_option' => 'my_plugin_license_key',
    'status_option'  => 'my_plugin_license_status',
    'manager_required' => true,
    'data_option'    => 'my_plugin_license_data',
    'menu_title'     => 'لایسنس افزونه من',
]);

new WPLM_Client_Kit_Updater([
    'api_url'        => 'https://amolnovin.ir/wp-json/wplm/v1/updates/check',
    'plugin_file'    => __FILE__,
    'plugin_slug'    => 'your-plugin-folder',
    'product_slug'   => 'your-product-slug',
    'version'        => MY_SELLABLE_PLUGIN_VERSION,
    'license_option' => 'my_plugin_license_key',
    'name'           => 'نمونه افزونه قابل فروش',
    'author'         => 'نام شما',
]);

add_action('plugins_loaded', function () use ($my_plugin_license) {
    if (!$my_plugin_license->is_active()) {
        // افزونه قفل است؛ فقط صفحه فعال‌سازی لایسنس و اعلان مدیریت نمایش داده می‌شود.
        return;
    }

    // اینجا امکانات اصلی افزونه خود را اجرا کنید.
    // My_Plugin_Core::boot();
});
