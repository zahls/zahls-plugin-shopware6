<?php

declare(strict_types=1);

namespace ZahlsPaymentGateway\Handler;

use Zahls\Models\Response\Transaction;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

class TransactionHandler
{
    protected OrderTransactionStateHandler $transactionStateHandler;
    protected ContainerInterface $container;
    protected LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        ContainerInterface $container,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->container = $container;
        $this->logger = $logger;
    }

    public function saveTransactionCustomFields(Context $context, string $transactionId, array $details): void
    {
        $transactionRepo = $this->container->get('order_transaction.repository');

        // Manage existing gateway ids.Add commentMore actions
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('customFields');
        $transaction = $transactionRepo->search($criteria, $context)->first();
        if (!$transaction) {
           return;
        }

        $customFields = $transaction->getCustomFields() ?? [];
        $gatewayIds = $customFields['gateway_id'] ?? '';

        if (!empty($gatewayIds)) {
            // Save new gateway id first.
            $newGatewayId = $details['gateway_id'] . ',' . $gatewayIds;
            $newGatewayIds = array_slice(explode(',', (string) $newGatewayId), 0, 10);
            $details['gateway_id'] = implode(',', $newGatewayIds);
        }

        $transactionRepo->upsert([[
            'id' => $transactionId,
            'customFields' => $details
        ]], $context);
    }

    public function removeGatewayId(Context $context, string $transactionId, int $gatewayId): void
    {
        $transactionRepo = $this->container->get('order_transaction.repository');

        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('customFields');
        $transaction = $transactionRepo->search($criteria, $context)->first();
        if (!$transaction) {
           return;
        }

        $customFields = $transaction->getCustomFields() ?? [];
        $existingGatewayIds = $customFields['gateway_id'] ?? '';

        $gatewayIdArray = $existingGatewayIds !== '' ? explode(',', (string) $existingGatewayIds) : [];
        $filteredIds = array_filter($gatewayIdArray, fn($id) => $id !== (string) $gatewayId);

        // If no change, avoid unnecessary upsert
        if (implode(',', $gatewayIdArray) === implode(',', $filteredIds)) {
            return;
        }

        $customFields['gateway_id'] = implode(',', array_values($filteredIds));
        $transactionRepo->upsert([[
            'id' => $transactionId,
            'customFields' => $customFields,
        ]], $context);
    }

    public function handleTransactionStatus(OrderTransactionEntity $orderTransaction, string $zahlsTransactionStatus, Context $context): void
    {
        $state = $orderTransaction->getStateMachineState();
        switch ($zahlsTransactionStatus) {
            case OrderTransactionStates::STATE_UNCONFIRMED:
                if ($state !== null && OrderTransactionStates::STATE_OPEN === $state->getTechnicalName()) {
                    $this->transactionStateHandler->processUnconfirmed($orderTransaction->getId(), $context);
                }
                break;
            case Transaction::CONFIRMED:
                if ($state !== null && OrderTransactionStates::STATE_PAID === $orderTransaction->getStateMachineState()->getTechnicalName()) break;
                $this->transactionStateHandler->paid($orderTransaction->getId(), $context);
                break;
            case Transaction::WAITING:
                if ($state !== null && in_array($orderTransaction->getStateMachineState()->getTechnicalName(), [OrderTransactionStates::STATE_IN_PROGRESS, OrderTransactionStates::STATE_PAID])) break;
                $this->transactionStateHandler->reopen($orderTransaction->getId(), $context);
                break;
            case Transaction::REFUNDED:
                if ($state !== null && OrderTransactionStates::STATE_REFUNDED === $orderTransaction->getStateMachineState()->getTechnicalName()) break;
                $this->transactionStateHandler->refund($orderTransaction->getId(), $context);
                break;
            case Transaction::PARTIALLY_REFUNDED:
                if ($state !== null && OrderTransactionStates::STATE_PARTIALLY_REFUNDED === $orderTransaction->getStateMachineState()->getTechnicalName()) break;
                $this->transactionStateHandler->refundPartially($orderTransaction->getId(), $context);
                break;
            case Transaction::CANCELLED:
            case Transaction::DECLINED:
            case Transaction::EXPIRED:
                if ($state !== null && !in_array($orderTransaction->getStateMachineState()->getTechnicalName(), [OrderTransactionStates::STATE_OPEN, OrderTransactionStates::STATE_UNCONFIRMED])) {
                    break;
                }
                $this->transactionStateHandler->cancel($orderTransaction->getId(), $context);
                break;
            case Transaction::ERROR:
                if ($state !== null && !in_array($orderTransaction->getStateMachineState()->getTechnicalName(), [OrderTransactionStates::STATE_OPEN, OrderTransactionStates::STATE_UNCONFIRMED])) {
                    break;
                }
                $this->transactionStateHandler->fail($orderTransaction->getId(), $context);
                break;
        }
    }
}
