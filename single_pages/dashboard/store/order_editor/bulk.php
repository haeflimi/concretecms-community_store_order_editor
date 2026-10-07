<?php
defined('C5_EXECUTE') or die("Access Denied.");
use Concrete\Core\Support\Facade\Url;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;

$app = \Concrete\Core\Support\Facade\Application::getFacadeApplication();
$dh = $app->make('helper/date');
$base = Url::to('/dashboard/store/order_editor');
?>
<div class="ccm-dashboard-header-buttons">
    <a href="<?= $base ?>" class="btn btn-secondary"><?= t('Back to list') ?></a>
</div>

<form method="post" action="<?= $base ?>/bulk_run" id="csoe-bulk">
    <?php $token->output($tokenName) ?>
    <?= $form->hidden('batchId', $batchId) ?>
    <div class="row">
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header"><strong><?= t('Who gets an order') ?></strong></div>
                <div class="card-body">
                    <div class="form-group mb-3">
                        <?= $form->label('target', t('Group')) ?>
                        <?= $form->select('target', ['' => t('-- select --')] + $targets, '') ?>
                        <small class="text-muted"><?= t('One order is created for every active user of the selection.') ?> <strong id="csoe-bulk-count"></strong></small>
                    </div>
                    <div class="form-group mb-3">
                        <?= $form->label('label', t('Batch label')) ?>
                        <?= $form->text('label', '', ['placeholder' => t('e.g. Membership fee 2026'), 'maxlength' => 190]) ?>
                        <small class="text-muted"><?= t('Shown on every created order and in the batch list.') ?></small>
                    </div>
                </div>
            </div>
            <div class="card mb-4">
                <div class="card-header"><strong><?= t('Order') ?></strong></div>
                <div class="card-body">
                    <div class="form-group mb-3">
                        <?= $form->label('oDate', t('Order date')) ?>
                        <?= $dateWidget->datetime('oDate', new DateTime()) ?>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <?= $form->label('pmID', t('Payment method')) ?>
                            <?= $form->select('pmID', [0 => t('-- none / free text --')] + $paymentMethods, 0) ?>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <?= $form->label('pmName', t('Payment method name on the order')) ?>
                            <?= $form->text('pmName', '', ['placeholder' => t('taken from the method when empty')]) ?>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <?= $form->label('status', t('Status')) ?>
                        <?= $form->select('status', $statuses) ?>
                    </div>
                    <div class="form-group mb-3">
                        <?= $form->label('oNotes', t('Notes')) ?>
                        <?= $form->textarea('oNotes', '', ['rows' => 2]) ?>
                    </div>
                    <div class="form-check">
                        <?= $form->checkbox('markPaid', 1, false) ?>
                        <?= $form->label('markPaid', t('Mark the orders as paid right away'), ['class' => 'form-check-label']) ?>
                        <small class="text-muted d-block"><?= t('Runs the store\'s payment steps for every order; the batch id becomes the transaction reference.') ?></small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-4">
                <div class="card-header"><strong><?= t('Items on every order') ?></strong></div>
                <div class="card-body">
                    <?php
                    $productRow = function ($i) use ($form, $productOptions) { ?>
                        <tr class="csoe-row">
                            <td><span class="badge bg-secondary"><?= t('Product') ?></span><input type="hidden" name="items[<?= $i ?>][kind]" value="product"></td>
                            <td><?= $form->select('items[' . $i . '][pID]', ['' => t('-- select a product --')] + $productOptions, '', ['class' => 'form-select form-select-sm']) ?></td>
                            <td><input type="text" class="form-control form-control-sm text-end" name="items[<?= $i ?>][qty]" value="1"></td>
                            <td><input type="text" class="form-control form-control-sm text-end" name="items[<?= $i ?>][price]" placeholder="<?= h(t('list price')) ?>"></td>
                            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger csoe-remove-row" title="<?= h(t('Remove line')) ?>">&times;</button></td>
                        </tr>
                    <?php };
                    $customRow = function ($i) { ?>
                        <tr class="csoe-row">
                            <td><span class="badge bg-info text-dark"><?= t('Free text') ?></span><input type="hidden" name="items[<?= $i ?>][kind]" value="custom"></td>
                            <td>
                                <input type="text" class="form-control form-control-sm mb-1" name="items[<?= $i ?>][name]" placeholder="<?= h(t('Name, e.g. Membership fee 2026')) ?>">
                                <input type="text" class="form-control form-control-sm" name="items[<?= $i ?>][sku]" placeholder="<?= h(t('SKU (optional)')) ?>">
                            </td>
                            <td><input type="text" class="form-control form-control-sm text-end" name="items[<?= $i ?>][qty]" value="1"></td>
                            <td><input type="text" class="form-control form-control-sm text-end" name="items[<?= $i ?>][price]" placeholder="<?= h(t('amount')) ?>"></td>
                            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger csoe-remove-row" title="<?= h(t('Remove line')) ?>">&times;</button></td>
                        </tr>
                    <?php };
                    ?>
                    <table class="table table-sm align-middle" id="csoe-bulk-items">
                        <thead>
                        <tr><th style="width:100px"><?= t('Type') ?></th><th><?= t('Product / name') ?></th><th style="width:90px"><?= t('Qty') ?></th><th style="width:120px"><?= t('Price') ?></th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php $productRow(0); ?>
                        <?php $customRow(1); ?>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="csoe-bulk-add-product"><?= t('Add product line') ?></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="csoe-bulk-add-custom"><?= t('Add free-text line') ?></button>
                    <small class="text-muted d-block mt-2"><?= t('Lines without a product or without a name are ignored. Product lines use the product\'s current price unless a price is given. Free-text lines need a name and a price; a negative price books a reduction. Quantities and prices accept a comma as decimal separator.') ?></small>
                </div>
            </div>
            <template id="csoe-tpl-product"><?php $productRow('IDX'); ?></template>
            <template id="csoe-tpl-custom"><?php $customRow('IDX'); ?></template>
        </div>
    </div>
    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <button type="submit" class="btn btn-primary float-end" id="csoe-bulk-submit"><?= t('Create orders') ?></button>
        </div>
    </div>
