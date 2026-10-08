<?php
/**
 * Copyright 2026 Google LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Gemini Enterprise for CX order webhook
 */

declare(strict_types=1);

namespace Google\Gemini_Enterprise_For_CX\REST;

use Google\Gemini_Enterprise_For_CX\Admin;
use Google\Gemini_Enterprise_For_CX\Auth;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Manages the WooCommerce order webhook that tells Gemini Enterprise for CX
 * about attributed orders: its secret, creation, status and removal, which
 * orders it is delivered for, and what its payload contains.
 */
class Order_Webhook {

    /**
     * Longest accepted value for a WooCommerce consumer secret.
     *
     * A WooCommerce consumer secret is 'cs_' followed by 40 hex characters, so
     * it is well under this. The bound exists so that an authenticated caller
     * cannot park a megabyte in the options table.
     */
    private const MAX_SECRET_LENGTH = 512;

    /**
     * Registers this component's hooks.
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_webhooks_rest_route' ] );
        add_filter( 'woocommerce_webhook_should_deliver', [ $this, 'gate_order_webhook_delivery' ], 10, 3 );
        add_filter( 'woocommerce_webhook_payload', [ $this, 'minimize_order_webhook_payload' ], 10, 4 );
    }

    /**
     * Checks that a secret is a plausible WooCommerce consumer secret.
     *
     * @param string $secret Candidate secret.
     * @return bool True if the secret is within the length bound and uses only base64 and base64url characters.
     */
    private static function is_valid_secret( string $secret ): bool {
        return strlen( $secret ) <= self::MAX_SECRET_LENGTH
            && 1 === preg_match( '/^[a-zA-Z0-9_\-\+\/=]+$/', $secret );
    }

