<?php
// phpcs:ignoreFile -- Test bootstrap and mocks.
/**
 * Test Bootstrap and WordPress Test Environment Mocks.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/var/www/html/' );
}
if ( ! defined( 'WP_DEBUG' ) ) {
    define( 'WP_DEBUG', true );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'GECX_TESTING' ) ) {
    define( 'GECX_TESTING', true );
}
if ( ! defined( 'GECX_VERSION' ) ) {
    define( 'GECX_VERSION', '1.0.0' );
}

/**
 * Resets every global the harness owns.
 *
 * Called once at load and from setUp() in both test case base classes. It
 * exists because there are three reset sites and they were previously
 * duplicated verbatim, so a new global could be added to one and forgotten in
 * the others.
 */
function gecx_reset_test_globals(): void {
    $GLOBALS['gecx_test_options']              = [];
    $GLOBALS['gecx_test_option_autoload']      = [];
    $GLOBALS['gecx_test_current_user']         = null;
    $GLOBALS['gecx_test_cookie_user_id']       = 0;
    $GLOBALS['gecx_test_users']                = [];
    $GLOBALS['gecx_test_transients']           = [];
    $GLOBALS['gecx_test_last_redirect']        = null;
    $GLOBALS['gecx_test_last_json_response']   = null;
    $GLOBALS['gecx_test_post_meta']            = [];
    $GLOBALS['gecx_test_is_product']           = false;
    $GLOBALS['gecx_test_the_id']               = 101;
    $GLOBALS['gecx_test_queried_object_id']    = 101;
    $GLOBALS['gecx_test_wp_salt']              = 'secret_salt';
    $GLOBALS['gecx_test_webhooks']             = [];
    $GLOBALS['gecx_test_orders']               = [];
    $GLOBALS['gecx_test_disable_wc_webhook']   = false;
    if ( class_exists( 'GECX_Mock_WPDB' ) ) {
        $GLOBALS['wpdb'] = new GECX_Mock_WPDB();
    }
    $GLOBALS['gecx_test_filter_callbacks']     = [];
    $GLOBALS['gecx_test_actions']              = [];
    $GLOBALS['gecx_test_rest_url_prefix']      = 'wp-json';
    $GLOBALS['gecx_test_home_url']             = 'https://example.com';
    $GLOBALS['gecx_test_site_url']             = null;
    $GLOBALS['gecx_test_rest_plain_permalinks'] = false;
    $GLOBALS['gecx_test_http_responses']       = [];
    $GLOBALS['gecx_test_http_requests']        = [];
    $GLOBALS['gecx_test_rest_routes']          = [];
    $GLOBALS['gecx_test_settings_errors']      = [];
    $GLOBALS['gecx_test_inline_styles']        = [];
    $GLOBALS['gecx_test_localized_scripts']    = [];
    $GLOBALS['gecx_test_wc_session']           = null;

    // Request context. Every one of these is false for a storefront page
    // render, which is what the majority of tests assume.
    $GLOBALS['gecx_test_is_admin']         = false;
    $GLOBALS['gecx_test_doing_ajax']       = false;
    $GLOBALS['gecx_test_is_json_request']  = false;
    $GLOBALS['gecx_test_is_feed']          = false;

    // Network context. Single site unless a test says otherwise.
    $GLOBALS['gecx_test_is_multisite']     = false;
    $GLOBALS['gecx_test_sites']            = [];
    $GLOBALS['gecx_test_switched_blogs']   = [];
    $GLOBALS['gecx_test_blog_stack']       = [];

    // Strings a test wants __() to translate, keyed by the untranslated text.
    $GLOBALS['gecx_test_translations']     = [];

    $GLOBALS['gecx_test_enqueued_styles']  = [];
    $GLOBALS['gecx_test_enqueued_scripts'] = [];

    // Route resolution state. Tests that set this must not leak it into the
    // next test, which would silently change which branch of
    // GECX_Auth::is_store_api_request() runs.
    unset( $GLOBALS['wp'] );

    $GLOBALS['gecx_test_home_url'] = 'https://example.com';

    $_GET  = [];
    $_POST = [];
    // $_COOKIE drives the CSRF branch of check_admin_permissions(), so it is
    // reset here rather than by hand in the tests that set it.
    $_COOKIE = [];
    // argv/argc survive: the standalone CLI runner at the foot of each test
    // file decides whether to run from argv[0]. SCRIPT_NAME defaults to the
    // front controller, which is what a REST request reports.
    $_SERVER = array_merge(
        [
            'REQUEST_URI'    => '/',
            'SCRIPT_NAME'    => '/index.php',
            'HTTP_HOST'      => 'example.com',
            'REQUEST_METHOD' => 'GET',
        ],
        array_intersect_key( $_SERVER, [ 'argv' => 1, 'argc' => 1, 'HTTP_HOST' => 1, 'REQUEST_METHOD' => 1 ] )
    );

    if ( class_exists( 'GECX_Auth' ) ) {
        GECX_Auth::reset_cart_token_state();
    }
}

gecx_reset_test_globals();

