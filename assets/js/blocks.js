// import { registerPaymentMethod } from '@woocommerce/blocks-registry';
// import { __ } from '@wordpress/i18n';

// // 注册支付方法
// registerPaymentMethod( {
//     name: 'paykka', // 支付网关 ID
//     label: __( 'PayKKa Gateway', 'woocommerce' ), // 支付方法标签
//     content: <div>{ __( 'Pay via Custom Gateway', 'woocommerce' ) }</div>, // 支付方法内容
//     edit: <div>{ __( 'Pay via Custom Gateway', 'woocommerce' ) }</div>, // 编辑模式下的内容
//     canMakePayment: () => true, // 支付方法是否可用
//     ariaLabel: __( 'PayKKa Gateway', 'woocommerce' ), // ARIA 标签
//     supports: {
//         features: [ 'products' ], // 支持的支付功能
//     },
// } );


(()=>{
    "use strict";
    const e=window.wp.element,
        t=window.wp.i18n,
        n=window.wc.wcBlocksRegistry,
        s=window.wp.htmlEntities,
        a=window.wc.wcSettings,
        l=(0,a.getSetting)("paykka_data",{}),
        o=(0,t.__)("paykka","paykka-for-woocommerce"),
        c=(0,s.decodeEntities)(l.title)||o,
        w=()=>(0,s.decodeEntities)(l.description||""),
        y={
            name:"paykka",
            label:(0,e.createElement)(
                (t=> {const{PaymentMethodLabel:n}=t.components;
                        return(0,e.createElement)(n,{text:c})
                }),null),
                content:(0,e.createElement)(w,null),
                edit:(0,e.createElement)(w,null),
                canMakePayment:()=>!0,
                ariaLabel:c,
                supports:{features:l.supports}
        };
        (0,n.registerPaymentMethod)(y)
})();