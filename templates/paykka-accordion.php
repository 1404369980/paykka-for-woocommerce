<?php
if (!defined('ABSPATH')) {
    exit; // 确保安全性
} ?>


<!-- 通过插入样式和脚本来显示支付组件 -->
<link href="https://checkout-fat.eu.paykka.com/cp/style.css" rel="stylesheet" />
<script type="text/javascript" src="https://checkout-fat.eu.paykka.com/cp/card-checkout-ui.js"></script>

<style>
    body {
        font-family: Arial, sans-serif;
        margin: 20px;
    }

    .payment-option {
        border: 1px solid #ccc;
        padding: 15px;
        margin-bottom: 10px;
        border-radius: 5px;
        width: 530px;
    }

    .payment-header {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .payment-header img {
        height: 24px;
    }

    .payment-option input[type="radio"] {
        margin-right: 10px;
    }

    .payment-details {
        width: 500px;
        border: 1px solid #ddd;
        padding: 10px;
        margin-top: 10px;
        border-radius: 4px;
        background-color: #f9f9f9;
        display: none;
    }

    .total {
        font-size: 20px;
        font-weight: bold;
        margin: 20px 0;
    }

    .btn {
        background-color: #007bff;
        color: white;
        padding: 12px 25px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    }

    .btn:hover {
        background-color: #0056b3;
    }

    .note {
        font-size: 13px;
        color: #555;
        margin-top: 10px;
    }
</style>

<script type="text/javascript">

    function toggleFields(selected) {
        const allFields = document.querySelectorAll('.payment-details');
        allFields.forEach(div => {
            div.style.display = div.id === selected ? 'block' : 'none';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const props = {
            showCardBrands: false,
            cardInfoLayout: "combine",
            onSubmit: (formValidateError) => {
                // Handle submit logic here
            },
            onSuccess: () => {
                window.location.href = "<?php echo WC()->session->get('paykka_callback_url'); ?>";
            },
            onExpired: () => {
                window.location.replace("<?php echo WC()->session->get('paykka_callback_url'); ?>");
            }
        };

        console.log("PayKKaCardCheckoutUI", PayKKaCardCheckoutUI);

        const { Card, ApplePay, GooglePay, PayKKaCheckout } = PayKKaCardCheckoutUI;

        const paykkaCheckout = new PayKKaCheckout({
            sessionId: '<?php echo esc_html(WC()->session->get('paykka_session_id')) ?>',
            clientKey: '<?php echo esc_html(WC()->session->get('paykka_client_key')) ?>',
            hidePaymentButton: false, // 隐藏按钮
            _envConfig: {
                api: "https://checkout-fat.eu.paykka.com",
                cdn: "https://checkout-fat.eu.paykka.com/cp",
                fraudDetection: {
                    SR: 'pk_test_51QaC2P5VarcojPHdg13yagk5TqrGkIkeK8I21BgQUZe8BzyRmbtmOg3dKsXjkxt6JlsjyjJMTvBH9dFMCZWRxOkt00tWQ1eHFU'
                }
            },
            env: 'sandbox'
        });

        const CheckoutCard = paykkaCheckout.create(Card, props);
        const container = document.createElement('div');
        CheckoutCard.mount(container);

        const checkoutCardField = document.querySelector('#checkoutCardField');
        if (checkoutCardField) {
            checkoutCardField.appendChild(container);
        }

        //apple pay
        const appleCheckout = paykkaCheckout.create(ApplePay, props);
        appleCheckout.mount('#checkoutApplePayField');

        //google pay
        const googleCheckout = paykkaCheckout.create(GooglePay, props);
        googleCheckout.mount('#checkoutGooglePayField');

        // 绑定所有单选框事件
        document.querySelectorAll('input[name="payment"]').forEach(radio => {
            radio.addEventListener('change', function () {
                toggleFields(this.value);
            });
        });

        toggleFields('card'); // 默认选中 Credit Card

    });

</script>

<!-- 创建一个容器元素来展示支付组件 -->
<div id="paykka_payform" style="display:flex;flex-direction: column;align-items: center;padding-bottom: 20px">
    <!--  <div id="checkoutApplePayField" style="width:500px;padding-bottom: 20px"></div>
        <div id="checkoutGooglePayField" style="width:500px;padding-bottom: 20px"></div>
        <div id="checkoutCardField" style="width:500px;padding-bottom: 20px"></div> -->

    <!-- Apple Pay -->
    <div class="payment-option" id="apple-field">
        <label class="payment-header">
            <input type="radio" name="payment" value="applepay">
            <span>Apple Pay</span>
            <img src="https://i.postimg.cc/qqtj7WPn/logo-Apple-Pay.png" alt="Apple Pay" />
        </label>
        <div class="payment-details" id="applepay">
            <div class="note">
                <div id="checkoutApplePayField"></div>
            </div>
        </div>
    </div>

    <!-- Google Pay -->
    <div class="payment-option" id="google-field">
        <label class="payment-header">
            <input type="radio" name="payment" value="gpay">
            <span>Google Pay</span>
            <img src="https://i.postimg.cc/QdqPvZhx/logo-Google-Pay.png" alt="Google Pay" />
        </label>
        <div class="payment-details" id="gpay">
            <div class="note">
                <div id="checkoutGooglePayField"></div>
            </div>
        </div>
    </div>

    <!-- Credit Card -->
    <div class="payment-option">
        <label class="payment-header">
            <input type="radio" name="payment" value="card" checked>
            <span>Credit Card</span>
            <img src="https://img.icons8.com/color/36/000000/visa.png" alt="Visa" />
            <img src="https://img.icons8.com/color/36/000000/mastercard-logo.png" alt="Mastercard" />
        </label>
        <div class="payment-details" id="card">
            <div class="note">
                <div id="checkoutCardField"></div>
            </div>
        </div>
    </div>

</div>