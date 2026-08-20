# 🔗 راهنمای اتصال سایر افزونه‌ها به BEI Notify (API خارجی)

> نسخه افزونه: **3.0.1** به بالا
> این راهنما مخصوص توسعه‌دهندگانی است که می‌خواهند از داخل افزونه/قالب/سیستم خودشان، پیام به بله، ایتا، تلگرام و واتساپ بفرستند — **بدون هیچ وابستگی کدی** به افزونه BEI Notify.

---

## ۱) پیش‌نیازها

### ۱.۱ افزونه BEI Notify فعال باشد

- منوی پیشخوان ← **«اعلان‌رسان پیام‌رسان‌ها»**
- حداقل یک پیام‌رسان **سوییچ فعال‌سازی روشن** داشته باشد (کارت هر پیام‌رسان ← سوییچ «فعال‌سازی»)
- توکن و شناسه گفتگوی همان پیام‌رسان وارد و با «تست اتصال» تأیید شده باشد

> ⚠️ اگر پیام‌رسانی غیرفعال باشد، درخواست به آن کانال با خطای ۴۰۰ پاسخ می‌گیرد.

### ۱.۲ Application Password (رمز برنامه)

احراز هویت API با «نام کاربری وردپرس + رمز برنامه» انجام می‌شود — نه رمز اصلی ورود:

1. پیشخوان ← **کاربران ← پروفایل خودتان**
2. به پایین صفحه بروید ← بخش **Application Passwords**
3. یک نام وارد کنید (مثلاً `my-plugin-notifier`) ← **Add New**
4. رمز تولیدشده را کپی کنید (فرمت: `xxxx xxxx xxxx xxxx xxxx xxxx` — فقط یک‌بار نمایش داده می‌شود)
5. در درخواست‌ها به‌صورت `USERNAME:APP_PASSWORD` استفاده می‌شود

> 🔐 امنیت: رمز برنامه را هرگز در کد فرانت‌اند (جاوااسکریپت مرورگر) قرار ندهید — فقط در PHP سمت سرور یا فایل‌های خارج از `wp-content` ذخیره کنید.

---

## ۲) مشخصات Endpoint

| مورد | مقدار |
|---|---|
| آدرس | `https://your-site.com/wp-json/bei/v1/notify` |
| روش | `POST` |
| نوع بدنه | `application/json` |
| احراز هویت | HTTP Basic (`نام‌کاربری:رمز‌برنامه`) |

### پارامترها

| پارامتر | نوع | الزام | توضیح |
|---|---|---|---|
| `text` | string | ✅ الزامی | متن پیام (فارسی و ایموجی پشتیبانی می‌شود) |
| `targets` | array | اختیاری | کانال‌های مقصد: `bale`، `eitaa`، `telegram`، `whatsapp` — خالی = همه کانال‌های فعال |
| `source` | string | اختیاری | نام افزونه شما — در زیرمنوی «سایر افزونه‌ها» ثبت می‌شود؛ بدون آن «ناشناس» ثبت می‌شود |

### پاسخ موفق

```json
{
  "ok": true,
  "sent": ["telegram", "bale"],
  "errors": {}
}
```

| فیلد | توضیح |
|---|---|
| `ok` | موفقیت کلی (حداقل یک کانال ارسال شد) |
| `sent` | کانال‌هایی که با موفقیت ارسال شدند |
| `errors` | خطای هر کانال به تفکیک (مثلاً `{"eitaa": "توکن تنظیم نشده"}`) |

### کدهای خطا

| کد | معنی | راه‌حل |
|---|---|---|
| `400` | متن خالی / کانال نامعتبر / همه کانال‌های درخواستی غیرفعال | متن بدهید؛ نام کانال را درست کنید؛ سوییچ کانال را در تنظیمات روشن کنید |
| `401` | احراز هویت ناموفق | نام کاربری/رمز برنامه را بررسی کنید |
| `403` | کاربر مجوز ندارد | کاربر باید نقش مدیر (`manage_options`) داشته باشد |
| `502` | هیچ کانالی ارسال نشد | پیام خطا در فیلد `errors` را ببینید (مثلاً توکن نامعتبر) |

---

## ۳) نمونه‌های آماده

### ۳.۱ با cURL (تست سریع)

