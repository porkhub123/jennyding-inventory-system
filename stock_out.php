<?php
require 'config/db.php';
require_login();

// Why stock can leave the store
$reasons = ['Sold', 'Expired', 'Damaged', 'Personal use', 'Returned to supplier', 'Other'];

$errors = [];
$form = [
    'reason'      => 'Sold',
    'payment'     => 'cash',              // Sold items: paid in cash, or on credit (utang)
    'customer_id' => (int)($_GET['customer'] ?? 0),
    'notes'       => '',
    'items'  => [['product_id' => (int)($_GET['product'] ?? 0), 'quantity' => '']], // one empty row
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['reason'] = $_POST['reason'] ?? '';
    $form['notes']  = trim($_POST['notes'] ?? '');
    $form['payment']     = ($_POST['payment'] ?? 'cash') === 'credit' ? 'credit' : 'cash';
    $form['customer_id'] = (int)($_POST['customer_id'] ?? 0);
    $form['items']  = [];

    $ids  = $_POST['product_id'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    foreach ($ids as $i => $pid) {
        $pid = (int)$pid;
        $qty = trim($qtys[$i] ?? '');
        if ($pid === 0 && $qty === '') continue; // skip completely empty rows
        $form['items'][] = ['product_id' => $pid, 'quantity' => $qty];
    }

    // ----- Validate -----
    if (!in_array($form['reason'], $reasons, true)) $errors[] = 'Please choose a reason.';
    if (!$form['items']) {
        $errors[] = 'Add at least one item.';
        $form['items'][] = ['product_id' => 0, 'quantity' => ''];
    }

    // Total quantity per product (in case the same product is listed twice)
    $totals = [];
    foreach ($form['items'] as $n => $item) {
        $row = $n + 1;
        if ($item['product_id'] <= 0) { $errors[] = "Row $row: choose a product."; continue; }
        if (filter_var($item['quantity'], FILTER_VALIDATE_INT) === false || (int)$item['quantity'] <= 0) {
            $errors[] = "Row $row: quantity must be a whole number greater than 0.";
            continue;
        }
        $totals[$item['product_id']] = ($totals[$item['product_id']] ?? 0) + (int)$item['quantity'];
    }

    // Check there is enough stock for each product (the Product object knows the rule)
    $products = [];
    foreach ($totals as $pid => $qty) {
        $product = Product::find($conn, $pid);
        if (!$product) { $errors[] = 'A selected product no longer exists.'; continue; }
        if (!$product->hasEnough($qty)) {
            $errors[] = "Not enough stock for {$product->getName()}: only {$product->getStock()} {$product->getUnit()} left, but you entered $qty.";
        }
        $products[$pid] = $product;
    }

    // Sold on credit (utang): need a customer, and stay within their credit limit
    $onCredit = $form['reason'] === 'Sold' && $form['payment'] === 'credit';
    $customer = null;
    if ($onCredit && !$errors) {
        $customer = Customer::find($conn, $form['customer_id']);
        if (!$customer) {
            $errors[] = 'Choose the customer who is buying on credit.';
        } else {
            $saleValue = 0;
            foreach ($totals as $pid => $qty) $saleValue += $qty * $products[$pid]->getSellingPrice();
            if (!$customer->canCharge($saleValue)) {
                $errors[] = "{$customer->getName()} would go over the credit limit of ₱" . number_format($customer->getCreditLimit(), 2)
                          . ' (current balance ₱' . number_format($customer->getBalance(), 2) . ').';
            }
        }
    }

    // ----- Save everything in one transaction -----
    if (!$errors) {
        $userId  = $_SESSION['user_id'];
        $reason  = $form['reason'];
        $remarks = $form['notes'] !== '' ? $form['notes'] : null;
        if ($onCredit) {
            $remarks = 'Utang: ' . $customer->getName() . ($remarks ? ' — ' . $remarks : '');
        }
        $itemList = [];
        $totalValue = 0;
        $totalQty = 0;

        $conn->begin_transaction();
        try {
            foreach ($totals as $pid => $qty) {
                // "AND stock >= ?" makes sure stock can never go below zero
                $stmt = $conn->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
                $stmt->bind_param('iii', $qty, $pid, $qty);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    throw new Exception("Not enough stock for {$products[$pid]->getName()}.");
                }

                $price = $products[$pid]->getSellingPrice();
                $stmt = $conn->prepare("INSERT INTO stock_logs (product_id, user_id, type, reason, quantity, unit_price, remarks) VALUES (?, ?, 'out', ?, ?, ?, ?)");
                $stmt->bind_param('iisids', $pid, $userId, $reason, $qty, $price, $remarks);
                $stmt->execute();

                $totalValue += $price * $qty;
                $totalQty   += $qty;
                $itemList[]  = $products[$pid]->getName() . ' x' . $qty;
            }

            // Add the sale to the customer's utang in the same transaction
            if ($onCredit) {
                $desc = mb_substr(implode(', ', $itemList), 0, 255);
                $amt  = round($totalValue, 2);
                $stmt = $conn->prepare("INSERT INTO credit_transactions (customer_id, user_id, type, amount, description) VALUES (?, ?, 'charge', ?, ?)");
                $customerId = $customer->getId();
                $stmt->bind_param('iids', $customerId, $userId, $amt, $desc);
                $stmt->execute();
            }
            $conn->commit();

            $_SESSION['flash'] = ['type' => 'success',
                'msg' => "Recorded $totalQty item(s) out as \"$reason\" (worth ₱" . number_format($totalValue, 2) . ')'
                       . ($onCredit ? " and added it to {$customer->getName()}'s utang." : '.')];
            header('Location: stock_out.php');
            exit;
        } catch (Throwable $ex) {
            $conn->rollback();
            $errors[] = 'Nothing was saved. ' . $ex->getMessage();
        }
    }
}

