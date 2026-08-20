<?php
/**
 * ماژول پیام‌رسان — ارسال به API رسمی بله و ایتا.
 *
 * مستندات رسمی:
 *  - بله : https://docs.bale.ai
 *  - ایتا: https://eitaayar.ir/api/
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Messenger
 */
final class Bei_Messenger {

	/**
	 * آدرس پایه API معمولی بله.
	 */
	const BALE_API = 'https://tapi.bale.ai/bot';

	/**
	 * آدرس پایه API کسب‌وکاری بله.
	 */
	const BALE_BIZ_API = 'https://tapi.bale.ai/business/bot';

	/**
	 * آدرس پایه API ایتایار.
	 */
	const EITAA_API = 'https://eitaayar.ir/api/';

	/**
	 * API «برنامه» ایتا (ارسال به کاربران).
	 */
	const EITAA_APP_API = 'https://eitaayar.ir/api/app/sendMessage';

	/**
	 * آدرس پیش‌فرض API تلگرام.
	 * در صورت نیاز (سرور داخل ایران) از تنظیمات با آدرس جایگزین/رله جایگزین می‌شود.
	 */
	const TELEGRAM_API = 'https://api.telegram.org';

	/**
	 * آدرس پیش‌فرض درگاه Green API (واتساپ میزبانی‌شده).
	 */
	const GREEN_API = 'https://api.green-api.com';

	/**
	 * آدرس پیش‌فرض درگاه Ultramsg (واتساپ میزبانی‌شده).
	 */
	const ULTRAMSG_API = 'https://api.ultramsg.com';

	/**
	 * آدرس پیش‌فرض WhatsApp Cloud API رسمی متا (در ایران فیلتر است — نیازمند رله).
	 */
	const META_API = 'https://graph.facebook.com';

	/**
	 * آدرس CallMeBot — درگاه رایگان واتساپ (پیام به شماره خود کاربر).
	 */
	const CALLMEBOT_API = 'https://api.callmebot.com';

	/**
	 * مهلت درخواست عادی (ثانیه).
	 */
	const TIMEOUT = 30;

	/**
	 * مهلت آپلود فایل (ثانیه).
	 */
	const UPLOAD_TIMEOUT = 120;

	/**
	 * خواندن تنظیمات (همراه با پیش‌فرض‌ها).
	 *
	 * @return array
	 */
	private function options() {
		return Bei_Settings::get_options();
	}

	/**
	 * لیست شناسه‌های گفتگو یک پیام‌رسان (چند شناسه — هر کدام در یک خط یا با کاما).
	 *
	 * @param string $channel 'bale'، 'eitaa'، 'telegram' یا 'whatsapp'.
	 * @return array
	 */
	public function chat_ids( $channel ) {
		$options = $this->options();
		$map     = array(
			'bale'     => 'bale_chat_id',
			'eitaa'    => 'eitaa_chat_id',
			'telegram' => 'tg_chat_id',
			'whatsapp' => 'wa_chat_id',
		);

		$raw = isset( $map[ $channel ] ) ? $options[ $map[ $channel ] ] : '';
		$ids = preg_split( '/[\r\n,]+/', (string) $raw );
		$ids = array_map( 'trim', $ids );

		return array_values( array_filter( $ids ) );
	}

	/**
	 * اجرای یک ارسال برای چند شناسه و جمع‌بندی نتیجه.
	 *
	 * @param array    $chat_ids  شناسه‌های گفتگو.
	 * @param callable $callback  تابع ارسال برای یک شناسه.
	 * @return array|WP_Error
	 */
	private function multi_send( $chat_ids, $callback ) {
		$last   = null;
		$errors = array();

		foreach ( $chat_ids as $chat_id ) {
			$result = call_user_func( $callback, $chat_id );
			if ( is_wp_error( $result ) ) {
				$errors[] = $chat_id . ': ' . $result->get_error_message();
			} else {
				$last = $result;
			}
		}

		if ( null === $last && ! empty( $errors ) ) {
			return new WP_Error( 'bei_multi', implode( ' | ', $errors ) );
		}

		if ( ! empty( $errors ) && is_array( $last ) ) {
			$last['partial_errors'] = $errors;
		}

		return $last;
	}

