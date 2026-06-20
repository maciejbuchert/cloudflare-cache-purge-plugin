/* global cfPurge, jQuery */
(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // Repeater reguł
    // -------------------------------------------------------------------------

    var $wrapper = $('#cf-purge-rules-wrapper');
    var template = $('#cf-purge-rule-template').html();
    var ruleIndex = $wrapper.find('.cf-purge-rule').length;

    /**
     * Aktualizuje placeholder textarea zależnie od wybranego trybu purge.
     *
     * @param {jQuery} $select Element select trybu.
     */
    function updatePlaceholder($select) {
        var mode = $select.val();
        var $textarea = $select.closest('.cf-purge-rule').find('.cf-purge-values');
        var placeholder = (cfPurge.i18n.placeholders || {})[mode] || '';
        $textarea.attr('placeholder', placeholder);
    }

    // Inicjalizacja placeholderów dla istniejących reguł.
    $wrapper.find('.cf-purge-mode').each(function () {
        updatePlaceholder($(this));
    });

    // Aktualizacja przy zmianie trybu.
    $wrapper.on('change', '.cf-purge-mode', function () {
        updatePlaceholder($(this));
    });

    // Dodawanie nowej reguły.
    $('#cf-purge-add-rule').on('click', function () {
        if (!template) {
            return;
        }
        var html = template.replace(/__INDEX__/g, ruleIndex);
        $wrapper.append(html);
        var $newRule = $wrapper.find('.cf-purge-rule').last();
        $newRule.find('.cf-purge-mode').trigger('change');
        ruleIndex++;
    });

    // Usuwanie reguły.
    $wrapper.on('click', '.cf-purge-remove-rule', function () {
        $(this).closest('.cf-purge-rule').remove();
    });

    // -------------------------------------------------------------------------
    // Testowanie połączenia z Cloudflare
    // -------------------------------------------------------------------------

    $('#cf-purge-test-connection').on('click', function () {
        var $button = $(this);
        var $result = $('#cf-purge-connection-result');
        var apiToken = $('#cf_purge_api_token').val();
        var zoneId   = $('#cf_purge_zone_id').val();

        $result.text(cfPurge.i18n.testing).css('color', '#666');
        $button.prop('disabled', true);

        $.post(cfPurge.ajaxUrl, {
            action:    'cf_purge_test_connection',
            nonce:     cfPurge.nonce_test,
            api_token: apiToken,
            zone_id:   zoneId
        })
        .done(function (response) {
            if (response.success) {
                var data    = response.data;
                var message = cfPurge.i18n.connected + ' ' + data.name + ' (' + data.plan + ')';
                $result.text(message).css('color', 'green');

                if (data.notice) {
                    $result.append('<br><span style="color:#b45309;">' + data.notice + '</span>');
                }
            } else {
                var errMsg = (response.data && response.data.message) ? response.data.message : 'Unknown error';
                $result.text(cfPurge.i18n.error + ' ' + errMsg).css('color', '#a00');
            }
        })
        .fail(function () {
            $result.text(cfPurge.i18n.error + ' ' + 'Request failed').css('color', '#a00');
        })
        .always(function () {
            $button.prop('disabled', false);
        });
    });

    // -------------------------------------------------------------------------
    // Czyszczenie historii
    // -------------------------------------------------------------------------

    $('#cf-purge-clear-log').on('click', function () {
        if (!window.confirm(cfPurge.i18n.confirmClearLog)) {
            return;
        }

        var $button = $(this);
        $button.prop('disabled', true);

        $.post(cfPurge.ajaxUrl, {
            action: 'cf_purge_clear_log',
            nonce:  cfPurge.nonce_clear_log
        })
        .done(function (response) {
            if (response.success) {
                alert(cfPurge.i18n.logCleared);
                location.reload();
            }
        })
        .fail(function () {
            $button.prop('disabled', false);
        });
    });

}(jQuery));