```bash
curl -X POST "https://your-site.com/wp-json/bei/v1/notify" \
  -u "USERNAME:APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{
    "text": "📢 سلام از سیستم دیگر",
    "targets": ["telegram", "bale"],
    "source": "my-plugin"
  }'
```

پاسخ:

```json
{"ok": true, "sent": ["telegram", "bale"], "errors": {}}
```

### ۳.۲ با PHP — داخل وردپرس (پیشنهادی برای افزونه شما) ⭐

این تابع را در افزونه خودتان بگذارید و هر جا خواستید صدا بزنید:

```php
/**
 * ارسال پیام با استفاده از BEI Notify.
 *
 * @param string $text    متن پیام.
 * @param array  $targets کانال‌ها (خالی = همه فعال).
 * @param string $source  نام افزونه شما (برای فهرست «سایر افزونه‌ها»).
 * @return array|WP_Error نتیجه.
 */
function my_plugin_notify( $text, $targets = array(), $source = 'my-plugin' ) {
	$response = wp_remote_post(
		rest_url( 'bei/v1/notify' ),
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Basic ' . base64_encode( 'USERNAME:APP_PASSWORD' ),
			),
			'body' => wp_json_encode(
				array(
					'text'    => $text,
					'targets' => $targets,
					'source'  => $source,
				),
				JSON_UNESCAPED_UNICODE
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code < 200 || $code >= 300 ) {
		$message = isset( $data['errors'] ) ? wp_json_encode( $data['errors'], JSON_UNESCAPED_UNICODE ) : 'HTTP ' . $code;
		return new WP_Error( 'bei_api', 'BEI Notify: ' . $message );
	}

	return $data;
}
```

**نحوه استفاده:**

```php
// ارسال به یک کانال
$r = my_plugin_notify( 'سفارش جدید ثبت شد!', array( 'telegram' ) );

// ارسال به همه کانال‌های فعال
$r = my_plugin_notify( 'سلام از افزونه من' );

// بررسی نتیجه
if ( is_wp_error( $r ) ) {
	error_log( $r->get_error_message() );
} elseif ( ! empty( $r['errors'] ) ) {
	error_log( 'خطا در بعضی کانال‌ها: ' . wp_json_encode( $r['errors'] ) );
} else {
	// موفق — $r['sent'] لیست کانال‌های ارسال‌شده است.
}
```

### ۳.۳ با PHP خالص (خارج از وردپرس — اسکریپت مستقل)

```php
<?php
$url  = 'https://your-site.com/wp-json/bei/v1/notify';
$user = 'USERNAME';
$pass = 'APP_PASSWORD';

$payload = array(
	'text'    => 'سلام از اسکریپت مستقل',
	'targets' => array( 'telegram' ),
	'source'  => 'cron-script',
);

$ch = curl_init( $url );
curl_setopt_array( $ch, array(
	CURLOPT_POST           => true,
	CURLOPT_POSTFIELDS     => json_encode( $payload, JSON_UNESCAPED_UNICODE ),
	CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
	CURLOPT_USERPWD        => $user . ':' . $pass,
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_TIMEOUT        => 30,
) );
$response = curl_exec( $ch );
curl_close( $ch );

echo $response; // {"ok":true,"sent":["telegram"],"errors":{}}
```

### ۳.۴ نمونه کامل — اتصال یک افزونه شخصی‌سازی

مثال واقعی: وقتی افزونه رزرو شما یک رزرو جدید می‌گیرد:

```php
add_action( 'my_booking_created', function ( $booking_id ) {
	$booking = get_post( $booking_id );

	my_plugin_notify(
		"📅 رزرو جدید #{$booking_id} ثبت شد.\nعنوان: {$booking->post_title}",
		array( 'telegram', 'bale' ),
		'booking-plugin'
	);
} );
```

---

## ۴) جدول کانال‌های مجاز

| مقدار `targets` | پیام‌رسان | پیش‌نیاز |
|---|---|---|
| `bale` | بله | سوییچ فعال + توکن + chat_id |
| `eitaa` | ایتا | سوییچ فعال + توکن + chat_id |
| `telegram` | تلگرام | سوییچ فعال + توکن + chat_id |
| `whatsapp` | واتساپ | سوییچ فعال + درگاه (CallMeBot/متا/...) |

