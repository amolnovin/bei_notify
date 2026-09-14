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

		// فقط کانال‌های «فعال‌شده» در تنظیمات ارسال می‌شوند.
		$targets = array_values( array_intersect( (array) $targets, Bei_Settings::enabled_channels() ) );
		if ( empty( $targets ) ) {
			// نکته (باگ رفع‌شده): قبلاً اینجا بی‌صدا به بله/ایتا هدایت می‌شد —
			// یعنی پیام «ارسال» می‌شد ولی به مقصد اشتباه. حالا رد شدن ثبت می‌شود.
			bei()->logger()->log( 'system', 'queue', 'skipped', $text, array( 'reason' => 'no_enabled_targets' ) );

			return false;
		}

		$options = Bei_Settings::get_options();

		if ( empty( $options['queue_enabled'] ) ) {
			// صف غیرفعال است — ارسال مستقیم (حالت پیش‌فرض قدیمی).
			return bei()->messenger()->notify( $text, $targets );
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// Action Scheduler: ارسال واقعی در پس‌زمینه.
			$action_id = as_enqueue_async_action( self::HOOK, array( $text, $targets, 0 ), self::GROUP );
			if ( $action_id ) {
				bei()->logger()->log( 'system', 'queue', 'queued', $text, array( 'targets' => $targets, 'engine' => 'action-scheduler' ) );

				return true;
			}
			// اگر AS ناموفق بود، به WP-Cron می‌افتیم (نه اینکه پیام گم شود).
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			// WP-Cron تک‌زمانه: ارسال در اولین درخواست بعدی (خارج از Request فعلی).
			$scheduled = wp_schedule_single_event( time() + 2, self::HOOK, array( $text, $targets, 0 ) );
			if ( $scheduled ) {
				bei()->logger()->log( 'system', 'queue', 'queued', $text, array( 'targets' => $targets, 'engine' => 'wp-cron' ) );

				return true;
			}
		}

		// هیچ صفی در دسترس نبود — ارسال مستقیم تا پیام گم نشود.
		return bei()->messenger()->notify( $text, $targets );
	}

	/**
	 * تحویل واقعی پیام (از صف) + Retry با Exponential Backoff.
	 *
	 * نکته (باگ رفع‌شده): Retry فقط برای کانال‌های «ناموفق» زمان‌بندی می‌شود —
	 * کانال‌هایی که در تلاش اول موفق بودند دوباره پیام نمی‌گیرند. خطاهای
	 * پیکربندی (توکن/شناسه تنظیم نشده) و «محدودیت سرویس» (bei_rate_limit —
	 * مثل Too many requests کالبوت) هم دائمی‌اند و Retry نمی‌شوند.
	 *
	 * @param string $text    متن پیام.
	 * @param array  $targets کانال‌های مقصد.
	 * @param int    $attempt شماره تلاش (۰ تا ۲).
	 * @return array نتایج.
	 */
	public function deliver( $text, $targets, $attempt = 0 ) {
		$attempt = (int) $attempt;
		$results = bei()->messenger()->notify( (string) $text, (array) $targets );

		$retryable = array();
		$permanent = array();

		foreach ( (array) $results as $channel => $result ) {
			if ( is_wp_error( $result ) ) {
				bei()->logger()->log(
					$channel,
					'deliver',
					'failed',
					$text,
					array( 'attempt' => $attempt + 1, 'error' => $result->get_error_message() )
				);

				if ( in_array( $result->get_error_code(), array( 'bei_config', 'bei_rate_limit' ), true ) ) {
					// خطای پیکربندی یا محدودیت سرویس (Rate Limit) دائمی است —
					// تلاش مجدد فوری فقط سرویس رایگان را بدتر محدود می‌کند.
					$permanent[] = $channel;
				} else {
					$retryable[ $channel ] = $result->get_error_message();
				}
			} else {
				bei()->logger()->log( $channel, 'deliver', 'sent', $text, array( 'attempt' => $attempt + 1 ) );
			}
		}

		if ( ! empty( $permanent ) ) {
			bei()->logger()->log( 'system', 'permanent', 'failed', '', array( 'channels' => $permanent ) );
		}

		// Retry: فقط کانال‌های ناموفق (نه همه) — جلوگیری از ارسال تکراری به کانال‌های موفق.
		if ( ! empty( $retryable ) && $attempt < 2 ) {
			$retry_targets = array_keys( $retryable );
			$delays        = array( 10, 60 );
			$delay         = isset( $delays[ $attempt ] ) ? $delays[ $attempt ] : 300;
			$args          = array( $text, $retry_targets, $attempt + 1 );

			bei()->logger()->log(
				'system',
				'retry',
				'scheduled',
				'',
				array( 'channels' => $retry_targets, 'in_seconds' => $delay, 'attempt' => $attempt + 2 )
			);

			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
			} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
				wp_schedule_single_event( time() + $delay, self::HOOK, $args );
			}
		}

		return $results;
	}
}
