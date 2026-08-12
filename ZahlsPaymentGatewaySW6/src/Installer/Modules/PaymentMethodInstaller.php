<?php


namespace ZahlsPaymentGateway\Installer\Modules;

use ZahlsPaymentGateway\Installer\InstallerInterface;
use Shopware\Core\Framework\Context;
use ZahlsPaymentGateway\Handler\PaymentHandler;
use ZahlsPaymentGateway\ZahlsPaymentGatewaySW6;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

class PaymentMethodInstaller implements InstallerInterface
{

    public const ZAHLS_MASTERPASS         = 'masterpass';
    public const ZAHLS_MASTERCARD         = 'mastercard';
    public const ZAHLS_VISA               = 'visa';
    public const ZAHLS_APPLE_PAY          = 'apple_pay';
    public const ZAHLS_MAESTRO            = 'maestro';
    public const ZAHLS_JCB                = 'jcb';
    public const ZAHLS_AMEX               = 'american_express';
    public const ZAHLS_WIR                = 'wirpay';
    public const ZAHLS_PAYPAL             = 'paypal';
    public const ZAHLS_BITCOIN            = 'bitcoin';
    public const ZAHLS_AIRPLUS            = 'airplus';
    public const ZAHLS_BILLPAY            = 'billpay';
    public const ZAHLS_BONUSCARD          = 'bonuscard';
    public const ZAHLS_CASHU              = 'cashu';
    public const ZAHLS_CB                 = 'cb';
    public const ZAHLS_DINERS             = 'diners_club';
    public const ZAHLS_DIRECT_DEBIT       = 'direct_debit';
    public const ZAHLS_DISCOVER           = 'discover';
    public const ZAHLS_ELV                = 'elv';
    public const ZAHLS_IDEAL              = 'ideal';
    public const ZAHLS_INVOICE            = 'invoice';
    public const ZAHLS_MYONE              = 'myone';
    public const ZAHLS_PAYSAFECARD        = 'paysafecard';
    public const ZAHLS_SWISSBILLING       = 'swissbilling';
    public const ZAHLS_TWINT              = 'twint';
    public const ZAHLS_VIACASH            = 'barzahlen';
    public const ZAHLS_BANCONTANCT        = 'bancontact';
    public const ZAHLS_GIROPAY            = 'giropay';
    public const ZAHLS_EPS                = 'eps';
    public const ZAHLS_GOOGLE_PAY         = 'google_pay';
    public const ZAHLS_KLARNA_PAYNOW      = 'klarna_paynow';
    public const ZAHLS_KLARNA_PAYLATER    = 'klarna_paylater';
    public const ZAHLS_ONEY               = 'oney';
    public const ZAHLS_BOB_INVOICE        = 'bob-invoice';
    public const ZAHLS_CENTI              = 'centi';
    public const ZAHLS_HEIDIPAY           = 'heidipay';
    public const ZAHLS_REKA               = 'reka';
    public const ZAHLS_BANK_TRANSFER      = 'bank-transfer';
    public const ZAHLS_KLARNA             = 'klarna';
    public const ZAHLS_POSTFINANCE_PAY    = 'post-finance-pay';
    public const ZAHLS_PRE_PAYMENT        = 'pre-payment';
    public const ZAHLS_PAY_BY_BANK        = 'pay-by-bank';
    public const ZAHLS_POWERPAY           = 'powerpay';
    public const ZAHLS_CEMBRAPAY          = 'cembrapay';
    public const ZAHLS_CRYPTO             = 'crypto';
    public const ZAHLS_VERD_CASH          = 'verd-cash';
    public const ZAHLS_NO_PM              = '';

