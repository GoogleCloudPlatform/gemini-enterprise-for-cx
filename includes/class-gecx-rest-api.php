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
 * Gemini Enterprise for CX REST API
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

require_once __DIR__ . '/class-gecx-rest-console-api.php';
require_once __DIR__ . '/class-gecx-rest-cart-session.php';
require_once __DIR__ . '/class-gecx-rest-session-attribution.php';
require_once __DIR__ . '/class-gecx-rest-auth-context.php';
require_once __DIR__ . '/class-gecx-rest-order-webhook.php';

/**
 * Creates the components that make up the plugin's REST API. Each registers
 * its own routes and hooks.
 */
class GECX_Rest_API {

    /** Console routes and WooCommerce API key authentication. */
    public GECX_Rest_Console_API $console;

    /** Cart session sync for carts the agent changes. */
    public GECX_Rest_Cart_Session $cart_session;

    /** Chat session to order attribution. */
    public GECX_Rest_Session_Attribution $session_attribution;

    /** Shopper auth context for the storefront widget. */
    public GECX_Rest_Auth_Context $auth_context;

    /** The order webhook. */
    public GECX_Rest_Order_Webhook $order_webhook;

    /**
     * Creates the components.
     */
    public function __construct() {
        $this->console             = new GECX_Rest_Console_API();
        $this->cart_session        = new GECX_Rest_Cart_Session();
        $this->session_attribution = new GECX_Rest_Session_Attribution();
        $this->auth_context        = new GECX_Rest_Auth_Context();
        $this->order_webhook       = new GECX_Rest_Order_Webhook();
    }
}
