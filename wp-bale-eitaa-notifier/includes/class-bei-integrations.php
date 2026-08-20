<?php
/**
 * ماژول اتصال به افزونه‌های فرم و فروشگاه‌ساز + رویدادهای وردپرس.
 *
 * هر اتصال فقط زمانی اجرا می‌شود که از صفحه تنظیمات فعال شده باشد.
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Integrations
 */
final class Bei_Integrations {

	/**
	 * ثبت هوک‌های همه اتصال‌ها.
	 */
	public function __construct() {
		add_action( 'wpcf7_mail_sent', array( $this, 'cf7' ), 10, 1 );
		add_action( 'wpforms_process_complete', array( $this, 'wpforms' ), 10, 3 );
		add_action( 'gform_after_submission', array( $this, 'gravity' ), 10, 2 );
		add_action( 'ninja_forms_after_submission', array( $this, 'ninja' ), 10, 1 );
		add_action( 'fluentform_submission_inserted', array( $this, 'fluent' ), 10, 3 );

		// نکته: ووکامرس و فرم‌های المنتور عمداً اینجا هندلر ندارند — سوییچشان در
		// تنظیمات فقط نصب بودن را بررسی می‌کند و منوی فرعی‌شان را فعال می‌کند؛
		// اعلان‌ها توسط ماژول‌های Bei_Woo_Statuses و Bei_Elementor_Forms انجام می‌شود.

		add_action( 'transition_post_status', array( $this, 'maybe_notify_publish' ), 10, 3 );
	}

