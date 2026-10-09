/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Storefront CSS and markup fixtures for static browser placement tests.
 */
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..', '..');

const themeCss = fs.readFileSync(path.join(ROOT, 'assets/css/theme.css'), 'utf8');

const inlineWidgetCss = `
footer .gecx-nav-menu-item, .site-footer .gecx-nav-menu-item, [role="contentinfo"] .gecx-nav-menu-item, .wp-block-template-part:has(footer) .gecx-nav-menu-item, #colophon .gecx-nav-menu-item, .elementor-location-footer .gecx-nav-menu-item { display: none !important; }
 :where(.gecx-nav-menu-item) { display: flex; align-items: center; justify-content: center; align-self: center; height: auto; margin: 0 4px; list-style: none; }
 .gecx-nav-menu-item gecx-agent-button { display: inline-flex; align-items: center; vertical-align: middle; }
 .wp-block-navigation__responsive-container.is-menu-open .gecx-nav-menu-item { display: none !important; }
 .gecx-mobile-header-button { display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; margin: 0 6px; align-self: center; height: auto; line-height: normal; }
 .gecx-mobile-header-button gecx-agent-button { display: inline-flex; align-items: center; vertical-align: middle; }
 .gecx-mobile-header-button.gecx-mobile-header-button--floating { position: fixed; bottom: calc(20px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); right: 20px; z-index: 99999; margin: 0; height: auto; }
 body.rtl .gecx-mobile-header-button.gecx-mobile-header-button--floating { right: auto; left: 20px; }
 chat-messenger.slide-over { position: fixed !important; }
 .gecx-floating-button-container { transition: transform 0.5s cubic-bezier(0.32, 0.72, 0, 1); }
 body.gecx-chat-no-transition .gecx-floating-button-container { transition: none !important; }
 @media (prefers-reduced-motion: reduce) { .gecx-floating-button-container { transition: none !important; } }
 body.gecx-chat-open .gecx-floating-button-container--center-right, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--center-right,
 body.gecx-chat-open .gecx-floating-button-container--bottom-right, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--bottom-right { display: none !important; }
 @media (min-width: 600px) and (max-width: 839.98px) {
   body.gecx-chat-open .gecx-floating-button-container--bottom-center, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--bottom-center {
     transform: translateX(calc(-50% - var(--gecx-chat-panel-width, 360px) / 2)) !important;
   }
 }
 @media (min-width: 840px) {
   body.gecx-chat-open .gecx-floating-button-container--bottom-center, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container--bottom-center {
     transform: translateX(calc(-50% - var(--gecx-chat-panel-width, clamp(0px, 412px, 50dvw)) / 2)) !important;
   }
 }
 @media (max-width: 599.98px) {
   body.gecx-chat-open .gecx-floating-button-container, body:has(chat-messenger:not(.messenger-hidden)) .gecx-floating-button-container,
   body.gecx-chat-open .gecx-mobile-header-button--floating, body:has(chat-messenger:not(.messenger-hidden)) .gecx-mobile-header-button--floating {
     display: none !important;
   }
 }
`;

const buttonHtml = '<gecx-agent-button display-style="responsive" collapse-to="short-label" preset-icon="button_magic" enable-shimmer="true" hide-chat-bubble short-label="Shop"></gecx-agent-button>';

const pdpPromptsHtml = `
<!-- Start Gemini Enterprise for CX WooCommerce Suggested Prompts -->
<gecx-suggested-prompts chat-widget-selector="gecx-woocommerce-chat-widget" direction="row" show-input enable-shimmer branding="none">
</gecx-suggested-prompts>
<!-- End Gemini Enterprise for CX WooCommerce Suggested Prompts -->
`;

const floatingStyles = {
  bottom_center: 'position: fixed; bottom: calc(24px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); left: 50%; transform: translateX(-50%); z-index: 999999;',
  bottom_left: 'position: fixed; bottom: calc(20px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); left: 20px; z-index: 999999;',
  bottom_right: 'position: fixed; bottom: calc(20px + var(--gecx-floating-offset, 0px) + var(--gecx-floating-extra-offset, 0px) + env(safe-area-inset-bottom, 0px)); right: 20px; z-index: 999999;',
  center_left: 'position: fixed; top: 50%; left: 0; transform: translateY(-50%); z-index: 999999;',
  center_right: 'position: fixed; top: 50%; right: 0; transform: translateY(-50%); z-index: 999999;',
};

module.exports = {
  css: `${themeCss}\n${inlineWidgetCss}`,
  buttonHtml,
  pdpPromptsHtml,
  floatingStyles,
};

