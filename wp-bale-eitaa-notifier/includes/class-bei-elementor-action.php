<?php
/**
 * اکشن اختصاصی «اعلان پیام‌رسان‌ها» برای بخش Actions After Submit فرم‌های المنتور پرو.
 *
 * این فایل فقط وقتی المنتور پرو فعال است و از طریق هوک
 * elementor_pro/forms/register_action بارگذاری می‌شود (جلوگیری از خطای
 * class_exists در سایت‌های بدون المنتور).
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Elementor_Action
 */
class Bei_Elementor_Action extends \ElementorPro\Modules\Forms\Classes\Action_Base {

	/**
	 * نام یکتای اکشن.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'bei_notify';
	}

	/**
	 * برچسب نمایشی در ویرایشگر فرم.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'اعلان پیام‌رسان‌ها (بله/ایتا/تلگرام/واتساپ)', 'bale-eitaa-notifier' );
	}

	/**
	 * ثبت فیلدهای تنظیمات اکشن در ویرایشگر فرم.
	 *
	 * @param object $widget ویجت فرم.
	 */
	public function register_settings_section( $widget ) {
		$widget->start_controls_section(
			'section_bei',
			array(
				'label'     => $this->get_label(),
				'condition' => array(),
			)
		);

		$widget->add_control(
			'bei_enable',
			array(
				'label'        => __( 'فعال‌سازی ارسال اعلان', 'bale-eitaa-notifier' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '',
				'label_on'     => __( 'بله', 'bale-eitaa-notifier' ),
				'label_off'    => __( 'خیر', 'bale-eitaa-notifier' ),
				'return_value' => 'yes',
			)
		);

		$widget->add_control(
			'bei_method',
			array(
				'label'   => __( 'روش ارسال', 'bale-eitaa-notifier' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'all',
				'options' => array(
					'all'      => __( 'همه', 'bale-eitaa-notifier' ),
					'bale'     => __( 'بله', 'bale-eitaa-notifier' ),
					'eitaa'    => __( 'ایتا', 'bale-eitaa-notifier' ),
					'telegram' => __( 'تلگرام', 'bale-eitaa-notifier' ),
					'whatsapp' => __( 'واتساپ', 'bale-eitaa-notifier' ),
				),
			)
		);

		$widget->add_control(
			'bei_message',
			array(
				'label'       => __( 'متن پیام', 'bale-eitaa-notifier' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'default'     => __( "📩 فرم «{form_name}» ثبت شد:\n\n{all_fields}", 'bale-eitaa-notifier' ),
				'description' => __( 'متغیرها: {form_name} ، {all_fields} ، {field:شناسه} ، {site_name} ، {date} ، {page_title}', 'bale-eitaa-notifier' ),
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * حذف فیلدهای اکشن هنگام خروجی‌گیری/کپی فرم.
	 *
	 * @param array $element داده عنصر.
	 * @return array
	 */
	public function on_export( $element ) {
		unset(
			$element['bei_enable'],
			$element['bei_method'],
			$element['bei_message']
		);

		return $element;
	}

	/**
	 * اجرای اکشن بعد از ثبت موفق فرم.
	 *
	 * نکته مهم (رفع خطای 500): کنترل‌های register_settings_section به «ویجت فرم»
	 * اضافه می‌شوند؛ بنابراین هنگام ارسال، مقادیر داخل $record->get('form_settings')
	 * قرار دارند — نه داخل خود اکشن. روش قدیمی get_settings() در نسخه‌های جدید
	 * المنتور پرو وجود ندارد و باعث Fatal می‌شد.
	 *
	 * @param object $record       رکورد ارسالی.
	 * @param object $ajax_handler هندلر ایجکس.
	 */
	public function run( $record, $ajax_handler ) {
		try {
			$this->dispatch( $record );
		} catch ( \Throwable $e ) {
			// خطای داخلی اکشن نباید ارسال فرم را بشکند (500) — فقط ثبت می‌شود.
			if ( function_exists( 'error_log' ) ) {
				error_log( '[bale-eitaa-notifier] ' . $e->getMessage() );
			}
		}
	}

	/**
	 * منطق اصلی ارسال اعلان.
	 *
	 * @param object $record رکورد ارسالی.
	 */
	private function dispatch( $record ) {
		if ( ! function_exists( 'bei' ) ) {
			return;
		}

		// الگوی رسمی المنتور: تنظیمات اکشن داخل form_settings رکورد است.
		$form_settings = $record->get( 'form_settings' );
		$form_settings = is_array( $form_settings ) ? $form_settings : array();

		// پشتیبان: در برخی نسخه‌های قدیمی تنظیمات از خود اکشن در دسترس است.
		$action_settings = method_exists( $this, 'get_settings' ) ? $this->get_settings() : array();
		$action_settings = is_array( $action_settings ) ? $action_settings : array();

		$enable = isset( $form_settings['bei_enable'] )
			? $form_settings['bei_enable']
			: ( isset( $action_settings['bei_enable'] ) ? $action_settings['bei_enable'] : '' );

		if ( empty( $enable ) || 'yes' !== $enable ) {
			return;
		}

		$method = isset( $form_settings['bei_method'] )
			? $form_settings['bei_method']
			: ( isset( $action_settings['bei_method'] ) ? $action_settings['bei_method'] : 'all' );

		$message = isset( $form_settings['bei_message'] )
			? $form_settings['bei_message']
			: ( isset( $action_settings['bei_message'] ) ? $action_settings['bei_message'] : '' );

		$form_name = isset( $form_settings['form_name'] ) ? $form_settings['form_name'] : '';

		$text = bei()->elementor_forms()->build_message( $message, $record, $form_name );

		$targets = ( 'all' === $method ) ? array( 'bale', 'eitaa', 'telegram', 'whatsapp' ) : array( $method );

		bei()->messenger()->notify( $text, $targets );
	}
}
