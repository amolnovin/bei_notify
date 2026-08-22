<?php
/**
 * ماژول فعال‌سازی خودکار اعلان کاربر — بعد از ورود (Login).
 *
 * واقعیت فنی: ربات‌های تلگرام/بله «نمی‌توانند» پیام اول را خودشان شروع کنند؛
 * هیچ API برای تبدیل نام کاربری به chat_id وجود ندارد و تنها راه، زدن Start
 * توسط خود کاربر است. این ماژول حداکثر خودکارسازی ممکن را انجام می‌دهد:
 *
 *  ۱) در هر بارگذاری صفحه برای کاربر واردشده، متای bei_chat_telegram و
 *     bei_chat_bale بررسی می‌شود.
 *  ۲) اگر خالی باشد و پیام‌رسان مربوطه فعال/پیکربندی باشد، یک اعلان شناور
 *     یک‌کلیکی نمایش داده می‌شود (با دکمه رد برای مزاحم‌نشدن).
 *  ۳) کاربر کلیک می‌کند ← ربات با لینک استارت (توکن یکبارمصرف) باز می‌شود ←
 *     کاربر Start می‌زند ← شناسه از وبهوک/getUpdates به‌صورت خودکار دریافت و
 *     در متای کاربر ذخیره می‌شود ← اعلان دیگر نمایش داده نمی‌شود.
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_User_Subscribe
 */
final class Bei_User_Subscribe {

	const NONCE        = 'bei_user_prompt';
	const DISMISS_META = 'bei_subscribe_dismiss';

	/**
	 * ثبت هوک‌ها.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_prompt' ), 30 );
		add_action( 'wp_ajax_bei_dismiss_prompt', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * کانال‌هایی که برای کاربر جاری «نیاز به فعال‌سازی» دارند.
	 *
	 * @return array ['telegram', 'bale', ...]
	 */
	public function channels_needed() {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() || is_admin() ) {
			return array();
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}

		if ( get_user_meta( $user_id, self::DISMISS_META, true ) ) {
			return array(); // کاربر قبلاً رد کرده — مزاحم نشو.
		}

		$options = Bei_Settings::get_options();
		$woo     = bei()->woo_statuses();

		$needed = array();

		foreach ( array( 'telegram' => 'tg_bot_username', 'bale' => 'bale_bot_username' ) as $channel => $key ) {
			if ( empty( $options[ $key ] ) ) {
				continue; // نام کاربری ربات تنظیم نشده.
			}
			if ( ! in_array( $channel, Bei_Settings::enabled_channels(), true ) ) {
				continue; // پیام‌رسان غیرفعال است.
			}
			if ( ! $woo->customer_enabled_for_channel( $channel ) ) {
				continue; // هیچ وضعیتی با گیرنده مشتری برای این کانال فعال نیست.
			}
			if ( get_user_meta( $user_id, 'bei_chat_' . $channel, true ) ) {
				continue; // شناسه از قبل ذخیره شده.
			}

			$needed[] = $channel;
		}

		return $needed;
	}

	/**
	 * بارگذاری منابع فقط وقتی اعلان قرار است نمایش داده شود.
	 */
	public function enqueue_assets() {
		if ( is_admin() || empty( $this->channels_needed() ) ) {
			return;
		}

		wp_enqueue_style( 'bei-checkout', BEI_PLUGIN_URL . 'assets/css/checkout.css', array(), BEI_VERSION );
		wp_enqueue_script( 'bei-checkout', BEI_PLUGIN_URL . 'assets/js/checkout-subscribe.js', array(), BEI_VERSION, true );
		wp_localize_script(
			'bei-checkout',
			'beiCheckout',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'bei_checkout_subscribe' ),
				'promptNonce' => wp_create_nonce( self::NONCE ),
				'i18n'        => array(
					'waiting' => __( 'ربات باز شد — دکمه Start را بزنید…', 'bale-eitaa-notifier' ),
					'done'    => __( '✅ شناسه شما دریافت و ذخیره شد!', 'bale-eitaa-notifier' ),
					'error'   => __( '❌ خطا در فعال‌سازی — دوباره امتحان کنید.', 'bale-eitaa-notifier' ),
				),
			)
		);
	}

	/**
	 * نمایش اعلان شناور برای کاربر واردشده.
	 */
	public function render_prompt() {
		$needed = $this->channels_needed();
		if ( empty( $needed ) ) {
			return;
		}

		$options  = Bei_Settings::get_options();
		$buttons  = array();

		if ( in_array( 'telegram', $needed, true ) ) {
			$buttons[] = array(
				'channel' => 'telegram',
				'label'   => __( '🟦 فعال‌سازی اعلان تلگرام', 'bale-eitaa-notifier' ),
			);
		}
		if ( in_array( 'bale', $needed, true ) ) {
			$buttons[] = array(
				'channel' => 'bale',
				'label'   => __( '🟢 فعال‌سازی اعلان بله', 'bale-eitaa-notifier' ),
			);
		}

		echo '<div class="bei-checkout-bar bei-prompt-bar">';
		echo '<span class="bei-checkout-bar-title">🔔 ' . esc_html__( 'دریافت اعلان وضعیت سفارش‌ها:', 'bale-eitaa-notifier' ) . '</span> ';
		foreach ( $buttons as $button ) {
			printf(
				'<button type="button" class="button bei-checkout-btn" data-channel="%s">%s</button> ',
				esc_attr( $button['channel'] ),
				esc_html( $button['label'] )
			);
		}
		echo '<button type="button" class="bei-prompt-close" data-bei-dismiss="1" aria-label="' . esc_attr__( 'بستن', 'bale-eitaa-notifier' ) . '">✕</button>';
		echo '<span class="bei-checkout-status" aria-live="polite"></span>';
		echo '</div>';
	}

	/**
	 * ایجکس «رد کردن اعلان» — برای کاربر واردشده، دیگر نمایش داده نمی‌شود.
	 */
	public function ajax_dismiss() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			wp_send_json_error( array( 'message' => __( 'خطای امنیتی.', 'bale-eitaa-notifier' ) ), 403 );
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'ابتدا وارد حساب شوید.', 'bale-eitaa-notifier' ) ), 403 );
		}

		update_user_meta( $user_id, self::DISMISS_META, 1 );

		wp_send_json_success();
	}
}
