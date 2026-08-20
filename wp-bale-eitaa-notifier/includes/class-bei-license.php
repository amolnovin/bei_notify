<?php
/**
 * ماژول لایسنس و بروزرسانی خودکار — اتصال به WPLM Client Kit آمل نوین.
 *
 * این کلاس دو قابلیت را با استفاده از کیت رسمی (wplm-client-kit) فعال می‌کند:
 *  ۱) قفل لایسنس: امکانات اصلی افزونه فقط با لایسنس فعال اجرا می‌شود
 *  ۲) بروزرسانی خودکار: نسخه‌های جدید در بخش «افزونه‌ها/بروزرسانی‌ها» وردپرس
 *     نمایش داده می‌شوند (از سرور فروش amolnovin.ir)
 *
 * ⚠️ مدیریت لایسنس (ثبت/فعال‌سازی/بررسی/حذف) در افزونه جداگانه
 * «لایسنس منیجر آمل نوین» (wplm-amolnovin-license-manager) انجام می‌شود —
 * این افزونه فقط «کلاینت» است و صفحه لایسنس مستقلی ندارد.
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_License
 */
final class Bei_License {

	/**
	 * اسلاگ محصول در پنل فروش (wp-plugin-market-manager).
	 * در صورت تغییر، باید در پنل فروش هم همان مقدار ثبت شود.
	 */
	const PRODUCT_SLUG = 'bei-notify';

	/**
	 * آدرس‌های API سرور فروش آمل نوین.
	 */
	const API_BASE    = 'https://amolnovin.ir/wp-json/wplm/v1';
	const UPDATE_API  = 'https://amolnovin.ir/wp-json/wplm/v1/updates/check';

	/**
	 * نمونه لایسنس (Singleton).
	 *
	 * @var WPLM_Client_Kit_License|null
	 */
	private static $license = null;

	/**
	 * نمونه آپدیت‌کننده.
	 *
	 * @var WPLM_Client_Kit_Updater|null
	 */
	private static $updater = null;

	/**
	 * راه‌اندازی کیت لایسنس و بروزرسانی — از فایل اصلی افزونه فراخوانی می‌شود.
	 */
	public static function boot() {
		require_once BEI_PLUGIN_DIR . 'wplm-client-kit/includes/wplm-license-client.php';
		require_once BEI_PLUGIN_DIR . 'wplm-client-kit/includes/wplm-plugin-updater.php';

		if ( ! class_exists( 'WPLM_Client_Kit_License' ) ) {
			return;
		}

		self::$license = new WPLM_Client_Kit_License(
			array(
				'api_base'       => self::API_BASE,
				'product_slug'   => self::PRODUCT_SLUG,
				'plugin_file'    => BEI_PLUGIN_FILE,
				'plugin_name'    => __( 'اعلان‌رسان بله، ایتا، تلگرام و واتساپ', 'bale-eitaa-notifier' ),
				'license_option' => 'bei_license_key',
				'status_option'  => 'bei_license_status',
				'data_option'    => 'bei_license_data',

				// حالت «منیجر مرکزی»: صفحه لایسنس فقط در افزونه جداگانه
				// wplm-amolnovin-license-manager است — این افزونه صفحه‌ای ندارد.
				'manager_page'     => true,
				'manager_required' => true,
				'individual_page'  => false,

				'lock_message' => __( 'برای استفاده از افزونه اعلان‌رسان، ابتدا لایسنس را از «لایسنس منیجر آمل نوین» فعال کنید.', 'bale-eitaa-notifier' ),
			)
		);

		self::$updater = new WPLM_Client_Kit_Updater(
			array(
				'api_url'        => self::UPDATE_API,
				'plugin_file'    => BEI_PLUGIN_FILE,
				'plugin_slug'    => 'wp-bale-eitaa-notifier',
				'product_slug'   => self::PRODUCT_SLUG,
				'version'        => BEI_VERSION,
				'license_option' => 'bei_license_key',
				'name'           => __( 'اعلان‌رسان بله، ایتا، تلگرام و واتساپ', 'bale-eitaa-notifier' ),
				'author'         => 'آمل نوین',
			)
		);

		// لینک «لایسنس» در فهرست افزونه‌ها: به صفحه منیجر مرکزی اشاره کند
		// (لینک پیش‌فرض کیت به صفحه فردی اشاره می‌کند که دیگر وجود ندارد).
		if ( self::$license instanceof WPLM_Client_Kit_License ) {
			remove_filter( 'plugin_action_links_' . BEI_PLUGIN_BASENAME, array( self::$license, 'plugin_action_links' ), 10 );
		}
		add_filter( 'plugin_action_links_' . BEI_PLUGIN_BASENAME, array( __CLASS__, 'action_links' ), 20, 1 );
	}

	/**
	 * لینک «لایسنس» در فهرست افزونه‌ها.
	 *
	 * @param array $links لینک‌های فعلی.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		if ( class_exists( 'WPLM_Amolnovin_License_Manager' ) ) {
			$url = WPLM_Amolnovin_License_Manager::page_url( array( 'product' => self::PRODUCT_SLUG ) );
		} else {
			// منیجر نصب نیست — کیت اعلان نصب/فعال‌سازی را نمایش می‌دهد.
			$url = admin_url( 'plugins.php' );
		}

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'لایسنس', 'bale-eitaa-notifier' ) . '</a>' );

		return $links;
	}

	/**
	 * آیا لایسنس فعال است؟
	 *
	 * نکته: ثابت BEI_LICENSE_BYPASS در wp-config.php فقط برای سایت‌های
	 * مالک/توسعه‌دهنده است و قفل را دور می‌زند:
	 *   define( 'BEI_LICENSE_BYPASS', true );
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( defined( 'BEI_LICENSE_BYPASS' ) && BEI_LICENSE_BYPASS ) {
			return true;
		}

		return self::$license instanceof WPLM_Client_Kit_License && self::$license->is_active();
	}

	/**
	 * دسترسی به نمونه لایسنس (برای سایر بخش‌های افزونه).
	 *
	 * @return WPLM_Client_Kit_License|null
	 */
	public static function license() {
		return self::$license;
	}

	/**
	 * دسترسی به نمونه آپدیت‌کننده.
	 *
	 * @return WPLM_Client_Kit_Updater|null
	 */
	public static function updater() {
		return self::$updater;
	}
}
