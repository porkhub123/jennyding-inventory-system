<?php
require 'config/db.php';
require_login();

// Only the admin (store owner) can delete, and only through the Delete button (POST)
if (!$CURRENT_USER->canDeleteRecords() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'You are not allowed to do that.'];
    header('Location: products.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);

// Remember the picture so we can remove the file too
$stmt = $conn->prepare('SELECT image FROM products WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$image = $stmt->get_result()->fetch_assoc()['image'] ?? null;

$stmt = $conn->prepare('DELETE FROM products WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();

if ($stmt->affected_rows > 0) delete_product_image($image);

$_SESSION['flash'] = $stmt->affected_rows > 0
    ? ['type' => 'success', 'msg' => 'Product deleted.']
    : ['type' => 'error',   'msg' => 'Product not found.'];

header('Location: products.php');
exit;
