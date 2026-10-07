<?php
namespace CommunityStoreOrderEditor\Service;

use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Support\Facade\Events;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderEvent;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderItem;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderStatus\OrderStatus;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as PaymentMethod;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every change the order editor makes to a Community Store order. Totals are always summed from the database,
 * because the store's OrderItem::add() does not put new items into the order's in-memory collection.
 */
class OrderEditor
{
    /** Text attributes of an order the editor lets an admin change (address attributes are left alone). */
    const TEXT_ATTRIBUTES = ['email', 'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_company', 'shipping_first_name', 'shipping_last_name', 'shipping_company', 'vat_number'];

    /** @var EntityManagerInterface */
    protected $em;

    /** @var Connection */
    protected $db;

    /** @var UserInfoRepository */
    protected $users;

    public function __construct(EntityManagerInterface $em, UserInfoRepository $users)
    {
        $this->em = $em;
        $this->db = $em->getConnection();
        $this->users = $users;
    }

    // ---- create / header

    /**
     * A new order from the editor form. Items are added afterwards.
     *
     * @throws UserMessageException
     */
    public function create(array $data): Order
    {
        $order = new Order();
        $order->setTemporaryRecordCreated(false);
        $order->setExternalPaymentRequested(null);
        $this->applyHeader($order, $data);
        $status = (string) ($data['status'] ?? '');
        if ($status === '' || !OrderStatus::getByHandle($status)) {
            $starting = OrderStatus::getStartingStatus();
            $status = $starting ? $starting->getHandle() : 'incomplete';
        }
        $order->updateStatus($status, t('Created in the order editor'));
        $this->applyAttributes($order, $data);

        return $order;
    }

    /**
     * Header fields of an existing order.
     *
     * @throws UserMessageException
     */
    public function update(Order $order, array $data): void
    {
        $this->applyHeader($order, $data);
        $this->applyAttributes($order, $data);
    }

    /**
     * @throws UserMessageException
     */
    protected function applyHeader(Order $order, array $data): void
    {
        $cID = (int) ($data['cID'] ?? 0);
        if ($cID > 0 && !$this->users->getByID($cID)) {
            throw new UserMessageException(t('Unknown customer.'));
        }
        $order->setCustomerID($cID);

        $date = $data['oDate'] ?? null;
        if ($date instanceof DateTime) {
            $order->setDate($date);
        } elseif (is_string($date) && trim($date) !== '') {
            try {
                $order->setDate(new DateTime($date));
            } catch (\Exception $e) {
                throw new UserMessageException(t('Invalid order date.'));
            }
        } elseif (!$order->getOrderDate()) {
            $order->setDate(new DateTime());
        }

        $pmID = (int) ($data['pmID'] ?? 0);
        $pmName = trim((string) ($data['pmName'] ?? ''));
        if ($pmID > 0) {
            $pm = PaymentMethod::getByID($pmID);
            if (!$pm) {
                throw new UserMessageException(t('Unknown payment method.'));
            }
            if ($pmName === '') {
                $pmName = $pm->getDisplayName() ?: $pm->getName();
            }
            $order->setPaymentMethodID($pmID);
        } else {
            $order->setPaymentMethodID(null);
        }
        $order->setPaymentMethodName($pmName !== '' ? $pmName : null);

        $order->setShippingMethodName($this->nullable($data['smName'] ?? ''));
        $order->setShippingInstructions($this->nullable($data['sInstructions'] ?? ''));
        $order->setTransactionReference($this->nullable($data['transactionReference'] ?? ''));
        $order->setNotes($this->nullable($data['oNotes'] ?? ''));
        if (array_key_exists('locale', $data)) {
            $order->setLocale($this->nullable($data['locale']));
        }

        $order->setShippingTotal($this->money($data['oShippingTotal'] ?? 0, t('Shipping')));
        $tax = $this->money($data['oTax'] ?? 0, t('Tax'));
        $taxIncluded = $this->money($data['oTaxIncluded'] ?? 0, t('Included tax'));
        $taxLabel = trim((string) ($data['oTaxName'] ?? ''));
        $order->setTaxTotal($tax != 0 ? (string) $tax : ($taxIncluded != 0 ? '0' : ''));
        $order->setTaxIncluded($taxIncluded != 0 ? (string) $taxIncluded : ($tax != 0 ? '0' : ''));
        $order->setTaxLabels(($tax != 0 || $taxIncluded != 0) ? ($taxLabel !== '' ? $taxLabel : t('Tax')) : '');

        if (!empty($data['recalculate'])) {
            $order->setTotal(0);
            $order->save();
            $this->recalculate($order);
        } else {
            $order->setTotal($this->money($data['oTotal'] ?? 0, t('Total')));
            $order->save();
        }
    }

