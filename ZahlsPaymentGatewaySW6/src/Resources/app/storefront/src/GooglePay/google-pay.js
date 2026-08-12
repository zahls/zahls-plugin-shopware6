import Plugin from 'src/plugin-system/plugin.class';

export default class GooglePay extends Plugin {
    init() {
        jQuery("#zahls-googlepay").parent('.payment-method').hide();
        try {
            const baseRequest = {
                apiVersion: 2,
                apiVersionMinor: 0
            };
            const allowedCardNetworks = ['MASTERCARD', 'VISA'];
            const allowedCardAuthMethods = ['CRYPTOGRAM_3DS'];
            const baseCardPaymentMethod = {
                type: 'CARD',
                parameters: {
                    allowedAuthMethods: allowedCardAuthMethods,
                    allowedCardNetworks: allowedCardNetworks
                }
            };

            const isReadyToPayRequest = Object.assign({}, baseRequest);
            isReadyToPayRequest.allowedPaymentMethods = [
                baseCardPaymentMethod
            ];
            const paymentsClient = new google.payments.api.PaymentsClient(
                {
                    environment: 'TEST'
                }
            );
            paymentsClient.isReadyToPay(isReadyToPayRequest).then(function(response) {
                if (response.result) {
                    jQuery("#zahls-googlepay").parent('.payment-method').show();
                } else {
                    console.warn("zahls.ch Google pay is not supported on this device/browser");
                }
            }).catch(function(err) {
                console.log(err);
            });
        } catch (err) {
            console.log(err);
        }
    }
}
