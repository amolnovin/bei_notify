<?php
/**
 * تست رله تلگرام (مستقل از وردپرس)
 *
 * روش استفاده:
 *  ۱) مقادیر زیر را پر کنید
 *  ۲) روی سرور وردپرس آپلود کنید و اجرا کنید:
 *       php test-relay.php
 *       یا از مرورگر:  https://your-domain.com/test-relay.php
 *  ۳) بعد از تست موفق، این فایل را از سرور حذف کنید!
 */

// ---------------------- مقادیر خود را وارد کنید ----------------------
$BASE  = 'https://tg-relay.example.com'; // آدرس رله شما (بدون /bot و بدون اسلش آخر)
$TOKEN = '123456789:AAF...';             // توکن ربات تلگرام
$CHAT  = '@myChannel';                   // شناسه گفتگو
// ----------------------------------------------------------------------

/**
 * درخواست به متد Bot API از مسیر رله.
 *
 * @param string      $base   آدرس پایه رله.
 * @param string      $token  توکن ربات.
 * @param string      $method نام متد.
 * @param array|null  $post   بدنه POST (اختیاری).
 * @return array
 */
function tg_request( $base, $token, $method, $post = null ) {
	$url = rtrim( $base, '/' ) . '/bot' . $token . '/' . $method;

	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_TIMEOUT        => 60,
		)
	);

	if ( null !== $post ) {
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $post, JSON_UNESCAPED_UNICODE ) );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
	}

	$response = curl_exec( $ch );
	$error    = curl_error( $ch );
	curl_close( $ch );

	if ( $error ) {
		return array( 'transport_error' => $error );
	}

	return json_decode( $response, true ) ?: array( 'raw' => $response );
}

header( 'Content-Type: text/plain; charset=utf-8' );

echo "آدرس رله: {$BASE}\n";
echo str_repeat( '=', 60 ) . "\n\n";

echo "1) getMe (تست صحت توکن از مسیر رله):\n";
$me = tg_request( $BASE, $TOKEN, 'getMe' );
echo json_encode( $me, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
echo ( isset( $me['ok'] ) && $me['ok'] ) ? "✅ رله به api.telegram.org وصل شد\n" : "❌ ناموفق — آدرس رله/دامنه/توکن را بررسی کنید\n";
echo "\n" . str_repeat( '=', 60 ) . "\n\n";

echo "2) sendMessage (ارسال پیام تست):\n";
$sent = tg_request(
	$BASE,
	$TOKEN,
	'sendMessage',
	array(
		'chat_id' => $CHAT,
		'text'    => '✅ تست موفق رله تلگرام',
	)
);
echo json_encode( $sent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
echo ( isset( $sent['ok'] ) && $sent['ok'] ) ? "✅ پیام ارسال شد — حالا همین آدرس را در افزونه (فیلد «آدرس API») وارد کنید\n" : "❌ ارسال ناموفق\n";
