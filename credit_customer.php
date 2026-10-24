<?php
require 'config/db.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// The Customer object loads itself together with its current balance
$customer = Customer::find($conn, $id);
if (!$customer) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Customer not found.'];
    header('Location: credit.php');
    exit;
}

$errors = [];
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_SESSION['user_id'];
    $amount = trim($_POST['amount'] ?? '');
    $desc   = trim($_POST['description'] ?? '');

    if ($action === 'payment' || $action === 'charge') {
        // The Customer object decides what is allowed (canPay, canCharge)
        if (!is_numeric($amount) || $amount <= 0) {
            $errors[] = 'Amount must be more than 0.';
        } elseif ($action === 'payment' && !$customer->canPay((float)$amount)) {
            $errors[] = 'Payment is more than the balance of ₱' . number_format($customer->getBalance(), 2) . '.';
        } elseif ($action === 'charge' && $desc === '') {
            $errors[] = 'Write what was bought on credit.';
        } elseif ($action === 'charge' && !$customer->canCharge((float)$amount)) {
            $errors[] = 'This would go over the credit limit of ₱' . number_format($customer->getCreditLimit(), 2) . '.';
        }

        if (!$errors) {
            $amt  = round((float)$amount, 2);
            $text = $desc !== '' ? $desc : ($action === 'payment' ? 'Payment' : 'Utang');
            $stmt = $conn->prepare('INSERT INTO credit_transactions (customer_id, user_id, type, amount, description) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('iisds', $id, $userId, $action, $amt, $text);
            $stmt->execute();

            $newBalance = $customer->getBalance() + ($action === 'charge' ? $amt : -$amt);
            $_SESSION['flash'] = ['type' => 'success', 'msg' =>
                ($action === 'payment' ? 'Payment of ₱' : 'Utang of ₱') . number_format($amt, 2)
                . ' recorded. New balance: ₱' . number_format($newBalance, 2) . '.'];
            header('Location: credit_customer.php?id=' . $id);
            exit;
        }
    }

    if ($action === 'update') {
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $limit   = trim($_POST['credit_limit'] ?? '');
        if ($name === '') $errors[] = 'Customer name is required.';
        if ($limit !== '' && (!is_numeric($limit) || $limit <= 0)) $errors[] = 'Credit limit must be more than 0, or blank for no limit.';
        if (!$errors) {
            $stmt = $conn->prepare('SELECT id FROM customers WHERE name = ? AND id <> ?');
            $stmt->bind_param('si', $name, $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) $errors[] = 'Another customer already has this name.';
        }
        if (!$errors) {
            $phoneV = $phone !== '' ? $phone : null;
            $addrV  = $address !== '' ? $address : null;
            $limitV = $limit !== '' ? (float)$limit : null;
            $stmt = $conn->prepare('UPDATE customers SET name = ?, phone = ?, address = ?, credit_limit = ? WHERE id = ?');
            $stmt->bind_param('sssdi', $name, $phoneV, $addrV, $limitV, $id);
            $stmt->execute();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Customer details saved.'];
            header('Location: credit_customer.php?id=' . $id);
            exit;
        }
    }
}

