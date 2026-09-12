<?php
/**
 * ماژول پراکسی — دور زدن فیلترینگ برای درخواست‌های API پیام‌رسان‌ها.
 *
 * وقتی سایت روی سرور داخل ایران است، api.telegram.org در دسترس نیست.
 * این ماژول از طریق هوک http_api_curl (قبل از اجرای هر درخواست cURL وردپرس)
 * تنظیمات پراکسی را فقط برای درخواست‌های پیام‌رسان‌های انتخاب‌شده اعمال می‌کند.
 *
 * راهکارهای دیگر (در مستندات افزونه):
 *  - میزبانی سایت خارج از ایران (پرکاربردترین حالت در ایران)
 *  - آدرس API جایگزین/رله (تنظیم tg_api_base)
 *  - ثابت‌های سراسری WP_PROXY_HOST و... در wp-config.php
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Proxy
 */
final class Bei_Proxy {

	/**
	 * نگاشت پیام‌رسان به دامنه API.
	 *
	 * @var array
	 */
	const HOSTS = array(
		'telegram' => 'api.telegram.org',
		'bale'     => 'tapi.bale.ai',
		'eitaa'    => 'eitaayar.ir',
		'greenapi' => 'api.green-api.com',
		'ultramsg' => 'api.ultramsg.com',
		'meta'     => 'graph.facebook.com',
		'callmebot' => 'api.callmebot.com',
	);

	/**
	 * ثبت هوک cURL.
	 */
	public function __construct() {
		add_action( 'http_api_curl', array( $this, 'apply' ), 10, 3 );

		// دفاع در برابر افزونه‌هایی که مهلت HTTP وردپرس را سراسری کم می‌کنند
		// (خطای «Operation timed out after 10000 milliseconds»): مهلت درخواست‌های
		// دامنه‌های پیام‌رسان همیشه از تنظیم «مهلت کلی هر درخواست» کمتر نمی‌شود.
		add_filter( 'http_request_args', array( $this, 'enforce_timeout' ), 9999, 2 );
	}

	/**
	 * بازنویسی مهلت کلی درخواست‌های پیام‌رسان‌ها (اولویت ۹۹۹۹ — آخر از همه).
	 *
	 * @param array  $args آرگومان‌های درخواست.
	 * @param string $url  آدرس درخواست.
	 * @return array
	 */
	public function enforce_timeout( $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || ! $this->is_messenger_host( $host, Bei_Settings::get_options() ) ) {
			return $args;
		}

		$options = Bei_Settings::get_options();
		$floor   = min( 300, max( 10, (int) $options['bei_http_timeout'] ) );

		if ( isset( $args['timeout'] ) && is_numeric( $args['timeout'] ) && (float) $args['timeout'] < $floor ) {
			$args['timeout'] = $floor;
		}

