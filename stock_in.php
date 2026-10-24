<?php
require 'config/db.php';
require_login();

$errors = [];
$form = [
    'product_id' => (int)($_GET['product'] ?? 0),   // pre-selected from "Restock" buttons
    'quantity'   => '',
    'supplier'   => '',
    'new_cost'   => '',
    'notes'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['product_id'] = (int)($_POST['product_id'] ?? 0);
    $form['quantity']   = trim($_POST['quantity'] ?? '');
    $form['supplier']   = trim($_POST['supplier'] ?? '');
    $form['new_cost']   = trim($_POST['new_cost'] ?? '');
    $form['notes']      = trim($_POST['notes'] ?? '');

    // ----- Validate -----
    $stmt = $conn->prepare('SELECT id, name, unit, stock, cost_price FROM products WHERE id = ?');
    $stmt->bind_param('i', $form['product_id']);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    if (!$product) $errors[] = 'Please choose a product.';
    if (filter_var($form['quantity'], FILTER_VALIDATE_INT) === false || (int)$form['quantity'] <= 0) {
        $errors[] = 'Quantity must be a whole number greater than 0.';
    }
    if ($form['new_cost'] !== '' && (!is_numeric($form['new_cost']) || $form['new_cost'] < 0)) {
        $errors[] = 'New cost price must be 0 or more (or leave it blank).';
    }

    // ----- Save -----
    if (!$errors) {
        $qty    = (int)$form['quantity'];
        $userId = $_SESSION['user_id'];

        // Remarks saved in the history, e.g. "Supplier: Puregold — 2 boxes"
        $parts = [];
        if ($form['supplier'] !== '') $parts[] = 'Supplier: ' . $form['supplier'];
        if ($form['notes'] !== '')    $parts[] = $form['notes'];
        $remarks = $parts ? implode(' — ', $parts) : 'Delivery';

        // Transaction: both changes succeed together, or neither is saved
        $conn->begin_transaction();
        try {
            if ($form['new_cost'] !== '') {
                $cost = (float)$form['new_cost'];
                $stmt = $conn->prepare('UPDATE products SET stock = stock + ?, cost_price = ? WHERE id = ?');
                $stmt->bind_param('idi', $qty, $cost, $product['id']);
            } else {
                $stmt = $conn->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
                $stmt->bind_param('ii', $qty, $product['id']);
            }
            $stmt->execute();

            // Save the cost at the time of delivery (for reports)
            $unitCost = $form['new_cost'] !== '' ? (float)$form['new_cost'] : (float)$product['cost_price'];
            $stmt = $conn->prepare("INSERT INTO stock_logs (product_id, user_id, type, quantity, unit_price, remarks) VALUES (?, ?, 'in', ?, ?, ?)");
            $stmt->bind_param('iiids', $product['id'], $userId, $qty, $unitCost, $remarks);
            $stmt->execute();

            $conn->commit();
        } catch (Throwable $ex) {
            $conn->rollback();
            $errors[] = 'Could not save the delivery. Please try again.';
        }

        if (!$errors) {
            $newStock = $product['stock'] + $qty;
            $_SESSION['flash'] = ['type' => 'success',
                'msg' => "Added $qty {$product['unit']} of {$product['name']}. New stock: $newStock."];
            header('Location: stock_in.php');
            exit;
        }
    }
}

// Products for the dropdown, grouped by category
$productRows = $conn->query(
    'SELECT p.id, p.name, p.unit, p.stock, p.reorder_level, p.cost_price, c.name AS category
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     ORDER BY c.name, p.name'
);
$groups = [];
while ($r = $productRows->fetch_assoc()) {
    $groups[$r['category'] ?? 'Uncategorized'][] = $r;
}

// Latest deliveries
$recent = $conn->query(
    "SELECT l.quantity, l.remarks, l.created_at, p.name AS product, p.unit, u.full_name AS user
     FROM stock_logs l
     JOIN products p ON p.id = l.product_id
     LEFT JOIN users u ON u.id = l.user_id
     WHERE l.type = 'in'
     ORDER BY l.created_at DESC, l.id DESC LIMIT 10"
);

$pageTitle = 'Stock In';
$active    = 'stock_in';
require 'includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Stock In</h2>
        <p>Record deliveries from suppliers. Stock goes up automatically.</p>
    </div>
    <a class="btn ghost" href="stock_history.php"><?= icon('history', 16) ?> Full history</a>
</div>

