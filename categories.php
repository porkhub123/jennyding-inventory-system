<?php
require 'config/db.php';
require_login();

$isAdmin = $CURRENT_USER->canDeleteRecords();

// ---------- Handle Add / Rename / Delete (admin only) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $flash  = ['type' => 'error', 'msg' => 'Unknown action.'];

    if (!$isAdmin) {
        $flash = ['type' => 'error', 'msg' => 'Only the admin can change categories.'];
    } elseif ($action === 'add' || $action === 'rename') {
        if ($name === '') {
            $flash = ['type' => 'error', 'msg' => 'Category name is required.'];
        } else {
            // Check for duplicate name (ignore the one being renamed)
            $stmt = $conn->prepare('SELECT id FROM categories WHERE name = ? AND id <> ?');
            $stmt->bind_param('si', $name, $id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $flash = ['type' => 'error', 'msg' => "\"$name\" already exists."];
            } elseif ($action === 'add') {
                $stmt = $conn->prepare('INSERT INTO categories (name) VALUES (?)');
                $stmt->bind_param('s', $name);
                $stmt->execute();
                $flash = ['type' => 'success', 'msg' => "Category \"$name\" added."];
            } else {
                $stmt = $conn->prepare('UPDATE categories SET name = ? WHERE id = ?');
                $stmt->bind_param('si', $name, $id);
                $stmt->execute();
                $flash = ['type' => 'success', 'msg' => 'Category renamed.'];
            }
        }
    } elseif ($action === 'delete') {
        // Don't allow deleting a category that still has products
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM products WHERE category_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['c'];

        if ($count > 0) {
            $flash = ['type' => 'error', 'msg' => "Can't delete: this category still has $count product(s). Move or delete them first."];
        } else {
            $stmt = $conn->prepare('DELETE FROM categories WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $flash = ['type' => 'success', 'msg' => 'Category deleted.'];
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: categories.php');
    exit;
}

// ---------- List categories with product count and stock value ----------
$categories = $conn->query(
    'SELECT c.id, c.name,
            COUNT(p.id) AS products,
            COALESCE(SUM(p.stock), 0) AS total_stock,
            COALESCE(SUM(p.stock * p.cost_price), 0) AS stock_value,
            COALESCE(SUM(p.stock <= p.reorder_level), 0) AS low_stock
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id
     GROUP BY c.id, c.name
     ORDER BY c.name'
);

// Which row is being renamed (categories.php?edit=3)
$editId = (int)($_GET['edit'] ?? 0);

$pageTitle = 'Categories';
$active    = 'categories';
require 'includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Categories</h2>
        <p>Group your products and see stock per category.</p>
    </div>
</div>

<?php if ($isAdmin): ?>
<form class="toolbar" method="post">
    <input type="hidden" name="action" value="add">
    <input type="text" name="name" placeholder="New category name (e.g. Frozen Goods)" required>
    <button class="btn" type="submit"><?= icon('plus', 16) ?> Add category</button>
</form>
<?php endif; ?>

<div class="table-wrap">
<table>
    <tr>
        <th>Category</th>
        <th class="num">Products</th>
        <th class="num">Total stock</th>
        <th class="num">Stock value</th>
        <th class="num">Low stock</th>
        <?php if ($isAdmin): ?><th class="actions">Actions</th><?php endif; ?>
    </tr>
    <?php if ($categories->num_rows === 0): ?>
        <tr><td colspan="6" class="empty">No categories yet.</td></tr>
    <?php endif; ?>
    <?php while ($c = $categories->fetch_assoc()): ?>
        <tr>
            <td>
                <?php if ($isAdmin && $editId === (int)$c['id']): ?>
                    <!-- Rename form shown in place of the name -->
                    <form method="post" class="inline-edit">
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <input type="text" name="name" value="<?= e($c['name']) ?>" required autofocus>
                        <button class="btn small" type="submit">Save</button>
                        <a href="categories.php">Cancel</a>
                    </form>
                <?php else: ?>
                    <a class="strong" href="products.php?cat=<?= $c['id'] ?>"><span class="badge cat"><?= icon('tag', 13) ?> <?= e($c['name']) ?></span></a>
                <?php endif; ?>
            </td>
            <td class="num"><?= $c['products'] ?></td>
            <td class="num"><?= $c['total_stock'] ?></td>
            <td class="num strong">₱<?= number_format($c['stock_value'], 2) ?></td>
            <td class="num"><span class="badge <?= $c['low_stock'] > 0 ? 'low' : 'ok' ?>"><?= $c['low_stock'] ?></span></td>
            <?php if ($isAdmin): ?>
                <td class="actions">
                    <a class="act" href="categories.php?edit=<?= $c['id'] ?>"><?= icon('edit', 15) ?> Rename</a>
                    <form method="post" onsubmit="return confirm('Delete this category?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                        <button type="submit" class="act danger"><?= icon('trash', 15) ?> Delete</button>
                    </form>
                </td>
            <?php endif; ?>
        </tr>
    <?php endwhile; ?>
</table>
</div>
<p class="muted" style="margin-top:12px">Tip: click a category to see its products.</p>
<?php require 'includes/footer.php'; ?>
