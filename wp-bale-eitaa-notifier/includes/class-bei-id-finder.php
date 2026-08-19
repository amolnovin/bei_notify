<?php
/**
 * ماژول شناسه‌یاب — پیدا کردن شناسه عددی گفتگو در بله، ایتا و تلگرام.
 *
 * امکانات:
 *  - خواندن شناسه عددی با ارسال یک پیام تست (از پاسخ result.chat.id) — هر سه پیام‌رسان
 *  - دریافت گفتگوهای اخیر با getUpdates — بله و تلگرام
 *  - ربات پاسخگوی «شناسه شما» با وبهوک و دکمه اینلاین — بله و تلگرام
 *
 * توجه: API ایتایار متد دریافت آپدیت (getUpdates) ندارد؛ به همین دلیل
 * ربات پاسخگو فقط برای بله و تلگرام پیاده‌سازی شده است.
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Id_Finder
 */
final class Bei_Id_Finder {

	const SECRET_OPTION      = 'bei_webhook_secret';
	const WEBHOOK_OPTION     = 'bei_webhook_active';
	const WEBHOOK_OPTION_TG  = 'bei_webhook_active_tg';
	const FOUND_TRANSIENT    = 'bei_found_chats';
	const RESULT_TRANSIENT   = 'bei_id_result';
	const NONCE_ACTION       = 'bei_id_finder';

	/**
	 * ثبت هوک‌ها.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_post_bei_id_probe', array( $this, 'handle_probe' ) );
		add_action( 'admin_post_bei_id_updates', array( $this, 'handle_get_updates' ) );
		add_action( 'admin_post_bei_id_webhook', array( $this, 'handle_webhook_toggle' ) );
		add_action( 'admin_post_bei_webhook_info', array( $this, 'handle_webhook_info' ) );
		add_action( 'admin_post_bei_webhook_simulate', array( $this, 'handle_webhook_simulate' ) );
		add_action( 'admin_post_bei_webhook_fix', array( $this, 'handle_webhook_fix' ) );
	}

	/* ------------------------------------------------------------------ */
	/* تعریف پیام‌رسان‌های قابل پشتیبانی                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * مشخصات پیام‌رسان‌های بخش شناسه‌یاب.
	 *
	 * @return array
	 */
	private function messenger_specs() {
		return array(
			'bale'     => array(
				'label'    => __( 'بله', 'bale-eitaa-notifier' ),
				'token'    => 'bale_token',
				'updates'  => true,
				'webhook'  => true,
			),
			'telegram' => array(
				'label'    => __( 'تلگرام', 'bale-eitaa-notifier' ),
				'token'    => 'tg_token',
				'updates'  => true,
				'webhook'  => true,
			),
			'eitaa'    => array(
				'label'    => __( 'ایتا', 'bale-eitaa-notifier' ),
				'token'    => 'eitaa_token',
				'updates'  => false,
				'webhook'  => false,
			),
		);
	}

	/**
	 * نام نمایشی یک پیام‌رسان.
	 *
	 * @param string $target شناسه پیام‌رسان.
	 * @return string
	 */
	private function label_for( $target ) {
		$specs = $this->messenger_specs();

		return isset( $specs[ $target ]['label'] ) ? $specs[ $target ]['label'] : ucfirst( $target );
	}

	/**
	 * کلاس چیپ برای نمایش در فهرست پیداها.
	 *
	 * @param string $target شناسه پیام‌رسان.
	 * @return string
	 */
	private function chip_class( $target ) {
		if ( 'telegram' === $target ) {
			return 'bei-chip--tg';
		}

		return 'bale' === $target ? 'bei-chip--ok' : 'bei-chip--warn';
	}

	/**
	 * شناسه فیلد chat_id مربوط به هر پیام‌رسان (برای دکمه «استفاده از این شناسه»).
	 *
	 * @param string $target شناسه پیام‌رسان.
	 * @return string
	 */
	private function fill_field( $target ) {
		$map = array(
			'bale'     => 'bei-bale-chat',
			'eitaa'    => 'bei-eitaa-chat',
			'telegram' => 'bei-tg-chat',
		);

		return isset( $map[ $target ] ) ? $map[ $target ] : 'bei-bale-chat';
	}

	/**
	 * کلید توکن هر پیام‌رسان در تنظیمات.
	 *
	 * @param string $target شناسه پیام‌رسان.
	 * @return string
	 */
	private function token_key( $target ) {
		$specs = $this->messenger_specs();

		return isset( $specs[ $target ]['token'] ) ? $specs[ $target ]['token'] : 'bale_token';
	}

	/**
	 * کلید گزینه فعال‌بودن وبهوک.
	 *
	 * @param string $target bale یا telegram.
	 * @return string
	 */
	private function webhook_key( $target ) {
		return 'telegram' === $target ? self::WEBHOOK_OPTION_TG : self::WEBHOOK_OPTION;
	}

	/**
	 * آدرس کامل یک متد API برای پیام‌رسان موردنظر (با توکن).
	 *
	 * @param string $target bale، telegram یا eitaa.
	 * @param string $method نام متد.
	 * @return string
	 */
	private function endpoint( $target, $method ) {
		$options = Bei_Settings::get_options();
		$token   = isset( $options[ $this->token_key( $target ) ] ) ? $options[ $this->token_key( $target ) ] : '';

		if ( 'telegram' === $target ) {
			return bei()->messenger()->with_relay_key( bei()->messenger()->telegram_base() . $token . '/' . $method );
		}
		if ( 'eitaa' === $target ) {
			return Bei_Messenger::EITAA_API . $token . '/' . $method;
		}

		return Bei_Messenger::BALE_API . $token . '/' . $method;
	}

	/* ------------------------------------------------------------------ */
	/* REST — وبهوک (ربات پاسخگوی «شناسه شما» برای بله و تلگرام)            */
	/* ------------------------------------------------------------------ */

