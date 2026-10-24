<?php
require 'config/db.php';
require_admin();

$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;
$myId   = (int)$_SESSION['user_id'];
$isMe   = $isEdit && $id === $myId;

$user = ['full_name' => '', 'username' => '', 'role' => 'staff', 'is_active' => 1];

if ($isEdit) {
    $stmt = $conn->prepare('SELECT id, full_name, username, role, is_active FROM users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'User not found.'];
        header('Location: users.php');
        exit;
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user['full_name'] = trim($_POST['full_name'] ?? '');
    $user['username']  = trim($_POST['username'] ?? '');
    // You can't change your own role (so you never lock yourself out of admin pages)
    if (!$isMe) $user['role'] = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if ($user['full_name'] === '') $errors[] = 'Full name is required.';
    if (!preg_match('/^[A-Za-z0-9_]{4,30}$/', $user['username'])) $errors[] = 'Username must be 4-30 letters, numbers or _ only.';
    if (!$isEdit) {
        if (strlen($password) < 6)   $errors[] = 'Password must be at least 6 characters.';
        if ($password !== $confirm)  $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
        $stmt->bind_param('si', $user['username'], $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $errors[] = 'That username is already taken.';
    }

    // Don't remove the last active admin
    if (!$errors && $isEdit && $user['role'] === 'staff') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $others = $stmt->get_result()->fetch_assoc()['c'];
        if ($others == 0) $errors[] = 'The store needs at least one active admin.';
    }

    if (!$errors) {
        if ($isEdit) {
            $stmt = $conn->prepare('UPDATE users SET full_name = ?, username = ?, role = ? WHERE id = ?');
            $stmt->bind_param('sssi', $user['full_name'], $user['username'], $user['role'], $id);
            $stmt->execute();
            $msg = 'User updated.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (full_name, username, password, role, is_active) VALUES (?, ?, ?, ?, 1)');
            $stmt->bind_param('ssss', $user['full_name'], $user['username'], $hash, $user['role']);
            $stmt->execute();
            $msg = "Account for {$user['full_name']} created. They can log in now.";
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: users.php');
        exit;
    }
}

$pageTitle = $isEdit ? 'Edit User' : 'Add User';
$active    = 'users';
require 'includes/header.php';
?>
<a class="back" href="users.php"><?= icon('back', 16) ?> Back to users</a>
<div class="page-head">
    <div>
        <h2><?= $isEdit ? 'Edit User' : 'Add User' ?></h2>
        <p><?= $isEdit ? 'Change the name, username or role.' : 'Create an account for a store helper or co-owner.' ?></p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="flash error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form class="form" method="post" autocomplete="off">
    <input type="hidden" name="id" value="<?= $id ?>">

    <label>Full name</label>
    <input type="text" name="full_name" value="<?= e($user['full_name']) ?>" placeholder="e.g. Maria Santos" required>

    <label>Username</label>
    <input type="text" name="username" value="<?= e($user['username']) ?>" placeholder="e.g. maria" required>
    <div class="hint">4-30 letters, numbers or underscore. Used to log in.</div>

    <label>Role</label>
    <?php if ($isMe): ?>
        <div class="hint" style="margin-top:0">You are an <b>Admin</b>. You can't change your own role.</div>
    <?php else: ?>
        <div class="role-pick">
            <label class="role-card">
                <input type="radio" name="role" value="staff" <?= $user['role'] === 'staff' ? 'checked' : '' ?>>
                <span><b>Staff</b><small>Can manage products, stock in/out and view reports. Can't delete products or manage users.</small></span>
            </label>
            <label class="role-card">
                <input type="radio" name="role" value="admin" <?= $user['role'] === 'admin' ? 'checked' : '' ?>>
                <span><b>Admin (owner)</b><small>Full access, including deleting products, categories and managing users.</small></span>
            </label>
        </div>
    <?php endif; ?>

    <?php if (!$isEdit): ?>
        <div class="row">
            <div>
                <label>Password</label>
                <input type="password" name="password" minlength="6" placeholder="At least 6 characters" required>
            </div>
            <div>
                <label>Confirm password</label>
                <input type="password" name="confirm" minlength="6" placeholder="Type it again" required>
            </div>
        </div>
    <?php else: ?>
        <div class="hint" style="margin-top:16px">To change this user's password, use <b>Reset password</b> on the Users page.</div>
    <?php endif; ?>

    <div class="form-actions">
        <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Create account' ?></button>
        <a href="users.php" class="btn ghost">Cancel</a>
    </div>
</form>
<?php require 'includes/footer.php'; ?>
