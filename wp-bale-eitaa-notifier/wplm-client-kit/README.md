# WPLM Client Kit

کیت آماده برای قرار دادن داخل افزونه‌های قابل فروش و اتصال به افزونه `wp-plugin-market-manager` نصب‌شده روی سایت `https://amolnovin.ir`. مدیریت مرکزی لایسنس‌ها توسط افزونه جداگانه `wplm-amolnovin-license-manager` انجام می‌شود.

## شامل

- `includes/wplm-license-client.php` — قفل لایسنس، لایسنس منیجر آمل نوین، فرم فعال‌سازی، بررسی دوره‌ای لایسنس
- `includes/wplm-plugin-updater.php` — نمایش بروزرسانی در بخش افزونه‌های وردپرس
- `example-plugin-main.php` — نمونه کد فایل اصلی افزونه مشتری
- `docs/README-FA.md` — آموزش کامل فارسی
- `docs/SECURITY.md` — نکات امنیتی

## استفاده سریع

پوشه `wplm-client-kit` را داخل افزونه مشتری قرار دهید و مطابق `docs/README-FA.md` در فایل اصلی افزونه لود کنید.

## API آماده برای آمل نوین

```text
License API Base: https://amolnovin.ir/wp-json/wplm/v1
Update API URL:  https://amolnovin.ir/wp-json/wplm/v1/updates/check
```

## لایسنس منیجر مرکزی

بعد از قرار دادن کیت در افزونه‌های قابل فروش، در مسیر زیر یک صفحه مرکزی ساخته می‌شود:

```text
تنظیمات ← لایسنس منیجر آمل نوین
```

این صفحه همه افزونه‌های فعال متصل به WPLM Client Kit را در یک جدول نمایش می‌دهد و امکان ثبت، بررسی و حذف لایسنس هر افزونه را فراهم می‌کند.

## افزونه جداگانه لایسنس منیجر

از نسخه جدید، صفحه مرکزی «لایسنس منیجر آمل نوین» داخل خود Client Kit ساخته نمی‌شود و باید افزونه جداگانه زیر نصب و فعال باشد:

```text
wplm-amolnovin-license-manager
```

اگر این افزونه نصب/فعال نباشد، افزونه‌های استفاده‌کننده از Client Kit در پیشخوان وردپرس notice نصب/فعال‌سازی نمایش می‌دهند.


## نسخه کیت

```text
License Client: 1.3.1
Updater: 1.0.3
```

## نکته مهم برای افزونه‌های دیگر

برای افزونه‌های لایسنس‌دار، هر دو فایل را در افزونه قرار دهید:

```php
require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-license-client.php';
require_once plugin_dir_path(__FILE__) . 'wplm-client-kit/includes/wplm-plugin-updater.php';
```

برای افزونه‌های بدون لایسنس، فقط updater کافی است و باید این گزینه را قرار دهید:

```php
'license_required' => false,
```

مدیریت مرکزی لایسنس‌ها با افزونه جداگانه `wplm-amolnovin-license-manager` انجام می‌شود. اگر نصب نباشد، notice نصب/فعال‌سازی نمایش داده می‌شود.
