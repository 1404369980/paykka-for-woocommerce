/**
 * Paykka 结账页卡支付：加密卡 SDK + 欺诈检测，在 WooCommerce 提交前注入加密数据
 */
(function () {
    'use strict';

    var paykkaSubmitting = false;
    var encryptedCardInstance = null;

    function initPaykkaCard() {
        var container = document.getElementById('paykka-card-fields-container');
        if (!container) return;

        var checkoutBase = container.getAttribute('data-checkout-base') || (window.paykka_checkout_card_config && window.paykka_checkout_card_config.checkoutBaseUrl);
        var merchantId = container.getAttribute('data-merchant-id');
        var clientKey = container.getAttribute('data-client-key');

        var PayKKaEncryptedCard = window.PayKKaCardCheckoutEncryptedCard || window.PaykkaCardCheckoutEncryptedCard;
        if (!PayKKaEncryptedCard) {
            setTimeout(initPaykkaCard, 100);
            return;
        }

        PayKKaEncryptedCard.setEnv({
            apiUrl: checkoutBase,
            cdnUrl: checkoutBase + '/cp'
        });

        if (clientKey && typeof PayKKaEncryptedCard.setFraudDetectionEnv === 'function') {
            PayKKaEncryptedCard.setFraudDetectionEnv({ SR: clientKey });
        }

        encryptedCardInstance = PayKKaEncryptedCard.init({
            merchantId: merchantId,
            clientKey: clientKey,
            showLabel: true,
            onCardEncrypted: function (res) {
                var input = document.getElementById('paykka_encrypted_card_data');
                var errEl = document.getElementById('paykka-card-error');
                if (input && res) {
                    input.value = typeof res === 'string' ? res : JSON.stringify(res);
                    if (errEl) {
                        errEl.style.display = 'none';
                        errEl.textContent = '';
                    }
                    paykkaSubmitting = true;
                    if (typeof jQuery !== 'undefined') {
                        setTimeout(function () {
                            jQuery(document.body).trigger('checkout_place_order');
                        }, 0);
                    } else {
                        var form = document.querySelector('form.checkout');
                        if (form) form.submit();
                    }
                } else {
                    if (errEl) {
                        errEl.style.display = 'block';
                        errEl.textContent = 'Card encryption failed. Please check your card details.';
                    }
                    paykkaSubmitting = false;
                    if (typeof jQuery !== 'undefined') {
                        jQuery(document.body).trigger('checkout_error');
                    }
                }
            }
        });
    }

    function blockAndEncrypt() {
        if (paykkaSubmitting) return true;
        if (!document.getElementById('paykka-card-fields-container')) return true;
        if (!encryptedCardInstance || typeof encryptedCardInstance.encrypt !== 'function') {
            var errEl = document.getElementById('paykka-card-error');
            if (errEl) {
                errEl.style.display = 'block';
                errEl.textContent = 'Payment form is not ready. Please wait or refresh.';
            }
            return false;
        }
        var errEl = document.getElementById('paykka-card-error');
        if (errEl) {
            errEl.style.display = 'none';
            errEl.textContent = '';
        }
        encryptedCardInstance.encrypt();
        return false;
    }

    function onCheckoutSubmit(e) {
        var form = e.target;
        if (!form || !form.classList || !form.classList.contains('checkout')) return;
        var paymentMethod = (form.querySelector('input[name="payment_method"]:checked') || {}).value;
        if (paymentMethod !== 'paykka') return;
        if (paykkaSubmitting) return;
        if (!document.getElementById('paykka-card-fields-container')) return;
        if (!encryptedCardInstance || typeof encryptedCardInstance.encrypt !== 'function') {
            var errEl = document.getElementById('paykka-card-error');
            if (errEl) {
                errEl.style.display = 'block';
                errEl.textContent = 'Payment form is not ready. Please wait or refresh.';
            }
            e.preventDefault();
            return false;
        }
        e.preventDefault();
        e.stopPropagation();
        var errEl = document.getElementById('paykka-card-error');
        if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
        encryptedCardInstance.encrypt();
        return false;
    }

    function bind() {
        initPaykkaCard();
        var form = document.querySelector('form.checkout');
        if (form) form.addEventListener('submit', onCheckoutSubmit, true);
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('checkout_place_order_paykka', function () {
                return blockAndEncrypt();
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
