<?php
require 'config/db.php';
require_login();

$myId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT full_name, username, role, last_login, created_at FROM users WHERE id = ?');
$stmt->bind_param('i', $myId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();

$errors = [];
$form = $_POST['form'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'profile') {
    $name = trim($_POST['full_name'] ?? '');
    if ($name === '') {
        $errors[] = 'Full name is required.';
    } else {
        $stmt = $conn->prepare('UPDATE users SET full_name = ? WHERE id = ?');
        $stmt->bind_param('si', $name, $myId);
        $stmt->execute();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Your name was updated.'];
        header('Location: account.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form === 'password') {
    $current = $_POST['current'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->bind_param('i', $myId);
    $stmt->execute();
    $hash = $stmt->get_result()->fetch_assoc()['password'];

    if (!password_verify($current, $hash)) $errors[] = 'Your current password is wrong.';
    if (strlen($new) < 6)                  $errors[] = 'New password must be at least 6 characters.';
    if ($new !== $confirm)                 $errors[] = 'The two new passwords do not match.';

    if (!$errors) {
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $newHash, $myId);
        $stmt->execute();
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Your password was changed.'];
        header('Location: account.php');
        exit;
    }
}

$pageTitle = 'My Account';
$active    = 'account';
require 'includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>My Account</h2>
        <p>Update your name and password.</p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="flash error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head"><div><h3>Profile</h3><p>How your name appears in the system</p></div></div>
        <div class="panel-body">
            <div class="profile-top">
                <div class="avatar lg"><?= e(initials($me['full_name'])) ?></div>
                <div>
                    <b><?= e($me['full_name']) ?></b><br>
                    <span class="muted">@<?= e($me['username']) ?> · <?= $me['role'] === 'admin' ? 'Admin (owner)' : 'Staff' ?></span><br>
                    <span class="muted">Member since <?= date('M j, Y', strtotime($me['created_at'])) ?></span>
                </div>
            </div>
            <form class="form" method="post">
                <input type="hidden" name="form" value="profile">
                <label>Full name</label>
                <input type="text" name="full_name" value="<?= e($me['full_name']) ?>" required>
                <div class="form-actions"><button class="btn" type="submit">Save name</button></div>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h3>Change password</h3><p>Use at least 6 characters</p></div></div>
        <div class="panel-body">
            <form class="form" method="post" autocomplete="off">
                <input type="hidden" name="form" value="password">
                <label>Current password</label>
                <input type="password" name="current" required>
                <label>New password</label>
                <input type="password" name="new_password" minlength="6" required>
                <label>Confirm new password</label>
                <input type="password" name="confirm_password" minlength="6" required>
                <div class="form-actions"><button class="btn" type="submit"><?= icon('key', 16) ?> Change password</button></div>
            </form>
        </div>
    </section>
</div>
<?php require 'includes/footer.php'; ?>
