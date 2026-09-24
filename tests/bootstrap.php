<?php
// phpcs:ignoreFile -- Test bootstrap and mocks.
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
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
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'GECX_PHPUNIT_RUNNING' ) ) {
    define( 'GECX_PHPUNIT_RUNNING', true );
}
if ( ! defined( 'GECX_VERSION' ) ) {
    define( 'GECX_VERSION', '1.0.0' );
}
if ( ! defined( 'COOKIEHASH' ) ) {
    define( 'COOKIEHASH', 'testcookiehash' );
}
if ( ! defined( 'COOKIEPATH' ) ) {
    define( 'COOKIEPATH', '/' );
}
if ( ! defined( 'COOKIE_DOMAIN' ) ) {
    define( 'COOKIE_DOMAIN', '' );
}

if ( ! function_exists( 'wc_setcookie' ) ) {
    function wc_setcookie( $name, $value, $expire = 0, $secure = false, $httponly = false ) {
        $GLOBALS['gecx_test_cookies'][ $name ] = [
            'value'    => $value,
            'expire'   => $expire,
            'secure'   => $secure,
            'httponly' => $httponly,
        ];
        $_COOKIE[ $name ] = $value;
    }
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
    $GLOBALS['gecx_test_options']              = [
        'permalink_structure' => '/%postname%/',
    ];
    $GLOBALS['gecx_test_registered_settings']  = [];
    $GLOBALS['gecx_test_option_autoload']      = [];
    $GLOBALS['gecx_test_current_user']         = null;
    $GLOBALS['gecx_test_cookie_user_id']       = 0;
    $GLOBALS['gecx_test_users']                = [];
    $GLOBALS['gecx_test_last_redirect']        = null;
    $GLOBALS['gecx_test_last_json_response']   = null;
    $GLOBALS['gecx_test_post_meta']            = [];
    $GLOBALS['gecx_test_is_product']           = false;
    $GLOBALS['gecx_test_is_cart']              = false;
    $GLOBALS['gecx_test_is_checkout']          = false;
    $GLOBALS['gecx_test_is_rtl']               = false;
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
    $GLOBALS['gecx_test_deleted_cache_keys']   = [];
    $GLOBALS['gecx_test_wc_sessions_table']    = [];
    $GLOBALS['gecx_test_scheduled_events']     = [];
    $GLOBALS['gecx_test_action_callbacks']     = [];
    $GLOBALS['gecx_test_defer_cron']           = false;
    $GLOBALS['gecx_test_blog_memberships']     = [];
    $GLOBALS['gecx_test_super_admins']         = [];
    $GLOBALS['gecx_test_products']             = [];
    $GLOBALS['gecx_test_script_translations']  = [];

    // Request context. Every one of these is false for a storefront page
    // render, which is what the majority of tests assume.
    $GLOBALS['gecx_test_is_admin']         = false;
    $GLOBALS['gecx_test_doing_ajax']       = false;
    $GLOBALS['gecx_test_is_json_request']  = false;
    $GLOBALS['gecx_test_is_feed']          = false;

    // Network context. Single site unless a test says otherwise.
    $GLOBALS['gecx_test_is_multisite']     = false;
    $GLOBALS['gecx_test_sites']            = [];
    $GLOBALS['gecx_test_sites_by_path']    = [];
    $GLOBALS['gecx_test_switched_blogs']   = [];
    $GLOBALS['gecx_test_blog_stack']       = [];

    // Strings a test wants __() to translate, keyed by the untranslated text.
    $GLOBALS['gecx_test_translations']     = [];

    $GLOBALS['gecx_test_enqueued_styles']  = [];
    $GLOBALS['gecx_test_enqueued_scripts'] = [];
    $GLOBALS['gecx_test_current_screen']   = null;
    unset( $GLOBALS['hook_suffix'] );

    // Route resolution state. Tests that set this must not leak it into the
    // next test, which would silently change which branch of
    // GECX_Auth::is_store_api_request() runs.
    unset( $GLOBALS['wp'] );

    $GLOBALS['gecx_test_home_url'] = 'https://example.com';

    $_GET     = [];
    $_POST    = [];
    $_REQUEST = [];
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
    if ( class_exists( 'GECX_Rest_API' ) && method_exists( 'GECX_Rest_API', 'reset_wc_auth_state' ) ) {
        GECX_Rest_API::reset_wc_auth_state();
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

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'maybe_serialize' ) ) {
    function maybe_serialize( $data ) {
        if ( is_array( $data ) || is_object( $data ) ) {
            return serialize( $data );
        }
        return $data;
    }
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
    function maybe_unserialize( $data ) {
        if ( is_string( $data ) ) {
            $trimmed = trim( $data );
            if ( 'N;' === $trimmed || preg_match( '/^([adObis]):/', $trimmed ) ) {
                $unserialized = @unserialize( $trimmed );
                if ( false !== $unserialized || 'b:0;' === $trimmed ) {
                    return $unserialized;
                }
            }
        }
        return $data;
    }
}


