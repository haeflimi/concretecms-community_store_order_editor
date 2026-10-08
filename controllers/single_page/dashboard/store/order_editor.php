<?php
namespace Concrete\Package\CommunityStoreOrderEditor\Controller\SinglePage\Dashboard\Store;

use CommunityStoreOrderEditor\Service\BulkOrders;
use CommunityStoreOrderEditor\Service\OrderEditor as Editor;
use Concrete\Core\Error\UserMessageException;
use Concrete\Core\Form\Service\Widget\DateTime as DateTimeWidget;
use Concrete\Core\Http\Request;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Search\Pagination\PaginationFactory;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\Order;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderList;
use Concrete\Package\CommunityStore\Src\CommunityStore\Order\OrderStatus\OrderStatus;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as PaymentMethod;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductList;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Dashboard › Store › Order Editor: list, create, edit and delete Community Store orders.
 */
class OrderEditor extends DashboardPageController
{
    const TOKEN = 'cs_order_editor';

    public function view()
    {
        $keywords = trim((string) $this->request->query->get('keywords', ''));
        $paymentStatus = (string) $this->request->query->get('paymentStatus', '');
        $cID = (int) $this->request->query->get('cID', 0);
        $batch = trim((string) $this->request->query->get('batch', ''));

        $list = new OrderList();
        $list->setItemsPerPage(50);
        if ($batch !== '') {
            $ids = $this->bulkService()->getOrderIDs($batch);
            $list->setOrderIDs($ids !== [] ? $ids : [0]);
        }
        if ($keywords !== '') {
            $list->setSearch($keywords);
        }
        if (in_array($paymentStatus, ['paid', 'unpaid', 'cancelled', 'refunded', 'incomplete'], true)) {
            $list->setPaymentStatus($paymentStatus);
        }
        if ($cID > 0) {
            $list->setCustomerID($cID);
        }
        $paginator = (new PaginationFactory($this->app->make(Request::class)))->createPaginationObject($list);

        $orders = $paginator->getCurrentPageResults();
        $this->set('orders', $orders);
        $this->set('batch', $batch);
        $this->set('batchOf', $this->bulkService()->getBatchesOfOrders(array_map(function ($o) { return (int) $o->getOrderID(); }, $orders)));
        $this->set('paginator', $paginator);
        $this->set('pagination', $paginator->renderDefaultView());
        $this->set('keywords', $keywords);
        $this->set('paymentStatus', $paymentStatus);
        $this->set('cID', $cID);
        $this->set('customerName', $cID > 0 ? $this->customerName($cID) : '');
        $this->set('pageTitle', t('Order Editor'));
    }

    public function add()
    {
        $this->setupForm(null);
        $this->set('pageTitle', t('New order'));
        $this->render('/dashboard/store/order_editor/edit');
    }

    public function edit($oID = null)
    {
        $order = $this->requireOrder($oID);
        if (!$order) {
            return $this->buildRedirect($this->action(''));
        }
        $this->setupForm($order);
        $this->set('pageTitle', t('Order #%s', $order->getOrderID()));
        $this->render('/dashboard/store/order_editor/edit');
    }

    /**
     * Header, totals and contact attributes; creates the order when there is no id.
     */
    public function save($oID = null)
    {
        if (!$this->validToken()) {
            return $this->buildRedirect($this->action(''));
        }
        $order = $oID !== null ? $this->requireOrder($oID) : null;
        if ($oID !== null && !$order) {
            return $this->buildRedirect($this->action(''));
        }
        $data = $this->request->request->all();
        $data['oDate'] = $this->app->make(DateTimeWidget::class)->translate('oDate', $data, true);
        try {
            if ($order) {
                $this->editor()->update($order, $data);
                $this->flash('success', t('Order saved.'));
            } else {
                $order = $this->editor()->create($data);
                $this->flash('success', t('Order #%s created. Now add its items.', $order->getOrderID()));
            }
        } catch (UserMessageException $e) {
            $this->flash('error', $e->getMessage());

            return $this->buildRedirect($order ? $this->action('edit', $order->getOrderID()) : $this->action('add'));
        }

        return $this->buildRedirect($this->action('edit', $order->getOrderID()));
    }

