<?php

namespace ZahlsPaymentGateway\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class CustomerService
{

    protected EntityRepository $addressRepository;
    protected LoggerInterface $logger;

    public function __construct(EntityRepository $addressRepository, LoggerInterface $logger)
    {
        $this->addressRepository = $addressRepository;
        $this->logger = $logger;
    }

    public function getBillingAndShippingDetails(OrderEntity $order, Context $context): array
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $order->getBillingAddressId()));
            $criteria->addAssociation('country');

            /** @var OrderAddressEntity $address */
            $address = $this->addressRepository->search($criteria, $context)->first();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), [$e]);
        }

        $customer = $order->getOrderCustomer();

        $shippingAddress = null;
        $delivery = $order->getDeliveries()?->first();
        if ($delivery) {
            $shippingAddress = $delivery->getShippingOrderAddress();
        }

        $company = $address->getCompany();
        $department = $address->getDepartment();
        if (!empty($department)) {
            if (!empty($company)) {
                $company .= '/' . $department;
            } else {
                $company = $department;
            }
        }
        $billingDetails = [
            'forename' => $address->getFirstName(),
            'surname' => $address->getLastName(),
            'company' => $company,
            'street' => $address->getStreet(),
            'postcode' => $address->getZipCode(),
            'place' => $address->getCity(),
            'email' => $customer->getEmail(),
            'country' => $address->getCountry()->getIso()
        ];

        $shippingDetails = [];
        if ($shippingAddress) {
            $shippingCompany = $shippingAddress->getCompany() ?? $address->getCompany();
            $shippingDepartment = $shippingAddress->getDepartment() ?? $address->getDepartment();
            if (!empty($shippingDepartment)) {
                if (!empty($shippingCompany)) {
                    $shippingCompany .= '/' . $shippingDepartment;
                } else {
                    $shippingCompany = $shippingDepartment;
                }
            }
            $shippingDetails = [
                'delivery_forename' => $shippingAddress->getFirstName() ?? $address->getFirstName(),
                'delivery_surname' => $shippingAddress->getLastName() ?? $address->getLastName(),
                'delivery_company' => $shippingCompany,
                'delivery_street' => $shippingAddress->getStreet() ?? $address->getStreet(),
                'delivery_postcode' => $shippingAddress->getZipCode() ?? $address->getZipCode(),
                'delivery_place' => $shippingAddress->getCity() ?? $address->getCity(),
                'delivery_country' => $shippingAddress->getCountry()->getIso() ?? $address->getCountry()->getIso(),
            ];
        }

        $details = array_merge($billingDetails, $shippingDetails);

        return $details;
    }
}