// Mock WordPress classes
if ( ! class_exists( 'WC_Webhook' ) ) {
    class WC_Webhook {
        private int $id = 0;
        private string $name = '';
        private int $user_id = 0;
        private string $topic = '';
        private string $delivery_url = '';
        private string $secret = '';
        private string $status = 'active';
        private string $api_version = 'wp_api_v3';

        public function __construct( $id = 0 ) {
            $this->id = (int) $id;
            if ( $this->id > 0 && isset( $GLOBALS['gecx_test_webhooks'][ $this->id ] ) ) {
                $data = $GLOBALS['gecx_test_webhooks'][ $this->id ];
                $this->name         = $data['name'] ?? '';
                $this->user_id      = $data['user_id'] ?? 0;
                $this->topic        = $data['topic'] ?? '';
                $this->delivery_url = $data['delivery_url'] ?? '';
                $this->secret       = $data['secret'] ?? '';
                $this->status       = $data['status'] ?? 'active';
                $this->api_version  = $data['api_version'] ?? 'wp_api_v3';
            }
        }

        public function get_id(): int {
            return $this->id;
        }

        public function set_name( string $name ): void {
            $this->name = $name;
        }

        public function get_name(): string {
            return $this->name;
        }

        public function set_user_id( int $user_id ): void {
            $this->user_id = $user_id;
        }

        public function get_user_id(): int {
            return $this->user_id;
        }

        public function set_topic( string $topic ): void {
            $this->topic = $topic;
        }

        public function get_topic(): string {
            return $this->topic;
        }

        public function set_delivery_url( string $url ): void {
            $this->delivery_url = $url;
        }

        public function get_delivery_url(): string {
            return $this->delivery_url;
        }

        public function set_secret( string $secret ): void {
            $this->secret = $secret;
        }

        public function get_secret(): string {
            return $this->secret;
        }

        public function set_status( string $status ): void {
            $this->status = $status;
        }

        public function get_status(): string {
            return $this->status;
        }

        public function set_api_version( string $version ): void {
            $this->api_version = $version;
        }

        public function get_api_version(): string {
            return $this->api_version;
        }

        public function save(): int {
            if ( ! $this->id ) {
                $this->id = count( $GLOBALS['gecx_test_webhooks'] ?? [] ) + 1;
            }
            $GLOBALS['gecx_test_webhooks'][ $this->id ] = [
                'id'           => $this->id,
                'name'         => $this->name,
                'user_id'      => $this->user_id,
                'topic'        => $this->topic,
                'delivery_url' => $this->delivery_url,
                'secret'       => $this->secret,
                'status'       => $this->status,
                'api_version'  => $this->api_version,
            ];
            return $this->id;
        }

        public function delete( bool $force = false ): void {
            unset( $GLOBALS['gecx_test_webhooks'][ $this->id ] );
            $this->id = 0;
        }
    }
}

if ( ! class_exists( 'WC_Session_Handler' ) ) {
    class WC_Session_Handler {
        private array $data = [];
        public function init(): void {}
        public function get( string $key, $default = null ) {
            return $this->data[ $key ] ?? $default;
        }
        public function set( string $key, $value ): void {
            $this->data[ $key ] = $value;
        }
        public function save_data(): void {}
        public function set_customer_session_cookie( bool $val ): void {}
    }
}

if ( ! class_exists( 'WooCommerce_Mock' ) ) {
    class WooCommerce_Mock {
        public $session = null;
        public function __construct() {
            $this->session = new WC_Session_Handler();
        }
    }
}

if ( ! function_exists( 'WC' ) ) {
    function WC(): WooCommerce_Mock {
        if ( ! isset( $GLOBALS['gecx_test_wc_session'] ) || null === $GLOBALS['gecx_test_wc_session'] ) {
            $GLOBALS['gecx_test_wc_session'] = new WooCommerce_Mock();
        }
        return $GLOBALS['gecx_test_wc_session'];
    }
}

if ( ! defined( 'WC_ABSPATH' ) ) {
    define( 'WC_ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! function_exists( 'wc_get_webhooks' ) ) {
    function wc_get_webhooks( array $args = [] ): array {
        $webhooks = [];
        foreach ( array_keys( $GLOBALS['gecx_test_webhooks'] ?? [] ) as $id ) {
            $webhooks[] = new \WC_Webhook( (int) $id );
        }
        return $webhooks;
    }
}

if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        private int $id = 0;
        private array $meta = [];
        private string $currency = 'USD';
        private string $total = '0.00';
        private string $total_tax = '0.00';
        private string $shipping_total = '0.00';
        private array $items = [];

        public function __construct( int $id = 0 ) {
            $this->id = $id;
            if ( $id > 0 && isset( $GLOBALS['gecx_test_orders'][ $id ] ) && $GLOBALS['gecx_test_orders'][ $id ] instanceof WC_Order ) {
                $existing             = $GLOBALS['gecx_test_orders'][ $id ];
                $this->meta           = $existing->meta;
                $this->currency       = $existing->currency;
                $this->total          = $existing->total;
                $this->total_tax      = $existing->total_tax;
                $this->shipping_total = $existing->shipping_total;
                $this->items          = $existing->items;
            }
        }

        public function get_id(): int {
            return $this->id;
        }

        public function update_meta_data( string $key, $value ): void {
            $this->meta[ $key ] = $value;
        }

        public function get_meta( string $key, bool $single = true ) {
            return $this->meta[ $key ] ?? '';
        }

        public function set_currency( string $currency ): void {
            $this->currency = $currency;
        }

        public function get_currency(): string {
            return $this->currency;
        }

        public function set_total( string $total ): void {
            $this->total = $total;
        }

        public function get_total(): string {
            return $this->total;
        }

        public function set_total_tax( string $tax ): void {
            $this->total_tax = $tax;
        }

        public function get_total_tax(): string {
            return $this->total_tax;
        }

        public function set_shipping_total( string $shipping ): void {
            $this->shipping_total = $shipping;
        }

        public function get_shipping_total(): string {
            return $this->shipping_total;
        }

        public function set_items( array $items ): void {
            $this->items = $items;
        }

        public function get_items(): array {
            return $this->items;
        }

        public function get_item_total( $item, bool $inc_tax = false, bool $round = true ) {
            $qty = method_exists( $item, 'get_quantity' ) ? max( 1, (int) $item->get_quantity() ) : 1;
            $total = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
            $price = $total / $qty;
            return $round ? round( $price, 4 ) : $price;
        }

        public function save(): int {
            if ( ! $this->id ) {
                $this->id = count( $GLOBALS['gecx_test_orders'] ?? [] ) + 1;
            }
            $GLOBALS['gecx_test_orders'][ $this->id ] = $this;
            return $this->id;
        }
    }
}

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        $id = (int) $order_id;
        return $GLOBALS['gecx_test_orders'][ $id ] ?? false;
    }
}