    public function add_item($oID = null)
    {
        $order = $this->requirePost($oID);
        if (!$order) {
            return $this->buildRedirect($this->action(''));
        }
        $p = $this->request->request;
        try {
            if ($p->get('kind') === 'custom') {
                $this->editor()->addCustomItem($order, (string) $p->get('name', ''), (float) $this->num($p->get('qty', 1)), (float) $this->num($p->get('price', 0)), trim((string) $p->get('sku', '')));
            } else {
                $price = trim((string) $p->get('price', ''));
                $this->editor()->addProduct($order, (int) $p->get('pID', 0), (float) $this->num($p->get('qty', 1)), $price === '' ? null : (float) $this->num($price));
            }
            $this->flash('success', t('Item added.'));
        } catch (UserMessageException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->buildRedirect($this->action('edit', $order->getOrderID()));
    }

    /**
     * One form for the whole items table: the "remove" button carries the item id, otherwise all rows are saved.
     */
    public function update_items($oID = null)
    {
        $order = $this->requirePost($oID);
        if (!$order) {
            return $this->buildRedirect($this->action(''));
        }
        $p = $this->request->request;
        try {
            if ((int) $p->get('remove', 0) > 0) {
                $this->editor()->removeItem($order, (int) $p->get('remove'));
                $this->flash('success', t('Item removed.'));
            } else {
                $rows = $p->get('items', []);
                $this->editor()->updateItems($order, is_array($rows) ? $rows : []);
                $this->flash('success', t('Items saved.'));
            }
        } catch (UserMessageException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->buildRedirect($this->action('edit', $order->getOrderID()));
    }

    public function recalculate($oID = null)
    {
        $order = $this->requirePost($oID);
        if (!$order) {
            return $this->buildRedirect($this->action(''));
        }
        $total = $this->editor()->recalculate($order);
        $this->flash('success', t('Total recalculated: %s', \Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price::format($total)));

        return $this->buildRedirect($this->action('edit', $order->getOrderID()));
    }

    public function update_status($oID = null)
    {
        $order = $this->requirePost($oID);
        if (!$order) {
            return $this->buildRedirect($this->action(''));
        }
        try {
            $this->editor()->updateStatus($order, (string) $this->request->request->get('status', ''), trim((string) $this->request->request->get('comment', '')));
            $this->flash('success', t('Status updated.'));
        } catch (UserMessageException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->buildRedirect($this->action('edit', $order->getOrderID()));
    }

    /**
     * Payment state transitions, chosen by the "do" field.
     */
    public function payment($oID = null)
    {
        $order = $this->requirePost($oID);
        if (!$order) {
            return $this->buildRedirect($this->action(''));
        }
        $p = $this->request->request;
        $uID = (int) $this->app->make(User::class)->getUserID();
        $editor = $this->editor();
        try {
            switch ((string) $p->get('do')) {
                case 'mark_paid':
                    $paidAt = $this->app->make(DateTimeWidget::class)->translate('oPaid', $p->all(), true);
                    $editor->markPaid($order, $uID, trim((string) $p->get('transactionReference', '')), $paidAt ?: null);
                    $this->flash('success', t('Order marked as paid.'));
                    break;
                case 'reverse_paid':
                    $editor->reversePaid($order);
                    $this->flash('success', t('Payment reversed.'));
                    break;
                case 'mark_refunded':
                    $editor->markRefunded($order, $uID, trim((string) $p->get('reason', '')));
                    $this->flash('success', t('Order marked as refunded.'));
                    break;
                case 'reverse_refund':
                    $editor->reverseRefund($order);
                    $this->flash('success', t('Refund reversed.'));
                    break;
                case 'mark_cancelled':
                    $editor->markCancelled($order, $uID);
                    $this->flash('success', t('Order cancelled.'));
                    break;
                case 'reverse_cancel':
                    $editor->reverseCancel($order);
                    $this->flash('success', t('Cancellation reversed.'));
                    break;
                default:
                    throw new UserMessageException(t('Unknown action.'));
            }
        } catch (UserMessageException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->buildRedirect($this->action('edit', $order->getOrderID()));
    }

    // ---- bulk creation

    public function bulk()
    {
        $bulk = $this->bulkService();
        $names = [];
        foreach ($bulk->getBatches() as $row) {
            $names[$row['byUID']] = $this->customerName($row['byUID']);
        }
        $this->set('targets', $bulk->getTargets());
        $this->set('batchId', $bulk->newBatchId());
        $this->set('paymentMethods', $this->paymentMethodOptions());
        $this->set('productOptions', $this->productOptions());
        $this->set('statuses', OrderStatus::getList());
        $this->set('batches', $bulk->getBatches());
        $this->set('userNames', $names);
        $this->set('countUrl', (string) $this->action('bulk_count'));
        $this->set('token', $this->app->make('token'));
        $this->set('tokenName', self::TOKEN);
        $this->set('dateWidget', $this->app->make(DateTimeWidget::class));
        $this->set('pageTitle', t('Bulk-create orders'));
        $this->render('/dashboard/store/order_editor/bulk');
    }

    /**
     * GET ?target=… → {"count": n}
     */
    public function bulk_count()
    {
        return new JsonResponse(['count' => $this->bulkService()->countUsers((string) $this->request->query->get('target', ''))]);
    }

    public function bulk_run()
    {
        if (!$this->validToken()) {
            return $this->buildRedirect($this->action('bulk'));
        }
        $data = $this->request->request->all();
        $data['oDate'] = $this->app->make(DateTimeWidget::class)->translate('oDate', $data, true);
        $batchId = (string) ($data['batchId'] ?? '');
        try {
            $result = $this->bulkService()->run($data, $batchId, (int) $this->app->make(User::class)->getUserID());
        } catch (UserMessageException $e) {
            $this->flash('error', $e->getMessage());

            return $this->buildRedirect($this->action('bulk'));
        }
        $message = t2('%d order created', '%d orders created', $result['created']) . ' (' . \Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price::format($result['total']) . ')';
        if ($result['skipped'] > 0) {
            $message .= ' ' . t('%d users already had an order from this batch.', $result['skipped']);
        }
        $this->flash('success', $message);

        return $this->buildRedirect($this->action('') . '?' . http_build_query(['batch' => preg_replace('/[^A-Za-z0-9_.\-]/', '', $batchId)]));
    }

    public function delete($oID = null)
    {
        $order = $this->requirePost($oID);
        if (!$order) {
            return $this->buildRedirect($this->action(''));
        }
        $id = $order->getOrderID();
        $this->editor()->delete($order);
        $this->flash('success', t('Order #%s deleted.', $id));

        return $this->buildRedirect($this->action(''));
    }

    // ---- helpers

    protected function paymentMethodOptions(): array
    {
        $methods = [];
        foreach (PaymentMethod::getMethods() as $pm) {
            $methods[(int) $pm->getID()] = ($pm->getDisplayName() ?: $pm->getName()) . ($pm->isEnabled() ? '' : ' (' . t('disabled') . ')');
        }

        return $methods;
    }

    protected function productOptions(): array
    {
        $products = new ProductList();
        $products->setActiveOnly(false);
        $products->setShowOutOfStock(true);
        $products->setItemsPerPage(1000);
        $products->setSortBy('alpha');
        $options = [];
        foreach ($products->getResults() as $product) {
            $options[(int) $product->getID()] = $product->getName() . ' (' . strip_tags((string) $product->getFormattedPrice()) . ')' . ($product->isActive() ? '' : ' [' . t('inactive') . ']');
        }

        return $options;
    }

    protected function setupForm(?Order $order): void
    {
        $editor = $this->editor();
        $methods = $this->paymentMethodOptions();
        $productOptions = $this->productOptions();
        $attributes = [];
        foreach (Editor::TEXT_ATTRIBUTES as $handle) {
            $attributes[$handle] = $order ? (string) $order->getAttribute($handle) : '';
        }
        $taxes = $order ? $order->getTaxes() : [];
        $taxLabels = array_filter(array_map(function ($t) { return $t['label']; }, $taxes));

        $this->set('order', $order);
        $this->set('bulkBatch', $order ? $this->bulkService()->getBatchOfOrder((int) $order->getOrderID()) : null);
        $this->set('items', $order ? $editor->getItems($order) : []);
        $this->set('itemsTotal', $order ? $editor->getItemsTotal($order) : 0.0);
        $this->set('paymentMethods', $methods);
        $this->set('productOptions', $productOptions);
        $this->set('statuses', OrderStatus::getList());
        $this->set('statusHistory', $order ? $order->getStatusHistory() : []);
        $this->set('attributes', $attributes);
        $this->set('taxLabel', implode(',', $taxLabels));
        $this->set('customerName', $order && (int) $order->getCustomerID() > 0 ? $this->customerName((int) $order->getCustomerID()) : '');
        $this->set('userNames', $order ? ['paid' => $this->customerName((int) $order->getPaidByUID()), 'refunded' => $this->customerName((int) $order->getRefundedByUID()), 'cancelled' => $this->customerName((int) $order->getCancelledByUID())] : []);
        $this->set('token', $this->app->make('token'));
        $this->set('tokenName', self::TOKEN);
        $this->set('dateWidget', $this->app->make(DateTimeWidget::class));
        $this->set('userSelector', $this->app->make('helper/form/user_selector'));
    }

    protected function requireOrder($oID): ?Order
    {
        $order = (int) $oID > 0 ? Order::getByID((int) $oID) : null;
        if (!$order) {
            $this->flash('error', t('Unknown order.'));
        }

        return $order ?: null;
    }

    protected function requirePost($oID): ?Order
    {
        if (!$this->validToken()) {
            return null;
        }

        return $this->requireOrder($oID);
    }

    protected function validToken(): bool
    {
        $token = $this->app->make('token');
        if ($this->request->getMethod() !== 'POST' || !$token->validate(self::TOKEN)) {
            $this->flash('error', $token->getErrorMessage());

            return false;
        }

        return true;
    }

    protected function editor(): Editor
    {
        return $this->app->make(Editor::class);
    }

    protected function bulkService(): BulkOrders
    {
        return $this->app->make(BulkOrders::class);
    }

    protected function customerName(int $uID): string
    {
        $ui = $uID > 0 ? $this->app->make(UserInfoRepository::class)->getByID($uID) : null;

        return $ui ? $ui->getUserName() : '';
    }

    /**
     * Accepts "1,5" as well as "1.5".
     */
    protected function num($value): string
    {
        $value = trim((string) $value);

        return is_numeric($value) ? $value : str_replace(',', '.', $value);
    }
}
