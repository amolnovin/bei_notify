<?php
/**
 * ماژول ووکامرس — اعلان وضعیت سفارش‌ها به ادمین و مشتری.
 *
 * امکانات:
 *  - منوی فرعی «ووکامرس» زیر منوی اصلی افزونه (در صورت نصب بودن ووکامرس و فعال بودن سوییچ)
 *  - دو تب مجزا: «پیام‌های مدیر» و «پیام‌های مشتریان» — برای هر وضعیت، پیام و روش
 *    ارسال هر طرف به‌صورت مستقل تنظیم می‌شود
 *  - متغیرهای {order_id} ، {status} ، {total} ، {customer_name} و...
 *  - ارسال به مشتری: واتساپ با شماره موبایل + تلگرام/بله با دکمه ایجکس «دریافت شناسه»
 *    در صفحه تسویه حساب (توکن یکبارمصرف + getUpdates/وبهوک) — روش ۱۰۰٪ پاسخگو
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Woo_Statuses
 */
final class Bei_Woo_Statuses {

	const OPTION_KEY    = 'bei_wc_options';
	const PAGE_SLUG     = 'bei-woocommerce';
	const SETTINGS_GROUP = 'bei_wc_group';

	/**
	 * شناسه سفارش جاری (صفحه تشکر).
	 *
	 * @var int
	 */
	private $current_order_id = 0;

	/**
	 * شناسه‌های سفارشی که وضعیت اولیه‌شان قبلاً ارسال شده (ضد ارسال تکراری).
	 *
	 * @var array
	 */
	private static $initial_dispatched = array();

	/**
	 * پرچم جلوگیری از نمایش دوباره نوار تسویه حساب (هوک دوگانه).
	 *
	 * @var bool
	 */
	private static $bar_rendered = false;

	/**
	 * ثبت هوک‌ها.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 10, 3 );

		// وضعیت «اولیه» سفارش (مثل در انتظار پرداخت) با انتقال ثبت نمی‌شود.
		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 10, 1 );

		add_action( 'woocommerce_thankyou', array( $this, 'set_current_order' ), 5 );

		// دکمه ایجکس «دریافت شناسه» — منابع در سر + نمایش در دو نقطه:
		// (۱) هوک کلاسیک woocommerce_after_checkout_form و (۲) پاصفحه برای تسویه حساب بلوکی.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
		add_action( 'woocommerce_after_checkout_form', array( $this, 'maybe_render_checkout_subscribe' ), 20 );
		add_action( 'wp_footer', array( $this, 'maybe_render_checkout_subscribe' ), 20 );

		add_shortcode( 'bei_subscribe', array( $this, 'shortcode' ) );

		add_action( 'wp_ajax_bei_subscribe', array( $this, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_nopriv_bei_subscribe', array( $this, 'ajax_subscribe' ) );
		add_action( 'wp_ajax_bei_sub_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_nopriv_bei_sub_status', array( $this, 'ajax_status' ) );
	}

	/* ------------------------------------------------------------------ */
	/* تنظیمات (مدل جدید: پیام جدا برای ادمین و مشتری)                      */
	/* ------------------------------------------------------------------ */

	/**
	 * ساختار یک ردیف وضعیت.
	 *
	 * @return array
	 */
	public static function default_row() {
		return array(
			'admin'    => array(
				'enabled' => 0,
				'method'  => 'all',
				'message' => '',
			),
			'customer' => array(
				'enabled' => 0,
				'method'  => 'all',
				'message' => '',
			),
		);
	}

	/**
	 * مقادیر پیش‌فرض تنظیمات.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'  => 0,
			'statuses' => array(),
		);
	}

	/**
	 * خواندن تنظیمات (با مهاجرت خودکار از مدل قدیمی audience).
	 *
	 * @return array
	 */
	public static function get_options() {
		$options = get_option( self::OPTION_KEY, array() );
		$options = wp_parse_args( $options, self::defaults() );

		if ( ! empty( $options['statuses'] ) && is_array( $options['statuses'] ) ) {
			foreach ( $options['statuses'] as $key => $row ) {
				if ( isset( $row['admin'] ) && isset( $row['customer'] ) ) {
					continue; // مدل جدید.
				}

				// مهاجرت از مدل قدیمی: audience → تب‌های ادمین/مشتری.
				$admin    = array( 'enabled' => 0, 'method' => 'all', 'message' => '' );
				$customer = array( 'enabled' => 0, 'method' => 'all', 'message' => '' );

				if ( ! empty( $row['enabled'] ) ) {
					$audience = isset( $row['audience'] ) ? $row['audience'] : 'admin';
					$method   = isset( $row['method'] ) ? $row['method'] : 'all';
					$message  = isset( $row['message'] ) ? $row['message'] : '';

					if ( in_array( $audience, array( 'admin', 'both' ), true ) ) {
						$admin = array( 'enabled' => 1, 'method' => $method, 'message' => $message );
					}
					if ( in_array( $audience, array( 'customer', 'both' ), true ) ) {
						$customer = array( 'enabled' => 1, 'method' => $method, 'message' => $message );
					}
				}

				$options['statuses'][ $key ] = array(
					'admin'    => $admin,
					'customer' => $customer,
				);
			}
		}

		return $options;
	}

	/**
	 * منوی فرعی «ووکامرس» — فقط وقتی ووکامرس نصب است و سوییچ اتصال فعال باشد.
	 */
	public function admin_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$options = Bei_Settings::get_options();
		if ( empty( $options['integ_wc'] ) ) {
			return;
		}

