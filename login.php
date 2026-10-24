<?php
require 'config/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
// "Remember me" saves the username in a cookie so it is filled in next time
$username = $_COOKIE['remember_user'] ?? '';
$remember = $username !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    // Prepared statement = safe from SQL injection
    $stmt = $conn->prepare('SELECT id, full_name, password, role, is_active, last_login FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user['password']) && !$user['is_active']) {
        // Right password, but the account is switched off
        $error = $user['last_login'] === null
            ? 'Your account is waiting for approval. Please ask the store owner to activate it.'
            : 'Your account has been deactivated. Please ask the store owner.';
    } elseif ($user && password_verify($password, $user['password'])) {
        // Remember when this user last logged in (shown in User Management)
        $stmt = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();

        if ($remember) {
            setcookie('remember_user', $username, time() + 60 * 60 * 24 * 30, '/'); // 30 days
        } else {
            setcookie('remember_user', '', time() - 3600, '/'); // forget it
        }
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Wrong username or password.';
    }
}

// Message from the Register page ("Account created...")
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Jenny &amp; Ding Store</title>
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
                <h1>USER LOGIN</h1>
                <p class="auth-sub">Please log in to access the system.</p>

                <?php if ($flash): ?>
                    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="flash error"><?= e($error) ?></div>
                <?php endif; ?>

                <input type="text" name="username" placeholder="Enter your username"
                       value="<?= e($username) ?>" required <?= $username === '' ? 'autofocus' : '' ?>>
                <input type="password" name="password" placeholder="Enter your password"
                       required <?= $username !== '' ? 'autofocus' : '' ?>>

                <div class="auth-row">
                    <label class="check"><input type="checkbox" name="remember" <?= $remember ? 'checked' : '' ?>> Remember me</label>
                    <a href="forgot.php">Forgot password?</a>
                </div>

                <div class="auth-buttons">
                    <button type="submit" class="auth-btn primary">LOGIN</button>
                    <button type="button" class="auth-btn outline" onclick="this.form.querySelectorAll('input[type=text],input[type=password]').forEach(i => i.value = ''); this.form.querySelector('input').focus();">CLEAR</button>
                </div>

                <p class="auth-foot">Don't have an account? <a href="register.php"><b>Register</b></a></p>
                <p class="auth-copy">© 2026 Jenny &amp; Ding Store</p>
            </form>
        </div>
    </div>
</body>
</html>
