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
    var boundOrderId = 0;

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

    /**
     * 支付前绑定 pending WC 订单（对齐 WC/PayPal/Stripe）。
     */
    function bindPendingOrder() {
        var url = config.noteAjaxUrl;
        if (!url) {
            return Promise.reject(new Error(
                (config.i18n && config.i18n.orderBindFailed) || 'Could not create order'
            ));
        }
        var $form = $('form.checkout');
        return $.ajax({
            url: url,
            type: 'POST',
            dataType: 'json',
            data: {
                security: config.nonce,
                post_data: $form.serialize(),
                payment_method: gatewayId
            }
        }).then(function (response) {
            if (!response || !response.success || !response.data || !response.data.order_id) {
                var msg =
                    (response && response.data && response.data.message) ||
                    (config.i18n && config.i18n.orderBindFailed) ||
                    'Could not create order';
                return $.Deferred().reject(new Error(msg)).promise();
            }
            boundOrderId = Number(response.data.order_id) || 0;
            return response.data;
        });
    }

    function markPaymentFailed(text) {
        var url = config.failAjaxUrl;
        if (!url) {
            return $.Deferred().resolve({}).promise();
        }
        return $.ajax({
            url: url,
            type: 'POST',
            dataType: 'json',
            data: {
                security: config.nonce,
                message: String(text || '').slice(0, 500)
            }
        }).then(
            function (json) {
                return (json && json.success && json.data) || {};
            },
            function () {
                return {};
            }
        );
    }

    var recoveringSession = false;

    /**
     * 解析 payments：收银台过期 / 5009 → 重建 Session（不标 failed）。
     */
    function analyzePaymentResponse(payload) {
        var out = { status: '', orderStatus: '', code: '', message: '', needsNewSession: false };
        if (!payload) {
            return out;
        }
        var root = payload;
        if (typeof root === 'string') {
            try {
                root = JSON.parse(root);
            } catch (e) {
                return out;
            }
        }
        if (typeof root !== 'object') {
            return out;
        }
        if (root.response && typeof root.response === 'object') {
            root = root.response;
        }
        // SDK 回调多为 camelCase；原始 API 多为 snake_case
        var data = root.data && typeof root.data === 'object' ? root.data : root;
        out.status = data.status ? String(data.status).toUpperCase() : '';
        out.orderStatus = String(data.order_status || data.orderStatus || '').toUpperCase();
        var rawCode =
            data.error_code != null
                ? data.error_code
                : data.errorCode != null
                  ? data.errorCode
                  : root.error_code != null
                    ? root.error_code
                    : root.errorCode != null
                      ? root.errorCode
                      : root.code != null
                        ? root.code
                        : '';
        out.code = rawCode !== '' && rawCode != null ? String(rawCode) : '';
        out.message =
            data.error_description ||
            data.errorDescription ||
            data.errorMessage ||
            root.error_description ||
            root.errorDescription ||
            root.errorMessage ||
            data.msg ||
            root.msg ||
            (root.message && String(root.message).charAt(0) !== '{'
                ? String(root.message)
                : '') ||
            '';
        var msgLower = String(out.message || '').toLowerCase();
        // 收银台过期 / 已结束不可重复提交 / 风控 5009：换新 attempt 重建 Session
        if (
            out.status === 'EXPIRED' ||
            out.orderStatus === 'EXPIRED' ||
            out.code === '4018' ||
            out.code === '5009' ||
            msgLower.indexOf('session is finished') !== -1 ||
            msgLower.indexOf('duplicate submission') !== -1
        ) {
            out.needsNewSession = true;
        }
        return out;
    }

    function recoverMessage(analyzed) {
        if (!analyzed || !analyzed.needsNewSession) {
            return '';
        }
        // 优先使用接口返回描述，不自行改写
        var apiMsg = analyzed.message ? String(analyzed.message).trim() : '';
        if (apiMsg) {
            return apiMsg;
        }
        return (config.i18n && config.i18n.error) || '';
    }

    /** EXPIRED / 5009：换新 attempt 重建收银台，不标 failed、不跳转。 */
    function recoverCheckoutIfNeeded(payload) {
        var analyzed = analyzePaymentResponse(payload);
        if (!analyzed.needsNewSession) {
            return false;
        }
        if (recoveringSession) {
            return true;
        }
        recoveringSession = true;
        paying = false;
        cardReady = false;
        var msg = recoverMessage(analyzed);
        message('error', msg);
        destroyCard();
        lastSignature = '';
        // 等旧 SDK 卸干净后再 force_new，避免新 Session 挂载失败/空白
        setTimeout(function () {
            requestSession({ forceNew: true });
        }, 320);
        return true;
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
            var text = (err && err.message) || config.i18n.error;
            message('error', text);
            markPaymentFailed(text);
            return false;
        }
    }

    function createCard(data) {
        if (!data || !data.session_id || !data.client_key) {
            throw new Error('Invalid PayKKa session response');
        }
        destroyCard();
        if (data.order_id) {
            boundOrderId = Number(data.order_id) || boundOrderId;
        }
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
            } : undefined,
            onEvent: function (eventName, payload) {
                if (
                    eventName === 'payment:response' ||
                    eventName === 'afterPayment' ||
                    (payload && payload.action === 'afterPayment')
                ) {
                    recoverCheckoutIfNeeded(
                        payload && payload.response ? payload : payload
                    );
                }
            },
            onSubmitResponse: function (response) {
                recoverCheckoutIfNeeded(response);
            },
            onFailure: function (payload) {
                recoverCheckoutIfNeeded(payload || { errorCode: '5009' });
            },
            onExpired: function () {
                recoverCheckoutIfNeeded({ status: 'EXPIRED' });
            },
            onError: function (error) {
                if (recoverCheckoutIfNeeded(error)) {
                    return;
                }
                paying = false;
                var text =
                    (error && (error.error_description || error.msg || error.message)) ||
                    config.i18n.error;
                message('error', text);
            }
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
                onSubmitResponse: function (response) {
                    recoverCheckoutIfNeeded(response);
                },
                onFailure: function (payload) {
                    recoverCheckoutIfNeeded(payload || { errorCode: '5009' });
                },
                onExpired: function () {
                    recoverCheckoutIfNeeded({ status: 'EXPIRED' });
                },
                onError: function (error) {
                    if (recoverCheckoutIfNeeded(error)) {
                        return;
                    }
                    paying = false;
                    var text =
                        (error && (error.error_description || error.msg || error.message)) ||
                        config.i18n.error;
                    message('error', text);
                }
            });
            cardInstance.mount(container()[0]);
            cardReady = true;
            message('ready', config.i18n.ready);
        });
    }

    function requestSession(opts) {
        if (!selectedCard() || !container().length) {
            destroyCard();
            recoveringSession = false;
            return;
        }

        var forceNew = !!(opts && opts.forceNew);
        // 重建中禁止普通拉 Session 抢跑
        if (!forceNew && recoveringSession) {
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
        if (!forceNew && signature === lastSignature && cardInstance && cardReady) {
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
        var ajaxData = {
            security: config.nonce,
            post_data: serialized,
            payment_method: gatewayId
        };
        if (forceNew) {
            ajaxData.force_new = '1';
        }
        request = $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: ajaxData
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
        }).then(function () {
            if (currentRequest !== requestNumber) {
                return;
            }
            recoveringSession = false;
            if (cardReady && cardInstance && container().length) {
                message('ready', config.i18n.ready);
            }
        }).catch(function (error) {
            // 被更新请求 abort：由新请求负责清 recoveringSession
            if (currentRequest !== requestNumber) {
                return;
            }
            if (error && error.statusText === 'abort') {
                recoveringSession = false;
                return;
            }
            cardInstance = null;
            cardReady = false;
            recoveringSession = false;
            message('error', error && error.message ? error.message : config.i18n.error);
        });
    }

    function scheduleRequest() {
        if (recoveringSession) {
            return;
        }
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

        // 对齐同行：先绑定 WC 订单，再触发 Card 支付；不走 WC 二次 process_payment。
        $(document.body).on('checkout_place_order_' + gatewayId, function () {
            if (paying) {
                return false;
            }
            if (!cardReady || !cardInstance) {
                message('error', config.i18n.notReady);
                return false;
            }

            paying = true;
            message('ready', config.i18n.paying);
            bindPendingOrder()
                .then(function () {
                    if (!triggerPayment()) {
                        paying = false;
                        message('error', config.i18n.notReady);
                    }
                })
                .catch(function (err) {
                    paying = false;
                    message(
                        'error',
                        (err && err.message) ||
                            (config.i18n && config.i18n.orderBindFailed) ||
                            config.i18n.error
                    );
                    if (typeof window.jQuery !== 'undefined') {
                        $(document.body).trigger('checkout_error');
                    }
                });

            return false;
        });

        scheduleRequest();
    });
}(jQuery));