if ( ! class_exists( 'GECX_Mock_WPDB' ) ) {
    class GECX_Mock_WPDB {
        public string $prefix = 'wp_';

        public function esc_like( string $text ): string {
            return addcslashes( $text, '_%\\' );
        }

        public function prepare( string $query, ...$args ): string {
            $arg_index = 0;
            return preg_replace_callback(
                '/%([dsifF])/',
                static function ( array $matches ) use ( &$arg_index, $args ) {
                    $type = $matches[1];
                    $arg  = $args[ $arg_index++ ] ?? null;
                    if ( 'd' === $type ) {
                        return (string) (int) $arg;
                    }
                    if ( 'i' === $type ) {
                        return '`' . str_replace( '`', '``', (string) $arg ) . '`';
                    }
                    return "'" . addslashes( (string) $arg ) . "'";
                },
                $query
            );
        }

        public function get_var( string $query ) {
            if ( 0 === strpos( $query, 'SHOW TABLES LIKE' ) ) {
                return $this->prefix . 'wc_webhooks';
            }
            if ( preg_match( '/WHERE webhook_id = (\d+)/', $query, $m ) ) {
                $id = (int) $m[1];
                return $GLOBALS['gecx_test_webhooks'][ $id ]['secret'] ?? null;
            }
            if ( preg_match( "/WHERE name = '([^']+)' AND topic = '([^']+)'/", $query, $m ) ) {
                $name  = $m[1];
                $topic = $m[2];
                foreach ( $GLOBALS['gecx_test_webhooks'] ?? [] as $wh ) {
                    if ( ( $wh['name'] ?? '' ) === $name && ( $wh['topic'] ?? '' ) === $topic ) {
                        return $wh['secret'] ?? null;
                    }
                }
            }
            return null;
        }

        public function delete( string $table, array $where, array $where_format = [] ): int {
            $deleted = 0;
            if ( $table === $this->prefix . 'wc_webhooks' ) {
                if ( isset( $where['webhook_id'] ) ) {
                    $id = (int) $where['webhook_id'];
                    if ( isset( $GLOBALS['gecx_test_webhooks'][ $id ] ) ) {
                        unset( $GLOBALS['gecx_test_webhooks'][ $id ] );
                        $deleted++;
                    }
                } elseif ( isset( $where['name'], $where['topic'] ) ) {
                    foreach ( array_keys( $GLOBALS['gecx_test_webhooks'] ?? [] ) as $id ) {
                        $wh = $GLOBALS['gecx_test_webhooks'][ $id ];
                        if ( ( $wh['name'] ?? '' ) === $where['name'] && ( $wh['topic'] ?? '' ) === $where['topic'] ) {
                            unset( $GLOBALS['gecx_test_webhooks'][ $id ] );
                            $deleted++;
                        }
                    }
                }
            }
            return $deleted;
        }
    }
    $GLOBALS['wpdb'] = new GECX_Mock_WPDB();
}

