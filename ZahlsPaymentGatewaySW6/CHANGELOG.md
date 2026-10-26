# Changelog

## [2.1.18]
### Security
- Webhook: a transaction is only applied to an order when it was made on one of the order's zahls.ch gateways, and a confirmed payment must cover the order amount.

## [2.1.17]
### Improvement
- Removed the "Platform" setting: zahls.ch is the only platform.

## [2.1.16]
### Fix
- Installable via ZIP upload (no composer requirement on zahls/zahls-php).
- Plugin settings and webhook crashes fixed.

## [2.1.15]
### Improvement
- Initial zahls.ch release for Shopware 6.