	/**
	 * ارسال پیام به یک یا چند پیام‌رسان.
	 *
	 * @param string $text    متن پیام.
	 * @param array  $targets آرایه‌ای از 'bale'، 'eitaa'، 'telegram' و/یا 'whatsapp'.
	 * @return array نتیجه به تفکیک هر پیام‌رسان.
	 */
	public function notify( $text, $targets = array( 'bale', 'eitaa' ) ) {
		$results = array();

		// فقط کانال‌هایی که سوییچ فعال‌سازی‌شان در تنظیمات روشن است ارسال می‌شوند.
		$targets = array_values( array_intersect( (array) $targets, Bei_Settings::enabled_channels() ) );

		foreach ( $targets as $target ) {
			/**
			 * فیلتر متن پیش از ارسال — امکان شخصی‌سازی متن برای هر کانال
			 * (مثلاً حذف Markdown برای واتساپ).
			 *
			 * @param string $text   متن پیام.
			 * @param string $target نام کانال ('bale'، 'eitaa'، 'telegram'، 'whatsapp').
			 */
			$channel_text = apply_filters( 'bei_notify_text', $text, $target );

			if ( 'bale' === $target ) {
				$results['bale'] = $this->send_bale( $channel_text );
			} elseif ( 'eitaa' === $target ) {
				$results['eitaa'] = $this->send_eitaa( $channel_text );
			} elseif ( 'telegram' === $target ) {
				$results['telegram'] = $this->send_telegram( $channel_text );
			} elseif ( 'whatsapp' === $target ) {
				$results['whatsapp'] = $this->send_whatsapp( $channel_text );
			}
		}

		return $results;
	}

	/* ------------------------------ بله ------------------------------ */

