<?php
/**
 * ماژول فرم‌های المنتور (Elementor Pro) — مدیریت ارسال برای هر فرم.
 *
 * نکته مهم: در المنتور پرو فرم‌ها «قالب» (Template) جداگانه ندارند؛ ویجت Form
 * می‌تواند در هر صفحه‌ای قرار بگیرد. این ماژول همه صفحات ساخته‌شده با المنتور
 * را اسکن می‌کند و ویجت‌های Form داخل آن‌ها را شناسایی و لیست می‌کند.
 *
 * امکانات:
 *  - منوی فرعی «فرم‌های المنتور» (در صورت نصب بودن المنتور پرو و فعال بودن سوییچ)
 *  - لیست خودکار فرم‌ها از صفحات مختلف + سوییچ فعال‌سازی و روش ارسال
 *  - اکشن اختصاصی در «Actions After Submit» خود فرم‌ها (class-bei-elementor-action.php)
 *
 * @package Bale_Eitaa_Notifier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Bei_Elementor_Forms
 */
final class Bei_Elementor_Forms {

	const OPTION_KEY     = 'bei_elementor_options';
	const PAGE_SLUG      = 'bei-elementor';
	const SETTINGS_GROUP = 'bei_elementor_group';

	/**
	 * متن پیش‌فرض پیام (در صورت خالی بودن تنظیمات).
	 *
	 * @return string
	 */
	public static function default_message() {
		return __( "📩 فرم «{form_name}» ثبت شد:\n\n{all_fields}", 'bale-eitaa-notifier' );
	}

	/**
	 * ثبت هوک‌ها.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'elementor_pro/forms/new_record', array( $this, 'on_new_record' ), 10, 2 );
	}

	/**
	 * آیا المنتور پرو نصب است؟
	 *
	 * @return bool
	 */
	public function installed() {
		return defined( 'ELEMENTOR_PRO_VERSION' )
			|| class_exists( 'ElementorPro\\Plugin' )
			|| class_exists( 'ElementorPro_Plugin' );
	}

	/**
	 * مقادیر پیش‌فرض.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled' => 0,
			'forms'   => array(),
		);
	}

	/**
	 * خواندن تنظیمات.
	 *
	 * @return array
	 */
	public static function get_options() {
		$options = get_option( self::OPTION_KEY, array() );

		return wp_parse_args( $options, self::defaults() );
	}

	/**
	 * منوی فرعی — فقط با نصب بودن المنتور پرو و فعال بودن سوییچ.
	 */
	public function admin_menu() {
		if ( ! $this->installed() ) {
			return;
		}

		$options = Bei_Settings::get_options();
		if ( empty( $options['integ_elementor'] ) ) {
			return;
		}

		add_submenu_page(
			Bei_Settings::PAGE_SLUG,
			__( 'فرم‌های المنتور', 'bale-eitaa-notifier' ),
			__( 'فرم‌های المنتور', 'bale-eitaa-notifier' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * ثبت تنظیمات.
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
	 * پاک‌سازی ورودی‌ها.
	 *
	 * @param array $input ورودی خام.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$clean = self::defaults();

		$clean['enabled'] = empty( $input['enabled'] ) ? 0 : 1;

		if ( ! empty( $input['forms'] ) && is_array( $input['forms'] ) ) {
			foreach ( $input['forms'] as $key => $row ) {
				$key = sanitize_key( wp_unslash( $key ) );
				if ( '' === $key || ! is_array( $row ) ) {
					continue;
				}

				$method = isset( $row['method'] ) ? sanitize_key( wp_unslash( $row['method'] ) ) : 'all';

				$clean['forms'][ $key ] = array(
					'enabled'   => empty( $row['enabled'] ) ? 0 : 1,
					'method'    => in_array( $method, array( 'all', 'bale', 'eitaa', 'telegram', 'whatsapp' ), true ) ? $method : 'all',
					'message'   => isset( $row['message'] ) ? sanitize_textarea_field( wp_unslash( $row['message'] ) ) : '',
					'label'     => isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : '',
					'form_name' => isset( $row['form_name'] ) ? sanitize_text_field( wp_unslash( $row['form_name'] ) ) : '',
				);
			}
		}

		return $clean;
	}

	/* ------------------------------------------------------------------ */
	/* اسکن فرم‌ها در صفحات (فرم‌ها در قالب‌های المنتور نیستند)               */
	/* ------------------------------------------------------------------ */

	/**
	 * جستجوی بازگشتی ویجت‌های Form داخل ساختار _elementor_data.
	 *
	 * @param array $elements آرایه عناصر.
	 * @param array $found    خروجی (ارجاعی).
	 */
	private function find_form_widgets( $elements, &$found ) {
		foreach ( (array) $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['elType'], $element['widgetType'] )
				&& 'widget' === $element['elType']
				&& 'form' === $element['widgetType'] ) {
				$found[] = $element;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->find_form_widgets( $element['elements'], $found );
			}
		}
	}

