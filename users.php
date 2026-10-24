<?php
require 'config/db.php';
require_admin();

$myId = (int)$_SESSION['user_id'];

// Number of active admins (the store must always keep at least one)
function active_admins($conn) {
    return (int)$conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND is_active = 1")->fetch_assoc()['c'];
}

// ---------- Actions: activate / deactivate / reset password ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    $stmt = $conn->prepare('SELECT id, full_name, role, is_active FROM users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    $flash = ['type' => 'error', 'msg' => 'User not found.'];

    if ($user && $action === 'activate') {
        $stmt = $conn->prepare('UPDATE users SET is_active = 1 WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $flash = ['type' => 'success', 'msg' => "{$user['full_name']} can now log in."];

    } elseif ($user && $action === 'deactivate') {
        if ($id === $myId) {
            $flash = ['type' => 'error', 'msg' => "You can't deactivate your own account."];
        } elseif ($user['role'] === 'admin' && active_admins($conn) <= 1) {
            $flash = ['type' => 'error', 'msg' => 'The store needs at least one active admin.'];
        } else {
            $stmt = $conn->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $flash = ['type' => 'success', 'msg' => "{$user['full_name']} has been deactivated and can no longer log in."];
        }

    } elseif ($user && $action === 'reset') {
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (strlen($new) < 6) {
            $flash = ['type' => 'error', 'msg' => 'New password must be at least 6 characters.'];
        } elseif ($new !== $confirm) {
            $flash = ['type' => 'error', 'msg' => 'The two passwords do not match.'];
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->bind_param('si', $hash, $id);
            $stmt->execute();
            $flash = ['type' => 'success', 'msg' => "Password for {$user['full_name']} has been reset. Give them the new password."];
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: users.php');
    exit;
}

// ---------- List ----------
// Waiting-for-approval accounts first, then active, then deactivated
$users = $conn->query(
    'SELECT id, full_name, username, role, is_active, last_login, created_at
     FROM users
     ORDER BY (is_active = 0 AND last_login IS NULL) DESC, is_active DESC, role, full_name'
)->fetch_all(MYSQLI_ASSOC);

$counts = ['all' => count($users), 'active' => 0, 'pending' => 0, 'admins' => 0];
foreach ($users as $u) {
    if ($u['is_active']) $counts['active']++;
    if (!$u['is_active'] && $u['last_login'] === null) $counts['pending']++;
    if ($u['role'] === 'admin' && $u['is_active']) $counts['admins']++;
}

$resetId = (int)($_GET['reset'] ?? 0);

$pageTitle = 'Users';
$active    = 'users';
require 'includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>User Management</h2>
        <p>Add staff accounts, approve sign-ups, reset passwords and switch accounts off.</p>
    </div>
    <a class="btn" href="user_form.php"><?= icon('plus', 16) ?> Add user</a>
</div>

<div class="summary">
    <div class="chip"><span>All users</span><b><?= $counts['all'] ?></b></div>
    <div class="chip"><span>Active</span><b><?= $counts['active'] ?></b></div>
    <div class="chip"><span>Waiting for approval</span><b class="<?= $counts['pending'] ? 'qty-out' : '' ?>"><?= $counts['pending'] ?></b></div>
    <div class="chip"><span>Admins</span><b><?= $counts['admins'] ?></b></div>
</div>

<div class="table-wrap">
<table>
    <tr><th>User</th><th>Role</th><th>Status</th><th>Last login</th><th class="actions">Actions</th></tr>
    <?php foreach ($users as $u): ?>
        <?php
            $pending = !$u['is_active'] && $u['last_login'] === null;
            $isMe    = (int)$u['id'] === $myId;
        ?>
        <tr class="<?= $pending ? 'row-pending' : (!$u['is_active'] ? 'row-off' : '') ?>">
            <td>
                <div class="prod">
                    <div class="avatar sm"><?= e(initials($u['full_name'])) ?></div>
                    <div>
                        <b><?= e($u['full_name']) ?></b><?= $isMe ? ' <span class="badge gray">You</span>' : '' ?><br>
                        <span class="muted">@<?= e($u['username']) ?></span>
                    </div>
                </div>
            </td>
            <td><span class="badge <?= $u['role'] === 'admin' ? 'cat' : 'gray' ?>"><?= $u['role'] === 'admin' ? 'Admin (owner)' : 'Staff' ?></span></td>
            <td>
                <?php if ($u['is_active']): ?>
                    <span class="badge ok">● Active</span>
                <?php elseif ($pending): ?>
                    <span class="badge adjust">Waiting for approval</span>
                <?php else: ?>
                    <span class="badge low">Deactivated</span>
                <?php endif; ?>
            </td>
            <td class="muted"><?= $u['last_login'] ? date('M j, Y g:i A', strtotime($u['last_login'])) : 'Never' ?></td>
            <td class="actions">
                <?php if ($pending): ?>
                    <form method="post">
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button class="btn small" type="submit">✓ Approve</button>
                    </form>
                <?php endif; ?>
                <a class="act" href="user_form.php?id=<?= $u['id'] ?>"><?= icon('edit', 15) ?> Edit</a>
                <a class="act" href="users.php?reset=<?= $u['id'] ?>#reset"><?= icon('key', 15) ?> Reset password</a>
                <?php if (!$isMe && !$pending): ?>
                    <form method="post" onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> <?= e(addslashes($u['full_name'])) ?>?');">
                        <input type="hidden" name="action" value="<?= $u['is_active'] ? 'deactivate' : 'activate' ?>">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button type="submit" class="act <?= $u['is_active'] ? 'danger' : '' ?>"><?= icon($u['is_active'] ? 'lock' : 'unlock', 15) ?> <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>

        <?php if ($resetId === (int)$u['id']): ?>
            <tr id="reset" class="reset-row">
                <td colspan="5">
                    <form method="post" class="reset-form">
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <b><?= icon('key', 16) ?> New password for <?= e($u['full_name']) ?></b>
                        <input type="password" name="new_password" placeholder="New password (min. 6)" minlength="6" required autofocus>
                        <input type="password" name="confirm_password" placeholder="Type it again" minlength="6" required>
                        <button class="btn small" type="submit">Save password</button>
                        <a href="users.php" class="act">Cancel</a>
                    </form>
                </td>
            </tr>
        <?php endif; ?>
    <?php endforeach; ?>
</table>
</div>

<p class="muted" style="margin-top:12px">
    Users are never deleted, so the history keeps showing who recorded each stock movement. Deactivate an account instead.
</p>
<?php require 'includes/footer.php'; ?>
