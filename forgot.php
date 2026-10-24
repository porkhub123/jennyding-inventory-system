<?php
require 'config/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password - Jenny &amp; Ding Store</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="assets/style.css">
<?php include 'includes/pwa.php'; ?>
</head>
<body class="auth-page">
    <div class="auth-window">
        <div class="auth-titlebar">Jenny &amp; Ding Inventory System</div>

        <div class="auth-body">
            <?php require 'includes/auth_brand.php'; ?>

            <div class="auth-form">
                <h1>FORGOT PASSWORD?</h1>
                <p class="auth-sub">No problem.</p>

                <div class="auth-note">
                    For security, passwords can only be reset by the <b>store owner (admin)</b>.
                    Please ask the owner to reset your password, then log in with the new one.
                </div>

                <div class="auth-buttons">
                    <a href="login.php" class="auth-btn primary">BACK TO LOGIN</a>
                </div>

                <p class="auth-copy">© 2026 Jenny &amp; Ding Store</p>
            </div>
        </div>
    </div>
</body>
</html>
