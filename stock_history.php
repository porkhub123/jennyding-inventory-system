<?php
require 'config/db.php';
require_login();

// ----- Filters from the URL -----
$productId = (int)($_GET['product'] ?? 0);
$type      = $_GET['type'] ?? '';
$from      = $_GET['from'] ?? '';
$to        = $_GET['to'] ?? '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 25;

// "type" can also be a stock-out reason, e.g. "out:Expired"
$reasons = ['Sold', 'Expired', 'Damaged', 'Personal use', 'Returned to supplier', 'Other'];
$reason  = '';
if (strpos($type, 'out:') === 0) {
    $reason = substr($type, 4);
    $type   = 'out';
    if (!in_array($reason, $reasons, true)) { $reason = ''; }
}
if (!in_array($type, ['', 'in', 'out', 'adjust'], true)) $type = '';
$typeValue = $reason !== '' ? "out:$reason" : $type;   // what the dropdown shows as selected
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
if ($to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to = '';

// Build the WHERE part step by step (all values go through prepared statements)
$where  = [];
$types  = '';
$params = [];
if ($productId > 0) { $where[] = 'l.product_id = ?';       $types .= 'i'; $params[] = $productId; }
if ($type !== '')   { $where[] = 'l.type = ?';             $types .= 's'; $params[] = $type; }
if ($reason !== '') { $where[] = 'l.reason = ?';           $types .= 's'; $params[] = $reason; }
if ($from !== '')   { $where[] = 'DATE(l.created_at) >= ?'; $types .= 's'; $params[] = $from; }
if ($to !== '')     { $where[] = 'DATE(l.created_at) <= ?'; $types .= 's'; $params[] = $to; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Helper: run a prepared query with the filter values
function run_query($conn, $sql, $types, $params) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result();
}

// ----- Totals for the summary chips -----
$sum = run_query($conn,
    "SELECT COUNT(*) AS records,
            COALESCE(SUM(CASE WHEN l.type = 'in'     THEN l.quantity END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN l.type = 'out'    THEN l.quantity END), 0) AS total_out,
            COALESCE(SUM(CASE WHEN l.type = 'adjust' THEN l.quantity END), 0) AS total_adjust,
            COALESCE(SUM(CASE WHEN l.type = 'out' AND l.reason = 'Sold' THEN l.quantity * l.unit_price END), 0) AS sold_value
     FROM stock_logs l $whereSql", $types, $params)->fetch_assoc();

$totalPages = max(1, (int)ceil($sum['records'] / $perPage));
$page   = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// ----- The log rows for this page -----
$rows = run_query($conn,
    "SELECT l.*, p.name AS product, p.unit, u.full_name AS user
     FROM stock_logs l
     JOIN products p ON p.id = l.product_id
     LEFT JOIN users u ON u.id = l.user_id
     $whereSql
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT $perPage OFFSET $offset", $types, $params);

$products = $conn->query('SELECT id, name FROM products ORDER BY name');

// Keep the filters when moving between pages
function page_url($n) {
    $q = $_GET;
    $q['page'] = $n;
    return 'stock_history.php?' . http_build_query($q);
}

$filtered  = $productId || $type !== '' || $from !== '' || $to !== '';

$pageTitle = 'Stock History';
$active    = 'history';
require 'includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Stock History</h2>
        <p>Every stock movement is recorded here: stock in, stock out and adjustments.</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap">
        <a class="btn ghost" href="stock_out.php"><?= icon('arrow-out', 16) ?> Stock out</a>
        <a class="btn" href="stock_in.php"><?= icon('arrow-in', 16) ?> Stock in</a>
    </div>
</div>

<form class="toolbar" method="get">
    <select name="product" onchange="this.form.submit()">
        <option value="0">All products</option>
        <?php while ($p = $products->fetch_assoc()): ?>
            <option value="<?= $p['id'] ?>" <?= $productId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endwhile; ?>
    </select>
    <select name="type" onchange="this.form.submit()">
        <option value="">All types</option>
        <option value="in"     <?= $typeValue === 'in' ? 'selected' : '' ?>>Stock in</option>
        <option value="out"    <?= $typeValue === 'out' ? 'selected' : '' ?>>Stock out (all reasons)</option>
        <?php foreach ($reasons as $r): ?>
            <option value="out:<?= e($r) ?>" <?= $typeValue === "out:$r" ? 'selected' : '' ?>>&nbsp;&nbsp;↳ <?= e($r) ?></option>
        <?php endforeach; ?>
        <option value="adjust" <?= $typeValue === 'adjust' ? 'selected' : '' ?>>Adjusted</option>
    </select>
    <input type="date" name="from" value="<?= e($from) ?>" title="From date">
    <input type="date" name="to" value="<?= e($to) ?>" title="To date">
    <button class="btn" type="submit">Filter</button>
    <?php if ($filtered): ?><a class="btn ghost" href="stock_history.php">Clear</a><?php endif; ?>
</form>

<div class="summary">
    <div class="chip"><span>Records</span><b><?= number_format($sum['records']) ?></b></div>
    <div class="chip"><span>Total stock in</span><b class="qty-in">+<?= number_format($sum['total_in']) ?></b></div>
    <div class="chip"><span>Total stock out</span><b class="<?= $sum['total_out'] > 0 ? 'qty-out' : '' ?>"><?= $sum['total_out'] > 0 ? '-' : '' ?><?= number_format($sum['total_out']) ?></b></div>
    <div class="chip"><span>Sold value</span><b>₱<?= number_format($sum['sold_value'], 2) ?></b></div>
    <div class="chip"><span>Adjustments</span><b><?= $sum['total_adjust'] > 0 ? '+' : '' ?><?= number_format($sum['total_adjust']) ?></b></div>
</div>

<div class="table-wrap">
<table>
    <tr><th>Date &amp; time</th><th>Product</th><th>Type</th><th class="num">Quantity</th><th class="num">Value</th><th>By</th><th>Remarks</th></tr>
    <?php if ($rows->num_rows === 0): ?>
        <tr><td colspan="7" class="empty">No stock movements found<?= $filtered ? ' for these filters' : ' yet' ?>.</td></tr>
    <?php endif; ?>
    <?php while ($r = $rows->fetch_assoc()): ?>
        <?php $signed = $r['type'] === 'out' ? -$r['quantity'] : (int)$r['quantity']; ?>
        <tr>
            <td style="white-space:nowrap"><?= date('M j, Y', strtotime($r['created_at'])) ?><br><span class="muted"><?= date('g:i A', strtotime($r['created_at'])) ?></span></td>
            <td class="strong"><a href="stock_history.php?product=<?= $r['product_id'] ?>" style="color:inherit"><?= e($r['product']) ?></a></td>
            <td><span class="badge <?= $r['type'] === 'out' ? reason_class($r['reason']) : $r['type'] ?>"><?= e(log_label($r['type'], $r['reason'])) ?></span></td>
            <td class="num <?= $signed >= 0 ? 'qty-in' : 'qty-out' ?>"><?= $signed > 0 ? '+' : '' ?><?= $signed ?> <span class="muted"><?= e($r['unit']) ?></span></td>
            <td class="num muted"><?= $r['unit_price'] !== null && $r['type'] !== 'adjust' ? '₱' . number_format($r['quantity'] * $r['unit_price'], 2) : '—' ?></td>
            <td><?= e($r['user'] ?? '—') ?></td>
            <td class="muted"><?= e($r['remarks'] ?? '') ?></td>
        </tr>
    <?php endwhile; ?>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pager">
    <span class="muted">Page <?= $page ?> of <?= $totalPages ?></span>
    <div style="display:flex; gap:8px">
        <?php if ($page > 1): ?><a class="btn ghost small" href="<?= e(page_url($page - 1)) ?>">← Newer</a><?php endif; ?>
        <?php if ($page < $totalPages): ?><a class="btn ghost small" href="<?= e(page_url($page + 1)) ?>">Older →</a><?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php require 'includes/footer.php'; ?>
