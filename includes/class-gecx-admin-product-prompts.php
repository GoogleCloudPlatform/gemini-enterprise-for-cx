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
 * Gemini Enterprise for CX product prompt overrides
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * The Suggested Prompts override field in the WooCommerce product editor.
 */
class GECX_Admin_Product_Prompts {

    /**
     * Product IDs already processed by save_product_prompts_override_field()
     * in the current request so dual WooCommerce save hooks write once.
     *
     * @var array<int, bool>
     */
    private array $saved_product_prompt_ids = [];

    /**
     * Registers this component's hooks.
     */
    public function register_hooks(): void {
        // Register product settings override hooks (WooCommerce CRUD + legacy post meta fallback).
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'add_product_prompts_override_field' ] );
        add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_prompts_override_field' ] );
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_prompts_override_field' ] );
    }

    /**
     * Render a custom field for suggested prompts override on the product general edit tab.
     */
    public function add_product_prompts_override_field(): void {
        echo '<div class="options_group">';
        wp_nonce_field( 'gecx_save_prompts_override', 'gecx_prompts_override_nonce' );
        woocommerce_wp_textarea_input( [
            'id'          => '_gecx_suggested_prompts_override',
            'label'       => __( 'GECX Prompts Override', 'gemini-enterprise-for-cx' ),
            /* translators: Example prompts shown as placeholder text. One prompt per line; keep the newline separators. */
            'placeholder' => __( "What is the return policy?\nIs this machine washable?\nCompare with similar items", 'gemini-enterprise-for-cx' ),
            'desc_tip'    => true,
            'description' => __( 'Enter one prompt per line to override AI-generated prompts for this product.', 'gemini-enterprise-for-cx' ),
        ] );
        echo '</div>';
    }

    /**
     * Save the product suggested prompts override custom field via WooCommerce
     * product CRUD (`WC_Product::update_meta_data` / `delete_meta_data`) with a
     * post-meta fallback.
     *
     * @param mixed $product_or_id `WC_Product` object (from `woocommerce_admin_process_product_object`)
     *                             or integer product post ID (from `woocommerce_process_product_meta`).
     */
    public function save_product_prompts_override_field( $product_or_id ): void {
        if ( ! isset( $_POST['gecx_prompts_override_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gecx_prompts_override_nonce'] ) ), 'gecx_save_prompts_override' ) ) {
            return;
        }

        $product = null;
        $post_id = 0;
        if ( is_object( $product_or_id ) && method_exists( $product_or_id, 'get_id' ) ) {
            $product = $product_or_id;
            $post_id = (int) $product_or_id->get_id();
        } elseif ( is_numeric( $product_or_id ) ) {
            $post_id = (int) $product_or_id;
            if ( function_exists( 'wc_get_product' ) ) {
                $candidate = wc_get_product( $post_id );
                if ( is_object( $candidate ) ) {
                    $product = $candidate;
                }
            }
        }

        if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( ! is_object( $product_or_id ) && ! empty( $this->saved_product_prompt_ids[ $post_id ] ) ) {
            return;
        }
        if ( is_object( $product_or_id ) ) {
            $this->saved_product_prompt_ids[ $post_id ] = true;
        }

        $override = isset( $_POST['_gecx_suggested_prompts_override'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_gecx_suggested_prompts_override'] ) ) : '';
        if ( null !== $product && method_exists( $product, 'update_meta_data' ) && method_exists( $product, 'delete_meta_data' ) ) {
            if ( ! empty( $override ) ) {
                $product->update_meta_data( '_gecx_suggested_prompts_override', $override );
            } else {
                $product->delete_meta_data( '_gecx_suggested_prompts_override' );
            }
            if ( ! is_object( $product_or_id ) && method_exists( $product, 'save' ) ) {
                $product->save();
            }
            return;
        }

        if ( ! empty( $override ) ) {
            update_post_meta( $post_id, '_gecx_suggested_prompts_override', $override );
        } else {
            delete_post_meta( $post_id, '_gecx_suggested_prompts_override' );
        }
    }
}