if ( ! class_exists( 'WC_Session_Handler' ) ) {
    class WC_Session_Handler {
        private array $data = [];
        public bool $cookie_set = false;
        public int $cookie_set_calls = 0;
        public int $save_data_calls = 0;
        // WooCommerce generates guest customer IDs with a t_ prefix. See
        // WC_Session_Handler::generate_customer_id() and is_customer_guest().
        public string $customer_id = 't_guest_session_123';
        public bool $has_active_session = false;
        public function init(): void {}
        public function get( string $key, $default = null ) {
            return $this->data[ $key ] ?? $default;
        }
        public function set( string $key, $value ): void {
            $this->data[ $key ] = $value;
        }
        public function set_customer_id( string $id ): void {
            $this->customer_id = $id;
        }
        public function get_customer_id(): string {
            return $this->customer_id;
        }
        public function save_data(): void {
            $this->save_data_calls++;
        }
        public function set_customer_session_cookie( bool $val ): void {
            $this->cookie_set = $val;
            $this->cookie_set_calls++;
        }
        public function has_session(): bool {
            return $this->has_active_session;
        }
    }
}

if ( ! class_exists( 'WC_Cart_Mock' ) ) {
    class WC_Cart_Mock {
        public array $session_cart = [];
        public array $cart_for_session = [];
        public int $persistent_cart_updates = 0;
        public function persistent_cart_update(): void {
            $this->persistent_cart_updates++;
        }
        public function get_cart_for_session(): array {
            return ! empty( $this->cart_for_session ) ? $this->cart_for_session : $this->session_cart;
        }
        public function is_empty(): bool {
            return empty( $this->get_cart_for_session() );
        }
    }
}

if ( ! class_exists( 'WC_Product' ) ) {
    class WC_Product {
        private int $id = 0;
        private array $meta = [];
        public int $save_calls = 0;

        public function __construct( int $id = 0 ) {
            $this->id = $id;
        }

        public function get_id(): int {
            return $this->id;
        }

        public function update_meta_data( string $key, $value ): void {
            $this->meta[ $key ] = $value;
            if ( $this->id > 0 && function_exists( 'update_post_meta' ) ) {
                update_post_meta( $this->id, $key, $value );
            }
        }

        public function delete_meta_data( string $key ): void {
            unset( $this->meta[ $key ] );
            if ( $this->id > 0 && function_exists( 'delete_post_meta' ) ) {
                delete_post_meta( $this->id, $key );
            }
        }

        public function get_meta( string $key, bool $single = true ) {
            return $this->meta[ $key ] ?? '';
        }

        public function save(): int {
            $this->save_calls++;
            $GLOBALS['gecx_test_products'][ $this->id ] = $this;
            return $this->id;
        }
    }
}

if ( ! function_exists( 'wc_get_product' ) ) {
    function wc_get_product( $product_id ) {
        $id = (int) $product_id;
        return $GLOBALS['gecx_test_products'][ $id ] ?? false;
    }
}

