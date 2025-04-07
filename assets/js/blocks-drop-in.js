// // ✅ 正确引入 React 方法
// const { createElement } = window.wp.element; 
// const { registerPaymentMethod } = window.wc.wcBlocksRegistry;

// console.log('wp.element:', window.wp.element);
// console.log('wcBlocksRegistry:', window.wc.wcBlocksRegistry);

// const PayKKaGateway = {
//   name: 'paykka-drop-in',
//   label: 'PayKKa Drop In',
//   ariaLabel: 'PayKKa Drop In Payment Method',
//   content: () => () => createElement('div', null, '支付表单内容'), // ✅ 双重函数包裹
//   edit: () => () => createElement('div', null, '后台配置界面'),    // ✅ 双重函数包裹
//   canMakePayment: () => true
// };

// // ✅ 确保在 DOM 加载后执行
// window.addEventListener('DOMContentLoaded', () => {
//   registerPaymentMethod(PayKKaGateway);
// });

// <link href="https://checkout-fat.eu.paykka.com/cp/style.css" rel="stylesheet" />
const link = document.createElement('link')
link.href = 'https://checkout-fat.eu.paykka.com/cp/style.css'
link.rel = 'stylesheet'
const body = document.querySelector('body')
console.log('body', body)
body.appendChild(link)

const PayKKaEncryptedCard = window.PayKKaCardCheckoutEncryptedCard || window.PaykkaCardCheckoutEncryptedCard;
// const { createElement, useState, useEffect } = React;
const { createElement, useState, useEffect } = window.wp.element;

var { registerPlugin } = wp.plugins;
var { ExperimentalOrderMeta } = wc.blocksCheckout;
var { registerExpressPaymentMethod, registerPaymentMethod } = wc.wcBlocksRegistry;
// const { useDispatch } = wc.wcData;
const { useDispatch, useSelect } = wp.data; // 现在可以正常解构
const { useStoreCart } = wc.blocksCheckout;
// const { useSelect, useDispatch } = wp.data;



PayKKaEncryptedCard.setEnv({
    apiUrl: 'https://checkout-fat.eu.paykka.com',
    cdnUrl: 'https://checkout-fat.eu.paykka.com/cp',
});

(() => {
    "use strict";
    // 支付表单组件
    const PaymentForm = (props) => {
        const [encryptedData, setEncryptedData] = useState(null);
        const [error, setError] = useState('');

        const { eventRegistration} = props;
        // const { extensionData, setExtensionData } = useCheckoutSubmit();



        useEffect(() => {
            const unsubscribe = eventRegistration.onPaymentSetup(() => {
                console.log("知乎")
                EncryptedCard.encrypt();
                // 发送 AJAX 请求
                return {
                    type: 'PAYMENT_METHOD_SETUP',
                    meta: {
                        payment_method: 'paykka-drop-in',
                        // encrypted_data: 'encryptedData',
                        // card_last4: '42424111111111'
                        // ,user_id: get_current_user_id()
                        // 其他支付网关需要的元数据
                    }
                };

            });
            return () => unsubscribe();
        }, [eventRegistration]);


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
                setEncryptedData(res);
                console.log("调用支付接口，传输加密数据:", res);

                fetch('http://10.199.101.49:8018/index.php?rest_route=/paykka/v1/drop-in/session', {
                    // fetch('/wp-json/paykka/v1/encrypted_card', {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "X-WP-Nonce": wpApiSettings.nonce
                    },
                    body: JSON.stringify({
                        encrypted_card_data: res
                        // ,user_id: get_current_user_id()
                    })
                })
                    .then(response => response.json())
                    .then(result => {
                        console.error("result:", result)
                        if (result.success) {
                            // window.location.href = result.redirect_url;
                        } else {
                            document.getElementById("error_message").textContent = result.message;
                        }
                    }).catch(error => {
                        document.getElementById("error_message").textContent = error;
                        console.error("请求失败:", error)
                    });
            }
        });

        // 创建支付表单结构
        return createElement('div', {
            id: 'paykka_payform',
            style: {
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                paddingBottom: '20px'
            }
        }, [
            createElement('div', {
                id: 'encryptedCardWrapper',
                key: 'cardWrapper'
            }, [
                // 卡号输入
                createElement('div', {
                    'data-eci': 'cardNumber',
                    key: 'cardNumber',
                    // style: { width: '500px', paddingBottom: '20px' }
                }),

                // 有效期输入
                createElement('div', {
                    'data-eci': 'expiryDate',
                    key: 'expiryDate',
                    // style: { width: '500px', paddingBottom: '20px' }
                }),

                // CVV输入
                createElement('div', {
                    'data-eci': 'securityCode',
                    key: 'securityCode',
                    // style: { width: '500px', paddingBottom: '20px' }
                }),

                // 支付按钮
                // createElement('button', {
                //     className: 'paykka-card-checkout paykka-card-checkout-button paykka-card-checkout-submit-button paykka-card-checkout-card__button',
                //     onClick: handlePayment,
                //     disabled: isProcessing,
                //     key: 'submitButton',
                //     // style: {
                //     //     width: '500px',
                //     //     backgroundColor: isProcessing ? '#ccc' : '#4CAF50',
                //     //     cursor: isProcessing ? 'wait' : 'pointer'
                //     // }
                // }, isProcessing ? '处理中...' : '支付')
            ]),

            // 错误信息显示
            error && createElement('div', {
                id: 'error_message',
                key: 'errorMessage',
                // style: { width: '500px', color: 'red' }
            }, error)
        ]);
    };

    const e = window.wp.element,
        t = window.wp.i18n,
        n = window.wc.wcBlocksRegistry,
        s = window.wp.htmlEntities,
        a = window.wc.wcSettings,
        l = (0, a.getSetting)("paykka-drop-in_data", {}),
        o = (0, t.__)("paykka-drop-in", "paykka-for-woocommerce"),
        c = (0, s.decodeEntities)(l.title) || o,
        w = () => (0, s.decodeEntities)(l.description || ""),

        y = {
            name: "paykka-drop-in",
            paymentMethodId: 'paykka-drop-in',
            label: (0, e.createElement)(
                (t => {
                    const { PaymentMethodLabel: n } = t.components;
                    return (0, e.createElement)(n, { text: c })
                }), null),
            // content: Object(createElement)(PaymentForm, null),
            content: createElement(PaymentForm, null),
            edit: Object(createElement)(PaymentForm, null),
            canMakePayment: () => !0,
            ariaLabel: c,
            supports: { features: l.supports }
        };
    (0, n.registerPaymentMethod)(y)
})()