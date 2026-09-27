/**
 * Copyright 2026 Google LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * @fileoverview Editor side of the gecx/agent-button block. The block is
 * rendered on the server; the editor shows a placeholder because the launcher
 * custom element comes from the Google-hosted widget bundle, which the editor
 * does not load.
 */
(function(wp) {
'use strict';

if (!wp || !wp.blocks || !wp.element) {
  return;
}

const el = wp.element.createElement;
const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function(text) {
  return text;
};
const useBlockProps = (wp.blockEditor && wp.blockEditor.useBlockProps) ?
    wp.blockEditor.useBlockProps :
    function(props) {
      return props || {};
    };

wp.blocks.registerBlockType('gecx/agent-button', {
  apiVersion: 2,
  title: __('Gemini Enterprise for CX Launcher', 'gemini-enterprise-for-cx'),
  description: __(
      'The AI shopping agent launcher button. Pair it with the "Manual" launcher placement.',
      'gemini-enterprise-for-cx'),
  category: 'widgets',
  icon: 'format-chat',
  supports: {html: false, multiple: false},
  edit: function() {
    return el(
        'span',
        useBlockProps({
          className: 'gecx-agent-button-placeholder',
          style: {
            display: 'inline-flex',
            alignItems: 'center',
            padding: '6px 14px',
            border: '1px dashed currentColor',
            borderRadius: '999px',
            fontSize: '14px'
          }
        }),
        __('AI shopping agent', 'gemini-enterprise-for-cx'));
  },
  save: function() {
    return null;
  }
});
})(window.wp);
