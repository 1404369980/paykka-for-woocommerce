(function ($) {
    'use strict';

    var config = window.PaykkaCardCheckoutConfig || {};
    var gatewayId = config.gatewayId || 'paykka-card';
    var checkout = null;
    var cardInstance = null;
    var request = null;
    var requestNumber = 0;
    var initTimer = null;
    var lastSignature = '';
    var cardReady = false;
    var paying = false;

    function selectedCard() {
        return $('form.checkout input[name="payment_method"]:checked').val() === gatewayId;
    }

    function container() {
        return $('#paykka-checkout-card');
    }

    function message(type, text) {
        var $box = $('.paykka-card-status');
        var $error = $('.paykka-card-error');
        if (type === 'error') {
            $error.text(text || config.i18n.error).prop('hidden', false);
            $box.text('');
        } else {
            $error.text('').prop('hidden', true);
            $box.text(text || '');
        }
    }

    function destroyCard() {
        cardReady = false;
        if (cardInstance && typeof cardInstance.unmount === 'function') {
            try {
                cardInstance.unmount();
            } catch (ignore) {
                // ignore
            }
        }
        if (checkout && typeof checkout.destroy === 'function') {
            try {
                checkout.destroy();
            } catch (ignore) {
                // ignore
            }
        }
        checkout = null;
        cardInstance = null;
        container().empty();
    }

    function loadSdk() {
        if (window.PayKKaCardCheckoutUI) {
            return Promise.resolve();
        }
        if (!config.sdkUrl) {
            return Promise.reject(new Error('Missing PayKKa SDK URL'));
        }
        return new Promise(function (resolve, reject) {
            var script = document.querySelector('script[src="' + config.sdkUrl + '"]');
            if (!script) {
                script = document.createElement('script');
                script.src = config.sdkUrl;
                script.async = true;
                document.head.appendChild(script);
            }
            script.addEventListener('load', resolve, { once: true });
            script.addEventListener('error', reject, { once: true });
        });
    }

    function triggerPayment() {
        if (!cardInstance || !cardInstance.ref || typeof cardInstance.ref.payment !== 'function') {
            return false;
        }
        paying = true;
        message('ready', config.i18n.paying);
        try {
            cardInstance.ref.payment();
            return true;
        } catch (err) {
            paying = false;
            message('error', (err && err.message) || config.i18n.error);
            return false;
        }
    }

    function createCard(data) {
        if (!data || !data.session_id || !data.client_key) {
            throw new Error('Invalid PayKKa session response');
        }
        destroyCard();
        var sdk = window.PayKKaCardCheckoutUI;
        if (!sdk || !sdk.PayKKaCheckout || !sdk.Card) {
            throw new Error('PayKKa Card SDK is unavailable');
        }

        var checkoutBase = data.checkout_base || config.checkoutBase || '';
        checkout = new sdk.PayKKaCheckout({
            sessionId: data.session_id,
            clientKey: data.client_key,
            env: data.env,
            returnUrl: data.return_url,
            hidePaymentButton: true,
            _envConfig: checkoutBase ? {
                api: checkoutBase,
                cdn: checkoutBase.replace(/\/$/, '') + '/cp'
            } : undefined
        });

        return checkout.init().then(function () {
            if (!selectedCard() || !container().length) {
                return;
            }
            cardInstance = checkout.create(sdk.Card, {
                showCardBrands: true,
                cardInfoLayout: 'combine',
                showHolderName: false,
                hidePaymentButton: true,
                onSuccess: function (returnUrl) {
                    paying = false;
                    if (typeof window.jQuery !== 'undefined') {
                        $(document.body).trigger('checkout_error');
                    }
                    window.location.href = returnUrl || data.return_url;
                },
                onExpired: function () {
                    paying = false;
                    cardReady = false;
                    message('error', config.i18n.error);
                },
                onError: function (error) {
                    paying = false;
                    var text = config.i18n.error;
                    if (error) {
                        if (error.msg) {
                            text = String(error.msg);
                        } else if (error.message) {
                            text = String(error.message);
                            if (text.charAt(0) === '{') {
                                try {
                                    var parsed = JSON.parse(text);
                                    if (parsed && (parsed.msg || parsed.message)) {
                                        text = String(parsed.msg || parsed.message);
                                    }
                                } catch (ignore) {
                                    // keep text
                                }
                            }
                        }
                    }
                    message('error', text);
                    if (typeof window.jQuery !== 'undefined') {
                        $(document.body).trigger('checkout_error');
                    }
                }
            });
            cardInstance.mount(container()[0]);
            cardReady = true;
            message('ready', config.i18n.ready);
        });
    }

    function requestSession() {
        if (!selectedCard() || !container().length) {
            destroyCard();
            return;
        }

        var $form = $('form.checkout');
        var serialized = $form.serialize();
        var signature = serialized;
        var fields = {};
        $.each(serialized.split('&'), function (_, part) {
            var pair = part.split('=');
            if (pair[0]) {
                fields[decodeURIComponent(pair[0].replace(/\+/g, ' '))] = pair[1] || '';
            }
        });
        if (!fields.billing_email || !fields.billing_country) {
            message('ready', config.i18n.needBilling);
            return;
        }
        if (signature === lastSignature && cardInstance && cardReady) {
            return;
        }
        lastSignature = signature;
        requestNumber += 1;
        var currentRequest = requestNumber;
        if (request && request.readyState !== 4) {
            request.abort();
        }
        destroyCard();
        message('ready', config.i18n.loading);
        request = $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                security: config.nonce,
                post_data: serialized,
                payment_method: gatewayId
            }
        }).then(function (response) {
            if (currentRequest !== requestNumber) {
                return;
            }
            if (!response || !response.success || !response.data) {
                throw new Error(response && response.data && response.data.message ? response.data.message : config.i18n.error);
            }
            return loadSdk().then(function () {
                return createCard(response.data);
            });
        }).catch(function (error) {
            if (currentRequest !== requestNumber || (error && error.statusText === 'abort')) {
                return;
            }
            cardInstance = null;
            cardReady = false;
            message('error', error && error.message ? error.message : config.i18n.error);
        });
    }

    function scheduleRequest() {
        clearTimeout(initTimer);
        initTimer = setTimeout(requestSession, 200);
    }

    $(function () {
        $(document.body).on('payment_method_selected updated_checkout', scheduleRequest);
        $(document.body).on('change', 'form.checkout input, form.checkout select, form.checkout textarea', function () {
            if (selectedCard()) {
                scheduleRequest();
            } else {
                destroyCard();
            }
        });

        // Airwallex 风格：点击「下单」触发 Card 支付，不走 WC 再下一单。
        $(document.body).on('checkout_place_order_' + gatewayId, function () {
            if (paying) {
                return false;
            }
            if (!cardReady || !cardInstance) {
                message('error', config.i18n.notReady);
                return false;
            }
            if (!triggerPayment()) {
                message('error', config.i18n.notReady);
                return false;
            }
            return false;
        });

        scheduleRequest();
    });
}(jQuery));