if ( ! class_exists( 'WooCommerce_Mock' ) ) {
    class WooCommerce_Mock {
        public $session = null;
        public $cart = null;
        public function __construct() {
            $this->session = new WC_Session_Handler();
            $this->cart    = new WC_Cart_Mock();
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

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'OBJECT_K' ) ) {
    define( 'OBJECT_K', 'OBJECT_K' );
}
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'ARRAY_N' ) ) {
    define( 'ARRAY_N', 'ARRAY_N' );
}

if ( ! class_exists( 'GECX_Mock_WPDB' ) ) {
    class GECX_Mock_WPDB {
        public string $prefix        = 'wp_';
        public string $options       = 'wp_options';
        public string $usermeta      = 'wp_usermeta';
        public int $rows_affected    = 0;
        public array $queries        = [];
        public array $wc_sessions    = [];

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
                $unescaped = str_replace( '\\_', '_', stripslashes( $query ) );
                if ( false !== strpos( $unescaped, 'woocommerce_sessions' ) ) {
                    return $this->prefix . 'woocommerce_sessions';
                }
                return $this->prefix . 'wc_webhooks';
            }
            if ( preg_match( "/SELECT session_value FROM `?([a-zA-Z0-9_]+)`? WHERE session_key = '([^']+)'/", $query, $m ) ) {
                $session_key = stripslashes( $m[2] );
                if ( isset( $this->wc_sessions[ $session_key ] ) ) {
                    return $this->wc_sessions[ $session_key ];
                }
                return $GLOBALS['gecx_test_wc_sessions_table'][ $session_key ]['session_value'] ?? null;
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

        public function query( string $query ) {
            $this->queries[]     = $query;
            $this->rows_affected = 0;

            if ( preg_match(
                "/INSERT INTO `?([a-zA-Z0-9_]*woocommerce_sessions)`?\s*\(`?session_key`?,\s*`?session_value`?,\s*`?session_expiry`?\)\s*VALUES\s*\('([^']*)',\s*'(.*)',\s*(\d+)\)\s*ON DUPLICATE KEY UPDATE/s",
                $query,
                $m
            ) ) {
                $session_key   = stripslashes( $m[2] );
                $session_value = stripslashes( $m[3] );
                $expiry        = (int) $m[4];
                $this->wc_sessions[ $session_key ]                      = $session_value;
                $GLOBALS['gecx_test_wc_sessions_table'][ $session_key ] = [
                    'session_key'    => $session_key,
                    'session_value'  => $session_value,
                    'session_expiry' => $expiry,
                ];
                $this->rows_affected = 1;
                return 1;
            }

            if ( false !== strpos( $query, 'DELETE FROM' ) && false !== strpos( $query, 'options' ) ) {
                if ( preg_match_all( "/LIKE '([^']+)'/", $query, $likes ) ) {
                    foreach ( $likes[1] as $like_pattern ) {
                        $prefix = str_replace( [ '\\_', '\\%', '%' ], [ '_', '%', '' ], stripslashes( $like_pattern ) );
                        foreach ( array_keys( $GLOBALS['gecx_test_options'] ?? [] ) as $opt_name ) {
                            if ( 0 === strpos( (string) $opt_name, $prefix ) ) {
                                unset( $GLOBALS['gecx_test_options'][ $opt_name ] );
                                $this->rows_affected++;
                            }
                        }
                    }
                }
                return $this->rows_affected;
            }

            return 0;
        }

        public function get_results( string $query, string $output = OBJECT ): array {
            if ( false !== strpos( $query, 'woocommerce_sessions' ) && false !== strpos( $query, 'session_value LIKE' ) ) {
                $min_key = '';
                if ( preg_match( "/session_key > '([^']*)'/", $query, $m ) ) {
                    $min_key = stripslashes( $m[1] );
                }
                $results = [];
                $keys    = array_unique(
                    array_merge(
                        array_keys( $this->wc_sessions ),
                        array_keys( $GLOBALS['gecx_test_wc_sessions_table'] ?? [] )
                    )
                );
                sort( $keys, SORT_STRING );
                foreach ( $keys as $k ) {
                    if ( '' !== $min_key && strcmp( (string) $k, $min_key ) <= 0 ) {
                        continue;
                    }
                    $val = $this->wc_sessions[ $k ] ?? ( $GLOBALS['gecx_test_wc_sessions_table'][ $k ]['session_value'] ?? null );
                    if ( is_string( $val ) && false !== strpos( $val, 'gecx_session_id' ) ) {
                        $results[] = (object) [
                            'session_key'   => (string) $k,
                            'session_value' => $val,
                        ];
                    }
                }
                return $results;
            }
            return [];
        }

        public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
            if ( false !== strpos( $table, 'woocommerce_sessions' ) && isset( $where['session_key'], $data['session_value'] ) ) {
                $key = (string) $where['session_key'];
                $val = (string) $data['session_value'];
                $this->wc_sessions[ $key ] = $val;
                if ( isset( $GLOBALS['gecx_test_wc_sessions_table'][ $key ] ) ) {
                    $GLOBALS['gecx_test_wc_sessions_table'][ $key ]['session_value'] = $val;
                }
                $this->rows_affected = 1;
                return 1;
            }
            $this->rows_affected = 0;
            return 0;
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
            $this->rows_affected = $deleted;
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
        private string $method = 'GET';
        public function __construct( string $method = 'GET', string $route = '/gecx/v1/public-key' ) {
            $this->method = strtoupper( $method );
            $this->route  = $route;
        }
        public function set_method( string $method ): void {
            $this->method = strtoupper( $method );
        }
        public function get_method(): string {
            return $this->method;
        }
        public function set_header( string $name, string $value ): void {
            $this->headers[ strtolower( $name ) ] = $value;
        }
        public function get_header( string $name ): ?string {
            return $this->headers[ strtolower( $name ) ] ?? null;
        }
        public function get_headers(): array {
            return $this->headers;
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


if ( ! function_exists( 'wp_cache_delete' ) ) {
    function wp_cache_delete( $key, string $group = '' ): bool {
        $GLOBALS['gecx_test_deleted_cache_keys'][] = [
            'key'   => (string) $key,
            'group' => $group,
        ];
        return true;
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
    function user_can( $user, string $capability, ...$args ): bool {
        if ( is_numeric( $user ) ) {
            $user = get_userdata( (int) $user );
        }
        if ( ! ( $user instanceof WP_User ) ) {
            return false;
        }
        $allcaps = $user->allcaps;
        if ( in_array( 'administrator', $user->roles, true ) ) {
            $allcaps['manage_options']     = true;
            $allcaps['manage_woocommerce'] = true;
            $allcaps['edit_post']          = true;
            $allcaps['delete_users']       = true;
        }
        if ( in_array( 'shop_manager', $user->roles, true ) ) {
            $allcaps['manage_woocommerce'] = true;
            $allcaps['edit_post']          = true;
        }
        if ( function_exists( 'apply_filters' ) ) {
            $allcaps = (array) apply_filters(
                'user_has_cap',
                $allcaps,
                [ $capability ],
                array_merge( [ $capability, $user->ID ], $args ),
                $user
            );
        }
        return ! empty( $allcaps[ $capability ] );
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( string $capability, ...$args ): bool {
        if ( ! empty( $GLOBALS['gecx_test_current_user'] ) ) {
            return user_can( $GLOBALS['gecx_test_current_user'], $capability, ...$args );
        }
        return false;
    }
}

if ( ! function_exists( 'get_users' ) ) {
    function get_users( array $args = [] ): array {
        $candidates = $GLOBALS['gecx_test_users'] ?? [];
        if ( ! empty( $GLOBALS['gecx_test_current_user'] ) && $GLOBALS['gecx_test_current_user'] instanceof WP_User && $GLOBALS['gecx_test_current_user']->ID > 0 ) {
            $candidates[ $GLOBALS['gecx_test_current_user']->ID ] = $GLOBALS['gecx_test_current_user'];
        }
        $role = $args['role'] ?? '';
        $matched = [];
        foreach ( $candidates as $u ) {
            if ( ! ( $u instanceof WP_User ) ) {
                continue;
            }
            if ( '' !== $role && ! in_array( $role, $u->roles, true ) ) {
                continue;
            }
            $matched[ $u->ID ] = $u;
        }
        ksort( $matched );
        $list = array_values( $matched );
        if ( isset( $args['number'] ) && (int) $args['number'] > 0 ) {
            $list = array_slice( $list, 0, (int) $args['number'] );
        }
        if ( isset( $args['fields'] ) && 'ID' === $args['fields'] ) {
            return array_map(
                static function ( WP_User $u ): int {
                    return $u->ID;
                },
                $list
            );
        }
        return $list;
    }
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
    function get_current_blog_id(): int {
        if ( ! empty( $GLOBALS['gecx_test_blog_stack'] ) ) {
            return (int) end( $GLOBALS['gecx_test_blog_stack'] );
        }
        return 1;
    }
}

if ( ! function_exists( 'is_user_member_of_blog' ) ) {
    function is_user_member_of_blog( int $user_id = 0, int $blog_id = 0 ): bool {
        if ( $user_id <= 0 ) {
            $user_id = get_current_user_id();
        }
        if ( $blog_id <= 0 ) {
            $blog_id = get_current_blog_id();
        }
        if ( isset( $GLOBALS['gecx_test_blog_memberships'][ $blog_id ] ) && is_array( $GLOBALS['gecx_test_blog_memberships'][ $blog_id ] ) ) {
            return ! empty( $GLOBALS['gecx_test_blog_memberships'][ $blog_id ][ $user_id ] );
        }
        return true;
    }
}

if ( ! function_exists( 'is_super_admin' ) ) {
    function is_super_admin( $user_id = false ): bool {
        $uid = false === $user_id ? get_current_user_id() : (int) $user_id;
        return ! empty( $GLOBALS['gecx_test_super_admins'][ $uid ] );
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

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( string $url ): string {
        $clean = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', trim( $url ) );
        if ( '' === $clean ) {
            return '';
        }
        if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $clean, $m ) ) {
            $scheme = strtolower( $m[1] );
            if ( ! in_array( $scheme, [ 'http', 'https', 'mailto', 'tel' ], true ) ) {
                return '';
            }
        }
        return $clean;
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( string $url ): string {
        $clean = esc_url_raw( $url );
        if ( '' === $clean ) {
            return '';
        }
        return str_replace( [ '"', "'", '<', '>' ], [ '&quot;', '&#039;', '&lt;', '&gt;' ], $clean );
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

if ( ! function_exists( 'is_rtl' ) ) {
    function is_rtl(): bool {
        return ! empty( $GLOBALS['gecx_test_is_rtl'] );
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
        $uid = get_current_user_id();
        if ( ! $uid && function_exists( 'apply_filters' ) ) {
            $uid = (int) apply_filters( 'nonce_user_logged_out', $uid, $action );
        }
        $expected = $uid > 0 ? 'test_nonce_' . $action . '_u' . $uid : 'test_nonce_' . $action;
        return is_string( $nonce ) && $nonce === $expected;
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
        if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
            return false;
        }
        if ( empty( $parsed['host'] ) ) {
            return false;
        }
        $host = strtolower( trim( (string) $parsed['host'], '[]' ) );
        if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) ) {
            return false;
        }
        if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
            if ( false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return false;
            }
        }
        if ( isset( $parsed['port'] ) && ! in_array( (int) $parsed['port'], [ 80, 443, 8080 ], true ) ) {
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
        $GLOBALS['gecx_test_action_callbacks'][ $hook_name ] = $callback;
        return true;
    }
}

if ( ! class_exists( 'WP_Screen' ) ) {
    class WP_Screen {
        public string $id = '';
        public string $base = '';

        public function __construct( string $id = '' ) {
            $this->id   = $id;
            $this->base = $id;
        }
    }
}

if ( ! function_exists( 'get_current_screen' ) ) {
    function get_current_screen() {
        return $GLOBALS['gecx_test_current_screen'] ?? null;
    }
}

if ( ! function_exists( 'set_current_screen' ) ) {
    function set_current_screen( $screen = null ): void {
        if ( is_string( $screen ) ) {
            $GLOBALS['gecx_test_current_screen'] = new WP_Screen( $screen );
        } elseif ( $screen instanceof WP_Screen || is_null( $screen ) ) {
            $GLOBALS['gecx_test_current_screen'] = $screen;
        }
    }
}

if ( ! function_exists( 'add_submenu_page' ) ) {
    function add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
        return 'marketing_page_' . $menu_slug;
    }
}

if ( ! function_exists( 'get_site_by_path' ) ) {
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WordPress get_site_by_path signature.
    function get_site_by_path( string $domain, string $path, $segments = null ) {
        $normalized = '/' . trim( $path, '/' );
        if ( '/' !== $normalized ) {
            $normalized .= '/';
        }
        $sites_by_path = $GLOBALS['gecx_test_sites_by_path'] ?? [];
        if ( is_array( $sites_by_path ) ) {
            foreach ( $sites_by_path as $prefix => $blog_id ) {
                $prefix_norm = '/' . trim( (string) $prefix, '/' ) . '/';
                if ( '//' !== $prefix_norm && 0 === strpos( $normalized, $prefix_norm ) ) {
                    if ( false === $blog_id ) {
                        return false;
                    }
                    return (object) [ 'blog_id' => (int) $blog_id ];
                }
            }
        }
        return (object) [ 'blog_id' => 1 ];
    }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( string $hook, array $args = [] ) {
        foreach ( $GLOBALS['gecx_test_scheduled_events'] ?? [] as $event ) {
            if ( ( $event['hook'] ?? '' ) === $hook && ( $event['args'] ?? [] ) === $args ) {
                return (int) $event['timestamp'];
            }
        }
        return false;
    }
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
    function wp_clear_scheduled_hook( string $hook, array $args = [] ): int {
        $cleared  = 0;
        $remaining = [];
        foreach ( $GLOBALS['gecx_test_scheduled_events'] ?? [] as $event ) {
            if ( ( $event['hook'] ?? '' ) === $hook && ( empty( $args ) || ( $event['args'] ?? [] ) === $args ) ) {
                $cleared++;
                continue;
            }
            $remaining[] = $event;
        }
        $GLOBALS['gecx_test_scheduled_events'] = $remaining;
        return $cleared;
    }
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
    function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ): bool {
        $GLOBALS['gecx_test_scheduled_events'][] = [
            'timestamp' => $timestamp,
            'hook'      => $hook,
            'args'      => $args,
        ];
        if ( empty( $GLOBALS['gecx_test_defer_cron'] ) && isset( $GLOBALS['gecx_test_action_callbacks'][ $hook ] ) ) {
            call_user_func_array( $GLOBALS['gecx_test_action_callbacks'][ $hook ], $args );
        }
        return true;
    }
}

if ( ! function_exists( 'check_admin_referer' ) ) {
    function check_admin_referer( $action = -1, string $query_arg = '_wpnonce' ): bool {
        return true;
    }
}

if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '', $title = '', $args = [] ): void {}
}

