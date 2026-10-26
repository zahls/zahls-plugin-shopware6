<?php
/*
 * Copyright (c) Pickware GmbH. All rights reserved.
 * This file is part of software that is released under a proprietary license.
 * You must not copy, modify, distribute, make publicly available, or execute
 * its contents or parts thereof without express permission by the copyright
 * holder, unless otherwise permitted by law.
 */

declare(strict_types=1);

namespace ZahlsPaymentGateway\Webhook;

use Zahls\Models\Response\Transaction;
use ZahlsPaymentGateway\Handler\TransactionHandler;
use ZahlsPaymentGateway\Service\ConfigService;
use ZahlsPaymentGateway\Service\ZahlsApiService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class Dispatcher
{

    protected OrderTransactionStateHandler $transactionStateHandler;
    protected ContainerInterface $container;
    protected ConfigService $configService;
    protected ZahlsApiService $zahlsApiService;
    protected TransactionHandler $transactionHandler;
    protected LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        ContainerInterface $container,
        ConfigService $configService,
        ZahlsApiService $zahlsApiService,
        TransactionHandler $transactionHandler,
        LoggerInterface $logger
    )
    {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->container = $container;
        $this->configService = $configService;
        $this->zahlsApiService = $zahlsApiService;
        $this->transactionHandler = $transactionHandler;
        $this->logger = $logger;
    }

    /**
     * @Route(
     *     "/zahls-payment/webhook",
     *     name="frontend.zahls-payment.webhook",
     *     methods={"POST"},
     *     defaults={"csrf_protected"=false}
     * )
     */
    #[Route(path: '/zahls-payment/webhook', name: 'frontend.zahls-payment.webhook', methods: ['POST'], defaults: ['csrf_protected' => false])]
    public function executeWebhook(Request $request, Context $context): Response
    {
        $content = json_decode($request->getContent());

        $requestTransaction = $content->transaction ?? null;
        $swOrderNumber = $requestTransaction->referenceId ?? null;
        $requestGatewayId = $requestTransaction->invoice->paymentRequestId ?? null;

        // check required data
        if (!$swOrderNumber || empty($requestTransaction->status) || empty($requestTransaction->id)) {
            return new Response('Data incomplete', Response::HTTP_BAD_REQUEST);
        }

        try {
            $orderRepo = $this->container->get('order.repository');
            $transactionRepo = $this->container->get('order_transaction.repository');

            // TODO: Remove if and only keep else content
            if (preg_match("/[a-z]{30,}/i", $swOrderNumber)) {
                $transactionDetails = $transactionRepo->search(
                    (new Criteria())
                        ->addFilter(new EqualsFilter('id', $swOrderNumber))
                        ->addAssociation('order')
                        ->addAssociation('stateMachineState'),
                    $context
                );
                $order = $transactionDetails->first()->getOrder();
            } else {
                $orderDetails = $orderRepo->search(
                    (new Criteria())
                        ->addFilter(new EqualsFilter('orderNumber', $swOrderNumber))
                        ->addAssociation('transactions')
                        ->addAssociation('transactions.stateMachineState'),
                    $context
                );
                $order = $orderDetails->first();
            }

            if (!$order) {
                return new Response('Order does not exists.', Response::HTTP_OK);
            }
            $transaction = false;
            foreach($order->getTransactions() as $orderTransaction) {
                // Check all transaction if has already paid
                $state = $orderTransaction->getStateMachineState();

                if ($state && $state->getTechnicalName() === OrderTransactionStates::STATE_PAID) {
                    if (!in_array($requestTransaction->status, ['refunded', 'partially-refunded'])) {
                        return new Response('Already Paid State', Response::HTTP_OK);
                    }
                }
                $savedGatewayIds = explode(',', (string) ($orderTransaction->getCustomFields()['gateway_id'] ?? ''));
                if (in_array($requestGatewayId, $savedGatewayIds)) {
                    $transaction = $orderTransaction;
                    break;
                }
            }

        } catch (\Zahls\ZahlsException $e) {
            return new Response('Data incorrect', Response::HTTP_BAD_REQUEST);
        }

        // Validate request by gateway ID
        if (!$transaction) {
            return new Response(
                'Validation: Gateway ID not found in shopware. Transaction might be unsuccessful.',
                Response::HTTP_OK
            );
        }

        // The webhook is unsigned: only its transaction id is trusted, everything else is checked against the API
        $salesChannelId = $order->getSalesChannelId();
        $zahlsTransaction = $this->zahlsApiService->getZahlsTransaction((int) $requestTransaction->id, $salesChannelId);
        if (!$zahlsTransaction) {
            return new Response('Validation: Transaction not found at zahls.ch', Response::HTTP_BAD_REQUEST);
        }
        if ($zahlsTransaction->getStatus() !== $requestTransaction->status) {
            return new Response('Validation: Status Mismatch', Response::HTTP_BAD_REQUEST);
        }

        // Validate that the transaction was made on a gateway of this order
        if (!$this->isTransactionOfGateway($zahlsTransaction, (int) $requestGatewayId, $salesChannelId)) {
            $this->logger->warning('zahls.ch webhook: transaction does not belong to the gateway of the order', [
                'transactionId' => $zahlsTransaction->getId(),
                'gatewayId' => $requestGatewayId,
                'orderNumber' => $order->getOrderNumber(),
            ]);
            return new Response('Validation: Transaction does not belong to this order', Response::HTTP_BAD_REQUEST);
        }

        // Validate the paid amount
        if ($zahlsTransaction->getStatus() === Transaction::CONFIRMED
            && (int) $zahlsTransaction->getAmount() < $this->toCents($transaction->getAmount()->getTotalPrice())
        ) {
            $this->logger->warning('zahls.ch webhook: transaction amount lower than the order amount', [
                'transactionId' => $zahlsTransaction->getId(),
                'amount' => $zahlsTransaction->getAmount(),
                'orderNumber' => $order->getOrderNumber(),
            ]);
            return new Response('Validation: Amount Mismatch', Response::HTTP_BAD_REQUEST);
        }

        $this->transactionHandler->handleTransactionStatus($transaction, $zahlsTransaction->getStatus(), $context);

        return new Response('', Response::HTTP_OK);
    }

    /**
     * The gateway id comes from the webhook payload, so the gateway must list the transaction
     * or the transaction loaded from the API must name the gateway.
     */
    private function isTransactionOfGateway(Transaction $zahlsTransaction, int $gatewayId, string $salesChannelId): bool
    {
        $transactionId = (int) $zahlsTransaction->getId();
        if (!$gatewayId || !$transactionId) {
            return false;
        }

        $invoice = $zahlsTransaction->getInvoice();
        if (is_array($invoice) && (int) ($invoice['paymentRequestId'] ?? 0) === $gatewayId) {
            return true;
        }

        $zahlsGateway = $this->zahlsApiService->getZahlsGateway($gatewayId, $salesChannelId);
        if (!$zahlsGateway) {
            return false;
        }
        foreach ($zahlsGateway->getInvoices() ?: [] as $gatewayInvoice) {
            foreach ($gatewayInvoice['transactions'] ?? [] as $gatewayTransaction) {
                if ((int) ($gatewayTransaction['id'] ?? 0) === $transactionId) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Same conversion as used when creating the gateway
     */
    private function toCents(float $amount): int
    {
        return (int) str_replace('.', '', number_format($amount, 2, '.', ''));
    }
}