if ( ! class_exists( 'WP_User' ) ) {
    class WP_User {
        public int $ID;
        public string $user_email;
        public array $roles = [];

        /**
         * Raw capability grant, as WP_User exposes it. Declared rather than
         * assigned dynamically so tests can populate it on PHP 8.2+.
         */
        public array $allcaps = [];

        public function __construct( int $id = 0, string $email = '', array $roles = [], array $allcaps = [] ) {
            $this->ID         = $id;
            $this->user_email = $email;
            $this->roles      = $roles;
            $this->allcaps    = $allcaps;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $headers = [];
        private array $params  = [];
        private string $route  = '/gecx/v1/public-key';
        public function set_header( string $name, string $value ): void {
            $this->headers[ strtolower( $name ) ] = $value;
        }
        public function get_header( string $name ): ?string {
            return $this->headers[ strtolower( $name ) ] ?? null;
        }
        public function set_param( string $key, $value ): void {
            $this->params[ $key ] = $value;
        }
        public function get_param( string $key ) {
            return $this->params[ $key ] ?? null;
        }
        public function set_route( string $route ): void {
            $this->route = $route;
        }
        public function get_route(): string {
            return $this->route;
        }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        private $data;
        private int $status;
        private array $headers = [];
        public function __construct( $data = null, int $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function get_data() {
            return $this->data;
        }
        public function set_data( $data ): void {
            $this->data = $data;
        }
        public function get_status(): int {
            return $this->status;
        }
        public function header( string $name, string $value, bool $replace = true ): void {
            $this->headers[ $name ] = $value;
        }
        public function get_headers(): array {
            return $this->headers;
        }
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        private array $data;
        public function __construct( string $code = '', string $message = '', $data = [] ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = (array) $data;
        }
        public function get_error_code(): string {
            return $this->code;
        }
        public function get_error_message(): string {
            return $this->message;
        }
        public function get_error_data(): array {
            return $this->data;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool {
        return ( $thing instanceof WP_Error );
    }
}

// Mock WordPress functions
if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user, string $capability ): bool {
        if ( is_numeric( $user ) ) {
            $user = get_userdata( (int) $user );
        }
        if ( $user instanceof WP_User ) {
            if ( in_array( 'administrator', $user->roles, true ) && ( $capability === 'manage_options' || $capability === 'manage_woocommerce' ) ) {
                return true;
            }
            if ( in_array( 'shop_manager', $user->roles, true ) && $capability === 'manage_woocommerce' ) {
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( string $capability ): bool {
        if ( ! empty( $GLOBALS['gecx_test_current_user'] ) ) {
            return user_can( $GLOBALS['gecx_test_current_user'], $capability );
        }
        return false;
    }
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in(): bool {
        return ! empty( $GLOBALS['gecx_test_current_user'] ) && $GLOBALS['gecx_test_current_user']->ID > 0;
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id(): int {
        return $GLOBALS['gecx_test_current_user']->ID ?? 0;
    }
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
    function wp_get_current_user(): ?WP_User {
        return $GLOBALS['gecx_test_current_user'] ?? new WP_User();
    }
}

if ( ! function_exists( 'get_userdata' ) ) {
    function get_userdata( int $user_id ): ?WP_User {
        if ( isset( $GLOBALS['gecx_test_users'][ $user_id ] ) ) {
            return $GLOBALS['gecx_test_users'][ $user_id ];
        }
        if ( ! empty( $GLOBALS['gecx_test_current_user'] ) && $GLOBALS['gecx_test_current_user']->ID === $user_id ) {
            return $GLOBALS['gecx_test_current_user'];
        }
        return null;
    }
}

if ( ! function_exists( 'wp_set_current_user' ) ) {
    function wp_set_current_user( int $id, string $name = '' ): WP_User {
        if ( $id <= 0 ) {
            $GLOBALS['gecx_test_current_user'] = null;
            return new WP_User( 0, '' );
        }
        $user = get_userdata( $id );
        if ( ! ( $user instanceof WP_User ) ) {
            $user = new WP_User( $id, '' !== $name ? $name : 'user_' . $id . '@example.com' );
        }
        $GLOBALS['gecx_test_current_user'] = $user;
        return $user;
    }
}

if ( ! function_exists( 'wp_validate_auth_cookie' ) ) {
    function wp_validate_auth_cookie( string $cookie = '', string $scheme = '' ) {
        if ( ! empty( $GLOBALS['gecx_test_cookie_user_id'] ) && (int) $GLOBALS['gecx_test_cookie_user_id'] > 0 ) {
            return (int) $GLOBALS['gecx_test_cookie_user_id'];
        }
        return false;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    /**
     * Runs registered callbacks, in priority order, over $value.
     *
     * Deliberately not a canned-value lookup keyed by tag: that would make a
     * test registering one filter change the meaning of every apply_filters()
     * call sharing that tag, and would ignore both $value and $args.
     */
    function apply_filters( string $tag, $value, ...$args ) {
        $hooks = $GLOBALS['gecx_test_filter_callbacks'][ $tag ] ?? [];
        if ( empty( $hooks ) ) {
            return $value;
        }

        usort(
            $hooks,
            static function ( array $a, array $b ): int {
                if ( $a['priority'] === $b['priority'] ) {
                    return ( $a['insertion_index'] ?? 0 ) <=> ( $b['insertion_index'] ?? 0 );
                }
                return $a['priority'] <=> $b['priority'];
            }
        );

        foreach ( $hooks as $hook ) {
            $params = array_slice( array_merge( [ $value ], $args ), 0, $hook['accepted_args'] );
            $value  = call_user_func_array( $hook['callback'], $params );
        }

        return $value;
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( string $url ): string {
        return $url;
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( string $url ): string {
        return $url;
    }
}

if ( ! function_exists( 'untrailingslashit' ) ) {
    function untrailingslashit( string $value ): string {
        return rtrim( $value, '/\\' );
    }
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
    function wp_normalize_path( $path ): string {
        $path = str_replace( '\\', '/', (string) $path );
        return (string) preg_replace( '|(?<=.)/+|', '/', $path );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url(): string {
        return (string) ( $GLOBALS['gecx_test_home_url'] ?? 'https://example.com' );
    }
}

if ( ! function_exists( 'site_url' ) ) {
    function site_url(): string {
        return (string) ( $GLOBALS['gecx_test_site_url'] ?? home_url() );
    }
}

if ( ! function_exists( 'rest_url' ) ) {
    /**
     * Mirrors WordPress: the REST root follows the permalink structure, so a
     * store on plain permalinks answers at ?rest_route= rather than /wp-json/.
     *
     * @param string $path Route appended to the REST root.
     * @return string
     */
    function rest_url( string $path = '' ): string {
        $base = rtrim( home_url(), '/' );

        if ( ! empty( $GLOBALS['gecx_test_rest_plain_permalinks'] ) ) {
            return $base . '/?rest_route=/' . ltrim( $path, '/' );
        }

        return $base . '/' . rest_get_url_prefix() . '/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin(): bool {
        return (bool) ( $GLOBALS['gecx_test_is_admin'] ?? false );
    }
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
    function wp_doing_ajax(): bool {
        return (bool) ( $GLOBALS['gecx_test_doing_ajax'] ?? false );
    }
}

if ( ! function_exists( 'wp_is_json_request' ) ) {
    function wp_is_json_request(): bool {
        return (bool) ( $GLOBALS['gecx_test_is_json_request'] ?? false );
    }
}

if ( ! function_exists( 'is_feed' ) ) {
    function is_feed(): bool {
        return (bool) ( $GLOBALS['gecx_test_is_feed'] ?? false );
    }
}

if ( ! function_exists( 'is_multisite' ) ) {
    function is_multisite(): bool {
        return (bool) ( $GLOBALS['gecx_test_is_multisite'] ?? false );
    }
}

if ( ! function_exists( 'get_sites' ) ) {
    function get_sites( array $args = [] ): array {
        return $GLOBALS['gecx_test_sites'] ?? [];
    }
}

// The options store is flat rather than per-site, so these record the calls
// and let the site body run against the one store. That is enough to pin the
// loop shape -- every site visited, every switch restored -- which is what the
// multisite bug was.
if ( ! function_exists( 'switch_to_blog' ) ) {
    function switch_to_blog( int $site_id ): bool {
        $GLOBALS['gecx_test_switched_blogs'][] = $site_id;
        $GLOBALS['gecx_test_blog_stack'][]     = $site_id;
        return true;
    }
}

if ( ! function_exists( 'restore_current_blog' ) ) {
    function restore_current_blog(): bool {
        if ( empty( $GLOBALS['gecx_test_blog_stack'] ) ) {
            return false;
        }
        array_pop( $GLOBALS['gecx_test_blog_stack'] );
        return true;
    }
}

if ( ! function_exists( 'is_customize_preview' ) ) {
    function is_customize_preview(): bool {
        return false;
    }
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ): string {
        $uid = get_current_user_id();
        if ( ! $uid && function_exists( 'apply_filters' ) ) {
            $uid = (int) apply_filters( 'nonce_user_logged_out', $uid, $action );
        }
        return $uid > 0 ? 'test_nonce_' . $action . '_u' . $uid : 'test_nonce_' . $action;
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ): bool {
        $uid      = get_current_user_id();
        $expected = $uid > 0 ? 'test_nonce_' . $action . '_u' . $uid : 'test_nonce_' . $action;
        return $nonce === 'valid_nonce_' . $action || $nonce === $expected;
    }
}

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( string $scheme = 'auth' ): string {
        return (string) ( $GLOBALS['gecx_test_wp_salt'] ?? 'secret_salt' );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
    function wp_http_validate_url( string $url ) {
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return false;
        }
        $parsed = parse_url( $url );
        if ( ! isset( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], [ 'http', 'https' ], true ) ) {
            return false;
        }
        if ( empty( $parsed['host'] ) ) {
            return false;
        }
        return $url;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string {
        // Returns $text unless a test has supplied a translation for it, which
        // is how a string that goes through __() is told from one that
        // was hard-coded.
        return (string) ( $GLOBALS['gecx_test_translations'][ $text ] ?? $text );
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
        static $filter_insertion_index = 0;
        $GLOBALS['gecx_test_filter_callbacks'][ $hook_name ][] = [
            'callback'        => $callback,
            'priority'        => $priority,
            'accepted_args'   => $accepted_args,
            'insertion_index' => ++$filter_insertion_index,
        ];
        return true;
    }
}

if ( ! function_exists( 'remove_filter' ) ) {
    function remove_filter( string $hook_name, $callback, int $priority = 10 ): bool {
        if ( empty( $GLOBALS['gecx_test_filter_callbacks'][ $hook_name ] ) ) {
            return false;
        }
        foreach ( $GLOBALS['gecx_test_filter_callbacks'][ $hook_name ] as $idx => $entry ) {
            if ( ( $entry['priority'] ?? 10 ) === $priority && ( $entry['callback'] ?? null ) === $callback ) {
                unset( $GLOBALS['gecx_test_filter_callbacks'][ $hook_name ][ $idx ] );
                return true;
            }
        }
        return false;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    /**
     * Records fired actions as [ tag, args ] so tests can assert on them.
     */
    function do_action( string $hook_name, ...$args ): void {
        $GLOBALS['gecx_test_actions'][] = [ $hook_name, $args ];
    }
}

if ( ! function_exists( 'rest_get_url_prefix' ) ) {
    function rest_get_url_prefix(): string {
        return $GLOBALS['gecx_test_rest_url_prefix'] ?? 'wp-json';
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
        return true;
    }
}

if ( ! function_exists( 'add_shortcode' ) ) {
    function add_shortcode( string $tag, $callback ): void {}
}

if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( string $file ): string {
        return basename( $file );
    }
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
        return substr( str_repeat( 'abcdef0123456789', 8 ), 0, $length );
    }
}

// Escaping and form helpers used by the admin page. The escapers match the
// existing esc_attr()/esc_html() stubs; the form helpers emit the same
// attributes WordPress does.
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( string $text, string $domain = 'default' ): string {
        return htmlspecialchars( __( $text, $domain ), ENT_QUOTES );
    }
}

if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( string $text, string $domain = 'default' ): void {
        echo esc_html__( $text, $domain );
    }
}

if ( ! function_exists( 'esc_attr_e' ) ) {
    function esc_attr_e( string $text, string $domain = 'default' ): void {
        echo htmlspecialchars( __( $text, $domain ), ENT_QUOTES );
    }
}

if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( string $content ): string {
        return $content;
    }
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
        $field = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce( $action ) . '" />';
        if ( $display ) {
            echo $field;
        }
        return $field;
    }
}

if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, bool $display = true ): string {
        $result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
        if ( $display ) {
            echo $result;
        }
        return $result;
    }
}

if ( ! function_exists( 'selected' ) ) {
    function selected( $selected, $current = true, bool $display = true ): string {
        $result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
        if ( $display ) {
            echo $result;
        }
        return $result;
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( string $path = '' ): string {
        return 'https://example.com/wp-admin/' . $path;
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    /**
     * Array-and-URL form only, which is the only form the plugin uses.
     *
     * WordPress core's add_query_arg() delegates to build_query() ->
     * _http_build_query( $data, null, '&', '', false ), which does NOT
     * URL-encode keys or values ($urlencode = false). Callers must rawurlencode()
     * embedded URLs (such as return_url) themselves.
     */
    function add_query_arg( array $args, string $url ): string {
        $base     = $url;
        $existing = [];
        if ( strpos( $url, '?' ) !== false ) {
            [ $base, $query ] = explode( '?', $url, 2 );
            foreach ( explode( '&', $query ) as $pair ) {
                if ( '' === $pair ) {
                    continue;
                }
                if ( strpos( $pair, '=' ) !== false ) {
                    [ $k, $v ] = explode( '=', $pair, 2 );
                    $existing[ $k ] = $v;
                } else {
                    $existing[ $pair ] = '';
                }
            }
        }

        $merged = array_merge( $existing, $args );
        $pairs  = [];
        foreach ( $merged as $k => $v ) {
            if ( false === $v || null === $v ) {
                continue;
            }
            $pairs[] = $k . '=' . $v;
        }

        return empty( $pairs ) ? $base : $base . '?' . implode( '&', $pairs );
    }
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( string $location, int $status = 302 ): bool {
        $GLOBALS['gecx_test_last_redirect'] = $location;
        return true;
    }
}

if ( ! function_exists( 'register_setting' ) ) {
    function register_setting( string $option_group, string $option_name, array $args = [] ): void {}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action = -1, $query_arg = false, $die = true ): bool {
        return true;
    }
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, int $status_code = null ): void {
        $GLOBALS['gecx_test_last_json_response'] = [ 'success' => true, 'data' => $data ];
    }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, int $status_code = null ): void {
        $GLOBALS['gecx_test_last_json_response'] = [ 'success' => false, 'data' => $data, 'status' => $status_code ];
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $transient, $value, int $expiration = 0 ): bool {
        $GLOBALS['gecx_test_transients'][ $transient ] = $value;
        return true;
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $transient ) {
        return $GLOBALS['gecx_test_transients'][ $transient ] ?? false;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( string $transient ): bool {
        unset( $GLOBALS['gecx_test_transients'][ $transient ] );
        return true;
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool {
        return $thing instanceof WP_Error;
    }
}

/**
 * Mock HTTP transport.
 *
 * Responses are taken from $GLOBALS['gecx_test_http_responses'] in order. Each
 * entry is either a WP_Error or an array shaped like a WordPress HTTP response.
 * When the queue is empty a WP_Error is returned, so a test that does not
 * expect a request cannot silently pass on a real one.
 */
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( string $url, array $args = [] ) {
        $GLOBALS['gecx_test_last_remote_post'] = [
            'url'  => $url,
            'args' => $args,
        ];
        $GLOBALS['gecx_test_http_requests'][] = [
            'url'  => $url,
            'args' => $args,
        ];
        if ( empty( $GLOBALS['gecx_test_http_responses'] ) ) {
            return new WP_Error( 'gecx_test_no_response', 'No mocked HTTP response queued.' );
        }
        return array_shift( $GLOBALS['gecx_test_http_responses'] );
    }
}

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( string $url, array $args = [] ) {
        return wp_remote_post( $url, $args );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return is_array( $response ) ? ( $response['response']['code'] ?? '' ) : '';
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ): string {
        return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
    }
}

