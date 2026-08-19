<?php
/**
 * نمونه‌های اتصال — چطور «سایر افزونه‌ها» از اعلان‌رسان بله و ایتا پیام بفرستند
 * ======================================================================
 * این فایل فقط یک کتابخانه نمونه (کتابچه کد) است و خودش به‌صورت خودکار
 * بارگذاری نمی‌شود. کدهای لازم را در افزونه خودتان، functions.php قالب
 * یا یک mu-plugin کپی کنید.
 *
 * سه روش استاندارد اتصال:
 *
 *   ۱) تابع مستقیم bei_notify()      — ساده‌ترین (با گارد function_exists)
 *   ۲) هوک استاندارد bei_send        — امن‌ترین (بدون هیچ وابستگی کدی)
 *   ۳) REST API                       — برای کدهای غیر PHP یا سایت دیگر
 * ----------------------------------------------------------------------
 * نمونه‌های فایل فقط با اهداف آموزشی ارائه شده‌اند.
 */

defined( 'ABSPATH' ) || exit;

/* ======================================================================
 * روش ۱ — تابع مستقیم bei_notify()
 * همیشه با function_exists گارد کنید تا با غیرفعال بودن افزونه، سایت نشکند.
 * ====================================================================== */

if ( function_exists( 'bei_notify' ) ) {
	bei_notify( '📢 پیام آزمایشی' );                                     // به کانال‌های پیش‌فرض تنظیمات.
	bei_notify( 'فقط بله', array( 'bale' ) );                            // کانال خاص.
	bei_notify( 'همه کانال‌ها', array( 'bale', 'eitaa', 'telegram', 'whatsapp' ) );

	// توابع اختصاصی هر کانال (با گارد جداگانه):
	if ( function_exists( 'bei_telegram_send' ) ) {
		bei_telegram_send( 'فقط تلگرام' );
	}
	if ( function_exists( 'bei_whatsapp_send' ) ) {
		bei_whatsapp_send( 'فقط واتساپ' );
	}
}

/* ======================================================================
 * روش ۲ — هوک استاندارد bei_send (پیشنهادی برای سایر افزونه‌ها ⭐)
 * بدون هیچ گاردی؛ اگر افزونه ما فعال نباشد، فراخوانی بی‌اثر و بی‌خطر است.
 * ====================================================================== */

// do_action( 'bei_send', '📢 پیام از افزونه شما', array( 'bale', 'eitaa' ) );

/* ======================================================================
 * روش ۳ — REST API (برای اسکریپت‌های بیرونی / غیر PHP)
 * آدرس:  /wp-json/bei/v1/notify
 * احراز: نام کاربری + Application Password
 * ====================================================================== */

/*
$response = wp_remote_post(
	rest_url( 'bei/v1/notify' ),
	array(
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode(
			array(
				'text'    => 'سلام از افزونه دیگر',
				'targets' => array( 'telegram' ),
			)
		),
	)
);
*/

/* ======================================================================
 * شخصی‌سازی متن برای هر کانال (فیلتر bei_notify_text)
 * مثلاً حذف قالب‌بندی Markdown برای واتساپ:
 * ====================================================================== */

/*
add_filter(
	'bei_notify_text',
	function ( $text, $target ) {
		if ( 'whatsapp' === $target ) {
			$text = wp_strip_all_tags( $text );
		}
		return $text;
	},
	10,
	2
);
*/

/* ======================================================================
 * نمونه‌های آماده — هوک‌های افزونه‌های معروف
 * (کد هر نمونه را در افزونه خودتان یا functions.php قرار دهید)
 * ----------------------------------------------------------------------
 * ⚠️ توجه: برای افزونه‌هایی که «اتصال آماده» خود ما دارد (CF7 ، WPForms ،
 * Gravity ، Ninja ، Fluent ، Elementor ، ووکامرس سفارش جدید) از سوییچ
 * تنظیمات استفاده کنید و هوک تکراری نسازید — در غیر این صورت پیام دوبار
 * ارسال می‌شود.
 * ====================================================================== */

// ---------- ووکامرس: تغییر وضعیت سفارش (مثلاً «تکمیل‌شده») ----------
/*
add_action(
	'woocommerce_order_status_completed',
	function ( $order_id ) {
		bei_notify( "✅ سفارش #{$order_id} تکمیل شد." );
	}
);
*/

// ---------- ووکامرس: کمبود موجودی محصول ----------
/*
add_action(
	'woocommerce_low_stock',
	function ( $product ) {
		bei_notify( "⚠️ موجودی «{$product->get_name()}» رو به اتمام است." );
	}
);
*/

// ---------- ثبت‌نام کاربر جدید ----------
/*
add_action(
	'user_register',
	function ( $user_id ) {
		$user = get_userdata( $user_id );
		bei_notify( "👤 کاربر جدید: {$user->display_name} ({$user->user_email})" );
	}
);
*/

