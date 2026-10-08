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

namespace Google\Gemini_Enterprise_For_CX\REST;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Creates the components that make up the plugin's REST API. Each registers
 * its own routes and hooks.
 */
class REST_API {

    /** Console routes and WooCommerce API key authentication. */
    public Console_API $console;

    /** Cart session sync for carts the agent changes. */
    public Cart_Session $cart_session;

    /** Chat session to order attribution. */
    public Session_Attribution $session_attribution;

    /** Shopper auth context for the storefront widget. */
    public Auth_Context $auth_context;

    /** The order webhook. */
    public Order_Webhook $order_webhook;

    /**
     * Creates the components.
     */
    public function __construct() {
        $this->console             = new Console_API();
        $this->cart_session        = new Cart_Session();
        $this->session_attribution = new Session_Attribution();
        $this->auth_context        = new Auth_Context();
        $this->order_webhook       = new Order_Webhook();
    }
}