		add_submenu_page(
			Bei_Settings::PAGE_SLUG,
			__( 'وضعیت سفارش‌های ووکامرس', 'bale-eitaa-notifier' ),
			__( 'ووکامرس', 'bale-eitaa-notifier' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * ثبت تنظیمات (Settings API).
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
	 * پاک‌سازی ورودی‌ها (مدل جدید).
	 *
	 * @param array $input ورودی خام.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$clean = self::defaults();

		$clean['enabled'] = empty( $input['enabled'] ) ? 0 : 1;

		if ( ! empty( $input['statuses'] ) && is_array( $input['statuses'] ) ) {
			foreach ( $input['statuses'] as $key => $row ) {
				$key = sanitize_key( wp_unslash( $key ) );
				if ( '' === $key || ! is_array( $row ) ) {
					continue;
				}

				$clean['statuses'][ $key ] = self::default_row();

				foreach ( array( 'admin', 'customer' ) as $side ) {
					$side_row = isset( $row[ $side ] ) && is_array( $row[ $side ] ) ? $row[ $side ] : array();

					$method = isset( $side_row['method'] ) ? sanitize_key( wp_unslash( $side_row['method'] ) ) : 'all';

					$clean['statuses'][ $key ][ $side ] = array(
						'enabled' => empty( $side_row['enabled'] ) ? 0 : 1,
						'method'  => in_array( $method, array( 'all', 'bale', 'eitaa', 'telegram', 'whatsapp' ), true ) ? $method : 'all',
						'message' => isset( $side_row['message'] ) ? sanitize_textarea_field( wp_unslash( $side_row['message'] ) ) : '',
					);
				}
			}
		}

		return $clean;
	}

	/**
	 * فهرست وضعیت‌های ووکامرس.
	 *
	 * @return array
	 */
	public function get_statuses() {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			return wc_get_order_statuses();
		}

		return array();
	}

	/**
	 * تبدیل روش ارسال به آرایه کانال‌ها.
	 *
	 * @param string $method روش انتخاب‌شده.
	 * @return array
	 */
	public function method_targets( $method ) {
		$enabled = Bei_Settings::enabled_channels();

		if ( 'all' === $method ) {
			return $enabled;
		}

		return in_array( $method, $enabled, true ) ? array( $method ) : array();
	}

	/**
	 * گزینه‌های روش ارسال.
	 *
	 * @return array
	 */
	private function method_options() {
		$all = array(
			'all'      => __( 'همه', 'bale-eitaa-notifier' ),
			'bale'     => __( 'بله', 'bale-eitaa-notifier' ),
			'eitaa'    => __( 'ایتا', 'bale-eitaa-notifier' ),
			'telegram' => __( 'تلگرام', 'bale-eitaa-notifier' ),
			'whatsapp' => __( 'واتساپ', 'bale-eitaa-notifier' ),
		);

		// فقط پیام‌رسان‌های «فعال» در لیست نمایش داده می‌شوند.
		$filtered = array( 'all' => $all['all'] );
		foreach ( Bei_Settings::enabled_channels() as $channel ) {
			if ( isset( $all[ $channel ] ) ) {
				$filtered[ $channel ] = $all[ $channel ];
			}
		}

		return $filtered;
	}

	/**
	 * سوییچ استایل‌شده.
	 *
	 * @param string $name     نام ورودی.
	 * @param string $value    مقدار.
	 * @param bool   $checked  وضعیت.
	 * @param string $label    برچسب.
	 * @param bool   $disabled غیرفعال.
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

	/* ------------------------------------------------------------------ */
	/* صفحه تنظیمات با دو تب: پیام‌های مدیر / پیام‌های مشتریان                */
	/* ------------------------------------------------------------------ */