if ( ! function_exists( 'add_settings_error' ) ) {
    function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
        $GLOBALS['gecx_test_settings_errors'][] = [
            'setting' => $setting,
            'code'    => $code,
            'message' => $message,
            'type'    => $type,
        ];
    }
}

if ( ! function_exists( 'settings_errors' ) ) {
    function settings_errors( string $setting = '' ): void {}
}

/** Builds a mock HTTP response array. */
function gecx_test_http_response( int $code, string $body = '' ): array {
    return [
        'response' => [ 'code' => $code ],
        'body'     => $body,
    ];
}

if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( string $namespace, string $route, array $args = [], bool $override = false ): bool {
        $GLOBALS['gecx_test_rest_routes'][ $namespace . $route ] = $args;
        return true;
    }
}

if ( ! function_exists( 'is_product' ) ) {
    function is_product(): bool {
        return ! empty( $GLOBALS['gecx_test_is_product'] );
    }
}

if ( ! function_exists( 'get_the_ID' ) ) {
    function get_the_ID(): int {
        return $GLOBALS['gecx_test_the_id'] ?? 101;
    }
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
    function get_queried_object_id(): int {
        return $GLOBALS['gecx_test_queried_object_id'] ?? 101;
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
        return $GLOBALS['gecx_test_post_meta'][ $post_id ][ $key ] ?? ( $single ? '' : [] );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ): int {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $option, $default = false ) {
        return $GLOBALS['gecx_test_options'][ $option ] ?? $default;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( string $option, $value = '', string $deprecated = '', $autoload = 'yes' ): bool {
        if ( isset( $GLOBALS['gecx_test_options'][ $option ] ) ) {
            return false;
        }
        $GLOBALS['gecx_test_options'][ $option ]         = $value;
        $GLOBALS['gecx_test_option_autoload'][ $option ] = $autoload;
        return true;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $option, $value, $autoload = null ): bool {
        $GLOBALS['gecx_test_options'][ $option ] = $value;
        // WordPress leaves the stored autoload alone when the caller passes
        // null, and rewrites it otherwise, including for an option that
        // already exists.
        if ( null !== $autoload ) {
            $GLOBALS['gecx_test_option_autoload'][ $option ] = $autoload;
        }
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( string $option ): bool {
        unset( $GLOBALS['gecx_test_options'][ $option ] );
        unset( $GLOBALS['gecx_test_option_autoload'][ $option ] );
        return true;
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( int $post_id, string $meta_key, $meta_value, $prev_value = '' ): bool {
        $GLOBALS['gecx_test_post_meta'][ $post_id ][ $meta_key ] = $meta_value;
        return true;
    }
}

if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( int $post_id, string $meta_key, $meta_value = '' ): bool {
        unset( $GLOBALS['gecx_test_post_meta'][ $post_id ][ $meta_key ] );
        return true;
    }
}

if ( ! function_exists( 'delete_post_meta_by_key' ) ) {
    function delete_post_meta_by_key( string $meta_key ): bool {
        foreach ( $GLOBALS['gecx_test_post_meta'] as $post_id => $metas ) {
            if ( is_array( $metas ) && isset( $metas[ $meta_key ] ) ) {
                unset( $GLOBALS['gecx_test_post_meta'][ $post_id ][ $meta_key ] );
            }
        }
        return true;
    }
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( ...$args ): void {
        $GLOBALS['gecx_test_enqueued_scripts'][] = (string) ( $args[0] ?? '' );
    }
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( ...$args ): void {
        $GLOBALS['gecx_test_enqueued_styles'][] = (string) ( $args[0] ?? '' );
    }
}

if ( ! function_exists( 'wp_add_inline_style' ) ) {
    function wp_add_inline_style( ...$args ): void {
        $GLOBALS['gecx_test_inline_styles'][ $args[0] ] = ( $GLOBALS['gecx_test_inline_styles'][ $args[0] ] ?? '' ) . $args[1];
    }
}

if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( ...$args ): void {
        $GLOBALS['gecx_test_localized_scripts'][ $args[0] ][ $args[1] ] = $args[2];
    }
}

if ( ! function_exists( 'plugins_url' ) ) {
    function plugins_url( string $path = '', string $plugin = '' ): string {
        return 'https://example.com/wp-content/plugins/gemini-enterprise-for-cx/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( string $file ): string {
        return trailingslashit( dirname( $file ) );
    }
}

if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( string $string ): string {
        return rtrim( $string, '/\\' ) . '/';
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( string $str ): string {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}

// Load core classes
require_once dirname( __DIR__ ) . '/includes/class-gecx-auth.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-rest-api.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-gecx-storefront.php';

/**
 * Mints tokens shaped like WooCommerce Store API cart tokens.
 *
 * Shared so that no test file has to reimplement base64url-and-HMAC by hand;
 * a second implementation that drifts from this one would test the drift.
 *
 * @see Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token()
 */
trait GECX_CartTokenMinting {
    /**
     * @param string|null $iss Issuer claim, or null to omit it entirely, as
     *                         WooCommerce below 7.1 does.
     */
    protected function generate_jwt(
        $user_id,
        int $exp_offset = 3600,
        string $salt = 'secret_salt',
        bool $valid_sig = true,
        ?string $iss = 'store-api'
    ): string {
        $claims = [
            'user_id' => $user_id,
            'exp'     => time() + $exp_offset,
        ];
        if ( null !== $iss ) {
            $claims['iss'] = $iss;
        }

        $header  = json_encode( [ 'typ' => 'JWT', 'alg' => 'HS256' ] );
        $payload = json_encode( $claims );

        $to_base_64_url = static function ( string $string ) {
            return str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $string ) );
        };

        $header_encoded  = $to_base_64_url( $header );
        $payload_encoded = $to_base_64_url( $payload );

        $secret            = '@' . $salt;
        $signature         = hash_hmac( 'sha256', $header_encoded . '.' . $payload_encoded, $secret, true );
        $signature_encoded = $to_base_64_url( $signature );

        if ( ! $valid_sig ) {
            $signature_encoded .= 'invalid';
        }

        return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
    }

    protected function mint_token_with_payload(
        array $claims,
        string $salt = 'secret_salt',
        bool $valid_sig = true
    ): string {
        $header  = json_encode( [ 'typ' => 'JWT', 'alg' => 'HS256' ] );
        $payload = json_encode( $claims );

        $to_base_64_url = static function ( string $string ) {
            return str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $string ) );
        };

        $header_encoded  = $to_base_64_url( $header );
        $payload_encoded = $to_base_64_url( $payload );

        $secret            = '@' . $salt;
        $signature         = hash_hmac( 'sha256', $header_encoded . '.' . $payload_encoded, $secret, true );
        $signature_encoded = $to_base_64_url( $signature );

        if ( ! $valid_sig ) {
            $signature_encoded .= 'invalid';
        }

        return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
    }
}

