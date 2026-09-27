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
  if (typeof gecx_admin_params === 'undefined') {
    return;
  }
  const saveNonce = gecx_admin_params.save_nonce;

  // Toggle Storefront Chat Widget
  $(document).on('change', '#gecx_agent_enabled', function() {
    const $checkbox = $(this);
    const isChecked = $checkbox.is(':checked');
    const isEnabled = isChecked ? '1' : '0';
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

    $.post(ajaxurl, {
       action: 'gecx_toggle_app_embed',
       enabled: isEnabled,
       nonce: saveNonce
     }).done(function(res) {
       if (!res || !res.success) {
         revertAgentToggle();
       }
     }).fail(function(err) {
       console.error('Error toggling GECX embed:', err);
       revertAgentToggle();
     });
  });

  // Toggle PDP Suggested Prompts
  $(document).on('change', '#gecx_pdp_prompts_enabled', function() {
    const $checkbox = $(this);
    const isChecked = $checkbox.is(':checked');
    const isEnabled = isChecked ? '1' : '0';

    function revertPdpToggle() {
      $checkbox.prop('checked', !isChecked);
      showNotice('error', gecx_admin_params.errorTogglePrompts);
    }

    $.post(ajaxurl, {
       action: 'gecx_toggle_pdp_prompts',
       enabled: isEnabled,
       nonce: saveNonce
     }).done(function(res) {
       if (!res || !res.success) {
         revertPdpToggle();
       }
     }).fail(function(err) {
       console.error('Error saving PDP prompts toggle state:', err);
       revertPdpToggle();
     });
  });

  // Toggle the placement-specific rows on placement change
  $(document).on('change', 'input[name="gecx_button_placement"]', function() {
    $('#gecx_floating_position_row').toggle($(this).val() === 'floating');
    $('#gecx_nav_menu_target_row').toggle($(this).val() === 'nav_menu');
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
        $('#gecx_button_enable_shimmer').is(':checked') ? '1' : '0';
    const navMenuTarget = $('#gecx_nav_menu_target').val() || '';
    const matchThemeStyles =
        $('#gecx_match_theme_styles').is(':checked') ? '1' : '0';

    const $savedIndicator = $('#gecx-button-config-saved');
    $.post(ajaxurl, {
       action: 'gecx_save_button_config',
       placement: placement,
       floating_position: floatingPos,
       display_style: displayStyle,
       label: label,
       short_label: shortLabel,
       enable_shimmer: enableShimmer,
       nav_menu_target: navMenuTarget,
       match_theme_styles: matchThemeStyles,
       nonce: saveNonce
     }).done(function() {
      if ($savedIndicator.length) {
        $savedIndicator.stop(true, true).fadeIn(200).delay(1500).fadeOut(400);
      }
    }).fail(function(err) {
      console.error('Error saving launcher button config:', err);
    });
  }

  $(document).on('input', '#gecx_button_label, #gecx_button_short_label', function() {
    clearTimeout(buttonConfigDebounceTimer);
    buttonConfigDebounceTimer = setTimeout(saveButtonConfig, 400);
  });

  $(document).on(
      'change',
      'input[name="gecx_button_placement"], #gecx_button_floating_position, #gecx_nav_menu_target, #gecx_button_display_style, #gecx_button_label, #gecx_button_short_label, #gecx_button_enable_shimmer, #gecx_match_theme_styles',
      function() {
        clearTimeout(buttonConfigDebounceTimer);
        saveButtonConfig();
      });
  function showNotice(type, message) {
    const $container = $('#gecx-admin-notices');
    const noticeHtml =
        '<div class="notice notice-' + (type || 'error') +
        ' is-dismissible" style="margin-top:15px;"><p>' +
        $('<div>').text(message).html() + '</p></div>';
    if ($container.length) {
      $container.html(noticeHtml);
    } else {
      $('.wrap > h1').after(noticeHtml);
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
    $.post(ajaxurl, {action: 'gecx_unlink_agent', nonce: saveNonce})
        .done(function(res) {
          if (res && res.success) {
            window.location.href =
                window.location.pathname + '?page=gemini-enterprise-for-cx';
          } else {
            const errorMsg = (res && res.data && res.data.message) ?
                res.data.message :
                gecx_admin_params.errorDisconnect;
            showNotice('error', errorMsg);
            $btn.prop('disabled', false).text(gecx_admin_params.disconnectLabel);
          }
        })
        .fail(function(jqXHR) {
          const errorMsg = (jqXHR && jqXHR.responseJSON &&
                            jqXHR.responseJSON.data &&
                            jqXHR.responseJSON.data.message) ?
              jqXHR.responseJSON.data.message :
              gecx_admin_params.errorDisconnectAjax;
          showNotice('error', errorMsg);
          $btn.prop('disabled', false).text(gecx_admin_params.disconnectLabel);
        });
  });

  // Dismiss activation notice
  $(document).on(
      'click', '.gecx-activation-notice .notice-dismiss', function() {
        $.post(ajaxurl, {
           action: 'gecx_dismiss_notice',
           nonce: gecx_admin_params.dismiss_nonce
         }).fail(function(jqXHR, textStatus, errorThrown) {
          console.error('Error dismissing GECX notice:', errorThrown);
        });
      });
});
})(jQuery);