    protected function applyAttributes(Order $order, array $data): void
    {
        $attrs = isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : [];
        if (!array_key_exists('email', $attrs) && (int) $order->getCustomerID() > 0 && (string) $order->getAttribute('email') === '') {
            $ui = $this->users->getByID((int) $order->getCustomerID());
            if ($ui) {
                $attrs['email'] = $ui->getUserEmail();
            }
        }
        $category = $order->getObjectAttributeCategory();
        foreach (self::TEXT_ATTRIBUTES as $handle) {
            if (!array_key_exists($handle, $attrs)) {
                continue;
            }
            if (!$category->getByHandle($handle)) {
                continue;
            }
            $value = trim((string) $attrs[$handle]);
            if ($value === '') {
                $order->clearAttribute($handle);
            } else {
                $order->setAttribute($handle, $value);
            }
        }
    }

    // ---- items

    /**
     * @throws UserMessageException
     */
    public function addProduct(Order $order, int $pID, float $qty, ?float $price = null): OrderItem
    {
        $this->requireEditable($order);
        $product = $pID > 0 ? Product::getByID($pID) : null;
        if (!$product) {
            throw new UserMessageException(t('Unknown product.'));
        }
        $qty = $this->quantity($qty, $product->allowQuantity());
        $data = ['product' => ['object' => $product, 'pID' => $pID, 'qty' => $qty], 'productAttributes' => []];
        if ($price !== null) {
            $data['product']['customerPrice'] = $price;
        }
        $item = OrderItem::add($data, (int) $order->getOrderID());
        $this->recalculate($this->reload($order));

        return $item;
    }

    /**
     * A line that is not a store product (fees, corrections, legacy bookings).
     *
     * @throws UserMessageException
     */
    public function addCustomItem(Order $order, string $name, float $qty, float $price, string $sku = ''): OrderItem
    {
        $this->requireEditable($order);
        $name = trim($name);
        if ($name === '') {
            throw new UserMessageException(t('The item needs a name.'));
        }
        $item = new OrderItem();
        $item->setProductID(0);
        $item->setProductName($name);
        $item->setSKU($sku !== '' ? $sku : null);
        $item->setPricePaid(round($price, 4));
        $item->setQuantity($this->quantity($qty, true));
        $item->setTax(0);
        $item->setTaxIncluded(0);
        $item->setTaxName('');
        $item->setOrder($order);
        $item->save();
        $this->recalculate($this->reload($order));

        return $item;
    }

    /**
     * Name, SKU, quantity and price of existing lines.
     *
     * @param array<int, array{name?: string, sku?: string, qty?: mixed, price?: mixed}> $rows oiID => fields
     *
     * @throws UserMessageException
     */
    public function updateItems(Order $order, array $rows): void
    {
        $this->requireEditable($order);
        foreach ($rows as $oiID => $fields) {
            $item = $this->requireItem($order, (int) $oiID);
            $name = trim((string) ($fields['name'] ?? $item->getProductName()));
            if ($name === '') {
                throw new UserMessageException(t('Item #%s needs a name.', $oiID));
            }
            $item->setProductName($name);
            $item->setSKU($this->nullable($fields['sku'] ?? $item->getSKU()));
            if (isset($fields['qty'])) {
                $item->setQuantity($this->quantity((float) $this->number($fields['qty'], t('Quantity')), true));
            }
            if (isset($fields['price'])) {
                $item->setPricePaid(round($this->number($fields['price'], t('Price')), 4));
            }
            $this->em->persist($item);
        }
        $this->em->flush();
        $this->recalculate($this->reload($order));
    }

    /**
     * @throws UserMessageException
     */
    public function removeItem(Order $order, int $oiID): void
    {
        $this->requireEditable($order);
        $item = $this->requireItem($order, $oiID);
        $this->db->executeStatement('DELETE FROM CommunityStoreOrderItemOptions WHERE oiID = ?', [$oiID]);
        $item->delete();
        $this->recalculate($this->reload($order));
    }

