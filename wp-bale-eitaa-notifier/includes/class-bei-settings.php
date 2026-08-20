<?php
/**
 * ماژول تنظیمات — صفحه تنظیمات با رابط کاربری مدرن.
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Settings
 */
final class Bei_Settings {

	const OPTION_KEY     = 'bei_options';
	const SETTINGS_GROUP = 'bei_settings_group';
	const PAGE_SLUG      = 'bei-settings';
	const NONCE_ACTION   = 'bei_test';

	/**
	 * ثبت هوک‌های مدیریت.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_bei_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_bei_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * مقادیر پیش‌فرض تنظیمات.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// اعتبارنامه‌ها.
			'bale_token'       => '',
			'bale_chat_id'     => '',
			'bale_bot_username' => '',
			'bale_business'    => 0,
			'eitaa_token'      => '',
			'eitaa_chat_id'    => '',
			'tg_token'         => '',
			'tg_chat_id'       => '',
			'tg_bot_username'  => '',
			'tg_api_base'      => '',
			'tg_relay_key'     => '',

			// واتساپ (مسیرهای رایگان: CallMeBot و شماره تست متا + درگاه‌های پولی).
			'wa_gateway'  => 'callmebot',
			'wa_instance' => '',
			'wa_token'    => '',
			'wa_chat_id'  => '',
			'wa_api_base' => '',

			// پراکسی (برای سرورهای داخل ایران).
			'tg_proxy_enabled' => 0,
			'tg_proxy_type'    => 'http',
			'tg_proxy_host'    => '',
			'tg_proxy_port'    => '',
			'tg_proxy_user'    => '',
			'tg_proxy_pass'    => '',
			'tg_proxy_hosts'   => array( 'telegram' ),

			// عیب‌یابی شبکه (خطای cURL error 28).
			'tg_force_ipv4'      => 0,
			'tg_connect_timeout' => 0,

			// پل ایمیل.
			'email_bridge'         => 0,
			'email_bridge_targets' => array( 'bale', 'eitaa' ),
			'email_subject_filter' => '',

			// اتصال به افزونه‌های فرم و فروشگاه‌ساز.
			'integ_cf7'       => 0,
			'integ_wpforms'   => 0,
			'integ_gravity'   => 0,
			'integ_wc'        => 0,
			'integ_ninja'     => 0,
			'integ_fluent'    => 0,
			'integ_elementor' => 0,

			// رویدادهای وردپرس.
			'notify_publish' => 0,

			// صف ارسال (Action Scheduler / WP-Cron + Retry).
			'queue_enabled' => 1,
		);
	}

	/**
	 * خواندن تنظیمات از دیتابیس (همراه با پیش‌فرض‌ها).
	 *
	 * @return array
	 */
	public static function get_options() {
		$options = get_option( self::OPTION_KEY, array() );
		$options = wp_parse_args( $options, self::defaults() );

		// امنیت (SEC): اولویت خواندن اعتبارنامه‌ها از ثابت‌های wp-config.php
		// تا توکن‌ها به‌صورت Plain Text فقط در دیتابیس نباشند.
		$constants = array(
			'bale_token'    => 'BEI_BALE_TOKEN',
			'bale_chat_id'  => 'BEI_BALE_CHAT_ID',
			'eitaa_token'   => 'BEI_EITAA_TOKEN',
			'eitaa_chat_id' => 'BEI_EITAA_CHAT_ID',
			'tg_token'      => 'BEI_TG_TOKEN',
			'tg_chat_id'    => 'BEI_TG_CHAT_ID',
			'tg_api_base'   => 'BEI_TG_API_BASE',
			'tg_relay_key'  => 'BEI_TG_RELAY_KEY',
			'wa_instance'   => 'BEI_WA_INSTANCE',
			'wa_token'      => 'BEI_WA_TOKEN',
			'wa_chat_id'    => 'BEI_WA_CHAT_ID',
		);

		foreach ( $constants as $key => $constant ) {
			if ( defined( $constant ) ) {
				$options[ $key ] = constant( $constant );
			}
		}

		return $options;
	}

