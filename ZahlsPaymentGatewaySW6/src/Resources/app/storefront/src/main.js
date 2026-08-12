// Import all necessary Storefront plugins
import GooglePay from './GooglePay/google-pay';
import ApplePay from './ApplePay/apple-pay';

// Register the plugin via the existing PluginManager
const PluginManager = window.PluginManager;
PluginManager.register('GooglePay', GooglePay, '[data-zahls-google-pay]');
PluginManager.register('ApplePay', ApplePay, '[data-zahls-apple-pay');