// Base Test Case Implementation
if ( ! class_exists( 'PHPUnit\Framework\TestCase' ) ) {
    abstract class GECX_BaseTestCase {
        protected function setUp(): void {
            gecx_reset_test_globals();
        }

        protected function tearDown(): void {}

        public function assertEquals( $expected, $actual, string $message = '' ): void {
            if ( $expected != $actual ) {
                throw new \AssertionError( ( $message ?: 'Failed asserting that values are equal.' ) . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
            }
        }

        public function assertSame( $expected, $actual, string $message = '' ): void {
            if ( $expected !== $actual ) {
                throw new \AssertionError( ( $message ?: 'Failed asserting that values are identical.' ) . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
            }
        }

        public function assertInstanceOf( string $expected, $actual, string $message = '' ): void {
            if ( ! ( $actual instanceof $expected ) ) {
                // Not get_debug_type(): that is PHP 8.0+, and the plugin
                // declares Requires PHP 7.4. This runner only executes where
                // PHPUnit is absent, so it would fatal exactly when an
                // assertion failed.
                $actual_type = is_object( $actual ) ? get_class( $actual ) : gettype( $actual );
                throw new \AssertionError( ( $message ?: 'Failed asserting that value is an instance of ' . $expected . '.' ) . "\nActual: " . $actual_type );
            }
        }

        public function assertTrue( $condition, string $message = '' ): void {
            if ( true !== $condition ) {
                throw new \AssertionError( $message ?: 'Failed asserting that condition is true.' );
            }
        }

        public function assertFalse( $condition, string $message = '' ): void {
            if ( false !== $condition ) {
                throw new \AssertionError( $message ?: 'Failed asserting that condition is false.' );
            }
        }

        public function assertNull( $value, string $message = '' ): void {
            if ( null !== $value ) {
                throw new \AssertionError( $message ?: 'Failed asserting that value is null.' );
            }
        }

        public function assertNotNull( $value, string $message = '' ): void {
            if ( null === $value ) {
                throw new \AssertionError( $message ?: 'Failed asserting that value is not null.' );
            }
        }

        public function assertNotEmpty( $actual, string $message = '' ): void {
            if ( empty( $actual ) ) {
                throw new \AssertionError( $message ?: 'Failed asserting that a variable is not empty.' );
            }
        }

        public function assertCount( int $expectedCount, $countable, string $message = '' ): void {
            $count = is_array( $countable ) || $countable instanceof \Countable ? count( $countable ) : 0;
            if ( $expectedCount !== $count ) {
                throw new \AssertionError( ( $message ?: 'Failed asserting count.' ) . " Expected: $expectedCount, Actual: $count" );
            }
        }

        public function assertStringContainsString( string $needle, string $haystack, string $message = '' ): void {
            if ( false === strpos( $haystack, $needle ) ) {
                throw new \AssertionError( ( $message ?: 'Failed asserting that string contains needle.' ) . "\nNeedle: $needle\nHaystack: $haystack" );
            }
        }

        public function assertStringNotContainsString( string $needle, string $haystack, string $message = '' ): void {
            if ( false !== strpos( $haystack, $needle ) ) {
                throw new \AssertionError( ( $message ?: 'Failed asserting that string does not contain needle.' ) . "\nNeedle: $needle\nHaystack: $haystack" );
            }
        }

        public function assertFileExists( string $path, string $message = '' ): void {
            if ( ! file_exists( $path ) ) {
                throw new \AssertionError( ( $message ?: 'Failed asserting that file exists.' ) . "\nPath: $path" );
            }
        }

        public function assertArrayHasKey( $key, array $array, string $message = '' ): void {
            if ( ! array_key_exists( $key, $array ) ) {
                throw new \AssertionError( ( $message ?: 'Failed asserting that array has key.' ) . "\nKey: " . var_export( $key, true ) );
            }
        }

        public function assertArrayNotHasKey( $key, array $array, string $message = '' ): void {
            if ( array_key_exists( $key, $array ) ) {
                throw new \AssertionError( ( $message ?: 'Failed asserting that array does not have key.' ) . "\nKey: " . var_export( $key, true ) );
            }
        }
    }
    class_alias( 'GECX_BaseTestCase', 'PHPUnit\Framework\TestCase' );

    abstract class GECX_TestCase extends GECX_BaseTestCase {
        protected function base64_url_decode( string $string ): string {
            $remainder = strlen( $string ) % 4;
            if ( $remainder ) {
                $string .= str_repeat( '=', 4 - $remainder );
            }
            return (string) base64_decode( strtr( $string, '-_', '+/' ) );
        }

        protected function decode_jwt_payload( string $jwt ): ?array {
            $parts = explode( '.', $jwt );
            if ( count( $parts ) !== 3 ) {
                return null;
            }
            return json_decode( $this->base64_url_decode( $parts[1] ), true );
        }

        protected function verify_jwt_signature( string $jwt, string $secret ): bool {
            $parts = explode( '.', $jwt );
            if ( count( $parts ) !== 3 ) {
                return false;
            }
            $expected_sig   = hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], $secret, true );
            $to_base_64_url = function( string $string ) {
                return str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $string ) );
            };
            return hash_equals( $to_base_64_url( $expected_sig ), $parts[2] );
        }

        protected function verify_rs256_jwt( string $jwt, string $public_key_pem ): bool {
            $parts = explode( '.', $jwt );
            if ( count( $parts ) !== 3 ) {
                return false;
            }
            $signing_input = $parts[0] . '.' . $parts[1];
            $signature     = $this->base64_url_decode( $parts[2] );
            $pub_key_res   = openssl_pkey_get_public( $public_key_pem );
            if ( false === $pub_key_res ) {
                return false;
            }
            $verify = openssl_verify( $signing_input, $signature, $pub_key_res, OPENSSL_ALGO_SHA256 );
            return 1 === $verify;
        }
    }
} else {
    abstract class GECX_TestCase extends \PHPUnit\Framework\TestCase {
        protected function setUp(): void {
            parent::setUp();
            gecx_reset_test_globals();
        }

        protected function base64_url_decode( string $string ): string {
            $remainder = strlen( $string ) % 4;
            if ( $remainder ) {
                $string .= str_repeat( '=', 4 - $remainder );
            }
            return (string) base64_decode( strtr( $string, '-_', '+/' ) );
        }

        protected function decode_jwt_payload( string $jwt ): ?array {
            $parts = explode( '.', $jwt );
            if ( count( $parts ) !== 3 ) {
                return null;
            }
            return json_decode( $this->base64_url_decode( $parts[1] ), true );
        }

        protected function verify_jwt_signature( string $jwt, string $secret ): bool {
            $parts = explode( '.', $jwt );
            if ( count( $parts ) !== 3 ) {
                return false;
            }
            $expected_sig   = hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], $secret, true );
            $to_base_64_url = function( string $string ) {
                return str_replace( [ '+', '/', '=' ], [ '-', '_', '' ], base64_encode( $string ) );
            };
            return hash_equals( $to_base_64_url( $expected_sig ), $parts[2] );
        }

        protected function verify_rs256_jwt( string $jwt, string $public_key_pem ): bool {
            $parts = explode( '.', $jwt );
            if ( count( $parts ) !== 3 ) {
                return false;
            }
            $signing_input = $parts[0] . '.' . $parts[1];
            $signature     = $this->base64_url_decode( $parts[2] );
            $pub_key_res   = openssl_pkey_get_public( $public_key_pem );
            if ( false === $pub_key_res ) {
                return false;
            }
            $verify = openssl_verify( $signing_input, $signature, $pub_key_res, OPENSSL_ALGO_SHA256 );
            return 1 === $verify;
        }
    }
}

// Standalone CLI test runner
function gecx_run_test_class( string $className ): array {
    $test    = new $className();
    $methods = get_class_methods( $test );
    $passed  = 0;
    $failed  = 0;

    foreach ( $methods as $method ) {
        if ( strpos( $method, 'test_' ) === 0 ) {
            try {
                $ref = new ReflectionMethod( $test, 'setUp' );
                $ref->setAccessible( true );
                $ref->invoke( $test );

                $test->$method();

                $ref = new ReflectionMethod( $test, 'tearDown' );
                $ref->setAccessible( true );
                $ref->invoke( $test );

                echo "PASS: $className::$method\n";
                $passed++;
            } catch ( \Throwable $e ) {
                echo "FAIL: $className::$method - " . $e->getMessage() . "\n";
                $failed++;
            }
        }
    }
    echo "\n$className Summary: Total: " . ( $passed + $failed ) . ", Passed: $passed, Failed: $failed\n";
    if ( $failed > 0 ) {
        exit( 1 );
    }
    return [ 'passed' => $passed, 'failed' => $failed ];
}
