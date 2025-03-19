<?php
get_header();


if (!defined('ABSPATH')) {
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh">

<head>
    <!-- 通过插入样式和脚本来显示支付组件 -->
    <link href="https://checkout-sandbox.aq.paykka.com/cp/style.css" rel="stylesheet" />
    <script type="text/javascript" src="https://checkout-sandbox.aq.paykka.com/cp/card-checkout-ui.js"></script>


    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            const props = {
                showCardBrands: false,
                onSubmit: (formValidateError) => {
                    // Handle submit logic here
                },
                onSuccess: () => {
                    window.location.href = "{$return_url}";
                },
                onExpired: () => {
                    window.location.replace("{$return_url}");
                }
            };

            // Initialize and create CheckoutCard instance

            console.log("PaykkaCardCheckoutUI", PaykkaCardCheckoutUI);

            const { Card, ApplePay, GooglePay, setRadarEnv, PayKKaCheckout } = PaykkaCardCheckoutUI;

            const paykkaCheckout = new PayKKaCheckout({
                sessionId: '<?php echo esc_html(WC()->session->get('paykka_session_id')) ?>',
                clientKey: '<?php echo esc_html(WC()->session->get('paykka_client_key')) ?>',
                // sessionId: 'CS205752350276680803',
                // clientKey: 'ck_945bb1f80011be2e932c1651dda8bb39',
                hidePaymentButton: false, // 隐藏按钮
                sandbox: true
            })

            setRadarEnv({
                STRIPE_RADAR: 'pk_test_51QaC2P5VarcojPHdg13yagk5TqrGkIkeK8I21BgQUZe8BzyRmbtmOg3dKsXjkxt6JlsjyjJMTvBH9dFMCZWRxOkt00tWQ1eHFU'
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

        });

    </script>
</head>

<body>

    <!-- 创建一个容器元素来展示支付组件 -->
    <div id="paykka_payform" style="display:flex;flex-direction: column;align-items: center;padding-bottom: 20px">
        <div id="checkoutApplePayField" style="width:500px;padding-bottom: 20px"></div>
        <div id="checkoutGooglePayField" style="width:500px;padding-bottom: 20px"></div>
        <div id="checkoutCardField" style="width:500px;padding-bottom: 20px"></div>
    </div>

</body>

</html>


<?php get_footer(); ?>