	/**
	 * ثبت مسیر وبهوک.
	 */
	public function register_routes() {
		register_rest_route(
			'bei/v1',
			'/bale-webhook',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'webhook_permission' ),
				'callback'            => array( $this, 'handle_webhook' ),
			)
		);
	}

	/**
	 * بررسی کلید محرمانه در آدرس وبهوک.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return bool
	 */
	public function webhook_permission( $request ) {
		$secret = get_option( self::SECRET_OPTION, '' );
		$given  = (string) $request->get_param( 'secret' );

		return ! empty( $secret ) && hash_equals( $secret, $given );
	}

	/**
	 * آدرس کامل وبهوک با کلید محرمانه و پارامتر پیام‌رسان.
	 *
	 * @param string $for bale یا telegram.
	 * @return string
	 */
	public function webhook_url( $for = 'bale' ) {
		return add_query_arg(
			array(
				'secret' => $this->ensure_secret(),
				'for'    => $for,
			),
			rest_url( 'bei/v1/bale-webhook' )
		);
	}

	/**
	 * پاسخ به آپدیت‌ها: نمایش شناسه عددی گفتگو.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return array|WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$update = $request->get_json_params();

		if ( empty( $update ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$for = (string) $request->get_param( 'for' );
		$for = 'telegram' === $for ? 'telegram' : 'bale';

		$chat = null;

		if ( isset( $update['message'] ) ) {
			if ( ! empty( $update['message']['from']['is_bot'] ) ) {
				return rest_ensure_response( array( 'ok' => true ) ); // جلوگیری از حلقه.
			}
			$chat = $update['message']['chat'];
		} elseif ( isset( $update['edited_message'] ) ) {
			if ( ! empty( $update['edited_message']['from']['is_bot'] ) ) {
				return rest_ensure_response( array( 'ok' => true ) );
			}
			$chat = $update['edited_message']['chat'];
		} elseif ( isset( $update['callback_query'] ) ) {
			$chat = isset( $update['callback_query']['message']['chat'] ) ? $update['callback_query']['message']['chat'] : null;
		}

		if ( empty( $chat ) || ! isset( $chat['id'] ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$chat_id = (string) $chat['id'];

		// پردازش لینک «فعال‌سازی اعلان سفارش» (deep link) — ووکامرس.
		$message_text = '';
		if ( isset( $update['message']['text'] ) ) {
			$message_text = $update['message']['text'];
		}
		$deep_reply = bei()->woo_statuses()->handle_deep_link( $chat_id, $message_text, $for );
		if ( $deep_reply ) {
			$this->log_webhook_reply( $for, $chat_id, 'deep_link' );
			if ( 'telegram' === $for ) {
				bei()->messenger()->send_telegram_direct( $chat_id, $deep_reply );
			} else {
				bei()->messenger()->send_bale_direct( $chat_id, $deep_reply );
			}

			return rest_ensure_response( array( 'ok' => true ) );
		}

		$reply_markup = array(
			'inline_keyboard' => array(
				array(
					array(
						'text'          => __( 'شناسه شما', 'bale-eitaa-notifier' ),
						'callback_data' => 'bei_show_id',
					),
				),
			),
		);

		$text = sprintf(
			/* translators: %s: شناسه عددی گفتگو */
			__( "🆔 شناسه عددی این گفتگو: %s\n\nاین شناسه را در تنظیمات افزونه در فیلد chat_id وارد کنید.", 'bale-eitaa-notifier' ),
			$chat_id
		);

		$this->log_webhook_reply( $for, $chat_id, 'id' );

		if ( 'telegram' === $for ) {
			bei()->messenger()->send_telegram_direct( $chat_id, $text, array( 'reply_markup' => $reply_markup ) );
		} else {
			bei()->messenger()->send_bale_direct( $chat_id, $text, array( 'reply_markup' => $reply_markup ) );
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * ثبت تشخیصی آخرین پاسخ وبهوک (برای تست شبیه‌سازی).
	 *
	 * @param string $channel کانال پاسخ‌دهنده.
	 * @param string $chat_id شناسه گفتگو.
	 * @param string $type    نوع پاسخ (id یا deep_link).
	 */
	private function log_webhook_reply( $channel, $chat_id, $type ) {
		set_transient(
			'bei_webhook_last_reply',
			array(
				'channel' => $channel,
				'chat_id' => (string) $chat_id,
				'type'    => $type,
			),
			60
		);
	}

	/* ------------------------------------------------------------------ */
	/* اکشن‌های پنل مدیریت                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * خواندن شناسه عددی با ارسال پیام تست (بله، ایتا یا تلگرام).
	 */
	public function handle_probe() {
		$this->check_access();

		$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'bale';
		$label  = $this->label_for( $target );

		$test_text = __( '🔍 در حال دریافت شناسه عددی... (تست افزونه)', 'bale-eitaa-notifier' );

		if ( 'eitaa' === $target ) {
			$result = bei()->messenger()->send_eitaa( $test_text );
		} elseif ( 'telegram' === $target ) {
			$result = bei()->messenger()->send_telegram( $test_text );
		} else {
			$result = bei()->messenger()->send_bale( $test_text );
		}

		$this->finish_probe( $result, $target, $label );
	}

	/**
	 * تحلیل نتیجه پروب و ذخیره شناسه.
	 *
	 * @param array|WP_Error $result پاسخ API.
	 * @param string         $target 'bale'، 'eitaa' یا 'telegram'.
	 * @param string         $label  نام نمایشی پیام‌رسان.
	 */
	private function finish_probe( $result, $target, $label ) {
		if ( is_wp_error( $result ) ) {
			set_transient( self::RESULT_TRANSIENT, array( 'error', $result->get_error_message() ), 120 );
			$this->redirect_back();
		}

		if ( empty( $result['result']['chat']['id'] ) ) {
			set_transient(
				self::RESULT_TRANSIENT,
				array( 'error', __( 'شناسه عددی در پاسخ API پیدا نشد. مطمئن شوید chat_id (نام کاربری یا لینک دعوت) درست است و ربات ادمین گفتگو باشد.', 'bale-eitaa-notifier' ) ),
				120
			);
			$this->redirect_back();
		}

		$chat    = $result['result']['chat'];
		$chat_id = (string) $chat['id'];

		$title = isset( $chat['title'] ) ? $chat['title'] : '';
		if ( '' === $title && isset( $chat['first_name'] ) ) {
			$title = trim( $chat['first_name'] . ' ' . ( isset( $chat['last_name'] ) ? $chat['last_name'] : '' ) );
		}

		$this->remember_chat(
			array(
				'target'   => $target,
				'id'       => $chat_id,
				'title'    => $title,
				'username' => isset( $chat['username'] ) ? $chat['username'] : '',
			)
		);

		set_transient(
			self::RESULT_TRANSIENT,
			array(
				'ok',
				sprintf(
					/* translators: 1: نام پیام‌رسان، 2: شناسه عددی */
					__( 'شناسه عددی گفتگو در %1$s: %2$s — با دکمه «استفاده از این شناسه» در فیلد chat_id قرار گرفت.', 'bale-eitaa-notifier' ),
					$label,
					$chat_id
				),
				$target,
				$chat_id,
			),
			120
		);

		$this->redirect_back();
	}

	/**
	 * دریافت گفتگوهای اخیر با getUpdates (بله یا تلگرام).
	 */
	public function handle_get_updates() {
		$this->check_access();

		$target = isset( $_POST['messenger'] ) ? sanitize_key( wp_unslash( $_POST['messenger'] ) ) : 'bale';
		$target = ( 'telegram' === $target || 'bale' === $target ) ? $target : 'bale';
		$label  = $this->label_for( $target );

		$options = Bei_Settings::get_options();

		if ( empty( $options[ $this->token_key( $target ) ] ) ) {
			set_transient(
				self::RESULT_TRANSIENT,
				array( 'error', sprintf( /* translators: %s: نام پیام‌رسان */ __( 'ابتدا توکن ربات %s را وارد و ذخیره کنید.', 'bale-eitaa-notifier' ), $label ) ),
				120
			);
			$this->redirect_back();
		}

		$url      = add_query_arg( 'timeout', '0', $this->endpoint( $target, 'getUpdates' ) );
		$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
		$data     = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['ok'] ) ) {
			$desc = isset( $data['description'] ) ? $data['description'] : __( 'نامشخص', 'bale-eitaa-notifier' );
			set_transient(
				self::RESULT_TRANSIENT,
				array(
					'error',
					sprintf(
						/* translators: 1: توضیح خطا، 2: نام پیام‌رسان */
						__( 'دریافت آپدیت‌ها ناموفق بود: %1$s — اگر «ربات پاسخگو» %2$s فعال است ابتدا آن را غیرفعال کنید (getUpdates همزمان با وبهوک کار نمی‌کند).', 'bale-eitaa-notifier' ),
						$desc,
						$label
					),
				),
				120
			);
			$this->redirect_back();
		}

		$chats = $this->extract_chats( isset( $data['result'] ) ? $data['result'] : array(), $target );

		if ( empty( $chats ) ) {
			set_transient(
				self::RESULT_TRANSIENT,
				array(
					'error',
					sprintf(
						/* translators: %s: نام پیام‌رسان */
						__( 'هیچ گفتگویی پیدا نشد. در %s به ربات خود پیام بدهید یا ربات را به گروه اضافه کنید و یک پیام در گروه بفرستید، سپس دوباره تلاش کنید.', 'bale-eitaa-notifier' ),
						$label
					),
				),
				120
			);
			$this->redirect_back();
		}

		foreach ( $chats as $chat ) {
			$this->remember_chat( $chat );
		}

		set_transient(
			self::RESULT_TRANSIENT,
			array(
				'ok',
				sprintf(
					/* translators: 1: تعداد گفتگوها، 2: نام پیام‌رسان */
					__( '٪1$s گفتگو از آپدیت‌های اخیر ربات %2$s استخراج شد:', 'bale-eitaa-notifier' ),
					count( $chats ),
					$label
				),
			),
			120
		);

		$this->redirect_back();
	}

	/**
	 * فعال/غیرفعال‌کردن ربات پاسخگوی «شناسه شما» (بله یا تلگرام).
	 */
	public function handle_webhook_toggle() {
		$this->check_access();

		$target = isset( $_POST['messenger'] ) ? sanitize_key( wp_unslash( $_POST['messenger'] ) ) : 'bale';
		$target = ( 'telegram' === $target || 'bale' === $target ) ? $target : 'bale';
		$label  = $this->label_for( $target );
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		$options = Bei_Settings::get_options();

		if ( empty( $options[ $this->token_key( $target ) ] ) ) {
			set_transient(
				self::RESULT_TRANSIENT,
				array( 'error', sprintf( /* translators: %s: نام پیام‌رسان */ __( 'ابتدا توکن ربات %s را وارد و ذخیره کنید.', 'bale-eitaa-notifier' ), $label ) ),
				120
			);
			$this->redirect_back();
		}

		if ( 'on' === $do ) {
			$webhook_url = $this->webhook_url( $target );
			$url         = add_query_arg( 'url', $webhook_url, $this->endpoint( $target, 'setWebhook' ) );

			// ثبت وبهوک — از مسیر «آدرس API (سفارشی/رله)» انجام می‌شود (telegram_base).
			$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
			$data     = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $data['ok'] ) ) {
				$desc = isset( $data['description'] ) ? $data['description'] : __( 'نامشخص', 'bale-eitaa-notifier' );
				set_transient(
					self::RESULT_TRANSIENT,
					array( 'error', sprintf( /* translators: 1: توضیح خطا، 2: نام پیام‌رسان */ __( 'فعال‌سازی وبهوک %2$s ناموفق بود: %1$s (سایت باید HTTPS معتبر داشته باشد و از بیرون در دسترس باشد)', 'bale-eitaa-notifier' ), $desc, $label ) ),
					120
				);
				$this->redirect_back();
			}

			update_option( $this->webhook_key( $target ), 1 );

			$lines = array(
				sprintf(
					/* translators: 1: نام پیام‌رسان، 2: دکمه */
					__( 'ربات پاسخگوی %1$s فعال شد ✅ — در %1$s دکمه Start را بزنید؛ ربات شناسه عددی گفتگو را با دکمه «%2$s» پاسخ می‌دهد.', 'bale-eitaa-notifier' ),
					$label,
					__( 'شناسه شما', 'bale-eitaa-notifier' )
				),
				__( 'آدرس ثبت‌شده:', 'bale-eitaa-notifier' ) . ' ' . $webhook_url,
			);

			// وضعیت تحویل واقعی از تلگرام (getWebhookInfo از مسیر رله).
			$info_response = wp_remote_get( $this->endpoint( $target, 'getWebhookInfo' ), array( 'timeout' => 30 ) );
			$info_data     = json_decode( wp_remote_retrieve_body( $info_response ), true );

			if ( ! empty( $info_data['ok'] ) && ! empty( $info_data['result'] ) ) {
				if ( ! empty( $info_data['result']['last_error_message'] ) ) {
					$lines[] = '⚠️ ' . __( 'هشدار تلگرام:', 'bale-eitaa-notifier' ) . ' ' . $info_data['result']['last_error_message'];
				}
				if ( ! empty( $info_data['result']['pending_update_count'] ) ) {
					$lines[] = __( 'آپدیت‌های در انتظار تحویل:', 'bale-eitaa-notifier' ) . ' ' . $info_data['result']['pending_update_count'];
				}
			}

			if ( 'telegram' === $target && empty( $options['tg_api_base'] ) ) {
				$lines[] = '⚠️ ' . __( '«آدرس API (سفارشی/رله)» تلگرام تنظیم نشده — روی سرورهای داخل ایران، ثبت وبهوک و پاسخ ربات از api.telegram.org مستقیم انجام می‌شود و احتمالاً نمی‌رسد.', 'bale-eitaa-notifier' );
			}

			set_transient(
				self::RESULT_TRANSIENT,
				array( 'ok', $lines ),
				120
			);
			$this->redirect_back();
		}

		// غیرفعال‌سازی.
		wp_remote_get( $this->endpoint( $target, 'deleteWebhook' ), array( 'timeout' => 30 ) );

		update_option( $this->webhook_key( $target ), 0 );
		set_transient(
			self::RESULT_TRANSIENT,
			array( 'ok', sprintf( /* translators: %s: نام پیام‌رسان */ __( 'ربات پاسخگوی %s غیرفعال شد.', 'bale-eitaa-notifier' ), $label ) ),
			120
		);
		$this->redirect_back();
	}

	/**
	 * بررسی وضعیت وبهوک با getWebhookInfo — نمایش آدرس ثبت‌شده، خطای آخر تحویل
	 * تلگرام و آپدیت‌های در انتظار. بهترین ابزار تشخیص «ربات پاسخ نمی‌دهد».
	 */
	public function handle_webhook_info() {
		$this->check_access();

		$target = isset( $_POST['messenger'] ) ? sanitize_key( wp_unslash( $_POST['messenger'] ) ) : 'bale';
		$target = ( 'telegram' === $target || 'bale' === $target ) ? $target : 'bale';
		$label  = $this->label_for( $target );

		$options   = Bei_Settings::get_options();
		$token_key = $this->token_key( $target );

		if ( empty( $options[ $token_key ] ) ) {
			set_transient( self::RESULT_TRANSIENT, array( 'error', sprintf( /* translators: %s: نام پیام‌رسان */ __( 'ابتدا توکن ربات %s را وارد و ذخیره کنید.', 'bale-eitaa-notifier' ), $label ) ), 120 );
			$this->redirect_back();
		}

		$response = wp_remote_get( $this->endpoint( $target, 'getWebhookInfo' ), array( 'timeout' => 30 ) );
		$data     = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['ok'] ) || empty( $data['result'] ) ) {
			$desc = isset( $data['description'] ) ? $data['description'] : __( 'نامشخص', 'bale-eitaa-notifier' );
			set_transient( self::RESULT_TRANSIENT, array( 'error', sprintf( /* translators: 1: نام پیام‌رسان، 2: توضیح */ __( 'دریافت وضعیت وبهوک %1$s ناموفق بود: %2$s', 'bale-eitaa-notifier' ), $label, $desc ) ), 120 );
			$this->redirect_back();
		}

		$info = $data['result'];

		$lines = array(
			__( '📡 وضعیت وبهوک:', 'bale-eitaa-notifier' ) . ' ' . $label,
		);

		if ( empty( $info['url'] ) ) {
			$lines[] = '❌ ' . __( 'وبهوک ثبت نشده است — از دکمه «فعال‌سازی ربات پاسخگو» استفاده کنید.', 'bale-eitaa-notifier' );
		} else {
			$lines[] = '✅ URL: ' . $info['url'];

			if ( false === strpos( $info['url'], 'for=' . $target ) ) {
				$lines[] = '⚠️ ' . sprintf( /* translators: %s: پارامتر مسیردهی */ __( 'آدرس ثبت‌شده پارامتر %s را ندارد (احتمالاً با نسخه قدیمی ثبت شده) — یک بار «غیرفعال‌سازی» و دوباره «فعال‌سازی» کنید.', 'bale-eitaa-notifier' ), 'for=' . $target );
			}
		}

		if ( ! empty( $info['last_error_message'] ) ) {
			$lines[] = '⚠️ ' . __( 'آخرین خطای تحویل تلگرام:', 'bale-eitaa-notifier' ) . ' ' . $info['last_error_message'];
		}

		if ( ! empty( $info['pending_update_count'] ) ) {
			$lines[] = __( 'آپدیت‌های در انتظار تحویل:', 'bale-eitaa-notifier' ) . ' ' . $info['pending_update_count'];
		}

		$active = (int) get_option( $this->webhook_key( $target ), 0 );
		$lines[] = $active
			? __( 'وضعیت داخلی افزونه: فعال', 'bale-eitaa-notifier' )
			: __( 'وضعیت داخلی افزونه: غیرفعال — برای پاسخگویی ربات، ابتدا «فعال‌سازی ربات پاسخگو» را بزنید.', 'bale-eitaa-notifier' );

		$lines[] = __( 'مسیرها: ثبت وبهوک و پاسخ ربات از «آدرس API (سفارشی/رله)» رد می‌شوند؛ دریافت Start از تلگرام مستقیماً به سایت شما ارسال می‌شود (نیازمند HTTPS معتبر و در دسترس بودن سایت از بیرون).', 'bale-eitaa-notifier' );

		set_transient( self::RESULT_TRANSIENT, array( 'ok', $lines ), 120 );

		$this->redirect_back();
	}

	/**
	 * تست شبیه‌سازی Start — یک آپدیت جعلی مثل تلگرام ساخته می‌شود:
	 *   ۱) منطق هندلر مستقیم اجرا می‌شود (پاسخ از کدام کانال تولید شد؟)
	 *   ۲) درخواست HTTP واقعی به آدرس وبهوک سایت زده می‌شود (همان کاری که تلگرام می‌کند)
	 *
	 * نتیجه دقیقاً نشان می‌دهد زنجیره کجا می‌شکند: منطق، مسیر REST،
	 * کلید محرمانه یا تحویل از بیرون.
	 */
	public function handle_webhook_simulate() {
		$this->check_access();

		$target = isset( $_POST['messenger'] ) ? sanitize_key( wp_unslash( $_POST['messenger'] ) ) : 'telegram';
		$target = in_array( $target, array( 'telegram', 'bale' ), true ) ? $target : 'telegram';
		$label  = $this->label_for( $target );
		$active = (int) get_option( $this->webhook_key( $target ), 0 );

		$fake_update = array(
			'update_id' => 990000001,
			'message'   => array(
				'message_id' => 1,
				'from'       => array( 'id' => 991111, 'is_bot' => false, 'first_name' => __( 'تست', 'bale-eitaa-notifier' ) ),
				'chat'       => array( 'id' => 992222, 'type' => 'private', 'first_name' => __( 'تست', 'bale-eitaa-notifier' ) ),
				'date'       => time(),
				'text'       => '/start',
			),
		);

		$lines = array( '🧪 ' . __( 'تست شبیه‌سازی Start:', 'bale-eitaa-notifier' ) . ' ' . $label );

		// --- بخش ۱: منطق هندلر (بدون شبکه) ---
		delete_transient( 'bei_webhook_last_reply' );
		$fake_request = new WP_REST_Request( 'POST', '/bei/v1/bale-webhook' );
		$fake_request->set_param( 'for', $target );
		$fake_request->set_param( 'secret', $this->ensure_secret() );
		$fake_request->set_body( wp_json_encode( $fake_update, JSON_UNESCAPED_UNICODE ) );
		$fake_request->set_header( 'Content-Type', 'application/json' );
		$this->handle_webhook( $fake_request );

		$last = get_transient( 'bei_webhook_last_reply' );
		if ( $last ) {
			$lines[] = '✅ ' . sprintf(
				/* translators: 1: کانال پاسخ‌دهنده، 2: شناسه گفتگو، 3: نوع پاسخ */
				__( 'منطق پاسخ‌گویی سالم است — پاسخ از مسیر «%1$s» به گفتگوی %2$s ساخته شد (نوع: %3$s).', 'bale-eitaa-notifier' ),
				$last['channel'],
				$last['chat_id'],
				$last['type']
			);
			if ( $last['channel'] !== $target ) {
				$lines[] = '⚠️ ' . __( 'پاسخ از کانال اشتباهی ساخته شد! آدرس وبهوک با نسخه قدیمی (بدون for=) ثبت شده — «غیرفعال‌سازی» و دوباره «فعال‌سازی» کنید.', 'bale-eitaa-notifier' );
			}
		} else {
			$lines[] = '❌ ' . __( 'هندلر هیچ پاسخی تولید نکرد.', 'bale-eitaa-notifier' );
		}

		// --- بخش ۲: HTTP واقعی به آدرس وبهوک (همان کاری که تلگرام می‌کند) ---
		if ( $active ) {
			$response = wp_remote_post(
				$this->webhook_url( $target ),
				array(
					'timeout' => 20,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $fake_update, JSON_UNESCAPED_UNICODE ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$lines[] = '⚠️ ' . __( 'درخواست آزمایشی از داخل سرور به آدرس وبهوک ناموفق بود (این یک درخواست حلقه‌ای است؛ اگر سایت از بیرون در دسترس باشد ممکن است برای تلگرام مشکلی نباشد):', 'bale-eitaa-notifier' ) . ' ' . $response->get_error_message();
			} else {
				$code = wp_remote_retrieve_response_code( $response );
				if ( 200 === $code ) {
					$lines[] = '✅ ' . __( 'آدرس وبهوک پاسخ ۲۰۰ داد — مسیر REST، کلید محرمانه و هندلر سالم‌اند. اگر ربات جواب نمی‌دهد، مشکل در «تحویل از تلگرام به سایت» است (SSL / فایروال / CDN).', 'bale-eitaa-notifier' );
				} elseif ( 401 === $code || 403 === $code ) {
					$lines[] = '❌ ' . sprintf( /* translators: %s: کد HTTP */ __( 'پاسخ HTTP %s — کلید محرمانه نادرست است یا افزونه امنیتی درخواست‌های REST را می‌بندد.', 'bale-eitaa-notifier' ), $code );
				} elseif ( 404 === $code ) {
					$lines[] = '❌ ' . __( 'پاسخ ۴۰۴ — مسیر REST ثبت نشده؛ احتمالاً افزونه‌ای (Disable REST / امنیتی) یا پیوندهای یکتا wp-json را بسته‌اند.', 'bale-eitaa-notifier' );
				} else {
					$lines[] = '⚠️ ' . __( 'پاسخ HTTP غیرمنتظره:', 'bale-eitaa-notifier' ) . ' ' . $code;
				}
			}
		} else {
			$lines[] = '❌ ' . __( 'ربات پاسخگو غیرفعال است — ابتدا «فعال‌سازی ربات پاسخگو» را بزنید.', 'bale-eitaa-notifier' );
		}

		set_transient( self::RESULT_TRANSIENT, array( 'ok', $lines ), 120 );
		$this->redirect_back();
	}

	/**
	 * ترمیم خودکار وبهوک — ثبت مجدد با آدرس صحیح (دارای for=).
	 *
	 * حالت خراب: وبهوک با نسخه قدیمی (بدون پارامتر for) ثبت شده و Start تلگرام
	 * به‌اشتباه از مسیر «بله» پاسخ می‌گیرد. این متد همان setWebhook را با
	 * آدرس جدید (for=telegram یا for=bale) اجرا و نتیجه را تأیید می‌کند.
	 */
	public function handle_webhook_fix() {
		$this->check_access();

		$target = isset( $_POST['messenger'] ) ? sanitize_key( wp_unslash( $_POST['messenger'] ) ) : 'bale';
		$target = ( 'telegram' === $target || 'bale' === $target ) ? $target : 'bale';
		$label  = $this->label_for( $target );

		$options   = Bei_Settings::get_options();
		$token_key = $this->token_key( $target );

		if ( empty( $options[ $token_key ] ) ) {
			set_transient( self::RESULT_TRANSIENT, array( 'error', sprintf( /* translators: %s: نام پیام‌رسان */ __( 'ابتدا توکن ربات %s را وارد و ذخیره کنید.', 'bale-eitaa-notifier' ), $label ) ), 120 );
			$this->redirect_back();
		}

		$webhook_url = $this->webhook_url( $target );
		$url         = add_query_arg( 'url', $webhook_url, $this->endpoint( $target, 'setWebhook' ) );

		$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
		$data     = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['ok'] ) ) {
			$desc = isset( $data['description'] ) ? $data['description'] : __( 'نامشخص', 'bale-eitaa-notifier' );
			set_transient( self::RESULT_TRANSIENT, array( 'error', sprintf( /* translators: 1: نام پیام‌رسان، 2: توضیح */ __( 'ثبت مجدد وبهوک %1$s ناموفق بود: %2$s', 'bale-eitaa-notifier' ), $label, $desc ) ), 120 );
			$this->redirect_back();
		}

		update_option( $this->webhook_key( $target ), 1 );

		$lines = array(
			sprintf(
				/* translators: %s: نام پیام‌رسان */
				__( 'وبهوک %s با آدرس صحیح (دارای for=) دوباره ثبت شد ✅ — حالا در %s دکمه Start را بزنید.', 'bale-eitaa-notifier' ),
				$label,
				$label
			),
			__( 'آدرس ثبت‌شده:', 'bale-eitaa-notifier' ) . ' ' . $webhook_url,
		);

		// تأیید نهایی از خود تلگرام/بله.
		$info_response = wp_remote_get( $this->endpoint( $target, 'getWebhookInfo' ), array( 'timeout' => 30 ) );
		$info_data     = json_decode( wp_remote_retrieve_body( $info_response ), true );

		if ( ! empty( $info_data['ok'] ) && ! empty( $info_data['result'] ) ) {
			if ( ! empty( $info_data['result']['last_error_message'] ) ) {
				$lines[] = '⚠️ ' . __( 'هشدار:', 'bale-eitaa-notifier' ) . ' ' . $info_data['result']['last_error_message'];
			}
			if ( false !== strpos( $info_data['result']['url'], 'for=' . $target ) ) {
				$lines[] = '✅ ' . __( 'تأیید شد: پارامتر for= در آدرس ثبت‌شده موجود است.', 'bale-eitaa-notifier' );
			}
		}

		set_transient( self::RESULT_TRANSIENT, array( 'ok', $lines ), 120 );
		$this->redirect_back();
	}

	/* ------------------------------------------------------------------ */
	/* ابزار                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * بررسی دسترسی و nonce.
	 */
	private function check_access() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'bale-eitaa-notifier' ) );
		}
		check_admin_referer( self::NONCE_ACTION, 'bei_id_nonce' );
	}

	/**
	 * بازگشت به صفحه تنظیمات.
	 */
	private function redirect_back() {
		wp_safe_redirect( add_query_arg( 'page', Bei_Settings::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * ساخت/خواندن کلید محرمانه وبهوک.
	 *
	 * @return string
	 */
	private function ensure_secret() {
		$secret = get_option( self::SECRET_OPTION, '' );
		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 24, false );
			update_option( self::SECRET_OPTION, $secret );
		}

		return $secret;
	}

	/**
	 * استخراج گفتگوها از آرایه آپدیت‌ها.
	 *
	 * @param array  $updates آرایه آپدیت‌های getUpdates.
	 * @param string $target  bale یا telegram.
	 * @return array
	 */
	private function extract_chats( $updates, $target ) {
		$chats = array();

		foreach ( (array) $updates as $update ) {
			$chat = null;

			if ( isset( $update['message'] ) ) {
				$chat = $update['message']['chat'];
			} elseif ( isset( $update['edited_message'] ) ) {
				$chat = $update['edited_message']['chat'];
			} elseif ( isset( $update['callback_query'] ) && isset( $update['callback_query']['message']['chat'] ) ) {
				$chat = $update['callback_query']['message']['chat'];
			}

			if ( empty( $chat ) || ! isset( $chat['id'] ) ) {
				continue;
			}

			$title = isset( $chat['title'] ) ? $chat['title'] : '';
			if ( '' === $title && isset( $chat['first_name'] ) ) {
				$title = trim( $chat['first_name'] . ' ' . ( isset( $chat['last_name'] ) ? $chat['last_name'] : '' ) );
			}

			$chats[ (string) $chat['id'] ] = array(
				'target'   => $target,
				'id'       => (string) $chat['id'],
				'title'    => $title,
				'username' => isset( $chat['username'] ) ? $chat['username'] : '',
			);
		}

		return array_values( $chats );
	}

	/**
	 * نگهداری گفتگوی پیدا شده در ترنزینت (بدون تکرار، حداکثر ۱۲ مورد).
	 *
	 * @param array $chat داده گفتگو.
	 */
	private function remember_chat( $chat ) {
		$found = get_transient( self::FOUND_TRANSIENT );
		$found = is_array( $found ) ? $found : array();

		foreach ( $found as $key => $existing ) {
			if ( $existing['target'] === $chat['target'] && (string) $existing['id'] === (string) $chat['id'] ) {
				unset( $found[ $key ] );
			}
		}

		array_unshift( $found, $chat );
		$found = array_slice( $found, 0, 12 );

		set_transient( self::FOUND_TRANSIENT, $found, DAY_IN_SECONDS );
	}

	/* ------------------------------------------------------------------ */
	/* رابط کاربری                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * نمایش بخش «پیدا کردن شناسه عددی» در صفحه تنظیمات.
	 */
	public function render_section() {
		$found  = get_transient( self::FOUND_TRANSIENT );
		$result = get_transient( self::RESULT_TRANSIENT );

		if ( false !== $result ) {
			delete_transient( self::RESULT_TRANSIENT );
		}

		$admin_post = admin_url( 'admin-post.php' );
		?>
		<section class="bei-card">
			<div class="bei-card-head">
				<span class="bei-icon bei-icon--find">🔎</span>
				<div>
					<h2><?php esc_html_e( 'پیدا کردن شناسه عددی گفتگو', 'bale-eitaa-notifier' ); ?></h2>
					<p class="bei-hint"><?php esc_html_e( 'شناسه عددی با ارسال تست یا ربات پاسخگوی «شناسه شما» به‌دست می‌آید و با یک کلیک در فیلد chat_id قرار می‌گیرد.', 'bale-eitaa-notifier' ); ?></p>
				</div>
			</div>
			<div class="bei-card-body">

				<?php if ( false !== $result ) : ?>
					<?php if ( 'error' === $result[0] ) : ?>
						<div class="bei-notice bei-notice--error"><strong><?php esc_html_e( 'خطا:', 'bale-eitaa-notifier' ); ?></strong> <?php echo esc_html( $result[1] ); ?></div>
					<?php elseif ( isset( $result[3] ) ) : ?>
						<div class="bei-notice bei-notice--success">
							<strong><?php echo esc_html( $result[1] ); ?></strong>
							<button type="button" class="bei-fill" data-bei-fill="<?php echo esc_attr( $this->fill_field( $result[2] ) ); ?>" data-value="<?php echo esc_attr( $result[3] ); ?>"><?php esc_html_e( 'استفاده از این شناسه', 'bale-eitaa-notifier' ); ?></button>
						</div>
				<?php else : ?>
					<div class="bei-notice bei-notice--success">
						<?php if ( is_array( $result[1] ) ) : ?>
							<?php foreach ( $result[1] as $bei_line ) : ?>
								<div><?php echo esc_html( $bei_line ); ?></div>
							<?php endforeach; ?>
						<?php else : ?>
							<strong><?php echo esc_html( $result[1] ); ?></strong>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php endif; ?>

				<?php if ( is_array( $found ) && ! empty( $found ) ) : ?>
					<div class="bei-found-list">
						<span class="bei-label"><?php esc_html_e( 'شناسه‌های پیدا شده:', 'bale-eitaa-notifier' ); ?></span>
						<?php foreach ( $found as $chat ) : ?>
							<div class="bei-find-result">
								<div class="bei-find-meta">
									<span class="bei-chip <?php echo esc_attr( $this->chip_class( $chat['target'] ) ); ?>">
										<?php echo esc_html( $this->label_for( $chat['target'] ) ); ?>
									</span>
									<span class="bei-id-big" dir="ltr"><?php echo esc_html( $chat['id'] ); ?></span>
									<?php if ( ! empty( $chat['title'] ) ) : ?>
										<span class="bei-find-title"><?php echo esc_html( $chat['title'] ); ?></span>
									<?php endif; ?>
								</div>
								<button type="button" class="bei-fill"
									data-bei-fill="<?php echo esc_attr( $this->fill_field( $chat['target'] ) ); ?>"
									data-value="<?php echo esc_attr( $chat['id'] ); ?>">
									<?php esc_html_e( 'استفاده از این شناسه', 'bale-eitaa-notifier' ); ?>
								</button>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="bei-finder">
					<?php $this->render_finder_box( 'bale', '🟢', $admin_post ); ?>
					<?php $this->render_finder_box( 'telegram', '🟦', $admin_post ); ?>
					<?php $this->render_finder_box( 'eitaa', '🟣', $admin_post ); ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * نمایش جعبه راهنمای یک پیام‌رسان.
	 *
	 * @param string $target     شناسه پیام‌رسان.
	 * @param string $icon       ایموجی.
	 * @param string $admin_post آدرس admin-post.
	 */
	private function render_finder_box( $target, $icon, $admin_post ) {
		$label   = $this->label_for( $target );
		$specs   = $this->messenger_specs();
		$can_upd = ! empty( $specs[ $target ]['updates'] );
		$can_hk  = ! empty( $specs[ $target ]['webhook'] );

		$steps = array();

		if ( 'bale' === $target ) {
			$steps = array(
				__( 'ربات را به گفتگو اضافه و ادمین کنید.', 'bale-eitaa-notifier' ),
				__( 'برای کانال: نام کاربری با @ را در فیلد chat_id بگذارید و «خواندن شناسه با ارسال تست» را بزنید.', 'bale-eitaa-notifier' ),
				__( 'برای چت خصوصی یا گروه: «فعال‌سازی ربات پاسخگو» را بزنید و در بله به ربات پیام دهید — ربات شناسه را با دکمه «شناسه شما» نشان می‌دهد.', 'bale-eitaa-notifier' ),
			);
		} elseif ( 'telegram' === $target ) {
			$steps = array(
				__( 'ربات را با @BotFather تلگرام بسازید و به گفتگو اضافه و ادمین کنید.', 'bale-eitaa-notifier' ),
				__( 'اگر سرور داخل ایران است، ابتدا پراکسی یا «آدرس API جایگزین» را در کارت تلگرام تنظیم کنید.', 'bale-eitaa-notifier' ),
				__( 'برای کانال: نام کاربری با @ را در فیلد chat_id بگذارید و «خواندن شناسه با ارسال تست» را بزنید.', 'bale-eitaa-notifier' ),
				__( 'برای چت خصوصی یا گروه: «فعال‌سازی ربات پاسخگو» را بزنید و در تلگرام به ربات پیام دهید — ربات شناسه را با دکمه «شناسه شما» نشان می‌دهد.', 'bale-eitaa-notifier' ),
			);
		} else {
			$steps = array(
				__( 'شناسه عددی هر کانال در پنل ایتایار (بخش کانال‌ها) نمایش داده می‌شود.', 'bale-eitaa-notifier' ),
				__( 'یا username کانال (بدون @) یا لینک دعوت گروه را در فیلد chat_id بگذارید و «خواندن شناسه با ارسال تست» را بزنید.', 'bale-eitaa-notifier' ),
				__( 'API ایتایار متد دریافت آپدیت ندارد؛ به همین دلیل ربات پاسخگو در ایتا موجود نیست.', 'bale-eitaa-notifier' ),
			);
		}

		$webhook_active = $can_hk ? (int) get_option( $this->webhook_key( $target ), 0 ) : 0;
		?>
		<div class="bei-finder-box">
			<h3><?php echo esc_html( $icon . ' ' . $label ); ?></h3>
			<ol class="bei-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li><?php echo esc_html( $step ); ?></li>
				<?php endforeach; ?>
			</ol>
			<div class="bei-finder-actions">
				<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
					<input type="hidden" name="action" value="bei_id_probe" />
					<input type="hidden" name="target" value="<?php echo esc_attr( $target ); ?>" />
					<?php wp_nonce_field( self::NONCE_ACTION, 'bei_id_nonce' ); ?>
					<button class="button bei-btn" type="submit">📤 <?php esc_html_e( 'خواندن شناسه با ارسال تست', 'bale-eitaa-notifier' ); ?></button>
				</form>
				<?php if ( $can_upd ) : ?>
					<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
						<input type="hidden" name="action" value="bei_id_updates" />
						<input type="hidden" name="messenger" value="<?php echo esc_attr( $target ); ?>" />
						<?php wp_nonce_field( self::NONCE_ACTION, 'bei_id_nonce' ); ?>
						<button class="button bei-btn" type="submit">📥 <?php esc_html_e( 'دریافت گفتگوهای اخیر', 'bale-eitaa-notifier' ); ?></button>
					</form>
				<?php endif; ?>
				<?php if ( $can_hk ) : ?>
					<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
						<input type="hidden" name="action" value="bei_id_webhook" />
						<input type="hidden" name="messenger" value="<?php echo esc_attr( $target ); ?>" />
						<input type="hidden" name="do" value="<?php echo $webhook_active ? 'off' : 'on'; ?>" />
						<?php wp_nonce_field( self::NONCE_ACTION, 'bei_id_nonce' ); ?>
					<button class="button bei-btn" type="submit">
						<?php echo $webhook_active ? esc_html__( '⏹ غیرفعال‌سازی ربات پاسخگو', 'bale-eitaa-notifier' ) : esc_html__( '🤖 فعال‌سازی ربات پاسخگو', 'bale-eitaa-notifier' ); ?>
					</button>
				</form>
				<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
					<input type="hidden" name="action" value="bei_webhook_info" />
					<input type="hidden" name="messenger" value="<?php echo esc_attr( $target ); ?>" />
					<?php wp_nonce_field( self::NONCE_ACTION, 'bei_id_nonce' ); ?>
					<button class="button bei-btn" type="submit">📡 <?php esc_html_e( 'بررسی وضعیت وبهوک', 'bale-eitaa-notifier' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
					<input type="hidden" name="action" value="bei_webhook_simulate" />
					<input type="hidden" name="messenger" value="<?php echo esc_attr( $target ); ?>" />
					<?php wp_nonce_field( self::NONCE_ACTION, 'bei_id_nonce' ); ?>
					<button class="button bei-btn" type="submit">🧪 <?php esc_html_e( 'تست شبیه‌سازی Start', 'bale-eitaa-notifier' ); ?></button>
				</form>
				<?php if ( $webhook_active ) : ?>
					<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
						<input type="hidden" name="action" value="bei_webhook_fix" />
						<input type="hidden" name="messenger" value="<?php echo esc_attr( $target ); ?>" />
						<?php wp_nonce_field( self::NONCE_ACTION, 'bei_id_nonce' ); ?>
						<button class="button bei-btn" type="submit">🔧 <?php esc_html_e( 'ترمیم خودکار وبهوک', 'bale-eitaa-notifier' ); ?></button>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php if ( $can_hk ) : ?>
			<p class="bei-hint">
				<?php
				echo $webhook_active
					? esc_html__( 'وضعیت: ربات پاسخگو فعال است — به ربات خود پیام دهید تا شناسه را بفرستد. (تا وقتی فعال است، «گفتگوهای اخیر» کار نمی‌کند)', 'bale-eitaa-notifier' )
					: esc_html__( 'وضعیت: ربات پاسخگو غیرفعال است.', 'bale-eitaa-notifier' );
				?>
			</p>
			<p class="bei-hint">
				<?php esc_html_e( 'نکته: ثبت وبهوک و «پاسخ‌های» ربات از «آدرس API (سفارشی/رله)» تلگرام رد می‌شوند (اگر تنظیم باشد)؛ اما دریافت Start از تلگرام مستقیماً به سایت شما ارسال می‌شود — سایت باید HTTPS معتبر داشته باشد و از بیرون در دسترس باشد. اگر ربات جواب نداد، «بررسی وضعیت وبهوک» را بزنید؛ خطای آخر تحویل تلگرام دقیقاً علت را نشان می‌دهد.', 'bale-eitaa-notifier' ); ?>
			</p>
			<?php else : ?>
				<p class="bei-hint"><?php esc_html_e( 'نیازی به شناسه عددی نیست؛ username کانال بدون @ هم به‌عنوان chat_id کار می‌کند.', 'bale-eitaa-notifier' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
