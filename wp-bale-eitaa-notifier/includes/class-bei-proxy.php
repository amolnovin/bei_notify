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

		// عیب‌یابی شبکه: افزایش مهلت برقراری اتصال (پیش‌فرض وردپرس ۱۰ ثانیه است).
		if ( ! empty( $options['tg_connect_timeout'] ) && defined( 'CURLOPT_CONNECTTIMEOUT' ) ) {
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, max( 10, (int) $options['tg_connect_timeout'] ) );
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
	 * آیا هاست موردنظر جزو دامنه‌های هدف پیام‌رسان‌هاست؟
	 * (دامنه‌های انتخاب‌شده در تنظیمات + دامنه‌های رله سفارشی)
	 *
	 * @param string $host    هاست درخواست.
	 * @param array  $options تنظیمات افزونه.
	 * @return bool
	 */
	private function is_messenger_host( $host, $options ) {
		$targets = empty( $options['tg_proxy_hosts'] ) ? array( 'telegram' ) : $options['tg_proxy_hosts'];

		foreach ( $targets as $target ) {
			if ( isset( self::HOSTS[ $target ] ) && self::HOSTS[ $target ] === $host ) {
				return true;
			}
		}

		// اگر آدرس API جایگزین (رله) تنظیم شده باشد، دامنه آن هم پذیرفته می‌شود.
		foreach ( array( 'tg_api_base', 'wa_api_base' ) as $base_key ) {
			if ( empty( $options[ $base_key ] ) ) {
				continue;
			}
			$relay_host = wp_parse_url( $options[ $base_key ], PHP_URL_HOST );
			if ( $relay_host && $relay_host === $host ) {
				return true;
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