</form>


<?php if ($batches) { ?>
    <h4 class="mt-4"><?= t('Past batches') ?></h4>
    <table class="table table-striped">
        <thead><tr><th><?= t('Batch') ?></th><th><?= t('Label') ?></th><th><?= t('Created') ?></th><th><?= t('By') ?></th><th class="text-end"><?= t('Orders') ?></th><th class="text-end"><?= t('Total') ?></th></tr></thead>
        <tbody>
        <?php foreach ($batches as $b) { ?>
            <tr>
                <td><a href="<?= $base ?>?batch=<?= h($b['batchId']) ?>"><code><?= h($b['batchId']) ?></code></a></td>
                <td><?= h($b['label']) ?></td>
                <td><?= $dh->formatDateTime(new DateTime($b['createdAt'])) ?></td>
                <td><?= h($userNames[$b['byUID']] ?? '') ?></td>
                <td class="text-end"><?= $b['orders'] ?></td>
                <td class="text-end"><?= Price::format($b['total']) ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
<?php } ?>

<script>
(function () {
    var countUrl = <?= json_encode($countUrl) ?>;
    var countLabel = <?= json_encode(t('%s users will get an order.')) ?>;
    var confirmLabel = <?= json_encode(t('Create an order for %s users?')) ?>;
    var affected = null;
    var target = document.querySelector('#csoe-bulk select[name=target]');
    var countEl = document.getElementById('csoe-bulk-count');
    var tbody = document.querySelector('#csoe-bulk-items tbody');
    var idx = 100;

    function refreshCount() {
        affected = null;
        if (!target.value) { countEl.textContent = ''; return; }
        countEl.textContent = '…';
        fetch(countUrl + '?target=' + encodeURIComponent(target.value), {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) { affected = d.count; countEl.textContent = countLabel.replace('%s', d.count); })
            .catch(function () { countEl.textContent = ''; });
    }
    function bindRemove(row) {
        row.querySelector('.csoe-remove-row').addEventListener('click', function () { row.remove(); });
    }
    function addRow(tplId) {
        var wrap = document.createElement('tbody');
        wrap.innerHTML = document.getElementById(tplId).innerHTML.replace(/IDX/g, String(idx++));
        var row = wrap.querySelector('tr');
        bindRemove(row);
        tbody.appendChild(row);
        row.querySelector('select, input[type=text]').focus();
    }
    Array.prototype.forEach.call(tbody.querySelectorAll('tr.csoe-row'), bindRemove);
    document.getElementById('csoe-bulk-add-product').addEventListener('click', function () { addRow('csoe-tpl-product'); });
    document.getElementById('csoe-bulk-add-custom').addEventListener('click', function () { addRow('csoe-tpl-custom'); });
    target.addEventListener('change', refreshCount);
    document.getElementById('csoe-bulk').addEventListener('submit', function (e) {
        if (!window.confirm(confirmLabel.replace('%s', affected === null ? '?' : affected))) {
            e.preventDefault();
            return;
        }
        document.getElementById('csoe-bulk-submit').disabled = true;
    });
})();
</script>
