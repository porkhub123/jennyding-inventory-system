<?php
require 'config/db.php';
require_login();

// Search box and category filter (from the URL, e.g. products.php?q=kape&cat=1)
$q   = trim($_GET['q'] ?? '');
$cat = (int)($_GET['cat'] ?? 0);

$sql = 'SELECT p.*, c.name AS category
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.name LIKE ?';
$like = '%' . $q . '%';

if ($cat > 0) {
    $sql .= ' AND p.category_id = ? ORDER BY p.name';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $like, $cat);
} else {
    $sql .= ' ORDER BY c.name, p.name';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $like);
}
$stmt->execute();
$products = $stmt->get_result();

$categories = $conn->query('SELECT id, name FROM categories ORDER BY name');
$canDelete = $CURRENT_USER->canDeleteRecords();

$pageTitle = 'Products';
$active    = 'products';
require 'includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Products</h2>
        <p><?= $products->num_rows ?> product(s)<?= ($q !== '' || $cat > 0) ? ' found' : ' in the store' ?></p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap">
        <?php if ($canDelete): ?>
            <a class="btn ghost" href="product_images.php"><?= icon('image', 16) ?> Upload pictures</a>
        <?php endif; ?>
        <a class="btn" href="product_form.php"><?= icon('plus', 16) ?> Add product</a>
    </div>
</div>

<form class="toolbar" method="get">
    <div class="search-box">
        <?= icon('search', 18) ?>
        <input type="text" name="q" placeholder="Search product name..." value="<?= e($q) ?>">
    </div>
    <select name="cat" onchange="this.form.submit()">
        <option value="0">All categories</option>
        <?php while ($c = $categories->fetch_assoc()): ?>
            <option value="<?= $c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endwhile; ?>
    </select>
    <button class="btn" type="submit">Search</button>
    <?php if ($q !== '' || $cat > 0): ?>
        <a class="btn ghost" href="products.php">Clear</a>
    <?php endif; ?>
</form>

<div class="table-wrap">
<table>
    <tr>
        <th>Product</th><th>Category</th><th>Unit</th>
        <th class="num">Cost</th><th class="num">Price</th><th class="num">Stock</th><th class="actions">Actions</th>
    </tr>
    <?php if ($products->num_rows === 0): ?>
        <tr><td colspan="7" class="empty">No products found.</td></tr>
    <?php endif; ?>
    <?php while ($p = $products->fetch_assoc()): ?>
        <?php $low = (new Product($p))->isLowStock(); ?>
        <tr>
            <td><div class="prod"><?= product_thumb($p, 40) ?><b><?= e($p['name']) ?></b></div></td>
            <td><span class="badge cat"><?= e($p['category'] ?? '—') ?></span></td>
            <td class="muted"><?= e($p['unit']) ?></td>
            <td class="num">₱<?= number_format($p['cost_price'], 2) ?></td>
            <td class="num strong">₱<?= number_format($p['selling_price'], 2) ?></td>
            <td class="num"><span class="badge <?= $low ? 'low' : 'ok' ?>"><?= $p['stock'] ?><?= $low ? ' · Low' : '' ?></span></td>
            <td class="actions">
                <a class="act" href="stock_in.php?product=<?= $p['id'] ?>" title="Stock in"><?= icon('arrow-in', 15) ?></a>
                <a class="act" href="stock_out.php?product=<?= $p['id'] ?>" title="Stock out"><?= icon('arrow-out', 15) ?></a>
                <a class="act" href="product_form.php?id=<?= $p['id'] ?>"><?= icon('edit', 15) ?> Edit</a>
                <?php if ($canDelete): ?>
                    <form method="post" action="product_delete.php"
                          onsubmit="return confirm('Delete <?= e(addslashes($p['name'])) ?>?');">
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <button type="submit" class="act danger"><?= icon('trash', 15) ?> Delete</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endwhile; ?>
</table>
</div>
<?php require 'includes/footer.php'; ?>
