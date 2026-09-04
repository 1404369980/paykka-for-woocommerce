/**
 * PayKKa Payments on the classic "pay for order" page.
 *
 * The Checkout block refuses to render on the order-pay endpoint, so this page always
 * falls back to templates/checkout/form-pay.php. There is no cart here — the order is
 * already final — so the session is created server side from the order itself and the
 * "Pay for order" button triggers the component instead of submitting the form.
 */
(function ($) {
    'use strict';

    var config = window.PaykkaOrderPayConfig || {};
    var gatewayId = config.gatewayId || 'paykka-card';
    var i18n = config.i18n || {};

    var checkout = null;
    var card = null;
    var wallets = [];
    var request = null;
    var requestSeq = 0;
    var initTimer = null;
    var ready = false;
    var paying = false;
    var retriedFreshSession = false;
    var lastNoteAt = 0;
    var readyMethods = [];

    function $form() {
        return $('#order_review');
    }

    function selectedMethod() {
        return $form().find('input[name="payment_method"]:checked').val();
    }

    function isSelected() {
        return selectedMethod() === gatewayId;
    }

    function $container() {
        return $('#paykka-checkout-card');
    }

    function walletHost(kind) {
        var id = kind === 'apple' ? 'paykka-checkout-apple-pay' : 'paykka-checkout-google-pay';
        return document.getElementById(id);
    }

    /** 无文案时整行隐藏，卡组件之外不留空白。 */
    function setStatus(text) {
        $('.paykka-card-error').text('').prop('hidden', true);
        $('.paykka-card-status').text(text || '').prop('hidden', !text);
    }

    function setError(text) {
        $('.paykka-card-status').text('').prop('hidden', true);
        $('.paykka-card-error').text(text || i18n.error || '').prop('hidden', false);
    }

    /**
     * PayKKa 的错误对象有时把 JSON 串塞进 message，取出可读文案。
     */
    function formatError(err, fallback) {
        if (!err) {
            return fallback;
        }
        if (err.msg) {
            return String(err.msg);
        }
        var text = err.message ? String(err.message) : '';
        if (text.charAt(0) === '{') {
            try {
                var parsed = JSON.parse(text);
                if (parsed && (parsed.msg || parsed.message)) {
                    return String(parsed.msg || parsed.message);
                }
            } catch (ignore) {
                // 保留原文
            }
        }
        return text || fallback;
    }

    function unblockForm() {
        var $f = $form();
        $f.removeClass('processing').css('cursor', '');
        if ($.fn.unblock) {
            $f.unblock();
        }
    }

    /** Apple Pay 只在 Safari/macOS/iOS 且设备可用时才创建，Windows / Linux 上不留空容器。 */
    function isApplePayAvailable() {
        try {
            return !!(
                window.ApplePaySession &&
                typeof window.ApplePaySession.canMakePayments === 'function' &&
                window.ApplePaySession.canMakePayments()
            );
        } catch (e) {
            return false;
        }
    }

    function normalizeMethodCodes(methods) {
        if (!Array.isArray(methods)) {
            return [];
        }
        return methods
            .map(function (m) {
                if (typeof m === 'string') {
                    return m.toUpperCase();
                }
                if (m && typeof m === 'object') {
                    var code = m.code || m.id || m.method || m.payment_method || m.name;
                    return code ? String(code).toUpperCase() : '';
                }
                return '';
            })
            .filter(Boolean);
    }

    function hasPaymentMethod(codes, needle) {
        return codes.indexOf(String(needle).toUpperCase()) !== -1;
    }

    /**
     * AJAX 失败时错误文案优先取服务端返回的 message。
     */
    function ajaxErrorMessage(error) {
        if (error && error.responseJSON && error.responseJSON.data && error.responseJSON.data.message) {
            return String(error.responseJSON.data.message);
        }
        return formatError(error, i18n.error);
    }

    function destroy() {
        ready = false;
        paying = false;

        wallets.concat(card ? [card] : []).forEach(function (instance) {
            if (instance && typeof instance.unmount === 'function') {
                try {
                    instance.unmount();
                } catch (ignore) {
                    // ignore
                }
            }
        });
        if (checkout && typeof checkout.destroy === 'function') {
            try {
                checkout.destroy();
            } catch (ignore) {
                // ignore
            }
        }

        checkout = null;
        card = null;
        wallets = [];
        readyMethods = [];
        $container().empty();
        $('#paykka-checkout-apple-pay, #paykka-checkout-google-pay')
            .empty()
            .removeClass('paykka-has-content');
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
     * 每次发起支付写一条订单备注；失败不阻断支付。
     * 卡路径在按钮点击时写、钱包按钮走 SDK onSubmit，同一次点击可能先后触发，用时间窗去重。
     */
    function notePlaceOrder() {
        if (!config.noteAjaxUrl) {
            return Promise.resolve();
        }
        var now = Date.now();
        if (lastNoteAt && now - lastNoteAt < 3000) {
            return Promise.resolve();
        }
        lastNoteAt = now;
        return Promise.resolve(
            $.ajax({
                url: config.noteAjaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    security: config.nonce,
                    order_id: config.orderId,
                    order_key: config.orderKey
                }
            })
        ).catch(function () {
            // 备注失败不影响扣款
        });
    }

    function createComponents(data) {
        var sdk = window.PayKKaCardCheckoutUI;
        if (!sdk || !sdk.PayKKaCheckout || !sdk.Card) {
            throw new Error('PayKKa Card SDK is unavailable');
        }
        destroy();

        var checkoutBase = data.checkout_base || config.checkoutBase || '';
        var returnUrl = data.return_url;
        var env = String(data.env || '').toLowerCase();
        if (['eu', 'hk', 'sandbox'].indexOf(env) === -1) {
            throw new Error(i18n.error || 'Invalid PayKKa environment');
        }

        checkout = new sdk.PayKKaCheckout({
            sessionId: data.session_id,
            clientKey: data.client_key,
            env: env,
            returnUrl: returnUrl,
            // 卡支付由「Pay for order」按钮触发；钱包按钮由各自组件展示
            hidePaymentButton: true,
            _envConfig: checkoutBase ? {
                api: checkoutBase,
                cdn: checkoutBase.replace(/\/$/, '') + '/cp'
            } : undefined,
            onInitError: function (err) {
                if (!retriedFreshSession) {
                    retriedFreshSession = true;
                    requestSession(true);
                    return;
                }
                setError(formatError(err, i18n.error));
            },
            onSuccess: function (payload) {
                paying = false;
                var target =
                    (payload && payload.returnUrl) ||
                    (typeof payload === 'string' ? payload : '') ||
                    returnUrl;
                window.location.href = target;
            },
            onExpired: function () {
                paying = false;
                if (!retriedFreshSession) {
                    retriedFreshSession = true;
                    requestSession(true);
                    return;
                }
                setError(i18n.error);
            },
            onError: function (err) {
                paying = false;
                unblockForm();
                setError(formatError(err, i18n.error));
            },
            onTimeout: function () {
                paying = false;
                unblockForm();
                setError(i18n.error);
            },
            onPaymentMethodsReady: function (methods) {
                readyMethods = normalizeMethodCodes(methods);
            },
            onSubmit: function () {
                // Apple Pay / Google Pay 按钮直接提交，卡路径已在点击时写过备注
                notePlaceOrder();
            }
        });

        return checkout.init().then(function () {
            // init 是异步的，期间顾客可能已切走支付方式
            if (!isSelected() || !$container().length) {
                destroy();
                return;
            }

            var codes = readyMethods.length
                ? readyMethods
                : ['APPLE_PAY', 'GOOGLE_PAY', 'VISA', 'MASTER_CARD', 'BANKCARD'];

            ['apple', 'google'].forEach(function (kind) {
                var Component = kind === 'apple' ? sdk.ApplePay : sdk.GooglePay;
                var host = walletHost(kind);
                var supported = kind === 'apple'
                    ? hasPaymentMethod(codes, 'APPLE_PAY') && isApplePayAvailable()
                    : hasPaymentMethod(codes, 'GOOGLE_PAY');
                if (!Component || !host || !supported) {
                    return;
                }
                try {
                    var wallet = checkout.create(Component, { hidePaymentButton: false });
                    wallet.mount(host);
                    wallets.push(wallet);
                    // 容器默认隐藏，挂载成功后才显示（见 checkout-card.css）
                    host.classList.add('paykka-has-content');
                } catch (ignore) {
                    // 当前环境不支持该钱包
                }
            });

            card = checkout.create(sdk.Card, {
                showCardBrands: true,
                cardInfoLayout: 'combine',
                showHolderName: false,
                hidePaymentButton: true,
                onSubmit: function (formValidateError) {
                    if (formValidateError) {
                        paying = false;
                        unblockForm();
                        setError(formatError(formValidateError, i18n.needCard));
                    }
                }
            });
            card.mount($container()[0]);

            ready = true;
            retriedFreshSession = false;
            // 就绪后只留卡组件本身，不显示任何状态文案
            setStatus('');
        });
    }

    function requestSession(forceNew) {
        if (!isSelected() || !$container().length) {
            destroy();
            return;
        }

        requestSeq += 1;
        var seq = requestSeq;
        if (request && request.readyState !== 4) {
            request.abort();
        }
        destroy();
        setStatus(i18n.loading);

        request = $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                security: config.nonce,
                order_id: config.orderId,
                order_key: config.orderKey,
                force_new: forceNew ? 1 : 0
            }
        });

        Promise.resolve(request)
            .then(function (response) {
                if (seq !== requestSeq) {
                    return undefined;
                }
                if (!response || !response.success || !response.data) {
                    throw new Error(
                        (response && response.data && response.data.message) || i18n.error
                    );
                }
                return loadSdk().then(function () {
                    if (seq !== requestSeq) {
                        return undefined;
                    }
                    return createComponents(response.data);
                });
            })
            .catch(function (error) {
                if (seq !== requestSeq || (error && error.statusText === 'abort')) {
                    return;
                }
                ready = false;
                setError(ajaxErrorMessage(error));
            });
    }

    function scheduleSession(forceNew) {
        clearTimeout(initTimer);
        // 重新进入该支付方式时允许再自动重试一次新 session
        retriedFreshSession = false;
        // 等 WooCommerce 的 payment_box slideDown（230ms）结束后再挂载
        initTimer = setTimeout(function () {
            requestSession(forceNew);
        }, 300);
    }

    /**
     * 顾客点「Pay for order」：不提交 WC 表单，改由组件扣款，成功后由 returnUrl 回调收单。
     */
    function startPayment() {
        if (paying) {
            return;
        }
        if (!ready || !card || !card.ref || typeof card.ref.payment !== 'function') {
            setError(i18n.notReady);
            return;
        }

        paying = true;
        setStatus(i18n.paying);

        var fire = function () {
            try {
                var maybe = card.ref.payment();
                if (maybe && typeof maybe.catch === 'function') {
                    maybe.catch(function (err) {
                        paying = false;
                        unblockForm();
                        setError(formatError(err, i18n.needCard));
                    });
                }
            } catch (err) {
                paying = false;
                unblockForm();
                setError(formatError(err, i18n.needCard));
            }
        };
        // Promise.prototype.finally 在旧 Safari 上缺失，用双分支代替
        notePlaceOrder().then(fire, fire);
    }

    $(function () {
        if (!$form().length || !config.orderId) {
            return;
        }

        // 捕获阶段拦下点击，这样 WooCommerce 绑定的 submit 处理器不会触发遮罩
        var button = document.getElementById('place_order');
        if (button) {
            button.addEventListener(
                'click',
                function (event) {
                    if (!isSelected()) {
                        return;
                    }
                    event.preventDefault();
                    event.stopPropagation();
                    startPayment();
                },
                true
            );
        }

        // 回车提交等其他路径的兜底
        $form().on('submit', function (event) {
            if (!isSelected()) {
                return;
            }
            event.preventDefault();
            unblockForm();
            startPayment();
        });

        $form().on('change click', 'input[name="payment_method"]', function () {
            if (isSelected()) {
                scheduleSession(false);
            } else {
                destroy();
                setStatus('');
            }
        });

        if (isSelected()) {
            scheduleSession(false);
        }
    });
}(jQuery));