> اگر `targets` ارسال نشود یا خالی باشد، پیام به **همه کانال‌های فعال** می‌رود.

---

## ۵) زیرمنوی «سایر افزونه‌ها» — مشاهده مصرف‌کنندگان

بعد از اولین ارسال موفق، به منوی پیشخوان ← **«اعلان‌رسان پیام‌رسان‌ها» ← «سایر افزونه‌ها»** بروید:

| ستون | توضیح |
|---|---|
| نام افزونه | مقدار `source` ارسال‌شده (بدون source = «ناشناس») |
| تعداد ارسال | مجموع درخواست‌های آن افزونه |
| آخرین ارسال | زمان آخرین درخواست |
| IP | آدرس IP فراخوان |

- دکمه **«پاک‌سازی فهرست»** آمار را صفر می‌کند (تنظیمات ارسال دست نمی‌خورد)
- همه درخواست‌ها اینجا ثبت می‌شوند — حتی درخواست‌های ناموفق هم نامشان دیده می‌شود

---

## ۶) نکات مهم و عیب‌یابی

### ۶.۱ پیام فرستاده نمی‌شود؟

| علامت | علت احتمالی |
|---|---|
| `401` | رمز برنامه اشتباه/حذف‌شده — دوباره بسازید |
| `400` + «هیچ‌یک از پیام‌رسان‌ها فعال نیستند» | سوییچ کانال در تنظیمات خاموش است |
| `400` + «مقادیر مجاز» | نام کانال غلط است (فقط `bale/eitaa/telegram/whatsapp`) |
| `502` | کانال فعال است ولی ارسال خطا داد — `errors` را بخوانید (توکن نامعتبر، timeout و...) |
| `404` | پیوندهای یکتا خراب است → تنظیمات ← پیوندهای یکتا ← ذخیره دوباره |
| `cURL error` | افزونه BEI غیرفعال است یا مسیر REST توسط افزونه امنیتی بسته شده |

### ۶.۲ تفاوت «ارسال مستقیم» و «صف»

- فراخوانی از REST **مستقیم** انجام می‌شود (نتیجه همان لحظه در پاسخ است)
- اما هوک‌های داخلی افزونه (ووکامرس، فرم‌ها، پل ایمیل) از «صندوق ارسال» (Queue) می‌روند
- اگر افزونه شما هم می‌خواهد از صف استفاده کند، کافی است داخل وردپرس از هوک عمومی استفاده کند:

```php
// بدون REST — مستقیم از داخل وردپرس (با صف و Retry خودکار):
do_action( 'bei_send', '📢 پیام از افزونه شما', array( 'bale', 'telegram' ) );
```

### ۶.۳ محدودیت‌ها

- ارسال به واتساپ با درگاه رایگان CallMeBot فقط به شماره خودتان می‌رسد (محدودیت رسمی)
- پیام تلگرام/بله/ایتا با Markdown قالب‌بندی می‌شود — از کاراکترهای `*` و `_` بدون قصد استفاده نکنید
- تعداد درخواست تابع محدودیت‌های نرخ خود پیام‌رسان‌هاست

---

## ۷) چک‌لیست راه‌اندازی سریع

- [ ] افزونه BEI Notify فعال است
- [ ] حداقل یک پیام‌رسان سوییچ فعال + توکن + chat_id دارد و «تست اتصال» سبز است
- [ ] Application Password ساخته و یادداشت شده است
- [ ] درخواست cURL از بخش ۳.۱ موفق شد (`ok: true`)
- [ ] پارامتر `source` با نام افزونه شما تنظیم شده است
- [ ] در زیرمنوی «سایر افزونه‌ها» نام افزونه شما با تعداد ارسال ثبت شده است

---

## ۸) مستندات مرتبط

- راهنمای کامل افزونه: `bale-eitaa-wp-guide.md`
- نمونه‌های اتصال داخل وردپرس: `wp-bale-eitaa-notifier/examples/integration-examples.php`
- امنیت: `SECURITY.md`
- مستندات رسمی پیام‌رسان‌ها: [تلگرام](https://core.telegram.org/bots/api) · [بله](https://docs.bale.ai) · [ایتا](https://eitaayar.ir/api/) · [CallMeBot](https://www.callmebot.com/blog/free-api-whatsapp-messages/)