// ---------- ورود موفق مدیر به پیشخوان ----------
/*
add_action(
	'wp_login',
	function ( $user_login, $user ) {
		if ( user_can( $user, 'manage_options' ) ) {
			bei_notify( "🔐 ورود مدیر: {$user_login}" );
		}
	},
	10,
	2
);
*/

// ---------- نظر جدید ----------
/*
add_action(
	'comment_post',
	function ( $comment_id ) {
		$comment = get_comment( $comment_id );
		bei_notify( "💬 نظر جدید از {$comment->comment_author}:\n{$comment->comment_content}" );
	}
);
*/

// ---------- بازیابی رمز عبور ----------
/*
add_action(
	'password_reset',
	function ( $user ) {
		bei_notify( "🔑 رمز عبور {$user->user_login} بازنشانی شد." );
	}
);
*/

// ---------- هشدار خرابی ایمیل سایت (wp_mail_failed هسته وردپرس) ----------
/*
add_action(
	'wp_mail_failed',
	function ( $error ) {
		bei_notify( '⚠️ ارسال ایمیل سایت ناموفق بود: ' . $error->get_error_message(), array( 'bale' ) );
	}
);
*/

// ---------- EDD — پرداخت کامل ----------
/*
add_action(
	'edd_complete_purchase',
	function ( $payment_id ) {
		bei_notify( "💰 پرداخت کامل در EDD: #{$payment_id}" );
	}
);
*/

// ---------- LearnDash — تکمیل دوره ----------
/*
add_action(
	'learndash_course_completed',
	function ( $data ) {
		$user  = get_userdata( $data['user']->ID );
		$course = get_the_title( $data['course']->ID );
		bei_notify( "🎓 {$user->display_name} دوره «{$course}» را تمام کرد." );
	}
);
*/

// ---------- WP Job Manager — ثبت آگهی جدید ----------
/*
add_action(
	'job_manager_job_submitted',
	function ( $job_id ) {
		bei_notify( "💼 آگهی استخدامی جدید: " . get_the_title( $job_id ) );
	}
);
*/

// ---------- Elementor Pro Form — با فیلتر کردن نام فرم ----------
/*
add_action(
	'elementor_pro/forms/new_record',
	function ( $record ) {
		$settings = $record->get( 'form_settings' );
		if ( 'فرم پشتیبانی' !== $settings['form_name'] ) {
			return;
		}
		bei_notify( '📩 فرم پشتیبانی جدید ثبت شد.' );
	}
);
*/

/* ======================================================================
 * نمونه پیشرفته — خلاصه روزانه (Cron)
 * هر روز ساعت ۹ صبح تعداد سفارش‌های دیروز را بفرستد:
 * ====================================================================== */

/*
if ( ! wp_next_scheduled( 'my_daily_digest' ) ) {
	wp_schedule_event( strtotime( '09:00:00' ), 'daily', 'my_daily_digest' );
}

add_action(
	'my_daily_digest',
	function () {
		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$count = 0;
		if ( function_exists( 'wc_get_orders' ) ) {
			$count = count(
				wc_get_orders(
					array(
						'limit'        => -1,
						'return'       => 'ids',
						'date_created' => $yesterday,
					)
				)
			);
		}
		bei_notify( "📊 خلاصه دیروز: {$count} سفارش ثبت شد." );
	}
);
*/

/* ======================================================================
 * جدول مرجع سریع هوک‌های پرکاربرد
 * ----------------------------------------------------------------------
 * | افزونه / رویداد              | هوک                           |
 * |------------------------------|-------------------------------|
 * | ووکامرس: سفارش جدید          | woocommerce_new_order         |
 * | ووکامرس: پرداخت موفق         | woocommerce_payment_complete  |
 * | ووکامرس: تغییر وضعیت         | woocommerce_order_status_<وضعیت> |
 * | ووکامرس: کمبود موجودی        | woocommerce_low_stock         |
 * | ثبت‌نام کاربر                | user_register                 |
 * | ورود کاربر                   | wp_login                      |
 * | نظر جدید                     | comment_post                  |
 * | بازنشانی رمز                 | password_reset                |
 * | خطای ایمیل                   | wp_mail_failed                |
 * | Contact Form 7               | wpcf7_mail_sent               |
 * | WPForms                      | wpforms_process_complete      |
 * | Gravity Forms                | gform_after_submission        |
 * | Ninja Forms                  | ninja_forms_after_submission  |
 * | Fluent Forms                 | fluentform_submission_inserted |
 * | Elementor Pro Form           | elementor_pro/forms/new_record |
 * | EDD: پرداخت کامل             | edd_complete_purchase         |
 * | LearnDash: پایان دوره        | learndash_course_completed    |
 * | WP Job Manager: آگهی جدید    | job_manager_job_submitted     |
 * | bbPress: موضوع جدید          | bbp_new_topic                 |
 * | انتشار نوشته (بدون افزونه ما)| transition_post_status        |
 * ====================================================================== */