    const PAYMENT_METHODS = [
        self::ZAHLS_MASTERPASS => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Masterpass',
                ],
                'en-GB' => [
                    'name' => 'Masterpass',
                ],
            ],
        ],
        self::ZAHLS_MASTERCARD => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Mastercard',
                ],
                'en-GB' => [
                    'name' => 'Mastercard',
                ],
            ],
        ],
        self::ZAHLS_VISA => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Visa',
                ],
                'en-GB' => [
                    'name' => 'Visa',
                ],
            ],
        ],
        self::ZAHLS_APPLE_PAY => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Apple Pay',
                ],
                'en-GB' => [
                    'name' => 'Apple Pay',
                ],
            ],
        ],
        self::ZAHLS_MAESTRO => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Maestro',
                ],
                'en-GB' => [
                    'name' => 'Maestro',
                ],
            ],
        ],
        self::ZAHLS_JCB => [
            'translations' => [
                'de-DE' => [
                    'name' => 'JCB',
                ],
                'en-GB' => [
                    'name' => 'JCB',
                ],
            ],
        ],
        self::ZAHLS_AMEX => [
            'translations' => [
                'de-DE' => [
                    'name' => 'American Express',
                ],
                'en-GB' => [
                    'name' => 'American Express',
                ],
            ],
        ],
        self::ZAHLS_WIR => [
            'translations' => [
                'de-DE' => [
                    'name' => 'WIRpay',
                ],
                'en-GB' => [
                    'name' => 'WIRpay',
                ],
            ],
        ],
        self::ZAHLS_PAYPAL => [
            'translations' => [
                'de-DE' => [
                    'name' => 'PayPal',
                ],
                'en-GB' => [
                    'name' => 'PayPal',
                ],
            ],
        ],
        self::ZAHLS_BITCOIN => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Bitcoin',
                ],
                'en-GB' => [
                    'name' => 'Bitcoin',
                ],
            ],
        ],
        self::ZAHLS_AIRPLUS => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Airplus',
                ],
                'en-GB' => [
                    'name' => 'Airplus',
                ],
            ],
        ],
        self::ZAHLS_BILLPAY => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Billpay',
                ],
                'en-GB' => [
                    'name' => 'Billpay',
                ],
            ],
        ],
        self::ZAHLS_BONUSCARD => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Bonus card',
                ],
                'en-GB' => [
                    'name' => 'Bonus card',
                ],
            ],
        ],
        self::ZAHLS_CASHU => [
            'translations' => [
                'de-DE' => [
                    'name' => 'CashU',
                ],
                'en-GB' => [
                    'name' => 'CashU',
                ],
            ],
        ],
        self::ZAHLS_CB => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Carte Bleue',
                ],
                'en-GB' => [
                    'name' => 'Carte Bleue',
                ],
            ],
        ],
        self::ZAHLS_DINERS => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Diners Club',
                ],
                'en-GB' => [
                    'name' => 'Diners Club',
                ],
            ],
        ],
        self::ZAHLS_DIRECT_DEBIT => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Direct Debit',
                ],
                'en-GB' => [
                    'name' => 'Direct Debit',
                ],
            ],
        ],
        self::ZAHLS_DISCOVER => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Discover',
                ],
                'en-GB' => [
                    'name' => 'Discover',
                ],
            ],
        ],
        self::ZAHLS_ELV => [
            'translations' => [
                'de-DE' => [
                    'name' => 'ELV',
                ],
                'en-GB' => [
                    'name' => 'ELV',
                ],
            ],
        ],
        self::ZAHLS_IDEAL => [
            'translations' => [
                'de-DE' => [
                    'name' => 'iDEAL',
                ],
                'en-GB' => [
                    'name' => 'iDEAL',
                ],
            ],
        ],
        self::ZAHLS_INVOICE => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Invoice',
                ],
                'en-GB' => [
                    'name' => 'Invoice',
                ],
            ],
        ],
        self::ZAHLS_MYONE => [
            'translations' => [
                'de-DE' => [
                    'name' => 'My One',
                ],
                'en-GB' => [
                    'name' => 'My One',
                ],
            ],
        ],
        self::ZAHLS_PAYSAFECARD => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Paysafe Card',
                ],
                'en-GB' => [
                    'name' => 'Paysafe Card',
                ],
            ],
        ],
        self::ZAHLS_SWISSBILLING => [
            'translations' => [
                'de-DE' => [
                    'name' => 'SwissBilling',
                ],
                'en-GB' => [
                    'name' => 'SwissBilling',
                ],
            ],
        ],
        self::ZAHLS_TWINT => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Twint',
                ],
                'en-GB' => [
                    'name' => 'Twint',
                ],
            ],
        ],
        self::ZAHLS_VIACASH => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Barzahlen',
                ],
                'en-GB' => [
                    'name' => 'Viacash',
                ],
            ],
        ],
        self::ZAHLS_BANCONTANCT => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Bancontact',
                ],
                'en-GB' => [
                    'name' => 'Bancontact',
                ],
            ],
        ],
        self::ZAHLS_GIROPAY => [
            'translations' => [
                'de-DE' => [
                    'name' => 'GiroPay',
                ],
                'en-GB' => [
                    'name' => 'GiroPay',
                ],
            ],
        ],
        self::ZAHLS_EPS => [
            'translations' => [
                'de-DE' => [
                    'name' => 'EPS',
                ],
                'en-GB' => [
                    'name' => 'EPS',
                ],
            ],
        ],
        self::ZAHLS_GOOGLE_PAY => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Google Pay',
                ],
                'en-GB' => [
                    'name' => 'Google Pay',
                ],
            ],
        ],
        self::ZAHLS_KLARNA_PAYNOW => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Klarna Sofortüberweisung',
                ],
                'en-GB' => [
                    'name' => 'Klarna Pay Now',
                ],
            ],
        ],
        self::ZAHLS_KLARNA_PAYLATER => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Klarna Rechnung',
                ],
                'en-GB' => [
                    'name' => 'Klarna Pay Later',
                ],
            ],
        ],
        self::ZAHLS_ONEY => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Oney',
                ],
                'en-GB' => [
                    'name' => 'Oney',
                ],
            ],
        ],
        self::ZAHLS_BOB_INVOICE => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Bob Invoice',
                ],
                'en-GB' => [
                    'name' => 'Bob Invoice',
                ],
            ]
        ],
        self::ZAHLS_CENTI => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Centi',
                ],
                'en-GB' => [
                    'name' => 'Centi',
                ],
            ],
        ],
        self::ZAHLS_HEIDIPAY => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Heidipay',
                ],
                'en-GB' => [
                    'name' => 'Heidipay',
                ],
            ],
        ],
        self::ZAHLS_REKA => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Reka',
                ],
                'en-GB' => [
                    'name' => 'Reka',
                ],
            ],
        ],
        self::ZAHLS_BANK_TRANSFER => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Kauf auf Rechnung',
                ],
                'en-GB' => [
                    'name' => 'Purchase on invoice',
                ],
            ],
        ],
        self::ZAHLS_KLARNA => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Klarna',
                ],
                'en-GB' => [
                    'name' => 'Klarna',
                ],
            ],
        ],
        self::ZAHLS_POSTFINANCE_PAY => [
            'translations' => [
                'de-DE' => [
                    'name' => 'PostFinance Pay',
                ],
                'en-GB' => [
                    'name' => 'PostFinance Pay',
                ],
            ],
        ],
        self::ZAHLS_PRE_PAYMENT => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Vorkasse',
                ],
                'en-GB' => [
                    'name' => 'Pre-Payment',
                ],
            ],
        ],
        self::ZAHLS_PAY_BY_BANK => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Pay by Bank',
                ],
                'en-GB' => [
                    'name' => 'Pay by Bank',
                ],
            ],
        ],
        self::ZAHLS_POWERPAY => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Powerpay',
                ],
                'en-GB' => [
                    'name' => 'Powerpay',
                ],
            ],
        ],
        self::ZAHLS_CEMBRAPAY => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Kauf auf Rechnung (CembraPay)',
                ],
                'en-GB' => [
                    'name' => 'Purchase on Account (CembraPay)',
                ],
            ],
        ],
        self::ZAHLS_CRYPTO => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Crypto',
                ],
                'en-GB' => [
                    'name' => 'Crypto',
                ],
            ],
        ],
        self::ZAHLS_VERD_CASH => [
            'translations' => [
                'de-DE' => [
                    'name' => 'VERD.Cash',
                ],
                'en-GB' => [
                    'name' => 'VERD.Cash',
                ],
            ],
        ],
        self::ZAHLS_NO_PM => [
            'translations' => [
                'de-DE' => [
                    'name' => 'Zahlungsmittelauswahl nach Weiterleitung zum Zahlungsterminal',
                ],
                'en-GB' => [
                    'name' => 'Payment method selection after forwarding to the payment terminal',
                ],
            ],
        ],
    ];

    private EntityRepository $paymentMethodRepository;
    private PluginIdProvider $pluginIdProvider;

    public function __construct(
        EntityRepository $paymentMethodRepository,
        PluginIdProvider $pluginIdProvider
    ) {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->pluginIdProvider        = $pluginIdProvider;
    }

    /**
     * {@inheritdoc}
     */
    public function install(InstallContext $context): void
    {
        foreach (self::PAYMENT_METHODS as $zahlsPaymentMethodIdentifier => $zahlsPaymentMethod) {
            $this->upsertPaymentMethod($context->getContext(), $zahlsPaymentMethod, $zahlsPaymentMethodIdentifier);
        }
    }

    public function uninstall(UninstallContext $context): void
    {
        // Nothing to do, payment methods already deactivated
    }

    public function activate(ActivateContext $context): void
    {
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', PaymentHandler::class));
        $paymentMethods = $this->paymentMethodRepository->searchIds($paymentCriteria, Context::createDefaultContext());

        foreach ($paymentMethods->getIds() as $paymentMethodId) {
            $this->setPaymentMethodIsActive($context->getContext(), $paymentMethodId, true);
        }
    }

    public function deactivate(DeactivateContext $context): void
    {
        $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', PaymentHandler::class));
        $paymentMethods = $this->paymentMethodRepository->searchIds($paymentCriteria, Context::createDefaultContext());

        foreach ($paymentMethods->getIds() as $paymentMethodId) {
            $this->setPaymentMethodIsActive($context->getContext(), $paymentMethodId, false);
        }
    }

    public function update(UpdateContext $context): void {
        foreach (self::PAYMENT_METHODS as $zahlsPaymentMethodIdentifier => $zahlsPaymentMethod) {
            $this->upsertPaymentMethod($context->getContext(), $zahlsPaymentMethod, $zahlsPaymentMethodIdentifier);
        }
    }

    private function upsertPaymentMethod(Context $context, array $zahlsPaymentMethod, string $zahlsPaymentMethodIdentifier): void
    {
        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(ZahlsPaymentGatewaySW6::class, $context);

        $technicalName = PaymentHandler::PAYMENT_METHOD_PREFIX . $zahlsPaymentMethodIdentifier;
        $criteria = (new Criteria())
            ->addFilter(
                new OrFilter([
                    new EqualsFilter('technicalName', $technicalName),
                    new AndFilter([
                        new EqualsFilter('handlerIdentifier', PaymentHandler::class),
                        new EqualsFilter('customFields.zahls_payment_method_name', $technicalName)
                    ])
                ])
            )
            ->setLimit(1);
        $paymentMethods = $this->paymentMethodRepository->search($criteria, Context::createDefaultContext());

        $paymentMethodId = null;
        $paymentMethodActive = false;

        if ($paymentMethods->count()){
            $paymentMethod = $paymentMethods->getEntities()->first();
            $paymentMethodId = $paymentMethod->getId();
            $paymentMethodActive = $paymentMethod->getActive();
        }

        $options = [
            'id' => $paymentMethodId ?? Uuid::randomHex(),
            'handlerIdentifier' => PaymentHandler::class,
            'active' => $paymentMethodActive,
            'pluginId' => $pluginId,
            'technicalName' => $technicalName,
            'customFields' => [
                'zahls_payment_method_name' => $technicalName,
            ]
        ];

        if (!$paymentMethodId) {
            $options['afterOrderEnabled'] = true;
            $options['name'] = $zahlsPaymentMethod['translations']['en-GB']['name'];
            $options['translations'] = $zahlsPaymentMethod['translations'];
        }

        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($options): void {
            $this->paymentMethodRepository->upsert([$options], $context);
        });
    }

    private function setPaymentMethodIsActive(Context $context, $paymentMethodId, bool $active): void
    {
        $paymentMethod = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];
        $this->paymentMethodRepository->update([$paymentMethod], $context);
    }
}
