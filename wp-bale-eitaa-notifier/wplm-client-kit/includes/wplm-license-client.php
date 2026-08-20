<?php
/**
 * WPLM License Client
 *
 * Client-side license activation/checking helper compatible with wp-plugin-market-manager.
 * Put this file inside the product plugin/theme you sell.
 *
 * @version 1.3.1
 */

if (!defined('ABSPATH')) {
    exit;
}


if (!class_exists('WPLM_Client_Kit_License')) {
    final class WPLM_Client_Kit_License
    {
        private $config = [];
        private $action = '';
        private $page_slug = '';

        public function __construct(array $config)
        {
            $defaults = [
                'api_base'          => '', // https://your-shop.com/wp-json/wplm/v1
                'product_slug'      => '',
                'plugin_file'       => '', // __FILE__ from the main plugin file
                'license_option'    => '',
                'status_option'     => '',
                'data_option'       => '',
                'capability'        => 'manage_options',
                'menu_parent'       => 'options-general.php',
                'page_title'        => 'فعال‌سازی لایسنس',
                'menu_title'        => 'لایسنس افزونه',
                'plugin_name'       => 'افزونه',
                'cache_ttl'         => 12 * HOUR_IN_SECONDS,
                'request_timeout'   => 15,
                'require_ssl'       => true,
                'allow_insecure'    => false,
                'grace_on_error'    => true, // keep previous active status on network errors
                'show_notices'      => true,
                'manager_page'      => true,
                'manager_required'  => true,
                'manager_download_url' => 'https://amolnovin.ir/wp-content/plugins/wp-plugin-market-manager/client/wplm-amolnovin-license-manager.zip',
                'manager_plugin_file' => 'wplm-amolnovin-license-manager/wplm-amolnovin-license-manager.php',
                'individual_page'   => false,
                'lock_message'      => 'برای استفاده از افزونه، ابتدا لایسنس را فعال کنید.',
            ];

            $this->config = wp_parse_args($config, $defaults);
            $this->config['product_slug'] = sanitize_key($this->config['product_slug']);
            $this->config['api_base'] = $this->normalize_api_url((string) $this->config['api_base']);
            $this->config['plugin_file'] = $this->config['plugin_file'] ? plugin_basename($this->config['plugin_file']) : '';

            if (!$this->config['license_option']) {
                $this->config['license_option'] = 'wplm_license_' . $this->config['product_slug'];
            }
            if (!$this->config['status_option']) {
                $this->config['status_option'] = 'wplm_license_status_' . $this->config['product_slug'];
            }
            if (!$this->config['data_option']) {
                $this->config['data_option'] = 'wplm_license_data_' . $this->config['product_slug'];
            }

            $this->action = 'wplm_client_save_license_' . $this->config['product_slug'];
            $this->page_slug = $this->config['product_slug'] . '-license';

            if (!empty($this->config['manager_page'])) {
                $this->register_with_manager();
                add_action('plugins_loaded', [$this, 'register_with_manager'], 20);
                add_action('admin_init', [$this, 'register_with_manager'], 5);
                add_action('admin_notices', [$this, 'manager_missing_notice']);
            }

            if (!empty($this->config['individual_page'])) {
                add_action('admin_menu', [$this, 'register_menu']);
            }
            add_action('admin_post_' . $this->action, [$this, 'handle_save']);
            add_action('admin_notices', [$this, 'admin_notice']);

            if ($this->config['plugin_file']) {
                add_filter('plugin_action_links_' . $this->config['plugin_file'], [$this, 'plugin_action_links']);
            }
        }

        public function register_menu(): void
        {
            add_submenu_page(
                $this->config['menu_parent'],
                $this->config['page_title'],
                $this->config['menu_title'],
                $this->config['capability'],
                $this->page_slug,
                [$this, 'render_page']
            );
        }

        public function plugin_action_links(array $links): array
        {
            if (!current_user_can($this->config['capability'])) {
                return $links;
            }

            $url = admin_url($this->config['menu_parent'] . '?page=' . $this->page_slug);
            array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('لایسنس', 'default') . '</a>');
            return $links;
        }

        public function render_page(): void
        {
            if (!current_user_can($this->config['capability'])) {
                wp_die(esc_html__('دسترسی غیرمجاز است.', 'default'));
            }

            $license = $this->get_license_key();
            $status = $this->get_status();
            $data = $this->get_data();
            $is_active = 'active' === $status;
            ?>
            <div class="wrap" dir="rtl">
                <h1><?php echo esc_html($this->config['page_title']); ?></h1>
                <p><?php echo esc_html($this->config['plugin_name']); ?></p>

                <?php if ($is_active) : ?>
                    <div class="notice notice-success"><p>لایسنس فعال است.</p></div>
                <?php else : ?>
                    <div class="notice notice-warning"><p>لایسنس فعال نیست یا نیاز به بررسی دارد.</p></div>
                <?php endif; ?>

                <?php if (!empty($data['message'])) : ?>
                    <div class="notice notice-info"><p><?php echo esc_html($data['message']); ?></p></div>
                <?php endif; ?>

                <?php if (!$this->api_is_allowed()) : ?>
                    <div class="notice notice-error"><p>آدرس API باید HTTPS معتبر باشد. برای محیط تست می‌توانید allow_insecure را true کنید.</p></div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr($this->action); ?>">
                    <?php wp_nonce_field($this->action); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="wplm_license_key">کلید لایسنس</label></th>
                            <td>
                                <input id="wplm_license_key" name="license_key" type="text" class="regular-text" dir="ltr" autocomplete="off" value="<?php echo esc_attr($license); ?>">
                                <p class="description">کلید لایسنس دریافت‌شده پس از خرید را وارد کنید.</p>
                            </td>
                        </tr>
                        <?php if ($license) : ?>
                            <tr>
                                <th scope="row">حذف لایسنس محلی</th>
                                <td><label><input type="checkbox" name="clear_license" value="1"> حذف کلید ذخیره‌شده از این سایت</label></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                    <?php submit_button('ذخیره و فعال‌سازی'); ?>
                </form>

                <hr>
                <h2>اطلاعات فنی</h2>
                <table class="widefat striped" style="max-width:820px">
                    <tbody>
                    <tr><td>Product Slug</td><td dir="ltr"><code><?php echo esc_html($this->config['product_slug']); ?></code></td></tr>
                    <tr><td>Domain</td><td dir="ltr"><code><?php echo esc_html($this->domain()); ?></code></td></tr>
                    <tr><td>API</td><td dir="ltr"><code><?php echo esc_html($this->config['api_base']); ?></code></td></tr>
                    <tr><td>آخرین بررسی</td><td><?php echo !empty($data['checked_at']) ? esc_html(mysql2date('Y/m/d H:i', $data['checked_at'])) : '—'; ?></td></tr>
                    <tr><td>کد وضعیت</td><td dir="ltr"><code><?php echo esc_html($data['code'] ?? '—'); ?></code></td></tr>
                    </tbody>
                </table>
            </div>
            <?php
        }

        public function handle_save(): void
        {
            if (!current_user_can($this->config['capability'])) {
                wp_die(esc_html__('دسترسی غیرمجاز است.', 'default'));
            }

            check_admin_referer($this->action);

            if (!empty($_POST['clear_license'])) {
                $this->clear_license();
                wp_safe_redirect($this->page_url(['cleared' => 1]));
                exit;
            }

            $license = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
            $this->set_license_key($license);
            $result = $this->activate($license);

            wp_safe_redirect($this->page_url(['activated' => !empty($result['success']) ? 1 : 0]));
            exit;
        }

        public function admin_notice(): void
        {
            if (!$this->config['show_notices'] || !current_user_can($this->config['capability'])) {
                return;
            }

            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && (false !== strpos((string) $screen->id, $this->page_slug) || false !== strpos((string) $screen->id, 'wplm-amolnovin-license-manager'))) {
                return;
            }

            if ($this->is_active()) {
                return;
            }

            $url = $this->page_url();
            echo '<div class="notice notice-error"><p>' . esc_html($this->config['lock_message']) . ' <a href="' . esc_url($url) . '">فعال‌سازی لایسنس</a></p></div>';
        }

        public function is_active(bool $force = false): bool
        {
            $license = $this->get_license_key();
            if (!$license) {
                $this->set_status('inactive', ['code' => 'license_missing', 'message' => 'کلید لایسنس وارد نشده است.']);
                return false;
            }

            if (!$force) {
                $cached = get_transient($this->cache_key());
                if (false !== $cached) {
                    return 'active' === $cached;
                }
            }

            $result = $this->check($license);
            return !empty($result['success']);
        }

        public function guard(callable $callback = null): bool
        {
            if ($this->is_active()) {
                return true;
            }

            if ($callback) {
                call_user_func($callback, $this);
            }
            return false;
        }

        public function activate(string $license): array
        {
            $result = $this->request('/license/activate', [
                'product_slug' => $this->config['product_slug'],
                'license_key'  => $license,
                'domain'       => $this->domain(),
            ]);

            $this->store_result($result);
            delete_transient($this->cache_key());
            return $result;
        }

        public function check(string $license = ''): array
        {
            $license = $license ?: $this->get_license_key();
            if (!$license) {
                $result = ['success' => false, 'code' => 'license_missing', 'message' => 'کلید لایسنس وارد نشده است.'];
                $this->store_result($result);
                return $result;
            }

            $result = $this->request('/license/check', [
                'product_slug' => $this->config['product_slug'],
                'license_key'  => $license,
                'domain'       => $this->domain(),
            ]);

            $this->store_result($result);
            return $result;
        }

        private function request(string $endpoint, array $body): array
        {
            if (!$this->api_is_allowed()) {
                return ['success' => false, 'code' => 'insecure_api', 'message' => 'آدرس API امن نیست.'];
            }

            $url = $this->config['api_base'] . $endpoint;
            $response = wp_remote_post($url, [
                'timeout'     => (int) $this->config['request_timeout'],
                'redirection' => 3,
                'sslverify'   => true,
                'headers'     => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'WPLM-Client-Kit/1.0; ' . home_url('/'),
                ],
                'body'        => $body,
            ]);

            if (is_wp_error($response)) {
                if ($this->config['grace_on_error'] && 'active' === $this->get_status()) {
                    set_transient($this->cache_key(), 'active', min((int) $this->config['cache_ttl'], HOUR_IN_SECONDS));
                    return ['success' => true, 'code' => 'network_grace', 'message' => 'به دلیل خطای شبکه، وضعیت فعال قبلی موقتاً حفظ شد.'];
                }
                return ['success' => false, 'code' => $response->get_error_code(), 'message' => $response->get_error_message()];
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            $data = is_array($data) ? $data : ['success' => false, 'code' => 'bad_response'];
            $data['http_status'] = $status_code;

            if ($status_code >= 400) {
                $data['success'] = false;
            }

            return $data;
        }

        private function store_result(array $result): void
        {
            $active = !empty($result['success']);
            $this->set_status($active ? 'active' : 'inactive', [
                'code' => $result['code'] ?? ($active ? 'ok' : 'failed'),
                'message' => $this->message_for_code($result['code'] ?? '') ?: ($result['message'] ?? ''),
                'checked_at' => current_time('mysql'),
                'http_status' => $result['http_status'] ?? null,
            ]);
            set_transient($this->cache_key(), $active ? 'active' : 'inactive', (int) $this->config['cache_ttl']);
        }

        private function message_for_code(string $code): string
        {
            $map = [
                'ok' => 'لایسنس معتبر است.',
                'activated' => 'لایسنس با موفقیت فعال شد.',
                'not_found' => 'لایسنس پیدا نشد.',
                'product_mismatch' => 'لایسنس برای این محصول نیست.',
                'inactive' => 'لایسنس غیرفعال یا مسدود است.',
                'expired' => 'لایسنس منقضی شده است.',
                'domain_limit' => 'سقف دامنه‌های مجاز این لایسنس تکمیل شده است.',
                'rate_limited' => 'تعداد درخواست‌ها بیش از حد مجاز است. بعداً تلاش کنید.',
            ];
            return $map[$code] ?? '';
        }



        public function register_with_manager(): void
        {
            if (class_exists('WPLM_Amolnovin_License_Manager')) {
                WPLM_Amolnovin_License_Manager::register($this);
            }
        }

        public function manager_missing_notice(): void
        {
            if (empty($this->config['manager_required']) || !current_user_can('activate_plugins')) {
                return;
            }
            if (class_exists('WPLM_Amolnovin_License_Manager')) {
                return;
            }
            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();
                if ($screen && false !== strpos((string) $screen->id, 'wplm-amolnovin-license-manager')) {
                    return;
                }
            }

            $plugin_file = (string) $this->config['manager_plugin_file'];
            $installed = defined('WP_PLUGIN_DIR') && file_exists(trailingslashit(WP_PLUGIN_DIR) . $plugin_file);
            $message = 'برای مدیریت لایسنس افزونه‌های آمل نوین، افزونه «لایسنس منیجر آمل نوین» باید نصب و فعال باشد.';
            $links = [];
            if ($installed) {
                $activate_url = wp_nonce_url(admin_url('plugins.php?action=activate&plugin=' . rawurlencode($plugin_file)), 'activate-plugin_' . $plugin_file);
                $links[] = '<a class="button button-primary" href="' . esc_url($activate_url) . '">فعال‌سازی افزونه</a>';
            } else {
                $links[] = '<a class="button button-primary" href="' . esc_url(admin_url('plugin-install.php?tab=upload')) . '">نصب از طریق بارگذاری ZIP</a>';
                if (!empty($this->config['manager_download_url'])) {
                    $links[] = '<a class="button" target="_blank" rel="noopener" href="' . esc_url($this->config['manager_download_url']) . '">دانلود افزونه لایسنس منیجر</a>';
                }
            }

            echo '<div class="notice notice-warning"><p>' . esc_html($message) . '</p><p>' . implode(' ', $links) . '</p></div>';
        }

        public function get_config(): array
        {
            return $this->config;
        }

        public function get_product_slug(): string
        {
            return (string) $this->config['product_slug'];
        }

        public function status(): string
        {
            return $this->get_status();
        }

        public function data(): array
        {
            return $this->get_data();
        }

        public function save_and_activate(string $license): array
        {
            $license = sanitize_text_field($license);
            $this->set_license_key($license);
            return $this->activate($license);
        }

        public function clear_saved_license(): void
        {
            $this->clear_license();
        }

        private function normalize_api_url(string $url): string
        {
            $url = trim($url);
            $url = preg_replace('/\s+/', '', $url);

            if ($url && 0 === strpos($url, '//')) {
                $url = 'https:' . $url;
            }

            if ($url && !preg_match('#^https?://#i', $url)) {
                $url = 'https://' . $url;
            }

            return untrailingslashit(esc_url_raw($url));
        }

        private function api_is_allowed(): bool
        {
            if (empty($this->config['api_base'])) {
                return false;
            }

            $parts = wp_parse_url($this->config['api_base']);
            if (empty($parts['scheme']) || empty($parts['host'])) {
                return false;
            }

            $scheme = strtolower((string) $parts['scheme']);
            if (!in_array($scheme, ['http', 'https'], true)) {
                return false;
            }

            if ($this->config['require_ssl'] && !$this->config['allow_insecure'] && 'https' !== $scheme) {
                return false;
            }

            return true;
        }

        private function page_url(array $args = []): string
        {
            if (!empty($this->config['manager_page']) && class_exists('WPLM_Amolnovin_License_Manager')) {
                return WPLM_Amolnovin_License_Manager::page_url(array_merge(['product' => $this->config['product_slug']], $args));
            }
            return add_query_arg(array_merge(['page' => $this->page_slug], $args), admin_url($this->config['menu_parent']));
        }

        private function cache_key(): string
        {
            return 'wplm_client_status_' . md5($this->config['product_slug'] . '|' . $this->domain() . '|' . $this->get_license_key());
        }

        public function get_license_key(): string
        {
            return (string) get_option($this->config['license_option'], '');
        }

        private function set_license_key(string $license): void
        {
            update_option($this->config['license_option'], $license, false);
        }

        private function get_status(): string
        {
            return (string) get_option($this->config['status_option'], 'inactive');
        }

        private function set_status(string $status, array $data = []): void
        {
            update_option($this->config['status_option'], $status, false);
            update_option($this->config['data_option'], $data, false);
        }

        private function get_data(): array
        {
            $data = get_option($this->config['data_option'], []);
            return is_array($data) ? $data : [];
        }

        private function clear_license(): void
        {
            delete_option($this->config['license_option']);
            delete_option($this->config['status_option']);
            delete_option($this->config['data_option']);
            delete_transient($this->cache_key());
        }

        public function domain(): string
        {
            $host = wp_parse_url(home_url(), PHP_URL_HOST);
            $host = $host ? strtolower($host) : home_url();
            $host = preg_replace('/^www\./', '', (string) $host);
            return sanitize_text_field($host);
        }
    }
}