// Customers for "sold on credit", with their current utang
$customerList = $conn->query(
    "SELECT c.id, c.name, c.credit_limit,
            COALESCE(SUM(CASE WHEN t.type = 'charge' THEN t.amount ELSE -t.amount END), 0) AS balance
     FROM customers c LEFT JOIN credit_transactions t ON t.customer_id = c.id
     GROUP BY c.id, c.name, c.credit_limit ORDER BY c.name"
)->fetch_all(MYSQLI_ASSOC);

// Products for the dropdowns, grouped by category (only items with stock can go out)
$productRows = $conn->query(
    'SELECT p.id, p.name, p.unit, p.stock, p.selling_price, c.name AS category
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     ORDER BY c.name, p.name'
);
$groups = [];
while ($r = $productRows->fetch_assoc()) {
    $groups[$r['category'] ?? 'Uncategorized'][] = $r;
}

// Builds the <option> list for one row, with the chosen product selected
function product_options($groups, $selectedId) {
    $html = '<option value="">-- choose a product --</option>';
    foreach ($groups as $cat => $items) {
        $html .= '<optgroup label="' . e($cat) . '">';
        foreach ($items as $p) {
            $sel = (int)$selectedId === (int)$p['id'] ? ' selected' : '';
            $dis = $p['stock'] <= 0 && !$sel ? ' disabled' : '';
            $html .= '<option value="' . $p['id'] . '" data-stock="' . $p['stock'] . '" data-unit="' . e($p['unit'])
                   . '" data-price="' . $p['selling_price'] . '"' . $sel . $dis . '>'
                   . e($p['name']) . ' (' . $p['stock'] . ' left)' . ($p['stock'] <= 0 ? ' — out of stock' : '') . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

// Latest stock-out records
$recent = $conn->query(
    "SELECT l.quantity, l.reason, l.unit_price, l.remarks, l.created_at, p.name AS product, p.unit, p.image, u.full_name AS user
     FROM stock_logs l
     JOIN products p ON p.id = l.product_id
     LEFT JOIN users u ON u.id = l.user_id
     WHERE l.type = 'out'
     ORDER BY l.created_at DESC, l.id DESC LIMIT 10"
);

$pageTitle = 'Stock Out';
$active    = 'stock_out';
require 'includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Stock Out</h2>
        <p>Record items leaving the store: sold, expired, damaged, and more. Stock goes down automatically.</p>
    </div>
    <a class="btn ghost" href="stock_history.php?type=out"><?= icon('history', 16) ?> Stock-out history</a>
</div>

<?php if ($errors): ?>
    <div class="flash error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid-form wide">
    <section class="panel">
        <div class="panel-head">
            <div><h3>New stock out</h3><p>You can add several items at once</p></div>
        </div>
        <div class="panel-body">
            <form class="form" method="post" id="outForm">
                <label>Reason *</label>
                <div class="reason-pills">
                    <?php foreach ($reasons as $r): ?>
                        <label class="pill">
                            <input type="radio" name="reason" value="<?= e($r) ?>" <?= $form['reason'] === $r ? 'checked' : '' ?> required>
                            <span><?= e($r) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- Only for Sold: paid in cash, or on credit (utang) -->
                <div id="payBlock">
                    <label>Payment</label>
                    <div class="reason-pills">
                        <label class="pill"><input type="radio" name="payment" value="cash" <?= $form['payment'] === 'cash' ? 'checked' : '' ?>><span>Cash</span></label>
                        <label class="pill"><input type="radio" name="payment" value="credit" <?= $form['payment'] === 'credit' ? 'checked' : '' ?>><span>Utang (credit)</span></label>
                    </div>
                    <div id="custBlock">
                        <label>Customer *</label>
                        <select name="customer_id" id="customer">
                            <option value="">-- choose customer --</option>
                            <?php foreach ($customerList as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $form['customer_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?> (owes ₱<?= number_format($c['balance'], 2) ?><?= $c['credit_limit'] !== null ? ', limit ₱' . number_format($c['credit_limit'], 2) : '' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="hint">Not on the list? <a href="credit.php">Add a new customer</a> first.</div>
                    </div>
                </div>

                <label>Items *</label>
                <div id="rows">
                    <?php foreach ($form['items'] as $item): ?>
                        <div class="item-row">
                            <select name="product_id[]" class="prod-select" required><?= product_options($groups, $item['product_id']) ?></select>
                            <input type="number" name="quantity[]" class="qty-input" min="1" step="1" placeholder="Qty" value="<?= e($item['quantity']) ?>" required>
                            <button type="button" class="act danger remove-row" title="Remove row"><?= icon('trash', 16) ?></button>
                            <div class="row-info"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn ghost small" id="addRow" style="margin-top:4px"><?= icon('plus', 14) ?> Add another item</button>

                <label>Notes</label>
                <input type="text" name="notes" value="<?= e($form['notes']) ?>" placeholder="e.g. Sales for Oct 16, or 'expired last week'">

                <div class="preview-box show" id="summary">
                    <div class="line"><span>Items</span><span id="sumQty">0</span></div>
                    <div class="line"><span>Value (at selling price)</span><b id="sumValue">₱0.00</b></div>
                </div>

                <div class="form-actions">
                    <button class="btn block" type="submit"><?= icon('arrow-out', 16) ?> Save stock out</button>
                </div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div><h3>Recent stock out</h3><p>Last 10 records</p></div>
            <a class="act" href="stock_history.php?type=out">View all</a>
        </div>
        <div class="table-wrap">
            <table>
                <tr><th>Product</th><th>Reason</th><th class="num">Qty</th></tr>
                <?php if ($recent->num_rows === 0): ?>
                    <tr><td colspan="3" class="empty">No stock out recorded yet.</td></tr>
                <?php endif; ?>
                <?php while ($r = $recent->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div class="prod">
                                <?= product_thumb(['name' => $r['product'], 'image' => $r['image']], 36) ?>
                                <div>
                                    <b><?= e($r['product']) ?></b><br>
                                    <span class="muted"><?= date('M j, g:i A', strtotime($r['created_at'])) ?> · <?= e($r['user'] ?? '—') ?></span>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge <?= reason_class($r['reason']) ?>"><?= e($r['reason'] ?? 'Out') ?></span></td>
                        <td class="num qty-out">-<?= $r['quantity'] ?> <span class="muted"><?= e($r['unit']) ?></span></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </section>
</div>

<!-- Blank row copied when "Add another item" is clicked -->
<template id="rowTemplate">
    <div class="item-row">
        <select name="product_id[]" class="prod-select" required><?= product_options($groups, 0) ?></select>
        <input type="number" name="quantity[]" class="qty-input" min="1" step="1" placeholder="Qty" required>
        <button type="button" class="act danger remove-row" title="Remove row"><?= icon('trash', 16) ?></button>
        <div class="row-info"></div>
    </div>
</template>

<script>
// Adds/removes item rows and shows available stock + total value while typing
(function () {
    const rows = document.getElementById('rows');
    const tpl  = document.getElementById('rowTemplate');
    const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function refresh() {
        // How much of each product is used across all rows
        const used = {};
        rows.querySelectorAll('.item-row').forEach(row => {
            const id = row.querySelector('.prod-select').value;
            const q  = parseInt(row.querySelector('.qty-input').value, 10) || 0;
            if (id) used[id] = (used[id] || 0) + q;
        });

        let totalQty = 0, totalValue = 0;
        rows.querySelectorAll('.item-row').forEach(row => {
            const sel  = row.querySelector('.prod-select');
            const qtyI = row.querySelector('.qty-input');
            const info = row.querySelector('.row-info');
            const opt  = sel.options[sel.selectedIndex];
            const q    = parseInt(qtyI.value, 10) || 0;
            if (!opt || !opt.value) { info.textContent = ''; info.className = 'row-info'; return; }

            const stock = parseInt(opt.dataset.stock, 10);
            const price = parseFloat(opt.dataset.price);
            qtyI.max = stock;
            totalQty += q;
            totalValue += q * price;

            if (used[opt.value] > stock) {
                info.textContent = '⚠ Only ' + stock + ' ' + opt.dataset.unit + ' in stock';
                info.className = 'row-info bad';
            } else {
                info.textContent = stock + ' ' + opt.dataset.unit + ' in stock · ' + peso(price) + ' each' + (q ? ' · ' + (stock - used[opt.value]) + ' left after' : '');
                info.className = 'row-info';
            }
        });
        document.getElementById('sumQty').textContent = totalQty;
        document.getElementById('sumValue').textContent = peso(totalValue);
        // Can't remove the only row
        const all = rows.querySelectorAll('.remove-row');
        all.forEach(b => b.style.visibility = all.length > 1 ? 'visible' : 'hidden');
    }

    document.getElementById('addRow').addEventListener('click', () => {
        rows.appendChild(tpl.content.cloneNode(true));
        refresh();
        rows.lastElementChild.querySelector('select').focus();
    });
    rows.addEventListener('click', e => {
        const btn = e.target.closest('.remove-row');
        if (btn) { btn.closest('.item-row').remove(); refresh(); }
    });
    rows.addEventListener('input', refresh);
    rows.addEventListener('change', refresh);
    refresh();

    // Show "Payment" only for Sold, and the customer list only for Utang
    const form = document.getElementById('outForm');
    const payBlock = document.getElementById('payBlock');
    const custBlock = document.getElementById('custBlock');
    const customer = document.getElementById('customer');
    function togglePay() {
        const sold = form.reason.value === 'Sold';
        const credit = sold && form.payment.value === 'credit';
        payBlock.style.display = sold ? '' : 'none';
        custBlock.style.display = credit ? '' : 'none';
        customer.required = credit;
    }
    form.addEventListener('change', e => { if (e.target.name === 'reason' || e.target.name === 'payment') togglePay(); });
    togglePay();
})();
</script>
<?php require 'includes/footer.php'; ?>