    /**
     * Register API Route to register or update WooCommerce webhooks.
     */
    public function register_webhooks_rest_route(): void {
        register_rest_route( 'gecx/v1', '/webhooks/order-created', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'order_created_webhooks_handler' ],
            'permission_callback' => [ Console_API::class, 'check_admin_permissions' ],
            'args'                => [
                'consumer_secret' => [
                    'description'       => __( 'Optional WooCommerce consumer secret used to sign order.created webhook deliveries.', 'gemini-enterprise-for-cx' ),
                    'type'              => 'string',
                    'required'          => false,
                    'validate_callback' => static function( $value ): bool {
                        if ( null === $value || '' === trim( (string) $value ) ) {
                            return true;
                        }
                        return is_string( $value ) && self::is_valid_secret( trim( $value ) );
                    },
                ],
            ],
        ] );
    }

    /**
     * Helper to retrieve the webhook HMAC secret (the consumer_secret stored on
     * the WooCommerce webhook).
     */
    public static function get_webhook_secret(): string {
        $secret = '';
        $webhook_id = get_option( 'gecx_webhook_id' );
        if ( ! empty( $webhook_id ) && class_exists( 'WC_Webhook' ) ) {
            try {
                $webhook = new \WC_Webhook( (int) $webhook_id );
                if ( $webhook->get_id() ) {
                    $secret = (string) $webhook->get_secret();
                }
            } catch ( \Exception $e ) {
                $secret = '';
            }
        }
        return $secret;
    }

    /**
     * Ensures the order.created WooCommerce webhook is registered and signed with the preferred HMAC secret.
     *
     * Deliveries always go to the allowlisted console base URL; callers cannot
     * choose another destination.
     *
     * @param string $secret Optional explicit secret. Defaults to preferred webhook secret.
     * @return \WC_Webhook|\WP_Error Webhook instance or WP_Error on failure.
     */
    public static function ensure_order_webhook( string $secret = '' ) {
        if ( ! class_exists( 'WC_Webhook' ) ) {
            return new \WP_Error( 'woocommerce_not_active', __( 'WooCommerce WC_Webhook class not available.', 'gemini-enterprise-for-cx' ), [ 'status' => 500 ] );
        }

        if ( empty( $secret ) ) {
            $secret = self::get_webhook_secret();
        }

        if ( empty( $secret ) ) {
            return new \WP_Error( 'missing_secret', __( 'No webhook secret configured.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        $console_url = Auth::get_console_base_url();
        if ( '' === $console_url ) {
            return new \WP_Error( 'console_url_refused', __( 'The configured Google Cloud console URL is not allowed, so no webhook was registered.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }
        $delivery_url = $console_url . '/woocommerce/webhook';

        // Defence in depth: the origin is already allowlisted, but the
        // gecx_console_base_url filter runs inside get_console_base_url(), so
        // re-check the final URL rather than trust that nothing upstream
        // changes.

        $delivery_url = esc_url_raw( $delivery_url );
        $scheme       = (string) wp_parse_url( $delivery_url, PHP_URL_SCHEME );
        if ( 'https' !== $scheme || ! wp_http_validate_url( $delivery_url ) ) {
            return new \WP_Error( 'invalid_delivery_url', __( 'Webhook delivery URL must be a valid HTTPS URL.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        try {
            $existing_webhook_id = get_option( 'gecx_webhook_id' );
            $webhook             = null;
            if ( ! empty( $existing_webhook_id ) ) {
                try {
                    $webhook = new \WC_Webhook( (int) $existing_webhook_id );
                    if ( ! $webhook->get_id() ) {
                        $webhook = null;
                    }
                } catch ( \Exception $e ) {
                    $webhook = null;
                }
            }

            if ( class_exists( 'WC_Data_Store' ) ) {
                $webhook_data_store   = \WC_Data_Store::load( 'webhook' );
                $existing_webhook_ids = $webhook_data_store->search_webhooks( [
                    'search' => 'GECX Agent Order Created',
                    'limit'  => 25,
                ] );
                if ( is_array( $existing_webhook_ids ) ) {
                    foreach ( $existing_webhook_ids as $candidate_id ) {
                        $candidate = new \WC_Webhook( (int) $candidate_id );
                        if ( $candidate->get_id() && 'order.created' === $candidate->get_topic() && 'GECX Agent Order Created' === $candidate->get_name() ) {
                            if ( empty( $webhook ) || ! $webhook->get_id() ) {
                                $webhook = $candidate;
                                update_option( 'gecx_webhook_id', $webhook->get_id() );
                            } elseif ( $candidate->get_id() !== $webhook->get_id() ) {
                                $candidate->delete( true );
                            }
                        }
                    }
                }
            }

            $desired_status  = ( 1 === (int) get_option( 'gecx_agent_enabled', 1 ) ) ? 'active' : 'paused';
            $current_user_id = (int) get_current_user_id();
            if ( empty( $webhook ) || ! $webhook->get_id() ) {
                $webhook = new \WC_Webhook();
                $webhook->set_name( 'GECX Agent Order Created' );
                $webhook->set_user_id( $current_user_id );
                $webhook->set_topic( 'order.created' );
                $webhook->set_delivery_url( $delivery_url );
                $webhook->set_api_version( 'wp_api_v3' );
                $webhook->set_status( $desired_status );
                $webhook->set_secret( $secret );
                $webhook->save();
                update_option( 'gecx_webhook_id', $webhook->get_id() );
            } else {
                $needs_update = false;
                if ( $current_user_id > 0 && $webhook->get_user_id() !== $current_user_id ) {
                    $webhook->set_user_id( $current_user_id );
                    $needs_update = true;
                }
                if ( $webhook->get_delivery_url() !== $delivery_url ) {
                    $webhook->set_delivery_url( $delivery_url );
                    $needs_update = true;
                }
                if ( $webhook->get_secret() !== $secret ) {
                    $webhook->set_secret( $secret );
                    $needs_update = true;
                }
                if ( 'wp_api_v3' !== $webhook->get_api_version() ) {
                    $webhook->set_api_version( 'wp_api_v3' );
                    $needs_update = true;
                }
                if ( $desired_status !== $webhook->get_status() ) {
                    $webhook->set_status( $desired_status );
                    $needs_update = true;
                }
                if ( $needs_update ) {
                    $webhook->save();
                }
            }
            return $webhook;
        } catch ( \Exception $e ) {
            Auth::log( 'Failed to register WooCommerce order webhook: ' . $e->getMessage(), 'error' );
            return new \WP_Error(
                'webhook_registration_failed',
                __( 'Failed to register WooCommerce order webhook.', 'gemini-enterprise-for-cx' ),
                [ 'status' => 500 ]
            );
        }
    }

    /**
     * Handle the POST request to register or update WooCommerce order.created webhook.
     */
    public function order_created_webhooks_handler( \WP_REST_Request $request ) {
        // Deliberately not sanitized. is_valid_secret() below is a strict
        // allowlist, and this value becomes the HMAC key WooCommerce signs
        // order deliveries with, so it has to be validated byte for byte.
        // sanitize_text_field() strips percent-octets and tags, which would
        // turn a malformed secret into a well-formed one: "cs_1234%ab5678"
        // would be rejected today but would silently become "cs_12345678"
        // and be stored as the signing key, producing signature mismatches
        // on every delivery instead of a 400 at registration time.
        $consumer_secret = trim( (string) $request->get_param( 'consumer_secret' ) );
        if ( ! empty( $consumer_secret ) && ! self::is_valid_secret( $consumer_secret ) ) {
            return new \WP_Error( 'invalid_secret', __( 'Consumer secret is invalid.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        $webhook_secret = ! empty( $consumer_secret ) ? $consumer_secret : self::get_webhook_secret();
        if ( empty( $webhook_secret ) ) {
            return new \WP_Error( 'missing_secret', __( 'No webhook secret configured or provided.', 'gemini-enterprise-for-cx' ), [ 'status' => 400 ] );
        }

        $webhook = self::ensure_order_webhook( $webhook_secret );
        if ( is_wp_error( $webhook ) ) {
            return $webhook;
        }

        // New credentials are in place, so clear any invalidation recorded by
        // a previous SyncState reconciliation and mark store auth complete.
        update_option( 'gecx_auth_complete', 1, 'no' );
        delete_option( Admin::STORE_AUTH_INVALID_OPTION );

        return new \WP_REST_Response( [
            'success'    => true,
            'webhook_id' => $webhook->get_id(),
        ], 200 );
    }

    /**
     * Updates the status of the GECX order webhook (e.g. 'active' or 'paused')
     * and sweeps any duplicate/orphaned 'GECX Agent Order Created' webhooks.
     *
     * @param string $status Target WooCommerce webhook status ('active', 'paused', or 'disabled').
     */
    public static function set_order_webhook_status( string $status ): void {
        $wc_available = class_exists( 'WC_Webhook' );
        if ( ! $wc_available ) {
            return;
        }

        $stored_id = (int) get_option( 'gecx_webhook_id', 0 );
        $primary   = null;

        if ( $stored_id > 0 ) {
            try {
                $candidate = new \WC_Webhook( $stored_id );
                if ( $candidate->get_id() > 0 && 'order.created' === $candidate->get_topic() && 'GECX Agent Order Created' === $candidate->get_name() ) {
                    $primary = $candidate;
                }
            } catch ( \Exception $e ) {
                $primary = null;
            }
        }

        if ( function_exists( 'wc_get_webhooks' ) ) {
            try {
                $webhooks = wc_get_webhooks( [
                    'status' => 'any',
                    'search' => 'GECX Agent Order Created',
                    'limit'  => 25,
                ] );
                if ( is_array( $webhooks ) ) {
                    foreach ( $webhooks as $candidate ) {
                        if ( $candidate instanceof \WC_Webhook && 'order.created' === $candidate->get_topic() && 'GECX Agent Order Created' === $candidate->get_name() ) {
                            if ( null === $primary ) {
                                $primary = $candidate;
                                update_option( 'gecx_webhook_id', $primary->get_id() );
                            } elseif ( $candidate->get_id() !== $primary->get_id() ) {
                                try {
                                    $candidate->delete( true );
                                } catch ( \Throwable $e ) {
                                    // A duplicate webhook that cannot be deleted is not worth
                                    // failing the request over, but it should be visible.
                                    Auth::log( 'Failed to delete duplicate order webhook: ' . $e->getMessage(), 'debug' );
                                }
                            }
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                Auth::log( 'Failed to query order webhooks: ' . $e->getMessage(), 'debug' );
            }
        }

        if ( null !== $primary && $primary->get_id() > 0 ) {
            if ( $primary->get_status() !== $status ) {
                $primary->set_status( $status );
                try {
                    $primary->save();
                } catch ( \Throwable $e ) {
                    // Raising here would fatal inside an activation or deactivation hook.
                    Auth::log( 'Failed to save order webhook status: ' . $e->getMessage(), 'debug' );
                }
            }
        }
    }

    /**
     * Deletes the GECX order webhook (both via WC_Webhook API and direct $wpdb fallback)
     * and removes the gecx_webhook_id option.
     */
    public static function delete_order_webhook(): void {
        $stored_id    = (int) get_option( 'gecx_webhook_id', 0 );
        $wc_available = class_exists( 'WC_Webhook' );

        if ( $wc_available ) {
            if ( $stored_id > 0 ) {
                try {
                    $webhook = new \WC_Webhook( $stored_id );
                    if ( $webhook->get_id() > 0 ) {
                        $webhook->delete( true );
                    }
                } catch ( \Throwable $e ) {
                    // Cleanup continues with the remaining webhooks either way.
                    Auth::log( 'Failed to delete order webhook by stored id: ' . $e->getMessage(), 'debug' );
                }
            }
            if ( function_exists( 'wc_get_webhooks' ) ) {
                try {
                    $webhooks = wc_get_webhooks( [
                        'status' => 'any',
                        'search' => 'GECX Agent Order Created',
                        'limit'  => 25,
                    ] );
                    if ( is_array( $webhooks ) ) {
                        foreach ( $webhooks as $candidate ) {
                            if ( $candidate instanceof \WC_Webhook && 'order.created' === $candidate->get_topic() && 'GECX Agent Order Created' === $candidate->get_name() ) {
                                try {
                                    $candidate->delete( true );
                                } catch ( \Throwable $e ) {
                                    Auth::log( 'Failed to delete order webhook candidate: ' . $e->getMessage(), 'debug' );
                                }
                            }
                        }
                    }
                } catch ( \Throwable $e ) {
                    Auth::log( 'Failed to query order webhooks during cleanup: ' . $e->getMessage(), 'debug' );
                }
            }
        } else {
            global $wpdb;
            if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
                $table_name   = $wpdb->prefix . 'wc_webhooks';
                $table_exists = true;
                if ( method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
                    $escaped_like = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table_name ) : $table_name;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $escaped_like ) ) === $table_name );
                }
                if ( $table_exists ) {
                    if ( $stored_id > 0 ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->delete( $table_name, [ 'webhook_id' => $stored_id ], [ '%d' ] );
                        wp_cache_delete( $stored_id, 'webhooks' );
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->delete(
                        $table_name,
                        [
                            'name'  => 'GECX Agent Order Created',
                            'topic' => 'order.created',
                        ],
                        [ '%s', '%s' ]
                    );
                    delete_transient( 'woocommerce_webhook_ids' );
                    delete_transient( 'woocommerce_webhook_ids_status_active' );
                    delete_transient( 'woocommerce_webhook_ids_status_paused' );
                    delete_transient( 'woocommerce_webhook_ids_status_disabled' );
                }
            }
        }

        delete_option( 'gecx_webhook_id' );
    }

    /**
     * Reconciles the order webhook on plugin activation:
     * - If no agent is linked and the store is authorized, pauses the GECX order webhook.
     * - If no agent is linked and the store is not authorized, sweeps and deletes any orphaned GECX webhooks.
     * - If an agent is linked, adopts/deduplicates the webhook and sets its status
     *   to match gecx_agent_enabled ('active' when enabled, 'paused' when disabled).
     */
    public static function reconcile_webhook_on_activation(): void {
        $agent_name = (string) get_option( 'gecx_agent_name', '' );
        if ( '' === $agent_name ) {
            if ( (bool) get_option( 'gecx_auth_complete', false ) ) {
                self::set_order_webhook_status( 'paused' );
                return;
            }
            $has_stored_webhook = ! empty( get_option( 'gecx_webhook_id' ) );
            $wc_available       = class_exists( 'WC_Webhook' );
            if ( $has_stored_webhook || $wc_available ) {
                self::delete_order_webhook();
            }
            return;
        }
        $enabled = ( 1 === (int) get_option( 'gecx_agent_enabled', 0 ) );
        self::set_order_webhook_status( $enabled ? 'active' : 'paused' );
    }

    /**
     * Checks whether the given webhook instance or ID belongs to GECX order attribution.
     *
     * @param mixed $webhook_or_id WC_Webhook instance or integer webhook ID.
     * @return bool
     */
    private function is_gecx_order_webhook( $webhook_or_id ): bool {
        $stored_id = (int) get_option( 'gecx_webhook_id', 0 );
        $webhook   = null;

        if ( $webhook_or_id instanceof \WC_Webhook ) {
            $webhook = $webhook_or_id;
            if ( $stored_id > 0 && $webhook->get_id() === $stored_id ) {
                return true;
            }
        } elseif ( is_numeric( $webhook_or_id ) ) {
            $id = (int) $webhook_or_id;
            if ( $stored_id > 0 && $id === $stored_id ) {
                return true;
            }
            if ( $id > 0 && class_exists( 'WC_Webhook' ) ) {
                try {
                    $webhook = new \WC_Webhook( $id );
                } catch ( \Exception $e ) {
                    $webhook = null;
                }
            }
        }

        if ( $webhook instanceof \WC_Webhook && $webhook->get_id() > 0 ) {
            return 'order.created' === $webhook->get_topic()
                && 'GECX Agent Order Created' === $webhook->get_name();
        }

        return false;
    }

    /**
     * Suppresses delivery of the GECX order webhook when:
     * - the agent is unlinked or storefront chat widget is disabled, or
     * - the order does not carry a valid _gecx_session_id meta value.
     *
     * @param bool  $should_deliver Whether WooCommerce intends to deliver the webhook.
     * @param mixed $webhook        WC_Webhook instance or webhook ID.
     * @param mixed $arg            Hook argument (order ID or WC_Order).
     * @return bool
     */
    public function gate_order_webhook_delivery( $should_deliver, $webhook, $arg ): bool {
        if ( ! $should_deliver || ! $this->is_gecx_order_webhook( $webhook ) ) {
            return (bool) $should_deliver;
        }

        if ( empty( get_option( 'gecx_agent_name', '' ) ) || 1 !== (int) get_option( 'gecx_agent_enabled', 0 ) ) {
            return false;
        }

        $order = null;
        if ( $arg instanceof \WC_Order ) {
            $order = $arg;
        } elseif ( is_numeric( $arg ) && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( (int) $arg );
        }

        if ( ! $order instanceof \WC_Order ) {
            return false;
        }

        $session_id = (string) $order->get_meta( '_gecx_session_id' );
        return Session_Attribution::is_valid_session_id( $session_id, true );
    }

    /**
     * Replaces the full wp_api_v3 order record with only the minimal fields
     * required by the Gemini Enterprise backend. Strips all customer PII
     * (billing/shipping addresses, email, phone, IP, payment details, notes).
     *
     * @param mixed  $payload       Original webhook payload array.
     * @param string $resource_type Resource type (e.g. 'order').
     * @param mixed  $resource_id   Resource ID (order ID).
     * @param mixed  $webhook_id    Webhook ID.
     * @return mixed Minimized payload array for GECX webhooks, or original payload.
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed, Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WooCommerce webhook payload filter signature.
    public function minimize_order_webhook_payload( $payload, $resource_type, $resource_id, $webhook_id ) {
        if ( ! is_array( $payload ) || ! $this->is_gecx_order_webhook( $webhook_id ) ) {
            return $payload;
        }

        $order = null;
        if ( is_numeric( $resource_id ) && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( (int) $resource_id );
        }

        $session_id = '';
        if ( $order instanceof \WC_Order ) {
            $session_id = (string) $order->get_meta( '_gecx_session_id' );
        }
        if ( '' === $session_id && isset( $payload['meta_data'] ) && is_array( $payload['meta_data'] ) ) {
            foreach ( $payload['meta_data'] as $meta ) {
                if ( is_array( $meta ) && isset( $meta['key'] ) && '_gecx_session_id' === $meta['key'] ) {
                    $session_id = isset( $meta['value'] ) ? (string) $meta['value'] : '';
                    break;
                } elseif ( is_object( $meta ) && isset( $meta->key ) && '_gecx_session_id' === $meta->key ) {
                    $session_id = isset( $meta->value ) ? (string) $meta->value : '';
                    break;
                }
            }
        }

        $line_items = [];
        if ( isset( $payload['line_items'] ) && is_array( $payload['line_items'] ) ) {
            foreach ( $payload['line_items'] as $item ) {
                if ( is_array( $item ) ) {
                    $line_items[] = [
                        'product_id'   => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
                        'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
                        'name'         => isset( $item['name'] ) ? (string) $item['name'] : '',
                        'price'        => isset( $item['price'] ) ? $item['price'] : 0,
                        'quantity'     => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0,
                    ];
                }
            }
        } elseif ( $order instanceof \WC_Order && method_exists( $order, 'get_items' ) ) {
            foreach ( $order->get_items() as $item ) {
                if ( is_object( $item ) ) {
                    $qty   = method_exists( $item, 'get_quantity' ) ? max( 1, (int) $item->get_quantity() ) : 1;
                    $total = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
                    $line_items[] = [
                        'product_id'   => method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0,
                        'variation_id' => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : 0,
                        'name'         => method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '',
                        'price'        => method_exists( $order, 'get_item_total' ) ? (float) $order->get_item_total( $item, false, false ) : round( $total / $qty, 4 ),
                        'quantity'     => method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0,
                    ];
                }
            }
        }

        return [
            'id'             => isset( $payload['id'] ) ? (int) $payload['id'] : ( $order instanceof \WC_Order ? (int) $order->get_id() : (int) $resource_id ),
            'currency'       => isset( $payload['currency'] ) ? (string) $payload['currency'] : ( $order instanceof \WC_Order && method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'USD' ),
            'total'          => isset( $payload['total'] ) ? (string) $payload['total'] : ( $order instanceof \WC_Order && method_exists( $order, 'get_total' ) ? (string) $order->get_total() : '0.00' ),
            'total_tax'      => isset( $payload['total_tax'] ) ? (string) $payload['total_tax'] : ( $order instanceof \WC_Order && method_exists( $order, 'get_total_tax' ) ? (string) $order->get_total_tax() : '0.00' ),
            'shipping_total' => isset( $payload['shipping_total'] ) ? (string) $payload['shipping_total'] : ( $order instanceof \WC_Order && method_exists( $order, 'get_shipping_total' ) ? (string) $order->get_shipping_total() : '0.00' ),
            'meta_data'      => '' !== $session_id ? [
                [
                    'key'   => '_gecx_session_id',
                    'value' => $session_id,
                ],
            ] : [],
            'line_items'     => $line_items,
        ];
    }
}