	/**
	 * Contact Form 7 — wpcf7_mail_sent.
	 *
	 * @param object $contact_form نمونه فرم.
	 */
	public function cf7( $contact_form ) {
		if ( empty( Bei_Settings::get_options()['integ_cf7'] ) ) {
			return;
		}

		$data = array();
		if ( class_exists( 'WPCF7_Submission' ) ) {
			$submission = WPCF7_Submission::get_instance();
			if ( $submission ) {
				$data = $submission->get_posted_data();
			}
		}

		$rows = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( '، ', $value );
			}
			$rows[] = $key . ': ' . $value;
		}

		$title = method_exists( $contact_form, 'title' ) ? $contact_form->title() : __( 'فرم تماس', 'bale-eitaa-notifier' );

		bei()->queue()->notify_async(
			sprintf( /* translators: 1: نام فرم، 2: فیلدها */ __( "📩 فرم «%1\$s» ارسال شد:\n\n%2\$s", 'bale-eitaa-notifier' ), $title, implode( "\n", $rows ) )
		);
	}

	/**
	 * WPForms — wpforms_process_complete.
	 *
	 * @param array $fields    فیلدهای پردازش‌شده.
	 * @param array $entry     داده ورودی.
	 * @param array $form_data داده فرم.
	 */
	public function wpforms( $fields, $entry, $form_data ) {
		if ( empty( Bei_Settings::get_options()['integ_wpforms'] ) ) {
			return;
		}

		$rows = array();
		foreach ( $fields as $field ) {
			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( '' === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( '، ', $value );
			}
			$name   = isset( $field['name'] ) ? $field['name'] : __( 'فیلد', 'bale-eitaa-notifier' );
			$rows[] = $name . ': ' . $value;
		}

		$title = isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : __( 'فرم وردپرسی', 'bale-eitaa-notifier' );

		bei()->queue()->notify_async(
			sprintf( /* translators: 1: نام فرم، 2: فیلدها */ __( "📝 فرم «%1\$s» ثبت شد:\n\n%2\$s", 'bale-eitaa-notifier' ), $title, implode( "\n", $rows ) )
		);
	}

	/**
	 * Gravity Forms — gform_after_submission.
	 *
	 * @param array $entry داده ورودی.
	 * @param array $form  تعریف فرم.
	 */
	public function gravity( $entry, $form ) {
		if ( empty( Bei_Settings::get_options()['integ_gravity'] ) ) {
			return;
		}

		$rows = array();
		foreach ( $form['fields'] as $field ) {
			$value = isset( $entry[ (string) $field->id ] ) ? $entry[ (string) $field->id ] : '';
			if ( '' === $value || null === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( '، ', $value );
			}
			$rows[] = $field->label . ': ' . $value;
		}

		$title = isset( $form['title'] ) ? $form['title'] : __( 'فرم گراویتی', 'bale-eitaa-notifier' );

		bei()->queue()->notify_async(
			sprintf( /* translators: 1: نام فرم، 2: فیلدها */ __( "📝 فرم «%1\$s» ثبت شد:\n\n%2\$s", 'bale-eitaa-notifier' ), $title, implode( "\n", $rows ) )
		);
	}

	/**
	 * Ninja Forms — ninja_forms_after_submission.
	 *
	 * @param array $form_data داده فرم.
	 */
	public function ninja( $form_data ) {
		if ( empty( Bei_Settings::get_options()['integ_ninja'] ) ) {
			return;
		}

		$rows = array();
		foreach ( $form_data['fields'] as $field ) {
			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( '' === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( '، ', $value );
			}
			$label  = isset( $field['label'] ) ? $field['label'] : $field['key'];
			$rows[] = $label . ': ' . $value;
		}

		$title = isset( $form_data['settings']['title'] ) ? $form_data['settings']['title'] : __( 'فرم نینجا', 'bale-eitaa-notifier' );

		bei()->queue()->notify_async(
			sprintf( /* translators: 1: نام فرم، 2: فیلدها */ __( "📝 فرم «%1\$s» ثبت شد:\n\n%2\$s", 'bale-eitaa-notifier' ), $title, implode( "\n", $rows ) )
		);
	}

	/**
	 * Fluent Forms — fluentform_submission_inserted.
	 *
	 * @param int   $entry_id  شناسه ورودی.
	 * @param array $form_data داده ارسالی.
	 * @param object $form     تعریف فرم.
	 */
	public function fluent( $entry_id, $form_data, $form ) {
		if ( empty( Bei_Settings::get_options()['integ_fluent'] ) ) {
			return;
		}

		$rows = array();
		foreach ( $form_data as $key => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = implode( '، ', $value );
			}
			$rows[] = $key . ': ' . $value;
		}

		$title = isset( $form->title ) ? $form->title : __( 'فرم فلوئنت', 'bale-eitaa-notifier' );

		bei()->queue()->notify_async(
			sprintf( /* translators: 1: نام فرم، 2: فیلدها */ __( "📝 فرم «%1\$s» ثبت شد:\n\n%2\$s", 'bale-eitaa-notifier' ), $title, implode( "\n", $rows ) )
		);
	}

	/**
	 * (هندلر elementor حذف شد — اعلان فرم‌های المنتور توسط ماژول Bei_Elementor_Forms
	 * با مدیریت اختصاصی هر فرم در منوی فرعی انجام می‌شود.)
	 */

	/**
	 * اطلاع‌رسانی هنگام انتشار نوشته جدید (در صورت فعال بودن).
	 *
	 * @param string  $new_status وضعیت جدید.
	 * @param string  $old_status وضعیت قبلی.
	 * @param WP_Post $post       نوشته.
	 */
	public function maybe_notify_publish( $new_status, $old_status, $post ) {
		if ( empty( Bei_Settings::get_options()['notify_publish'] ) ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$title = get_the_title( $post );
		$url   = get_permalink( $post );

		bei()->queue()->notify_async(
			sprintf(
				/* translators: 1: عنوان نوشته، 2: آدرس */
				__( "📢 مطلب جدید در سایت منتشر شد:\n\n**%1\$s**\n\n[مشاهده مطلب](%2\$s)", 'bale-eitaa-notifier' ),
				$title,
				$url
			)
		);
	}
}
