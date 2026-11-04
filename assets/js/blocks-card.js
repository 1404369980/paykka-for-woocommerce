(() => {
    'use strict';

    const { createElement, useEffect, useLayoutEffect, useRef, useState, useCallback } = window.wp.element;
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
        payTimer: null,
        skipSubmitNote: false,
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
        // 已挂在同一容器：禁止 unmount/remount。
        // 否则点「下单」时 attachAll 会刷新卡组件（Activated 重触发、已填卡号丢失、支付 iframe 中断）。
        if (instance.__paykkaMountEl === container) {
            return true;
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
            instance.__paykkaMountEl = container;
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
            mountComponent(inst, parking);
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
        [cardCache.applePay, cardCache.googlePay, cardCache.card].forEach(function (inst) {
            if (inst) {
                try {
                    delete inst.__paykkaMountEl;
                } catch (e) {
                    // ignore
                }
            }
        });
        cardCache.signature = '';
        cardCache.sessionData = null;
        cardCache.checkout = null;
        cardCache.card = null;
        cardCache.applePay = null;
        cardCache.googlePay = null;
        cardCache.readyMethods = [];
        cardCache.payResolver = null;
        if (cardCache.payTimer) {
            clearTimeout(cardCache.payTimer);
            cardCache.payTimer = null;
        }
        cardCache.skipSubmitNote = false;
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

    /**
     * 支付前绑定/刷新 pending WC 订单（对齐 Stripe/PayPal：先有订单再收款）。
     * @returns {Promise<{ok:boolean, order_id?:number, message?:string}>}
     */
    function notePlaceOrder(billing, shippingData) {
        const url = settings.noteAjaxUrl;
        if (!url) {
            return Promise.resolve({
                ok: false,
                message: i18n.orderBindFailed || 'Could not create order',
            });
        }
        const body = new URLSearchParams();
        body.set('security', settings.nonce || '');
        body.set('billing', JSON.stringify(buildCheckoutPayload(billing, shippingData)));
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: body.toString(),
        })
            .then(function (response) {
                return response.text().then(function (raw) {
                    let json = null;
                    try {
                        json = raw ? JSON.parse(raw) : null;
                    } catch (e) {
                        return {
                            ok: false,
                            message: i18n.orderBindFailed || 'Could not create order',
                        };
                    }
                    if (json && json.success && json.data && json.data.order_id) {
                        return {
                            ok: true,
                            order_id: Number(json.data.order_id) || 0,
                            attempt_id: json.data.attempt_id || '',
                        };
                    }
                    return {
                        ok: false,
                        message:
                            (json && json.data && json.data.message) ||
                            i18n.orderBindFailed ||
                            'Could not create order',
                    };
                });
            })
            .catch(function () {
                return {
                    ok: false,
                    message: i18n.orderBindFailed || 'Could not create order',
                };
            });
    }

    /**
     * 收银台支付失败 → 将已绑定 WC 订单标为 failed。
     */
    function markPaymentFailed(message) {
        const url = settings.failAjaxUrl;
        if (!url) {
            return Promise.resolve();
        }
        const body = new URLSearchParams();
        body.set('security', settings.nonce || '');
        if (message) {
            body.set('message', String(message).slice(0, 500));
        }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: body.toString(),
        }).catch(function () {
            // 失败标记为最佳努力，不阻断前端错误展示
        });
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

    function splitPhone(phone) {
        const raw = String(phone || '').trim();
        if (!raw) {
            return { area_code: '', phone_number: '' };
        }
        const parts = raw.split(/\s+/);
        if (parts.length >= 2 && parts[1]) {
            return {
                area_code: String(parts[0]).slice(0, 10),
                phone_number: parts.slice(1).join(' '),
            };
        }
        return { area_code: '', phone_number: raw };
    }

    /**
     * _getExtraParams 的 bill / shipping 结构（与支付侧约定一致，不做 N/A 等默认兜底）。
     */
    function buildPaykkaBillOrShipping(address, fallbackEmail) {
        const a = address || {};
        const phone = splitPhone(a.phone);
        return {
            first_name: a.first_name || '',
            middle_name: a.middle_name || '',
            last_name: a.last_name || '',
            email: a.email || fallbackEmail || '',
            country: a.country || '',
            state: a.state || '',
            city: a.city || '',
            postal_code: a.postcode || '',
            address_line1: a.address_1 || '',
            address_line2: a.address_2 || '',
            district: a.district || '',
            area_code: phone.area_code || '',
            phone_number: phone.phone_number || '',
        };
    }

    /**
     * 是否有真实收货信息（有才传 shipping，无则不传；不回退账单、不填默认值）。
     */
    function hasRealShippingAddress(address) {
        const a = address || {};
        return !!(
            a.address_1 ||
            a.city ||
            a.postcode ||
            a.last_name ||
            a.first_name ||
            a.country
        );
    }

    function resolveBillingAddress(billing) {
        const billingAddress = Object.assign(
            {},
            (billing && billing.billingAddress) || {},
            billing && billing.email ? { email: billing.email } : {},
            billing && billing.phone ? { phone: billing.phone } : {}
        );
        const hasCountry = !!(billingAddress.country || (billing && billing.country));
        return hasCountry ? billingAddress : Object.assign({}, billing || {}, billingAddress);
    }

    function resolveShippingAddress(billing, shippingData) {
        return (
            (shippingData && shippingData.shippingAddress) ||
            (billing && billing.shippingAddress) ||
            {}
        );
    }

    function buildCheckoutPayload(billing, shippingData) {
        const address = resolveBillingAddress(billing);
        const shippingAddress = resolveShippingAddress(billing, shippingData);
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

    /**
     * cartTotal.value 为最小货币单位整数，按 minorUnit 除回主单位。
     * 例：value=100, minorUnit=2 → 1
     */
    function minorToMajorAmount(minorValue, minorUnit) {
        const value = Number(minorValue);
        if (!isFinite(value)) {
            return NaN;
        }
        const unitRaw =
            minorUnit != null && minorUnit !== ''
                ? parseInt(String(minorUnit), 10)
                : 2;
        const decimals = !isNaN(unitRaw) && unitRaw >= 0 ? unitRaw : 2;
        return value / Math.pow(10, decimals);
    }

    function getCartAmountParts(billing) {
        const currencyObj = (billing && billing.currency) || {};
        const currency =
            (typeof currencyObj === 'string' ? currencyObj : currencyObj.code) ||
            (billing && billing.currencyCode) ||
            '';
        const minorUnit =
            typeof currencyObj === 'object' && currencyObj
                ? currencyObj.minorUnit != null
                    ? currencyObj.minorUnit
                    : currencyObj.minor_unit
                : 2;
        const cartTotal = (billing && billing.cartTotal) || {};
        let amount = NaN;
        if (cartTotal.value != null && cartTotal.value !== '') {
            amount = minorToMajorAmount(cartTotal.value, minorUnit);
        }
        return {
            amount: amount,
            currency: String(currency || ''),
            minorUnit: minorUnit,
        };
    }

    /**
     * Session 选中后即可创建；改地址/加优惠券都不重建。
     * 金额与账单在支付时经 _getExtraParams（trans_amount / bill）覆盖。
     */
    function sessionSignature(billing, shippingData) {
        return 'paykka-card-ready';
    }

    /**
     * 结账页用户当前输入的邮箱（优先于创建 session 时的默认邮箱）。
     */
    function resolveEnteredEmail(billing) {
        const billAddr = resolveBillingAddress(billing);
        const email = String(
            (billAddr && billAddr.email) || (billing && billing.email) || ''
        ).trim();
        return email;
    }

    function buildExtraParams(billing, shippingData, sessionFallback) {
        const billAddr = resolveBillingAddress(billing);
        const shipAddr = resolveShippingAddress(billing, shippingData);
        // 下单支付：必须用用户输入邮箱覆盖 Create Session 的默认邮箱
        const email = resolveEnteredEmail(billing);
        const money = getCartAmountParts(billing);
        // 主路径：cartTotal.value 按 minorUnit 除回主单位
        let amountNum = money.amount;
        let currency = money.currency || '';
        if ((isNaN(amountNum) || amountNum === null) && sessionFallback) {
            if (sessionFallback.amount_major != null && sessionFallback.amount_major !== '') {
                amountNum = Number(sessionFallback.amount_major);
            } else if (sessionFallback.amount != null) {
                amountNum = minorToMajorAmount(sessionFallback.amount, money.minorUnit);
            }
        }
        if (!currency && sessionFallback && sessionFallback.currency) {
            currency = String(sessionFallback.currency);
        }

        const extras = {
            trans_amount: {
                amount: !isNaN(amountNum) ? amountNum : 0,
                currency: currency,
            },
            bill: buildPaykkaBillOrShipping(billAddr, email),
            customer: {
                email: email,
            },
        };
        if (hasRealShippingAddress(shipAddr)) {
            extras.shipping = buildPaykkaBillOrShipping(shipAddr, email);
        }
        return extras;
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
            setStatus('');
            return true;
        }, []);

        const createCard = useCallback(async function (data, sig, requestFreshSession) {
            await loadSdk();
            const sdk = window.PayKKaCardCheckoutUI;
            if (!sdk || !sdk.PayKKaCheckout || !sdk.Card) {
                throw new Error(i18n.error || 'SDK unavailable');
            }

            destroyCachedCard();

            // 与 classic checkout-card.js 一致：必须带 _envConfig，
            // 否则支付 hidden iframe 可能落在商户域，postMessage 目标源不匹配导致一直转圈。
            const checkoutBase =
                (data && data.checkout_base) || settings.checkoutBase || '';
            const envConfig = checkoutBase
                ? {
                      api: checkoutBase.replace(/\/$/, ''),
                      cdn: checkoutBase.replace(/\/$/, '') + '/cp',
                  }
                : undefined;

            const checkout = new sdk.PayKKaCheckout({
                sessionId: data.session_id,
                clientKey: data.client_key || settings.clientKey,
                env: data.env || settings.env || 'sandbox',
                returnUrl: data.return_url || '',
                _envConfig: envConfig,
                // 卡支付走 WC 下单按钮；钱包按钮由 Apple/Google 组件自身展示
                hidePaymentButton: true,
                // 支付时补齐 trans_amount / bill；（有收货时再带 shipping）
                _getExtraParams: function (type) {
                    if (type && type !== 'payment') {
                        return {};
                    }
                    return buildExtraParams(billingRef.current, shippingRef.current, data);
                },
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
                            // 仅表单校验失败，不改 WC 订单状态
                            cardCache.payResolver({
                                ok: false,
                                message: message,
                                markFailed: false,
                            });
                            cardCache.payResolver = null;
                        }
                        return;
                    }
                    // WC「下单」触发的 payment() 已绑定订单，避免重复
                    if (cardCache.skipSubmitNote) {
                        cardCache.skipSubmitNote = false;
                        return;
                    }
                    // Apple Pay / Google Pay：点钱包支付时才创建/刷新 WC 订单。
                    notePlaceOrder(billingRef.current, shippingRef.current).then(function (bindResult) {
                        if (bindResult && bindResult.ok && bindResult.order_id && cardCache.sessionData) {
                            cardCache.sessionData.order_id = bindResult.order_id;
                            return;
                        }
                        setError(
                            (bindResult && bindResult.message) ||
                                i18n.orderBindFailed ||
                                'Could not create order'
                        );
                    });
                },
                onSuccess: function (payload) {
                    // 成功跳转前清掉 WC 等待定时器，避免 60s 后误标 failed
                    if (cardCache.payTimer) {
                        clearTimeout(cardCache.payTimer);
                        cardCache.payTimer = null;
                    }
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
                    const expiredMsg = i18n.error || 'Session expired';
                    setReady(false);
                    setError(expiredMsg);
                    setStatus('');
                    // Session 过期：订单保持 pending，便于换新 Session 重试；不标 failed
                    if (cardCache.payResolver) {
                        cardCache.payResolver({
                            ok: false,
                            message: expiredMsg,
                            markFailed: false,
                        });
                        cardCache.payResolver = null;
                    }
                },
                onError: function (err) {
                    const message = formatPaykkaError(err, i18n.error || 'Payment failed');
                    setError(message);
                    setStatus(i18n.ready || 'Ready');
                    // 仅 SDK 明确支付失败时标 failed
                    markPaymentFailed(message);
                    if (cardCache.payResolver) {
                        cardCache.payResolver({
                            ok: false,
                            message: message,
                            markFailed: false,
                        });
                        cardCache.payResolver = null;
                    }
                },
                onTimeout: function () {
                    const message = i18n.payTimeout || i18n.error || 'Payment timeout';
                    setError(message);
                    setStatus(i18n.ready || 'Ready');
                    markPaymentFailed(message);
                    if (cardCache.payResolver) {
                        cardCache.payResolver({
                            ok: false,
                            message: message,
                            markFailed: false,
                        });
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
                showEmail: false,
                showHolderName: false,
                onSubmit: function (formValidateError) {
                    const message = formatFormValidateError(formValidateError);
                    if (message) {
                        setError(message);
                        setStatus(i18n.ready || 'Ready');
                        if (cardCache.payResolver) {
                            cardCache.payResolver({
                                ok: false,
                                message: message,
                                markFailed: false,
                            });
                            cardCache.payResolver = null;
                        }
                    }
                },
            };

            const card = checkout.create(sdk.Card, cardOpts);
            // 优先挂到可见容器；ref 尚未就绪时先挂 parking，随后由 useLayoutEffect 拉回
            card.mount(mountRef.current || parking);

            cardCache.checkout = checkout;
            cardCache.card = card;
            cardCache.sessionData = data;
            cardCache.signature = sig;
            retryNewSessionRef.current = false;

            const attached = attachAll(appleRef.current, googleRef.current, mountRef.current);
            setReady(true);
            setError('');
            // 表单已就绪时不展示 “Ready”，对齐 WC / Stripe：直接露出输入框
            setStatus(attached || mountRef.current ? '' : i18n.loading || 'Loading…');
        }, []);

        const requestSession = useCallback(
            async function (opts) {
                const forceNew = !!(opts && opts.forceNew);
                const sig = sessionSignature(billingRef.current, shippingRef.current);
                const payload = buildCheckoutPayload(billingRef.current, shippingRef.current);

                // 选中即可创建 Session，不要求先填邮箱/国家（支付时再经 _getExtraParams 覆盖）
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
                    if (forceNew) {
                        body.set('force_new', '1');
                    }
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
        const isSelected = activePaymentMethod === gatewayId;

        // 选中后立刻把 parking 中的组件挂回可见节点（比 useEffect 更早，减少空白闪烁）
        useLayoutEffect(
            function () {
                if (!isSelected) {
                    return undefined;
                }
                if (
                    cardCache.signature === sig &&
                    (cardCache.card || cardCache.applePay || cardCache.googlePay)
                ) {
                    if (attachAll(appleRef.current, googleRef.current, mountRef.current)) {
                        setReady(true);
                        setError('');
                        setStatus('');
                    }
                }
                return undefined;
            },
            [isSelected, sig, ready]
        );

        useEffect(
            function () {
                if (!isSelected) {
                    parkAll();
                    return undefined;
                }

                if (restoreCached(sig)) {
                    return undefined;
                }

                const timer = setTimeout(function () {
                    requestSession({ forceNew: false });
                }, 200);
                return function () {
                    clearTimeout(timer);
                };
            },
            [sig, isSelected, requestSession, restoreCached]
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

                    // 仅当组件还在 parking 时才挂回；已在可见容器则绝不 remount
                    if (
                        mountRef.current &&
                        cardCache.card &&
                        cardCache.card.__paykkaMountEl !== mountRef.current
                    ) {
                        attachAll(appleRef.current, googleRef.current, mountRef.current);
                    }

                    if (
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
                    cardCache.skipSubmitNote = true;

                    // 仅在点击「下单」时创建 WC 订单，成功后再调 SDK payment()。
                    return notePlaceOrder(billingRef.current, shippingRef.current).then(function (
                        bindResult
                    ) {
                        if (!bindResult || !bindResult.ok) {
                            cardCache.skipSubmitNote = false;
                            setStatus('');
                            return {
                                type: emitResponse.responseTypes.ERROR,
                                message:
                                    (bindResult && bindResult.message) ||
                                    i18n.orderBindFailed ||
                                    'Could not create order',
                                messageContext: emitResponse.noticeContexts.PAYMENTS,
                            };
                        }
                        if (bindResult.order_id && cardCache.sessionData) {
                            cardCache.sessionData.order_id = bindResult.order_id;
                        }

                        return new Promise(function (resolve) {
                            let settled = false;
                            const finish = function (result) {
                                if (settled) {
                                    return;
                                }
                                settled = true;
                                cardCache.payResolver = null;
                                if (cardCache.payTimer) {
                                    clearTimeout(cardCache.payTimer);
                                    cardCache.payTimer = null;
                                }
                                if (!result || result.ok === false) {
                                    cardCache.skipSubmitNote = false;
                                    setStatus('');
                                    const failMsg =
                                        (result && result.message) ||
                                        i18n.needCard ||
                                        i18n.error ||
                                        'Payment failed';
                                    if (result && result.markFailed === true) {
                                        markPaymentFailed(failMsg);
                                    }
                                    resolve({
                                        type: emitResponse.responseTypes.ERROR,
                                        message: failMsg,
                                        messageContext: emitResponse.noticeContexts.PAYMENTS,
                                    });
                                }
                            };

                            cardCache.payResolver = finish;
                            if (cardCache.payTimer) {
                                clearTimeout(cardCache.payTimer);
                            }
                            cardCache.payTimer = setTimeout(function () {
                                finish({
                                    ok: false,
                                    message:
                                        i18n.payTimeout ||
                                        i18n.error ||
                                        'Payment is taking longer than expected. Please wait or retry.',
                                    markFailed: false,
                                });
                            }, 120000);

                            try {
                                const maybe = cardCache.card.ref.payment();
                                if (maybe && typeof maybe.then === 'function') {
                                    maybe.catch(function (err) {
                                        finish({
                                            ok: false,
                                            message:
                                                (err && err.message) ||
                                                i18n.needCard ||
                                                i18n.error,
                                            markFailed: false,
                                        });
                                    });
                                }
                            } catch (err) {
                                finish({
                                    ok: false,
                                    message:
                                        (err && err.message) || i18n.needCard || i18n.error,
                                    markFailed: false,
                                });
                            }
                        });
                    });
                });
                return unsubscribe;
            },
            [onPaymentSetup, emitResponse, ready]
        );

        // 对齐 WC：选中后始终渲染非空内容区，避免 accordion `:empty` 把整块隐藏
        const showStatus = !!(
            status &&
            (!ready ||
                status === (i18n.paying || 'Paying…') ||
                status === (i18n.loading || 'Loading…'))
        );

        return createElement(
            'div',
            {
                className:
                    'paykka-checkout-fields paykka-blocks-card' +
                    (ready ? ' is-ready' : ' is-loading'),
            },
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
            showStatus
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
                // 占位，保证父级不是 :empty，WC accordion 不会 display:none
                'data-paykka-card': '1',
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