if ( ! function_exists( 'wp_set_script_translations' ) ) {
    function wp_set_script_translations( string $handle, string $domain = 'default', string $path = '' ): bool {
        $GLOBALS['gecx_test_script_translations'][ $handle ] = [
            'domain' => $domain,
            'path'   => $path,
        ];
        return true;
    }
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
    function load_plugin_textdomain( string $domain, $deprecated = false, string $plugin_rel_path = '' ): bool {
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
        $clean = esc_url_raw( $location );
        if ( '' === $clean ) {
            return false;
        }
        $is_relative = ( 0 === strpos( $clean, '/' ) && 0 !== strpos( $clean, '//' ) );
        if ( ! $is_relative ) {
            $scheme      = strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) );
            $target_host = strtolower( (string) wp_parse_url( $clean, PHP_URL_HOST ) );
            $home_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
            $allowed     = [ $home_host ];
            if ( function_exists( 'apply_filters' ) ) {
                $allowed = array_map( 'strtolower', (array) apply_filters( 'allowed_redirect_hosts', $allowed, $target_host ) );
            }
            if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $target_host || ! in_array( $target_host, $allowed, true ) ) {
                return false;
            }
        }
        $GLOBALS['gecx_test_last_redirect'] = $clean;
        return true;
    }
}

