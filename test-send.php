<?php
/**
 * اسکریپت تست سریع توکن‌های بله و ایتا (مستقل از وردپرس)
 *
 * روش استفاده:
 *   ۱) مقادیر زیر را پر کنید
 *   ۲) از خط فرمان:      php test-send.php
 *   ۳) یا از مرورگر:     https://your-domain.com/test-send.php
 *   ۴) بعد از تست موفق، این فایل را از سرور حذف کنید!
 *
 * منابع مستندات:
 *   بله : https://docs.bale.ai
 *   ایتا: https://eitaayar.ir/api/
 */

// ---------------------- تنظیمات خود را اینجا وارد کنید ----------------------
$BALE_TOKEN   = '';          // توکن ربات بله (از @botfather در بله)
$BALE_CHAT_ID = '';          // مثل @myChannel یا شناسه عددی

$EITAA_TOKEN   = '';         // توکن ربات ایتا (از پنل ایتایار یا @botfather در ایتا)
$EITAA_CHAT_ID = '';         // مثل myChannel (بدون @) یا شناسه عددی کانال
// -----------------------------------------------------------------------------

const BALE_API  = 'https://tapi.bale.ai/bot';   // مستندات رسمی بله
const EITAA_API = 'https://eitaayar.ir/api/';   // مستندات رسمی ایتایار

function http_request( $url, $method = 'GET', $data = null ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_CUSTOMREQUEST  => $method,
	) );
	if ( $data !== null ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, is_array( $data ) ? http_build_query( $data ) : $data );
	}
	$response = curl_exec( $ch );
	$error    = curl_error( $ch );
	curl_close( $ch );

	if ( $error ) {
		return array( 'transport_error' => $error );
	}
	return json_decode( $response, true ) ?: array( 'raw' => $response );
}

function show( $label, $result ) {
	echo "\n----- {$label} -----\n";
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
	if ( isset( $result['ok'] ) && $result['ok'] ) {
		echo "✅ موفق\n";
	} else {
		echo "❌ ناموفق\n";
	}
}

header( 'Content-Type: text/plain; charset=utf-8' );

echo "========== تست بله ==========\n";

if ( $BALE_TOKEN ) {
	// getMe — تست صحت توکن (متد بدون پارامتر)
	show( 'بله / getMe', http_request( BALE_API . $BALE_TOKEN . '/getMe' ) );

	// sendMessage — ارسال پیام (JSON پشتیبانی می‌شود)
	$payload = json_encode( array( 'chat_id' => $BALE_CHAT_ID, 'text' => '✅ تست از اسکریپت آزمایشی (بله)' ), JSON_UNESCAPED_UNICODE );
	show( 'بله / sendMessage', http_request( BALE_API . $BALE_TOKEN . '/sendMessage', 'POST', $payload ) );
} else {
	echo "توکن بله وارد نشده است — رد شد.\n";
}

echo "\n========== تست ایتا ==========\n";

if ( $EITAA_TOKEN ) {
	// getMe
	show( 'ایتا / getMe', http_request( EITAA_API . $EITAA_TOKEN . '/getMe' ) );

	// sendMessage (فرم urlencoded پشتیبانی می‌شود)
	show( 'ایتا / sendMessage', http_request(
		EITAA_API . $EITAA_TOKEN . '/sendMessage',
		'POST',
		array( 'chat_id' => $EITAA_CHAT_ID, 'text' => '✅ تست از اسکریپت آزمایشی (ایتا)' )
	) );
} else {
	echo "توکن ایتا وارد نشده است — رد شد.\n";
}
