<?php
require 'config/db.php';
require_login();

// =========================================================
// 1. Which report and which dates
// =========================================================
$report = ($_GET['report'] ?? 'movement') === 'inventory' ? 'inventory' : 'movement';
$range  = $_GET['range'] ?? 'month';
$today  = date('Y-m-d');

$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
switch ($range) {
    case 'today':  $from = $today;                                    $to = $today; break;
    case 'week':   $from = date('Y-m-d', strtotime('monday this week')); $to = $today; break;
    case 'last30': $from = date('Y-m-d', strtotime('-29 days'));      $to = $today; break;
    case 'custom':
        $from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
        $to   = $isDate($_GET['to'] ?? '')   ? $_GET['to']   : $today;
        if ($from > $to) [$from, $to] = [$to, $from];
        break;
    default:       $range = 'month'; $from = date('Y-m-01');          $to = $today;
}
$rangeNames = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'last30' => 'Last 30 days', 'custom' => 'Custom'];
$periodText = $from === $to
    ? date('F j, Y', strtotime($from))
    : date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

// Runs a prepared query and returns all rows
function rows($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Formats money: 1234.5 -> "₱1,234.50"
function peso($n) { return '₱' . number_format((float)$n, 2); }

// Short axis labels: 1500 -> "1.5K"
function compact_num($n) {
    if ($n >= 1000000) return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    if ($n >= 1000)    return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    return (string)round($n);
}

// =========================================================
// 2. CSV export (opens in Excel)
// =========================================================
if (isset($_GET['export'])) {
    $isInv = $_GET['export'] === 'inventory';
    $file  = $isInv ? "inventory-$today.csv" : "stock-movement-$from-to-$to.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // lets Excel read ₱ and ñ correctly

    if ($isInv) {
        fputcsv($out, ['Product', 'Category', 'Unit', 'Cost Price', 'Selling Price', 'Stock', 'Reorder Level', 'Cost Value', 'Retail Value', 'Status']);
        $list = rows($conn, 'SELECT p.*, c.name AS category FROM products p LEFT JOIN categories c ON c.id = p.category_id ORDER BY c.name, p.name');
        foreach ($list as $p) {
            $status = $p['stock'] <= 0 ? 'Out of stock' : ($p['stock'] <= $p['reorder_level'] ? 'Low' : 'OK');
            fputcsv($out, [$p['name'], $p['category'], $p['unit'], $p['cost_price'], $p['selling_price'], $p['stock'],
                           $p['reorder_level'], $p['stock'] * $p['cost_price'], $p['stock'] * $p['selling_price'], $status]);
        }
    } else {
        fputcsv($out, ['Date', 'Time', 'Product', 'Category', 'Type', 'Reason', 'Quantity', 'Unit', 'Unit Price', 'Value', 'By', 'Remarks']);
        $list = rows($conn,
            'SELECT l.*, p.name AS product, p.unit, c.name AS category, u.full_name AS user
             FROM stock_logs l
             JOIN products p ON p.id = l.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN users u ON u.id = l.user_id
             WHERE DATE(l.created_at) BETWEEN ? AND ?
             ORDER BY l.created_at, l.id', 'ss', [$from, $to]);
        foreach ($list as $l) {
            $ts = strtotime($l['created_at']);
            $value = $l['unit_price'] !== null && $l['type'] !== 'adjust' ? $l['quantity'] * $l['unit_price'] : '';
            fputcsv($out, [date('Y-m-d', $ts), date('g:i A', $ts), $l['product'], $l['category'], log_label($l['type']),
                           $l['reason'], $l['type'] === 'out' ? -$l['quantity'] : $l['quantity'], $l['unit'],
                           $l['unit_price'], $value, $l['user'], $l['remarks']]);
        }
    }
    fclose($out);
    exit;
}

// =========================================================
// 3. Data for the Stock Movement report
// =========================================================
if ($report === 'movement') {
    $dateWhere = 'DATE(l.created_at) BETWEEN ? AND ?';
    $dp = [$from, $to];

    // Totals for the cards ("Initial stock" is setup data, not a delivery, so it is left out)
    $sum = rows($conn,
        "SELECT
            COALESCE(SUM(CASE WHEN l.type = 'in' AND COALESCE(l.remarks, '') <> 'Initial stock' THEN l.quantity END), 0) AS in_qty,
            COALESCE(SUM(CASE WHEN l.type = 'in' AND COALESCE(l.remarks, '') <> 'Initial stock' THEN l.quantity * l.unit_price END), 0) AS in_value,
            COALESCE(SUM(CASE WHEN l.type = 'out' AND l.reason = 'Sold' THEN l.quantity END), 0) AS sold_qty,
            COALESCE(SUM(CASE WHEN l.type = 'out' AND l.reason = 'Sold' THEN l.quantity * l.unit_price END), 0) AS sold_value,
            COALESCE(SUM(CASE WHEN l.type = 'out' AND l.reason = 'Sold' THEN l.quantity * (l.unit_price - p.cost_price) END), 0) AS profit,
            COALESCE(SUM(CASE WHEN l.type = 'out' AND l.reason IN ('Expired', 'Damaged') THEN l.quantity END), 0) AS loss_qty,
            COALESCE(SUM(CASE WHEN l.type = 'out' AND l.reason IN ('Expired', 'Damaged') THEN l.quantity * p.cost_price END), 0) AS loss_value
         FROM stock_logs l JOIN products p ON p.id = l.product_id
         WHERE $dateWhere", 'ss', $dp)[0];

    // Sales over time: by day, or by month for long periods
    $days = (strtotime($to) - strtotime($from)) / 86400 + 1;
    $byMonth = $days > 62;
    $bucketSql = $byMonth ? "DATE_FORMAT(l.created_at, '%Y-%m')" : 'DATE(l.created_at)';
    $trendRows = rows($conn,
        "SELECT $bucketSql AS bucket, SUM(l.quantity * l.unit_price) AS value, SUM(l.quantity) AS qty
         FROM stock_logs l
         WHERE l.type = 'out' AND l.reason = 'Sold' AND $dateWhere
         GROUP BY bucket", 'ss', $dp);
    $found = [];
    foreach ($trendRows as $r) $found[$r['bucket']] = $r;

    // Fill in days/months with no sales so the chart has no gaps
    $trend = [];
    $cursor = strtotime($byMonth ? date('Y-m-01', strtotime($from)) : $from);
    while ($cursor <= strtotime($to)) {
        $key = $byMonth ? date('Y-m', $cursor) : date('Y-m-d', $cursor);
        $trend[] = [
            'key'   => $key,
            'label' => $byMonth ? date('M Y', $cursor) : date('M j', $cursor),
            'short' => $byMonth ? date('M', $cursor) : date('j', $cursor),
            'value' => (float)($found[$key]['value'] ?? 0),
            'qty'   => (int)($found[$key]['qty'] ?? 0),
        ];
        $cursor = strtotime($byMonth ? '+1 month' : '+1 day', $cursor);
    }

    // Top 10 best sellers
    $best = rows($conn,
        "SELECT p.name, p.unit, p.image, c.name AS category, SUM(l.quantity) AS qty, SUM(l.quantity * l.unit_price) AS value
         FROM stock_logs l
         JOIN products p ON p.id = l.product_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE l.type = 'out' AND l.reason = 'Sold' AND $dateWhere
         GROUP BY p.id, p.name, p.unit, p.image, c.name
         ORDER BY value DESC, qty DESC LIMIT 10", 'ss', $dp);

    // Stock out by reason
    $byReason = rows($conn,
        "SELECT l.reason, SUM(l.quantity) AS qty, SUM(l.quantity * l.unit_price) AS value
         FROM stock_logs l
         WHERE l.type = 'out' AND $dateWhere
         GROUP BY l.reason ORDER BY qty DESC", 'ss', $dp);

    // Sales by category
    $byCategory = rows($conn,
        "SELECT COALESCE(c.name, 'Uncategorized') AS category, SUM(l.quantity) AS qty, SUM(l.quantity * l.unit_price) AS value
         FROM stock_logs l
         JOIN products p ON p.id = l.product_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE l.type = 'out' AND l.reason = 'Sold' AND $dateWhere
         GROUP BY c.name ORDER BY value DESC", 'ss', $dp);

    // Customer credit (utang) in this period, and what is still owed now
    $credit = rows($conn,
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'charge'  AND DATE(created_at) BETWEEN ? AND ? THEN amount END), 0) AS charged,
            COALESCE(SUM(CASE WHEN type = 'payment' AND DATE(created_at) BETWEEN ? AND ? THEN amount END), 0) AS collected,
            COALESCE(SUM(CASE WHEN type = 'charge' THEN amount ELSE -amount END), 0) AS outstanding
         FROM credit_transactions", 'ssss', [$from, $to, $from, $to])[0];

    // Expired and damaged items (losses), valued at cost
    $losses = rows($conn,
        "SELECT p.name, p.unit, l.reason, SUM(l.quantity) AS qty, SUM(l.quantity * p.cost_price) AS value
         FROM stock_logs l
         JOIN products p ON p.id = l.product_id
         WHERE l.type = 'out' AND l.reason IN ('Expired', 'Damaged') AND $dateWhere
         GROUP BY p.id, p.name, p.unit, l.reason
         ORDER BY value DESC", 'ss', $dp);
}

// =========================================================
// 4. Data for the Inventory Status report
// =========================================================
if ($report === 'inventory') {
    $status = $_GET['status'] ?? 'all';
    if (!in_array($status, ['all', 'low', 'out'], true)) $status = 'all';

    $inv = rows($conn,
        "SELECT COUNT(*) AS products,
                COALESCE(SUM(stock), 0) AS units,
                COALESCE(SUM(stock * cost_price), 0) AS cost_value,
                COALESCE(SUM(stock * selling_price), 0) AS retail_value,
                COALESCE(SUM(stock > 0 AND stock <= reorder_level), 0) AS low,
                COALESCE(SUM(stock <= 0), 0) AS out_of_stock
         FROM products")[0];

    $where = ['all' => '', 'low' => 'WHERE p.stock > 0 AND p.stock <= p.reorder_level', 'out' => 'WHERE p.stock <= 0'][$status];
    $list = rows($conn,
        "SELECT p.*, COALESCE(c.name, 'Uncategorized') AS category
         FROM products p LEFT JOIN categories c ON c.id = p.category_id
         $where
         ORDER BY category, p.name");

    // Group the products by category, with subtotals
    $groups = [];
    foreach ($list as $p) {
        $g = &$groups[$p['category']];
        $g['items'][] = $p;
        $g['units'] = ($g['units'] ?? 0) + $p['stock'];
        $g['cost']  = ($g['cost'] ?? 0) + $p['stock'] * $p['cost_price'];
        $g['retail'] = ($g['retail'] ?? 0) + $p['stock'] * $p['selling_price'];
        unset($g);
    }
}

// Keeps the current filters when building links
function report_url($changes) {
    $q = array_merge($_GET, $changes);
    unset($q['export']);
    return 'reports.php?' . http_build_query(array_filter($q, fn($v) => $v !== null));
}

$pageTitle = 'Reports';
$active    = 'reports';
require 'includes/header.php';
?>
<!-- Only shows on paper -->
<div class="print-head">
    <div>
        <h1>Jenny &amp; Ding Store</h1>
        <p><?= $report === 'movement' ? 'Stock Movement Report' : 'Inventory Status Report' ?></p>
    </div>
    <div class="print-meta">
        <?php if ($report === 'movement'): ?><div><b>Period:</b> <?= e($periodText) ?></div><?php endif; ?>
        <div><b>Printed:</b> <?= date('M j, Y g:i A') ?></div>
        <div><b>By:</b> <?= e($_SESSION['full_name']) ?></div>
    </div>
</div>

<div class="page-head no-print">
    <div>
        <h2>Reports</h2>
        <p><?= $report === 'movement' ? 'Stock in, stock out, best sellers and losses for ' . e($periodText) . '.' : 'Current stock, value and status of every product.' ?></p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap">
        <a class="btn ghost" href="<?= e(report_url(['export' => $report])) ?>"><?= icon('arrow-in', 16) ?> Export to Excel</a>
        <button class="btn" type="button" onclick="window.print()"><?= icon('printer', 16) ?> Print report</button>
    </div>
</div>

<div class="tabs no-print">
    <a href="reports.php?report=movement" class="<?= $report === 'movement' ? 'on' : '' ?>"><?= icon('history', 16) ?> Stock movement</a>
    <a href="reports.php?report=inventory" class="<?= $report === 'inventory' ? 'on' : '' ?>"><?= icon('box', 16) ?> Inventory status</a>
</div>

<?php if ($report === 'movement'): ?>

    <form class="toolbar no-print" method="get">
        <input type="hidden" name="report" value="movement">
        <div class="seg">
            <?php foreach (['today', 'week', 'month', 'last30'] as $r): ?>
                <a href="reports.php?report=movement&range=<?= $r ?>" class="<?= $range === $r ? 'on' : '' ?>"><?= $rangeNames[$r] ?></a>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="range" value="custom">
        <input type="date" name="from" value="<?= e($from) ?>" title="From">
        <input type="date" name="to" value="<?= e($to) ?>" title="To" max="<?= $today ?>">
        <button class="btn" type="submit">Apply</button>
    </form>

    <div class="cards">
        <div class="card green">
            <div class="ic"><?= icon('cart', 22) ?></div>
            <div><span>Sales (sold items)</span><b><?= peso($sum['sold_value']) ?></b><small><?= number_format($sum['sold_qty']) ?> item(s) sold</small></div>
        </div>
        <div class="card">
            <div class="ic"><?= icon('wallet', 22) ?></div>
            <div><span>Estimated profit</span><b><?= peso($sum['profit']) ?></b><small>sales minus cost</small></div>
        </div>
        <div class="card blue">
            <div class="ic"><?= icon('truck', 22) ?></div>
            <div><span>Stock in (deliveries)</span><b><?= peso($sum['in_value']) ?></b><small><?= number_format($sum['in_qty']) ?> item(s) received</small></div>
        </div>
        <div class="card red">
            <div class="ic"><?= icon('alert', 22) ?></div>
            <div><span>Losses</span><b><?= peso($sum['loss_value']) ?></b><small><?= number_format($sum['loss_qty']) ?> expired / damaged</small></div>
        </div>
    </div>

    <?php
        // ----- Sales chart (plain HTML, works offline) -----
        $maxVal = max(array_column($trend, 'value') ?: [0]);
        // Round the top of the axis up to a clean number
        $step = 1;
        if ($maxVal > 0) {
            $raw = $maxVal / 4;
            $mag = pow(10, floor(log10($raw)));
            foreach ([1, 2, 2.5, 5, 10] as $m) { if ($m * $mag >= $raw) { $step = $m * $mag; break; } }
        }
        $axisMax = $step * 4;
        $labelEvery = max(1, (int)ceil(count($trend) / 12));
    ?>
    <section class="panel avoid-break" style="margin-bottom:20px">
        <div class="panel-head">
            <div>
                <h3>Sales <?= $byMonth ? 'per month' : 'per day' ?></h3>
                <p>Value of items sold, <?= e($periodText) ?></p>
            </div>
        </div>
        <div class="panel-body">
            <?php if ($maxVal <= 0): ?>
                <p class="empty">No sales recorded in this period yet. Record sold items in Stock Out.</p>
            <?php else: ?>
                <div class="chart" role="img" aria-label="Bar chart of sales <?= $byMonth ? 'per month' : 'per day' ?>">
                    <div class="chart-grid">
                        <?php for ($i = 4; $i >= 0; $i--): ?>
                            <div class="gridline" style="bottom: <?= $i * 25 ?>%"><span>₱<?= compact_num($step * $i) ?></span></div>
                        <?php endfor; ?>
                    </div>
                    <div class="chart-bars">
                        <?php foreach ($trend as $n => $t): ?>
                            <div class="bar-slot" tabindex="0"
                                 data-tip="<?= e($t['label']) ?>|<?= e(peso($t['value'])) ?>|<?= $t['qty'] ?> item(s)">
                                <div class="bar" style="height: <?= $axisMax > 0 ? round($t['value'] / $axisMax * 100, 2) : 0 ?>%"></div>
                                <span class="bar-label"><?= $n % $labelEvery === 0 ? e($t['short']) : '' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="chart-tip" id="chartTip"></div>
                </div>
                <details class="no-print" style="margin-top:14px">
                    <summary class="muted" style="cursor:pointer">View as table</summary>
                    <div class="table-wrap" style="margin-top:10px">
                        <table>
                            <tr><th><?= $byMonth ? 'Month' : 'Date' ?></th><th class="num">Items sold</th><th class="num">Sales</th></tr>
                            <?php foreach ($trend as $t): if ($t['qty'] == 0) continue; ?>
                                <tr><td><?= e($t['label']) ?></td><td class="num"><?= $t['qty'] ?></td><td class="num"><?= peso($t['value']) ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </section>

    <div class="grid-2">
        <section class="panel avoid-break">
            <div class="panel-head"><div><h3>Top 10 best sellers</h3><p>By sales value</p></div></div>
            <div class="table-wrap">
                <table>
                    <tr><th>#</th><th>Product</th><th class="num">Sold</th><th class="num">Sales</th></tr>
                    <?php if (!$best): ?><tr><td colspan="4" class="empty">No sales in this period.</td></tr><?php endif; ?>
                    <?php $topValue = $best[0]['value'] ?? 1; ?>
                    <?php foreach ($best as $i => $b): ?>
                        <tr>
                            <td class="muted"><?= $i + 1 ?></td>
                            <td>
                                <div class="prod">
                                    <?= product_thumb($b, 34) ?>
                                    <div style="flex:1; min-width:0">
                                        <b><?= e($b['name']) ?></b>
                                        <div class="meter"><span style="width: <?= round($b['value'] / max($topValue, 0.01) * 100) ?>%"></span></div>
                                    </div>
                                </div>
                            </td>
                            <td class="num"><?= $b['qty'] ?> <span class="muted"><?= e($b['unit']) ?></span></td>
                            <td class="num strong"><?= peso($b['value']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </section>

        <div style="display:flex; flex-direction:column; gap:20px">
            <section class="panel avoid-break">
                <div class="panel-head"><div><h3>Sales by category</h3><p>Which categories sell the most</p></div></div>
                <div class="table-wrap">
                    <table>
                        <tr><th>Category</th><th class="num">Sold</th><th class="num">Sales</th></tr>
                        <?php if (!$byCategory): ?><tr><td colspan="3" class="empty">No sales in this period.</td></tr><?php endif; ?>
                        <?php foreach ($byCategory as $c): ?>
                            <tr>
                                <td><span class="badge cat"><?= e($c['category']) ?></span></td>
                                <td class="num"><?= number_format($c['qty']) ?></td>
                                <td class="num strong"><?= peso($c['value']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>

            <section class="panel avoid-break">
                <div class="panel-head"><div><h3>Stock out by reason</h3><p>Where the stock went</p></div></div>
                <div class="table-wrap">
                    <table>
                        <tr><th>Reason</th><th class="num">Items</th><th class="num">At selling price</th></tr>
                        <?php if (!$byReason): ?><tr><td colspan="3" class="empty">No stock out in this period.</td></tr><?php endif; ?>
                        <?php foreach ($byReason as $r): ?>
                            <tr>
                                <td><span class="badge <?= reason_class($r['reason']) ?>"><?= e($r['reason'] ?? 'Other') ?></span></td>
                                <td class="num"><?= number_format($r['qty']) ?></td>
                                <td class="num"><?= peso($r['value']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <section class="panel avoid-break" style="margin-top:20px">
        <div class="panel-head"><div><h3>Customer credit (utang)</h3><p>Sold on credit and payments collected in this period</p></div><a class="act no-print" href="credit.php">Open Customer Credit</a></div>
        <div class="panel-body">
            <div class="summary" style="margin:0">
                <div class="chip"><span>New utang this period</span><b>₱<?= number_format($credit['charged'], 2) ?></b></div>
                <div class="chip"><span>Payments collected</span><b class="qty-in">₱<?= number_format($credit['collected'], 2) ?></b></div>
                <div class="chip"><span>Still to collect (today)</span><b class="<?= $credit['outstanding'] > 0 ? 'qty-out' : '' ?>">₱<?= number_format($credit['outstanding'], 2) ?></b></div>
            </div>
        </div>
    </section>

    <section class="panel avoid-break" style="margin-top:20px">
        <div class="panel-head"><div><h3>Losses: expired and damaged</h3><p>Valued at cost price</p></div></div>
        <div class="table-wrap">
            <table>
                <tr><th>Product</th><th>Reason</th><th class="num">Quantity</th><th class="num">Loss</th></tr>
                <?php if (!$losses): ?><tr><td colspan="4" class="empty">🎉 No expired or damaged items in this period.</td></tr><?php endif; ?>
                <?php foreach ($losses as $l): ?>
                    <tr>
                        <td class="strong"><?= e($l['name']) ?></td>
                        <td><span class="badge <?= reason_class($l['reason']) ?>"><?= e($l['reason']) ?></span></td>
                        <td class="num"><?= $l['qty'] ?> <span class="muted"><?= e($l['unit']) ?></span></td>
                        <td class="num qty-out"><?= peso($l['value']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </section>

<?php else: /* ===== Inventory status ===== */ ?>

    <div class="cards">
        <div class="card">
            <div class="ic"><?= icon('box', 22) ?></div>
            <div><span>Products</span><b><?= number_format($inv['products']) ?></b><small><?= number_format($inv['units']) ?> units in stock</small></div>
        </div>
        <div class="card blue">
            <div class="ic"><?= icon('wallet', 22) ?></div>
            <div><span>Stock value (cost)</span><b><?= peso($inv['cost_value']) ?></b><small>what the stock cost</small></div>
        </div>
        <div class="card green">
            <div class="ic"><?= icon('cart', 22) ?></div>
            <div><span>Retail value</span><b><?= peso($inv['retail_value']) ?></b><small>potential profit <?= peso($inv['retail_value'] - $inv['cost_value']) ?></small></div>
        </div>
        <div class="card red">
            <div class="ic"><?= icon('alert', 22) ?></div>
            <div><span>Need attention</span><b><?= $inv['low'] + $inv['out_of_stock'] ?></b><small><?= $inv['low'] ?> low · <?= $inv['out_of_stock'] ?> out of stock</small></div>
        </div>
    </div>

    <div class="toolbar no-print">
        <div class="seg">
            <a href="<?= e(report_url(['status' => 'all'])) ?>" class="<?= $status === 'all' ? 'on' : '' ?>">All products</a>
            <a href="<?= e(report_url(['status' => 'low'])) ?>" class="<?= $status === 'low' ? 'on' : '' ?>">Low stock (<?= $inv['low'] ?>)</a>
            <a href="<?= e(report_url(['status' => 'out'])) ?>" class="<?= $status === 'out' ? 'on' : '' ?>">Out of stock (<?= $inv['out_of_stock'] ?>)</a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="inv-table">
            <tr>
                <th>Product</th><th>Unit</th><th class="num">Cost</th><th class="num">Price</th>
                <th class="num">Stock</th><th class="num">Cost value</th><th class="num">Retail value</th><th>Status</th>
            </tr>
            <?php if (!$groups): ?>
                <tr><td colspan="8" class="empty">No products to show.</td></tr>
            <?php endif; ?>
            <?php foreach ($groups as $cat => $g): ?>
                <tr class="group-row">
                    <td colspan="4"><?= e($cat) ?> <span class="muted">· <?= count($g['items']) ?> product(s)</span></td>
                    <td class="num"><?= number_format($g['units']) ?></td>
                    <td class="num"><?= peso($g['cost']) ?></td>
                    <td class="num"><?= peso($g['retail']) ?></td>
                    <td></td>
                </tr>
                <?php foreach ($g['items'] as $p): ?>
                    <?php
                        if ($p['stock'] <= 0)                     { $st = ['low', 'Out of stock']; }
                        elseif ($p['stock'] <= $p['reorder_level']) { $st = ['adjust', 'Low']; }
                        else                                        { $st = ['ok', 'OK']; }
                    ?>
                    <tr>
                        <td class="strong"><?= e($p['name']) ?></td>
                        <td class="muted"><?= e($p['unit']) ?></td>
                        <td class="num"><?= peso($p['cost_price']) ?></td>
                        <td class="num"><?= peso($p['selling_price']) ?></td>
                        <td class="num strong"><?= $p['stock'] ?></td>
                        <td class="num"><?= peso($p['stock'] * $p['cost_price']) ?></td>
                        <td class="num"><?= peso($p['stock'] * $p['selling_price']) ?></td>
                        <td><span class="badge <?= $st[0] ?>"><?= $st[1] ?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </table>
    </div>

<?php endif; ?>

<div class="print-sign">
    <div><span>Prepared by</span><b><?= e($_SESSION['full_name']) ?></b></div>
    <div><span>Checked by</span><b>&nbsp;</b></div>
</div>

<script>
// Tooltip for the sales chart (mouse and keyboard)
(function () {
    const tip = document.getElementById('chartTip');
    if (!tip) return;
    const chart = tip.parentElement;
    function show(slot) {
        const [label, value, qty] = slot.dataset.tip.split('|');
        tip.innerHTML = '<span>' + label + '</span><b>' + value + '</b><span>' + qty + '</span>';
        const c = chart.getBoundingClientRect(), s = slot.getBoundingClientRect();
        let left = s.left - c.left + s.width / 2;
        left = Math.max(70, Math.min(left, c.width - 70));
        tip.style.left = left + 'px';
        tip.classList.add('show');
    }
    chart.querySelectorAll('.bar-slot').forEach(slot => {
        slot.addEventListener('mouseenter', () => show(slot));
        slot.addEventListener('focus', () => show(slot));
        slot.addEventListener('mouseleave', () => tip.classList.remove('show'));
        slot.addEventListener('blur', () => tip.classList.remove('show'));
    });
})();
</script>
<?php require 'includes/footer.php'; ?>
