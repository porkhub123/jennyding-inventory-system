<?php
require 'config/db.php';
require_login();

// ---------- Add a new customer ----------
$errors = [];
$form = ['name' => '', 'phone' => '', 'address' => '', 'credit_limit' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['name']         = trim($_POST['name'] ?? '');
    $form['phone']        = trim($_POST['phone'] ?? '');
    $form['address']      = trim($_POST['address'] ?? '');
    $form['credit_limit'] = trim($_POST['credit_limit'] ?? '');

    if ($form['name'] === '') $errors[] = 'Customer name is required.';
    if ($form['credit_limit'] !== '' && (!is_numeric($form['credit_limit']) || $form['credit_limit'] <= 0)) {
        $errors[] = 'Credit limit must be more than 0, or leave it blank for no limit.';
    }
    if (!$errors) {
        $stmt = $conn->prepare('SELECT id FROM customers WHERE name = ?');
        $stmt->bind_param('s', $form['name']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $errors[] = 'A customer with this name already exists. Add a nickname or surname to tell them apart.';
    }

    if (!$errors) {
        $phone   = $form['phone'] !== '' ? $form['phone'] : null;
        $address = $form['address'] !== '' ? $form['address'] : null;
        $limit   = $form['credit_limit'] !== '' ? (float)$form['credit_limit'] : null;
        $stmt = $conn->prepare('INSERT INTO customers (name, phone, address, credit_limit) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('sssd', $form['name'], $phone, $address, $limit);
        $stmt->execute();
        $newId = $conn->insert_id;

        $_SESSION['flash'] = ['type' => 'success', 'msg' => "Customer {$form['name']} added."];
        header('Location: credit_customer.php?id=' . $newId);
        exit;
    }
}

// ---------- List customers with their balance ----------
$q    = trim($_GET['q'] ?? '');
$show = ($_GET['show'] ?? 'owing') === 'all' ? 'all' : 'owing';
$like = '%' . $q . '%';

$sql = "SELECT c.*,
               COALESCE(SUM(CASE WHEN t.type = 'charge' THEN t.amount ELSE -t.amount END), 0) AS balance,
               MAX(t.created_at) AS last_activity
        FROM customers c
        LEFT JOIN credit_transactions t ON t.customer_id = c.id
        WHERE c.name LIKE ?
        GROUP BY c.id, c.name, c.phone, c.address, c.credit_limit, c.created_at";
if ($show === 'owing') $sql .= ' HAVING balance > 0';
$sql .= ' ORDER BY balance DESC, c.name';

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $like);
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Totals for the cards
$totals = $conn->query(
    "SELECT
        COALESCE(SUM(CASE WHEN type = 'charge' THEN amount ELSE -amount END), 0) AS outstanding,
        COALESCE(SUM(CASE WHEN type = 'payment' AND DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN amount END), 0) AS collected_month
     FROM credit_transactions"
)->fetch_assoc();
$owingCount = $conn->query(
    "SELECT COUNT(*) AS c FROM (
        SELECT customer_id FROM credit_transactions
        GROUP BY customer_id
        HAVING SUM(CASE WHEN type = 'charge' THEN amount ELSE -amount END) > 0
     ) x"
)->fetch_assoc()['c'];

$pageTitle = 'Customer Credit';
$active    = 'credit';
require 'includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Customer Credit (Utang)</h2>
        <p>Track what customers owe, record payments, and follow up on balances.</p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="flash error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="cards">
    <div class="card red">
        <div class="ic"><?= icon('wallet', 22) ?></div>
        <div><span>Total utang to collect</span><b>₱<?= number_format($totals['outstanding'], 2) ?></b><small><?= $owingCount ?> customer(s) with a balance</small></div>
    </div>
    <div class="card green">
        <div class="ic"><?= icon('cart', 22) ?></div>
        <div><span>Collected this month</span><b>₱<?= number_format($totals['collected_month'], 2) ?></b><small>payments received</small></div>
    </div>
</div>

<div class="grid-form">
    <section class="panel">
        <div class="panel-head"><div><h3>New customer</h3><p>Add someone who buys on credit</p></div></div>
        <div class="panel-body">
            <form class="form" method="post">
                <label>Name *</label>
                <input type="text" name="name" value="<?= e($form['name']) ?>" placeholder="e.g. Aling Rosa" required>
                <label>Phone</label>
                <input type="text" name="phone" value="<?= e($form['phone']) ?>" placeholder="e.g. 0917 123 4567">
                <label>Address</label>
                <input type="text" name="address" value="<?= e($form['address']) ?>" placeholder="e.g. Sapphire St.">
                <label>Credit limit (₱)</label>
                <input type="number" name="credit_limit" min="1" step="0.01" value="<?= e($form['credit_limit']) ?>" placeholder="Leave blank for no limit">
                <div class="hint">The system will refuse new utang that goes over this limit.</div>
                <div class="form-actions"><button class="btn block" type="submit"><?= icon('plus', 16) ?> Add customer</button></div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head" style="flex-wrap:wrap">
            <div><h3><?= $show === 'all' ? 'All customers' : 'Customers with utang' ?></h3><p><?= count($customers) ?> customer(s)</p></div>
            <div class="seg">
                <a href="credit.php?show=owing&q=<?= urlencode($q) ?>" class="<?= $show === 'owing' ? 'on' : '' ?>">With balance</a>
                <a href="credit.php?show=all&q=<?= urlencode($q) ?>" class="<?= $show === 'all' ? 'on' : '' ?>">All</a>
            </div>
        </div>
        <div class="panel-body" style="padding-bottom:0">
            <form class="toolbar" method="get" style="margin-bottom:12px">
                <input type="hidden" name="show" value="<?= e($show) ?>">
                <div class="search-box"><?= icon('search', 18) ?><input type="text" name="q" value="<?= e($q) ?>" placeholder="Search customer..."></div>
                <button class="btn" type="submit">Search</button>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <tr><th>Customer</th><th class="num">Balance</th><th>Last activity</th><th class="actions"></th></tr>
                <?php if (!$customers): ?>
                    <tr><td colspan="4" class="empty"><?= $show === 'owing' ? '🎉 No one owes the store right now.' : 'No customers yet. Add one on the left.' ?></td></tr>
                <?php endif; ?>
                <?php foreach ($customers as $c): ?>
                    <?php $over = $c['credit_limit'] !== null && $c['balance'] > $c['credit_limit']; ?>
                    <tr>
                        <td>
                            <div class="prod">
                                <div class="avatar sm"><?= e(initials($c['name'])) ?></div>
                                <div><b><?= e($c['name']) ?></b><br><span class="muted"><?= e($c['phone'] ?: 'No phone') ?></span></div>
                            </div>
                        </td>
                        <td class="num">
                            <?php if ($c['balance'] > 0): ?>
                                <b class="qty-out">₱<?= number_format($c['balance'], 2) ?></b>
                                <?php if ($c['credit_limit'] !== null): ?><br><span class="muted">limit ₱<?= number_format($c['credit_limit'], 2) ?><?= $over ? ' · over' : '' ?></span><?php endif; ?>
                            <?php else: ?>
                                <span class="badge ok">Paid up</span>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?= $c['last_activity'] ? date('M j, Y', strtotime($c['last_activity'])) : '—' ?></td>
                        <td class="actions"><a class="btn small" href="credit_customer.php?id=<?= $c['id'] ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </section>
</div>
<?php require 'includes/footer.php'; ?>
