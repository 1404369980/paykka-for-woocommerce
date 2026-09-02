(() => {
    'use strict';

    const { createElement, useEffect, useRef, useState, useCallback } = window.wp.element;
    const { __ } = window.wp.i18n;
    const { decodeEntities } = window.wp.htmlEntities;
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting, getPaymentMethodData } = window.wc.wcSettings;

    const settings =
        (typeof getPaymentMethodData === 'function' && getPaymentMethodData('paykka-card', null)) ||
        getSetting('paykka-card_data', {}) ||
        {};

    const gatewayId = settings.gatewayId || 'paykka-card';
    const i18n = settings.i18n || {};
    const labelText = decodeEntities(settings.title || '') || __('Payments', 'paykka-for-woocommerce');

    /**
     * 跨支付方式切换时 Blocks 会卸载 Content；用模块级缓存保留已 init 的组件，
     * 切回 Payments 时只 remount，不重新 create session。
     */
    const cardCache = {
        signature: '',
        sessionData: null,
        checkout: null,
        card: null,
        applePay: null,
        googlePay: null,
        readyMethods: [],
        parking: null,
        payResolver: null,
    };

    function ensureParking() {
        if (cardCache.parking && document.body.contains(cardCache.parking)) {
            return cardCache.parking;
        }
        const el = document.createElement('div');
        el.id = 'paykka-card-parking';
        el.setAttribute('aria-hidden', 'true');
        el.style.cssText =
            'position:absolute;left:-9999px;top:0;width:1px;height:1px;overflow:hidden;opacity:0;pointer-events:none;';
        document.body.appendChild(el);
        cardCache.parking = el;
        return el;
    }

    function mountComponent(instance, container) {
        if (!instance || typeof instance.mount !== 'function' || !container) {
            return false;
        }
        try {
            if (typeof instance.unmount === 'function') {
                instance.unmount();
            }
        } catch (e) {
            // ignore
        }
        try {
            instance.mount(container);
            return true;
        } catch (e) {
            return false;
        }
    }

    function parkAll() {
        const parking = ensureParking();
        [cardCache.applePay, cardCache.googlePay, cardCache.card].forEach(function (inst) {
            if (!inst) {
                return;
            }
            try {
                if (typeof inst.unmount === 'function') {
                    inst.unmount();
                }
            } catch (e) {
                // ignore
            }
            try {
                inst.mount(parking);
            } catch (e) {
                // ignore
            }
        });
    }

    function attachAll(appleEl, googleEl, cardEl) {
        let ok = false;
        if (cardCache.applePay && appleEl && mountComponent(cardCache.applePay, appleEl)) {
            ok = true;
        }
        if (cardCache.googlePay && googleEl && mountComponent(cardCache.googlePay, googleEl)) {
            ok = true;
        }
        if (cardCache.card && cardEl && mountComponent(cardCache.card, cardEl)) {
            ok = true;
        }
        return ok;
    }

    function destroyCachedCard() {
        [cardCache.applePay, cardCache.googlePay, cardCache.card].forEach(function (inst) {
            try {
                if (inst && typeof inst.unmount === 'function') {
                    inst.unmount();
                }
            } catch (e) {
                // ignore
            }
        });
        try {
            if (cardCache.checkout && typeof cardCache.checkout.destroy === 'function') {
                cardCache.checkout.destroy();
            }
        } catch (e) {
            // ignore
        }
        cardCache.signature = '';
        cardCache.sessionData = null;
        cardCache.checkout = null;
        cardCache.card = null;
        cardCache.applePay = null;
        cardCache.googlePay = null;
        cardCache.readyMethods = [];
        cardCache.payResolver = null;
        if (cardCache.parking && cardCache.parking.parentNode) {
            cardCache.parking.innerHTML = '';
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
                    const code = m.code || m.id || m.method || m.payment_method || m.name;
                    return code ? String(code).toUpperCase() : '';
                }
                return '';
            })
            .filter(Boolean);
    }

    function hasPaymentMethod(codes, needle) {
        const target = String(needle).toUpperCase();
        return codes.indexOf(target) !== -1;
    }

    function mapBlocksAddress(prefix, address) {
        const a = address || {};
        return {
            [prefix + '_first_name']: a.first_name || '',
            [prefix + '_last_name']: a.last_name || '',
            [prefix + '_company']: a.company || '',
            [prefix + '_address_1']: a.address_1 || '',
            [prefix + '_address_2']: a.address_2 || '',
            [prefix + '_city']: a.city || '',
            [prefix + '_state']: a.state || '',
            [prefix + '_postcode']: a.postcode || '',
            [prefix + '_country']: a.country || '',
            [prefix + '_phone']: a.phone || '',
            [prefix + '_email']: a.email || '',
        };
    }

    function buildCheckoutPayload(billing, shippingData) {
        const billingAddress = Object.assign(
            {},
            (billing && billing.billingAddress) || {},
            billing && billing.email ? { email: billing.email } : {},
            billing && billing.phone ? { phone: billing.phone } : {}
        );
        const hasCountry = !!(billingAddress.country || (billing && billing.country));
        const address = hasCountry
            ? billingAddress
            : Object.assign({}, billing || {}, billingAddress);

        const shippingAddress =
            (shippingData && shippingData.shippingAddress) ||
            (billing && billing.shippingAddress) ||
            {};

        const payload = Object.assign(
            { payment_method: gatewayId },
            mapBlocksAddress('billing', address),
            mapBlocksAddress('shipping', shippingAddress)
        );
        if (!payload.billing_email && billing && billing.email) {
            payload.billing_email = billing.email;
        }
        return payload;
    }

    function sessionSignature(billing, shippingData) {
        const p = buildCheckoutPayload(billing, shippingData);
        return JSON.stringify({
            email: p.billing_email || '',
            country: p.billing_country || '',
            first: p.billing_first_name || '',
            last: p.billing_last_name || '',
            line1: p.billing_address_1 || '',
            city: p.billing_city || '',
            post: p.billing_postcode || '',
            state: p.billing_state || '',
            phone: p.billing_phone || '',
            s_country: p.shipping_country || '',
            s_line1: p.shipping_address_1 || '',
            s_city: p.shipping_city || '',
            s_post: p.shipping_postcode || '',
            s_last: p.shipping_last_name || '',
        });
    }

    function formatFormValidateError(formValidateError) {
        if (!formValidateError) {
            return '';
        }
        if (typeof formValidateError === 'string') {
            return formValidateError;
        }
        const keys = Object.keys(formValidateError);
        if (!keys.length) {
            return '';
        }
        const parts = [];
        keys.forEach(function (key) {
            const list = formValidateError[key];
            if (!Array.isArray(list)) {
                return;
            }
            list.forEach(function (item) {
                if (!item) {
                    return;
                }
                if (typeof item === 'string') {
                    parts.push(item);
                    return;
                }
                parts.push(item.msg || item.message || item.code || '');
            });
        });
        const text = parts.filter(Boolean).join(' ').trim();
        return text || i18n.needCard || 'Please enter complete card details';
    }

    function getCheckoutValidationBlocker() {
        try {
            if (!window.wp || !window.wp.data || typeof window.wp.data.select !== 'function') {
                return '';
            }
            const store = window.wp.data.select('wc/store/validation');
            if (!store) {
                return '';
            }
            if (typeof store.getValidationErrors === 'function') {
                const errors = store.getValidationErrors();
                if (errors && typeof errors === 'object') {
                    const keys = Object.keys(errors);
                    for (let i = 0; i < keys.length; i++) {
                        const err = errors[keys[i]];
                        if (err && err.message && err.hidden !== true) {
                            return String(err.message);
                        }
                    }
                }
            }
            if (typeof store.hasValidationErrors === 'function' && store.hasValidationErrors()) {
                return i18n.fixInvalid || 'Please fix checkout field errors before paying.';
            }
        } catch (e) {
            // ignore
        }
        return '';
    }

    function formatPaykkaError(err, fallback) {
        const fb = fallback || i18n.error || 'Payment failed';
        if (!err) {
            return fb;
        }
        if (typeof err === 'string') {
            const trimmed = err.trim();
            if (trimmed.charAt(0) === '{') {
                try {
                    const parsed = JSON.parse(trimmed);
                    if (parsed && (parsed.msg || parsed.message)) {
                        return String(parsed.msg || parsed.message);
                    }
                } catch (e) {
                    // ignore
                }
            }
            return err;
        }
        if (typeof err === 'object') {
            if (err.msg) {
                return String(err.msg);
            }
            if (err.message) {
                const m = String(err.message).trim();
                if (m.charAt(0) === '{') {
                    try {
                        const parsed = JSON.parse(m);
                        if (parsed && (parsed.msg || parsed.message)) {
                            return String(parsed.msg || parsed.message);
                        }
                    } catch (e) {
                        // ignore
                    }
                }
                return m;
            }
            if (err.code && err.type) {
                return fb;
            }
        }
        return fb;
    }

    function loadSdk() {
        if (window.PayKKaCardCheckoutUI) {
            return Promise.resolve();
        }
        if (!settings.sdkUrl) {
            return Promise.reject(new Error('Missing PayKKa SDK URL'));
        }
        return new Promise(function (resolve, reject) {
            let script = document.querySelector('script[src="' + settings.sdkUrl + '"]');
            if (!script) {
                script = document.createElement('script');
                script.src = settings.sdkUrl;
                script.async = true;
                document.head.appendChild(script);
            }
            script.addEventListener('load', resolve, { once: true });
            script.addEventListener('error', reject, { once: true });
        });
    }

    function Label(props) {
        const { PaymentMethodLabel } = props.components;
        return createElement(PaymentMethodLabel, { text: labelText });
    }

    function Content(props) {
        const { billing, shippingData, eventRegistration, emitResponse, activePaymentMethod } = props;
        const { onPaymentSetup } = eventRegistration || {};
        const appleRef = useRef(null);
        const googleRef = useRef(null);
        const mountRef = useRef(null);
        const requestSeq = useRef(0);
        const retryNewSessionRef = useRef(false);
        const inFlightRef = useRef(false);
        const billingRef = useRef(billing);
        const shippingRef = useRef(shippingData);
        billingRef.current = billing;
        shippingRef.current = shippingData;

        const cachedReady =
            !!(cardCache.checkout && cardCache.sessionData && (cardCache.card || cardCache.applePay || cardCache.googlePay));
        const [status, setStatus] = useState(
            cachedReady ? i18n.ready || 'Ready' : i18n.loading || 'Loading…'
        );
        const [error, setError] = useState('');
        const [ready, setReady] = useState(cachedReady);

        const restoreCached = useCallback(function (sig) {
            if (
                !cardCache.checkout ||
                !cardCache.sessionData ||
                cardCache.signature !== sig ||
                (!cardCache.card && !cardCache.applePay && !cardCache.googlePay)
            ) {
                return false;
            }
            if (!attachAll(appleRef.current, googleRef.current, mountRef.current)) {
                return false;
            }
            setReady(true);
            setError('');
            setStatus(i18n.ready || 'Ready');
            return true;
        }, []);

        const createCard = useCallback(async function (data, sig, requestFreshSession) {
            await loadSdk();
            const sdk = window.PayKKaCardCheckoutUI;
            if (!sdk || !sdk.PayKKaCheckout || !sdk.Card) {
                throw new Error(i18n.error || 'SDK unavailable');
            }

            destroyCachedCard();

            const checkout = new sdk.PayKKaCheckout({
                sessionId: data.session_id,
                clientKey: data.client_key || settings.clientKey,
                env: data.env || settings.env || 'sandbox',
                // 卡支付走 WC 下单按钮；钱包按钮由 Apple/Google 组件自身展示
                hidePaymentButton: true,
                onPaymentMethodsReady: function (methods) {
                    cardCache.readyMethods = normalizeMethodCodes(methods);
                },
                onInitError: function (err) {
                    if (!retryNewSessionRef.current && typeof requestFreshSession === 'function') {
                        retryNewSessionRef.current = true;
                        destroyCachedCard();
                        requestFreshSession({ forceNew: true });
                        return;
                    }
                    const message = formatPaykkaError(err, i18n.error || 'Init error');
                    setReady(false);
                    setError(message);
                    setStatus('');
                },
                onSubmit: function (formValidateError) {
                    const message = formatFormValidateError(formValidateError);
                    if (message) {
                        setError(message);
                        setStatus(i18n.ready || 'Ready');
                        if (cardCache.payResolver) {
                            cardCache.payResolver({ ok: false, message: message });
                            cardCache.payResolver = null;
                        }
                    }
                },
                onSuccess: function (payload) {
                    cardCache.payResolver = null;
                    const returnUrl =
                        (payload && payload.returnUrl) ||
                        (typeof payload === 'string' ? payload : '') ||
                        (cardCache.sessionData && cardCache.sessionData.return_url) ||
                        data.return_url ||
                        '/';
                    window.location.href = returnUrl;
                },
                onExpired: function () {
                    if (!retryNewSessionRef.current && typeof requestFreshSession === 'function') {
                        retryNewSessionRef.current = true;
                        destroyCachedCard();
                        requestFreshSession({ forceNew: true });
                        return;
                    }
                    setReady(false);
                    setError(i18n.error || 'Session expired');
                    setStatus('');
                    if (cardCache.payResolver) {
                        cardCache.payResolver({
                            ok: false,
                            message: i18n.error || 'Session expired',
                        });
                        cardCache.payResolver = null;
                    }
                },
                onError: function (err) {
                    const message = formatPaykkaError(err, i18n.error || 'Payment failed');
                    setError(message);
                    setStatus(i18n.ready || 'Ready');
                    if (cardCache.payResolver) {
                        cardCache.payResolver({ ok: false, message: message });
                        cardCache.payResolver = null;
                    }
                },
                onTimeout: function () {
                    const message = i18n.error || 'Payment timeout';
                    setError(message);
                    setStatus(i18n.ready || 'Ready');
                    if (cardCache.payResolver) {
                        cardCache.payResolver({ ok: false, message: message });
                        cardCache.payResolver = null;
                    }
                },
            });

            await checkout.init();

            const codes = cardCache.readyMethods.length
                ? cardCache.readyMethods
                : ['APPLE_PAY', 'GOOGLE_PAY', 'VISA', 'MASTER_CARD', 'BANKCARD'];
            const parking = ensureParking();

            if (sdk.ApplePay && hasPaymentMethod(codes, 'APPLE_PAY')) {
                try {
                    const apple = checkout.create(sdk.ApplePay, { hidePaymentButton: false });
                    apple.mount(appleRef.current || parking);
                    cardCache.applePay = apple;
                } catch (e) {
                    // 环境不支持时忽略
                }
            }

            if (sdk.GooglePay && hasPaymentMethod(codes, 'GOOGLE_PAY')) {
                try {
                    const google = checkout.create(sdk.GooglePay, { hidePaymentButton: false });
                    google.mount(googleRef.current || parking);
                    cardCache.googlePay = google;
                } catch (e) {
                    // 环境不支持时忽略
                }
            }

            const cardOpts = {
                showCardBrands: true,
                cardInfoLayout: 'combine',
                showHolderName: false,
                onSubmit: function (formValidateError) {
                    const message = formatFormValidateError(formValidateError);
                    if (message) {
                        setError(message);
                        setStatus(i18n.ready || 'Ready');
                        if (cardCache.payResolver) {
                            cardCache.payResolver({ ok: false, message: message });
                            cardCache.payResolver = null;
                        }
                    }
                },
            };

            const card = checkout.create(sdk.Card, cardOpts);
            card.mount(mountRef.current || parking);

            cardCache.checkout = checkout;
            cardCache.card = card;
            cardCache.sessionData = data;
            cardCache.signature = sig;
            retryNewSessionRef.current = false;

            if (mountRef.current || appleRef.current || googleRef.current) {
                attachAll(appleRef.current, googleRef.current, mountRef.current);
                setReady(true);
                setError('');
                setStatus(i18n.ready || 'Ready');
            }
        }, []);

        const requestSession = useCallback(
            async function (opts) {
                const forceNew = !!(opts && opts.forceNew);
                const sig = sessionSignature(billingRef.current, shippingRef.current);
                const payload = buildCheckoutPayload(billingRef.current, shippingRef.current);

                if (!payload.billing_email || !payload.billing_country) {
                    destroyCachedCard();
                    setReady(false);
                    setError('');
                    setStatus(i18n.needBilling || 'Need billing');
                    return;
                }

                const checkoutBlocker = getCheckoutValidationBlocker();
                if (checkoutBlocker) {
                    destroyCachedCard();
                    setReady(false);
                    setStatus('');
                    setError(checkoutBlocker);
                    return;
                }

                if (!forceNew && restoreCached(sig)) {
                    return;
                }

                if (
                    !forceNew &&
                    cardCache.sessionData &&
                    cardCache.signature === sig &&
                    (cardCache.card || cardCache.applePay || cardCache.googlePay)
                ) {
                    if (restoreCached(sig)) {
                        return;
                    }
                }

                if (inFlightRef.current && !forceNew) {
                    return;
                }

                const seq = ++requestSeq.current;
                inFlightRef.current = true;
                setStatus(i18n.loading || 'Loading…');
                setError('');
                setReady(false);

                try {
                    if (!settings.ajaxUrl) {
                        throw new Error(i18n.error || 'Missing ajaxUrl');
                    }
                    const body = new URLSearchParams();
                    body.set('security', settings.nonce || '');
                    body.set('payment_method', gatewayId);
                    body.set('force_new', '1');
                    body.set('billing', JSON.stringify(payload));

                    const response = await fetch(settings.ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        },
                        body: body.toString(),
                    });
                    const raw = await response.text();
                    let json = null;
                    try {
                        json = raw ? JSON.parse(raw) : null;
                    } catch (parseErr) {
                        throw new Error(
                            (i18n.error || 'Session failed') + (raw ? '' : ' (empty response)')
                        );
                    }
                    if (seq !== requestSeq.current) {
                        return;
                    }
                    if (!json || !json.success || !json.data) {
                        throw new Error(
                            (json && json.data && json.data.message) ||
                                i18n.error ||
                                'Session failed'
                        );
                    }
                    await createCard(json.data, sig, requestSession);
                } catch (err) {
                    if (seq !== requestSeq.current) {
                        return;
                    }
                    destroyCachedCard();
                    setReady(false);
                    setStatus('');
                    setError(formatPaykkaError(err, i18n.error || 'Error'));
                } finally {
                    if (seq === requestSeq.current) {
                        inFlightRef.current = false;
                    }
                }
            },
            [createCard, restoreCached]
        );

        const sig = sessionSignature(billing, shippingData);

        useEffect(
            function () {
                if (activePaymentMethod !== gatewayId) {
                    parkAll();
                    return undefined;
                }

                if (restoreCached(sig)) {
                    return undefined;
                }

                const timer = setTimeout(function () {
                    requestSession({ forceNew: false });
                }, 300);
                return function () {
                    clearTimeout(timer);
                };
            },
            [sig, activePaymentMethod, requestSession, restoreCached]
        );

        useEffect(function () {
            return function () {
                parkAll();
            };
        }, []);

        useEffect(function () {
            const onLeave = function () {
                destroyCachedCard();
            };
            window.addEventListener('pagehide', onLeave);
            return function () {
                window.removeEventListener('pagehide', onLeave);
            };
        }, []);

        useEffect(
            function () {
                if (!onPaymentSetup) {
                    return undefined;
                }
                const unsubscribe = onPaymentSetup(function () {
                    const checkoutBlocker = getCheckoutValidationBlocker();
                    if (checkoutBlocker) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: checkoutBlocker,
                            messageContext: emitResponse.noticeContexts.PAYMENTS,
                        };
                    }

                    if (
                        !ready ||
                        !cardCache.card ||
                        !cardCache.card.ref ||
                        typeof cardCache.card.ref.payment !== 'function'
                    ) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: i18n.notReady || 'Payment form is not ready',
                            messageContext: emitResponse.noticeContexts.PAYMENTS,
                        };
                    }

                    setStatus(i18n.paying || 'Paying…');
                    setError('');

                    return new Promise(function (resolve) {
                        let settled = false;
                        const finish = function (result) {
                            if (settled) {
                                return;
                            }
                            settled = true;
                            cardCache.payResolver = null;
                            clearTimeout(timer);
                            if (!result || result.ok === false) {
                                setStatus(i18n.ready || 'Ready');
                                resolve({
                                    type: emitResponse.responseTypes.ERROR,
                                    message:
                                        (result && result.message) ||
                                        i18n.needCard ||
                                        i18n.error ||
                                        'Payment failed',
                                    messageContext: emitResponse.noticeContexts.PAYMENTS,
                                });
                                return;
                            }
                        };

                        cardCache.payResolver = finish;
                        const timer = setTimeout(function () {
                            finish({
                                ok: false,
                                message: i18n.needCard || i18n.error || 'Payment timeout',
                            });
                        }, 60000);

                        try {
                            const maybe = cardCache.card.ref.payment();
                            if (maybe && typeof maybe.then === 'function') {
                                maybe.catch(function (err) {
                                    finish({
                                        ok: false,
                                        message: (err && err.message) || i18n.needCard || i18n.error,
                                    });
                                });
                            }
                        } catch (err) {
                            finish({
                                ok: false,
                                message: (err && err.message) || i18n.needCard || i18n.error,
                            });
                        }
                    });
                });
                return unsubscribe;
            },
            [onPaymentSetup, emitResponse, ready]
        );

        return createElement(
            'div',
            { className: 'paykka-checkout-fields paykka-blocks-card' },
            createElement('div', {
                id: 'paykka-checkout-apple-pay',
                className: 'paykka-wallet-container paykka-apple-pay-container',
                ref: appleRef,
            }),
            createElement('div', {
                id: 'paykka-checkout-google-pay',
                className: 'paykka-wallet-container paykka-google-pay-container',
                ref: googleRef,
            }),
            status
                ? createElement(
                      'p',
                      { className: 'paykka-card-status', role: 'status', 'aria-live': 'polite' },
                      status
                  )
                : null,
            createElement('div', {
                id: 'paykka-checkout-card',
                className: 'paykka-card-container',
                ref: mountRef,
            }),
            error
                ? createElement('p', { className: 'paykka-card-error', role: 'alert' }, error)
                : null
        );
    }

    registerPaymentMethod({
        name: gatewayId,
        label: createElement(Label, null),
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: () => true,
        ariaLabel: labelText,
        supports: {
            features: settings.supports || ['products'],
        },
    });
})();
