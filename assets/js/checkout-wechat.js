(function ($) {
    'use strict';

    var cfg = window.PaykkaWechatCheckout || {};
    var popup = null;
    var pollTimer = null;
    var overlay = null;
    var started = false;

    function i18n(key, fallback) {
        return (cfg.i18n && cfg.i18n[key]) || fallback || '';
    }

    function ensureOverlay() {
        if (overlay && overlay.length) {
            return overlay;
        }
        overlay = $('#paykka-wechat-overlay');
        if (!overlay.length) {
            overlay = $(
                '<div id="paykka-wechat-overlay" class="paykka-wechat-overlay" hidden>' +
                    '<div class="paykka-wechat-overlay__panel">' +
                    '<p class="paykka-wechat-overlay__text"></p>' +
                    '<button type="button" class="button alt paykka-wechat-overlay__reopen"></button>' +
                    '</div>' +
                    '</div>'
            );
            $('body').append(overlay);
            overlay.find('.paykka-wechat-overlay__reopen').on('click', function () {
                if (cfg.popupUrl) {
                    openPopup(cfg.popupUrl);
                }
            });
        }
        overlay.find('.paykka-wechat-overlay__reopen').text(i18n('reopen', 'Reopen WeChat Pay'));
        return overlay;
    }

    function showOverlay(message) {
        var $el = ensureOverlay();
        $el.find('.paykka-wechat-overlay__text').text(message || i18n('waiting'));
        $el.prop('hidden', false);
    }

    function hideOverlay() {
        if (overlay) {
            overlay.prop('hidden', true);
        }
    }

    function openPopup(url) {
        var w = 480;
        var h = 720;
        var left = Math.max(0, (window.screen.width - w) / 2);
        var top = Math.max(0, (window.screen.height - h) / 2);
        var features =
            'popup=yes,width=' +
            w +
            ',height=' +
            h +
            ',left=' +
            left +
            ',top=' +
            top +
            ',scrollbars=yes,resizable=yes';

        if (popup && !popup.closed) {
            try {
                popup.location = url;
                popup.focus();
                return popup;
            } catch (e) {
                /* reopen below */
            }
        }

        popup = window.open(url, 'paykka_wechat_pay', features);
        if (!popup || popup.closed || typeof popup.closed === 'undefined') {
            showOverlay(i18n('popupBlocked'));
            return null;
        }
        showOverlay(i18n('waiting'));
        return popup;
    }

    function stopPoll() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function unblockCheckout() {
        var $form = $('form.checkout');
        if ($form.length) {
            $form.removeClass('processing').unblock();
        }
        $(document.body).css('cursor', 'default').unblock();
        $('.blockUI.blockOverlay').remove();
    }

    function redirectThankYou(url) {
        stopPoll();
        hideOverlay();
        started = false;
        if (popup && !popup.closed) {
            try {
                popup.close();
            } catch (e) {
                /* ignore */
            }
        }
        window.location = url || cfg.returnUrl || '/';
    }

    function pollStatus() {
        if (!cfg.statusUrl) {
            return;
        }
        $.ajax({
            url: cfg.statusUrl,
            method: 'GET',
            dataType: 'json',
            cache: false,
        })
            .done(function (res) {
                if (res && res.success && res.data && res.data.paid) {
                    redirectThankYou(res.data.return_url || cfg.returnUrl);
                }
            })
            .fail(function () {
                /* keep polling */
            });
    }

    function startWaiting(payload) {
        if (!payload || !payload.popupUrl) {
            return;
        }
        cfg.popupUrl = payload.popupUrl;
        cfg.returnUrl = payload.returnUrl || cfg.returnUrl;
        cfg.statusUrl = payload.statusUrl || cfg.statusUrl;
        cfg.orderId = payload.orderId || cfg.orderId;
        cfg.orderKey = payload.orderKey || cfg.orderKey;

        try {
            sessionStorage.setItem(
                'paykka_wechat_pending',
                JSON.stringify({
                    popupUrl: cfg.popupUrl,
                    returnUrl: cfg.returnUrl,
                    statusUrl: cfg.statusUrl,
                    orderId: cfg.orderId,
                    orderKey: cfg.orderKey,
                })
            );
        } catch (e) {
            /* ignore */
        }

        started = true;
        openPopup(cfg.popupUrl);
        stopPoll();
        pollTimer = setInterval(function () {
            if (popup && popup.closed) {
                showOverlay(i18n('closed'));
            }
            pollStatus();
        }, 2500);
        unblockCheckout();
    }

    function detailsToMap(details) {
        var map = {};
        if (!details) {
            return map;
        }
        if (Array.isArray(details)) {
            details.forEach(function (row) {
                if (row && row.key) {
                    map[row.key] = row.value;
                }
            });
            return map;
        }
        if (typeof details === 'object') {
            return details;
        }
        return map;
    }

    function startFromGatewayResult(result) {
        if (!result) {
            return false;
        }
        var map = detailsToMap(result.payment_details || result.paymentDetails);
        var popupUrl =
            result.paykka_wechat_popup ||
            map.paykka_wechat_popup ||
            (map.redirect && String(map.redirect).indexOf('http') === 0 ? null : null);
        if (!popupUrl && map.paykka_wechat_popup) {
            popupUrl = map.paykka_wechat_popup;
        }
        if (!popupUrl) {
            return false;
        }
        startWaiting({
            popupUrl: popupUrl,
            returnUrl: result.paykka_return_url || map.paykka_return_url || cfg.returnUrl,
            statusUrl: result.paykka_status_url || map.paykka_status_url || cfg.statusUrl,
            orderId: result.paykka_order_id || map.paykka_order_id || cfg.orderId,
            orderKey: result.paykka_order_key || map.paykka_order_key || cfg.orderKey,
        });
        return true;
    }

    /**
     * Classic checkout: stay on this page (hash redirect only) and open popup.
     */
    $(document.body).on('checkout_place_order_success', function (event, result) {
        if (!result || !result.paykka_wechat_popup) {
            return;
        }
        startFromGatewayResult(result);
        // Keep checkout page; WC will assign location to this hash (no full navigation).
        result.redirect = '#paykka-wechat-pay';
        setTimeout(unblockCheckout, 0);
    });

    /**
     * Blocks checkout: onCheckoutSuccess receives payment details from process_payment.
     */
    function bindBlocksSuccess() {
        var api = window.wc && window.wc.blocksCheckoutEvents;
        if (!api || !api.checkoutEvents || typeof api.checkoutEvents.onCheckoutSuccess !== 'function') {
            return;
        }
        api.checkoutEvents.onCheckoutSuccess(function (data) {
            var response = (data && (data.processingResponse || data)) || {};
            var details = response.paymentDetails || response.payment_details || {};
            var map = detailsToMap(details);
            // Legacy merges full gateway result into payment_details.
            if (!map.paykka_wechat_popup && response.paykka_wechat_popup) {
                map.paykka_wechat_popup = response.paykka_wechat_popup;
            }
            if (!map.paykka_wechat_popup) {
                return true;
            }
            startWaiting({
                popupUrl: map.paykka_wechat_popup,
                returnUrl: map.paykka_return_url,
                statusUrl: map.paykka_status_url,
                orderId: map.paykka_order_id,
                orderKey: map.paykka_order_key,
            });
            // Returning a response that clears redirect is ideal; hash redirect is already set server-side.
            return true;
        });
    }

    $(function () {
        bindBlocksSuccess();

        $('#paykka-wechat-reopen').on('click', function (e) {
            e.preventDefault();
            if (cfg.popupUrl) {
                openPopup(cfg.popupUrl);
            }
        });

        // Resume if hash already set / receipt fallback.
        if (cfg.autoStart && cfg.popupUrl) {
            startWaiting({
                popupUrl: cfg.popupUrl,
                returnUrl: cfg.returnUrl,
                statusUrl: cfg.statusUrl,
                orderId: cfg.orderId,
                orderKey: cfg.orderKey,
            });
        } else if (window.location.hash === '#paykka-wechat-pay' && !started) {
            try {
                var saved = JSON.parse(sessionStorage.getItem('paykka_wechat_pending') || 'null');
                if (saved && saved.popupUrl) {
                    startWaiting(saved);
                }
            } catch (e) {
                /* ignore */
            }
        }

        window.addEventListener('message', function (event) {
            if (!event || !event.data || event.data.source !== 'paykka-wechat') {
                return;
            }
            if (event.data.type === 'paid' && event.data.returnUrl) {
                try {
                    sessionStorage.removeItem('paykka_wechat_pending');
                } catch (e) {
                    /* ignore */
                }
                redirectThankYou(event.data.returnUrl);
            }
        });
    });
})(jQuery);
