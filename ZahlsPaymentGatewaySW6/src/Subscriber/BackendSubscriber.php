<?php declare(strict_types=1);

namespace ZahlsPaymentGateway\Subscriber;

use ZahlsPaymentGateway\Service\ZahlsApiService;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BackendSubscriber implements EventSubscriberInterface
{
    private ContainerInterface $container;
    private ZahlsApiService $zahlsApiService;

    public function __construct(ContainerInterface $container,  ZahlsApiService $zahlsApiService)
    {
        $this->container = $container;
        $this->zahlsApiService = $zahlsApiService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_DELIVERY_WRITTEN_EVENT => 'onUpdateOrder'
        ];
    }

    public function onUpdateOrder(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();
        foreach ($event->getWriteResults() as $writeResult) {

            $payload = $writeResult->getPayload();

            if (!isset($payload['id'])) {
                continue;
            }

            /** @var EntityRepositoryInterface $stateRepository */
            $deliveryRepository = $this->container->get('order_delivery.repository');
            try {
                $deliveryResult = $deliveryRepository->search(
                    (new Criteria([$payload['id']]))->addAssociation('order', 'stateMachineState'),
                    $context
                );
            } catch (InconsistentCriteriaIdsException $e) {
            }

            /** @var OrderDeliveryEntity|null $deliveryInfo */
            $deliveryInfo =  $deliveryResult->first();
            if (!($deliveryInfo instanceof OrderDeliveryEntity)) {
                continue;
            }

            $deliveryState = $deliveryInfo->getStateMachineState();

            if(!$deliveryState || $deliveryState->getTechnicalName() !== 'shipped') {
                continue;
            }

            /** @var EntityRepositoryInterface $orderRepo */
            $orderRepo = $this->container->get('order.repository');
            /** @var EntitySearchResult $orderR */
            $orderResult = $orderRepo->search(
                (new Criteria([$deliveryInfo->getOrderId()]))->addAssociation('transactions.paymentMethod', 'transactions.customFields'),
                $context
            );

            /** @var OrderEntity|null $order */
            $order = $orderResult->first();
            if (!($order instanceof OrderEntity)) {
                continue;
            }
            $salesChannelId = $order->getSalesChannelId();

            $transaction = $order->getTransactions()->first();

            if (!$transaction) {
                continue;
            }
            $transactionCustomFields = $transaction->getCustomFields();
            if (empty($transactionCustomFields['gateway_id'])) {
                continue;
            }

            $gatewayIds = explode(',', (string) $transactionCustomFields['gateway_id']);
            if (!$zahlsGateway = $this->zahlsApiService->getZahlsGateway((int) current($gatewayIds), $salesChannelId)) {
                continue;
            }
            if (!$zahlsTransaction = $this->zahlsApiService->getTransactionByGateway($zahlsGateway, $salesChannelId)) {
                continue;
            }

            if ($zahlsTransaction->getStatus() == 'uncaptured') {
                $this->zahlsApiService->captureTransaction($zahlsTransaction->getId(), $salesChannelId);
            }
        }
    }
}
