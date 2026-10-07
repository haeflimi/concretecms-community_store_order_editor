<?php
defined('C5_EXECUTE') or die("Access Denied.");
use Concrete\Core\Support\Facade\Url;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;

$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$dh = $app->make('helper/date');
$base = Url::to('/dashboard/store/order_editor');
$oID = $order ? (int) $order->getOrderID() : 0;
$isNew = $order === null;
$paid = $order ? $order->getPaid() : null;
$refunded = $order ? $order->getRefunded() : null;
$cancelled = $order ? $order->getCancelled() : null;
$itemsLocked = $paid && !$refunded;
$lockAttr = $itemsLocked ? ['disabled' => 'disabled'] : [];
$v = function ($getter, $default = '') use ($order) {
    return $order ? (string) $order->$getter() : $default;
};
?>
<div class="ccm-dashboard-header-buttons">
    <?php if (!$isNew) { ?>
        <a href="<?= Url::to('/dashboard/store/orders/order/' . $oID) ?>" class="btn btn-secondary"><?= t('Store view') ?></a>
    <?php } ?>
    <a href="<?= $base ?>" class="btn btn-secondary"><?= t('Back to list') ?></a>
</div>

<?php if (!$isNew) {
    if ($cancelled) {
        $stateBadge = 'bg-secondary'; $stateLabel = t('Cancelled');
    } elseif ($refunded) {
        $stateBadge = 'bg-warning text-dark'; $stateLabel = t('Refunded');
    } elseif ($paid) {
        $stateBadge = 'bg-success'; $stateLabel = t('Paid');
    } elseif ($order->getExternalPaymentRequested()) {
        $stateBadge = 'bg-info text-dark'; $stateLabel = t('Payment pending');
    } else {
        $stateBadge = 'bg-danger'; $stateLabel = t('Unpaid');
    }
    $diff = round((float) $order->getTotal() - ($itemsTotal + (float) $order->getShippingTotal() + (float) $order->getTaxTotal()), 2);
    ?>
    <div class="row mb-4">
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Payment') ?></div><h4 class="mb-0"><span class="badge <?= $stateBadge ?>"><?= $stateLabel ?></span></h4></div></div>
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Order total') ?></div><h4 class="mb-0"><?= Price::format($order->getTotal()) ?></h4></div></div>
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Items (%d lines)', count($items)) ?></div><h4 class="mb-0"><?= Price::format($itemsTotal) ?></h4></div></div>
        <div class="col-md-3"><div class="card card-body"><div class="text-muted small"><?= t('Status') ?></div><h4 class="mb-0"><?= h($order->getStatus()) ?></h4></div></div>
    </div>
    <?php if ($bulkBatch) { ?>
        <div class="alert alert-light border"><?= t('Created by bulk batch %s (%s).', '<a href="' . $base . '?batch=' . h($bulkBatch->getBatchId()) . '"><code>' . h($bulkBatch->getBatchId()) . '</code></a>', h($bulkBatch->getLabel())) ?></div>
    <?php } ?>
    <?php if (abs($diff) > 0.005) { ?>
        <div class="alert alert-warning"><?= t('The order total differs by %s from items + shipping + tax. Use "Recalculate total" below or correct the total by hand.', Price::format($diff)) ?></div>
    <?php } ?>
<?php } ?>

