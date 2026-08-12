import './components/zahls-payment-settings-icon';
import './extension/sw-settings-index';
import './page/zahls-settings';

import deDE from './snippet/de_DE.json';
import deCH from './snippet/de_CH.json';
import enGB from './snippet/en_GB.json';


const { Module } = Shopware;

Module.register('zahls-payment', {
    type: 'plugin',
    name: 'ZahlsPayment',
    title: 'zahls-payment.module.title',
    description: 'zahls-payment.module.description',
    version: '1.0.0',
    targetVersion: '1.0.0',
    icon: 'regular-cog',

    snippets: {
        'de-DE': deDE,
        'de-CH': deCH,
        'en-GB': enGB
    },

    routes: {
        index: {
            component: 'zahls-settings',
            path: 'index',
            meta: {
                parentPath: 'sw.settings.index'
            }
        }
    },
    settingsItem: {
        name: 'zahls-payment-settings',
        group: 'plugins',
        to: 'zahls.payment.index',
        // iconComponent: 'zahls-payment-settings-icon',
        icon: 'regular-credit-card',
        backgroundEnabled: true,
    }
});
