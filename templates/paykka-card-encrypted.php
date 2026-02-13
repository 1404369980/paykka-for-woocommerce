<?php
/**
 * Paykka Encrypted Card payment page.
 */
if (!defined('ABSPATH')) {
    exit;
}
$paykka_checkout_base = function_exists('paykka_get_checkout_base_url') ? paykka_get_checkout_base_url() : 'https://checkout-fat.eu.paykka.com';
$settings = function_exists('getPaykkaSettings') ? getPaykkaSettings() : array();
$merchant_id = isset($settings['paykka_merchant_id']) ? $settings['paykka_merchant_id'] : '';
$client_key = isset($settings['paykka_client_key']) ? $settings['paykka_client_key'] : '';
$order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
$rest_url = rest_url('paykka/v1/encrypted_card');
?>
<link href="<?php echo esc_url($paykka_checkout_base); ?>/cp/style.css" rel="stylesheet" />
<script type="text/javascript" src="<?php echo esc_url($paykka_checkout_base); ?>/cp/encrypted-card.js"></script>

<div id="paykka_payform" class="paykka-api-payment-form" style="display:flex;flex-direction: column;align-items: center;padding-bottom: 20px;max-width: 500px;margin: 0 auto;">
    <div id="encryptedCardWrapper">
        <div data-eci="cardNumber" class="paykka-eci-field" style="width:100%;max-width:500px;padding-bottom: 16px;"></div>
        <div data-eci="expiryDate" class="paykka-eci-field" style="width:100%;max-width:500px;padding-bottom: 16px;"></div>
        <div data-eci="securityCode" class="paykka-eci-field" style="width:100%;max-width:500px;padding-bottom: 16px;"></div>
        <button
            class="paykka-card-checkout paykka-card-checkout-button paykka-card-checkout-submit-button paykka-card-checkout-card__button"
            onclick="handleClick()" style="width:100%;max-width:500px;margin-top:8px;"><?php esc_html_e('支付', 'paykka-for-woocommerce'); ?></button>
    </div>
    <div id="error_message" style="width:100%;max-width:500px;color:red;margin-top:8px;"></div>
</div>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function () {
    var orderId = <?php echo (int) $order_id; ?>;
    var restUrl = <?php echo wp_json_encode($rest_url); ?>;
    var merchantId = <?php echo wp_json_encode($merchant_id); ?>;
    var clientKey = <?php echo wp_json_encode($client_key); ?>;
    var checkoutBase = <?php echo wp_json_encode($paykka_checkout_base); ?>;

    var PayKKaEncryptedCard = window.PayKKaCardCheckoutEncryptedCard || window.PaykkaCardCheckoutEncryptedCard;
    if (!PayKKaEncryptedCard) {
        document.getElementById("error_message").textContent = "SDK not loaded.";
        return;
    }

    PayKKaEncryptedCard.setEnv({
        apiUrl: checkoutBase,
        cdnUrl: checkoutBase + '/cp'
    });

    if (clientKey && typeof PayKKaEncryptedCard.setFraudDetectionEnv === 'function') {
        PayKKaEncryptedCard.setFraudDetectionEnv({ SR: clientKey });
    }

    var encryptedRes = null;
    var EncryptedCard = PayKKaEncryptedCard.init({
        merchantId: merchantId,
        clientKey: clientKey,
        showLabel: true,
        styles: {
            input: {
                base: { fontSize: '16px' },
                focus: { color: 'blue' },
                valid: { border: '1px solid yellowgreen', color: 'yellowgreen' },
                invalid: { border: '1px solid red', color: 'red' }
            }
        },
        onCardEncrypted: function (res) {
            encryptedRes = res;
            processPay();
        }
    });

    var payButton = document.querySelector('#encryptedCardWrapper button');
    window.handleClick = function () {
        if (!orderId) {
            document.getElementById("error_message").textContent = "Invalid order.";
            return;
        }
        payButton.disabled = true;
        payButton.style.opacity = '0.7';
        payButton.textContent = '处理中...';
        EncryptedCard.encrypt();
    };

    function processPay() {
        if (!encryptedRes) {
            document.getElementById("error_message").textContent = "Encryption failed.";
            payButton.disabled = false;
            payButton.style.opacity = '1';
            payButton.textContent = '支付';
            return;
        }
        fetch(restUrl, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                encrypted_card_data: encryptedRes,
                order_id: orderId
            })
        })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success && result.redirect_url) {
                window.location.href = result.redirect_url;
            } else {
                document.getElementById("error_message").textContent = result.message || "Payment failed.";
                payButton.disabled = false;
                payButton.style.opacity = '1';
                payButton.textContent = '支付';
            }
        })
        .catch(function (error) {
            document.getElementById("error_message").textContent = String(error);
            payButton.disabled = false;
            payButton.style.opacity = '1';
            payButton.textContent = '支付';
        });
    }
});
</script>