<?php if ($errors): ?>
    <div class="flash error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid-form">
    <section class="panel">
        <div class="panel-head">
            <div><h3>New delivery</h3><p>Fields with * are required</p></div>
        </div>
        <div class="panel-body">
            <form class="form" method="post" id="stockForm">
                <label>Product *</label>
                <select name="product_id" id="product" required>
                    <option value="">-- choose a product --</option>
                    <?php foreach ($groups as $cat => $items): ?>
                        <optgroup label="<?= e($cat) ?>">
                            <?php foreach ($items as $p): ?>
                                <option value="<?= $p['id'] ?>"
                                        data-stock="<?= $p['stock'] ?>"
                                        data-unit="<?= e($p['unit']) ?>"
                                        data-cost="<?= $p['cost_price'] ?>"
                                        <?= $form['product_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                                    <?= e($p['name']) ?> (<?= $p['stock'] ?> left)<?= $p['stock'] <= $p['reorder_level'] ? ' ⚠' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>

                <div class="row">
                    <div>
                        <label>Quantity received *</label>
                        <input type="number" name="quantity" id="qty" min="1" step="1" value="<?= e($form['quantity']) ?>" placeholder="e.g. 24" required>
                    </div>
                    <div>
                        <label>New cost price (₱)</label>
                        <input type="number" name="new_cost" id="cost" min="0" step="0.01" value="<?= e($form['new_cost']) ?>" placeholder="Leave blank if same">
                    </div>
                </div>

                <label>Supplier</label>
                <input type="text" name="supplier" value="<?= e($form['supplier']) ?>" placeholder="e.g. Puregold, Ahente ng Coke">

                <label>Notes</label>
                <input type="text" name="notes" value="<?= e($form['notes']) ?>" placeholder="e.g. 1 case, OR #12345">

                <!-- Live preview: current stock -> new stock -->
                <div class="preview-box" id="preview">
                    <div class="line"><span>Current stock</span><span id="pvNow">0</span></div>
                    <div class="line"><span>Adding</span><span class="qty-in" id="pvAdd">+0</span></div>
                    <div class="line"><span>New stock</span><b id="pvNew">0</b></div>
                </div>

                <div class="form-actions">
                    <button class="btn block" type="submit"><?= icon('arrow-in', 16) ?> Save delivery</button>
                </div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div><h3>Recent deliveries</h3><p>Last 10 stock-in records</p></div>
            <a class="act" href="stock_history.php?type=in">View all</a>
        </div>
        <div class="table-wrap">
            <table>
                <tr><th>Date</th><th>Product</th><th class="num">Qty</th><th>Remarks</th></tr>
                <?php if ($recent->num_rows === 0): ?>
                    <tr><td colspan="4" class="empty">No deliveries recorded yet.</td></tr>
                <?php endif; ?>
                <?php while ($r = $recent->fetch_assoc()): ?>
                    <tr>
                        <td class="muted" style="white-space:nowrap"><?= date('M j, Y', strtotime($r['created_at'])) ?><br><?= date('g:i A', strtotime($r['created_at'])) ?></td>
                        <td><b><?= e($r['product']) ?></b><br><span class="muted">by <?= e($r['user'] ?? '—') ?></span></td>
                        <td class="num qty-in">+<?= $r['quantity'] ?> <span class="muted"><?= e($r['unit']) ?></span></td>
                        <td class="muted"><?= e($r['remarks']) ?></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </section>
</div>

<script>
// Shows "current stock -> new stock" while typing the quantity
(function () {
    const product = document.getElementById('product');
    const qty     = document.getElementById('qty');
    const cost    = document.getElementById('cost');
    const box     = document.getElementById('preview');

    function update() {
        const opt = product.options[product.selectedIndex];
        if (!opt || !opt.value) { box.classList.remove('show'); return; }
        const now  = parseInt(opt.dataset.stock, 10) || 0;
        const add  = Math.max(parseInt(qty.value, 10) || 0, 0);
        const unit = opt.dataset.unit;
        document.getElementById('pvNow').textContent = now + ' ' + unit;
        document.getElementById('pvAdd').textContent = '+' + add + ' ' + unit;
        document.getElementById('pvNew').textContent = (now + add) + ' ' + unit;
        cost.placeholder = 'Current: ₱' + parseFloat(opt.dataset.cost).toFixed(2);
        box.classList.add('show');
    }
    product.addEventListener('change', update);
    qty.addEventListener('input', update);
    update();
    if (product.value) qty.focus();
})();
</script>
<?php require 'includes/footer.php'; ?>
