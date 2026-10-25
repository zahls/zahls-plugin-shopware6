<?php

declare(strict_types=1);

namespace ZahlsPaymentGateway\Handler;

use Exception;
use Zahls\Models\Response\Transaction;
use ZahlsPaymentGateway\Service\ConfigService;
use ZahlsPaymentGateway\Service\ZahlsApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;
use ZahlsPaymentGateway\Service\CustomerService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\PaymentException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Plugin\PluginService;

class PaymentHandler extends AbstractPaymentHandler
{

    const PAYMENT_METHOD_PREFIX = 'zahls_payment_';
    const BASE_URL = 'zahls.ch';

    protected OrderTransactionStateHandler $transactionStateHandler;
    protected ContainerInterface $container;
    protected ConfigService $configService;
    protected CustomerService $customerService;
    protected ZahlsApiService $zahlsApiService;
    protected TransactionHandler $transactionHandler;
    protected LoggerInterface $logger;
    protected RouterInterface $router;
    protected RequestStack $requestStack;
    protected PluginService $pluginService;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        ContainerInterface $container,
        CustomerService $customerService,
        ZahlsApiService $zahlsApiService,
        TransactionHandler $transactionHandler,
        ConfigService $configService,
        LoggerInterface $logger,
        RouterInterface $router,
        RequestStack $requestStack,
        PluginService $pluginService,
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->container = $container;
        $this->customerService = $customerService;
        $this->zahlsApiService = $zahlsApiService;
        $this->transactionHandler = $transactionHandler;
        $this->configService = $configService;
        $this->logger = $logger;
        $this->router = $router;
        $this->requestStack = $requestStack;
        $this->pluginService = $pluginService;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {

        [$orderTransaction, $order] = $this->fetchOrderTransaction($transaction->getOrderTransactionId(), $context);
        $totalAmount = $orderTransaction->getAmount()->getTotalPrice();

        // Workaround if amount is 0
        if ($totalAmount <= 0) {
            $redirectUrl = $transaction->getReturnUrl();
            return new RedirectResponse($redirectUrl);
        }

        $salesChannelId = $order->getSalesChannelId();
        $transactionId = $orderTransaction->getId();

        $paymentMethodRepo = $this->container->get('payment_method.repository');
        $paymentCriteria = (new Criteria())->addFilter(
            new EqualsFilter('id', $orderTransaction->getPaymentMethodId())
        );
        $paymentMethod = $paymentMethodRepo->search($paymentCriteria, $context)->first();
        $customFields = isset($paymentMethod->getTranslated()['customFields']) ? $paymentMethod->getTranslated()['customFields'] : [];

        $paymentMethodName = '';

        if (isset($customFields['zahls_payment_method_name'])) {
            $paymentMethodName = $customFields['zahls_payment_method_name'] ?: $paymentMethodName;
        }

        $paymentMean = str_replace(self::PAYMENT_METHOD_PREFIX, '', $paymentMethodName);

        $basket = $this->collectBasketData($order, $context);
        $purpose = $this->createPurposeByBasket($basket);
        $basketAmount = $this->getBasketAmount($basket);

        // Compare with rounded totals to check if basket is correct
        if ((float) $totalAmount !== (float) ($basketAmount / 100)) {
            $basket = [];
        }

        $averageVatRate = $this->getAverageTaxRate($order);
        try {
            $billingAndShippingDetails = $this->customerService->getBillingAndShippingDetails($order, Context::createDefaultContext());
        } catch (\Exception $e) {
            $message = 'An error occurred while processing the customer details' . PHP_EOL . $e->getMessage();
            $this->customAsyncException($transactionId, $message);
        }

        // Delete gateway from all transactions.
        if (!empty($order->getTransactions())) {
            foreach ($order->getTransactions() as $transactionGateway) {
                $customFields = $transactionGateway->getCustomFields() ?? [];
                $oldGatewayIds = $customFields['gateway_id'] ?? '';

                if (empty($oldGatewayIds)) {
                    continue;
                }

                $gatewayIds = array_filter(explode(',', (string) $oldGatewayIds));
                $oldGatewayId = current($gatewayIds);

                if (!$oldGatewayId) {
                    continue;
                }

                $gatewayStatus = $this->zahlsApiService->deleteZahlsGateway(
                    $salesChannelId,
                    (int) $oldGatewayId
                );

                if ($gatewayStatus) {
                    $this->transactionHandler->removeGatewayId(
                        $context,
                        $transactionGateway->getId(),
                        (int) $oldGatewayId
                    );
                }
            }
        }
        $returnUrl = $this->createReturnUrl(
            $transaction,
            $order->getOrderNumber(),
            $transactionId
        );

        $metaData = [];
        try {
            $metaData['X-Shop-Version'] = (string) $this->container->getParameter(
                'kernel.shopware_version'
            );
            $plugin = $this->pluginService->getPluginByName(
                'ZahlsPaymentGatewaySW6',
                Context::createDefaultContext()
            );
            if ($plugin) {
                $metaData['X-Plugin-Version'] = (string) $plugin->getVersion();
            }
        } catch (Exception $e) {}

        // Create    zahls Gateway link for checkout and redirect user
        try {
            $zahlsGateway = $this->zahlsApiService->createZahlsGateway(
                $order->getOrderNumber(),
                $totalAmount,
                $averageVatRate,
                $order->getCurrency()->getIsoCode(),
                $paymentMean,
                $billingAndShippingDetails,
                $returnUrl,
                $basket,
                $salesChannelId,
                $purpose,
                $metaData
            );

            if (!$zahlsGateway) {
                throw new Exception('Gateway creation error');
            }
            $this->transactionHandler->saveTransactionCustomFields($context, $transactionId, ['gateway_id' => $zahlsGateway->getId()]);
            $this->transactionHandler->handleTransactionStatus(
                $orderTransaction,
                OrderTransactionStates::STATE_UNCONFIRMED,
                $context
            );
            $redirectUrl = $zahlsGateway->getLink();
        } catch (\Exception $e) {
            $message = 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage();
            $this->customAsyncException($transactionId, $message);
        }

        return new RedirectResponse($redirectUrl);
    }

