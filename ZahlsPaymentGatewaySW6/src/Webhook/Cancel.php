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

use ZahlsPaymentGateway\Handler\TransactionHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * @Route(defaults={"_routeScope"={"storefront"}})
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class Cancel
{

    protected ContainerInterface $container;
    protected TransactionHandler $transactionHandler;

    public function __construct(
        ContainerInterface $container,
        TransactionHandler $transactionHandler
    ) {
        $this->container = $container;
        $this->transactionHandler = $transactionHandler;
    }

    /**
     * @Route(
     *     "/zahls-payment/cancel",
     *     name="frontend.zahls-payment.cancel",
     *     methods={"GET"},
     *     defaults={"csrf_protected"=false}
     * )
     */
    #[Route(path: '/zahls-payment/cancel', name: 'frontend.zahls-payment.cancel', methods: ['GET'], defaults: ['csrf_protected' => false])]
    public function executeCancel(Request $request, Context $context): Response
    {
        $orderRepository = $this->container->get('order.repository');
        $transactionRepo = $this->container->get('order_transaction.repository');
        $router = $this->container->get('router');

        // Get parameters from the request
        $orderNumber = $request->query->get('orderId');
        $transactionId = $request->query->get('transactionId');

        // Validate input
        if (!$orderNumber || !$transactionId) {
            return new Response('Invalid request parameters.', Response::HTTP_BAD_REQUEST);
        }

        // Search for the order
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('orderNumber', $orderNumber)
        );

        $order = $orderRepository->search($criteria, $context)->first();
        if (!$order) {
            return new Response('Order not found.', Response::HTTP_NOT_FOUND);
        }

        // Search for the transaction
        $criteria = new Criteria([$transactionId]);
        $criteria->addFilter(
            new EqualsFilter('orderId', $order->getId())
        );
        $criteria->addAssociation('stateMachineState');

        $transaction = $transactionRepo->search($criteria, $context)->first();

        if (!$transaction) {
            return new Response('Transaction not found.', Response::HTTP_NOT_FOUND);
        }

        if (!class_exists(\Zahls\Models\Response\Transaction::class)) {
            require_once dirname(dirname(__DIR__)). '/vendor/autoload.php';
        }
        
        $this->transactionHandler->handleTransactionStatus(
            $transaction,
            OrderTransactionStates::STATE_CANCELLED,
            $context
        );
        $redirectUrl = $router->generate(
            'frontend.account.edit-order.page',
            ['orderId' => $order->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        return new RedirectResponse($redirectUrl);
    }
}
