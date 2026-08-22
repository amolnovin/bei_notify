<?php
/**
 * کلاس اصلی افزونه — بارگذاری و اتصال همه ماژول‌ها.
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Plugin
 */
final class Bei_Plugin {

	/**
	 * نمونه یکتا (Singleton).
	 *
	 * @var Bei_Plugin|null
	 */
	private static $instance = null;

	/**
	 * ماژول پیام‌رسان.
	 *
	 * @var Bei_Messenger
	 */
	private $messenger;

	/**
	 * ماژول تنظیمات.
	 *
	 * @var Bei_Settings
	 */
	private $settings;

	/**
	 * ماژول پل ایمیل.
	 *
	 * @var Bei_Email_Bridge
	 */
	private $email_bridge;

	/**
	 * ماژول اتصال به افزونه‌های دیگر.
	 *
	 * @var Bei_Integrations
	 */
	private $integrations;

	/**
	 * ماژول REST API.
	 *
	 * @var Bei_Rest
	 */
	private $rest;

	/**
	 * ماژول شناسه‌یاب.
	 *
	 * @var Bei_Id_Finder
	 */
	private $id_finder;

	/**
	 * ماژول پراکسی.
	 *
	 * @var Bei_Proxy
	 */
	private $proxy;

	/**
	 * ماژول وضعیت‌های ووکامرس.
	 *
	 * @var Bei_Woo_Statuses
	 */
	private $woo_statuses;

	/**
	 * ماژول فرم‌های المنتور.
	 *
	 * @var Bei_Elementor_Forms
	 */
	private $elementor_forms;

	/**
	 * ماژول صف ارسال (Action Scheduler + Retry).
	 *
	 * @var Bei_Queue
	 */
	private $queue;

	/**
	 * ماژول لاگ.
	 *
	 * @var Bei_Logger
	 */
	private $logger;

	/**
	 * ماژول فهرست مصرف‌کنندگان API خارجی.
	 *
	 * @var Bei_Api_Consumers
	 */
	private $api_consumers;

	/**
	 * ماژول فعال‌سازی خودکار اعلان کاربر (بعد از ورود).
	 *
	 * @var Bei_User_Subscribe
	 */
	private $user_subscribe;

	/**
	 * نمونه یکتای افزونه را برمی‌گرداند.
	 *
	 * @return Bei_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * سازنده — خصوصی برای الگوی Singleton.
	 */
	private function __construct() {
		$this->includes();

		$this->messenger    = new Bei_Messenger();
		$this->settings     = new Bei_Settings();
		$this->email_bridge = new Bei_Email_Bridge();
		$this->integrations = new Bei_Integrations();
		$this->rest         = new Bei_Rest();
		$this->id_finder    = new Bei_Id_Finder();
		$this->proxy        = new Bei_Proxy();
		$this->woo_statuses    = new Bei_Woo_Statuses();
		$this->elementor_forms = new Bei_Elementor_Forms();
		$this->queue           = new Bei_Queue();
		$this->logger          = new Bei_Logger();
		$this->api_consumers   = new Bei_Api_Consumers();
		$this->user_subscribe  = new Bei_User_Subscribe();

		// بارگذاری ترجمه در init (طبق توصیه وردپرس 6.7+ — جلوگیری از خطای
		// «Translation loading was triggered too early»).
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// نقطه اتصال عمومی سایر افزونه‌ها: do_action( 'bei_send', $text, $targets ).
		add_action( 'bei_send', array( $this, 'handle_send_action' ), 10, 2 );

		// اکشن اختصاصی در «Actions After Submit» فرم‌های المنتور پرو:
		//  - هوک جدید (3.5+): elementor_pro/forms/actions_registrar + متد register()
		//  - هوک قدیمی (هنوز اجرا می‌شود): elementor_pro/forms/register_action
		// متد صحیح با method_exists تشخیص داده می‌شود تا با هر نسخه‌ای سازگار باشد.
		add_action( 'elementor_pro/forms/actions_registrar', array( $this, 'register_elementor_action' ) );
		add_action( 'elementor_pro/forms/register_action', array( $this, 'register_elementor_action' ) );
	}