    /**
     * @throws PaymentException|CustomerCanceledAsyncPaymentException
     */
    public function finalize(
        Request $request,
        PaymentTransactionStruct $shopwareTransaction,
        Context $context,
    ): void  {

        if (!$shopwareTransaction) {
            return;
        }
        [$orderTransaction, $order] = $this->fetchOrderTransaction($shopwareTransaction->getOrderTransactionId(), $context);

        // skip process if already paid.
        if ($orderTransaction->getStateMachineState() &&
            OrderTransactionStates::STATE_PAID === $orderTransaction->getStateMachineState()->getTechnicalName()
        ) {
            return;
        }

        $transactionId = $orderTransaction->getId();
        $totalAmount = $orderTransaction->getAmount()->getTotalPrice();

        if ($totalAmount <= 0) {
            $this->transactionStateHandler->paid($orderTransaction->getId(), $context);
            return;
        }

        $customFields = $orderTransaction->getCustomFields();
        $gatewayId = $customFields['gateway_id'] ?? '';
        if (empty($gatewayId)) {
            $this->customCustomerException($transactionId, 'Customer canceled the payment on the    zahls page');
        }
    }

    private function collectBasketData(OrderEntity $order, $context):array
    {
        // Collect basket data
        $basket = [];

        $lineItemElements = [];
        if ($order->getLineItems()) {
            $lineItemElements = $order->getLineItems()->getElements();
        }
        foreach ($lineItemElements as $item) {
            $unitPrice = $item->getUnitPrice();
            $quantity = $item->getQuantity();

            $basket[] = [
                'name' => $item->getLabel(),
                'description' => $item->getDescription(),
                'quantity' => $item->getQuantity(),
                'amount' => $unitPrice * 100,
                'sku' => isset($item->getPayload()['productNumber']) ? $item->getPayload()['productNumber'] : '',
            ];
        }

        $shippingMethodRepo = $this->container->get('shipping_method.repository');

        $deliveryElements = [];
        if ($order->getDeliveries()) {
            $deliveryElements = $order->getDeliveries()->getElements();
        }
        foreach ($deliveryElements as $delivery) {
            $shippingCriteria = (new Criteria())->addFilter(
                new EqualsFilter('id', $delivery->getShippingMethodId())
            );
            $shippingMethod = $shippingMethodRepo->search($shippingCriteria, $context)->first();

            $unitPrice = $delivery->getShippingCosts()->getUnitPrice() ;
            $quantity = $delivery->getShippingCosts()->getQuantity();

            $basket[] = [
                'name' => $shippingMethod->getTranslated()['name'] ?: $shippingMethod->getName(),
                'description' => $shippingMethod->getTranslated()['description'] ?: $shippingMethod->getDescription(),
                'quantity' => $quantity,
                'amount' => $unitPrice * 100,
                'sku' => $shippingMethod->getId(),
            ];
        }

        $taxElements = [];
        if ($order->getPrice() && $order->getPrice()->getCalculatedTaxes()) {
            $taxElements = $order->getPrice()->getCalculatedTaxes();
        }
        if ($order->getTaxStatus() === CartPrice::TAX_STATE_NET) {
            foreach ($taxElements as $tax) {
                $unitPrice = $tax->getTax();
                $quantity = 1;
                $basket[] = [
                    'name' => 'Tax ' . $tax->getTaxRate() . '%',
                    'quantity' => $quantity,
                    'amount' => $unitPrice * 100,
                ];
            }
        }

        return $basket;
    }

