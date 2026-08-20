# WPLM Client Kit

> این نسخه برای اتصال به سایت اصلی **amolnovin.ir** آماده‌سازی شده است. آدرس‌های API به صورت پیش‌فرض در نمونه‌ها روی `https://amolnovin.ir/wp-json/wplm/v1` تنظیم شده‌اند.

این پکیج برای افزونه‌هایی است که می‌خواهید از طریق افزونه فروش و لایسنس `wp-plugin-market-manager` بفروشید.

با قرار دادن این کیت داخل افزونه مشتری، دو قابلیت اضافه می‌شود:

1. **قفل لایسنس و فرم فعال‌سازی**
2. **بروزرسانی خودکار از بخش افزونه‌های وردپرس**

---

## ساختار فایل‌ها

```text
wplm-client-kit/
├── includes/
│   ├── wplm-license-client.php
│   └── wplm-plugin-updater.php
├── docs/
│   └── README-FA.md
└── example-plugin-main.php
```

---

## پیش‌نیاز سمت سایت فروش

روی سایت فروش باید افزونه `wp-plugin-market-manager` نصب و تنظیم شده باشد.

در سایت فروش:

1. محصول را ثبت کنید.
2. `slug` محصول را یادداشت کنید.
3. لایسنس برای مشتری ایجاد شود یا بعد از خرید خودکار ساخته شود.
4. برای بروزرسانی، در بخش «بروزرسانی‌ها» نسخه جدید و فایل ZIP را آپلود کنید.
5. نسخه را به عنوان نسخه فعلی انتخاب کنید.

---

## نصب کیت داخل افزونه مشتری

پوشه `wplm-client-kit` را داخل ریشه افزونه‌ای که می‌فروشید قرار دهید:

```text
my-plugin/
├── my-plugin.php
└── wplm-client-kit/
    └── includes/
```

سپس در فایل اصلی افزونه مشتری، کد زیر را اضافه کنید:

```php
define('MY_PLUGIN_VERSION', '1.0.0');

require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-license-client.php';
require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-plugin-updater.php';

$license = new WPLM_Client_Kit_License([
    'api_base'       => 'https://amolnovin.ir/wp-json/wplm/v1',
    'product_slug'   => 'my-plugin',
    'plugin_file'    => __FILE__,
    'plugin_name'    => 'نام افزونه من',
    'license_option' => 'my_plugin_license_key',
    'status_option'  => 'my_plugin_license_status',
    'data_option'    => 'my_plugin_license_data',
]);

new WPLM_Client_Kit_Updater([
    'api_url'        => 'https://amolnovin.ir/wp-json/wplm/v1/updates/check',
    'plugin_file'    => __FILE__,
    'plugin_slug'    => 'my-plugin',
    'product_slug'   => 'my-plugin',
    'version'        => MY_PLUGIN_VERSION,
    'license_option' => 'my_plugin_license_key',
    'name'           => 'نام افزونه من',
    'author'         => 'نام شما',
]);

add_action('plugins_loaded', function () use ($license) {
    if (!$license->is_active()) {
        return; // افزونه قفل است
    }

    // اجرای امکانات اصلی افزونه شما
    // My_Plugin_Core::boot();
});
```

---

## مقدارهای مهم

### api_base

آدرس API سایت فروش:

```text
https://amolnovin.ir/wp-json/wplm/v1
```

### api_url

آدرس API بروزرسانی:

```text
https://amolnovin.ir/wp-json/wplm/v1/updates/check
```

### product_slug

باید دقیقاً برابر slug محصول در `wp-plugin-market-manager` باشد.

### plugin_slug

نام پوشه افزونه در وردپرس. اگر افزونه شما در مسیر زیر است:

```text
wp-content/plugins/my-plugin/my-plugin.php
```

پس مقدار مناسب:

```php
'plugin_slug' => 'my-plugin'
```

### license_option

نام option که کلید لایسنس در سایت مشتری ذخیره می‌شود.

---

## صفحه فعال‌سازی لایسنس

پس از نصب افزونه مشتری، در پیشخوان وردپرس یک صفحه تنظیمات اضافه می‌شود:

```text
تنظیمات ← لایسنس افزونه
```

کاربر کلید لایسنس را وارد می‌کند و کیت، درخواست زیر را به سایت فروش می‌فرستد:

```text
POST /wp-json/wplm/v1/license/activate
```

---

## قفل افزونه

برای قفل کردن امکانات اصلی افزونه، اجرای بخش اصلی را پشت شرط زیر قرار دهید:

