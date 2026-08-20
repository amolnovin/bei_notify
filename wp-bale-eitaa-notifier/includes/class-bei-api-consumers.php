<?php
/**
 * ماژول «سایر افزونه‌ها» — فهرست مصرف‌کنندگان API خارجی (REST).
 *
 * هر افزونه/سیستمی که با پارامتر source به REST (wp-json/bei/v1/notify)
 * پیام می‌فرستد، در این فهرست ثبت می‌شود: نام، تعداد ارسال، آخرین زمان و IP.
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Api_Consumers
 */
final class Bei_Api_Consumers {

	const OPTION    = 'bei_api_consumers';
	const PAGE_SLUG = 'bei-api-consumers';
	const NONCE     = 'bei_consumers';

	/**
	 * ثبت هوک‌ها.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_post_bei_consumers_clear', array( $this, 'handle_clear' ) );
	}

	/**
	 * منوی فرعی زیر منوی اصلی افزونه.
	 */
	public function admin_menu() {
		add_submenu_page(
			Bei_Settings::PAGE_SLUG,
			__( 'سایر افزونه‌ها', 'bale-eitaa-notifier' ),
			__( 'سایر افزونه‌ها', 'bale-eitaa-notifier' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * ثبت یک مصرف‌کننده (از REST).
	 *
	 * @param string $source نام افزونه فراخوان.
	 * @param string $ip     آدرس IP.
	 */
	public static function record( $source, $ip = '' ) {
		$source = trim( sanitize_text_field( (string) $source ) );
		if ( '' === $source ) {
			$source = __( 'ناشناس', 'bale-eitaa-notifier' );
		}

		$list = get_option( self::OPTION, array() );
		if ( ! is_array( $list ) ) {
			$list = array();
		}

		$key = sanitize_key( $source );
		if ( '' === $key ) {
			$key = 'unknown_' . substr( md5( $source ), 0, 6 );
		}

		if ( ! isset( $list[ $key ] ) ) {
			$list[ $key ] = array(
				'name'      => $source,
				'count'     => 0,
				'last_seen' => '',
				'last_ip'   => '',
			);
		}

		$list[ $key ]['name']      = $source;
		$list[ $key ]['count']    += 1;
		$list[ $key ]['last_seen'] = current_time( 'mysql' );
		$list[ $key ]['last_ip']   = sanitize_text_field( (string) $ip );

		update_option( self::OPTION, $list );
	}

	/**
	 * فهرست مصرف‌کنندگان.
	 *
	 * @return array
	 */
	public static function entries() {
		$list = get_option( self::OPTION, array() );

		return is_array( $list ) ? $list : array();
	}

	/**
	 * پاک‌سازی فهرست.
	 */
	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'bale-eitaa-notifier' ) );
		}
		check_admin_referer( self::NONCE, 'bei_consumers_nonce' );

		delete_option( self::OPTION );

		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * نمایش صفحه.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$entries = self::entries();
		$rest_url = get_rest_url( null, 'bei/v1/notify' );
		?>
		<div class="wrap bei-wrap" dir="rtl">
			<header class="bei-header">
				<div>
					<h1>🔗 <?php esc_html_e( 'سایر افزونه‌ها', 'bale-eitaa-notifier' ); ?></h1>
					<p class="bei-subtitle"><?php esc_html_e( 'افزونه‌ها و سیستم‌هایی که از طریق API خارجی (REST) به این افزونه متصل شده و پیام ارسال کرده‌اند.', 'bale-eitaa-notifier' ); ?></p>
				</div>
			</header>

			<div class="bei-grid">
				<div class="bei-main">
					<section class="bei-card">
						<div class="bei-card-head">
							<span class="bei-icon bei-icon--api">🌐</span>
							<div>
								<h2><?php esc_html_e( 'فهرست مصرف‌کنندگان API', 'bale-eitaa-notifier' ); ?></h2>
							</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-inline-start:auto">
								<input type="hidden" name="action" value="bei_consumers_clear" />
								<?php wp_nonce_field( self::NONCE, 'bei_consumers_nonce' ); ?>
								<button class="button bei-btn" type="submit">🧹 <?php esc_html_e( 'پاک‌سازی فهرست', 'bale-eitaa-notifier' ); ?></button>
							</form>
						</div>
						<div class="bei-card-body">
							<?php if ( empty( $entries ) ) : ?>
								<p class="bei-hint"><?php esc_html_e( 'هنوز هیچ افزونه‌ای از API خارجی استفاده نکرده است. کافی است افزونه موردنظر با پارامتر source به آدرس REST پیام بفرستد تا همین‌جا ثبت شود.', 'bale-eitaa-notifier' ); ?></p>
							<?php else : ?>
								<div class="bei-wc-table">
									<div class="bei-wc-row bei-wc-row--head">
										<span><?php esc_html_e( 'نام افزونه', 'bale-eitaa-notifier' ); ?></span>
										<span><?php esc_html_e( 'تعداد ارسال', 'bale-eitaa-notifier' ); ?></span>
										<span><?php esc_html_e( 'آخرین ارسال', 'bale-eitaa-notifier' ); ?></span>
										<span><?php esc_html_e( 'IP', 'bale-eitaa-notifier' ); ?></span>
									</div>
									<?php foreach ( $entries as $entry ) : ?>
										<div class="bei-wc-row">
											<span class="bei-wc-label"><?php echo esc_html( $entry['name'] ); ?></span>
											<span><?php echo esc_html( $entry['count'] ); ?></span>
											<span class="bei-hint" dir="ltr"><?php echo esc_html( $entry['last_seen'] ); ?></span>
											<span class="bei-hint" dir="ltr"><?php echo esc_html( $entry['last_ip'] ); ?></span>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
					</section>
				</div>

				<aside class="bei-side">
					<section class="bei-card">
						<div class="bei-card-head">
							<span class="bei-icon bei-icon--docs">📖</span>
							<h2><?php esc_html_e( 'نحوه اتصال افزونه‌ها', 'bale-eitaa-notifier' ); ?></h2>
						</div>
						<div class="bei-card-body">
							<p class="bei-hint"><?php esc_html_e( 'هر افزونه می‌تواند با یک درخواست POST به آدرس زیر پیام بفرستد (احراز هویت با Application Password):', 'bale-eitaa-notifier' ); ?></p>
							<pre class="bei-pre" dir="ltr"><code>curl -X POST "<?php echo esc_html( $rest_url ); ?>" \
  -u "USERNAME:APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"text":"سلام","targets":["telegram"],"source":"my-plugin"}'</code></pre>
							<ul class="bei-steps" style="margin-top:10px">
								<li><?php esc_html_e( 'پارامتر source نام افزونه شما را مشخص می‌کند — بدون آن، ارسال با نام «ناشناس» ثبت می‌شود.', 'bale-eitaa-notifier' ); ?></li>
								<li><?php esc_html_e( 'targets فقط شامل پیام‌رسان‌های «فعال» در تنظیمات است؛ کانال غیرفعال با خطای ۴۰۰ پاسخ می‌گیرد.', 'bale-eitaa-notifier' ); ?></li>
								<li><?php esc_html_e( 'نمونه PHP داخل وردپرس: wp_remote_post با آرایه body و پارامتر source.', 'bale-eitaa-notifier' ); ?></li>
							</ul>
						</div>
					</section>
				</aside>
			</div>
		</div>
		<?php
	}
}