	/**
	 * اسکن همه صفحات المنتور و یافتن ویجت‌های Form.
	 *
	 * خروجی: [کلید => [label, post_title, form_name, post_id]]
	 * کلید: p_{post_id}_{md5(form_name)}
	 *
	 * @return array
	 */
	public function scan_forms() {
		$list = array();

		if ( ! $this->installed() ) {
			return $list;
		}

		$posts = get_posts(
			array(
				'post_type'   => 'any',
				'post_status' => 'publish',
				'numberposts' => 200,
				'meta_key'    => '_elementor_data',
			)
		);

		foreach ( (array) $posts as $post_id ) {
			$post_id = (int) $post_id;
			$data    = get_post_meta( $post_id, '_elementor_data', true );
			if ( empty( $data ) ) {
				continue;
			}

			$decoded = json_decode( $data, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$widgets = array();
			$this->find_form_widgets( $decoded, $widgets );

			foreach ( $widgets as $index => $widget ) {
				$settings  = isset( $widget['settings'] ) && is_array( $widget['settings'] ) ? $widget['settings'] : array();
				$form_name = isset( $settings['form_name'] ) && '' !== trim( $settings['form_name'] ) ? trim( $settings['form_name'] ) : sprintf( /* translators: %s: شماره فرم */ __( 'فرم %s', 'bale-eitaa-notifier' ), $index + 1 );

				$post_title = get_the_title( $post_id );
				if ( '' === $post_title ) {
					$post_title = sprintf( /* translators: %s: شناسه صفحه */ __( 'صفحه #%s', 'bale-eitaa-notifier' ), $post_id );
				}

				$key = 'p_' . $post_id . '_' . md5( $form_name );

				$list[ $key ] = array(
					'label'      => sprintf( /* translators: 1: عنوان صفحه، 2: نام فرم */ __( '%1$s — %2$s', 'bale-eitaa-notifier' ), $post_title, $form_name ),
					'post_title' => $post_title,
					'form_name'  => $form_name,
					'post_id'    => $post_id,
				);
			}
		}

		return $list;
	}

	/**
	 * فهرست نهایی برای نمایش: اسکن تازه + تنظیمات ذخیره‌شده قبلی.
	 *
	 * @return array
	 */
	public function get_form_list() {
		$options = self::get_options();
		$list    = $this->scan_forms();

		foreach ( $options['forms'] as $key => $row ) {
			if ( isset( $list[ $key ] ) ) {
				continue;
			}
			// فرمی که قبلاً تنظیم شده ولی الان در صفحات پیدا نشد (صفحه حذف/تغییر کرده).
			if ( ! empty( $row['label'] ) ) {
				$list[ $key ] = array(
					'label'      => $row['label'] . ' — ' . __( '(دیگر یافت نشد)', 'bale-eitaa-notifier' ),
					'post_title' => '',
					'form_name'  => isset( $row['form_name'] ) ? $row['form_name'] : '',
					'post_id'    => 0,
				);
			}
		}

		return $list;
	}

	/**
	 * گزینه‌های روش ارسال.
	 *
	 * @return array
	 */
	private function method_options() {
		return array(
			'all'      => __( 'همه', 'bale-eitaa-notifier' ),
			'bale'     => __( 'بله', 'bale-eitaa-notifier' ),
			'eitaa'    => __( 'ایتا', 'bale-eitaa-notifier' ),
			'telegram' => __( 'تلگرام', 'bale-eitaa-notifier' ),
			'whatsapp' => __( 'واتساپ', 'bale-eitaa-notifier' ),
		);
	}

	/**
	 * سوییچ استایل‌شده.
	 *
	 * @param string $name    نام ورودی.
	 * @param string $value   مقدار.
	 * @param bool   $checked وضعیت.
	 * @param string $label   برچسب.
	 */
	private function render_switch( $name, $value, $checked, $label = '' ) {
		printf(
			'<label class="bei-switch"><input type="checkbox" name="%1$s" value="%2$s"%3$s /><span class="bei-track" aria-hidden="true"></span>%4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			$label ? '<span class="bei-switch-label">' . esc_html( $label ) . '</span>' : ''
		);
	}

	/**
	 * نمایش صفحه فرم‌های المنتور (فقط سوییچ + روش ارسال).
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = self::get_options();
		$list    = $this->get_form_list();
		?>
		<div class="wrap bei-wrap" dir="rtl">
			<header class="bei-header">
				<div>
					<h1>📝 <?php esc_html_e( 'فرم‌های المنتور', 'bale-eitaa-notifier' ); ?></h1>
					<p class="bei-subtitle"><?php esc_html_e( 'فرم‌ها از صفحات ساخته‌شده با المنتور شناسایی می‌شوند — برای هر فرم فقط روش ارسال را انتخاب کنید.', 'bale-eitaa-notifier' ); ?></p>
				</div>
			</header>

			<?php settings_errors( self::PAGE_SLUG ); ?>

			<div class="bei-grid">
				<div class="bei-main">
					<form method="post" action="options.php">
						<?php settings_fields( self::SETTINGS_GROUP ); ?>

						<section class="bei-card">
							<div class="bei-card-head">
								<span class="bei-icon bei-icon--plug">🔌</span>
								<div>
									<h2><?php esc_html_e( 'مدیریت ارسال فرم‌ها', 'bale-eitaa-notifier' ); ?></h2>
									<p class="bei-hint"><?php esc_html_e( 'فرم‌ها به‌صورت خودکار از ویجت‌های Form در صفحات المنتور شناسایی می‌شوند.', 'bale-eitaa-notifier' ); ?></p>
								</div>
							</div>
							<div class="bei-card-body">
								<div class="bei-field">
									<?php $this->render_switch( 'bei_elementor_options[enabled]', '1', ! empty( $options['enabled'] ), __( 'فعال‌سازی کلی اعلان فرم‌های المنتور', 'bale-eitaa-notifier' ) ); ?>
								</div>

								<?php if ( empty( $list ) ) : ?>
									<div class="bei-info-box">
										<?php esc_html_e( 'هیچ صفحه‌ای با ویجت Form المنتور پیدا نشد. یک فرم در صفحه‌ای بسازید و صفحه را ذخیره/منتشر کنید، سپس این صفحه را تازه کنید. (توجه: فقط صفحات منتشرشده اسکن می‌شوند.)', 'bale-eitaa-notifier' ); ?>
									</div>
								<?php else : ?>
									<div class="bei-wc-table">
										<div class="bei-wc-row bei-wc-row--3col bei-wc-row--head">
											<span><?php esc_html_e( 'فعال', 'bale-eitaa-notifier' ); ?></span>
											<span><?php esc_html_e( 'نام فرم', 'bale-eitaa-notifier' ); ?></span>
											<span><?php esc_html_e( 'روش ارسال', 'bale-eitaa-notifier' ); ?></span>
										</div>
										<?php foreach ( $list as $key => $info ) : ?>
											<?php
											$row = isset( $options['forms'][ $key ] ) ? $options['forms'][ $key ] : array(
												'enabled' => 0,
												'method'  => 'all',
											);
											?>
											<div class="bei-wc-row bei-wc-row--3col">
												<span><?php $this->render_switch( 'bei_elementor_options[forms][' . $key . '][enabled]', '1', ! empty( $row['enabled'] ) ); ?></span>
												<span class="bei-wc-label">
													<?php echo esc_html( $info['label'] ); ?>
													<input type="hidden" name="bei_elementor_options[forms][<?php echo esc_attr( $key ); ?>][label]" value="<?php echo esc_attr( $info['label'] ); ?>" />
													<input type="hidden" name="bei_elementor_options[forms][<?php echo esc_attr( $key ); ?>][form_name]" value="<?php echo esc_attr( $info['form_name'] ); ?>" />
												</span>
												<span>
													<select class="bei-select bei-select--sm" name="bei_elementor_options[forms][<?php echo esc_attr( $key ); ?>][method]">
														<?php foreach ( $this->method_options() as $m => $m_label ) : ?>
															<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $row['method'], $m ); ?>><?php echo esc_html( $m_label ); ?></option>
														<?php endforeach; ?>
													</select>
												</span>
											</div>
										<?php endforeach; ?>
									</div>
									<p class="bei-hint"><?php esc_html_e( 'متن پیام به‌صورت خودکار از قالب پیش‌فرض ساخته می‌شود: 📩 فرم «{form_name}» ثبت شد + همه فیلدها. برای متن دلخواه، از اکشن «اعلان پیام‌رسان‌ها» داخل خود فرم (بخش Actions After Submit) استفاده کنید.', 'bale-eitaa-notifier' ); ?></p>
								<?php endif; ?>
							</div>
						</section>

						<div class="bei-save-bar">
							<?php submit_button( __( 'ذخیره تنظیمات فرم‌ها', 'bale-eitaa-notifier' ), 'button button-primary bei-btn bei-btn-primary', 'submit', false ); ?>
						</div>
					</form>
				</div>

				<aside class="bei-side">
					<section class="bei-card">
						<div class="bei-card-head">
							<span class="bei-icon bei-icon--api">🔤</span>
							<h2><?php esc_html_e( 'متغیرهای پیام پیش‌فرض', 'bale-eitaa-notifier' ); ?></h2>
						</div>
						<div class="bei-card-body">
							<pre class="bei-pre" dir="ltr"><code>{form_name}      {all_fields}
{field:شناسه_فیلد}   {site_name}
{date}           {page_title}</code></pre>
							<p class="bei-hint"><?php esc_html_e( '{all_fields} = همه فیلدها به شکل «عنوان: مقدار» در خط‌های جداگانه.', 'bale-eitaa-notifier' ); ?></p>
						</div>
					</section>

					<section class="bei-card">
						<div class="bei-card-head">
							<span class="bei-icon bei-icon--plug">⚙️</span>
							<h2><?php esc_html_e( 'تنظیمات داخل خود فرم', 'bale-eitaa-notifier' ); ?></h2>
						</div>
						<div class="bei-card-body">
							<p class="bei-hint"><?php esc_html_e( 'علاوه بر این صفحه، می‌توانید در ویرایشگر فرم المنتور از بخش «Actions After Submit» اکشن «اعلان پیام‌رسان‌ها» را اضافه کنید — با سوییچ، انتخاب پیام‌رسان و متن پیام اختصاصی همان فرم.', 'bale-eitaa-notifier' ); ?></p>
						</div>
					</section>
				</aside>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* ارسال                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * هوک ثبت فرم المنتور.
	 *
	 * @param object $record  رکورد ارسالی.
	 * @param object $handler هندلر.
	 */
	public function on_new_record( $record, $handler ) {
		$options = self::get_options();
		if ( empty( $options['enabled'] ) ) {
			return;
		}

		$settings    = $record->get( 'form_settings' );
		$form_name   = isset( $settings['form_name'] ) ? $settings['form_name'] : '';
		$form_id     = isset( $settings['form_id'] ) ? (int) $settings['form_id'] : 0;

		$row = null;

		// تطبیق با کلیدهای اسکن صفحات: p_{post_id}_{md5(form_name)}
		if ( $form_id && '' !== $form_name ) {
			$prefix = 'p_' . $form_id . '_';
			foreach ( $options['forms'] as $key => $candidate ) {
				if ( 0 !== strpos( $key, $prefix ) ) {
					continue;
				}
				$stored_name = isset( $candidate['form_name'] ) ? $candidate['form_name'] : '';
				if ( '' === $stored_name || $stored_name === $form_name || 0 === strcasecmp( $stored_name, $form_name ) ) {
					$row = $candidate;
					break;
				}
			}
		}

		// سازگاری با کلیدهای قدیمی (قالب‌های Form): f_{id}
		if ( null === $row && $form_id && isset( $options['forms'][ 'f_' . $form_id ] ) ) {
			$row = $options['forms'][ 'f_' . $form_id ];
		}

		if ( empty( $row ) || empty( $row['enabled'] ) ) {
			return;
		}

		$message = ! empty( $row['message'] ) ? $row['message'] : self::default_message();
		$text    = $this->build_message( $message, $record, $form_name );

		$targets = $this->method_targets( $row['method'] );
		bei()->queue()->notify_async( $text, $targets );
	}

	/**
	 * تبدیل روش به کانال‌ها.
	 *
	 * @param string $method روش.
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
	 * ساخت متن پیام با متغیرها.
	 *
	 * @param string $template  قالب.
	 * @param object $record    رکورد.
	 * @param string $form_name نام فرم.
	 * @return string
	 */
	public function build_message( $template, $record, $form_name = '' ) {
		if ( '' === trim( $template ) ) {
			$template = self::default_message();
		}

		$fields = $record->get( 'fields' );
		$lines  = array();

		foreach ( (array) $fields as $id => $field ) {
			$label = isset( $field['title'] ) ? $field['title'] : $id;
			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( is_array( $value ) ) {
				$value = implode( '، ', $value );
			}
			$lines[] = $label . ': ' . $value;
		}

		$vars = array(
			'{form_name}'  => $form_name,
			'{all_fields}' => implode( "\n", $lines ),
			'{site_name}'  => get_bloginfo( 'name' ),
			'{date}'       => current_time( 'mysql' ),
			'{page_title}' => get_the_title( get_the_ID() ),
		);

		$text = strtr( $template, $vars );

		// متغیرهای اختصاصی هر فیلد: {field:شناسه}
		foreach ( (array) $fields as $id => $field ) {
			$value = isset( $field['value'] ) ? $field['value'] : '';
			if ( is_array( $value ) ) {
				$value = implode( '، ', $value );
			}
			$text = str_replace( '{field:' . $id . '}', $value, $text );
		}

		return $text;
	}
}
