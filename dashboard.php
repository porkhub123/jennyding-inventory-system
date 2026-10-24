<?php
require 'config/db.php';
require_login();

// Quick numbers for the dashboard cards
$totalProducts = $conn->query('SELECT COUNT(*) AS c FROM products')->fetch_assoc()['c'];
$totalCats     = $conn->query('SELECT COUNT(*) AS c FROM categories')->fetch_assoc()['c'];
$lowStock      = $conn->query('SELECT COUNT(*) AS c FROM products WHERE stock <= reorder_level')->fetch_assoc()['c'];
$stockValue    = $conn->query('SELECT COALESCE(SUM(stock * cost_price),0) AS v FROM products')->fetch_assoc()['v'];
// Stock that left the store today, and how much of it was sold
$outToday      = $conn->query(
    "SELECT COALESCE(SUM(quantity), 0) AS qty,
            COALESCE(SUM(CASE WHEN reason = 'Sold' THEN quantity * unit_price END), 0) AS sold_value
     FROM stock_logs WHERE type = 'out' AND DATE(created_at) = CURDATE()"
)->fetch_assoc();

// Customer credit (utang) still to collect
$utang = $conn->query(
    "SELECT COALESCE(SUM(CASE WHEN type = 'charge' THEN amount ELSE -amount END), 0) AS total FROM credit_transactions"
)->fetch_assoc()['total'];

$lowList = $conn->query(
    'SELECT p.id, p.name, p.unit, p.stock, p.reorder_level, p.image, c.name AS category
     FROM products p LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.stock <= p.reorder_level ORDER BY p.stock ASC LIMIT 8'
);

$recent = $conn->query(
    'SELECT l.type, l.reason, l.quantity, l.created_at, p.name AS product, p.image, u.full_name AS user
     FROM stock_logs l
     JOIN products p ON p.id = l.product_id
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.created_at DESC, l.id DESC LIMIT 8'
);

// Greeting based on the time of day
$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', $_SESSION['full_name'])[0];


$pageTitle = 'Dashboard';
$active    = 'dashboard';
require 'includes/header.php';
?>
<section class="welcome">
    <div>
        <h2><?= $greet ?>, <?= e($firstName) ?>! 👋</h2>
        <p>Here's what's happening in the store today.</p>
    </div>
    <div class="welcome-actions">
        <a class="btn" href="stock_in.php"><?= icon('truck') ?> Stock in</a>
        <a class="btn" href="stock_out.php"><?= icon('arrow-out') ?> Stock out</a>
    </div>
</section>

<div class="cards">
    <div class="card">
        <div class="ic"><?= icon('box', 22) ?></div>
        <div><span>Total products</span><b><?= $totalProducts ?></b><small>in <?= $totalCats ?> categories</small></div>
    </div>
    <div class="card red">
        <div class="ic"><?= icon('alert', 22) ?></div>
        <div><span>Low stock</span><b><?= $lowStock ?></b><small>need restocking</small></div>
    </div>
    <div class="card blue">
        <div class="ic"><?= icon('wallet', 22) ?></div>
        <div><span>Stock value</span><b>₱<?= number_format($stockValue, 2) ?></b><small>at cost price</small></div>
    </div>
    <div class="card green">
        <div class="ic"><?= icon('cart', 22) ?></div>
        <div><span>Sold today</span><b>₱<?= number_format($outToday['sold_value'], 2) ?></b><small><?= $outToday['qty'] ?> item(s) out today</small></div>
    </div>
    <a class="card orange" href="credit.php" style="text-decoration:none;color:inherit">
        <div class="ic"><?= icon('wallet', 22) ?></div>
        <div><span>Utang to collect</span><b>₱<?= number_format($utang, 2) ?></b><small>customer credit</small></div>
    </a>
</div>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head">
            <div><h3>Items to restock</h3><p>Stock is at or below the reorder level</p></div>
            <a class="act" href="products.php">View all</a>
        </div>
        <div class="table-wrap">
            <table>
                <tr><th>Product</th><th class="num">Stock</th><th class="actions"></th></tr>
                <?php if ($lowList->num_rows === 0): ?>
                    <tr><td colspan="3" class="empty">🎉 All items are well stocked.</td></tr>
                <?php endif; ?>
                <?php while ($p = $lowList->fetch_assoc()): ?>
                    <tr>
                        <td><div class="prod"><?= product_thumb($p, 38) ?><div><b><?= e($p['name']) ?></b><br><span class="muted"><?= e($p['category'] ?? '—') ?></span></div></div></td>
                        <td class="num"><span class="badge low"><?= $p['stock'] ?> / <?= $p['reorder_level'] ?></span></td>
                        <td class="actions"><a class="btn small" href="stock_in.php?product=<?= $p['id'] ?>"><?= icon('plus', 14) ?> Restock</a></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div><h3>Recent stock activity</h3><p>Latest stock in, stock out and adjustments</p></div>
            <a class="act" href="stock_history.php">View history</a>
        </div>
        <div class="table-wrap">
            <table>
                <tr><th>Product</th><th>Type</th><th class="num">Qty</th></tr>
                <?php if ($recent->num_rows === 0): ?>
                    <tr><td colspan="3" class="empty">No stock movements yet. Record a delivery to get started.</td></tr>
                <?php endif; ?>
                <?php while ($r = $recent->fetch_assoc()): ?>
                    <?php $signed = $r['type'] === 'out' ? -$r['quantity'] : (int)$r['quantity']; ?>
                    <tr>
                        <td><div class="prod"><?= product_thumb(['name' => $r['product'], 'image' => $r['image']], 38) ?><div><b><?= e($r['product']) ?></b><br><span class="muted"><?= date('M j, g:i A', strtotime($r['created_at'])) ?> · <?= e($r['user'] ?? '—') ?></span></div></div></td>
                        <td><span class="badge <?= $r['type'] === 'out' ? reason_class($r['reason']) : $r['type'] ?>"><?= e(log_label($r['type'], $r['reason'])) ?></span></td>
                        <td class="num <?= $signed >= 0 ? 'qty-in' : 'qty-out' ?>"><?= $signed > 0 ? '+' : '' ?><?= $signed ?></td>
                    </tr>
                <?php endwhile; ?>
            </table>
        </div>
    </section>
</div>
<?php require 'includes/footer.php'; ?>