<form method="post" action="<?= $base ?>/save<?= $isNew ? '' : '/' . $oID ?>">
    <?php $token->output($tokenName) ?>
    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header"><strong><?= t('Order') ?></strong></div>
                <div class="card-body">
                    <div class="form-group mb-3">
                        <?= $form->label('cID', t('Customer')) ?>
                        <?= $userSelector->quickSelect('cID', $order && (int) $order->getCustomerID() > 0 ? (int) $order->getCustomerID() : false) ?>
                        <small class="text-muted"><?= t('Leave empty for an order without a user account.') ?><?= $customerName ? ' ' . t('Current: %s', h($customerName)) : '' ?></small>
                    </div>
                    <div class="form-group mb-3">
                        <?= $form->label('oDate', t('Order date')) ?>
                        <?= $dateWidget->datetime('oDate', $order ? $order->getOrderDate() : new DateTime()) ?>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <?= $form->label('pmID', t('Payment method')) ?>
                            <?= $form->select('pmID', [0 => t('-- none / free text --')] + $paymentMethods, $order ? (int) $order->getPaymentMethodID() : 0) ?>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <?= $form->label('pmName', t('Payment method name on the order')) ?>
                            <?= $form->text('pmName', $v('getPaymentMethodName'), ['placeholder' => t('taken from the method when empty')]) ?>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <?= $form->label('smName', t('Shipping method')) ?>
                            <?= $form->text('smName', $v('getShippingMethodName')) ?>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <?= $form->label('transactionReference', t('Transaction reference')) ?>
                            <?= $form->text('transactionReference', $v('getTransactionReference')) ?>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <?= $form->label('sInstructions', t('Shipping instructions')) ?>
                        <?= $form->textarea('sInstructions', $v('getShippingInstructions'), ['rows' => 2]) ?>
                    </div>
                    <div class="form-group mb-3">
                        <?= $form->label('oNotes', t('Notes')) ?>
                        <?= $form->textarea('oNotes', $v('getNotes'), ['rows' => 3]) ?>
                    </div>
                    <?php if ($isNew) { ?>
                        <div class="form-group mb-3">
                            <?= $form->label('status', t('Initial status')) ?>
                            <?= $form->select('status', $statuses) ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header"><strong><?= t('Contact and addresses') ?></strong></div>
                <div class="card-body">
                    <div class="form-group mb-3">
                        <?= $form->label('attributes[email]', t('E-mail')) ?>
                        <?= $form->email('attributes[email]', $attributes['email'], ['placeholder' => t('taken from the user account when empty')]) ?>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3"><?= $form->label('attributes[billing_first_name]', t('Billing first name')) ?><?= $form->text('attributes[billing_first_name]', $attributes['billing_first_name']) ?></div>
                        <div class="col-md-6 form-group mb-3"><?= $form->label('attributes[billing_last_name]', t('Billing last name')) ?><?= $form->text('attributes[billing_last_name]', $attributes['billing_last_name']) ?></div>
                        <div class="col-md-6 form-group mb-3"><?= $form->label('attributes[billing_company]', t('Billing company')) ?><?= $form->text('attributes[billing_company]', $attributes['billing_company']) ?></div>
                        <div class="col-md-6 form-group mb-3"><?= $form->label('attributes[billing_phone]', t('Billing phone')) ?><?= $form->text('attributes[billing_phone]', $attributes['billing_phone']) ?></div>
                        <div class="col-md-6 form-group mb-3"><?= $form->label('attributes[shipping_first_name]', t('Shipping first name')) ?><?= $form->text('attributes[shipping_first_name]', $attributes['shipping_first_name']) ?></div>
                        <div class="col-md-6 form-group mb-3"><?= $form->label('attributes[shipping_last_name]', t('Shipping last name')) ?><?= $form->text('attributes[shipping_last_name]', $attributes['shipping_last_name']) ?></div>
                        <div class="col-md-6 form-group mb-3"><?= $form->label('attributes[shipping_company]', t('Shipping company')) ?><?= $form->text('attributes[shipping_company]', $attributes['shipping_company']) ?></div>
                        <div class="col-md-6 form-group mb-3"><?= $form->label('attributes[vat_number]', t('VAT number')) ?><?= $form->text('attributes[vat_number]', $attributes['vat_number']) ?></div>
                    </div>
                    <?php if ($order) {
                        $billing = $order->getAttributeValueObject('billing_address');
                        $shipping = $order->getAttributeValueObject('shipping_address');
                        if ($billing || $shipping) { ?>
                            <div class="text-muted small">
                                <?= t('Addresses (read-only here, edit them on the store view):') ?>
                                <?php if ($billing) { ?><div><strong><?= t('Billing') ?>:</strong> <?= strip_tags((string) $billing->getDisplayValue(), '<br>') ?></div><?php } ?>
                                <?php if ($shipping) { ?><div><strong><?= t('Shipping') ?>:</strong> <?= strip_tags((string) $shipping->getDisplayValue(), '<br>') ?></div><?php } ?>
                            </div>
                        <?php }
                    } ?>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header"><strong><?= t('Totals') ?></strong></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 form-group mb-3"><?= $form->label('oShippingTotal', t('Shipping')) ?><?= $form->text('oShippingTotal', number_format((float) $v('getShippingTotal', '0'), 2, '.', '')) ?></div>
                        <div class="col-md-4 form-group mb-3"><?= $form->label('oTax', t('Tax (added)')) ?><?= $form->text('oTax', number_format($order ? (float) $order->getTaxTotal() : 0, 2, '.', '')) ?></div>
                        <div class="col-md-4 form-group mb-3"><?= $form->label('oTaxIncluded', t('Tax (included)')) ?><?= $form->text('oTaxIncluded', number_format($order ? (float) $order->getIncludedTaxTotal() : 0, 2, '.', '')) ?></div>
                        <div class="col-md-6 form-group mb-3"><?= $form->label('oTaxName', t('Tax label')) ?><?= $form->text('oTaxName', $taxLabel) ?></div>
                        <div class="col-md-6 form-group mb-3"><?= $form->label('oTotal', t('Order total')) ?><?= $form->text('oTotal', number_format((float) $v('getTotal', '0'), 2, '.', '')) ?></div>
                    </div>
                    <div class="form-check">
                        <?= $form->checkbox('recalculate', 1, false) ?>
                        <?= $form->label('recalculate', t('Set the total from items + shipping + added tax when saving'), ['class' => 'form-check-label']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <button type="submit" class="btn btn-primary float-end"><?= $isNew ? t('Create order') : t('Save order') ?></button>
        </div>
    </div>
</form>

<?php if ($isNew) {
    return;
} ?>

<div class="card mb-4">
    <div class="card-header"><strong><?= t('Items') ?></strong></div>
    <div class="card-body">
        <?php if ($itemsLocked) { ?>
            <div class="alert alert-info"><?= t('The order is paid. Reverse the payment to change its items.') ?></div>
        <?php } ?>
        <form method="post" action="<?= $base ?>/update_items/<?= $oID ?>">
            <?php $token->output($tokenName) ?>
            <table class="table table-sm align-middle">
                <thead>
                <tr><th><?= t('Id') ?></th><th><?= t('Name') ?></th><th><?= t('SKU') ?></th><th style="width:110px"><?= t('Qty') ?></th><th style="width:140px"><?= t('Price') ?></th><th class="text-end"><?= t('Subtotal') ?></th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item) {
                    $id = (int) $item->getID();
                    $options = [];
                    foreach ($item->getOrderItemOptions() as $opt) {
                        $options[] = $opt->getOrderItemOptionKey() . ': ' . $opt->getOrderItemOptionValue();
                    } ?>
                    <tr>
                        <td>#<?= $id ?><?php if ((int) $item->getProductID() > 0) { ?><br><small class="text-muted"><?= t('Product %s', $item->getProductID()) ?></small><?php } ?></td>
                        <td>
                            <?= $form->text('items[' . $id . '][name]', $item->getProductName(), ['class' => 'form-control form-control-sm'] + $lockAttr) ?>
                            <?php if ($options) { ?><small class="text-muted"><?= h(implode(', ', $options)) ?></small><?php } ?>
                        </td>
                        <td><?= $form->text('items[' . $id . '][sku]', (string) $item->getSKU(), ['class' => 'form-control form-control-sm'] + $lockAttr) ?></td>
                        <td><?= $form->text('items[' . $id . '][qty]', rtrim(rtrim(number_format((float) $item->getQuantity(), 4, '.', ''), '0'), '.'), ['class' => 'form-control form-control-sm text-end'] + $lockAttr) ?></td>
                        <td><?= $form->text('items[' . $id . '][price]', number_format((float) $item->getPricePaid(), 2, '.', ''), ['class' => 'form-control form-control-sm text-end'] + $lockAttr) ?></td>
                        <td class="text-end"><?= Price::format((float) $item->getPricePaid() * (float) $item->getQuantity()) ?></td>
                        <td class="text-end">
                            <?php if (!$itemsLocked) { ?>
                                <button type="submit" name="remove" value="<?= $id ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('<?= h(t('Remove this item?')) ?>')"><?= t('Remove') ?></button>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (empty($items)) { ?><tr><td colspan="7" class="text-muted"><?= t('No items yet.') ?></td></tr><?php } ?>
                </tbody>
                <tfoot>
                <tr><th colspan="5" class="text-end"><?= t('Items total') ?></th><th class="text-end"><?= Price::format($itemsTotal) ?></th><th></th></tr>
                </tfoot>
            </table>
            <?php if (!$itemsLocked && $items) { ?>
                <button type="submit" class="btn btn-secondary"><?= t('Save items') ?></button>
            <?php } ?>
        </form>
        <?php if (!$itemsLocked) { ?>
            <hr>
            <div class="row">
                <div class="col-lg-6">
                    <h5><?= t('Add a product') ?></h5>
                    <form method="post" action="<?= $base ?>/add_item/<?= $oID ?>">
                        <?php $token->output($tokenName) ?>
                        <input type="hidden" name="kind" value="product">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6"><?= $form->label('pID', t('Product')) ?><?= $form->select('pID', $productOptions) ?></div>
                            <div class="col-md-2"><?= $form->label('qty', t('Qty')) ?><?= $form->text('qty', '1') ?></div>
                            <div class="col-md-2"><?= $form->label('price', t('Price')) ?><?= $form->text('price', '', ['placeholder' => t('list')]) ?></div>
                            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><?= t('Add') ?></button></div>
                        </div>
                        <small class="text-muted"><?= t('Leave the price empty to use the product\'s current price.') ?></small>
                    </form>
                </div>
                <div class="col-lg-6">
                    <h5><?= t('Add a free-text line') ?></h5>
                    <form method="post" action="<?= $base ?>/add_item/<?= $oID ?>">
                        <?php $token->output($tokenName) ?>
                        <input type="hidden" name="kind" value="custom">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-4"><?= $form->label('name', t('Name')) ?><?= $form->text('name', '') ?></div>
                            <div class="col-md-2"><?= $form->label('sku', t('SKU')) ?><?= $form->text('sku', '') ?></div>
                            <div class="col-md-2"><?= $form->label('cqty', t('Qty')) ?><?= $form->text('qty', '1', ['id' => 'cqty']) ?></div>
                            <div class="col-md-2"><?= $form->label('cprice', t('Price')) ?><?= $form->text('price', '', ['id' => 'cprice']) ?></div>
                            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><?= t('Add') ?></button></div>
                        </div>
                        <small class="text-muted"><?= t('A negative price books a reduction.') ?></small>
                    </form>
                </div>
            </div>
        <?php } ?>
        <hr>
        <form method="post" action="<?= $base ?>/recalculate/<?= $oID ?>" class="d-inline">
            <?php $token->output($tokenName) ?>
            <button type="submit" class="btn btn-outline-secondary"><?= t('Recalculate total') ?></button>
            <small class="text-muted ms-2"><?= t('items %s + shipping %s + added tax %s', Price::format($itemsTotal), Price::format($order->getShippingTotal()), Price::format($order->getTaxTotal())) ?></small>
        </form>
    </div>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header"><strong><?= t('Payment state') ?></strong></div>
            <div class="card-body">
                <dl class="row mb-3">
                    <dt class="col-sm-4"><?= t('Paid') ?></dt>
                    <dd class="col-sm-8"><?= $paid ? $dh->formatDateTime($paid) . ($userNames['paid'] ? ' (' . h($userNames['paid']) . ')' : '') : '–' ?></dd>
                    <dt class="col-sm-4"><?= t('Refunded') ?></dt>
                    <dd class="col-sm-8"><?= $refunded ? $dh->formatDateTime($refunded) . ($userNames['refunded'] ? ' (' . h($userNames['refunded']) . ')' : '') . ($order->getRefundReason() ? '<br><small>' . h($order->getRefundReason()) . '</small>' : '') : '–' ?></dd>
                    <dt class="col-sm-4"><?= t('Cancelled') ?></dt>
                    <dd class="col-sm-8"><?= $cancelled ? $dh->formatDateTime($cancelled) . ($userNames['cancelled'] ? ' (' . h($userNames['cancelled']) . ')' : '') : '–' ?></dd>
                    <dt class="col-sm-4"><?= t('External payment') ?></dt>
                    <dd class="col-sm-8"><?= $order->getExternalPaymentRequested() ? t('requested %s', $dh->formatDateTime($order->getExternalPaymentRequested())) : '–' ?></dd>
                </dl>

                <?php if (!$paid) { ?>
                    <form method="post" action="<?= $base ?>/payment/<?= $oID ?>" class="border rounded p-3 mb-3">
                        <?php $token->output($tokenName) ?>
                        <input type="hidden" name="do" value="mark_paid">
                        <div class="form-group mb-2"><?= $form->label('oPaid', t('Paid on')) ?><?= $dateWidget->datetime('oPaid', new DateTime()) ?></div>
                        <div class="form-group mb-2"><?= $form->label('transactionReference', t('Transaction reference')) ?><?= $form->text('transactionReference', $v('getTransactionReference'), ['id' => 'pay_transactionReference']) ?></div>
                        <button type="submit" class="btn btn-success"><?= t('Mark paid') ?></button>
                        <small class="text-muted d-block mt-1"><?= t('Runs the store\'s payment steps (stock, user groups, store credit, tickets), like "Mark Paid" on the store view.') ?></small>
                    </form>
                <?php } else { ?>
                    <form method="post" action="<?= $base ?>/payment/<?= $oID ?>" class="d-inline">
                        <?php $token->output($tokenName) ?>
                        <input type="hidden" name="do" value="reverse_paid">
                        <button type="submit" class="btn btn-outline-warning mb-2" onclick="return confirm('<?= h(t('Reverse the payment?')) ?>')"><?= t('Reverse payment') ?></button>
                    </form>
                <?php } ?>

                <?php if (!$refunded) { ?>
                    <form method="post" action="<?= $base ?>/payment/<?= $oID ?>" class="border rounded p-3 mb-3">
                        <?php $token->output($tokenName) ?>
                        <input type="hidden" name="do" value="mark_refunded">
                        <div class="form-group mb-2"><?= $form->label('reason', t('Refund reason')) ?><?= $form->text('reason', '') ?></div>
                        <button type="submit" class="btn btn-warning"><?= t('Mark refunded') ?></button>
                    </form>
                <?php } else { ?>
                    <form method="post" action="<?= $base ?>/payment/<?= $oID ?>" class="d-inline">
                        <?php $token->output($tokenName) ?>
                        <input type="hidden" name="do" value="reverse_refund">
                        <button type="submit" class="btn btn-outline-warning mb-2"><?= t('Reverse refund') ?></button>
                    </form>
                <?php } ?>

                <?php if (!$cancelled) { ?>
                    <form method="post" action="<?= $base ?>/payment/<?= $oID ?>" class="d-inline">
                        <?php $token->output($tokenName) ?>
                        <input type="hidden" name="do" value="mark_cancelled">
                        <button type="submit" class="btn btn-outline-danger mb-2" onclick="return confirm('<?= h(t('Cancel this order? Store credit and event tickets linked to it will be released.')) ?>')"><?= t('Cancel order') ?></button>
                    </form>
                <?php } else { ?>
                    <form method="post" action="<?= $base ?>/payment/<?= $oID ?>" class="d-inline">
                        <?php $token->output($tokenName) ?>
                        <input type="hidden" name="do" value="reverse_cancel">
                        <button type="submit" class="btn btn-outline-secondary mb-2"><?= t('Reverse cancellation') ?></button>
                    </form>
                <?php } ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header"><strong><?= t('Fulfilment status') ?></strong></div>
            <div class="card-body">
                <form method="post" action="<?= $base ?>/update_status/<?= $oID ?>" class="mb-3">
                    <?php $token->output($tokenName) ?>
                    <div class="row g-2 align-items-end">
                        <div class="col-md-5"><?= $form->label('status', t('Status')) ?><?= $form->select('status', $statuses, $order->getStatusHandle()) ?></div>
                        <div class="col-md-5"><?= $form->label('comment', t('Comment')) ?><?= $form->text('comment', '') ?></div>
                        <div class="col-md-2"><button type="submit" class="btn btn-secondary w-100"><?= t('Update') ?></button></div>
                    </div>
                </form>
                <table class="table table-sm">
                    <thead><tr><th><?= t('Date') ?></th><th><?= t('Status') ?></th><th><?= t('Comment') ?></th><th><?= t('By') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($statusHistory as $entry) { ?>
                        <tr>
                            <td class="text-nowrap"><?= $dh->formatDateTime(new DateTime($entry->getDate('Y-m-d H:i:s'))) ?></td>
                            <td><?= h($entry->getOrderStatusName()) ?></td>
                            <td><?= h($entry->getOrderStatusComment()) ?></td>
                            <td><?= h($entry->getUserName()) ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card border-danger mb-4">
            <div class="card-header text-danger"><strong><?= t('Delete') ?></strong></div>
            <div class="card-body">
                <form method="post" action="<?= $base ?>/delete/<?= $oID ?>">
                    <?php $token->output($tokenName) ?>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('<?= h(t('Delete order #%s with all its items? This cannot be undone.', $oID)) ?>')"><?= t('Delete order') ?></button>
                    <small class="text-muted ms-2"><?= t('Removes the order, its items, attributes, discounts and status history. Nothing is refunded or re-stocked.') ?></small>
                </form>
            </div>
        </div>
    </div>
</div>
