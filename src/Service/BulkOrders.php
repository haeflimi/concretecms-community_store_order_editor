<?php
namespace CommunityStoreOrderEditor\Service;

use CommunityStoreOrderEditor\Entity\BulkOrder;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\User\Group\GroupList;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Core\User\UserList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Bulk creation of orders: the same order (items, payment method, status, notes) for every active user of a
 * group, like the credit manager's "Bulk Add Transactions". Every created order is recorded with the batch id,
 * so a batch can be re-submitted safely and found again in the order list.
 */
class BulkOrders
{
    const ALL_USERS = 'all';

    /** @var EntityManagerInterface */
    protected $em;

    /** @var OrderEditor */
    protected $editor;

    /** @var UserInfoRepository */
    protected $users;

    public function __construct(EntityManagerInterface $em, OrderEditor $editor, UserInfoRepository $users)
    {
        $this->em = $em;
        $this->editor = $editor;
        $this->users = $users;
    }

    /**
     * Selectable targets: every group except Guest and Registered Users, plus all active users.
     *
     * @return array<string, string> selection key => label with member count
     */
    public function getTargets(): array
    {
        $targets = [];
        $list = new GroupList();
        $list->includeAllGroups();
        foreach ($list->getResults() as $group) {
            $gID = (int) $group->getGroupID();
            if ($gID === GUEST_GROUP_ID || $gID === REGISTERED_GROUP_ID) {
                continue;
            }
            $targets[(string) $gID] = $group->getGroupDisplayName(false) . ' (' . t2('%d user', '%d users', $this->countUsers((string) $gID)) . ')';
        }
        $targets[self::ALL_USERS] = t('All active users') . ' (' . t2('%d user', '%d users', $this->countUsers(self::ALL_USERS)) . ')';

        return $targets;
    }

    public function isTarget(string $selection): bool
    {
        return $selection === self::ALL_USERS || (ctype_digit($selection) && (int) $selection > 0 && (int) $selection !== GUEST_GROUP_ID && (int) $selection !== REGISTERED_GROUP_ID);
    }

    public function countUsers(string $selection): int
    {
        return $this->isTarget($selection) ? (int) $this->userList($selection)->getTotalResults() : 0;
    }

    /**
     * @return UserInfo[] active users of the selection
     */
    public function getUsers(string $selection): array
    {
        if (!$this->isTarget($selection)) {
            return [];
        }
        $list = $this->userList($selection);
        $list->setItemsPerPage(100000);

        return $list->getResults();
    }

    /**
     * Creates the orders. $spec: target, label, oDate, pmID, pmName, status, oNotes, markPaid, items
     * (each: kind=product|custom, pID, name, sku, qty, price).
     *
     * @return array{created: int, skipped: int, total: float, orderIDs: int[]}
     *
     * @throws UserMessageException
     */
    public function run(array $spec, string $batchId, int $byUID): array
    {
        $batchId = preg_replace('/[^A-Za-z0-9_.\-]/', '', $batchId);
        if ($batchId === '' || strlen($batchId) > 64) {
            throw new UserMessageException(t('Missing batch id, please reload the page.'));
        }
        $target = (string) ($spec['target'] ?? '');
        if (!$this->isTarget($target)) {
            throw new UserMessageException(t('Please select a group.'));
        }
        $items = $this->normalizeItems($spec['items'] ?? []);
        if ($items === []) {
            throw new UserMessageException(t('Add at least one item.'));
        }
        $label = trim((string) ($spec['label'] ?? ''));
        if ($label === '') {
            throw new UserMessageException(t('A label for the batch is required.'));
        }
        $users = $this->getUsers($target);
        if ($users === []) {
            throw new UserMessageException(t('The selection has no active users.'));
        }

        $header = [
            'oDate' => $spec['oDate'] ?? null,
            'pmID' => (int) ($spec['pmID'] ?? 0),
            'pmName' => (string) ($spec['pmName'] ?? ''),
            'status' => (string) ($spec['status'] ?? ''),
            'oNotes' => trim((string) ($spec['oNotes'] ?? '')),
            'oShippingTotal' => 0, 'oTax' => 0, 'oTaxIncluded' => 0, 'oTaxName' => '', 'oTotal' => 0,
        ];
        $existing = $this->em->getRepository(BulkOrder::class)->findBy(['batchId' => $batchId]);
        $done = [];
        foreach ($existing as $row) {
            $done[$row->getUserID()] = true;
        }

        $result = ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'orderIDs' => []];
        foreach ($users as $ui) {
            $uID = (int) $ui->getUserID();
            if (isset($done[$uID])) {
                $result['skipped']++;
                continue;
            }
            $data = $header;
            $data['cID'] = $uID;
            $data['attributes'] = ['email' => (string) $ui->getUserEmail()];
            $order = $this->editor->create($data);
            foreach ($items as $item) {
                if ($item['kind'] === 'product') {
                    $this->editor->addProduct($order, $item['pID'], $item['qty'], $item['price']);
                } else {
                    $this->editor->addCustomItem($order, $item['name'], $item['qty'], (float) $item['price'], $item['sku']);
                }
            }
            $order = Order::getByID((int) $order->getOrderID()) ?: $order;
            if (!empty($spec['markPaid'])) {
                $this->editor->markPaid($order, $byUID, $batchId);
            }
            $this->em->persist(new BulkOrder($batchId, $label, $uID, (int) $order->getOrderID(), $byUID));
            $this->em->flush();
            $result['created']++;
            $result['total'] += (float) $order->getTotal();
            $result['orderIDs'][] = (int) $order->getOrderID();
        }
        $result['total'] = round($result['total'], 2);