```php
if ($license->is_active()) {
    My_Plugin_Core::boot();
}
```

اگر لایسنس فعال نباشد:

- امکانات اصلی اجرا نمی‌شود.
- اعلان مدیریت نمایش داده می‌شود.
- صفحه فعال‌سازی لایسنس در دسترس می‌ماند.

---

## بروزرسانی خودکار در بخش افزونه‌های وردپرس

کلاس `WPLM_Client_Kit_Updater` به فیلترهای استاندارد وردپرس وصل می‌شود:

```text
pre_set_site_transient_update_plugins
plugins_api
```

بنابراین اگر در سایت فروش نسخه جدید ثبت شده باشد، بروزرسانی در مسیرهای زیر نمایش داده می‌شود:

```text
پیشخوان ← افزونه‌ها
پیشخوان ← بروزرسانی‌ها
```

---

## نکات امنیتی

- همه درخواست‌ها با `sslverify => true` ارسال می‌شوند.
- به‌صورت پیش‌فرض API باید HTTPS باشد.
- Nonce و capability برای ذخیره لایسنس بررسی می‌شود.
- لایسنس در option اختصاصی ذخیره می‌شود.
- وضعیت لایسنس cache می‌شود تا سایت مشتری کند نشود.
- در خطای موقت شبکه، اگر قبلاً لایسنس فعال بوده، وضعیت فعال به‌صورت کوتاه‌مدت حفظ می‌شود.
- هیچ فایل ZIP مستقیماً از افزونه مشتری دانلود نمی‌شود؛ لینک دانلود امن از سرور فروش دریافت می‌شود.

---

## حالت تست روی HTTP

برای محیط تست محلی می‌توانید این گزینه‌ها را موقتاً اضافه کنید:

```php
'allow_insecure' => true,
'require_ssl' => false,
```

در سایت واقعی این گزینه‌ها را فعال نکنید.

---

## عیب‌یابی

### آپدیت نمایش داده نمی‌شود

بررسی کنید:

- product_slug درست باشد.
- لایسنس فعال باشد.
- نسخه افزونه مشتری کمتر از نسخه ثبت‌شده در سایت فروش باشد.
- فایل ZIP نسخه جدید آپلود شده باشد.
- نسخه جدید در سایت فروش به عنوان نسخه فعلی انتخاب شده باشد.
- کش بروزرسانی وردپرس پاک شود.

برای پاک کردن کش، یک بار به مسیر زیر بروید:

```text
پیشخوان ← بروزرسانی‌ها
```

یا transientهای بروزرسانی را پاک کنید.

### لایسنس فعال نمی‌شود

بررسی کنید:

- دامنه در لایسنس مجاز باشد.
- سقف دامنه تکمیل نشده باشد.
- لایسنس منقضی یا مسدود نباشد.
- محصول در سایت فروش فعال باشد.

---

## چک‌لیست نهایی قبل از انتشار افزونه مشتری

- [ ] پوشه `wplm-client-kit` داخل افزونه قرار گرفته است.
- [ ] api_base و api_url روی دامنه واقعی سایت فروش تنظیم شده است.
- [ ] product_slug با محصول ثبت‌شده برابر است.
- [ ] نسخه افزونه با ثابت VERSION هماهنگ است.
- [ ] اجرای امکانات اصلی افزونه پشت شرط `$license->is_active()` قرار گرفته است.
- [ ] روی یک سایت تست، فعال‌سازی لایسنس تست شده است.
- [ ] بروزرسانی از صفحه افزونه‌های وردپرس تست شده است.

---

## رفع خطای «آدرس API امن نیست»

این پیام از فایل `wplm-license-client.php` و متد `api_is_allowed()` نمایش داده می‌شود. دلیل آن یکی از موارد زیر است:

1. مقدار `api_base` خالی است یا هنوز مقدار نمونه `amolnovin.ir` باقی مانده است.
2. آدرس با `http://` وارد شده است، در حالی که در محیط واقعی باید `https://` باشد.
3. ابتدای آدرس `https://` جا افتاده است. در نسخه 1.0.1 اگر schema جا افتاده باشد، کیت به‌صورت خودکار `https://` اضافه می‌کند.
4. آدرس API اشتباه است. مقدار درست:

```php
'api_base' => 'https://amolnovin.ir/wp-json/wplm/v1'
```

و برای updater:

```php
'api_url' => 'https://amolnovin.ir/wp-json/wplm/v1/updates/check'
```

در سایت واقعی این گزینه‌ها را فعال نکنید مگر برای تست محلی:

```php
'allow_insecure' => true,
'require_ssl' => false,
```

