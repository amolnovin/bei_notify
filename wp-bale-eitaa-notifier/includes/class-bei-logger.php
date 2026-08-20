<?php
/**
 * ماژول Logger — ثبت تاریخچه ارسال‌ها و خطاها.
 *
 * ساختار هر رکورد: زمان، کانال، رویداد، وضعیت، خلاصه پیام، زمینه.
 * اطلاعات حساس (توکن/رمز) هرگز ذخیره نمی‌شود.
 * حداکثر ۲۰۰ رکورد آخر در آپشن نگهداری و در کارت «گزارش ارسال» نمایش داده می‌شود.
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Logger
 */
final class Bei_Logger {

	const OPTION = 'bei_log';
	const MAX    = 200;

	/**
	 * ثبت یک رکورد.
	 *
	 * @param string $channel کانال (bale/eitaa/telegram/whatsapp/system).
	 * @param string $event   رویداد (deliver/retry/test/webhook/...).
	 * @param string $status  وضعیت (sent/failed/scheduled/...).
	 * @param string $message خلاصه پیام (حداکثر ۱۸۰ کاراکتر — بدون داده حساس).
	 * @param array  $context زمینه (attempt/error/in_seconds/...).
	 */
	public function log( $channel, $event, $status, $message = '', $context = array() ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$summary = function_exists( 'mb_substr' ) ? mb_substr( (string) $message, 0, 180 ) : substr( (string) $message, 0, 180 );

		array_unshift(
			$log,
			array(
				'time'    => current_time( 'mysql' ),
				'channel' => sanitize_text_field( $channel ),
				'event'   => sanitize_text_field( $event ),
				'status'  => sanitize_text_field( $status ),
				'message' => $summary,
				'context' => is_array( $context ) ? $context : array(),
			)
		);

		$log = array_slice( $log, 0, self::MAX );
		update_option( self::OPTION, $log );
	}

	/**
	 * رکوردهای لاگ.
	 *
	 * @return array
	 */
	public static function entries() {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * پاک‌سازی کامل لاگ.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