		return $args;
	}

	/**
	 * اعمال تنظیمات شبکه (فورس IPv4، مهلت اتصال و پراکسی) روی درخواست‌های cURL
	 * پیام‌رسان‌های انتخاب‌شده.
	 *
	 * @param resource $handle      هندل cURL.
	 * @param array    $parsed_args آرگومان‌های درخواست.
	 * @param string   $url         آدرس درخواست.
	 */
	public function apply( $handle, $parsed_args, $url ) {
		$options = Bei_Settings::get_options();

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return;
		}

		if ( ! $this->is_messenger_host( $host, $options ) ) {
			return;
		}

		// عیب‌یابی شبکه: فورس IPv4 برای سرورهایی که IPv6 ندارند.
		if ( ! empty( $options['tg_force_ipv4'] ) && defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
		}

		// عیب‌یابی شبکه: مهلت برقراری اتصال (پیش‌فرض وردپرس ۱۰ ثانیه است —
		// برای سرورهای داخل ایران همین ۱۰ ثانیه منشأ خطای cURL error 28 است).
		if ( defined( 'CURLOPT_CONNECTTIMEOUT' ) ) {
			$connect = ! empty( $options['tg_connect_timeout'] )
				? max( 10, (int) $options['tg_connect_timeout'] )
				: 15;
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, $connect );
		}

		// بازنویسی مهلت کلی روی خود هندل cURL (آخرین لحظه، بعد از کتابخانه Requests):
		// اگر افزونه/میوپلاگین دیگری مهلت را به ۱۰ ثانیه محدود کرده باشد، اینجا
		// برای درخواست‌های پیام‌رسان‌ها به «مهلت کلی هر درخواست» برمی‌گردد.
		// (درخواست‌هایی که خودشان مهلت بیشتری خواسته‌اند — مثل آپلود فایل — دست نمی‌خورند.)
		if ( defined( 'CURLOPT_TIMEOUT' ) && isset( $parsed_args['timeout'] ) && is_numeric( $parsed_args['timeout'] ) ) {
			$floor = min( 300, max( 10, (int) $options['bei_http_timeout'] ) );
			if ( (float) $parsed_args['timeout'] >= 1 && (float) $parsed_args['timeout'] < $floor ) {
				curl_setopt( $handle, CURLOPT_TIMEOUT, $floor );
			}
		}

		if ( empty( $options['tg_proxy_enabled'] ) || empty( $options['tg_proxy_host'] ) ) {
			return;
		}

		curl_setopt( $handle, CURLOPT_PROXY, $this->proxy_url( $options ) );
		curl_setopt( $handle, CURLOPT_PROXYTYPE, 'socks5' === $options['tg_proxy_type'] ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP );

		if ( ! empty( $options['tg_proxy_user'] ) ) {
			curl_setopt( $handle, CURLOPT_PROXYUSERPWD, $options['tg_proxy_user'] . ':' . $options['tg_proxy_pass'] );
			// مذاکره خودکار روش احراز هویت (Basic/Digest/NTLM) — سازگار با پراکسی‌های مختلف.
			$auth = defined( 'CURLAUTH_ANY' ) ? CURLAUTH_ANY : CURLAUTH_BASIC;
			curl_setopt( $handle, CURLOPT_PROXYAUTH, $auth );
		}
	}

	/**
	 * آیا هاست موردنظر جزو دامنه‌های هدف پیام‌رسان‌های «فعال» است؟
	 * (دامنه‌های انتخاب‌شده در تنظیمات + دامنه‌های رله سفارشی)
	 *
	 * @param string $host    هاست درخواست.
	 * @param array  $options تنظیمات افزونه.
	 * @return bool
	 */
	private function is_messenger_host( $host, $options ) {
		$targets = empty( $options['tg_proxy_hosts'] ) ? array( 'telegram' ) : $options['tg_proxy_hosts'];

		// نگاشت هر هدف به سوییچ فعال‌سازی پیام‌رسان مربوطه.
		$enabled_map = array(
			'telegram'  => ! empty( $options['tg_enabled'] ),
			'bale'      => ! empty( $options['bale_enabled'] ),
			'eitaa'     => ! empty( $options['eitaa_enabled'] ),
			'greenapi'  => ! empty( $options['wa_enabled'] ),
			'ultramsg'  => ! empty( $options['wa_enabled'] ),
			'meta'      => ! empty( $options['wa_enabled'] ),
			'callmebot' => ! empty( $options['wa_enabled'] ),
		);

		foreach ( $targets as $target ) {
			if ( isset( self::HOSTS[ $target ] ) && self::HOSTS[ $target ] === $host ) {
				return ! empty( $enabled_map[ $target ] );
			}
		}

		// اگر آدرس API جایگزین (رله) تنظیم شده باشد، دامنه آن هم پذیرفته می‌شود.
		foreach ( array( 'tg_api_base' => 'tg_enabled', 'tg_api_base_alt' => 'tg_enabled', 'wa_api_base' => 'wa_enabled' ) as $base_key => $enabled_key ) {
			if ( empty( $options[ $base_key ] ) ) {
				continue;
			}
			$relay_host = wp_parse_url( $options[ $base_key ], PHP_URL_HOST );
			if ( $relay_host && $relay_host === $host ) {
				return ! empty( $options[ $enabled_key ] );
			}
		}

		return false;
	}

	/**
	 * ساخت آدرس کامل پراکسی (به‌همراه طرح و پورت).
	 *
	 * @param array $options تنظیمات افزونه.
	 * @return string
	 */
	private function proxy_url( $options ) {
		$host = $options['tg_proxy_host'];

		if ( false === strpos( $host, '://' ) ) {
			$host = ( 'socks5' === $options['tg_proxy_type'] ? 'socks5' : 'http' ) . '://' . $host;
		}

		return $host . ( ! empty( $options['tg_proxy_port'] ) ? ':' . $options['tg_proxy_port'] : '' );
	}
}
