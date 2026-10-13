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
 * @fileoverview Gemini Enterprise for CX Admin JS
 * @suppress {undefinedVars, missingProperties}
 */
/**
 * @param {!Function} $
 */
(function($) {
'use strict';

// 1. Native UI Handlers
$(function() {
  if (typeof gecx_admin_params === 'undefined' || !window.wp || !window.wp.apiFetch) {
    return;
  }

  // Toggle Storefront Chat Widget
  $(document).on('change', '#gecx_agent_enabled', function() {
    const $checkbox = $(this);
    const isChecked = $checkbox.is(':checked');
    $('#gecx_pdp_prompts_enabled').prop('disabled', !isChecked);
    if (typeof gecx_admin_params !== 'undefined') {
      $('#gecx-status-indicator').css('background-color', isChecked ? '#46b450' : '#787c82');
      $('#gecx-status-text').text(isChecked ? gecx_admin_params.statusActive : gecx_admin_params.statusInactive);
    }

    function revertAgentToggle() {
      const revertedState = !isChecked;
      $checkbox.prop('checked', revertedState);
      $('#gecx_pdp_prompts_enabled').prop('disabled', !revertedState);
      if (typeof gecx_admin_params !== 'undefined') {
        $('#gecx-status-indicator').css('background-color', revertedState ? '#46b450' : '#787c82');
        $('#gecx-status-text').text(revertedState ? gecx_admin_params.statusActive : gecx_admin_params.statusInactive);
      }
      showNotice('error', gecx_admin_params.errorToggleWidget);
    }

    wp.apiFetch({
      path: '/wp/v2/settings',
      method: 'POST',
      data: {
        gecx_agent_enabled: isChecked ? 1 : 0,
      },
    }).catch(function(err) {
      console.error('Error toggling GECX embed:', err);
      revertAgentToggle();
    });
  });

  // Toggle PDP Suggested Prompts
  $(document).on('change', '#gecx_pdp_prompts_enabled', function() {
    const $checkbox = $(this);
    const isChecked = $checkbox.is(':checked');

    function revertPdpToggle() {
      $checkbox.prop('checked', !isChecked);
      showNotice('error', gecx_admin_params.errorTogglePrompts);
    }

    wp.apiFetch({
      path: '/wp/v2/settings',
      method: 'POST',
      data: {
        gecx_pdp_prompts_enabled: isChecked ? 1 : 0,
      },
    }).catch(function(err) {
      console.error('Error saving PDP prompts toggle state:', err);
      revertPdpToggle();
    });
  });

  // Toggle the placement-specific rows on placement change
  $(document).on('change', 'input[name="gecx_button_placement"]', function() {
    $('#gecx_floating_position_row').toggle($(this).val() === 'floating');
    $('#gecx_nav_menu_target_row').toggle($(this).val() === 'nav_menu');
    $('#gecx-manual-placement-tip').toggle($(this).val() === 'manual');
  });

  // Let keyboard users dismiss an info tooltip with Escape.
  $(document).on('keydown', '.gecx-info-tip', function(e) {
    if (e.key === 'Escape') {
      $(this).trigger('blur');
    }
  });

  // Save Launcher Placement & Appearance settings
  let buttonConfigDebounceTimer = null;

  function saveButtonConfig() {
    const placement =
        $('input[name="gecx_button_placement"]:checked').val() || 'nav_menu';
    const floatingPos =
        $('#gecx_button_floating_position').val() || 'bottom_center';
    const displayStyle =
        $('#gecx_button_display_style').val() || 'responsive';
    const label = $('#gecx_button_label').val() || '';
    const shortLabel = $('#gecx_button_short_label').val() || '';
    const enableShimmer =
        $('#gecx_button_enable_shimmer').is(':checked') ? 1 : 0;
    const navMenuTarget = $('#gecx_nav_menu_target').val() || '';

    const $savedIndicator = $('#gecx-button-config-saved');
    wp.apiFetch({
      path: '/wp/v2/settings',
      method: 'POST',
      data: {
        gecx_button_placement: placement,
        gecx_floating_position: floatingPos,
        gecx_button_display_style: displayStyle,
        gecx_button_label: label,
        gecx_button_short_label: shortLabel,
        gecx_button_enable_shimmer: enableShimmer,
        gecx_nav_menu_target: navMenuTarget,
      },
    }).then(function() {
      if ($savedIndicator.length) {
        $savedIndicator.stop(true, true).fadeIn(200).delay(1500).fadeOut(400);
      }
    }).catch(function(err) {
      console.error('Error saving launcher button config:', err);
    });
  }

  $(document).on('input', '#gecx_button_label, #gecx_button_short_label', function() {
    clearTimeout(buttonConfigDebounceTimer);
    buttonConfigDebounceTimer = setTimeout(saveButtonConfig, 400);
  });

  $(document).on(
      'change',
      'input[name="gecx_button_placement"], #gecx_button_floating_position, #gecx_nav_menu_target, #gecx_button_display_style, #gecx_button_label, #gecx_button_short_label, #gecx_button_enable_shimmer',
      function() {
        clearTimeout(buttonConfigDebounceTimer);
        saveButtonConfig();
      });
  function showNotice(type, message) {
    const $container = $('#gecx-admin-notices');
    const noticeEl = document.createElement('div');
    noticeEl.className = 'notice notice-' + (type || 'error') + ' is-dismissible';
    noticeEl.style.marginTop = '15px';
    const pEl = document.createElement('p');
    pEl.textContent = message;
    noticeEl.appendChild(pEl);
    if ($container.length) {
      $container[0].replaceChildren(noticeEl);
    } else {
      $('.wrap > h1').after(noticeEl);
    }
  }
  // Disconnect Agent Button
  $(document).on('click', '#gecx-unlink-agent-btn', function(e) {
    e.preventDefault();
    if (!confirm(gecx_admin_params.confirmDisconnect)) {
      return;
    }
    const $btn = $(this);
    $btn.prop('disabled', true).text(gecx_admin_params.disconnecting);
    wp.apiFetch({
      path: '/gecx/v1/unlink-agent',
      method: 'POST',
    })
        .then(function(res) {
          if (res && res.success) {
            window.location.href =
                window.location.pathname + '?page=gemini-enterprise-for-cx';
          } else {
            const errorMsg = (res && res.message) ?
                res.message :
                gecx_admin_params.errorDisconnect;
            showNotice('error', errorMsg);
            $btn.prop('disabled', false).text(gecx_admin_params.disconnectLabel);
          }
        })
        .catch(function(err) {
          const errorMsg = (err && err.message) ?
              err.message :
              gecx_admin_params.errorDisconnectAjax;
          showNotice('error', errorMsg);
          $btn.prop('disabled', false).text(gecx_admin_params.disconnectLabel);
        });
  });

  // Dismiss activation notice
  $(document).on(
      'click', '.gecx-activation-notice .notice-dismiss', function() {
        wp.apiFetch({
          path: '/wp/v2/settings',
          method: 'POST',
          data: {
            gecx_dismiss_activation_notice: true,
          },
        }).catch(function(err) {
          console.error('Error dismissing GECX notice:', err);
        });
      });
});
})(jQuery);