    /**
     * @return array<int, OrderItem> straight from the database, independent of the entity's collection
     */
    public function getItems(Order $order): array
    {
        $ids = $this->db->fetchFirstColumn('SELECT oiID FROM CommunityStoreOrderItems WHERE oID = ? ORDER BY oiID', [(int) $order->getOrderID()]);
        $items = [];
        foreach ($ids as $oiID) {
            $item = OrderItem::getByID((int) $oiID);
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function getItemsTotal(Order $order): float
    {
        return round((float) $this->db->fetchOne('SELECT COALESCE(SUM(oiPricePaid * oiQty), 0) FROM CommunityStoreOrderItems WHERE oID = ?', [(int) $order->getOrderID()]), 2);
    }

    /**
     * Total = items + shipping + tax that is not already included in the prices.
     */
    public function recalculate(Order $order): float
    {
        $total = round($this->getItemsTotal($order) + (float) $order->getShippingTotal() + (float) $order->getTaxTotal(), 2);
        $order->setTotal($total);
        $order->save();

        return $total;
    }

    // ---- status and payment state

    /**
     * @throws UserMessageException
     */
    public function updateStatus(Order $order, string $handle, string $comment = ''): void
    {
        if (!OrderStatus::getByHandle($handle)) {
            throw new UserMessageException(t('Unknown status.'));
        }
        $order->updateStatus($handle, $comment !== '' ? $comment : null);
    }

    /**
     * Same steps as the store's own "Mark Paid": the store's payment events fire (stock, groups, listeners).
     *
     * @throws UserMessageException
     */
    public function markPaid(Order $order, int $byUID, string $transactionReference = '', ?DateTime $paidAt = null): void
    {
        if ($order->getPaid()) {
            throw new UserMessageException(t('The order is already paid.'));
        }
        if ($transactionReference !== '') {
            $order->setTransactionReference($transactionReference);
        }
        $order->completePayment();
        if ($paidAt) {
            $order->setPaid($paidAt);
        }
        $order->setExternalPaymentRequested(null);
        $order->setPaidByUID($byUID ?: null);
        $order->save();
    }

    public function reversePaid(Order $order): void
    {
        $order->setPaid(null);
        $order->setPaidByUID(null);
        $order->save();
    }

    public function markRefunded(Order $order, int $byUID, string $reason = ''): void
    {
        $order->setRefunded(new DateTime());
        $order->setRefundedByUID($byUID ?: null);
        $order->setRefundReason($reason !== '' ? $reason : null);
        $order->save();
    }

    public function reverseRefund(Order $order): void
    {
        $order->setRefunded(null);
        $order->setRefundedByUID(null);
        $order->setRefundReason(null);
        $order->save();
    }

    /**
     * Fires the store's cancel event, like the store's Orders page (store credit and tickets react to it).
     */
    public function markCancelled(Order $order, int $byUID): void
    {
        $order->setCancelled(new DateTime());
        $order->setCancelledByUID($byUID ?: null);
        $order->save();
        Events::dispatch(OrderEvent::ORDER_CANCELLED, new OrderEvent($order));
    }

    public function reverseCancel(Order $order): void
    {
        $order->setCancelled(null);
        $order->setCancelledByUID(null);
        $order->save();
    }

    /**
     * The order with everything that hangs off it. The store's Order::remove() deletes the items with raw SQL,
     * so item entities loaded earlier in the request would stay managed and, through their cascade=persist link,
     * make Doctrine re-insert the order on the next flush. Everything is detached first.
     */
    public function delete(Order $order): void
    {
        $oID = (int) $order->getOrderID();
        $this->db->executeStatement('DELETE FROM CommunityStoreOrderStatusHistories WHERE oID = ?', [$oID]);
        $this->db->executeStatement('DELETE FROM CommunityStoreOrderDiscounts WHERE oID = ?', [$oID]);
        $this->db->executeStatement('DELETE FROM csOrderEditorBulkOrders WHERE oID = ?', [$oID]);
        $this->em->clear();
        $fresh = Order::getByID($oID);
        if ($fresh) {
            $fresh->remove();
        }
        $this->em->clear();
    }

    // ---- helpers

    /**
     * Fresh entity (clears the item collection cached on the identity-mapped order).
     */
    public function reload(Order $order): Order
    {
        $oID = (int) $order->getOrderID();
        $this->em->clear(OrderItem::class);
        $this->em->refresh($order);

        return Order::getByID($oID) ?: $order;
    }

    /**
     * @throws UserMessageException
     */
    protected function requireEditable(Order $order): void
    {
        if ($order->getPaid() && !$order->getRefunded()) {
            throw new UserMessageException(t('The order is paid: reverse the payment before changing its items.'));
        }
    }

    /**
     * @throws UserMessageException
     */
    protected function requireItem(Order $order, int $oiID): OrderItem
    {
        $item = $oiID > 0 ? OrderItem::getByID($oiID) : null;
        if (!$item || !$item->getOrder() || (int) $item->getOrder()->getOrderID() !== (int) $order->getOrderID()) {
            throw new UserMessageException(t('This item is not part of the order.'));
        }

        return $item;
    }

    /**
     * @throws UserMessageException
     */
    protected function money($value, string $label): float
    {
        return round($this->number($value, $label), 2);
    }

    /**
     * @throws UserMessageException
     */
    protected function number($value, string $label): float
    {
        $value = str_replace([' ', "'"], '', trim((string) $value));
        if ($value === '') {
            return 0.0;
        }
        if (!is_numeric($value)) {
            $value = str_replace(',', '.', $value);
        }
        if (!is_numeric($value)) {
            throw new UserMessageException(t('%s must be a number.', $label));
        }

        return (float) $value;
    }

    /**
     * @throws UserMessageException
     */
    protected function quantity(float $qty, bool $allowFraction): float
    {
        if ($qty <= 0) {
            throw new UserMessageException(t('The quantity must be greater than zero.'));
        }

        return $allowFraction ? round($qty, 4) : max(1, round($qty));
    }

    protected function nullable($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