// Ledger: oldest first so we can compute the running balance, then show newest first
$stmt = $conn->prepare(
    'SELECT t.*, u.full_name AS user
     FROM credit_transactions t LEFT JOIN users u ON u.id = t.user_id
     WHERE t.customer_id = ?
     ORDER BY t.created_at, t.id'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$running = 0;
foreach ($rows as &$r) {
    $running += $r['type'] === 'charge' ? $r['amount'] : -$r['amount'];
    $r['running'] = $running;
}
unset($r);
$rows = array_reverse($rows);

$totalCharged = array_sum(array_map(fn($r) => $r['type'] === 'charge' ? $r['amount'] : 0, $rows));
$totalPaid    = array_sum(array_map(fn($r) => $r['type'] === 'payment' ? $r['amount'] : 0, $rows));
$limit        = $customer->getCreditLimit();
$editing      = isset($_GET['edit']) || $action === 'update';

$pageTitle = $customer->getName();
$active    = 'credit';
require 'includes/header.php';
?>
<a class="back" href="credit.php"><?= icon('back', 16) ?> Back to customer credit</a>

<?php if ($errors): ?>
    <div class="flash error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="panel customer-head">
    <div class="prod">
        <div class="avatar lg"><?= e(initials($customer->getName())) ?></div>
        <div>
            <h2><?= e($customer->getName()) ?></h2>
            <p class="muted" style="margin:4px 0 0"><?= e($customer->getPhone() ?: 'No phone') ?> · <?= e($customer->getAddress() ?: 'No address') ?></p>
            <p style="margin:8px 0 0"><a class="act" href="credit_customer.php?id=<?= $id ?>&edit=1" style="padding-left:0"><?= icon('edit', 15) ?> Edit details</a></p>
        </div>
    </div>
    <div class="balance-box <?= $customer->hasBalance() ? 'owing' : 'clear' ?>">
        <span>Current balance</span>
        <b>₱<?= number_format($customer->getBalance(), 2) ?></b>
        <small>
            <?php if ($limit !== null): ?>
                Limit ₱<?= number_format($limit, 2) ?> · ₱<?= number_format($customer->remainingCredit(), 2) ?> left
            <?php else: ?>
                No credit limit
            <?php endif; ?>
        </small>
    </div>
</section>

<?php if ($editing): ?>
<section class="panel" style="margin-bottom:20px">
    <div class="panel-head"><div><h3>Edit customer details</h3></div></div>
    <div class="panel-body">
        <form class="form" method="post" style="max-width:none">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="row">
                <div><label>Name *</label><input type="text" name="name" value="<?= e($_POST['name'] ?? $customer->getName()) ?>" required></div>
                <div><label>Phone</label><input type="text" name="phone" value="<?= e($_POST['phone'] ?? $customer->getPhone()) ?>"></div>
            </div>
            <div class="row">
                <div><label>Address</label><input type="text" name="address" value="<?= e($_POST['address'] ?? $customer->getAddress()) ?>"></div>
                <div><label>Credit limit (₱)</label><input type="number" name="credit_limit" min="1" step="0.01" value="<?= e($_POST['credit_limit'] ?? $customer->getCreditLimit()) ?>" placeholder="Blank = no limit"></div>
            </div>
            <div class="form-actions"><button class="btn" type="submit">Save details</button><a class="btn ghost" href="credit_customer.php?id=<?= $id ?>">Cancel</a></div>
        </form>
    </div>
</section>
<?php endif; ?>

<div class="grid-form">
    <div style="display:flex; flex-direction:column; gap:20px">
        <section class="panel">
            <div class="panel-head"><div><h3>Record a payment</h3><p>Customer paid some or all of the utang</p></div></div>
            <div class="panel-body">
                <form class="form" method="post">
                    <input type="hidden" name="action" value="payment">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <label>Amount paid (₱) *</label>
                    <input type="number" name="amount" min="0.01" step="0.01" max="<?= $customer->getBalance() ?>" placeholder="<?= $customer->getBalance() > 0 ? 'Up to ' . number_format($customer->getBalance(), 2) : 'No balance to pay' ?>" required <?= $customer->getBalance() <= 0 ? 'disabled' : '' ?>>
                    <label>Note</label>
                    <input type="text" name="description" placeholder="e.g. Partial payment, GCash" <?= $customer->getBalance() <= 0 ? 'disabled' : '' ?>>
                    <div class="form-actions">
                        <button class="btn block" type="submit" <?= $customer->getBalance() <= 0 ? 'disabled' : '' ?>>Save payment</button>
                    </div>
                    <?php if ($customer->getBalance() > 0): ?>
                        <p class="hint" style="margin-top:10px"><a href="#" onclick="var f=this.closest('form'); f.amount.value='<?= number_format($customer->getBalance(), 2, '.', '') ?>'; f.description.value='Paid in full'; return false;">Fill in full balance</a></p>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><div><h3>Add utang manually</h3><p>Tip: use Stock Out → Sold → Utang to also deduct the items from stock</p></div></div>
            <div class="panel-body">
                <form class="form" method="post">
                    <input type="hidden" name="action" value="charge">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <label>Amount (₱) *</label>
                    <input type="number" name="amount" min="0.01" step="0.01" required>
                    <label>What was bought *</label>
                    <input type="text" name="description" placeholder="e.g. 1 kilo bigas, 2 sardinas" required>
                    <div class="form-actions"><button class="btn ghost block" type="submit">Add utang</button></div>
                </form>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel-head">
            <div><h3>Utang history</h3><p>Total utang ₱<?= number_format($totalCharged, 2) ?> · Total paid ₱<?= number_format($totalPaid, 2) ?></p></div>
        </div>
        <div class="table-wrap">
            <table>
                <tr><th>Date</th><th>Details</th><th class="num">Utang</th><th class="num">Paid</th><th class="num">Balance</th></tr>
                <?php if (!$rows): ?>
                    <tr><td colspan="5" class="empty">No utang or payments yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="muted" style="white-space:nowrap"><?= date('M j, Y', strtotime($r['created_at'])) ?><br><?= date('g:i A', strtotime($r['created_at'])) ?></td>
                        <td><?= e($r['description']) ?><br><span class="muted">by <?= e($r['user'] ?? '—') ?></span></td>
                        <td class="num qty-out"><?= $r['type'] === 'charge' ? '₱' . number_format($r['amount'], 2) : '' ?></td>
                        <td class="num qty-in"><?= $r['type'] === 'payment' ? '₱' . number_format($r['amount'], 2) : '' ?></td>
                        <td class="num strong">₱<?= number_format($r['running'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </section>
</div>
<?php require 'includes/footer.php'; ?>