if ( ! function_exists( 'wp_redirect' ) ) {
    function wp_redirect( string $location, int $status = 302 ): bool {
        $GLOBALS['gecx_test_last_redirect'] = $location;
        return true;
    }
}

if ( ! function_exists( 'register_setting' ) ) {
    function register_setting( string $option_group, string $option_name, array $args = [] ): void {
        $GLOBALS['gecx_test_registered_settings'][ $option_name ] = [
            'group' => $option_group,
            'args'  => $args,
        ];
    }
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action = -1, $query_arg = false, $die = true ): bool {
        $nonce = '';
        if ( false !== $query_arg && is_string( $query_arg ) ) {
            $nonce = $_REQUEST[ $query_arg ] ?? $_POST[ $query_arg ] ?? $_GET[ $query_arg ] ?? '';
        } else {
            $nonce = $_REQUEST['_ajax_nonce'] ?? $_POST['_ajax_nonce'] ?? $_GET['_ajax_nonce'] ?? $_REQUEST['_wpnonce'] ?? $_POST['_wpnonce'] ?? $_GET['_wpnonce'] ?? '';
        }
        $verified = wp_verify_nonce( is_string( $nonce ) ? $nonce : '', $action );
        if ( ! $verified && $die ) {
            wp_die( -1, 403 );
        }
        return $verified;
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
        $GLOBALS['gecx_test_options'][ '_transient_' . $transient ] = $value;
        if ( $expiration > 0 ) {
            $GLOBALS['gecx_test_options'][ '_transient_timeout_' . $transient ] = time() + $expiration;
        }
        return true;
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $transient ) {
        if ( isset( $GLOBALS['gecx_test_options'] ) && array_key_exists( '_transient_' . $transient, $GLOBALS['gecx_test_options'] ) ) {
            return $GLOBALS['gecx_test_options'][ '_transient_' . $transient ];
        }
        return false;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( string $transient ): bool {
        unset( $GLOBALS['gecx_test_options'][ '_transient_' . $transient ] );
        unset( $GLOBALS['gecx_test_options'][ '_transient_timeout_' . $transient ] );
        return true;
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

/**
 * Mirrors core: wp_safe_remote_post() sets reject_unsafe_urls, validates the
 * destination via wp_http_validate_url(), and delegates.
 */
if ( ! function_exists( 'wp_safe_remote_post' ) ) {
    function wp_safe_remote_post( string $url, array $args = [] ) {
        $args['reject_unsafe_urls'] = true;
        if ( false === wp_http_validate_url( $url ) ) {
            return new WP_Error( 'http_request_not_executed', 'User has blocked requests through HTTP.' );
        }
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

if ( ! function_exists( 'is_cart' ) ) {
    function is_cart(): bool {
        return ! empty( $GLOBALS['gecx_test_is_cart'] );
    }
}

if ( ! function_exists( 'is_checkout' ) ) {
    function is_checkout(): bool {
        return ! empty( $GLOBALS['gecx_test_is_checkout'] );
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

if ( ! function_exists( '_gecx_test_sanitize_text_fields' ) ) {
    /**
     * Port of WordPress core's _sanitize_text_fields() (wp-includes/formatting.php).
     *
     * This is deliberately faithful rather than convenient. The previous stub
     * was trim( strip_tags() ), which silently omitted the percent-octet
     * stripping loop and the whitespace collapsing. Any test that asserted a
     * value survives sanitization, or that a malformed value is still rejected
     * after it, passed here and would have been wrong in production.
     *
     * Behaviour reproduced, in core's order:
     *   1. Invalid UTF-8 yields an empty string.
     *   2. If the input contains "<", stray less-than signs are escaped, then
     *      script/style blocks and all tags are removed.
     *   3. Newlines and tabs collapse into single spaces unless they are kept.
     *   4. Every /%[a-f0-9]{2}/i sequence is removed, repeatedly, and the
     *      whitespace that removal leaves behind is collapsed again.
     *
     * @param string $str           Value to sanitize.
     * @param bool   $keep_newlines Whether to preserve newlines.
     * @return string The sanitized value.
     */
    function _gecx_test_sanitize_text_fields( string $str, bool $keep_newlines = false ): string {
        if ( '' === $str ) {
            return '';
        }

        // wp_check_invalid_utf8(): core returns an empty string for input the
        // PCRE UTF-8 matcher rejects.
        if ( 1 !== preg_match( '/^./us', $str ) ) {
            return '';
        }

        $filtered = $str;

        if ( false !== strpos( $filtered, '<' ) ) {
            // wp_pre_kses_less_than(): escape a "<" that does not open a tag.
            $filtered = preg_replace_callback(
                '%<[^>]*?((?=<)|>|$)%',
                static function ( array $matches ): string {
                    if ( false === strpos( $matches[0], '>' ) ) {
                        return htmlspecialchars( $matches[0], ENT_QUOTES, 'UTF-8' );
                    }
                    return $matches[0];
                },
                $filtered
            );

            // wp_strip_all_tags(): drop script and style bodies, then all tags.
            $filtered = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $filtered );
            $filtered = trim( strip_tags( $filtered ) );

            $filtered = str_replace( "<\n", "&lt;\n", $filtered );
        }

        if ( ! $keep_newlines ) {
            $filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
        }
        $filtered = trim( $filtered );

        // Remove percent-encoded characters. This is the step the old stub
        // omitted, and the reason "cs_1234%ab5678" looks well-formed to an
        // allowlist that runs after sanitization.
        $found = false;
        while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
            $filtered = str_replace( $match[0], '', $filtered );
            $found    = true;
        }

        if ( $found ) {
            $filtered = trim( preg_replace( '/ +/', ' ', $filtered ) );
        }

        return $filtered;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return _gecx_test_sanitize_text_fields( $str, false );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( string $str ): string {
        return _gecx_test_sanitize_text_fields( $str, true );
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

        public function assertLessThan( $expected, $actual, string $message = '' ): void {
            if ( ! ( $actual < $expected ) ) {
                throw new \AssertionError( ( $message ?: 'Failed asserting that actual is less than expected.' ) . " Expected < $expected, Actual: $actual" );
            }
        }

        public function assertStringStartsWith( string $prefix, string $string, string $message = '' ): void {
            if ( strpos( $string, $prefix ) !== 0 ) {
                throw new \AssertionError( ( $message ?: 'Failed asserting that string starts with prefix.' ) . "\nPrefix: $prefix\nString: $string" );
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
                $method_ref = new ReflectionMethod( $test, $method );
                $doc        = (string) $method_ref->getDocComment();
                $datasets   = [ [] ];
                if ( preg_match( '/@dataProvider\s+([a-zA-Z0-9_]+)/', $doc, $m ) && method_exists( $test, $m[1] ) ) {
                    $provider = $m[1];
                    $datasets = (array) $test->$provider();
                }

                foreach ( $datasets as $args ) {
                    $ref = new ReflectionMethod( $test, 'setUp' );
                    $ref->setAccessible( true );
                    $ref->invoke( $test );

                    $test->$method( ...array_values( (array) $args ) );

                    $ref = new ReflectionMethod( $test, 'tearDown' );
                    $ref->setAccessible( true );
                    $ref->invoke( $test );
                }

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
