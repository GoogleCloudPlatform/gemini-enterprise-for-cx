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
 * @fileoverview Editor side of the gecx/agent-button and
 * gecx/suggested-prompts blocks. Both are rendered on the server; the editor
 * shows placeholders because the launcher and prompts are custom elements
 * from the Google-hosted widget bundle, which the editor does not load.
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
const sprintf = (wp.i18n && wp.i18n.sprintf) ? wp.i18n.sprintf : function(format, ...args) {
  let i = 0;
  return format.replace(/%[sd]/g, function() {
    return args[i++];
  });
};
const blockEditor = wp.blockEditor || {};
const useBlockProps = blockEditor.useBlockProps ?
    blockEditor.useBlockProps :
    function(props) {
      return props || {};
    };

/**
 * A dashed placeholder standing in for storefront-only markup.
 * @param {string} label
 * @return {!Object}
 */
function placeholder(label) {
  return el(
      'span',
      useBlockProps({
        style: {
          display: 'inline-flex',
          alignItems: 'center',
          padding: '6px 14px',
          border: '1px dashed currentColor',
          borderRadius: '999px',
          fontSize: '14px'
        }
      }),
      label);
}

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
    return placeholder(__('AI shopping agent', 'gemini-enterprise-for-cx'));
  },
  save: function() {
    return null;
  }
});

wp.blocks.registerBlockType('gecx/suggested-prompts', {
  apiVersion: 2,
  title: __('Gemini Enterprise for CX Suggested Prompts', 'gemini-enterprise-for-cx'),
  description: __(
      'AI-generated questions about a product. In the Single Product template it uses the product being viewed; elsewhere, set a Product ID.',
      'gemini-enterprise-for-cx'),
  category: 'widgets',
  icon: 'format-chat',
  attributes: {productId: {type: 'number', default: 0}},
  usesContext: ['postId'],
  supports: {html: false, multiple: false},
  edit: function(props) {
    const productId = props.attributes.productId || 0;
    const inspector = (blockEditor.InspectorControls && wp.components &&
                       wp.components.PanelBody && wp.components.TextControl) ?
        el(blockEditor.InspectorControls, null,
           el(wp.components.PanelBody,
              {title: __('Product', 'gemini-enterprise-for-cx')},
              el(wp.components.TextControl, {
                label: __('Product ID (optional)', 'gemini-enterprise-for-cx'),
                help: __(
                    'Leave empty to use the product being viewed.',
                    'gemini-enterprise-for-cx'),
                type: 'number',
                value: productId > 0 ? String(productId) : '',
                onChange: function(value) {
                  const id = parseInt(value, 10);
                  props.setAttributes({productId: id > 0 ? id : 0});
                }
              }))) :
        null;
    const label = productId > 0 ?
        sprintf(
            /* translators: %d: Product ID. */
            __('Suggested prompts for product #%d', 'gemini-enterprise-for-cx'),
            productId
        ) :
        __('Suggested prompts', 'gemini-enterprise-for-cx');
    return el(wp.element.Fragment, null, inspector, placeholder(label));
  },
  save: function() {
    return null;
  }
});
})(window.wp);
