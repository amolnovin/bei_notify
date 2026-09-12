<?php
/**
 * تشخیص شبکهٔ «اعلان‌رسان بله، ایتا، تلگرام و واتساپ» — اسکریپت مستقل (CLI)
 * =============================================================
 * بدون نیاز به وردپرس: مستقیماً روی سرور سایت اجرا کنید تا مشخص شود
 * هر مقصد در کدام مرحله می‌میرد: DNS ← اتصال TCP ← TLS ← پاسخ HTTP.
 *
 * اجرا:
 *   php bei-network-diagnose.php
 *   php bei-network-diagnose.php https://relay-1.example.com https://relay-2.example.com
 *
 * خروجی هر خط:
 *   ✔ پاسخ 404 در 45ms (DNS 2ms + اتصال 20ms + TLS 23ms)   ← سالم
 *   ❌ DNS: ...                                             ← مشکل DNS سرور
 *   ❌ اتصال برقرار نشد ...                                 ← فیلترینگ IP / فایروال هاست
 *   ❌ اتصال TCP برقرار شد ولی TLS نرسید ...                 ← فیلترینگ SNI (مثل *.workers.dev)
 *   ❌ اتصال برقرار شد ولی پاسخ HTTP نرسید ...                ← مقصد آویزان/میان‌بر
 *
 * @package Bale_Eitaa_Notifier
 */

error_reporting( E_ALL & ~E_DEPRECATED );

// مقصدهای پیش‌فرض (در صورت نیاز ویرایش کنید).
$targets = array(
	'تلگرام مستقیم'          => 'https://api.telegram.org/',
	'بله مستقیم'             => 'https://tapi.bale.ai/',
	'ایتا مستقیم'            => 'https://eitaayar.ir/',
	'واتساپ (CallMeBot) مستقیم' => 'https://api.callmebot.com/',
);

// آدرس‌های رله که از خط فرمان داده می‌شوند.
$extra = array_slice( $argv, 1 );
foreach ( $extra as $i => $url ) {
	$targets[ 'رله ' . ( $i + 1 ) ] = $url;
}

if ( ! function_exists( 'curl_init' ) ) {
	fwrite( STDERR, "✘ افزونهٔ cURL در PHP این سرور فعال نیست — نمی‌توان تشخیص دقیق گرفت.\n" );
	exit( 2 );
}

$timeout = 8; // ثانیه برای هر مقصد

echo "تشخیص شبکهٔ سرور — " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
echo str_repeat( '-', 78 ) . "\n";

$fail_count = 0;

foreach ( $targets as $label => $url ) {
	$url = trim( $url );
	if ( '' === $url ) {
		continue;
	}
	if ( false === strpos( $url, '://' ) ) {
		$url = 'https://' . $url;
	}

	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $timeout );

	curl_exec( $ch );
	$errno = curl_errno( $ch );
	$error = curl_error( $ch );
	$info  = curl_getinfo( $ch );
	curl_close( $ch );

	$dns_time   = isset( $info['namelookup_time'] ) ? (float) $info['namelookup_time'] : 0.0;
	$conn_time  = isset( $info['connect_time'] ) ? (float) $info['connect_time'] : 0.0;
	$appconnect = isset( $info['appconnect_time'] ) ? (float) $info['appconnect_time'] : 0.0;

	$dns_ms     = (int) round( $dns_time * 1000 );
	$connect_ms = (int) round( $conn_time * 1000 );
	$tls_ms     = (int) round( max( 0, ( $appconnect - $conn_time ) ) * 1000 );
	$total_ms   = (int) round( $info['total_time'] * 1000 );

	$line = '';

	if ( 0 === $errno ) {
		$code = (int) $info['http_code'];
		$line = "✔ پاسخ $code در {$total_ms}ms (DNS {$dns_ms}ms + اتصال {$connect_ms}ms + TLS {$tls_ms}ms)";
	} else {
		$fail_count++;
		$connected = isset( $info['connect_time'] ) && $info['connect_time'] > 0;
		$tls_done  = isset( $info['appconnect_time'] ) && $info['appconnect_time'] > 0;

		switch ( $errno ) {
			case 6:
				$line = "❌ DNS: نام دامنه به IP تبدیل نشد — تنظیمات DNS سرور/هاست را بررسی کنید. [$error]";
				break;
			case 7:
				$line = "❌ اتصال برقرار نشد ({$total_ms}ms) — IP مقصد از این سرور در دسترس نیست: فیلترینگ IP یا فایروال هاست/سرور. [$error]";
				break;
			case 35:
				$line = "❌ خطای TLS (اتصال TCP در {$connect_ms}ms برقرار شد) — فیلترینگ SNI یا مشکل گواهی. [$error]";
				break;
			case 52:
				$line = "❌ پاسخ خالی از سرور (اتصال برقرار شد ولی هیچ پاسخی نیامد) — مقصد بی‌پاسخ/میان‌بر. [$error]";
				break;
			case 28:
				if ( $connected && ! $tls_done ) {
					$line = "❌ اتصال TCP در {$connect_ms}ms برقرار شد ولی TLS/پاسخ هرگز نرسید ({$total_ms}ms) — فیلترینگ SNI (نام دامنه در شبکهٔ ملی مسدود است).";
				} elseif ( ! $connected ) {
					$line = "❌ اتصال برقرار نشد ({$total_ms}ms) — فیلترینگ IP یا فایروال هاست/سرور.";
				} else {
					$line = "❌ اتصال و TLS برقرار شد ولی پاسخ HTTP نرسید ({$total_ms}ms) — مقصد آویزان است. [$error]";
				}
				break;
			default:
				$line = "❌ خطای شبکه ({$total_ms}ms) [$errno: $error]";
		}

		// راهنمای اختصاصی workers.dev
		$host = parse_url( $url, PHP_URL_HOST );
		if ( false !== stripos( (string) $host, 'workers.dev' ) ) {
			$line .= "\n     💡 دامنهٔ *.workers.dev در ایران در لایهٔ SNI فیلتر می‌شود — مجازکردن در پنل هاست اثری ندارد. روی این ورکر یک «Custom Domain» (زیردامنهٔ دامنهٔ خودتان) در Cloudflare بسازید.";
		}
	}

	echo "$label → $line\n";
}

echo str_repeat( '-', 78 ) . "\n";
if ( 0 === $fail_count ) {
	echo "نتیجه: همهٔ مقصدها از این سرور در دسترس هستند ✔\n";
} else {
	echo "نتیجه: $fail_count مقصد در دسترس نیست — راه‌حل، «رله روی دامنهٔ اختصاصی» یا «پراکسی» است.\n";
}

exit( 0 === $fail_count ? 0 : 1 );