	/**
	 * نمایش صفحه وضعیت‌های ووکامرس (دو تب).
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options  = self::get_options();
		$statuses = $this->get_statuses();
		$settings = Bei_Settings::get_options();
		?>
		<div class="wrap bei-wrap" dir="rtl">
			<header class="bei-header">
				<div>
					<h1>🛒 <?php esc_html_e( 'اعلان وضعیت سفارش‌های ووکامرس', 'bale-eitaa-notifier' ); ?></h1>
					<p class="bei-subtitle"><?php esc_html_e( 'پیام‌های مدیر و مشتری در دو تب مجزا — برای هر وضعیت، پیام و روش ارسال هر طرف مستقل تنظیم می‌شود.', 'bale-eitaa-notifier' ); ?></p>
				</div>
			</header>

			<?php settings_errors( self::PAGE_SLUG ); ?>

			<div class="bei-grid">
				<div class="bei-main">
					<form method="post" action="options.php">
						<?php settings_fields( self::SETTINGS_GROUP ); ?>

						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--wp">📢</span>
								<div>
									<h2><?php esc_html_e( 'وضعیت‌های فعال فروشگاه', 'bale-eitaa-notifier' ); ?></h2>
									<p class="bei-hint"><?php esc_html_e( 'وضعیت‌ها از ووکامرس شناسایی شده‌اند (شامل وضعیت‌های سفارشی). «در انتظار پرداخت» وضعیت اولیه سفارش است و جداگانه پوشش داده می‌شود.', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
							<div class="bei-card-body">
								<div class="bei-field">
									<?php $this->render_switch( 'bei_wc_options[enabled]', '1', ! empty( $options['enabled'] ), __( 'فعال‌سازی کلی اعلان وضعیت سفارش‌ها', 'bale-eitaa-notifier' ) ); ?>
								</div>

								<?php if ( empty( $statuses ) ) : ?>
									<p class="bei-hint"><?php esc_html_e( 'ووکامرس فعال نیست یا هیچ وضعیتی ثبت نشده است.', 'bale-eitaa-notifier' ); ?></p>
								<?php else : ?>
									<div class="bei-tabs" data-bei-tabs>
										<button type="button" class="bei-tab-btn is-active" data-bei-tab="admin">📩 <?php esc_html_e( 'پیام‌های مدیر', 'bale-eitaa-notifier' ); ?></button>
										<button type="button" class="bei-tab-btn" data-bei-tab="customer">👤 <?php esc_html_e( 'پیام‌های مشتریان', 'bale-eitaa-notifier' ); ?></button>

										<?php foreach ( array( 'admin' => __( 'پیام‌های مدیر', 'bale-eitaa-notifier' ), 'customer' => __( 'پیام‌های مشتریان', 'bale-eitaa-notifier' ) ) as $side => $side_label ) : ?>
											<div class="bei-tab-panel<?php echo 'admin' === $side ? ' is-active' : ''; ?>" data-bei-panel="<?php echo esc_attr( $side ); ?>">
												<p class="bei-hint"><?php printf( /* translators: %s: نام تب */ esc_html__( 'تنظیمات «%s» — برای هر وضعیت: سوییچ، روش ارسال و متن پیام.', 'bale-eitaa-notifier' ), esc_html( $side_label ) ); ?></p>
												<div class="bei-wc-table">
													<div class="bei-wc-row bei-wc-row--head">
														<span><?php esc_html_e( 'فعال', 'bale-eitaa-notifier' ); ?></span>
														<span><?php esc_html_e( 'نام وضعیت', 'bale-eitaa-notifier' ); ?></span>
														<span><?php esc_html_e( 'روش ارسال', 'bale-eitaa-notifier' ); ?></span>
														<span><?php esc_html_e( 'متن پیام', 'bale-eitaa-notifier' ); ?></span>
													</div>
													<?php foreach ( $statuses as $slug => $label ) : ?>
														<?php
														$row = isset( $options['statuses'][ $slug ][ $side ] ) ? $options['statuses'][ $slug ][ $side ] : array(
															'enabled' => 0,
															'method'  => 'all',
															'message' => '',
														);
														?>
														<div class="bei-wc-row">
															<span><?php $this->render_switch( 'bei_wc_options[statuses][' . $slug . '][' . $side . '][enabled]', '1', ! empty( $row['enabled'] ) ); ?></span>
															<span class="bei-wc-label"><?php echo esc_html( $label ); ?> <code dir="ltr"><?php echo esc_html( $slug ); ?></code></span>
															<span>
																<select class="bei-select bei-select--sm" name="bei_wc_options[statuses][<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $side ); ?>][method]">
																	<?php foreach ( $this->method_options() as $m => $m_label ) : ?>
																		<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $row['method'], $m ); ?>><?php echo esc_html( $m_label ); ?></option>
																	<?php endforeach; ?>
																</select>
															</span>
															<span>
																<textarea class="bei-input bei-input--area bei-input--wcmsg" rows="2"
																	name="bei_wc_options[statuses][<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $side ); ?>][message]"
																	placeholder="<?php esc_attr_e( 'متن پیام با متغیرها، مثلاً: سفارش {order_id} شما {status_name} شد.', 'bale-eitaa-notifier' ); ?>"><?php echo esc_textarea( $row['message'] ); ?></textarea>
															</span>
														</div>
													<?php endforeach; ?>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
							</div>
						</section>

						<div class="bei-save-bar">
							<?php submit_button( __( 'ذخیره تنظیمات وضعیت‌ها', 'bale-eitaa-notifier' ), 'button button-primary bei-btn bei-btn-primary', 'submit', false ); ?>
						</div>
					</form>
				</div>

				<aside class="bei-side">
					<section class="bei-card">
						<div class="bei-card-head">
							<span class="bei-icon bei-icon--api">🔤</span>
							<h2><?php esc_html_e( 'متغیرهای مجاز پیام', 'bale-eitaa-notifier' ); ?></h2>
						</div>
						<div class="bei-card-body">
							<pre class="bei-pre" dir="ltr"><code>{order_id}        {status}      {status_name}
{total}          {currency}    {payment_method}
{customer_name}  {customer_first}  {customer_last}
{customer_phone} {customer_email}
{items}          {site_name}   {order_date}   {order_url}</code></pre>
						</div>
					</section>

					<section class="bei-card">
						<div class="bei-card-head">
							<span class="bei-icon bei-icon--test">🤝</span>
							<h2><?php esc_html_e( 'ارسال پیام به مشتری — راه ۱۰۰٪ پاسخگو', 'bale-eitaa-notifier' ); ?></h2>
						</div>
						<div class="bei-card-body">
							<ol class="bei-steps">
								<li><?php esc_html_e( 'در کارت بله/تلگرام تنظیمات، «نام کاربری ربات» را وارد کنید.', 'bale-eitaa-notifier' ); ?></li>
								<li><?php esc_html_e( 'در صفحه تسویه حساب، دکمه «فعال‌سازی اعلان» به‌صورت خودکار نمایش داده می‌شود.', 'bale-eitaa-notifier' ); ?></li>
								<li><?php esc_html_e( 'مشتری کلیک می‌کند و ربات را Start می‌زند — شناسه گفتگوی او روی سفارش/حساب کاربری ذخیره می‌شود.', 'bale-eitaa-notifier' ); ?></li>
								<li><?php esc_html_e( 'واتساپ: شماره موبایل مشتری خودش شناسه است ولی +98 پشتیبانی نمی‌شود.', 'bale-eitaa-notifier' ); ?></li>
							</ol>
							<p class="bei-hint"><strong><?php esc_html_e( 'وضعیت نمایش دکمه در تسویه حساب:', 'bale-eitaa-notifier' ); ?></strong></p>
							<?php
							$diag = array(
								__( 'ووکامرس نصب است', 'bale-eitaa-notifier' )                 => class_exists( 'WooCommerce' ),
								__( 'سوییچ کلی اعلان وضعیت‌ها روشن است', 'bale-eitaa-notifier' ) => ! empty( $options['enabled'] ),
								__( 'نام کاربری ربات تلگرام تنظیم شده', 'bale-eitaa-notifier' ) => ! empty( $settings['tg_bot_username'] ),
								__( 'نام کاربری ربات بله تنظیم شده', 'bale-eitaa-notifier' )   => ! empty( $settings['bale_bot_username'] ),
								__( 'حداقل یک وضعیت در تب «پیام‌های مشتریان» فعال است', 'bale-eitaa-notifier' ) => $this->has_any_customer_status(),
							);
							?>
							<ul class="bei-diag">
								<?php foreach ( $diag as $diag_label => $diag_ok ) : ?>
									<li class="<?php echo $diag_ok ? 'is-ok' : 'is-bad'; ?>"><?php echo $diag_ok ? '✅' : '❌'; ?> <?php echo esc_html( $diag_label ); ?></li>
								<?php endforeach; ?>
							</ul>
							<p class="bei-hint"><?php esc_html_e( 'دکمه شناور فقط وقتی همه موارد بالا سبز باشند نمایش داده می‌شود. می‌توانید شورت‌کد [bei_subscribe] را هم در صفحه «تشکر از خرید» قرار دهید.', 'bale-eitaa-notifier' ); ?></p>
						</div>
					</section>
				</aside>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* ارسال پیام (مدل جدید: ادمین و مشتری جدا)                            */
	/* ------------------------------------------------------------------ */

	/**
	 * هوک تغییر وضعیت سفارش.
	 *
	 * @param int    $order_id   شناسه سفارش.
	 * @param string $old_status وضعیت قبلی.
	 * @param string $new_status وضعیت جدید.
	 */
	public function on_status_changed( $order_id, $old_status, $new_status ) {
		$options = self::get_options();
		if ( empty( $options['enabled'] ) ) {
			return;
		}

		$key = 'wc-' . $new_status;
		if ( empty( $options['statuses'][ $key ] ) ) {
			return;
		}

		$order = $this->load_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$this->dispatch_row( $options['statuses'][ $key ], $order, $key, $new_status );
	}

	/**
	 * هوک ساخت سفارش جدید — وضعیت «اولیه» (در انتظار پرداخت و...).
	 *
	 * @param int $order_id شناسه سفارش.
	 */
	public function on_new_order( $order_id ) {
		$order_id = (int) $order_id;
		if ( isset( self::$initial_dispatched[ $order_id ] ) ) {
			return;
		}
		self::$initial_dispatched[ $order_id ] = true;

		$options = self::get_options();
		if ( empty( $options['enabled'] ) ) {
			return;
		}

		$order = $this->load_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$status = $order->get_status();
		$key    = 'wc-' . $status;
		if ( empty( $options['statuses'][ $key ] ) ) {
			return;
		}

		$this->dispatch_row( $options['statuses'][ $key ], $order, $key, $status );
	}

	/**
	 * بارگذاری سفارش.
	 *
	 * @param int $order_id شناسه سفارش.
	 * @return object|null
	 */
	private function load_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		return wc_get_order( $order_id );
	}

	/**
	 * اجرای یک ردیف: ارسال پیام ادمین و پیام مشتری به‌صورت مستقل.
	 *
	 * @param array  $row    ردیف وضعیت (admin/customer).
	 * @param object $order  سفارش.
	 * @param string $key    کلید وضعیت.
	 * @param string $status وضعیت خام.
	 */
	private function dispatch_row( $row, $order, $key, $status ) {
		if ( ! empty( $row['admin']['enabled'] ) ) {
			$targets = $this->method_targets( $row['admin']['method'] );
			$text    = $this->build_message( $row['admin']['message'], $order, $key, $status );
			bei()->queue()->notify_async( $text, $targets );
		}

		if ( ! empty( $row['customer']['enabled'] ) ) {
			$targets = $this->method_targets( $row['customer']['method'] );
			$text    = $this->build_message( $row['customer']['message'], $order, $key, $status );
			foreach ( $targets as $channel ) {
				$this->send_to_customer( $order, $text, $channel );
			}
		}
	}

	/**
	 * ساخت متن پیام با جایگزینی متغیرها.
	 *
	 * @param string $template قالب پیام.
	 * @param object $order    سفارش.
	 * @param string $key      کلید وضعیت.
	 * @param string $status   وضعیت خام.
	 * @return string
	 */
	public function build_message( $template, $order, $key = '', $status = '' ) {
		if ( '' === trim( $template ) ) {
			/* translators: 1: شماره سفارش، 2: نام وضعیت */
			$template = __( "🛒 وضعیت سفارش {order_id} به «{status_name}» تغییر کرد.\n\nمشتری: {customer_name}\nمبلغ: {total} {currency}", 'bale-eitaa-notifier' );
		}

		$statuses    = $this->get_statuses();
		$status_name = isset( $statuses[ $key ] ) ? $statuses[ $key ] : ucfirst( $status );

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name() . ' × ' . $item->get_quantity();
		}

		$first = $order->get_billing_first_name();
		$last  = $order->get_billing_last_name();

		$vars = array(
			'{order_id}'        => $order->get_id(),
			'{status}'          => $status,
			'{status_name}'     => $status_name,
			'{total}'           => $order->get_total(),
			'{currency}'        => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'{payment_method}'  => method_exists( $order, 'get_payment_method_title' ) ? $order->get_payment_method_title() : '',
			'{customer_name}'   => trim( $first . ' ' . $last ),
			'{customer_first}'  => $first,
			'{customer_last}'   => $last,
			'{customer_phone}'  => $order->get_billing_phone(),
			'{customer_email}'  => $order->get_billing_email(),
			'{items}'           => implode( '، ', $items ),
			'{site_name}'       => get_bloginfo( 'name' ),
			'{order_date}'      => method_exists( $order, 'get_date_created' ) && $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y/m/d H:i' ) : '',
			'{order_url}'       => method_exists( $order, 'get_view_order_url' ) ? $order->get_view_order_url() : '',
		);

		return strtr( $template, $vars );
	}

	/**
	 * ارسال پیام به مشتری.
	 *
	 * @param object $order   سفارش.
	 * @param string $text    متن پیام.
	 * @param string $channel کانال.
	 * @return bool ارسال شد یا نه.
	 */
	public function send_to_customer( $order, $text, $channel ) {
		if ( 'whatsapp' === $channel ) {
			$options = Bei_Settings::get_options();
			if ( 'callmebot' === $options['wa_gateway'] ) {
				return false; // CallMeBot فقط به شماره فعال‌سازی‌شده خودتان می‌فرستد.
			}
			$phone = $this->normalize_phone( $order->get_billing_phone() );
			if ( '' === $phone ) {
				return false;
			}
			if ( 'greenapi' === $options['wa_gateway'] ) {
				$phone .= '@c.us';
			}
			bei()->messenger()->send_whatsapp_direct( $phone, $text );

			return true;
		}

		$chat = $order->get_meta( '_bei_chat_' . $channel );
		if ( empty( $chat ) && $order->get_user_id() ) {
			$chat = get_user_meta( $order->get_user_id(), 'bei_chat_' . $channel, true );
		}
		if ( empty( $chat ) ) {
			return false;
		}

		if ( 'telegram' === $channel ) {
			bei()->messenger()->send_telegram_direct( $chat, $text );
		} elseif ( 'bale' === $channel ) {
			bei()->messenger()->send_bale_direct( $chat, $text );
		} else {
			bei()->messenger()->send_eitaa_direct( $chat, $text );
		}

		return true;
	}

	/**
	 * نرمال‌سازی شماره موبایل به قالب بین‌المللی.
	 *
	 * @param string $phone شماره ورودی.
	 * @return string
	 */
	public function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );

		if ( '' === $digits ) {
			return '';
		}

		if ( '0' === $digits[0] ) {
			$digits = '98' . substr( $digits, 1 );
		}

		return $digits;
	}

	/* ------------------------------------------------------------------ */
	/* فعال‌سازی اعلان مشتری (deep link + دکمه ایجکس تسویه حساب)              */
	/* ------------------------------------------------------------------ */

	/**
	 * ذخیره شناسه سفارش جاری + کپی شناسه‌های سشن به سفارش.
	 *
	 * @param int $order_id شناسه سفارش.
	 */
	public function set_current_order( $order_id ) {
		$this->current_order_id = (int) $order_id;

		if ( ! function_exists( 'wc_get_order' ) || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( array( 'telegram', 'bale' ) as $channel ) {
			$chat = WC()->session->get( 'bei_chat_' . $channel );
			if ( ! empty( $chat ) && empty( $order->get_meta( '_bei_chat_' . $channel ) ) ) {
				$order->update_meta_data( '_bei_chat_' . $channel, $chat );
				$order->save();
			}
		}
	}

	/**
	 * توکن امنیتی سفارش (SEC-01 — تصادفی، یکبارمصرف، با انقضا).
	 *
	 * در متای سفارش ذخیره می‌شود و در اولین استفاده باطل می‌شود.
	 *
	 * @param object $order سفارش ووکامرس.
	 * @return string
	 */
	public function ensure_subscribe_token( $order ) {
		$token = $order->get_meta( '_bei_sub_token' );

		if ( empty( $token ) ) {
			$token = wp_generate_password( 32, false, false );
			$order->update_meta_data( '_bei_sub_token', $token );
			$order->update_meta_data( '_bei_sub_expires', time() + ( 7 * DAY_IN_SECONDS ) );
			$order->save();
		}

		return $token;
	}

	/**
	 * شورت‌کد [bei_subscribe].
	 *
	 * @param array $atts ویژگی‌ها.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts     = shortcode_atts( array( 'order' => 0 ), $atts, 'bei_subscribe' );
		$order_id = $atts['order'] ? (int) $atts['order'] : $this->current_order_id;

		if ( ! $order_id ) {
			return '';
		}

		if ( ! function_exists( 'wc_get_order' ) ) {
			return '';
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '';
		}

		$options = Bei_Settings::get_options();
		$token   = $this->ensure_subscribe_token( $order );

		$links = array();

		if ( ! empty( $options['tg_bot_username'] ) && $this->customer_enabled_for_channel( 'telegram' ) ) {
			$links[] = sprintf(
				'<a class="bei-sub-btn" href="https://t.me/%1$s?start=bei_sub_%2$d_%3$s" target="_blank" rel="noopener">🟦 %4$s</a>',
				esc_attr( $options['tg_bot_username'] ),
				(int) $order_id,
				esc_attr( $token ),
				esc_html__( 'فعال‌سازی اعلان تلگرام', 'bale-eitaa-notifier' )
			);
		}

		if ( ! empty( $options['bale_bot_username'] ) && $this->customer_enabled_for_channel( 'bale' ) ) {
			$links[] = sprintf(
				'<a class="bei-sub-btn" href="https://ble.ir/%1$s?start=bei_sub_%2$d_%3$s" target="_blank" rel="noopener">🟢 %4$s</a>',
				esc_attr( $options['bale_bot_username'] ),
				(int) $order_id,
				esc_attr( $token ),
				esc_html__( 'فعال‌سازی اعلان بله', 'bale-eitaa-notifier' )
			);
		}

		if ( empty( $links ) ) {
			return '';
		}

		return '<div class="bei-subscribe">'
			. '<strong>' . esc_html__( '🔔 دریافت اعلان وضعیت سفارش:', 'bale-eitaa-notifier' ) . '</strong> '
			. implode( ' ', $links )
			. '</div>';
	}

	/**
	 * آیا کانالی در تب «پیام‌های مشتریان» حداقل یک وضعیت فعال دارد؟
	 * (عمومی — ماژول فعال‌سازی خودکار اعلان کاربر هم استفاده می‌کند)
	 *
	 * @param string $channel کانال.
	 * @return bool
	 */
	public function customer_enabled_for_channel( $channel ) {
		$options = self::get_options();
		if ( empty( $options['enabled'] ) ) {
			return false;
		}

		foreach ( $options['statuses'] as $row ) {
			if ( empty( $row['customer']['enabled'] ) ) {
				continue;
			}
			if ( in_array( $channel, $this->method_targets( $row['customer']['method'] ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * آیا حداقل یک وضعیت در تب «پیام‌های مشتریان» فعال است؟
	 *
	 * @return bool
	 */
	public function has_any_customer_status() {
		$options = self::get_options();
		if ( empty( $options['enabled'] ) ) {
			return false;
		}

		foreach ( $options['statuses'] as $row ) {
			if ( ! empty( $row['customer']['enabled'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * پردازش deep link «فعال‌سازی اعلان».
	 *
	 * @param string $chat_id شناسه گفتگو.
	 * @param string $text    متن پیام.
	 * @param string $channel 'bale' یا 'telegram'.
	 * @return string|null
	 */
	public function handle_deep_link( $chat_id, $text, $channel ) {
		// توکن «حساب کاربری» (دکمه ایجکس تسویه حساب): bei_u_<token>
		if ( preg_match( '/bei_u_([A-Za-z0-9]{14})/', (string) $text, $m ) ) {
			return $this->handle_user_token( $chat_id, 'bei_u_' . $m[1], $channel );
		}

		if ( ! preg_match( '/bei_sub_(\d+)_([A-Za-z0-9]{32})/', (string) $text, $m ) ) {
			return null;
		}

		$order_id = (int) $m[1];
		$token    = $m[2];

		if ( ! function_exists( 'wc_get_order' ) ) {
			return __( '❌ فروشگاه در دسترس نیست.', 'bale-eitaa-notifier' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return __( '❌ سفارش پیدا نشد.', 'bale-eitaa-notifier' );
		}

		$stored = $order->get_meta( '_bei_sub_token' );

		// یکبارمصرف: پس از استفاده موفق، توکن باطل می‌شود.
		if ( empty( $stored ) ) {
			return __( '❌ این لینک فعال‌سازی قبلاً استفاده شده است.', 'bale-eitaa-notifier' );
		}

		if ( ! hash_equals( $stored, $token ) ) {
			return __( '❌ لینک فعال‌سازی نامعتبر است.', 'bale-eitaa-notifier' );
		}

		$expires = (int) $order->get_meta( '_bei_sub_expires' );
		if ( $expires && time() > $expires ) {
			$order->delete_meta_data( '_bei_sub_token' );

			return __( '❌ لینک فعال‌سازی منقضی شده — از صفحه تشکر سفارش، لینک جدید بگیرید.', 'bale-eitaa-notifier' );
		}

		// مصرف توکن (یکبارمصرف).
		$order->delete_meta_data( '_bei_sub_token' );
		$order->delete_meta_data( '_bei_sub_expires' );
		$order->update_meta_data( '_bei_chat_' . $channel, $chat_id );
		$order->save();

		if ( $order->get_user_id() ) {
			update_user_meta( $order->get_user_id(), 'bei_chat_' . $channel, $chat_id );
		}

		return sprintf(
			/* translators: %s: شماره سفارش */
			__( '✅ اعلان‌های سفارش #%s برای شما فعال شد. از این پس هر تغییر وضعیت را همین‌جا اطلاع می‌دهیم.', 'bale-eitaa-notifier' ),
			$order_id
		);
	}

	/**
	 * پردازش توکن حساب کاربری (دکمه ایجکس تسویه حساب).
	 *
	 * @param string $chat_id شناسه گفتگو.
	 * @param string $token   توکن کامل.
	 * @param string $channel کانال.
	 * @return string
	 */
	private function handle_user_token( $chat_id, $token, $channel ) {
		$data = get_transient( 'bei_sub_token_' . $token );
		if ( empty( $data ) || ! isset( $data['user_id'] ) ) {
			return __( '❌ کد فعال‌سازی نامعتبر است یا منقضی شده — دوباره از صفحه تسویه حساب امتحان کنید.', 'bale-eitaa-notifier' );
		}

		delete_transient( 'bei_sub_token_' . $token );

		$user_id = (int) $data['user_id'];

		if ( $user_id > 0 ) {
			$this->store_chat( $user_id, $channel, $chat_id );
		} else {
			set_transient( 'bei_sub_chat_' . $token, $chat_id, 5 * MINUTE_IN_SECONDS );
		}

		return __( '✅ شناسه شما دریافت و در حساب کاربری ذخیره شد. از این پس اعلان‌های وضعیت سفارش برایتان ارسال می‌شود.', 'bale-eitaa-notifier' );
	}

	/**
	 * ذخیره شناسه در حساب کاربری و سشن فروشگاه.
	 *
	 * @param int    $user_id شناسه کاربر.
	 * @param string $channel کانال.
	 * @param string $chat_id شناسه گفتگو.
	 */
	private function store_chat( $user_id, $channel, $chat_id ) {
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, 'bei_chat_' . $channel, $chat_id );
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'bei_chat_' . $channel, $chat_id );
		}
	}

	/**
	 * جستجوی توکن در getUpdates.
	 *
	 * @param string $token   توکن کامل.
	 * @param string $channel کانال.
	 * @param int    $rounds  تعداد تلاش.
	 * @return string|false
	 */
	private function poll_updates_for_token( $token, $channel, $rounds = 4 ) {
		$options   = Bei_Settings::get_options();
		$token_key = 'telegram' === $channel ? 'tg_token' : 'bale_token';

		if ( empty( $options[ $token_key ] ) ) {
			return false;
		}

		$base = 'telegram' === $channel ? bei()->messenger()->telegram_base() : Bei_Messenger::BALE_API;
		$url  = bei()->messenger()->with_relay_key(
			add_query_arg(
				array(
					'timeout' => 5,
					'limit'   => 10,
				),
				$base . $options[ $token_key ] . '/getUpdates'
			)
		);

		for ( $i = 0; $i < $rounds; $i++ ) {
			$response = wp_remote_get( $url, array( 'timeout' => 12 ) );
			$data     = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $data['ok'] ) ) {
				return false;
			}

			foreach ( (array) ( isset( $data['result'] ) ? $data['result'] : array() ) as $update ) {
				if ( isset( $update['message']['text'] ) && trim( $update['message']['text'] ) === $token ) {
					return (string) $update['message']['chat']['id'];
				}
			}
		}

		return false;
	}

	/**
	 * ایجکس «دریافت شناسه».
	 */
	public function ajax_subscribe() {
		$this->check_ajax_nonce();

		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';
		if ( ! in_array( $channel, array( 'telegram', 'bale' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'کانال نامعتبر است.', 'bale-eitaa-notifier' ) ), 400 );
		}

		$options  = Bei_Settings::get_options();
		$username = 'telegram' === $channel ? $options['tg_bot_username'] : $options['bale_bot_username'];

		if ( empty( $username ) ) {
			wp_send_json_error( array( 'message' => __( 'نام کاربری ربات تنظیم نشده است — با مدیر سایت تماس بگیرید.', 'bale-eitaa-notifier' ) ), 400 );
		}

		$token = 'bei_u_' . wp_generate_password( 14, false, false );

		set_transient(
			'bei_sub_token_' . $token,
			array(
				'user_id' => get_current_user_id(),
				'channel' => $channel,
			),
			15 * MINUTE_IN_SECONDS
		);

		$deep_link = ( 'telegram' === $channel ? 'https://t.me/' : 'https://ble.ir/' ) . $username . '?start=' . rawurlencode( $token );

		$chat_id = $this->poll_updates_for_token( $token, $channel, 4 );
		if ( $chat_id ) {
			$this->store_chat( get_current_user_id(), $channel, $chat_id );
			delete_transient( 'bei_sub_token_' . $token );

			wp_send_json_success(
				array(
					'status'  => 'done',
					'chat_id' => $chat_id,
					'link'    => $deep_link,
				)
			);
		}

		wp_send_json_success(
			array(
				'status' => 'pending',
				'token'  => $token,
				'link'   => $deep_link,
			)
		);
	}

	/**
	 * ایجکس «بررسی وضعیت».
	 */
	public function ajax_status() {
		$this->check_ajax_nonce();

		$token   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$channel = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';

		if ( '' === $token || ! in_array( $channel, array( 'telegram', 'bale' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'پارامتر نامعتبر.', 'bale-eitaa-notifier' ) ), 400 );
		}

		$user_id = get_current_user_id();

		$chat = get_transient( 'bei_sub_chat_' . $token );
		if ( $chat ) {
			$this->store_chat( $user_id, $channel, $chat );
			delete_transient( 'bei_sub_chat_' . $token );
			delete_transient( 'bei_sub_token_' . $token );

			wp_send_json_success( array( 'status' => 'done', 'chat_id' => $chat ) );
		}

		if ( $user_id > 0 ) {
			$saved = get_user_meta( $user_id, 'bei_chat_' . $channel, true );
			if ( $saved ) {
				delete_transient( 'bei_sub_token_' . $token );

				wp_send_json_success( array( 'status' => 'done', 'chat_id' => $saved ) );
			}
		}

		$chat = $this->poll_updates_for_token( $token, $channel, 1 );
		if ( $chat ) {
			$this->store_chat( $user_id, $channel, $chat );
			delete_transient( 'bei_sub_token_' . $token );

			wp_send_json_success( array( 'status' => 'done', 'chat_id' => $chat ) );
		}

		wp_send_json_success( array( 'status' => 'waiting' ) );
	}

	/**
	 * بررسی nonce + Rate Limit ایجکس (SEC-02 — حداکثر ۱۰ درخواست در دقیقه).
	 */
	private function check_ajax_nonce() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bei_checkout_subscribe' ) ) {
			wp_send_json_error( array( 'message' => __( 'خطای امنیتی — صفحه را تازه کنید.', 'bale-eitaa-notifier' ) ), 403 );
		}

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'bei_rl_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= 10 ) {
			wp_send_json_error( array( 'message' => __( 'تعداد درخواست‌ها زیاد است — کمی صبر کنید و دوباره امتحان کنید.', 'bale-eitaa-notifier' ) ), 429 );
		}

		set_transient( $key, $count + 1, 60 );
	}

	/* ------------------------------------------------------------------ */
	/* دکمه ایجکس در تسویه حساب (نمایش خودکار در همه قالب‌ها)                 */
	/* ------------------------------------------------------------------ */

	/**
	 * شرایط نمایش دکمه‌ها برقرار است؟
	 *
	 * @return bool
	 */
	private function checkout_conditions_ok() {
		$options = Bei_Settings::get_options();

		$has_bot = ! empty( $options['tg_bot_username'] ) || ! empty( $options['bale_bot_username'] );

		return $has_bot
			&& ( $this->customer_enabled_for_channel( 'telegram' ) || $this->customer_enabled_for_channel( 'bale' ) );
	}

	/**
	 * بارگذاری CSS/JS دکمه تسویه حساب (فقط در صفحه تسویه حساب).
	 */
	public function enqueue_checkout_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! $this->checkout_conditions_ok() ) {
			return;
		}

		wp_enqueue_style( 'bei-checkout', BEI_PLUGIN_URL . 'assets/css/checkout.css', array(), BEI_VERSION );
		wp_enqueue_script( 'bei-checkout', BEI_PLUGIN_URL . 'assets/js/checkout-subscribe.js', array(), BEI_VERSION, true );
		wp_localize_script(
			'bei-checkout',
			'beiCheckout',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bei_checkout_subscribe' ),
				'i18n'    => array(
					'waiting' => __( 'ربات باز شد — دکمه Start را بزنید…', 'bale-eitaa-notifier' ),
					'done'    => __( '✅ شناسه شما دریافت و ذخیره شد!', 'bale-eitaa-notifier' ),
					'error'   => __( '❌ خطا در فعال‌سازی — دوباره امتحان کنید.', 'bale-eitaa-notifier' ),
				),
			)
		);
	}

	/**
	 * نمایش دکمه‌ها — هم از هوک کلاسیک woocommerce_after_checkout_form و هم از
	 * پاصفحه (تسویه حساب بلوکی) فراخوانی می‌شود؛ پرچم از نمایش دوباره جلوگیری می‌کند.
	 */
	public function maybe_render_checkout_subscribe() {
		if ( self::$bar_rendered ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( ! $this->checkout_conditions_ok() ) {
			return;
		}

		self::$bar_rendered = true;

		$options = Bei_Settings::get_options();

		$buttons = array();
		if ( ! empty( $options['tg_bot_username'] ) && $this->customer_enabled_for_channel( 'telegram' ) ) {
			$buttons[] = array(
				'channel' => 'telegram',
				'label'   => __( '🟦 فعال‌سازی اعلان تلگرام', 'bale-eitaa-notifier' ),
			);
		}
		if ( ! empty( $options['bale_bot_username'] ) && $this->customer_enabled_for_channel( 'bale' ) ) {
			$buttons[] = array(
				'channel' => 'bale',
				'label'   => __( '🟢 فعال‌سازی اعلان بله', 'bale-eitaa-notifier' ),
			);
		}

		if ( empty( $buttons ) ) {
			return;
		}

		echo '<div class="bei-checkout-bar">';
		echo '<span class="bei-checkout-bar-title">' . esc_html__( '🔔 دریافت اعلان وضعیت سفارش:', 'bale-eitaa-notifier' ) . '</span> ';
		foreach ( $buttons as $button ) {
			printf(
				'<button type="button" class="button bei-checkout-btn" data-channel="%s">%s</button> ',
				esc_attr( $button['channel'] ),
				esc_html( $button['label'] )
			);
		}
		echo '<span class="bei-checkout-status" aria-live="polite"></span>';
		echo '</div>';
	}
}
