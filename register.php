<?php
require 'config/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$errors = [];
$fullName = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if ($fullName === '')                         $errors[] = 'Full name is required.';
    if (!preg_match('/^[A-Za-z0-9_]{4,30}$/', $username)) $errors[] = 'Username must be 4-30 letters, numbers or _ only.';
    if (strlen($password) < 6)                    $errors[] = 'Password must be at least 6 characters.';
    if ($password !== $confirm)                   $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $errors[] = 'That username is already taken.';
    }

    if (!$errors) {
        // New accounts are always "staff" and start switched OFF (is_active = 0).
        // The store owner approves them in User Management before they can log in.
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role, is_active) VALUES (?, ?, ?, 'staff', 0)");
        $stmt->bind_param('sss', $fullName, $username, $hash);
        $stmt->execute();

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Account created! Please wait for the store owner to approve it before logging in.'];
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register - Jenny &amp; Ding Store</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/style.css">
<?php include 'includes/pwa.php'; ?>
</head>
<body class="auth-page">
    <div class="auth-window">
        <div class="auth-titlebar">Jenny &amp; Ding Inventory System</div>

        <div class="auth-body">
            <?php require 'includes/auth_brand.php'; ?>

            <form class="auth-form" method="post">
                <h1>CREATE ACCOUNT</h1>
                <p class="auth-sub">New staff accounts need the store owner's approval.</p>

                <?php if ($errors): ?>
                    <div class="flash error">
                        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <input type="text" name="full_name" placeholder="Enter your full name" value="<?= e($fullName) ?>" required autofocus>
                <input type="text" name="username" placeholder="Choose a username" value="<?= e($username) ?>" required>
                <input type="password" name="password" placeholder="Create a password (min. 6 characters)" required>
                <input type="password" name="confirm" placeholder="Confirm your password" required>

                <div class="auth-buttons">
                    <button type="submit" class="auth-btn primary">REGISTER</button>
                    <button type="button" class="auth-btn outline" onclick="this.form.querySelectorAll('input[type=text],input[type=password]').forEach(i => i.value = ''); this.form.querySelector('input').focus();">CLEAR</button>
                </div>

                <p class="auth-foot">Already have an account? <a href="login.php"><b>Log in</b></a></p>
                <p class="auth-copy">© 2026 Jenny &amp; Ding Store</p>
            </form>
        </div>
    </div>
</body>
</html>