---

## لایسنس منیجر آمل نوین در تنظیمات وردپرس

از نسخه 1.1.0، کیت به‌صورت خودکار یک صفحه مرکزی در تنظیمات وردپرس ایجاد می‌کند:

```text
پیشخوان وردپرس ← تنظیمات ← لایسنس منیجر آمل نوین
```

در این صفحه، همه افزونه‌های فعالی که از `WPLM_Client_Kit_License` استفاده می‌کنند نمایش داده می‌شوند.

برای هر افزونه در جدول می‌توانید:

- نام افزونه را ببینید
- Product Slug را ببینید
- وضعیت لایسنس را ببینید
- کلید لایسنس را ثبت یا ویرایش کنید
- لایسنس را دوباره بررسی کنید
- لایسنس ذخیره‌شده روی سایت را حذف کنید
- آخرین پیام API و آخرین زمان بررسی را ببینید

### نکته مهم

برای اینکه افزونه شما در این جدول نمایش داده شود، کافی است در فایل اصلی افزونه این کلاس ساخته شود:

```php
$license = new WPLM_Client_Kit_License([
    'api_base'       => 'https://amolnovin.ir/wp-json/wplm/v1',
    'product_slug'   => 'PRODUCT-SLUG-IN-AMOLNOVIN',
    'plugin_file'    => __FILE__,
    'plugin_name'    => 'نام افزونه شما',
    'license_option' => 'your_plugin_license_key',
]);
```

به‌صورت پیش‌فرض دیگر برای هر افزونه یک صفحه جداگانه ساخته نمی‌شود و همه در صفحه مرکزی «لایسنس منیجر آمل نوین» مدیریت می‌شوند.

اگر برای یک افزونه خاص همچنان صفحه جداگانه هم می‌خواهید، این گزینه را اضافه کنید:

```php
'individual_page' => true,
```

برای غیرفعال کردن صفحه مرکزی، که توصیه نمی‌شود:

```php
'manager_page' => false,
```

### تغییرات رابط لایسنس منیجر در نسخه 1.2.0

در صفحه «لایسنس منیجر آمل نوین» جدول افزونه‌های متصل ساده‌تر و مدرن‌تر شد:

- ستون‌های API و Product Slug از جدول اصلی حذف شدند.
- دکمه «ذخیره/فعال‌سازی» به ستون عملیات منتقل شد.
- دکمه‌های عملیات به صورت آیکون نمایش داده می‌شوند.
- جدول در موبایل به کارت‌های ریسپانسیو تبدیل می‌شود.

برای محصولات «فعال بدون لایسنس» معمولاً نیازی به `WPLM_Client_Kit_License` نیست. برای آپدیت چنین محصولاتی فقط updater را با گزینه زیر استفاده کنید:

```php
'license_required' => false,
```

### اصلاح نسخه 1.0.2 در updater

در نسخه قبل، alias قدیمی `WPLM_Plugin_Updater` با `extends` ساخته شده بود، در حالی که کلاس اصلی `WPLM_Client_Kit_Updater` به صورت `final` تعریف شده است. این مورد در PHP باعث fatal error می‌شد:

```text
Class WPLM_Plugin_Updater cannot extend final class WPLM_Client_Kit_Updater
```

در نسخه 1.0.2 این مورد با `class_alias` اصلاح شد.


---

## تغییر مهم: لایسنس منیجر به افزونه جداگانه منتقل شد

از نسخه 1.3.0 فایل `wplm-license-client.php` دیگر خودش صفحه مرکزی «لایسنس منیجر آمل نوین» را نمی‌سازد. این صفحه باید توسط افزونه جداگانه زیر ایجاد شود:

```text
wplm-amolnovin-license-manager
```

اگر افزونه جداگانه نصب و فعال نباشد، افزونه ثانوی که از Client Kit استفاده می‌کند در پیشخوان وردپرس notice نمایش می‌دهد و لینک نصب/بارگذاری یا فعال‌سازی را نشان می‌دهد.

### گزینه‌های مرتبط

به صورت پیش‌فرض فعال هستند:

```php
'manager_page' => true,
'manager_required' => true,
```

اگر نمی‌خواهید برای یک افزونه خاص notice نصب لایسنس منیجر نمایش داده شود:

```php
'manager_required' => false,
```

اگر مسیر دانلود افزونه لایسنس منیجر را روی سایت خودتان قرار دادید:

```php
'manager_download_url' => 'https://amolnovin.ir/wp-content/plugins/wp-plugin-market-manager/client/wplm-amolnovin-license-manager.zip',
```