	/**
	 * ثبت صفحه در منوی «تنظیمات».
	 */
	public function add_menu_page() {
		add_menu_page(
			__( 'اعلان‌رسان بله و ایتا', 'bale-eitaa-notifier' ),
			__( 'اعلان‌رسان پیام‌رسان‌ها', 'bale-eitaa-notifier' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			81
		);
	}

	/**
	 * ثبت گزینه‌ها با Settings API.
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * بارگذاری استایل و اسکریپت فقط در صفحات خود افزونه.
	 *
	 * @param string $hook_suffix شناسه صفحه جاری.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'bei-settings' )
			&& false === strpos( $hook_suffix, 'bei-woocommerce' )
			&& false === strpos( $hook_suffix, 'bei-elementor' ) ) {
			return;
		}

		wp_enqueue_style( 'bei-admin', BEI_PLUGIN_URL . 'assets/css/admin.css', array(), BEI_VERSION );
		wp_enqueue_script( 'bei-admin', BEI_PLUGIN_URL . 'assets/js/admin.js', array(), BEI_VERSION, true );
	}

	/**
	 * پاک‌سازی و اعتبارسنجی ورودی‌های تنظیمات.
	 *
	 * @param array $input ورودی خام.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$clean = self::defaults();

		$clean['bale_token']    = isset( $input['bale_token'] ) ? sanitize_text_field( wp_unslash( $input['bale_token'] ) ) : '';
		$clean['bale_chat_id']  = isset( $input['bale_chat_id'] ) ? sanitize_textarea_field( wp_unslash( $input['bale_chat_id'] ) ) : '';
		$clean['bale_bot_username'] = isset( $input['bale_bot_username'] ) ? sanitize_text_field( wp_unslash( $input['bale_bot_username'] ) ) : '';
		$clean['bale_business'] = empty( $input['bale_business'] ) ? 0 : 1;
		$clean['eitaa_token']   = isset( $input['eitaa_token'] ) ? sanitize_text_field( wp_unslash( $input['eitaa_token'] ) ) : '';
		$clean['eitaa_chat_id'] = isset( $input['eitaa_chat_id'] ) ? sanitize_textarea_field( wp_unslash( $input['eitaa_chat_id'] ) ) : '';
		$clean['tg_token']      = isset( $input['tg_token'] ) ? sanitize_text_field( wp_unslash( $input['tg_token'] ) ) : '';
		$clean['tg_chat_id']    = isset( $input['tg_chat_id'] ) ? sanitize_textarea_field( wp_unslash( $input['tg_chat_id'] ) ) : '';
		$clean['tg_bot_username'] = isset( $input['tg_bot_username'] ) ? sanitize_text_field( wp_unslash( $input['tg_bot_username'] ) ) : '';
		$clean['tg_api_base']   = isset( $input['tg_api_base'] ) ? esc_url_raw( wp_unslash( $input['tg_api_base'] ) ) : '';
		$clean['tg_relay_key']  = isset( $input['tg_relay_key'] ) ? sanitize_text_field( wp_unslash( $input['tg_relay_key'] ) ) : '';

		// واتساپ.
		$clean['wa_gateway']  = ( isset( $input['wa_gateway'] ) && in_array( $input['wa_gateway'], array( 'callmebot', 'greenapi', 'ultramsg', 'meta' ), true ) ) ? $input['wa_gateway'] : 'callmebot';
		$clean['wa_instance'] = isset( $input['wa_instance'] ) ? sanitize_text_field( wp_unslash( $input['wa_instance'] ) ) : '';
		$clean['wa_token']    = isset( $input['wa_token'] ) ? sanitize_text_field( wp_unslash( $input['wa_token'] ) ) : '';
		$clean['wa_chat_id']  = isset( $input['wa_chat_id'] ) ? sanitize_text_field( wp_unslash( $input['wa_chat_id'] ) ) : '';
		$clean['wa_api_base'] = isset( $input['wa_api_base'] ) ? esc_url_raw( wp_unslash( $input['wa_api_base'] ) ) : '';

		// پراکسی.
		$clean['tg_proxy_enabled'] = empty( $input['tg_proxy_enabled'] ) ? 0 : 1;
		$clean['tg_proxy_type']    = ( isset( $input['tg_proxy_type'] ) && 'socks5' === $input['tg_proxy_type'] ) ? 'socks5' : 'http';
		$clean['tg_proxy_host']    = isset( $input['tg_proxy_host'] ) ? sanitize_text_field( wp_unslash( $input['tg_proxy_host'] ) ) : '';
		$clean['tg_proxy_port']    = isset( $input['tg_proxy_port'] ) ? absint( $input['tg_proxy_port'] ) : '';
		$clean['tg_proxy_user']    = isset( $input['tg_proxy_user'] ) ? sanitize_text_field( wp_unslash( $input['tg_proxy_user'] ) ) : '';
		$clean['tg_proxy_pass']    = isset( $input['tg_proxy_pass'] ) ? sanitize_text_field( wp_unslash( $input['tg_proxy_pass'] ) ) : '';

		$clean['tg_proxy_hosts'] = array();
		if ( ! empty( $input['tg_proxy_hosts'] ) && is_array( $input['tg_proxy_hosts'] ) ) {
			foreach ( $input['tg_proxy_hosts'] as $host_target ) {
				$host_target = sanitize_key( wp_unslash( $host_target ) );
				if ( in_array( $host_target, array( 'telegram', 'bale', 'eitaa', 'greenapi', 'ultramsg', 'meta', 'callmebot' ), true ) ) {
					$clean['tg_proxy_hosts'][] = $host_target;
				}
			}
		}
		if ( empty( $clean['tg_proxy_hosts'] ) ) {
			$clean['tg_proxy_hosts'] = array( 'telegram' );
		}

		// عیب‌یابی شبکه.
		$clean['tg_force_ipv4'] = empty( $input['tg_force_ipv4'] ) ? 0 : 1;
		$clean['tg_connect_timeout'] = isset( $input['tg_connect_timeout'] ) ? min( 120, absint( $input['tg_connect_timeout'] ) ) : 0;

		// پل ایمیل.
		$clean['email_bridge'] = empty( $input['email_bridge'] ) ? 0 : 1;

		$clean['email_bridge_targets'] = array();
		if ( ! empty( $input['email_bridge_targets'] ) && is_array( $input['email_bridge_targets'] ) ) {
			foreach ( $input['email_bridge_targets'] as $target ) {
				$target = sanitize_key( wp_unslash( $target ) );
				if ( in_array( $target, array( 'bale', 'eitaa', 'telegram', 'whatsapp' ), true ) ) {
					$clean['email_bridge_targets'][] = $target;
				}
			}
		}
		if ( empty( $clean['email_bridge_targets'] ) ) {
			$clean['email_bridge_targets'] = array( 'bale', 'eitaa' );
		}

		$clean['email_subject_filter'] = isset( $input['email_subject_filter'] ) ? sanitize_textarea_field( wp_unslash( $input['email_subject_filter'] ) ) : '';

		// اتصال‌ها.
		foreach ( array( 'integ_cf7', 'integ_wpforms', 'integ_gravity', 'integ_wc', 'integ_ninja', 'integ_fluent', 'integ_elementor' ) as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$clean['notify_publish'] = empty( $input['notify_publish'] ) ? 0 : 1;

		$clean['queue_enabled'] = empty( $input['queue_enabled'] ) ? 0 : 1;

		return $clean;
	}

	/**
	 * فهرست اتصال‌های آماده (برای نمایش در صفحه).
	 *
	 * @return array
	 */
	private function integration_rows() {
		return array(
			array(
				'key'       => 'integ_cf7',
				'name'      => __( 'Contact Form 7', 'bale-eitaa-notifier' ),
				'hook'      => 'wpcf7_mail_sent',
				'installed' => class_exists( 'WPCF7_ContactForm' ),
			),
			array(
				'key'       => 'integ_wpforms',
				'name'      => __( 'WPForms', 'bale-eitaa-notifier' ),
				'hook'      => 'wpforms_process_complete',
				'installed' => function_exists( 'wpforms' ),
			),
			array(
				'key'       => 'integ_gravity',
				'name'      => __( 'Gravity Forms', 'bale-eitaa-notifier' ),
				'hook'      => 'gform_after_submission',
				'installed' => class_exists( 'GFSettings' ),
			),
			array(
				'key'       => 'integ_ninja',
				'name'      => __( 'Ninja Forms', 'bale-eitaa-notifier' ),
				'hook'      => 'ninja_forms_after_submission',
				'installed' => class_exists( 'Ninja_Forms' ),
			),
			array(
				'key'       => 'integ_fluent',
				'name'      => __( 'Fluent Forms', 'bale-eitaa-notifier' ),
				'hook'      => 'fluentform_submission_inserted',
				'installed' => defined( 'FLUENTFORM' ),
			),
			array(
				'key'       => 'integ_elementor',
				'name'      => __( 'فرم المنتور (Elementor Pro)', 'bale-eitaa-notifier' ),
				'hook'      => 'elementor_pro/forms/new_record',
				'note'      => __( 'لیست فرم‌ها و تنظیمات ارسال در منوی فرعی «فرم‌های المنتور»', 'bale-eitaa-notifier' ),
				'installed' => defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( 'ElementorPro\Plugin' ) || class_exists( 'ElementorPro_Plugin' ),
			),
			array(
				'key'       => 'integ_wc',
				'name'      => __( 'ووکامرس', 'bale-eitaa-notifier' ),
				'hook'      => 'woocommerce_order_status_changed',
				'note'      => __( 'اعلان‌ها در منوی فرعی «ووکامرس» بر اساس وضعیت‌ها تنظیم می‌شوند', 'bale-eitaa-notifier' ),
				'installed' => class_exists( 'WooCommerce' ),
			),
		);
	}

	/**
	 * چاپ یک سوییچ (checkbox) استایل‌شده.
	 *
	 * @param string $name     ویژگی name ورودی.
	 * @param string $value    مقدار ورودی.
	 * @param bool   $checked  وضعیت انتخاب.
	 * @param string $label    متن برچسب (اختیاری).
	 * @param bool   $disabled غیرفعال‌سازی ورودی (مثلاً وقتی افزونه مقصد نصب نیست).
	 */
	private function render_switch( $name, $value, $checked, $label = '', $disabled = false ) {
		printf(
			'<label class="bei-switch%1$s"><input type="checkbox" name="%2$s" value="%3$s"%4$s%5$s /><span class="bei-track" aria-hidden="true"></span>%6$s</label>',
			$disabled ? ' bei-switch--disabled' : '',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			$disabled ? ' disabled' : '',
			$label ? '<span class="bei-switch-label">' . esc_html( $label ) . '</span>' : ''
		);
	}

	/**
	 * نمایش اعلان نتیجه تست (ترنزینت یک‌بارمصرف).
	 */
	private function render_test_notice() {
		$result = get_transient( 'bei_test_result' );
		if ( false === $result ) {
			return;
		}
		delete_transient( 'bei_test_result' );

		if ( 'error' === $result[0] ) {
			printf(
				'<div class="bei-notice bei-notice--error"><strong>%s</strong> %s</div>',
				esc_html__( 'خطا در ارسال:', 'bale-eitaa-notifier' ),
				esc_html( $result[1] )
			);
		} else {
			printf(
				'<div class="bei-notice bei-notice--success"><strong>%s</strong> <code>%s</code></div>',
				esc_html__( 'پیام با موفقیت ارسال شد ✅', 'bale-eitaa-notifier' ),
				esc_html( $result[1] )
			);
		}
	}

	/**
	 * نمایش صفحه تنظیمات.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options  = self::get_options();
		$rest_url = get_rest_url( null, 'bei/v1/notify' );
		?>
		<div class="wrap bei-wrap" dir="rtl">

			<header class="bei-header">
				<div>
					<h1>🚀 <?php esc_html_e( 'اعلان‌رسان بله و ایتا', 'bale-eitaa-notifier' ); ?></h1>
					<p class="bei-subtitle"><?php esc_html_e( 'ارسال خودکار پیام به‌جای تلگرام — پل ایمیل، اتصال به فرم‌ها و ووکامرس، API خارجی', 'bale-eitaa-notifier' ); ?></p>
				</div>
				<div class="bei-header-meta">
					<span class="bei-badge"><?php printf( /* translators: %s: نسخه افزونه */ esc_html__( 'نسخه %s', 'bale-eitaa-notifier' ), esc_html( BEI_VERSION ) ); ?></span>
				</div>
			</header>

