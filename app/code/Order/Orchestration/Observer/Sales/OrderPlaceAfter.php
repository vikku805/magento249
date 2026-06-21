<?php
declare(strict_types=1);

namespace Order\Orchestration\Observer\Sales;


use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;

class OrderPlaceAfter implements ObserverInterface
{
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    private PublisherInterface $publisher;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     */
    public function __construct(
        LoggerInterface $logger,
        PublisherInterface $publisher
    ) {
        $this->logger = $logger;
        $this->publisher = $publisher;
    }

    /**
     * Execute observer
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        $this->logger->info(
            sprintf(
                'Order Orchestration Observer Triggered. Order Increment ID: %s',
                $order->getIncrementId()
            )
            );
            $this->logger->debug(
                'Order Data: ' . json_encode($order->getData())
            );
        $items = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $items[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (float) $item->getQtyOrdered(),
                'price' => (float) $item->getPrice()
            ];
        }

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $payload = [
            'correlation_id' => $order->getIncrementId(),
            'increment_id' => $order->getIncrementId(),
            'magento_order_id' => $order->getId() ? (int) $order->getId() : null,
            'customer' => [
                'email' => $order->getCustomerEmail(),
                'firstname' => $order->getCustomerFirstname(),
                'lastname' => $order->getCustomerLastname(),
                'is_guest' => (bool) $order->getCustomerIsGuest()
            ],
            'billing_address' => $billingAddress ? $this->formatAddress($billingAddress) : null,
            'shipping_address' => $shippingAddress ? $this->formatAddress($shippingAddress) : null,
            'items' => $items,
            'totals' => [
                'subtotal' => (float) $order->getSubtotal(),
                'shipping' => (float) $order->getShippingAmount(),
                'tax' => (float) $order->getTaxAmount(),
                'grand_total' => (float) $order->getGrandTotal()
            ],
            'payment' => [
                'method' => $order->getPayment() ? $order->getPayment()->getMethod() : null
            ],
            'metadata' => [
                'currency' => $order->getOrderCurrencyCode(),
                'store_id' => (int) $order->getStoreId(),
                'created_at' => $order->getCreatedAt()
            ]
        ];

        $this->logger->info('ORDER EXPORT PAYLOAD: ' . json_encode($payload));

        $this->publisher->publish(
            'order.export',
            json_encode($payload)
        );
    }

    /**
     * Format an order address into a flat array.
     *
     * @param \Magento\Sales\Api\Data\OrderAddressInterface $address
     * @return array
     */
    private function formatAddress($address): array
    {
        return [
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'region' => $address->getRegion(),
            'postcode' => $address->getPostcode(),
            'country' => $address->getCountryId(),
            'telephone' => $address->getTelephone()
        ];


    }
}
