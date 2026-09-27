/**
 * Copyright 2026 Google LLC
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Stands in for the Google-hosted widget bundle so tests make no requests to
 * Google and the launcher has a size to measure. Served in place of
 * https://www.gstatic.com/gecx/chat-widget/woocommerce-chat-widget.js.
 */
(function() {
  function define(name, html) {
    if (customElements.get(name)) {
      return;
    }
    customElements.define(name, class extends HTMLElement {
      connectedCallback() {
        if (!this.shadowRoot && html) {
          this.attachShadow({ mode: 'open' }).innerHTML = html;
        }
      }
    });
  }
  define('gecx-agent-button', '<button type="button" style="padding:6px 12px;font:14px sans-serif">Ask AI</button>');
  define('gecx-suggested-prompts', '<div style="padding:8px;font:14px sans-serif">Suggested prompts</div>');
  define('gecx-woocommerce-chat-widget', '');
})();
