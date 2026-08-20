<?php
/**
 * ماژول صف ارسال (Queue) — ارسال ناهمزمان با Action Scheduler و Retry با Backoff.
 *
 * طبق گزارش فنی: ارسال همزمان داخل Request اصلی ووکامرس باعث کند شدن Checkout
 * می‌شود. این ماژول ارسال‌ها را به صف می‌برد:
 *  - Action Scheduler (اگر ووکامرس/AS فعال باشد) — ارسال واقعی در پس‌زمینه
 *  - WP-Cron تک‌زمانه (بدون AS) — ارسال در اولین درخواست بعدی
 *  - ارسال مستقیم (اگر هر دو در دسترس نباشند) — هیچ پیامی گم نمی‌شود
 *
 * Retry: تا ۳ تلاش با تأخیر ۱۰ و ۶۰ ثانیه‌ای (Exponential Backoff).
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Queue
 */
final class Bei_Queue {

	const HOOK  = 'bei_deliver';
	const GROUP = 'bei-notify';

	/**
	 * ثبت هوک تحویل.
	 */
	public function __construct() {
		add_action( self::HOOK, array( $this, 'deliver' ), 10, 3 );
	}

	/**
	 * ارسال ناهمزمان: پیام به صف می‌رود (یا در نبود صف، مستقیم ارسال می‌شود).
	 *
	 * @param string $text    متن پیام.
	 * @param array  $targets کانال‌های مقصد.
	 * @return bool ارسال به صف انجام شد یا نتیجه مستقیم.
	 */
	public function notify_async( $text, $targets = array( 'bale', 'eitaa' ) ) {
		$text = (string) $text;

		$targets = array_intersect( (array) $targets, array( 'bale', 'eitaa', 'telegram', 'whatsapp' ) );
		if ( empty( $targets ) ) {
			$targets = array( 'bale', 'eitaa' );
		}

		$options = Bei_Settings::get_options();

		if ( empty( $options['queue_enabled'] ) ) {
			// صف غیرفعال است — ارسال مستقیم (حالت پیش‌فرض قدیمی).
			return bei()->messenger()->notify( $text, $targets );
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// Action Scheduler: ارسال واقعی در پس‌زمینه.
			as_enqueue_async_action( self::HOOK, array( $text, $targets, 0 ), self::GROUP );

			return true;
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			// WP-Cron تک‌زمانه: ارسال در اولین درخواست بعدی (خارج از Request فعلی).
			return wp_schedule_single_event( time() + 2, self::HOOK, array( $text, $targets, 0 ) );
		}

		// هیچ صفی در دسترس نیست — ارسال مستقیم تا پیام گم نشود.
		return bei()->messenger()->notify( $text, $targets );
	}

	/**
	 * تحویل واقعی پیام (از صف) + Retry با Exponential Backoff.
	 *
	 * @param string $text    متن پیام.
	 * @param array  $targets کانال‌های مقصد.
	 * @param int    $attempt شماره تلاش (۰ تا ۲).
	 * @return array نتایج.
	 */
	public function deliver( $text, $targets, $attempt = 0 ) {
		$attempt = (int) $attempt;
		$results = bei()->messenger()->notify( (string) $text, (array) $targets );

		$failed = array();

		foreach ( (array) $results as $channel => $result ) {
			if ( is_wp_error( $result ) ) {
				$failed[ $channel ] = $result->get_error_message();
				bei()->logger()->log( $channel, 'deliver', 'failed', $text, array( 'attempt' => $attempt + 1, 'error' => $result->get_error_message() ) );
			} else {
				bei()->logger()->log( $channel, 'deliver', 'sent', $text, array( 'attempt' => $attempt + 1 ) );
			}
		}

		// Retry: خطاهای موقتی (Timeout/DNS/Rate Limit) با تأخیر تصاعدی دوباره تلاش می‌شوند.
		if ( ! empty( $failed ) && $attempt < 2 ) {
			$delays = array( 10, 60 );
			$delay  = isset( $delays[ $attempt ] ) ? $delays[ $attempt ] : 300;
			$args   = array( $text, $targets, $attempt + 1 );

			bei()->logger()->log( 'system', 'retry', 'scheduled', '', array( 'in_seconds' => $delay, 'attempt' => $attempt + 2 ) );

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
			} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time() + $delay, self::HOOK, $args );
			}
		}

		return $results;
	}
}
