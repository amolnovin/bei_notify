<?php
/**
 * Plugin Name:       اعلان‌رسان بله و ایتا
 * Plugin URI:        https://example.com/bale-eitaa-notifier
 * Description:       ارسال خودکار پیام به تلگرام، بله، ایتا و واتساپ (با مسیرهای رایگان CallMeBot و شماره تست متا)؛ با پل ایمیل سراسری، اتصال آماده به فرم‌ها و ووکامرس، API خارجی و پشتیبانی پراکسی/رله برای دور زدن فیلترینگ. بر اساس مستندات رسمی core.telegram.org ، docs.bale.ai ، eitaayar.ir/api و callmebot.com
 * Version:           3.0.1
 * Requires at least: 5.0
 * Requires PHP:      7.2
 * Author:            شما
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bale-eitaa-notifier
 * Domain Path:       /languages
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

define( 'BEI_VERSION', '3.0.1' );
define( 'BEI_PLUGIN_FILE', __FILE__ );
define( 'BEI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BEI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BEI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once BEI_PLUGIN_DIR . 'includes/class-bei-plugin.php';

/**
 * دسترسی به نمونه اصلی افزونه.
 *
 * @return Bei_Plugin
 */
function bei() {
	return Bei_Plugin::instance();
}

bei();

/* ---------------------------------------------------------------------------
 * توابع عمومی (میان‌بُر) — برای استفاده آسان در قالب و سایر افزونه‌ها
 * ------------------------------------------------------------------------- */

/**
 * ارسال پیام به یک یا چند پیام‌رسان.
 *
 * @param string $text    متن پیام.
 * @param array  $targets آرایه‌ای از 'bale'، 'eitaa' و/یا 'telegram'.
 * @return array نتیجه به تفکیک هر پیام‌رسان.
 */
function bei_notify( $text, $targets = array( 'bale', 'eitaa' ) ) {
	return bei()->messenger()->notify( $text, $targets );
}

/**
 * ارسال پیام متنی به بله.
 *
 * @param string $text متن پیام.
 * @param array  $args پارامترهای اضافی (مثل reply_markup).
 * @return array|WP_Error
 */
function bei_bale_send( $text, $args = array() ) {
	return bei()->messenger()->send_bale( $text, $args );
}

/**
 * ارسال تصویر به بله (فایل محلی یا URL).
 *
 * @param string $photo   مسیر فایل یا آدرس اینترنتی.
 * @param string $caption توضیح تصویر.
 * @return array|WP_Error
 */
function bei_bale_send_photo( $photo, $caption = '' ) {
	return bei()->messenger()->send_bale_photo( $photo, $caption );
}

/**
 * دریافت اطلاعات ربات بله (تست توکن).
 *
 * @return array|WP_Error
 */
function bei_bale_get_me() {
	return bei()->messenger()->get_bale_me();
}

/**
 * ارسال پیام متنی به ایتا.
 *
 * @param string $text متن پیام.
 * @param array  $args پارامترهای اختیاری (pin, date, viewCountForDelete, ...).
 * @return array|WP_Error
 */
function bei_eitaa_send( $text, $args = array() ) {
	return bei()->messenger()->send_eitaa( $text, $args );
}

/**
 * ارسال فایل به ایتا.
 *
 * @param string $file_path مسیر فایل روی سرور.
 * @param string $caption   توضیح فایل.
 * @param array  $extra     پارامترهای اختیاری.
 * @return array|WP_Error
 */
function bei_eitaa_send_file( $file_path, $caption = '', $extra = array() ) {
	return bei()->messenger()->send_eitaa_file( $file_path, $caption, $extra );
}

/**
 * ارسال پیام به کاربران از طریق API «برنامه» ایتا.
 *
 * @param string $token   توکن برنامه.
 * @param string $chat_id ایتا آیدی کاربر.
 * @param string $text    متن پیام.
 * @return array|WP_Error
 */
function bei_eitaa_app_send( $token, $chat_id, $text ) {
	return bei()->messenger()->send_eitaa_app( $token, $chat_id, $text );
}

/**
 * دریافت اطلاعات ربات ایتا (تست توکن).
 *
 * @return array|WP_Error
 */
function bei_eitaa_get_me() {
	return bei()->messenger()->get_eitaa_me();
}

/**
 * ارسال پیام متنی به تلگرام.
 *
 * @param string $text متن پیام.
 * @param array  $args پارامترهای اضافی (reply_markup، parse_mode و...).
 * @return array|WP_Error
 */
function bei_telegram_send( $text, $args = array() ) {
	return bei()->messenger()->send_telegram( $text, $args );
}

/**
 * ارسال تصویر به تلگرام (فایل محلی یا URL).
 *
 * @param string $photo   مسیر فایل یا آدرس اینترنتی.
 * @param string $caption توضیح تصویر.
 * @return array|WP_Error
 */
function bei_telegram_send_photo( $photo, $caption = '' ) {
	return bei()->messenger()->send_telegram_photo( $photo, $caption );
}

/**
 * دریافت اطلاعات ربات تلگرام (تست توکن).
 *
 * @return array|WP_Error
 */
function bei_telegram_get_me() {
	return bei()->messenger()->get_telegram_me();
}

/**
 * ارسال پیام به واتساپ (درگاه انتخاب‌شده در تنظیمات: CallMeBot رایگان / شماره تست متا / پولی‌ها).
 *
 * @param string $text متن پیام.
 * @param array  $args پارامترهای اضافی.
 * @return array|WP_Error
 */
function bei_whatsapp_send( $text, $args = array() ) {
	return bei()->messenger()->send_whatsapp( $text, $args );
}

/**
 * میان‌بُر پل ایمیل (برای تست‌های دستی).
 *
 * @param array $args آرایه‌ی wp_mail.
 * @return array همان آرایه بدون تغییر.
 */
function bei_wp_mail_bridge( $args ) {
	return bei()->email_bridge()->bridge( $args );
}

/**
 * تبدیل موضوع و بدنه ایمیل به متن قابل ارسال.
 *
 * @param string $subject موضوع ایمیل.
 * @param string $message بدنه ایمیل.
 * @return string
 */
function bei_format_mail_message( $subject, $message ) {
	return bei()->email_bridge()->format_message( $subject, $message );
}