        return $result;
    }

    /**
     * Past batches, newest first: batchId, label, createdAt, byUID, orders, total (of the orders that still exist).
     *
     * @return array<int, array{batchId: string, label: string, createdAt: string, byUID: int, orders: int, total: float}>
     */
    public function getBatches(int $limit = 50): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT b.batchId, MAX(b.label) AS label, MIN(b.createdAt) AS createdAt, MAX(b.byUID) AS byUID, COUNT(*) AS orders, COALESCE(SUM(o.oTotal), 0) AS total'
            . ' FROM csOrderEditorBulkOrders b LEFT JOIN CommunityStoreOrders o ON o.oID = b.oID'
            . ' GROUP BY b.batchId ORDER BY MAX(b.id) DESC LIMIT ' . (int) $limit
        );
        foreach ($rows as &$row) {
            $row['byUID'] = (int) $row['byUID'];
            $row['orders'] = (int) $row['orders'];
            $row['total'] = round((float) $row['total'], 2);
        }

        return $rows;
    }

    /**
     * @return int[] order ids of a batch
     */
    public function getOrderIDs(string $batchId): array
    {
        return array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT oID FROM csOrderEditorBulkOrders WHERE batchId = ?', [$batchId]));
    }

    /**
     * The batch an order was created by, if any.
     */
    public function getBatchOfOrder(int $oID): ?BulkOrder
    {
        return $this->em->getRepository(BulkOrder::class)->findOneBy(['oID' => $oID]);
    }

    /**
     * @param int[] $oIDs
     * @return array<int, string> oID => batchId for the orders that came from a batch
     */
    public function getBatchesOfOrders(array $oIDs): array
    {
        $oIDs = array_values(array_filter(array_map('intval', $oIDs)));
        if ($oIDs === []) {
            return [];
        }
        $map = [];
        foreach ($this->em->getConnection()->fetchAllAssociative('SELECT oID, batchId FROM csOrderEditorBulkOrders WHERE oID IN (' . implode(',', $oIDs) . ')') as $row) {
            $map[(int) $row['oID']] = (string) $row['batchId'];
        }

        return $map;
    }

    public function newBatchId(): string
    {
        return date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    /**
     * @throws UserMessageException
     */
    protected function normalizeItems($rows): array
    {
        $items = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $kind = ($row['kind'] ?? 'product') === 'custom' ? 'custom' : 'product';
            $qty = $this->number($row['qty'] ?? '1', t('Quantity'));
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $price = trim((string) ($row['price'] ?? ''));
            if ($kind === 'product') {
                $pID = (int) ($row['pID'] ?? 0);
                if ($pID <= 0) {
                    continue;
                }
                $items[] = ['kind' => 'product', 'pID' => $pID, 'qty' => $qty, 'price' => $price === '' ? null : $this->number($price, t('Price'))];
            } else {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $items[] = ['kind' => 'custom', 'name' => $name, 'sku' => trim((string) ($row['sku'] ?? '')), 'qty' => $qty, 'price' => $this->number($price, t('Price'))];
            }
        }

        return $items;
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

    protected function userList(string $selection): UserList
    {
        $list = new UserList();
        $list->filterByIsActive(true);
        if ($selection !== self::ALL_USERS) {
            $list->filterByGroupID((int) $selection);
        }

        return $list;
    }
}