	/**
	 * ارسال پیام متنی به بله — متد sendMessage.
	 *
	 * پارامترهای مستندات: chat_id (الزامی)، text (الزامی، ۱ تا ۴۰۹۶ کاراکتر)،
	 * reply_to_message_id و reply_markup (اختیاری).
	 *
	 * @param string $text متن پیام.
	 * @param array  $args پارامترهای اضافی (مثل reply_markup).
	 * @return array|WP_Error
	 */
	public function send_bale( $text, $args = array() ) {
		$options = $this->options();

		if ( empty( $options['bale_token'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن بله تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$chat_ids = $this->chat_ids( 'bale' );
		if ( empty( $chat_ids ) ) {
			return new WP_Error( 'bei_config', __( 'شناسه چت بله تنظیم نشده است. (هر شناسه در یک خط)', 'bale-eitaa-notifier' ) );
		}

		$base = $options['bale_business'] ? self::BALE_BIZ_API : self::BALE_API;

		return $this->multi_send(
			$chat_ids,
			function ( $chat_id ) use ( $base, $options, $text, $args ) {
				$payload = wp_parse_args(
					$args,
					array(
						'chat_id' => $chat_id,
						'text'    => $text,
					)
				);

				// مستندات بله: ارسال پارامترها به صورت application/json پشتیبانی می‌شود.
				$response = wp_remote_post(
					$base . $options['bale_token'] . '/sendMessage',
					array(
						'timeout' => self::TIMEOUT,
						'headers' => array( 'Content-Type' => 'application/json' ),
						'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
					)
				);

				return $this->check_response( $response, __( 'بله', 'bale-eitaa-notifier' ) );
			}
		);
	}

	/**
	 * ارسال تصویر به بله — متد sendPhoto.
	 *
	 * @param string $photo   مسیر فایل محلی یا آدرس http(s).
	 * @param string $caption توضیح تصویر.
	 * @return array|WP_Error
	 */
	public function send_bale_photo( $photo, $caption = '' ) {
		$options = $this->options();

		if ( empty( $options['bale_token'] ) || empty( $options['bale_chat_id'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن یا شناسه چت بله تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$base = $options['bale_business'] ? self::BALE_BIZ_API : self::BALE_API;
		$url  = $base . $options['bale_token'] . '/sendPhoto';

		if ( filter_var( $photo, FILTER_VALIDATE_URL ) ) {
			// روش ۲ مستندات بله: ارسال با URL (بله خودش دانلود می‌کند).
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'chat_id' => $options['bale_chat_id'],
							'photo'   => $photo,
							'caption' => $caption,
						),
						JSON_UNESCAPED_UNICODE
					),
				)
			);
		} else {
			// روش ۳ مستندات بله: آپلود multipart/form-data.
			if ( ! file_exists( $photo ) ) {
				return new WP_Error( 'bei_file', sprintf( /* translators: %s: مسیر فایل */ __( 'فایل تصویر پیدا نشد: %s', 'bale-eitaa-notifier' ), $photo ) );
			}

			$parts = $this->multipart_body(
				array(
					'chat_id' => $options['bale_chat_id'],
					'caption' => $caption,
				),
				'photo',
				$photo
			);

			$response = wp_remote_post(
				$url,
				array(
					'timeout' => self::UPLOAD_TIMEOUT,
					'headers' => $parts['headers'],
					'body'    => $parts['body'],
				)
			);
		}

		return $this->check_response( $response, __( 'بله', 'bale-eitaa-notifier' ) );
	}

	/**
	 * ارسال پیام مستقیم به یک گفتگوی مشخص در بله (برای ربات پاسخگوی شناسه).
	 * برخلاف send_bale به فیلد chat_id تنظیمات وابسته نیست و فقط توکن لازم دارد.
	 *
	 * @param string $chat_id شناسه عددی گفتگو.
	 * @param string $text    متن پیام.
	 * @param array  $args    پارامترهای اضافی (مثل reply_markup).
	 * @return array|WP_Error
	 */
	public function send_bale_direct( $chat_id, $text, $args = array() ) {
		$options = $this->options();

		if ( empty( $options['bale_token'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن بله تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$payload = wp_parse_args(
			$args,
			array(
				'chat_id' => $chat_id,
				'text'    => $text,
			)
		);

		$response = wp_remote_post(
			self::BALE_API . $options['bale_token'] . '/sendMessage',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			)
		);

		return $this->check_response( $response, __( 'بله', 'bale-eitaa-notifier' ) );
	}

	/**
	 * ارسال پیام مستقیم به یک گفتگوی مشخص در ایتا.
	 *
	 * @param string $chat_id شناسه گفتگو.
	 * @param string $text    متن پیام.
	 * @param array  $args    پارامترهای اختیاری.
	 * @return array|WP_Error
	 */
	public function send_eitaa_direct( $chat_id, $text, $args = array() ) {
		$options = $this->options();

		if ( empty( $options['eitaa_token'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن ایتا تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$payload = wp_parse_args(
			$args,
			array(
				'chat_id' => $chat_id,
				'text'    => $text,
			)
		);

		$response = wp_remote_post(
			self::EITAA_API . $options['eitaa_token'] . '/sendMessage',
			array(
				'timeout' => self::TIMEOUT,
				'body'    => $payload,
			)
		);

		return $this->check_response( $response, __( 'ایتا', 'bale-eitaa-notifier' ) );
	}

	/**
	 * دریافت اطلاعات ربات بله — متد getMe (تست توکن).
	 *
	 * @return array|WP_Error
	 */
	public function get_bale_me() {
		$options = $this->options();

		if ( empty( $options['bale_token'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن بله تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$base     = $options['bale_business'] ? self::BALE_BIZ_API : self::BALE_API;
		$response = wp_remote_get( $base . $options['bale_token'] . '/getMe', array( 'timeout' => self::TIMEOUT ) );

		return $this->check_response( $response, __( 'بله', 'bale-eitaa-notifier' ) );
	}

	/* ------------------------------ ایتا ------------------------------ */

	/**
	 * ارسال پیام متنی به ایتا — متد sendMessage.
	 *
	 * پارامترهای مستندات: chat_id و text (الزامی)؛ اختیاری: title, date (Unix
	 * Timestamp)، pin، viewCountForDelete، disable_notification، reply_to_message_id.
	 *
	 * @param string $text متن پیام.
	 * @param array  $args پارامترهای اختیاری.
	 * @return array|WP_Error
	 */
	public function send_eitaa( $text, $args = array() ) {
		$options = $this->options();

		if ( empty( $options['eitaa_token'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن ایتا تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$chat_ids = $this->chat_ids( 'eitaa' );
		if ( empty( $chat_ids ) ) {
			return new WP_Error( 'bei_config', __( 'شناسه چت ایتا تنظیم نشده است. (هر شناسه در یک خط)', 'bale-eitaa-notifier' ) );
		}

		return $this->multi_send(
			$chat_ids,
			function ( $chat_id ) use ( $options, $text, $args ) {
				$payload = wp_parse_args(
					$args,
					array(
						'chat_id' => $chat_id,
						'text'    => $text,
					)
				);

				// مستندات ایتایار: ارسال به صورت application/x-www-form-urlencoded پشتیبانی می‌شود.
				$response = wp_remote_post(
					self::EITAA_API . $options['eitaa_token'] . '/sendMessage',
					array(
						'timeout' => self::TIMEOUT,
						'body'    => $payload,
					)
				);

				return $this->check_response( $response, __( 'ایتا', 'bale-eitaa-notifier' ) );
			}
		);
	}

	/**
	 * ارسال فایل به ایتا — متد sendFile (آپلود multipart/form-data).
	 *
	 * نکات مستندات: به‌جای text از caption استفاده می‌شود؛ گیف باید .gif و
	 * استیکر باید .webp باشد.
	 *
	 * @param string $file_path مسیر فایل روی سرور.
	 * @param string $caption   توضیح فایل.
	 * @param array  $extra     پارامترهای اختیاری (title, date, pin, ...).
	 * @return array|WP_Error
	 */
	public function send_eitaa_file( $file_path, $caption = '', $extra = array() ) {
		$options = $this->options();

		if ( empty( $options['eitaa_token'] ) || empty( $options['eitaa_chat_id'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن یا شناسه چت ایتا تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'bei_file', sprintf( /* translators: %s: مسیر فایل */ __( 'فایل پیدا نشد: %s', 'bale-eitaa-notifier' ), $file_path ) );
		}

		$fields = wp_parse_args(
			$extra,
			array(
				'chat_id' => $options['eitaa_chat_id'],
				'caption' => $caption,
			)
		);

		$parts    = $this->multipart_body( $fields, 'file', $file_path );
		$response = wp_remote_post(
			self::EITAA_API . $options['eitaa_token'] . '/sendFile',
			array(
				'timeout' => self::UPLOAD_TIMEOUT,
				'headers' => $parts['headers'],
				'body'    => $parts['body'],
			)
		);

		return $this->check_response( $response, __( 'ایتا', 'bale-eitaa-notifier' ) );
	}

	/**
	 * ارسال پیام به کاربران از طریق API «برنامه» ایتا.
	 *
	 * @param string $token   توکن برنامه (از developer.eitaa.com).
	 * @param string $chat_id ایتا آیدی کاربر (عددی).
	 * @param string $text    متن پیام.
	 * @return array|WP_Error
	 */
	public function send_eitaa_app( $token, $chat_id, $text ) {
		$response = wp_remote_post(
			self::EITAA_APP_API,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'token'   => $token,
						'chat_id' => $chat_id,
						'text'    => $text,
					),
					JSON_UNESCAPED_UNICODE
				),
			)
		);

		return $this->check_response( $response, __( 'ایتا (برنامه)', 'bale-eitaa-notifier' ) );
	}

	/**
	 * دریافت اطلاعات ربات ایتا — متد getMe (تست توکن).
	 *
	 * @return array|WP_Error
	 */
	public function get_eitaa_me() {
		$options = $this->options();

		if ( empty( $options['eitaa_token'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن ایتا تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$response = wp_remote_get( self::EITAA_API . $options['eitaa_token'] . '/getMe', array( 'timeout' => self::TIMEOUT ) );

		return $this->check_response( $response, __( 'ایتا', 'bale-eitaa-notifier' ) );
	}

	/* ------------------------------ تلگرام ------------------------------ */

	/**
	 * آدرس پایه API تلگرام (پیش‌فرض یا آدرس جایگزین از تنظیمات).
	 *
	 * @return string
	 */
	public function telegram_base() {
		$options = $this->options();
		$base    = ! empty( $options['tg_api_base'] ) ? rtrim( $options['tg_api_base'], '/' ) : self::TELEGRAM_API;

		return $base . '/bot';
	}

	/**
	 * افزودن «کلید امنیتی رله» به درخواست‌های تلگرام (در صورت تنظیم بودن).
	 * برای رله‌های چندسایتی که در فایل worker مقدار RELAY_KEY دارند.
	 *
	 * @param string $url آدرس کامل درخواست.
	 * @return string
	 */
	public function with_relay_key( $url ) {
		$options = $this->options();

		if ( empty( $options['tg_relay_key'] ) ) {
			return $url;
		}

		return add_query_arg( 'key', $options['tg_relay_key'], $url );
	}

	/**
	 * ارسال پیام متنی به تلگرام — متد sendMessage.
	 *
	 * مستندات رسمی: https://core.telegram.org/bots/api#sendmessage
	 * به‌صورت پیش‌فرض parse_mode = Markdown ارسال می‌شود (قابل تغییر/حذف از طریق args).
	 * اگر تلگرام متن را با Markdown نپذیرد (کاراکترهای خاص)، ارسال بدون قالب‌بندی تکرار می‌شود.
	 *
	 * @param string $text متن پیام.
	 * @param array  $args پارامترهای اضافی (reply_markup، parse_mode و...).
	 * @return array|WP_Error
	 */
	public function send_telegram( $text, $args = array() ) {
		$options = $this->options();

		if ( empty( $options['tg_token'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن تلگرام تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$chat_ids = $this->chat_ids( 'telegram' );
		if ( empty( $chat_ids ) ) {
			return new WP_Error( 'bei_config', __( 'شناسه چت تلگرام تنظیم نشده است. (هر شناسه در یک خط)', 'bale-eitaa-notifier' ) );
		}

		return $this->multi_send(
			$chat_ids,
			function ( $chat_id ) use ( $options, $text, $args ) {
				$payload = wp_parse_args(
					$args,
					array(
						'chat_id'    => $chat_id,
						'text'       => $text,
						'parse_mode' => 'Markdown',
					)
				);

				return $this->send_telegram_with_retry(
					$this->telegram_base() . $options['tg_token'] . '/sendMessage',
					$payload
				);
			}
		);
	}

	/**
	 * ارسال تصویر به تلگرام — متد sendPhoto (فایل محلی یا URL).
	 *
	 * @param string $photo   مسیر فایل یا آدرس http(s).
	 * @param string $caption توضیح تصویر.
	 * @return array|WP_Error
	 */
	public function send_telegram_photo( $photo, $caption = '' ) {
		$options = $this->options();

		if ( empty( $options['tg_token'] ) || empty( $options['tg_chat_id'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن یا شناسه چت تلگرام تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$url = $this->telegram_base() . $options['tg_token'] . '/sendPhoto';

		if ( filter_var( $photo, FILTER_VALIDATE_URL ) ) {
			return $this->send_telegram_with_retry(
				$url,
				array(
					'chat_id'    => $options['tg_chat_id'],
					'photo'      => $photo,
					'caption'    => $caption,
					'parse_mode' => 'Markdown',
				)
			);
		}

		if ( ! file_exists( $photo ) ) {
			return new WP_Error( 'bei_file', sprintf( /* translators: %s: مسیر فایل */ __( 'فایل تصویر پیدا نشد: %s', 'bale-eitaa-notifier' ), $photo ) );
		}

		$url = $this->with_relay_key( $url );

		$parts = $this->multipart_body(
			array(
				'chat_id' => $options['tg_chat_id'],
				'caption' => $caption,
			),
			'photo',
			$photo
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::UPLOAD_TIMEOUT,
				'headers' => $parts['headers'],
				'body'    => $parts['body'],
			)
		);

		return $this->check_response( $response, __( 'تلگرام', 'bale-eitaa-notifier' ) );
	}

	/**
	 * ارسال پیام مستقیم به یک گفتگوی مشخص در تلگرام (برای ربات پاسخگوی شناسه).
	 *
	 * @param string $chat_id شناسه گفتگو.
	 * @param string $text    متن پیام.
	 * @param array  $args    پارامترهای اضافی (مثل reply_markup).
	 * @return array|WP_Error
	 */
	public function send_telegram_direct( $chat_id, $text, $args = array() ) {
		$options = $this->options();

		if ( empty( $options['tg_token'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن تلگرام تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$payload = wp_parse_args(
			$args,
			array(
				'chat_id'    => $chat_id,
				'text'       => $text,
				'parse_mode' => 'Markdown',
			)
		);

		return $this->send_telegram_with_retry(
			$this->telegram_base() . $options['tg_token'] . '/sendMessage',
			$payload
		);
	}

	/**
	 * دریافت اطلاعات ربات تلگرام — متد getMe (تست توکن).
	 *
	 * @return array|WP_Error
	 */
	public function get_telegram_me() {
		$options = $this->options();

		if ( empty( $options['tg_token'] ) ) {
			return new WP_Error( 'bei_config', __( 'توکن تلگرام تنظیم نشده است.', 'bale-eitaa-notifier' ) );
		}

		$url      = $this->with_relay_key( $this->telegram_base() . $options['tg_token'] . '/getMe' );
		$response = wp_remote_get( $url, array( 'timeout' => self::TIMEOUT ) );

		return $this->check_response( $response, __( 'تلگرام', 'bale-eitaa-notifier' ) );
	}

	/**
	 * ارسال JSON به تلگرام؛ در صورت خطای قالب‌بندی Markdown، بدون parse_mode تکرار می‌شود.
	 *
	 * @param string $url     آدرس متد.
	 * @param array  $payload بدنه درخواست.
	 * @return array|WP_Error
	 */
	private function send_telegram_with_retry( $url, $payload ) {
		$url = $this->with_relay_key( $url );

		$result = $this->check_response(
			wp_remote_post(
				$url,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
				)
			),
			__( 'تلگرام', 'bale-eitaa-notifier' )
		);

		// تلگرام برای Markdown نامعتبر خطای «can't parse entities» برمی‌گرداند.
		if ( is_wp_error( $result ) && ! empty( $payload['parse_mode'] ) && false !== stripos( $result->get_error_message(), 'parse' ) ) {
			unset( $payload['parse_mode'] );
			$result = $this->check_response(
				wp_remote_post(
					$url,
					array(
						'timeout' => self::TIMEOUT,
						'headers' => array( 'Content-Type' => 'application/json' ),
						'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
					)
				),
				__( 'تلگرام', 'bale-eitaa-notifier' )
			);
		}

		return $result;
	}

	/* ------------------------------ واتساپ ------------------------------ */

	/**
	 * آدرس پایه درگاه واتساپ (پیش‌فرض هر درگاه یا آدرس رله از تنظیمات).
	 *
	 * @param string $default آدرس پیش‌فرض درگاه.
	 * @return string
	 */
	public function wa_base( $default ) {
		$options = $this->options();

		return ! empty( $options['wa_api_base'] ) ? rtrim( $options['wa_api_base'], '/' ) : $default;
	}

	/**
	 * ارسال پیام به واتساپ — بسته به درگاه انتخاب‌شده در تنظیمات.
	 *
	 * درگاه‌های رایگان:
	 *  - callmebot : CallMeBot — رایگان، پیام به شماره خودتان (بدون سرور)
	 *  - meta      : WhatsApp Cloud API — با «شماره تست» رایگان متا (تا ۵ شماره، بدون قالب)
	 *
	 * درگاه‌های پولی (در صورت نیاز به ارسال انبوه):
	 *  - greenapi ، ultramsg
	 *
	 * @param string $text متن پیام.
	 * @param array  $args پارامترهای اضافی.
	 * @return array|WP_Error
	 */
	public function send_whatsapp( $text, $args = array() ) {
		$chat_ids = $this->chat_ids( 'whatsapp' );
		if ( empty( $chat_ids ) ) {
			return new WP_Error( 'bei_config', __( 'شماره مقصد واتساپ تنظیم نشده است. (هر شماره در یک خط)', 'bale-eitaa-notifier' ) );
		}

		return $this->multi_send(
			$chat_ids,
			function ( $chat_id ) use ( $text, $args ) {
				return $this->send_wa_one( $chat_id, $text, $args );
			}
		);
	}

	/**
	 * ارسال مستقیم به یک شماره مشخص واتساپ (بدون وابستگی به تنظیمات chat_id).
	 *
	 * @param string $chat_id شماره مقصد (مثل 989123456789 یا 79123@c.us).
	 * @param string $text    متن پیام.
	 * @param array  $args    پارامترهای اضافی.
	 * @return array|WP_Error
	 */
	public function send_whatsapp_direct( $chat_id, $text, $args = array() ) {
		return $this->send_wa_one( $chat_id, $text, $args );
	}

	/**
	 * ارسال تک‌شماره‌ای بر اساس درگاه انتخاب‌شده در تنظیمات.
	 *
	 * @param string $chat_id شماره مقصد.
	 * @param string $text    متن پیام.
	 * @param array  $args    پارامترهای اضافی.
	 * @return array|WP_Error
	 */
	private function send_wa_one( $chat_id, $text, $args = array() ) {
		$options = $this->options();
		$gateway = ! empty( $options['wa_gateway'] ) ? $options['wa_gateway'] : 'callmebot';

		switch ( $gateway ) {
			case 'ultramsg':
				return $this->send_wa_ultramsg( $text, wp_parse_args( array( 'to' => $chat_id ), $args ) );
			case 'meta':
				return $this->send_wa_meta( $text, wp_parse_args( array( 'to' => $chat_id ), $args ) );
			case 'greenapi':
				return $this->send_wa_green( $text, wp_parse_args( array( 'chatId' => $chat_id ), $args ) );
			default:
				return $this->send_wa_callmebot( $text, wp_parse_args( array( 'phone' => $chat_id ), $args ) );
		}
	}

	/**
	 * ارسال با CallMeBot — درگاه رایگان واتساپ.
	 *
	 * مستندات رسمی: https://www.callmebot.com/blog/free-api-whatsapp-messages/
	 * آدرس: GET {apiUrl}/whatsapp.php?phone=<شماره خودتان>&text=<متن>&apikey=<کلید>
	 * راه‌اندازی رایگان: یک‌بار به شماره ربات CallMeBot در واتساپ پیام
	 * «I allow callmebot to send me messages» بدهید تا apikey دریافت کنید.
	 * محدودیت: فقط به شماره‌ای که فعال‌سازی کرده ارسال می‌کند (شخصی).
	 *
	 * @param string $text متن پیام.
	 * @param array  $args پارامترهای اضافی.
	 * @return array|WP_Error
	 */
	public function send_wa_callmebot( $text, $args = array() ) {
		$options = $this->options();

		if ( empty( $options['wa_token'] ) || empty( $options['wa_chat_id'] ) ) {
			return new WP_Error( 'bei_config', __( 'تنظیمات CallMeBot کامل نیست: شماره واتساپ و apikey لازم است.', 'bale-eitaa-notifier' ) );
		}

		$url = add_query_arg(
			wp_parse_args(
				$args,
				array(
					'phone'  => $options['wa_chat_id'],
					'text'   => $text,
					'apikey' => $options['wa_token'],
				)
			),
			$this->wa_base( self::CALLMEBOT_API ) . '/whatsapp.php'
		);

		$response = wp_remote_get( $url, array( 'timeout' => self::TIMEOUT ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = trim( wp_remote_retrieve_body( $response ) );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'bei_http',
				sprintf(
					/* translators: 1: کد HTTP، 2: پاسخ */
					__( 'خطای HTTP در واتساپ (CallMeBot): کد %1$s — %2$s', 'bale-eitaa-notifier' ),
					$code,
					$body ? $body : __( 'بدون پاسخ', 'bale-eitaa-notifier' )
				)
			);
		}

		// پاسخ CallMeBot متن ساده است؛ شروع با ERROR یعنی خطا.
		if ( '' === $body || 0 === stripos( $body, 'error' ) ) {
			return new WP_Error(
				'bei_api',
				sprintf(
					/* translators: %s: متن پاسخ */
					__( 'خطا در واتساپ (CallMeBot): %s', 'bale-eitaa-notifier' ),
					$body ? $body : __( 'پاسخ خالی', 'bale-eitaa-notifier' )
				)
			);
		}

		return array(
			'ok'     => true,
			'result' => $body,
		);
	}

	/**
	 * ارسال با Green API.
	 *
	 * مستندات رسمی: https://green-api.com/docs/api/sending/SendMessage/
	 * آدرس: POST {apiUrl}/waInstance{idInstance}/sendMessage/{apiTokenInstance}
	 * بدنه: {"chatId": "7912xxxxxxx@c.us", "message": "..."}
	 * موفقیت: HTTP 200 + {"idMessage": "..."}
	 *
	 * @param string $text متن پیام.
	 * @param array  $args پارامترهای اضافی.
	 * @return array|WP_Error
	 */
	public function send_wa_green( $text, $args = array() ) {
		$options = $this->options();

		if ( empty( $options['wa_instance'] ) || empty( $options['wa_token'] ) || empty( $options['wa_chat_id'] ) ) {
			return new WP_Error( 'bei_config', __( 'تنظیمات واتساپ (Green API) کامل نیست: idInstance، توکن و chatId لازم است.', 'bale-eitaa-notifier' ) );
		}

		$payload = wp_parse_args(
			$args,
			array(
				'chatId'  => $options['wa_chat_id'],
				'message' => $text,
			)
		);

		$url = $this->wa_base( self::GREEN_API )
			. '/waInstance' . $options['wa_instance']
			. '/sendMessage/' . $options['wa_token'];

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			)
		);

		$result = $this->wa_check_response( $response, __( 'واتساپ (Green API)', 'bale-eitaa-notifier' ) );

		if ( ! is_wp_error( $result ) && empty( $result['idMessage'] ) ) {
			return new WP_Error( 'bei_api', __( 'پاسخ موفق ولی بدون idMessage از Green API — وضعیت نمونه/شماره را در پنل گرین بررسی کنید.', 'bale-eitaa-notifier' ) );
		}

		return $result;
	}

	/**
	 * ارسال با Ultramsg.
	 *
	 * مستندات رسمی: https://docs.ultramsg.com/
	 * آدرس: POST {apiUrl}/{instance_id}/messages/chat
	 * پارامترها: token ، to (شماره بین‌المللی یا @c.us) ، body
	 * موفقیت: {"sent": "true", ...}
	 *
	 * @param string $text متن پیام.
	 * @param array  $args پارامترهای اضافی.
	 * @return array|WP_Error
	 */
	public function send_wa_ultramsg( $text, $args = array() ) {
		$options = $this->options();

		if ( empty( $options['wa_instance'] ) || empty( $options['wa_token'] ) || empty( $options['wa_chat_id'] ) ) {
			return new WP_Error( 'bei_config', __( 'تنظیمات واتساپ (Ultramsg) کامل نیست: instance_id، توکن و شماره مقصد لازم است.', 'bale-eitaa-notifier' ) );
		}

		$payload = wp_parse_args(
			$args,
			array(
				'token' => $options['wa_token'],
				'to'    => $options['wa_chat_id'],
				'body'  => $text,
			)
		);

		$url = $this->wa_base( self::ULTRAMSG_API )
			. '/' . $options['wa_instance']
			. '/messages/chat';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => $payload,
			)
		);

		$result = $this->wa_check_response( $response, __( 'واتساپ (Ultramsg)', 'bale-eitaa-notifier' ) );

		// Ultramsg ممکن است HTTP 200 بدهد ولی ارسال واقعاً ناموفق باشد — فیلد sent بررسی می‌شود.
		if ( ! is_wp_error( $result ) ) {
			$sent = isset( $result['sent'] ) ? $result['sent'] : '';
			if ( 'true' !== (string) $sent && true !== $sent && 1 !== $sent ) {
				$desc = isset( $result['error'] ) ? $result['error'] : __( 'پاسخ نامشخص از Ultramsg', 'bale-eitaa-notifier' );

				return new WP_Error( 'bei_api', sprintf( /* translators: %s: توضیح خطا */ __( 'خطای ارسال در واتساپ (Ultramsg): %s', 'bale-eitaa-notifier' ), $desc ) );
			}
		}

		return $result;
	}

	/**
	 * ارسال با WhatsApp Cloud API رسمی متا.
	 *
	 * مستندات رسمی: https://developers.facebook.com/docs/whatsapp/cloud-api
	 * آدرس: POST {apiUrl}/{phone_number_id}/messages
	 * احراز: Authorization: Bearer {token}
	 * بدنه: {"messaging_product": "whatsapp", "to": "...", "type": "text", "text": {"body": "..."}}
	 *
	 * نکات: graph.facebook.com در ایران فیلتر است (از «آدرس API سفارشی» رله استفاده کنید)
	 * و پیام‌های آغازشده توسط کسب‌وکار باید «قالب تأییدشده» باشند.
	 *
	 * @param string $text متن پیام.
	 * @param array  $args پارامترهای اضافی.
	 * @return array|WP_Error
	 */
	public function send_wa_meta( $text, $args = array() ) {
		$options = $this->options();

		if ( empty( $options['wa_instance'] ) || empty( $options['wa_token'] ) || empty( $options['wa_chat_id'] ) ) {
			return new WP_Error( 'bei_config', __( 'تنظیمات واتساپ (Meta) کامل نیست: Phone Number ID، توکن و شماره مقصد لازم است.', 'bale-eitaa-notifier' ) );
		}

		$payload = wp_parse_args(
			$args,
			array(
				'messaging_product' => 'whatsapp',
				'to'                => $options['wa_chat_id'],
				'type'              => 'text',
				'text'              => array( 'body' => $text ),
			)
		);

		$url = $this->wa_base( self::META_API )
			. '/' . $options['wa_instance']
			. '/messages';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $options['wa_token'],
				),
				'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			)
		);

		$result = $this->wa_check_response( $response, __( 'واتساپ (Meta)', 'bale-eitaa-notifier' ) );

		if ( ! is_wp_error( $result ) && empty( $result['messages'] ) ) {
			return new WP_Error( 'bei_api', __( 'پاسخ موفق ولی بدون messages از Meta — وضعیت قالب پیام و شماره را بررسی کنید.', 'bale-eitaa-notifier' ) );
		}

		return $result;
	}

	/**
	 * بررسی پاسخ درگاه‌های واتساپ.
	 * برخلاف بله/ایتا/تلگرام، این درگاه‌ها فیلد ok ندارند؛ موفقیت بر اساس
	 * کد HTTP 2xx و ساختار پاسخ (idMessage/sent/messages) سنجیده می‌شود.
	 *
	 * @param array|WP_Error $response خروجی wp_remote_post.
	 * @param string         $source   نام درگاه برای متن خطا.
	 * @return array|WP_Error
	 */
	private function wa_check_response( $response, $source ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$desc = 'HTTP ' . $code;
			if ( isset( $data['error'] ) ) {
				if ( is_array( $data['error'] ) && isset( $data['error']['message'] ) ) {
					// قالب خطای متا (WhatsApp Cloud API): error.message
					$desc = $data['error']['message'];
				} else {
					$desc = is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'], JSON_UNESCAPED_UNICODE );
				}
			} elseif ( isset( $data['message'] ) ) {
				$desc = $data['message'];
			} elseif ( isset( $data['details'] ) ) {
				$desc = is_string( $data['details'] ) ? $data['details'] : wp_json_encode( $data['details'], JSON_UNESCAPED_UNICODE );
			}

			return new WP_Error( 'bei_http', sprintf( /* translators: 1: درگاه، 2: توضیح خطا */ __( 'خطای HTTP در %1$s: %2$s', 'bale-eitaa-notifier' ), $source, $desc ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bei_api', sprintf( /* translators: %s: نام درگاه */ __( 'پاسخ نامعتبر از %s', 'bale-eitaa-notifier' ), $source ) );
		}

		return $data;
	}

	/* ------------------------------ ابزار ------------------------------ */

	/**
	 * بررسی پاسخ سرور (در هر دو پیام‌رسان پاسخ JSON با فیلد ok است).
	 *
	 * @param array|WP_Error $response خروجی wp_remote_post/wp_remote_get.
	 * @param string         $source   نام پیام‌رسان برای متن خطا.
	 * @return array|WP_Error
	 */
	private function check_response( $response, $source ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$desc = isset( $data['description'] ) ? $data['description'] : sprintf( /* translators: %s: کد HTTP */ __( 'کد HTTP: %s', 'bale-eitaa-notifier' ), $code );

			return new WP_Error( 'bei_http', sprintf( /* translators: 1: پیام‌رسان، 2: توضیح خطا */ __( 'خطای HTTP در %1$s: %2$s', 'bale-eitaa-notifier' ), $source, $desc ) );
		}

		if ( empty( $data['ok'] ) ) {
			$desc = isset( $data['description'] ) ? $data['description'] : ( isset( $data['error'] ) ? $data['error'] : __( 'نامشخص', 'bale-eitaa-notifier' ) );

			return new WP_Error( 'bei_api', sprintf( /* translators: 1: پیام‌رسان، 2: توضیح خطا */ __( 'خطای API در %1$s: %2$s', 'bale-eitaa-notifier' ), $source, $desc ) );
		}

		return $data;
	}

	/**
	 * ساخت بدنه multipart/form-data به‌صورت دستی
	 * (wp_remote_post به‌صورت پیش‌فرض فایل آپلود نمی‌کند).
	 *
	 * @param array  $fields     فیلدهای متنی.
	 * @param string $file_field نام فیلد فایل (photo یا file).
	 * @param string $file_path  مسیر فایل.
	 * @return array با کلیدهای headers و body.
	 */
	private function multipart_body( $fields, $file_field, $file_path ) {
		$boundary = 'bei-' . wp_generate_password( 24, false );
		$eol      = "\r\n";
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol
				. 'Content-Disposition: form-data; name="' . esc_attr( $name ) . '"' . $eol . $eol
				. $value . $eol;
		}

		$body .= '--' . $boundary . $eol
			. 'Content-Disposition: form-data; name="' . esc_attr( $file_field ) . '"; filename="' . esc_attr( basename( $file_path ) ) . '"' . $eol
			. 'Content-Type: application/octet-stream' . $eol . $eol
			. file_get_contents( $file_path ) . $eol
			. '--' . $boundary . '--' . $eol;

		return array(
			'body'    => $body,
			'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
		);
	}
}
