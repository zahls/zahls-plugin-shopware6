import template from './platform-connect-button.html.twig';

const {Component, Mixin} = Shopware;

Component.register('platform-connect-button', {
    template,

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: false,
            isEnabled: false,
            platform: '',
        }
    },

    created() {
        try {
            const systemConfigApiService = Shopware.Service('systemConfigApiService');
            systemConfigApiService.getValues('ZahlsPaymentGatewaySW6.settings').then(config => {
                if (config && config['ZahlsPaymentGatewaySW6.settings.platform']) {
                    this.platform = config['ZahlsPaymentGatewaySW6.settings.platform'];
                    this.isEnabled = true;
                } else {
                    this.isEnabled = false;
                }
            });
        } catch (error) {
            this.isEnabled = false;
        }

        window.addEventListener('message', this.handleMessage);

        // Listen for change of zahls config (handle reactive changes)
        window.addEventListener('zahls-config-change', (event) => {
            if (event.detail['ZahlsPaymentGatewaySW6.settings.platform']) {
                this.platform = event.detail['ZahlsPaymentGatewaySW6.settings.platform'];
                this.isEnabled = true;
            }
        })
    },

    beforeDestroy() {
        window.removeEventListener('message', this.handleMessage);
    },

    methods: {
        async onButtonClick() {
            this.isLoading = true;
            await this.createPopupWindow();
        },

        async createPopupWindow() {
            const popupWidth = 900;
            const popupHeight = 900;

            // Get the parent window's size and position
            const dualScreenLeft = window.screenLeft !== undefined ? window.screenLeft : window.screenX;
            const dualScreenTop = window.screenTop !== undefined ? window.screenTop : window.screenY;

            const width = window.innerWidth
                ? window.innerWidth
                : document.documentElement.clientWidth
                    ? document.documentElement.clientWidth
                    : screen.width;
            const height = window.innerHeight
                ? window.innerHeight
                : document.documentElement.clientHeight
                    ? document.documentElement.clientHeight
                    : screen.height;

            const left = dualScreenLeft + (width - popupWidth) / 2;
            const top = dualScreenTop + (height - popupHeight) / 2;

            const params = `width=${popupWidth},height=${popupHeight},top=${top},left=${left},resizable=no,scrollbars=yes`

            if (!this.platform) {
                const systemConfigApiService = Shopware.Service('systemConfigApiService');
                const systemConfig = await systemConfigApiService.getValues('ZahlsPaymentGatewaySW6.settings');
                this.platform = systemConfig['ZahlsPaymentGatewaySW6.settings.platform'];
            }

            if (!this.platform) {
                this.createNotificationError({
                    title: this.$tc('zahls-payment.settingsForm.connectButton.messages.noPlatform.title'),
                    message: this.$tc('zahls-payment.settingsForm.connectButton.messages.noPlatform.message'),
                })
                return;
            }

            const url = `https://login.${this.platform}?action=connect&plugin_id=24`;

            let popupWindow = window.open(url, 'Connect    zahls', params);
            let popupCheck;
            popupCheck = setInterval(() => {
                if (popupWindow.closed) {
                    popupWindow = null;
                    if (this.isLoading) {
                        this.createNotificationWarning({
                            title: this.$tc('zahls-payment.settingsForm.connectButton.messages.cancelled.title'),
                            message: this.$tc('zahls-payment.settingsForm.connectButton.messages.cancelled.message'),
                        });
                    }
                    this.isLoading = false;
                    clearInterval(popupCheck);
                }
            }, 500)
        },

        handleMessage(event) {
            this.isLoading = false;

            if (!event.data || !event.data.instance) {
                return;
            }
            const data = event.data;

            try {
                this.storePayload(data.instance);
                this.setFieldValues(data.instance);
                this.createNotificationSuccess({
                    title: this.$tc('zahls-payment.settingsForm.connectButton.messages.success.title'),
                    message: this.$tc('zahls-payment.settingsForm.connectButton.messages.success.message'),
                });
            } catch (error) {
                this.createNotificationError({
                    title: this.$tc('zahls-payment.settingsForm.connectButton.messages.error.title'),
                    message: this.$tc('zahls-payment.settingsForm.connectButton.messages.error.message', {message: error.message}),
                });
            }
        },

        storePayload(instance) {
            const systemConfigApiService = Shopware.Service('systemConfigApiService');
            systemConfigApiService.saveValues({
                'ZahlsPaymentGatewaySW6.settings.apiKey': instance.apikey,
                'ZahlsPaymentGatewaySW6.settings.instanceName': instance.name,
                'ZahlsPaymentGatewaySW6.settings.platform': this.platform,
            });
        },

        setFieldValues(instance) {
            const instanceNameField = document.getElementById('ZahlsPaymentGatewaySW6.settings.instanceName');
            if (instanceNameField) {
                instanceNameField.value = instance.name;
            }
            const apiKeyField = document.getElementById('ZahlsPaymentGatewaySW6.settings.apiKey');
            if (apiKeyField) {
                apiKeyField.value = instance.apikey;
            }
        }
    },
});