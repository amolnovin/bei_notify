# 🚀 اعلان‌رسان بله، ایتا، تلگرام و واتساپ (BEI Notifier)

افزونه وردپرس برای ارسال خودکار پیام به پیام‌رسان‌های **بله، ایتا، تلگرام و واتساپ** — با پل ایمیل سراسری، اتصال آماده به فرم‌ها و ووکامرس، API خارجی و پشتیبانی پراکسی/رله برای دور زدن فیلترینگ.

بر اساس مستندات رسمی:
[core.telegram.org](https://core.telegram.org/bots/api) · [docs.bale.ai](https://docs.bale.ai) · [eitaayar.ir/api](https://eitaayar.ir/api/) · [callmebot.com](https://www.callmebot.com/blog/free-api-whatsapp-messages/)

---

## ✨ امکانات

- **۴ کانال ارسال:** بله، ایتا، تلگرام، واتساپ (مسیرهای رایگان: CallMeBot و شماره تست متا)
- **پل ایمیل سراسری:** هر ایمیلی که هر افزونه‌ای بفرستد، همزمان به پیام‌رسان می‌رود (مثل سرویس پیامک)
- **اتصال آماده با یک سوییچ:** Contact Form 7، WPForms، Gravity Forms، Ninja Forms، Fluent Forms، فرم‌های المنتور و ووکامرس
- **ماژول وضعیت‌های ووکامرس:** دو تب «پیام‌های مدیر» و «پیام‌های مشتریان» — برای هر وضعیت، پیام و روش ارسال مستقل با متغیرها (`{order_id}`، `{status_name}`، `{total}` و...)
- **اعلان به مشتری (روش ۱۰۰٪ پاسخگو):** دکمه ایجکس «دریافت شناسه» در صفحه تسویه حساب + شورت‌کد `[bei_subscribe]` + deep link امن
- **شناسه‌یاب:** خواندن شناسه عددی گفتگو با ارسال تست، getUpdates و ربات پاسخگوی «شناسه شما» (بله و تلگرام)
- **پراکسی/رله برای دور زدن فیلترینگ:** کارت پراکسی (HTTP/SOCKS5)، آدرس API سفارشی، رله Worker چندسایتی تلگرام و CallMeBot
- **API عمومی برای سایر افزونه‌ها:** هوک `bei_send` + توابع میان‌بُر `bei_notify()` + REST API
- **پنل مدرن:** منوی اصلی پیشخوان، کارت‌ها، سوییچ‌های toggle، تست اتصال، ابزارهای عیب‌یابی

## 🔑 لایسنس و بروزرسانی خودکار

- فعال‌سازی از افزونه جداگانه «لایسنس منیجر آمل نوین» (لینک «لایسنس» کنار افزونه → صفحه منیجر مرکزی)
- بروزرسانی‌ها مستقیم در «افزونه‌ها / بروزرسانی‌ها» وردپرس نمایش داده می‌شوند (سرور فروش: amolnovin.ir)
- `product_slug` محصول: `wp-bale-eitaa-notifier` (قابل تغییر با ثابت `BEI_PRODUCT_SLUG` در wp-config.php) — در `includes/class-bei-license.php`
- سایت مالک: `define( 'BEI_LICENSE_BYPASS', true )` در wp-config.php

## 📦 نصب

1. فایل `wp-bale-eitaa-notifier.zip` را از «افزونه‌ها ← افزودن ← بارگذاری افزونه» نصب کنید
2. از منوی اصلی پیشخوان **«اعلان‌رسان پیام‌رسان‌ها»** توکن و شناسه گفتگوی هر پیام‌رسان را وارد کنید
3. با دکمه‌های «تست اتصال» بررسی کنید

## 🔧 استفاده از سایر افزونه‌ها

```php
// هوک استاندارد (بدون وابستگی):
do_action( 'bei_send', '📢 پیام شما', array( 'bale', 'eitaa' ) );

// یا تابع مستقیم:
if ( function_exists( 'bei_notify' ) ) {
    bei_notify( 'سفارش جدید رسید', array( 'telegram' ) );
}
```

نمونه‌های آماده برای ۲۰+ افزونه معروف در `wp-bale-eitaa-notifier/examples/integration-examples.php`

## 🔗 اتصال سایر افزونه‌ها (API خارجی)

افزونه‌های دیگر می‌توانند بدون هیچ وابستگی کدی، از طریق REST پیام بفرستند:

```bash
curl -X POST "https://your-site.com/wp-json/bei/v1/notify"   -u "USERNAME:APP_PASSWORD"   -H "Content-Type: application/json"   -d '{"text":"سلام","targets":["telegram"],"source":"my-plugin"}'
```

- راهنمای کامل اتصال: **[api-consumers-guide.md](api-consumers-guide.md)**
- زیرمنوی «سایر افزونه‌ها» در پنل، فهرست مصرف‌کنندگان را با نام/تعداد/زمان/IP نشان می‌دهد

## 🌐 رله چندسایتی تلگرام (Cloudflare Worker)

فایل `telegram-relay-worker.js` یک رله چندسایتی است: هر تعداد سایت با ربات‌های مختلف می‌توانند همزمان از یک رله استفاده کنند. دو دربان اختیاری دارد:

| دربان | توضیح |
|---|---|
| `ALLOWED_BOTS` | لیست سفید پیشوند توکن ربات‌ها — خالی = همه ربات‌ها |
| `RELAY_KEY` | کلید مشترک — همین کلید را در «کلید امنیتی رله» کارت تلگرام هر سایت وارد کنید |

## 📁 ساختار

```
├── wp-bale-eitaa-notifier/          ← افزونه وردپرس
│   ├── wp-bale-eitaa-notifier.php   ← بوت‌استرپ + توابع میان‌بُر
│   ├── includes/                    ← ۱۲ کلاس ماژولار (OOP / Singleton)
│   ├── assets/                      ← استایل و اسکریپت پنل
│   └── examples/                    ← کتابخانه نمونه‌های اتصال
├── telegram-relay-worker.js         ← رله چندسایتی تلگرام (Cloudflare)
├── callmebot-relay-worker.js        ← رله CallMeBot (واتساپ)
├── test-relay.php                   ← تست رله از خط فرمان
├── test-send.php                    ← تست سریع توکن‌ها
├── settings-preview.html            ← پیش‌نمایش طراحی پنل
├── bale-eitaa-wp-guide.md           ← راهنمای کامل فارسی
└── api-consumers-guide.md           ← راهنمای اتصال سایر افزونه‌ها (REST)
```

## 🛡️ امنیت

- nonce و بررسی دسترسی در همه اکشن‌ها
- escaping خروجی‌ها و sanitize ورودی‌ها (WPCS)
- توکن یکبارمصرف و کلید محرمانه برای فعال‌سازی اعلان مشتری
- بدون تگ بسته PHP و محافظ ABSPATH در همه فایل‌ها

## 📄 لایسنس

GPL-2.0-or-later