	/**
	 * ثبت اکشن اختصاصی «اعلان پیام‌رسان‌ها» در فرم‌های المنتور پرو.
	 * (فایل کلاس اکشن فقط همین‌جا بارگذاری می‌شود تا در سایت‌های بدون المنتور خطا ندهد.)
	 *
	 * سازگار با:
	 *  - Elementor Pro 3.5+ : رجیسترار جدید با متد register()
	 *  - نسخه‌های قدیمی‌تر : ماژول Form_Actions_Module با متد add_form_action()
	 *
	 * نکته: در نسخه‌های جدید هر دو هوک (جدید و منسوخ) اجرا می‌شوند؛ ثبت دوباره
	 * با همان نام در رجیسترار المنتور (آرایه کلیددار) بی‌ضرر است.
	 *
	 * @param object $registrar رجیسترار اکشن‌های فرم.
	 */
	public function register_elementor_action( $registrar ) {
		if ( ! class_exists( 'ElementorPro\\Modules\\Forms\\Classes\\Action_Base' ) ) {
			return;
		}

		require_once BEI_PLUGIN_DIR . 'includes/class-bei-elementor-action.php';

		$action = new Bei_Elementor_Action();

		if ( method_exists( $registrar, 'register' ) ) {
			// Elementor Pro 3.5+ — رجیسترار جدید.
			$registrar->register( $action );
		} elseif ( method_exists( $registrar, 'add_form_action' ) ) {
			// نسخه‌های قدیمی‌تر.
			$registrar->add_form_action( $action );
		}
	}

	/**
	 * هندلر هوک عمومی bei_send — سایر افزونه‌ها بدون وابستگی مستقیم
	 * به توابع این افزونه، پیام ارسال می‌کنند:
	 *
	 *   do_action( 'bei_send', 'متن پیام', array( 'bale', 'eitaa' ) );
	 *
	 * اگر افزونه فعال نباشد، این فراخوانی بی‌اثر و بی‌خطر است (هیچ خطایی نمی‌دهد).
	 *
	 * @param string $text    متن پیام.
	 * @param array  $targets کانال‌های مقصد ('bale'، 'eitaa'، 'telegram'، 'whatsapp').
	 */
	public function handle_send_action( $text, $targets = array( 'bale', 'eitaa' ) ) {
		if ( empty( $text ) ) {
			return;
		}

		$targets = array_intersect( (array) $targets, array( 'bale', 'eitaa', 'telegram', 'whatsapp' ) );
		if ( empty( $targets ) ) {
			$targets = array( 'bale', 'eitaa' );
		}

		$this->queue->notify_async( (string) $text, $targets );
	}

	/**
	 * بارگذاری فایل‌های ماژول‌ها.
	 */
	private function includes() {
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-settings.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-messenger.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-email-bridge.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-integrations.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-rest.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-id-finder.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-proxy.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-woo-statuses.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-elementor-forms.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-queue.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-logger.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-api-consumers.php';
		require_once BEI_PLUGIN_DIR . 'includes/class-bei-user-subscribe.php';
	}

	/**
	 * بارگذاری فایل ترجمه.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'bale-eitaa-notifier', false, dirname( BEI_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * دسترسی به ماژول پیام‌رسان.
	 *
	 * @return Bei_Messenger
	 */
	public function messenger() {
		return $this->messenger;
	}

	/**
	 * دسترسی به ماژول تنظیمات.
	 *
	 * @return Bei_Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * دسترسی به ماژول پل ایمیل.
	 *
	 * @return Bei_Email_Bridge
	 */
	public function email_bridge() {
		return $this->email_bridge;
	}

	/**
	 * دسترسی به ماژول اتصال‌ها.
	 *
	 * @return Bei_Integrations
	 */
	public function integrations() {
		return $this->integrations;
	}

	/**
	 * دسترسی به ماژول REST.
	 *
	 * @return Bei_Rest
	 */
	public function rest() {
		return $this->rest;
	}

	/**
	 * دسترسی به ماژول شناسه‌یاب.
	 *
	 * @return Bei_Id_Finder
	 */
	public function id_finder() {
		return $this->id_finder;
	}

	/**
	 * دسترسی به ماژول پراکسی.
	 *
	 * @return Bei_Proxy
	 */
	public function proxy() {
		return $this->proxy;
	}

	/**
	 * دسترسی به ماژول وضعیت‌های ووکامرس.
	 *
	 * @return Bei_Woo_Statuses
	 */
	public function woo_statuses() {
		return $this->woo_statuses;
	}

	/**
	 * دسترسی به ماژول فرم‌های المنتور.
	 *
	 * @return Bei_Elementor_Forms
	 */
	public function elementor_forms() {
		return $this->elementor_forms;
	}

	/**
	 * دسترسی به ماژول صف ارسال.
	 *
	 * @return Bei_Queue
	 */
	public function queue() {
		return $this->queue;
	}

	/**
	 * دسترسی به ماژول لاگ.
	 *
	 * @return Bei_Logger
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * دسترسی به ماژول فهرست مصرف‌کنندگان API.
	 *
	 * @return Bei_Api_Consumers
	 */
	public function api_consumers() {
		return $this->api_consumers;
	}

	/**
	 * دسترسی به ماژول فعال‌سازی خودکار اعلان کاربر.
	 *
	 * @return Bei_User_Subscribe
	 */
	public function user_subscribe() {
		return $this->user_subscribe;
	}
}
