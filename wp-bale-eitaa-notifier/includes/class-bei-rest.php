<?php
/**
 * ماژول REST API — ارسال پیام از سیستم‌های بیرونی.
 *
 * آدرس:  /wp-json/bei/v1/notify
 * روش:   POST با بدنه JSON — {"text": "...", "targets": ["bale", "eitaa"]}
 * احراز: نام کاربری + Application Password
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Rest
 */
final class Bei_Rest {

	/**
	 * فضای نام مسیر.
	 */
	const NAMESPACE = 'bei/v1';

	/**
	 * ثبت هوک REST.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * ثبت مسیر REST.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/notify',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback' => array( $this, 'handle_notify' ),
			)
		);
	}

	/**
	 * هندلر ارسال پیام.
	 *
	 * @param WP_REST_Request $request درخواست.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_notify( $request ) {
		$text    = (string) $request->get_param( 'text' );
		$targets = $request->get_param( 'targets' );

		if ( '' === $text ) {
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'errors' => array( 'text' => __( 'پارامتر text الزامی است.', 'bale-eitaa-notifier' ) ),
				),
				400
			);
		}

		$enabled = Bei_Settings::enabled_channels();

		if ( empty( $targets ) ) {
			$targets = $enabled;
		}

		$requested = array_intersect( (array) $targets, array( 'bale', 'eitaa', 'telegram', 'whatsapp' ) );
		$targets   = array_intersect( $requested, $enabled );

		if ( empty( $requested ) ) {
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'errors' => array( 'targets' => __( 'مقادیر مجاز: bale، eitaa، telegram و whatsapp', 'bale-eitaa-notifier' ) ),
				),
				400
			);
		}

		if ( empty( $targets ) ) {
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'errors' => array( 'targets' => __( 'هیچ‌یک از پیام‌رسان‌های درخواستی فعال نیستند — ابتدا سوییچ فعال‌سازی آن‌ها را در تنظیمات روشن کنید.', 'bale-eitaa-notifier' ) ),
				),
				400
			);
		}

		// ثبت مصرف‌کننده در فهرست «سایر افزونه‌ها».
		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : $request->get_param( 'source' );
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		Bei_Api_Consumers::record( (string) $source, $ip );

		$results = bei()->messenger()->notify( $text, $targets );

		$sent   = array();
		$errors = array();
		foreach ( $results as $key => $result ) {
			if ( is_wp_error( $result ) ) {
				$errors[ $key ] = $result->get_error_message();
			} else {
				$sent[] = $key;
			}
		}

		if ( empty( $sent ) ) {
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'errors' => $errors,
				),
				502
			);
		}

		return rest_ensure_response(
			array(
				'ok'     => true,
				'sent'   => $sent,
				'errors' => $errors,
			)
		);
	}
}
