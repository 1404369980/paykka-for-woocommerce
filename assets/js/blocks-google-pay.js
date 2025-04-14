
(function () {
    const { createElement, useState, useEffect } = window.wp.element;

    if (typeof wc === 'undefined' || typeof wc.wcBlocksRegistry === 'undefined') {
        return;
    }

    // 获取必要的全局变量
    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = wc.wcSettings.getSetting;
    var decodeEntities = wp.htmlEntities.decodeEntities;
    var __ = wp.i18n.__;

    // 支付方法配置
    var PaykkaGooglePayMethod = {
        name: 'paykka-google-pay',
        label: decodeEntities('Google Pay (PayKKa)'),
        content: Object(createElement)(PaykkaGooglePayContent),
        edit: Object(createElement)(PaykkaGooglePayContent),
        canMakePayment: function () { return true; },
        ariaLabel: __('Google Pay via Paykka', 'paykka-google-pay'),
        supports: {
            features: ['products']
        }
    };

    // 支付内容组件
    function PaykkaGooglePayContent(props) {
        const [isAvailable, setIsAvailable] = useState();
        var [paymentGoogleData, setPaymentGoogleData] = useState();
        const { eventRegistration, emitResponse } = props;
        // const [paymentClient, setPaymentClient] = useState();
        var paymentClient = null;


        var paykkaData = window.paykkaGooglePayData || {};
        var merchantId = 18521974753296;
        var environment = paykkaData.environment || 'TEST';
        var buttonColor = paykkaData.buttonColor || 'black';
        var buttonType = paykkaData.buttonType || 'buy';
        var allowedCardNetworks = paykkaData.allowedCardNetworks || ['VISA', 'MASTERCARD'];
        var currencyCode = paykkaData.currencyCode || 'USD';
        var totalPrice = 100;

        useEffect(() => {
            const unsubscribe = eventRegistration.onPaymentProcessing(async () => {
                // await promise
                console.log('加密完成', JSON.stringify(paymentGoogleData));
                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            'payment_google_data': JSON.stringify(paymentGoogleData)
                            // 'payment_google_data': "{\"apiVersion\":2,\"apiVersionMinor\":0,\"paymentMethodData\":{\"description\":\"Test Card: Visa •••• 1111\",\"info\":{\"assuranceDetails\":{\"accountVerified\":true,\"cardHolderAuthenticated\":false},\"cardDetails\":\"1111\",\"cardNetwork\":\"VISA\"},\"tokenizationData\":{\"token\":\"{\\\"signature\\\":\\\"MEUCIQCRf5pbKP4spWNQC8EDJ2pyh2QS5lXCmhVWV6i4I4Dv2gIgJdGfas2ofDWz+2lNWihoHMeco2VC6SmA6+KfDfUOT3M\\\\u003d\\\",\\\"intermediateSigningKey\\\":{\\\"signedKey\\\":\\\"{\\\\\\\"keyValue\\\\\\\":\\\\\\\"MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEFSK9DWyD+KOWOJdEFfNjDsoQh3artsaTxJmfOP59C32SgGFCwMntsi6r4+IpZTsFYDIDly0YB7wL8pvMaU6HPA\\\\\\\\u003d\\\\\\\\u003d\\\\\\\",\\\\\\\"keyExpiration\\\\\\\":\\\\\\\"1745278382921\\\\\\\"}\\\",\\\"signatures\\\":[\\\"MEUCIGMwmX+pf8IT9kAIc01+K5SQw/1woHY6UTsVMr0e9uViAiEAhziO2H0i9WmCT3z6g3oJzdu6WzQaqmKcGlCSl6144/0\\\\u003d\\\"]},\\\"protocolVersion\\\":\\\"ECv2\\\",\\\"signedMessage\\\":\\\"{\\\\\\\"encryptedMessage\\\\\\\":\\\\\\\"kmF7MD+KUvmMtaDAhka35JSDHp7W2LrRjykKDx84HOJS+a2XyfMsBa5x+YBcUM1LEszzF88069qwYBmOh5WZTuz4LXdmjwiAcimXiG8yx5qTV3perfn8VaePmk8jQdJBpvsv2jEgNmMlR5tSHOdULVVJae4xErccJWnA3aFXpgfuawo84qooilOUe5gjAhhPFwMRWLI3jh0tnJoLHjZY5RwTkvdZXtT1tH55PFJfWVpuH7nTd29dJN8IXxCq5YFCzQRtuDYeWq0BkBrdFZx9O5P2nzwbJo1eUMKr82fQLVb+RTMrB9jz0cVYH1smVt91kAWtyiRAYrJdNCwP7E44h934/8MiRB36OesZss5nANnJU7durUbUkzErh7jNP4LDEEW7EbJ5JTLm17p8UnkhtbH4QxlQCAqhhb+fyKt2nf0tQ6LxC7O6e6vSwahuwMw+Ho92yqCl08/b7wMq2dJlhchJnS7ZvccA091UGLPKJuBusM02Znw8Fbd4RL+TQqVxBXvUv/ulBFs7W/C5ZpdS/KXePdBlflwQ/zGdNyjWc2yuXKuV9mLQCg\\\\\\\\u003d\\\\\\\\u003d\\\\\\\",\\\\\\\"ephemeralPublicKey\\\\\\\":\\\\\\\"BOjCtnpwQHLy3eOF5RYW1e69YM+nIj2BzuXs+gTid0AkRUiIcww0HLJp1mR8wjuv4F+tZ+4Qg0/gfG5vNNgA3WA\\\\\\\\u003d\\\\\\\",\\\\\\\"tag\\\\\\\":\\\\\\\"W3VyBouZw6xaBhWXL/rY+wLDeo8dWjMhJT1q0c3NEvU\\\\\\\\u003d\\\\\\\"}\\\"}\",\"type\":\"PAYMENT_GATEWAY\"},\"type\":\"CARD\"}}"
                        },
                    },
                };

            });
            return () => unsubscribe();
        }, [eventRegistration]);

        // 初始化效果
        useEffect(function () {
            // if (!window.google || !merchantId) return;

            function initGooglePay() {
                try {
                    var client = new google.payments.api.PaymentsClient({ environment: 'TEST' });

                    client.isReadyToPay({
                        apiVersion: 2,
                        apiVersionMinor: 0,
                        allowedPaymentMethods: [{
                            type: 'CARD',
                            parameters: {
                                allowedAuthMethods: ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
                                allowedCardNetworks: ['VISA', 'MASTERCARD']
                            }
                        }]
                    }).then(function (isReadyToPay) {
                        console.log("isReadyToPay", isReadyToPay)
                        setIsAvailable(isReadyToPay.result);
                        // setPaymentClient(client);
                        paymentClient = client;
                        console.log('client', client);
                        console.log('client', paymentClient);

                        if (isReadyToPay.result) {
                            renderButton(client);
                        }
                    }).catch(function (error) {
                        console.error('Google Pay readiness check failed:', error);
                    });

                } catch (error) {
                    console.error('Google Pay initialization error:', error);
                }
            }

            function renderButton(client) {
                var button = client.createButton({
                    onClick: handlePayment,
                    buttonColor: buttonColor,
                    buttonType: buttonType
                });

                var container = document.getElementById('paykka-googlepay-button-container');
                if (container) {
                    container.innerHTML = '';
                    container.appendChild(button);
                }
            }

            initGooglePay();

            // if (window.google) {

            // } else {
            //   var script = document.createElement('script');
            //   script.src = 'https://pay.google.com/gp/p/js/pay.js';
            //   script.async = true;
            //   script.onload = initGooglePay;
            //   document.body.appendChild(script);
            // }
        }, []);

        function handlePayment() {
            console.log('paymentClient');

            // if (!paymentClient) return;

            var paymentDataRequest = {
                apiVersion: 2,
                apiVersionMinor: 0,
                allowedPaymentMethods: [{
                    type: 'CARD',
                    parameters: {
                        allowedAuthMethods: ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
                        allowedCardNetworks: ['VISA', 'MASTERCARD']
                    },
                    tokenizationSpecification: {
                        type: 'PAYMENT_GATEWAY',
                        parameters: {
                            'gateway': 'paykkaeu',
                            'gatewayMerchantId': '18521974753296'
                        }
                    }
                }],
                merchantInfo: {
                    merchantName: 'Paykka Test Merchant 59'
                },
                transactionInfo: {
                    countryCode: 'DE',
                    currencyCode: 'EUR',
                    totalPriceStatus: 'FINAL',
                    // set to cart total
                    totalPrice: '1.00'
                }
            };

            paymentClient.loadPaymentData(paymentDataRequest)
                .then(function (paymentData) {
                    
                    setPaymentGoogleData(paymentData)
                    console.log('paymentData', JSON.stringify(paymentData));
                    // document.getElementById('paykka-googlepay-payment-data').value = JSON.stringify(paymentData);
                    document.querySelector('button.wc-block-components-checkout-place-order-button').click();
                })
                .catch(function (error) {
                    console.error('Google Pay error:', error);
                });
        }

        return createElement(
            'div',
            { className: 'paykka-googlepay-blocks' },
            createElement('input', {
                type: 'hidden',
                id: 'paykka-googlepay-payment-data',
                name: 'paykka_googlepay_payment_data'
            }),
            true
                ? createElement(
                    'div',
                    { id: 'paykka-googlepay-button-container' }
                )
                : createElement(
                    'p',
                    null,
                    __('Google Pay is not available', 'paykka-googlepay')
                )
        );
    }

    // 注册支付方法
    registerPaymentMethod(PaykkaGooglePayMethod);
})();