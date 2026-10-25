# zahls.ch Payment Gateway for Shopware 6

Accept TWINT, cards, PostFinance and more in Shopware 6 via [zahls.ch](https://www.zahls.ch).

## Requirements

- Shopware 6.6 / 6.7
- PHP 8+
- zahls.ch account

## Install

1. Download [`ZahlsPaymentGatewaySW6.zip`](https://github.com/zahls/zahls-plugin-shopware6/releases/latest/download/ZahlsPaymentGatewaySW6.zip) from the [latest release](https://github.com/zahls/zahls-plugin-shopware6/releases/latest).
2. Install it under Shopware Admin → Extensions → Upload extension.
3. Configure:
   - **Instance name:** subdomain of your account (`example` for `example.zahls.ch`)
   - **API Key:** from zahls.ch → API & Integrations
4. Activate payment methods and set the webhook URL in your zahls.ch dashboard.

## License

MIT