			<?php settings_errors( self::PAGE_SLUG ); ?>
			<?php $this->render_test_notice(); ?>

			<div class="bei-grid">
				<div class="bei-main">
					<form method="post" action="options.php">
						<?php settings_fields( self::SETTINGS_GROUP ); ?>

						<!-- پیکربندی بله -->
						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--bale">🟢</span>
								<div>
									<h2><?php esc_html_e( 'پیکربندی بله', 'bale-eitaa-notifier' ); ?></h2>
									<p class="bei-hint">
										<?php
										printf(
											/* translators: %s: آدرس ربات‌ساز */
											esc_html__( 'ربات را با @botfather بسازید (%s) و آن را ادمین کانال/گروه کنید.', 'bale-eitaa-notifier' ),
											'ble.ir/botfather'
										);
										?>
									</p>
								</div>
							</div>
							<div class="bei-card-body">
								<div class="bei-field">
									<label class="bei-label" for="bei-bale-token"><?php esc_html_e( 'توکن ربات', 'bale-eitaa-notifier' ); ?></label>
									<input id="bei-bale-token" class="bei-input bei-input--ltr" type="text" dir="ltr"
										name="bei_options[bale_token]" value="<?php echo esc_attr( $options['bale_token'] ); ?>"
										placeholder="123456789:abcd..." autocomplete="off" spellcheck="false" />
								</div>
								<div class="bei-field">
									<label class="bei-label" for="bei-bale-chat"><?php esc_html_e( 'شناسه گفتگو (chat_id) — هر شناسه در یک خط', 'bale-eitaa-notifier' ); ?></label>
									<textarea id="bei-bale-chat" class="bei-input bei-input--ltr bei-input--area" rows="2" dir="ltr"
										name="bei_options[bale_chat_id]" spellcheck="false"
										placeholder="@myChannel"><?php echo esc_textarea( $options['bale_chat_id'] ); ?></textarea>
									<p class="bei-hint"><?php esc_html_e( 'چند شناسه مجاز است — هر شناسه (عددی یا @username) در یک خط', 'bale-eitaa-notifier' ); ?></p>
								</div>
								<div class="bei-field">
									<label class="bei-label" for="bei-bale-bot"><?php esc_html_e( 'نام کاربری ربات (برای لینک فعال‌سازی اعلان مشتری)', 'bale-eitaa-notifier' ); ?></label>
									<input id="bei-bale-bot" class="bei-input bei-input--ltr" type="text" dir="ltr"
										name="bei_options[bale_bot_username]" value="<?php echo esc_attr( $options['bale_bot_username'] ); ?>"
										placeholder="my_bot" spellcheck="false" />
								</div>
								<div class="bei-field">
									<?php $this->render_switch( 'bei_options[bale_business]', '1', ! empty( $options['bale_business'] ), __( 'استفاده از API کسب‌وکاری بله', 'bale-eitaa-notifier' ) ); ?>
									<p class="bei-hint"><?php esc_html_e( 'سقف ارسال بالاتر برای اطلاع‌رسانی انبوه — نیازمند شارژ حساب در business.bale.ai', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
						</section>

						<!-- پیکربندی ایتا -->
						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--eitaa">🟣</span>
								<div>
									<h2><?php esc_html_e( 'پیکربندی ایتا', 'bale-eitaa-notifier' ); ?></h2>
									<p class="bei-hint">
										<?php
										printf(
											/* translators: %s: آدرس پنل ایتایار */
											esc_html__( 'توکن را از پنل ایتایار (%s) یا @botfather ایتا بگیرید و ربات را ادمین کانال/گروه کنید.', 'bale-eitaa-notifier' ),
											'eitaayar.ir'
										);
										?>
									</p>
								</div>
							</div>
							<div class="bei-card-body">
								<div class="bei-field">
									<label class="bei-label" for="bei-eitaa-token"><?php esc_html_e( 'توکن ربات', 'bale-eitaa-notifier' ); ?></label>
									<input id="bei-eitaa-token" class="bei-input bei-input--ltr" type="text" dir="ltr"
										name="bei_options[eitaa_token]" value="<?php echo esc_attr( $options['eitaa_token'] ); ?>"
										placeholder="bot123456:uuid..." autocomplete="off" spellcheck="false" />
								</div>
								<div class="bei-field">
									<label class="bei-label" for="bei-eitaa-chat"><?php esc_html_e( 'شناسه چت (chat_id) — هر شناسه در یک خط', 'bale-eitaa-notifier' ); ?></label>
									<textarea id="bei-eitaa-chat" class="bei-input bei-input--ltr bei-input--area" rows="2" dir="ltr"
										name="bei_options[eitaa_chat_id]" spellcheck="false"
										placeholder="myChannel"><?php echo esc_textarea( $options['eitaa_chat_id'] ); ?></textarea>
									<p class="bei-hint"><?php esc_html_e( 'چند شناسه مجاز است — شناسه عددی یا username بدون @ (گروه: لینک دعوت)', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
						</section>

						<!-- پیکربندی تلگرام -->
						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--tg">🟦</span>
								<div>
									<h2><?php esc_html_e( 'پیکربندی تلگرام', 'bale-eitaa-notifier' ); ?></h2>
									<p class="bei-hint">
										<?php
										printf(
											/* translators: %s: آدرس بات‌فادر */
											esc_html__( 'ربات را با @BotFather تلگرام بسازید (%s) و آن را ادمین کانال/گروه کنید. اگر سرور سایت داخل ایران است، از بخش «پراکسی» یا آدرس API جایگزین استفاده کنید.', 'bale-eitaa-notifier' ),
											't.me/BotFather'
										);
										?>
									</p>
								</div>
							</div>
							<div class="bei-card-body">
								<div class="bei-field">
									<label class="bei-label" for="bei-tg-token"><?php esc_html_e( 'توکن ربات', 'bale-eitaa-notifier' ); ?></label>
									<input id="bei-tg-token" class="bei-input bei-input--ltr" type="text" dir="ltr"
										name="bei_options[tg_token]" value="<?php echo esc_attr( $options['tg_token'] ); ?>"
										placeholder="123456789:AAF..." autocomplete="off" spellcheck="false" />
								</div>
								<div class="bei-field">
									<label class="bei-label" for="bei-tg-chat"><?php esc_html_e( 'شناسه گفتگو (chat_id) — هر شناسه در یک خط', 'bale-eitaa-notifier' ); ?></label>
									<textarea id="bei-tg-chat" class="bei-input bei-input--ltr bei-input--area" rows="2" dir="ltr"
										name="bei_options[tg_chat_id]" spellcheck="false"
										placeholder="@myChannel"><?php echo esc_textarea( $options['tg_chat_id'] ); ?></textarea>
									<p class="bei-hint"><?php esc_html_e( 'چند شناسه مجاز است — برای گروه خصوصی از «شناسه‌یاب» استفاده کنید', 'bale-eitaa-notifier' ); ?></p>
								</div>
								<div class="bei-field">
									<label class="bei-label" for="bei-tg-bot"><?php esc_html_e( 'نام کاربری ربات (برای لینک فعال‌سازی اعلان مشتری)', 'bale-eitaa-notifier' ); ?></label>
									<input id="bei-tg-bot" class="bei-input bei-input--ltr" type="text" dir="ltr"
										name="bei_options[tg_bot_username]" value="<?php echo esc_attr( $options['tg_bot_username'] ); ?>"
										placeholder="my_bot" spellcheck="false" />
								</div>
								<div class="bei-field">
									<label class="bei-label" for="bei-tg-base"><?php esc_html_e( 'آدرس API (سفارشی/رله)', 'bale-eitaa-notifier' ); ?></label>
									<input id="bei-tg-base" class="bei-input bei-input--ltr" type="text" dir="ltr"
										name="bei_options[tg_api_base]" value="<?php echo esc_attr( $options['tg_api_base'] ); ?>"
										placeholder="https://api.telegram.org" spellcheck="false" />
									<p class="bei-hint"><?php esc_html_e( 'خالی = api.telegram.org. برای دور زدن فیلترینگ می‌توانید آدرس یک سرور رله (Reverse Proxy) که روی سرور خارج از ایران دارید را وارد کنید — مسیرها باید همانند api.telegram.org حفظ شوند.', 'bale-eitaa-notifier' ); ?></p>
								</div>
								<div class="bei-field">
									<label class="bei-label" for="bei-tg-key"><?php esc_html_e( 'کلید امنیتی رله (اختیاری)', 'bale-eitaa-notifier' ); ?></label>
									<input id="bei-tg-key" class="bei-input bei-input--ltr" type="text" dir="ltr"
										name="bei_options[tg_relay_key]" value="<?php echo esc_attr( $options['tg_relay_key'] ); ?>"
										placeholder="RELAY_KEY در فایل worker" autocomplete="off" spellcheck="false" />
									<p class="bei-hint"><?php esc_html_e( 'اگر در فایل telegram-relay-worker.js مقدار RELAY_KEY تنظیم کرده‌اید، همان را اینجا وارد کنید — افزونه آن را خودکار به همه درخواست‌های تلگرام اضافه می‌کند (چند سایت می‌توانند با یک کلید مشترک از یک رله استفاده کنند).', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
						</section>

						<!-- پیکربندی واتساپ -->
						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--wa">💬</span>
								<div>
									<h2><?php esc_html_e( 'پیکربندی واتساپ (مسیر رایگان)', 'bale-eitaa-notifier' ); ?></h2>
									<p class="bei-hint"><?php esc_html_e( 'دو مسیر رایگان: CallMeBot (پیام به شماره خودتان، بدون ثبت‌نام) و «شماره تست» رایگان متا (بدون قالب، تا ۵ شماره). گزینه‌های Green API و Ultramsg پولی‌اند و فقط برای ارسال انبوه.', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
							<div class="bei-card-body">
								<div class="bei-row">
									<div class="bei-field">
										<label class="bei-label" for="bei-wa-gateway"><?php esc_html_e( 'درگاه', 'bale-eitaa-notifier' ); ?></label>
										<select id="bei-wa-gateway" class="bei-select" name="bei_options[wa_gateway]">
											<option value="callmebot" <?php selected( $options['wa_gateway'], 'callmebot' ); ?>>CallMeBot — رایگان</option>
											<option value="meta" <?php selected( $options['wa_gateway'], 'meta' ); ?>>Meta Cloud API — شماره تست رایگان</option>
											<option value="greenapi" <?php selected( $options['wa_gateway'], 'greenapi' ); ?>>Green API — پولی</option>
											<option value="ultramsg" <?php selected( $options['wa_gateway'], 'ultramsg' ); ?>>Ultramsg — پولی</option>
										</select>
									</div>
									<div class="bei-field">
										<label class="bei-label" for="bei-wa-instance"><?php esc_html_e( 'شناسه نمونه', 'bale-eitaa-notifier' ); ?></label>
										<input id="bei-wa-instance" class="bei-input bei-input--ltr" type="text" dir="ltr"
											name="bei_options[wa_instance]" value="<?php echo esc_attr( $options['wa_instance'] ); ?>"
											placeholder="apikey / apiTokenInstance / Phone Number ID" spellcheck="false" />
									</div>
								</div>
								<div class="bei-row">
									<div class="bei-field">
										<label class="bei-label" for="bei-wa-token"><?php esc_html_e( 'توکن درگاه', 'bale-eitaa-notifier' ); ?></label>
										<input id="bei-wa-token" class="bei-input bei-input--ltr" type="text" dir="ltr"
											name="bei_options[wa_token]" value="<?php echo esc_attr( $options['wa_token'] ); ?>"
											placeholder="apikey / token / Access Token" autocomplete="off" spellcheck="false" />
									</div>
									<div class="bei-field">
										<label class="bei-label" for="bei-wa-chat"><?php esc_html_e( 'شماره مقصد (chatId) — هر شماره در یک خط', 'bale-eitaa-notifier' ); ?></label>
										<textarea id="bei-wa-chat" class="bei-input bei-input--ltr bei-input--area" rows="2" dir="ltr"
											name="bei_options[wa_chat_id]" spellcheck="false"
											placeholder="7912xxxxxxx@c.us"><?php echo esc_textarea( $options['wa_chat_id'] ); ?></textarea>
										<p class="bei-hint"><?php esc_html_e( 'چند شماره مجاز است — CallMeBot: فقط شماره‌های فعال‌سازی‌شده توسط خودتان', 'bale-eitaa-notifier' ); ?></p>
									</div>
								</div>
								<div class="bei-field">
									<label class="bei-label" for="bei-wa-base"><?php esc_html_e( 'آدرس API (سفارشی/رله)', 'bale-eitaa-notifier' ); ?></label>
									<input id="bei-wa-base" class="bei-input bei-input--ltr" type="text" dir="ltr"
										name="bei_options[wa_api_base]" value="<?php echo esc_attr( $options['wa_api_base'] ); ?>"
										placeholder="https://wa-relay.example.com" spellcheck="false" />
									<p class="bei-hint"><?php esc_html_e( 'خالی = آدرس خود درگاه. اگر درگاه از سرور ایران باز نمی‌شود، آدرس رله (مثل رله تلگرام) را وارد کنید.', 'bale-eitaa-notifier' ); ?></p>
								</div>
								<div class="bei-info-box">
									<strong><?php esc_html_e( '🆓 مسیر ۱ — CallMeBot (رایگان، ساده‌ترین):', 'bale-eitaa-notifier' ); ?></strong><br />
									<?php esc_html_e( '۱) شماره ربات CallMeBot را در واتساپ ذخیره کنید و پیام «I allow callmebot to send me messages» بفرستید — apikey رایگان می‌گیرید (راهنما: callmebot.com/blog/free-api-whatsapp-messages).', 'bale-eitaa-notifier' ); ?><br />
									<?php esc_html_e( '۲) apikey را در «توکن درگاه» و شماره خودتان را (با کد کشور) در «شماره مقصد» بگذارید — تمام! پیام‌ها به واتساپ خودتان می‌رسد.', 'bale-eitaa-notifier' ); ?><br />
									<strong><?php esc_html_e( '🆓 مسیر ۲ — شماره تست رایگان متا (رسمی):', 'bale-eitaa-notifier' ); ?></strong><br />
									<?php esc_html_e( '۳) در business.facebook.com یک اکانت بیزینس رایگان بسازید و از بخش WhatsApp → API Setup «شماره تست» رایگان بگیرید (Phone Number ID + توکن).', 'bale-eitaa-notifier' ); ?><br />
									<?php esc_html_e( '۴) تا ۵ شماره را در بخش To (آدرس گیرنده) تأیید کنید — ارسال به این شماره‌ها بدون قالب و بدون هزینه است.', 'bale-eitaa-notifier' ); ?><br />
									<?php esc_html_e( 'نکته: شماره واتساپ با +98 پشتیبانی نمی‌شود؛ و برای سرور داخل ایران، آدرس رله یا پراکسی لازم است.', 'bale-eitaa-notifier' ); ?>
								</div>
							</div>
						</section>

<!-- پراکسی (دور زدن فیلترینگ) -->
						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--proxy">🌐</span>
								<div>
									<h2><?php esc_html_e( 'پراکسی — دور زدن فیلترینگ', 'bale-eitaa-notifier' ); ?></h2>
									<p class="bei-hint"><?php esc_html_e( 'برای سرورهای داخل ایران که به api.telegram.org دسترسی ندارند. پراکسی فقط روی درخواست‌های پیام‌رسان‌های انتخاب‌شده اعمال می‌شود.', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
							<div class="bei-card-body">
								<div class="bei-field">
									<?php $this->render_switch( 'bei_options[tg_proxy_enabled]', '1', ! empty( $options['tg_proxy_enabled'] ), __( 'فعال‌سازی پراکسی برای درخواست‌های API', 'bale-eitaa-notifier' ) ); ?>
								</div>
								<div class="bei-row">
									<div class="bei-field">
										<label class="bei-label" for="bei-proxy-type"><?php esc_html_e( 'نوع پراکسی', 'bale-eitaa-notifier' ); ?></label>
										<select id="bei-proxy-type" class="bei-select" name="bei_options[tg_proxy_type]">
											<option value="http" <?php selected( $options['tg_proxy_type'], 'http' ); ?>>HTTP</option>
											<option value="socks5" <?php selected( $options['tg_proxy_type'], 'socks5' ); ?>>SOCKS5</option>
										</select>
									</div>
									<div class="bei-field">
										<label class="bei-label" for="bei-proxy-host"><?php esc_html_e( 'آدرس پراکسی', 'bale-eitaa-notifier' ); ?></label>
										<input id="bei-proxy-host" class="bei-input bei-input--ltr" type="text" dir="ltr"
											name="bei_options[tg_proxy_host]" value="<?php echo esc_attr( $options['tg_proxy_host'] ); ?>"
											placeholder="1.2.3.4" spellcheck="false" />
									</div>
									<div class="bei-field">
										<label class="bei-label" for="bei-proxy-port"><?php esc_html_e( 'پورت', 'bale-eitaa-notifier' ); ?></label>
										<input id="bei-proxy-port" class="bei-input bei-input--ltr bei-input--short" type="text" dir="ltr"
											name="bei_options[tg_proxy_port]" value="<?php echo esc_attr( $options['tg_proxy_port'] ); ?>"
											placeholder="1080" spellcheck="false" />
									</div>
								</div>
								<div class="bei-row">
									<div class="bei-field">
										<label class="bei-label" for="bei-proxy-user"><?php esc_html_e( 'نام کاربری (اختیاری)', 'bale-eitaa-notifier' ); ?></label>
										<input id="bei-proxy-user" class="bei-input bei-input--ltr" type="text" dir="ltr"
											name="bei_options[tg_proxy_user]" value="<?php echo esc_attr( $options['tg_proxy_user'] ); ?>" autocomplete="off" />
									</div>
									<div class="bei-field">
										<label class="bei-label" for="bei-proxy-pass"><?php esc_html_e( 'رمز عبور (اختیاری)', 'bale-eitaa-notifier' ); ?></label>
										<input id="bei-proxy-pass" class="bei-input bei-input--ltr" type="password" dir="ltr"
											name="bei_options[tg_proxy_pass]" value="<?php echo esc_attr( $options['tg_proxy_pass'] ); ?>" autocomplete="new-password" />
									</div>
								</div>
								<div class="bei-field">
									<span class="bei-label"><?php esc_html_e( 'دامنه‌های هدف (پراکسی و تنظیمات شبکه):', 'bale-eitaa-notifier' ); ?></span>
									<div class="bei-options-row">
										<?php $this->render_switch( 'bei_options[tg_proxy_hosts][]', 'telegram', in_array( 'telegram', $options['tg_proxy_hosts'], true ), __( 'تلگرام', 'bale-eitaa-notifier' ) ); ?>
										<?php $this->render_switch( 'bei_options[tg_proxy_hosts][]', 'bale', in_array( 'bale', $options['tg_proxy_hosts'], true ), __( 'بله', 'bale-eitaa-notifier' ) ); ?>
										<?php $this->render_switch( 'bei_options[tg_proxy_hosts][]', 'eitaa', in_array( 'eitaa', $options['tg_proxy_hosts'], true ), __( 'ایتا', 'bale-eitaa-notifier' ) ); ?>
										<?php $this->render_switch( 'bei_options[tg_proxy_hosts][]', 'greenapi', in_array( 'greenapi', $options['tg_proxy_hosts'], true ), __( 'واتساپ (Green API)', 'bale-eitaa-notifier' ) ); ?>
										<?php $this->render_switch( 'bei_options[tg_proxy_hosts][]', 'ultramsg', in_array( 'ultramsg', $options['tg_proxy_hosts'], true ), __( 'واتساپ (Ultramsg)', 'bale-eitaa-notifier' ) ); ?>
										<?php $this->render_switch( 'bei_options[tg_proxy_hosts][]', 'meta', in_array( 'meta', $options['tg_proxy_hosts'], true ), __( 'واتساپ (Meta)', 'bale-eitaa-notifier' ) ); ?>
										<?php $this->render_switch( 'bei_options[tg_proxy_hosts][]', 'callmebot', in_array( 'callmebot', $options['tg_proxy_hosts'], true ), __( 'واتساپ (CallMeBot)', 'bale-eitaa-notifier' ) ); ?>
									</div>
								</div>
								<div class="bei-field">
									<span class="bei-label"><?php esc_html_e( 'عیب‌یابی شبکه (برای خطای cURL error 28 — سرور به api.* وصل نمی‌شود):', 'bale-eitaa-notifier' ); ?></span>
									<?php $this->render_switch( 'bei_options[tg_force_ipv4]', '1', ! empty( $options['tg_force_ipv4'] ), __( 'فورس IPv4', 'bale-eitaa-notifier' ) ); ?>
									<p class="bei-hint"><?php esc_html_e( 'مهلت برقراری اتصال (ثانیه — خالی = پیش‌فرض ۱۰):', 'bale-eitaa-notifier' ); ?></p>
									<input id="bei-connect-timeout" class="bei-input bei-input--ltr bei-input--short" type="text" dir="ltr"
										name="bei_options[tg_connect_timeout]" value="<?php echo esc_attr( $options['tg_connect_timeout'] ? $options['tg_connect_timeout'] : '' ); ?>"
										placeholder="30" spellcheck="false" />
									<p class="bei-hint"><?php esc_html_e( 'نکته: این تنظیمات فقط روی درخواست‌های دامنه‌های تیک‌خورده بالا اعمال می‌شوند. اگر سرور به خود api.* دسترسی ندارد، راه قطعی «رله» است (فایل callmebot-relay-worker.js آماده در پروژه).', 'bale-eitaa-notifier' ); ?></p>
								</div>
								<div class="bei-info-box">
									<strong><?php esc_html_e( 'راهنمای استفاده از مقادیر پنل Nova-Proxy (بخش تنظیمات پراکسی):', 'bale-eitaa-notifier' ); ?></strong><br />
									<?php esc_html_e( '• «SOCKS5 با احراز هویت» → نوع: SOCKS5 — آدرس/پورت و نام کاربری/رمز پنل را در همین فیلدها وارد کنید.', 'bale-eitaa-notifier' ); ?><br />
									<?php esc_html_e( '• «HTTP با احراز هویت» → نوع: HTTP — همان مقادیر.', 'bale-eitaa-notifier' ); ?><br />
									<?php esc_html_e( '• اگر پنل آدرس کامل (مثل socks5://user:pass@ip:port) می‌دهد، می‌توانید کل آن را یکجا در «آدرس پراکسی» بگذارید.', 'bale-eitaa-notifier' ); ?><br />
									<?php esc_html_e( '• بعد از ذخیره، از دکمه «🌐 تست پراکسی» در کارت تست اتصال استفاده کنید (بدون نیاز به توکن).', 'bale-eitaa-notifier' ); ?><br />
									<?php esc_html_e( '• اگر پراکسی روی دامنه کلودفلری است و از سرور ایران باز نمی‌شود، یک IP تمیز در /etc/hosts سرور قرار دهید.', 'bale-eitaa-notifier' ); ?><br /><br />
									<strong><?php esc_html_e( 'راهکارهای دیگر دور زدن فیلترینگ:', 'bale-eitaa-notifier' ); ?></strong><br />
									<?php esc_html_e( '۱) اگر سایت روی سرور خارج از ایران است، هیچ اقدامی لازم نیست — فیلترینگ فقط دستگاه‌های داخل ایران را محدود می‌کند.', 'bale-eitaa-notifier' ); ?><br />
									<?php esc_html_e( '۲) آدرس API جایگزین/رله (کارت تلگرام) — سرور شخصی شما خارج از ایران که api.telegram.org را پروکسی می‌کند (فایل telegram-relay-worker.js آماده در پروژه).', 'bale-eitaa-notifier' ); ?><br />
									<?php esc_html_e( '۳) ثابت‌های سراسری وردپرس در wp-config.php (برای HTTP Proxy):', 'bale-eitaa-notifier' ); ?>
									<code dir="ltr">define( 'WP_PROXY_HOST', '1.2.3.4' ); define( 'WP_PROXY_PORT', '1080' );</code>
								</div>
							</div>
						</section>

						<!-- پل ایمیل -->
						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--mail">📧</span>
								<div>
									<h2><?php esc_html_e( 'پل ایمیل سراسری', 'bale-eitaa-notifier' ); ?></h2>
									<p class="bei-hint"><?php esc_html_e( 'هر ایمیلی که هر افزونه‌ای بفرستد، همزمان به پیام‌رسان هم می‌رود — مثل سرویس پیامک متصل به ایمیل', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
							<div class="bei-card-body">
								<div class="bei-field">
									<?php $this->render_switch( 'bei_options[email_bridge]', '1', ! empty( $options['email_bridge'] ), __( 'فوروارد ایمیل‌های سایت به پیام‌رسان', 'bale-eitaa-notifier' ) ); ?>
								</div>
								<div class="bei-field">
									<span class="bei-label"><?php esc_html_e( 'مقصد ارسال:', 'bale-eitaa-notifier' ); ?></span>
									<div class="bei-options-row">
										<?php $this->render_switch( 'bei_options[email_bridge_targets][]', 'bale', in_array( 'bale', $options['email_bridge_targets'], true ), __( 'بله', 'bale-eitaa-notifier' ) ); ?>
										<?php $this->render_switch( 'bei_options[email_bridge_targets][]', 'eitaa', in_array( 'eitaa', $options['email_bridge_targets'], true ), __( 'ایتا', 'bale-eitaa-notifier' ) ); ?>
										<?php $this->render_switch( 'bei_options[email_bridge_targets][]', 'telegram', in_array( 'telegram', $options['email_bridge_targets'], true ), __( 'تلگرام', 'bale-eitaa-notifier' ) ); ?>
										<?php $this->render_switch( 'bei_options[email_bridge_targets][]', 'whatsapp', in_array( 'whatsapp', $options['email_bridge_targets'], true ), __( 'واتساپ', 'bale-eitaa-notifier' ) ); ?>
									</div>
								</div>
								<div class="bei-field">
									<label class="bei-label" for="bei-email-filter"><?php esc_html_e( 'فیلتر موضوع (اختیاری)', 'bale-eitaa-notifier' ); ?></label>
									<textarea id="bei-email-filter" class="bei-input bei-input--area" rows="3"
										name="bei_options[email_subject_filter]"
										placeholder="<?php esc_attr_e( 'هر خط = یک عبارت، مثلاً: سفارش جدید', 'bale-eitaa-notifier' ); ?>"><?php echo esc_textarea( $options['email_subject_filter'] ); ?></textarea>
									<p class="bei-hint"><?php esc_html_e( 'فقط ایمیل‌هایی ارسال می‌شوند که موضوعشان یکی از این عبارت‌ها را داشته باشد. خالی = ارسال همه.', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
						</section>

						<!-- صندوق ارسال (Queue) -->
						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--queue">🗂️</span>
								<div>
									<h2><?php esc_html_e( 'صندوق ارسال (Queue)', 'bale-eitaa-notifier' ); ?></h2>
									<p class="bei-hint"><?php esc_html_e( 'ارسال پیام‌ها خارج از Request اصلی سایت انجام می‌شود — فرم‌ها و سفارش‌ها کند نمی‌شوند و پیام‌های ناموفق تا ۳ بار با تأخیر تصاعدی دوباره تلاش می‌شوند.', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
							<div class="bei-card-body">
								<div class="bei-field">
									<?php $this->render_switch( 'bei_options[queue_enabled]', '1', ! empty( $options['queue_enabled'] ), __( 'ارسال ناهمزمان از طریق صف', 'bale-eitaa-notifier' ) ); ?>
									<p class="bei-hint"><?php esc_html_e( 'با Action Scheduler (ووکامرس) در پس‌زمینه واقعی اجرا می‌شود؛ بدون آن از WP-Cron تک‌زمانه استفاده می‌کند؛ در نبود هر دو، پیام مستقیم ارسال می‌شود (هیچ پیامی گم نمی‌شود). دکمه‌های «تست اتصال» همیشه مستقیم هستند تا نتیجه را همان لحظه ببینید.', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
						</section>

						<!-- اتصال به افزونه‌ها -->
						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--plug">🔌</span>
								<div>
									<h2><?php esc_html_e( 'اتصال به فرم‌ها و فروشگاه‌ساز', 'bale-eitaa-notifier' ); ?></h2>
									<p class="bei-hint"><?php esc_html_e( 'هر گزینه با یک سوییچ فعال می‌شود. سوییچ «ووکامرس» فقط نصب بودن آن را بررسی می‌کند و منوی فرعی «ووکامرس» را زیر منوی اصلی افزونه نمایان می‌سازد — تنظیم و فعال‌سازی اعلان‌ها بر اساس وضعیت‌های سفارش در همان منوی فرعی انجام می‌شود (هیچ پیام تکراری «سفارش جدید» ارسال نمی‌شود).', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
							<div class="bei-card-body">
								<?php foreach ( $this->integration_rows() as $row ) : ?>
									<div class="bei-integration">
									<div>
										<div class="bei-integration-name"><?php echo esc_html( $row['name'] ); ?></div>
										<div class="bei-hint"><code dir="ltr"><?php echo esc_html( $row['hook'] ); ?></code></div>
										<?php if ( ! empty( $row['note'] ) ) : ?>
											<div class="bei-hint"><?php echo esc_html( $row['note'] ); ?></div>
										<?php endif; ?>
									</div>
										<div class="bei-integration-side">
										<?php if ( $row['installed'] ) : ?>
											<span class="bei-chip bei-chip--ok"><?php esc_html_e( 'نصب است', 'bale-eitaa-notifier' ); ?></span>
										<?php else : ?>
											<span class="bei-chip bei-chip--warn"><?php esc_html_e( 'نصب نیست', 'bale-eitaa-notifier' ); ?></span>
										<?php endif; ?>
										<?php $this->render_switch( 'bei_options[' . $row['key'] . ']', '1', ! empty( $options[ $row['key'] ] ), '', empty( $row['installed'] ) ); ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</section>

						<!-- رویدادهای وردپرس -->
						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--wp">📢</span>
								<div>
									<h2><?php esc_html_e( 'رویدادهای وردپرس', 'bale-eitaa-notifier' ); ?></h2>
								</div>
							</div>
							<div class="bei-card-body">
								<div class="bei-field">
									<?php $this->render_switch( 'bei_options[notify_publish]', '1', ! empty( $options['notify_publish'] ), __( 'اطلاع‌رسانی هنگام انتشار نوشته جدید', 'bale-eitaa-notifier' ) ); ?>
									<p class="bei-hint"><?php esc_html_e( 'برای رویدادهای دیگر (ثبت‌نام کاربر، نظر جدید و...) از تابع bei_notify() در کد استفاده کنید — نمونه‌ها در راهنما', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
						</section>

						<div class="bei-save-bar">
							<?php submit_button( __( 'ذخیره تنظیمات', 'bale-eitaa-notifier' ), 'button button-primary bei-btn bei-btn-primary', 'submit', false ); ?>
							<span class="bei-hint"><?php esc_html_e( 'پس از ذخیره، اتصال را از کارت «تست اتصال» بررسی کنید.', 'bale-eitaa-notifier' ); ?></span>
						</div>
					</form>

					<?php
					// بخش شناسه‌یاب (خارج از فرم اصلی — فرم‌های مستقلی دارد).
					bei()->id_finder()->render_section();

					// کارت گزارش ارسال (لاگ).
					$this->render_log_card();
					?>
				</div>

				<aside class="bei-side">
					<!-- تست اتصال -->
					<section class="bei-card">
						<div class="bei-card-head">
							<span class="bei-icon bei-icon--test">🧪</span>
							<h2><?php esc_html_e( 'تست اتصال', 'bale-eitaa-notifier' ); ?></h2>
						</div>
						<div class="bei-card-body">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="bei_test" />
								<?php wp_nonce_field( self::NONCE_ACTION, 'bei_test_nonce' ); ?>
								<div class="bei-test-buttons">
									<button class="button bei-btn bei-btn-primary bei-btn-block" type="submit" name="target" value="all">📨 <?php esc_html_e( 'تست همه پیام‌رسان‌ها', 'bale-eitaa-notifier' ); ?></button>
									<button class="button bei-btn bei-btn-block" type="submit" name="target" value="bale">🟢 <?php esc_html_e( 'تست بله', 'bale-eitaa-notifier' ); ?></button>
									<button class="button bei-btn bei-btn-block" type="submit" name="target" value="eitaa">🟣 <?php esc_html_e( 'تست ایتا', 'bale-eitaa-notifier' ); ?></button>
									<button class="button bei-btn bei-btn-block" type="submit" name="target" value="telegram">🟦 <?php esc_html_e( 'تست تلگرام', 'bale-eitaa-notifier' ); ?></button>
									<button class="button bei-btn bei-btn-block" type="submit" name="target" value="whatsapp">💬 <?php esc_html_e( 'تست واتساپ', 'bale-eitaa-notifier' ); ?></button>
									<button class="button bei-btn bei-btn-block" type="submit" name="target" value="proxy">🌐 <?php esc_html_e( 'تست پراکسی', 'bale-eitaa-notifier' ); ?></button>
									<button class="button bei-btn bei-btn-block" type="submit" name="target" value="email">📧 <?php esc_html_e( 'تست پل ایمیل', 'bale-eitaa-notifier' ); ?></button>
								</div>
							</form>
							<p class="bei-hint bei-mt"><?php esc_html_e( 'ابتدا تنظیمات را ذخیره کنید، سپس تست بگیرید.', 'bale-eitaa-notifier' ); ?></p>
						</div>
					</section>

					<!-- API خارجی -->
					<section class="bei-card">
						<div class="bei-card-head">
							<span class="bei-icon bei-icon--api">🌐</span>
							<h2><?php esc_html_e( 'API خارجی (REST)', 'bale-eitaa-notifier' ); ?></h2>
						</div>
						<div class="bei-card-body">
							<p class="bei-hint"><?php esc_html_e( 'هر سیستم بیرونی با POST به این آدرس پیام می‌فرستد (احراز هویت: Application Password):', 'bale-eitaa-notifier' ); ?></p>
							<div class="bei-codebox">
								<code id="bei-rest-url" dir="ltr"><?php echo esc_html( $rest_url ); ?></code>
								<button type="button" class="bei-copy" data-bei-copy="bei-rest-url"><?php esc_html_e( 'کپی', 'bale-eitaa-notifier' ); ?></button>
							</div>
							<pre class="bei-pre" dir="ltr"><code>curl -X POST "<?php echo esc_html( $rest_url ); ?>" \
  -u "USERNAME:APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"text":"سلام","targets":["bale","eitaa"]}'</code></pre>
						</div>
					</section>

					<!-- مستندات -->
					<section class="bei-card">
						<div class="bei-card-head">
							<span class="bei-icon bei-icon--docs">📚</span>
							<h2><?php esc_html_e( 'مستندات رسمی', 'bale-eitaa-notifier' ); ?></h2>
						</div>
						<div class="bei-card-body">
							<ul class="bei-docs">
								<li><a href="https://core.telegram.org/bots/api" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'مستندات Telegram Bot API', 'bale-eitaa-notifier' ); ?><span>↗</span></a></li>
								<li><a href="https://docs.bale.ai" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'مستندات بازوی بله', 'bale-eitaa-notifier' ); ?><span>↗</span></a></li>
								<li><a href="https://eitaayar.ir/api/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'راهنمای API ایتایار', 'bale-eitaa-notifier' ); ?><span>↗</span></a></li>
								<li><a href="https://developer.eitaa.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'درگاه توسعه‌دهندگان ایتا', 'bale-eitaa-notifier' ); ?><span>↗</span></a></li>
								<li><a href="https://eitaayar.ir/testApi" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'صفحه تست API ایتا', 'bale-eitaa-notifier' ); ?><span>↗</span></a></li>
							</ul>
						</div>
					</section>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * مدیریت دکمه‌های تست ارسال (admin-post).
	 */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'bale-eitaa-notifier' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'bei_test_nonce' );

		$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'all';
		$site   = get_bloginfo( 'name' );

		switch ( $target ) {
			case 'bale':
				/* translators: %s: نام سایت */
				$result = bei()->messenger()->send_bale( sprintf( __( '✅ تست اتصال بله از سایت: %s', 'bale-eitaa-notifier' ), $site ) );
				break;
			case 'eitaa':
				/* translators: %s: نام سایت */
				$result = bei()->messenger()->send_eitaa( sprintf( __( '✅ تست اتصال ایتا از سایت: %s', 'bale-eitaa-notifier' ), $site ) );
				break;
			case 'telegram':
				/* translators: %s: نام سایت */
				$result = bei()->messenger()->send_telegram( sprintf( __( '✅ تست اتصال تلگرام از سایت: %s', 'bale-eitaa-notifier' ), $site ) );
				break;
			case 'whatsapp':
				/* translators: %s: نام سایت */
				$result = bei()->messenger()->send_whatsapp( sprintf( __( '✅ تست اتصال واتساپ از سایت: %s', 'bale-eitaa-notifier' ), $site ) );
				break;
			case 'proxy':
				// تست پراکسی مستقل — بدون نیاز به توکن پیام‌رسان.
				set_transient( 'bei_test_result', $this->run_proxy_test(), 60 );
				wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
				exit;
			case 'email':
				$options = self::get_options();
				$sample  = bei()->email_bridge()->format_message(
					__( 'تست پل ایمیل', 'bale-eitaa-notifier' ),
					__( "این یک ایمیل آزمایشی برای بررسی پل ایمیل است.\n\nاین بخش بدنه پیام است.", 'bale-eitaa-notifier' )
				);
				$result = bei()->messenger()->notify( $sample, $options['email_bridge_targets'] );
				break;
			default:
				/* translators: %s: نام سایت */
				$result = bei()->messenger()->notify( sprintf( __( '✅ تست اتصال افزونه از سایت: %s', 'bale-eitaa-notifier' ), $site ), array( 'bale', 'eitaa', 'telegram', 'whatsapp' ) );
		}

		$errors = array();
		if ( is_array( $result ) && ! empty( $result['bale'] ) && is_wp_error( $result['bale'] ) ) {
			$errors[] = 'بله: ' . $result['bale']->get_error_message();
		}
		if ( is_array( $result ) && ! empty( $result['eitaa'] ) && is_wp_error( $result['eitaa'] ) ) {
			$errors[] = 'ایتا: ' . $result['eitaa']->get_error_message();
		}
		if ( is_array( $result ) && ! empty( $result['telegram'] ) && is_wp_error( $result['telegram'] ) ) {
			$errors[] = 'تلگرام: ' . $result['telegram']->get_error_message();
		}
		if ( is_array( $result ) && ! empty( $result['whatsapp'] ) && is_wp_error( $result['whatsapp'] ) ) {
			$errors[] = 'واتساپ: ' . $result['whatsapp']->get_error_message();
		}
		if ( is_wp_error( $result ) ) {
			$errors[] = $result->get_error_message();
		}

		if ( ! empty( $errors ) ) {
			bei()->logger()->log( 'system', 'test', 'failed', $target, array( 'error' => implode( ' | ', $errors ) ) );
			set_transient( 'bei_test_result', array( 'error', implode( ' | ', $errors ) ), 60 );
		} else {
			bei()->logger()->log( 'system', 'test', 'sent', $target );
			set_transient( 'bei_test_result', array( 'ok', wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) ), 60 );
		}

		wp_safe_redirect(
			add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) )
		);
		exit;
	}

	/**
	 * اجرای تست پراکسی: یک درخواست HTTP از مسیر پراکسی به مقصد پیام‌رسان
	 * (بدون نیاز به توکن). هر پاسخ HTTP حتی ۴۰۴ یعنی تونل پراکسی سالم است.
	 *
	 * @return array ['ok', پیام] یا ['error', پیام]
	 */
	private function run_proxy_test() {
		$options = self::get_options();

		if ( empty( $options['tg_proxy_enabled'] ) ) {
			return array( 'error', __( 'پراکسی فعال نیست — ابتدا سوییچ «فعال‌سازی پراکسی» را روشن کنید و تنظیمات را ذخیره کنید.', 'bale-eitaa-notifier' ) );
		}

		if ( empty( $options['tg_proxy_host'] ) ) {
			return array( 'error', __( 'آدرس پراکسی وارد نشده است.', 'bale-eitaa-notifier' ) );
		}

		$hosts = array(
			'telegram' => 'https://api.telegram.org/',
			'bale'     => 'https://tapi.bale.ai/',
			'eitaa'    => 'https://eitaayar.ir/',
		);

		// مقصد تست = اولین پیام‌رسانی که پراکسی برای آن فعال است.
		$target = ! empty( $options['tg_proxy_hosts'] ) ? $options['tg_proxy_hosts'][0] : 'telegram';
		if ( ! isset( $hosts[ $target ] ) ) {
			$target = 'telegram';
		}

		// ماژول پراکسی (هوک http_api_curl) به‌صورت خودکار روی این درخواست اعمال می‌شود.
		$response = wp_remote_get(
			$hosts[ $target ],
			array(
				'timeout'     => 20,
				'redirection' => 0,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'error',
				sprintf(
					/* translators: %s: پیام خطا */
					__( 'اتصال از مسیر پراکسی ناموفق بود: %s — آدرس/پورت/احراز هویت یا دسترسی سرور به پراکسی را بررسی کنید.', 'bale-eitaa-notifier' ),
					$response->get_error_message()
				),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		return array(
			'ok',
			sprintf(
				/* translators: 1: کد HTTP، 2: آدرس مقصد */
				__( 'پراکسی به درستی کار می‌کند ✅ — پاسخ HTTP %1$s از %2$s دریافت شد (یعنی تونل پراکسی برقرار است).', 'bale-eitaa-notifier' ),
				$code,
				$hosts[ $target ]
			),
		);
	}

	/**
	 * پاک‌سازی لاگ ارسال‌ها.
	 */
	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'bale-eitaa-notifier' ) );
		}
		check_admin_referer( self::NONCE_ACTION, 'bei_test_nonce' );

		Bei_Logger::clear();

		wp_safe_redirect(
			add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) )
		);
		exit;
	}

	/**
	 * نمایش کارت «گزارش ارسال» (لاگ ۲۰ ارسال آخر).
	 */
	public function render_log_card() {
		$entries = Bei_Logger::entries();
		?>
		<section class="bei-card">
			<div class="bei-card-head">
				<span class="bei-icon bei-icon--docs">📊</span>
				<div>
					<h2><?php esc_html_e( 'گزارش ارسال پیام‌ها', 'bale-eitaa-notifier' ); ?></h2>
					<p class="bei-hint"><?php esc_html_e( '۲۰ ارسال آخر + تلاش‌های Retry — اطلاعات حساس (توکن/رمز) هرگز ثبت نمی‌شود.', 'bale-eitaa-notifier' ); ?></p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-inline-start:auto">
					<input type="hidden" name="action" value="bei_clear_log" />
					<?php wp_nonce_field( self::NONCE_ACTION, 'bei_test_nonce' ); ?>
					<button class="button bei-btn" type="submit">🧹 <?php esc_html_e( 'پاک‌سازی لاگ', 'bale-eitaa-notifier' ); ?></button>
				</form>
			</div>
			<div class="bei-card-body">
				<?php if ( empty( $entries ) ) : ?>
					<p class="bei-hint"><?php esc_html_e( 'هنوز هیچ ارسالی ثبت نشده است.', 'bale-eitaa-notifier' ); ?></p>
				<?php else : ?>
					<div class="bei-wc-table">
						<div class="bei-wc-row bei-wc-row--head">
							<span><?php esc_html_e( 'زمان', 'bale-eitaa-notifier' ); ?></span>
							<span><?php esc_html_e( 'کانال', 'bale-eitaa-notifier' ); ?></span>
							<span><?php esc_html_e( 'وضعیت', 'bale-eitaa-notifier' ); ?></span>
							<span><?php esc_html_e( 'جزئیات', 'bale-eitaa-notifier' ); ?></span>
						</div>
						<?php foreach ( array_slice( $entries, 0, 20 ) as $entry ) : ?>
							<?php
							$status_label = 'sent' === $entry['status'] ? '✅ ' . __( 'موفق', 'bale-eitaa-notifier' ) : ( 'scheduled' === $entry['status'] ? '⏳ ' . __( 'زمان‌بندی', 'bale-eitaa-notifier' ) : '❌ ' . __( 'ناموفق', 'bale-eitaa-notifier' ) );
							$detail       = $entry['message'];
							if ( ! empty( $entry['context']['attempt'] ) ) {
								$detail .= ' — ' . sprintf( /* translators: %s: شماره تلاش */ __( 'تلاش %s', 'bale-eitaa-notifier' ), $entry['context']['attempt'] );
							}
							if ( ! empty( $entry['context']['error'] ) ) {
								$detail .= ' — ' . $entry['context']['error'];
							}
							?>
							<div class="bei-wc-row">
								<span class="bei-hint" dir="ltr"><?php echo esc_html( $entry['time'] ); ?></span>
								<span class="bei-wc-label"><?php echo esc_html( $entry['channel'] ); ?></span>
								<span><?php echo esc_html( $status_label ); ?></span>
								<span class="bei-hint"><?php echo esc_html( $detail ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}
}
