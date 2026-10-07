<?php
defined('C5_EXECUTE') or die("Access Denied.");
use Concrete\Core\Support\Facade\Url;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;

$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$dh = $app->make('helper/date');
$paymentState = function ($order) {
    if ($order->getCancelled()) {
        return ['bg-secondary', t('Cancelled')];
    }
    if ($order->getRefunded()) {
        return ['bg-warning text-dark', t('Refunded')];
    }
    if ($order->getPaid()) {
        return ['bg-success', t('Paid')];
    }
    if ($order->getExternalPaymentRequested()) {
        return ['bg-info text-dark', t('Payment pending')];
    }
    return ['bg-danger', t('Unpaid')];
};
?>
<div class="ccm-dashboard-header-buttons">
    <a href="<?= Url::to('/dashboard/store/order_editor/bulk') ?>" class="btn btn-secondary"><?= t('Bulk-create orders') ?></a>
    <a href="<?= Url::to('/dashboard/store/order_editor/add') ?>" class="btn btn-primary"><?= t('New order') ?></a>
</div>

<?php if ($batch !== '') { ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <span><?= t('Showing the orders of batch %s.', '<code>' . h($batch) . '</code>') ?></span>
        <a href="<?= Url::to('/dashboard/store/order_editor') ?>" class="btn btn-sm btn-outline-secondary"><?= t('Show all orders') ?></a>
    </div>
<?php } ?>

<form action="<?= Url::to('/dashboard/store/order_editor') ?>" method="get">
    <?php if ($batch !== '') { ?><?= $form->hidden('batch', $batch) ?><?php } ?>
    <div class="row">
        <div class="col-md-4">
            <div class="form-group">
                <?= $form->label('keywords', t('Search')) ?>
                <?= $form->text('keywords', $keywords, ['placeholder' => t('Order id, transaction reference, name, e-mail')]) ?>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <?= $form->label('paymentStatus', t('Payment state')) ?>
                <?= $form->select('paymentStatus', ['' => t('All'), 'unpaid' => t('Unpaid'), 'paid' => t('Paid'), 'incomplete' => t('Payment pending'), 'refunded' => t('Refunded'), 'cancelled' => t('Cancelled')], $paymentStatus) ?>
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                <?= $form->label('cID', t('Customer id')) ?>
                <?= $form->number('cID', $cID ?: '', ['placeholder' => t('User id')]) ?>
                <?php if ($customerName) { ?><small class="text-muted"><?= h($customerName) ?></small><?php } ?>
            </div>
        </div>
        <div class="col-md-2">
            <div class="form-group">
                <label>&nbsp;</label><br>
                <button type="submit" class="btn btn-secondary"><?= t('Filter') ?></button>
            </div>
        </div>
    </div>
</form>

<hr>

<table class="table table-striped align-middle">
    <thead>
    <tr>
        <th><?= t('Order #') ?></th>
        <th><?= t('Date') ?></th>
        <th><?= t('Customer') ?></th>
        <th><?= t('Payment method') ?></th>
        <th><?= t('Status') ?></th>
        <th><?= t('Payment') ?></th>
        <th class="text-end"><?= t('Total') ?></th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($orders as $order) {
        list($badge, $label) = $paymentState($order);
        $name = trim($order->getAttribute('billing_first_name') . ' ' . $order->getAttribute('billing_last_name'));
        ?>
        <tr>
            <td><a href="<?= Url::to('/dashboard/store/order_editor/edit/' . $order->getOrderID()) ?>">#<?= $order->getOrderID() ?></a></td>
            <td><?= $dh->formatDateTime($order->getOrderDate()) ?></td>
            <td>
                <?= h($name) ?>
                <?php if ($order->getAttribute('email')) { ?><br><small class="text-muted"><?= h($order->getAttribute('email')) ?></small><?php } ?>
                <?php if ((int) $order->getCustomerID() > 0) { ?><br><small class="text-muted"><?= t('User id %s', $order->getCustomerID()) ?></small><?php } ?>
                <?php if (isset($batchOf[(int) $order->getOrderID()])) { ?><br><a class="badge bg-light text-dark text-decoration-none" href="<?= Url::to('/dashboard/store/order_editor') ?>?batch=<?= h($batchOf[(int) $order->getOrderID()]) ?>"><?= t('batch %s', h($batchOf[(int) $order->getOrderID()])) ?></a><?php } ?>
            </td>
            <td><?= h($order->getPaymentMethodName()) ?></td>
            <td><?= h($order->getStatus()) ?></td>
            <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
            <td class="text-end"><?= Price::format($order->getTotal()) ?></td>
            <td class="text-end text-nowrap">
                <a href="<?= Url::to('/dashboard/store/order_editor/edit/' . $order->getOrderID()) ?>" class="btn btn-sm btn-primary"><?= t('Edit') ?></a>
                <a href="<?= Url::to('/dashboard/store/orders/order/' . $order->getOrderID()) ?>" class="btn btn-sm btn-secondary"><?= t('Store view') ?></a>
            </td>
        </tr>
    <?php } ?>
    <?php if (empty($orders)) { ?>
        <tr><td colspan="8" class="text-muted"><?= t('No orders match.') ?></td></tr>
    <?php } ?>
    </tbody>
</table>

<?php if ($paginator->getTotalPages() > 1) { ?>
    <?= $pagination ?>
<?php } ?>
