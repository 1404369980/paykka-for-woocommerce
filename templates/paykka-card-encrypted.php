<?php
if (!defined('ABSPATH')) {
    exit; // 确保安全性
} ?>


<!-- 通过插入样式和脚本来显示支付组件 -->
<!-- PAYKKA IFRAME -->
<link href="https://checkout-sandbox.aq.paykka.com/cp/style.css" rel="stylesheet" />
<script type="text/javascript" src="https://checkout-sandbox.aq.paykka.com/cp/encrypted-card.js"></script>

<div id="paykka_payform" style="display:flex;flex-direction: column;align-items: center;padding-bottom: 20px">
    <div id="encryptedCardWrapper">
        <!-- 卡号 -->
        <div data-eci="cardNumber" style="width:500px;padding-bottom: 20px"></div>
        <!-- 有效期 -->
        <div data-eci="expiryDate" style="width:500px;padding-bottom: 20px"></div>
        <!-- CVV -->
        <div data-eci="securityCode" style="width:500px;padding-bottom: 20px"></div>
        <button
            class="paykka-card-checkout paykka-card-checkout-button paykka-card-checkout-submit-button paykka-card-checkout-card__button"
            onclick="handleClick()" style="width:500px">支付</button>
    </div>
    <div id="error_message" style="width:500px;color:red;"></div>
</div>



<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function () {




        const PayKKaEncryptedCard = window.PayKKaCardCheckoutEncryptedCard || window.PaykkaCardCheckoutEncryptedCard;

        PayKKaEncryptedCard.setEnv({
            apiUrl: 'https://checkout-fat.eu.paykka.com',
            cdnUrl: 'https://checkout-fat.eu.paykka.com/cp',
        });

        let encryptedRes = null;
        const EncryptedCard = PayKKaEncryptedCard.init({
            //      sandbox: true,
            merchantId: '18521974753296',
            clientKey: 'ck_a2cfcb80674e61fc494884cc3ccbaf67',
            showLabel: true,
            styles: {
                input: {
                    base: {
                        fontSize: '16px',
                    },
                    focus: {
                        color: 'blue',
                    },
                    valid: {
                        border: '1px solid yellowgreen',
                        color: 'yellowgreen',
                    },
                    invalid: {
                        border: '1px solid red',
                        color: 'red',
                    }
                }
            },
            onCardEncrypted: (res) => {
                encryptedRes = res;
                processPay();
            }
        });

        // 防抖控制相关变量
        let isProcessing = false;
        // 获取按钮引用
        const payButton = document.querySelector('#encryptedCardWrapper button');

        window.handleClick = function () {
            console.log("点击支付按钮，开始加密...");
            // 禁用按钮并添加状态
            isProcessing = true;
            payButton.disabled = true;
            payButton.style.opacity = '0.7';
            payButton.textContent = '处理中...';
            // 加密
            EncryptedCard.encrypt();
        };

        window.processPay = function () {
            // 调用支付接口并将加密信息传入
            console.log("调用支付接口，传输加密数据:", encryptedRes);

            // 自定义参数
            let data = encryptedRes;

            // 发送 AJAX 请求
            fetch('<?php echo rest_url('/paykka/v1/encrypted_card') ?>', {
                // fetch('/wp-json/paykka/v1/encrypted_card', {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    encrypted_card_data: encryptedRes,
                    order_id: <?php echo $_GET['order_id'] ?> // 实际订单 ID
                })
            })
                .then(response => response.json())
                .then(result => {
                    console.error("result:", result)
                    if (result.success) {
                        window.location.href = result.redirect_url;
                    } else {
                        document.getElementById("error_message").textContent = result.message;
                        isProcessing = false;
                        payButton.disabled = false;
                        payButton.style.opacity = '1';
                        payButton.textContent = '支付';
                    }
                }).catch(error => {
                    document.getElementById("error_message").textContent = error;
                    console.error("请求失败:", error)
                    isProcessing = false;
                    payButton.disabled = false;
                    payButton.style.opacity = '1';
                    payButton.textContent = '支付';
                });
        };

    });

</script>