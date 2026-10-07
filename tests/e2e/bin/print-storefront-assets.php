<?php
/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Prints the storefront CSS and markup the plugin renders, as JSON, so the
 * placement fixtures test the real thing rather than a copy of it.
 */

require dirname( __DIR__, 2 ) . '/bootstrap.php';

use Google\Gemini_Enterprise_For_CX\Storefront;

update_option( 'gecx_agent_name', 'projects/123/locations/global/agents/e2e' );
update_option( 'gecx_agent_enabled', 1 );

$storefront = new Storefront( dirname( __DIR__, 3 ) . '/gecx-agent.php' );
$storefront->enqueue_storefront_assets();

$floating = [];
foreach ( Storefront::ALLOWED_FLOATING_POSITIONS as $position ) {
    $floating[ $position ] = $storefront->get_floating_container_style( $position );
}

echo wp_json_encode(
    [
        'css'            => file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/theme.css' ) . "\n" . ( $GLOBALS['gecx_test_inline_styles']['gecx-widget-style'] ?? '' ),
        'buttonHtml'     => $storefront->get_agent_button_html(),
        'pdpPromptsHtml' => $storefront->get_suggested_prompts_html( 0 ),
        'floatingStyles' => $floating,
    ]
);