    private function getAverageTaxRate(OrderEntity $order): float {
        if (!$order->getPrice() || !$order->getPrice()->getCalculatedTaxes()) {
            return 0;
        }

        $taxRate = 0;
        $finalTaxRate = 0;
        $taxElements = $order->getPrice()->getCalculatedTaxes();

        if (!count($taxElements)) return $finalTaxRate;
        foreach ($taxElements as $tax) {
            $taxRate += $tax->getTaxRate();
        }

        $finalTaxRate = ($taxRate / count($taxElements));

        return $finalTaxRate;
    }

    /**
     * Get total amount of basket items
     */
    private function getBasketAmount(array $basket): int
    {
        $basketAmount = 0;

        foreach ($basket as $product) {
            $amount = $product['amount'];
            $basketAmount += $product['quantity'] * $amount;
        }
        return intval((string) $basketAmount);
    }

    /**
     * Create purpose from basket items
     */
    public static function createPurposeByBasket(array $basket): string
    {
        $desc = [];
        foreach ($basket as $product) {
            $desc[] = implode(' ', [
                $product['name'],
                $product['quantity'],
                'x',
                number_format($product['amount'] / 100, 2, '.'),
            ]);
        }
        return implode('; ', $desc);
    }

    /**
     * @param int $transactionId
     * @param string $message
     * @throws AsyncPaymentProcessException|PaymentException
     */
    public function customAsyncException($transactionId, $message)
    {
        if (class_exists(AsyncPaymentProcessException::class)) {
            throw new AsyncPaymentProcessException($transactionId, $message);
        } else {
            // support from shopware 6.6
            throw PaymentException::asyncProcessInterrupted($transactionId, $message);
        }
    }

    /**
     * @param int $transactionId
     * @param string $message
     * @throws AsyncPaymentProcessException|PaymentException
     */
    public function customCustomerException($transactionId, $message)
    {
        if (class_exists(CustomerCanceledAsyncPaymentException::class)) {
            throw new CustomerCanceledAsyncPaymentException($transactionId, $message);
        } else {
            // support from shopware 6.6
            throw PaymentException::customerCanceled($transactionId, $message);
        }
    }

    private function createReturnUrl(
        PaymentTransactionStruct $transaction,
        string $orderNumber,
        string $transactionId
    ): array {
        $returnUrl = $transaction->getReturnUrl();
        $request = $this->requestStack->getCurrentRequest();
        $urlPath = $request ? $request->getPathInfo() : '';

        // Check if the request comes from Store API (headless frontend)
        $isStoreApiRequest = (strpos($urlPath, '/store-api') === 0);
        $errorUrl = null;
        if ($isStoreApiRequest) {
            $errorUrl = $request->getPayload()->get('errorUrl');
        }

        return [
            'success' => $returnUrl,
            'cancel' => $isStoreApiRequest
                ? ($errorUrl ?: $returnUrl)
                : $this->router->generate(
                    'frontend.zahls-payment.cancel',
                    [
                        'orderId' => $orderNumber,
                        'transactionId' => $transactionId,
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
        ];
    }

    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return true;
    }

    private function fetchOrderTransaction(string $transactionId, Context $context): array
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('order.billingAddress.country');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('order.orderCustomer.customer');
        $criteria->addAssociation('order.salesChannel');
        $criteria->addAssociation('order.transactions');

        $transaction = $this->container->get('order_transaction.repository')->search($criteria, $context)->first();
        \assert($transaction instanceof OrderTransactionEntity);

        $order = $transaction->getOrder();
        \assert($order instanceof OrderEntity);

        return [$transaction, $order];
    }
}
