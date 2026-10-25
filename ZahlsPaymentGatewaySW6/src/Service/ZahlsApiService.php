<?php

namespace ZahlsPaymentGateway\Service;

use Zahls\Communicator;
use Zahls\Models\Response\Gateway;
use Zahls\Models\Response\Transaction;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

class ZahlsApiService
{
    protected EntityRepository $customerRepository;
    protected LoggerInterface $logger;
    protected ConfigService $configService;

    public function __construct(EntityRepository $customerRepository, LoggerInterface $logger, ConfigService $configService)
    {
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
        $this->configService = $configService;
    }

    private function getInterface(string $salesChannelId): \Zahls\Zahls
    {
        $config = $this->configService->getPluginConfiguration($salesChannelId);
        return new \Zahls\Zahls($config['instanceName'], $config['apiKey']);
    }

    /**
     * Create a checkout page in    zahls (zahls.ch Gateway)
     */
    public function createZahlsGateway(
        string $orderNumber,
        float $amount,
        float $averageVatRate,
        string $currency,
        string $paymentMean,
        array $billingAndShippingDetails,
        array $redirectUrl,
        array $basket,
        string $salesChannelId,
        string $purpose,
        array $metaData
    ): ?Gateway  {
        $config = $this->configService->getPluginConfiguration($salesChannelId);
        $lookAndFeelId = !empty($config['lookFeelID']) ? $config['lookFeelID'] : null;

        $zahls = $this->getInterface($salesChannelId);
        $gateway = new \Zahls\Models\Request\Gateway();
        $amount = number_format($amount, 2, '.', '');
        $amountInCents = (int) str_replace('.', '', $amount);
        $gateway->setAmount($amountInCents);
        $gateway->setVatRate($averageVatRate);
        $gateway->setCurrency($currency);
        $gateway->setSuccessRedirectUrl($redirectUrl['success']);
        $gateway->setFailedRedirectUrl($redirectUrl['cancel']);
        $gateway->setCancelRedirectUrl($redirectUrl['cancel']);
        $gateway->setSkipResultPage(true);
        $gateway->setLookAndFeelProfile($lookAndFeelId);

        $gateway->setPsp([]);
        $gateway->setPm([$paymentMean]);
        $gateway->setReferenceId($orderNumber);
        $gateway->setValidity(15);

        foreach ($billingAndShippingDetails as $fieldKey => $fieldValue) {
            $gateway->addField($fieldKey, $fieldValue);
        }

        if (!empty($basket)) {
            $gateway->setBasket($basket);
        } else {
            $gateway->setPurpose($purpose);
        }

        if (!empty($metaData)) {
            $zahls->setHttpHeaders($metaData);
        }
        try {
            return $zahls->create($gateway);
        } catch (\Zahls\ZahlsException $e) {
            $this->logger->error($e->getMessage(), [$e]);
        }
        return null;
    }

    public function getZahlsGateway(int $gatewayId, string $salesChannelId): Gateway|bool
    {
        if (!$gatewayId) {
            return false;
        }
        $zahls = $this->getInterface($salesChannelId);
        $gateway = new \Zahls\Models\Request\Gateway();
        $gateway->setId($gatewayId);
        try {
            $zahlsGateway = $zahls->getOne($gateway);
            return $zahlsGateway;
        } catch (\Zahls\ZahlsException $e) {
        }
        return false;
    }

    /**
     * capture a Transaction
     */
    public function captureTransaction(int $transactionId, string $salesChannelId): void
    {
        if (!$transactionId) {
            return;
        }
        $zahls = $this->getInterface($salesChannelId);

        $transaction = new \Zahls\Models\Request\Transaction();
        $transaction->setId($transactionId);

        try {
            $zahls->capture($transaction);
        } catch (\Zahls\ZahlsException $e) {
            return;
        }
    }

    public function getTransactionByGateway(Gateway $zahlsGateway, string $salesChannelId): ?\Zahls\Models\Response\Transaction
    {
        if (!in_array($zahlsGateway->getStatus(), [Transaction::CONFIRMED, Transaction::WAITING])) {
            return null;
        }
        $invoices = $zahlsGateway->getInvoices();

        if (!$invoices || !$invoice = end($invoices)) {
            return null;
        }

        if (!$transactions = $invoice['transactions']) {
            return null;
        }

        return $this->getZahlsTransaction(end($transactions)['id'], $salesChannelId);
    }

    public function getZahlsTransaction(int $zahlsTransactionId, string $salesChannelId): ?\Zahls\Models\Response\Transaction
    {
        $zahls = $this->getInterface($salesChannelId);

        $zahlsTransaction = new \Zahls\Models\Request\Transaction();
        $zahlsTransaction->setId($zahlsTransactionId);

        try {
            $response = $zahls->getOne($zahlsTransaction);
            return $response;
        } catch(\Zahls\ZahlsException $e) {
            return null;
        }
    }

    /**
     * Delete the zahls gateway
     */
    public function deleteZahlsGateway(string $salesChannelId, int $gatewayId): bool
    {
        if (empty($gatewayId)) {
            return true;
        }

        $zahls = $this->getInterface($salesChannelId);
        $gateway = $this->getZahlsGateway($gatewayId, $salesChannelId);

        if (!$gateway) {
            return true; // Already deleted.
        }
        if ($zahls && $gateway) {
            $transaction = $this->getTransactionByGateway($gateway, $salesChannelId);
            if (!$transaction) {
                try {
                    $zahls->delete($gateway);
                    return true;
                } catch (\Zahls\ZahlsException $e) {
                    // no action.
                }
            }

        }
        return false;
    }
}
