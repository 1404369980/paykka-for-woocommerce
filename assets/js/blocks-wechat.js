(() => {
    'use strict';
    const el = window.wp.element;
    const { __ } = window.wp.i18n;
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { decodeEntities } = window.wp.htmlEntities;
    const { getSetting } = window.wc.wcSettings;
    const settings = getSetting('paykka-wechat_data', {});
    const defaultLabel = __('WeChat Pay', 'paykka-for-woocommerce');
    const label = decodeEntities(settings.title) || defaultLabel;
    const Content = () => decodeEntities(settings.description || '');

    registerPaymentMethod({
        name: 'paykka-wechat',
        label: el.createElement(
            (props) => {
                const { PaymentMethodLabel } = props.components;
                return el.createElement(PaymentMethodLabel, { text: label });
            },
            null
        ),
        content: el.createElement(Content, null),
        edit: el.createElement(Content, null),
        canMakePayment: () => true,
        ariaLabel: label,
        supports: { features: settings.supports },
    });
})();
