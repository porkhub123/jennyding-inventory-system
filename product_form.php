<?php
require 'config/db.php';
require_login();

// If there's an ?id= in the URL we are editing, otherwise adding
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;

// Default empty values for a new product
$product = [
    'name' => '', 'category_id' => '', 'unit' => 'pc',
    'cost_price' => '', 'selling_price' => '', 'stock' => 0, 'reorder_level' => 5, 'image' => null,
];

if ($isEdit) {
    $stmt = $conn->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    if (!$product) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Product not found.'];
        header('Location: products.php');
        exit;
    }
}
$oldStock = (int)$product['stock'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read what the user typed
    $product['name']          = trim($_POST['name'] ?? '');
    $product['category_id']   = (int)($_POST['category_id'] ?? 0);
    $product['unit']          = trim($_POST['unit'] ?? '');
    $product['cost_price']    = $_POST['cost_price'] ?? '';
    $product['selling_price'] = $_POST['selling_price'] ?? '';
    $product['stock']         = $_POST['stock'] ?? '';
    $product['reorder_level'] = $_POST['reorder_level'] ?? '';

    // Validate
    if ($product['name'] === '')                                   $errors[] = 'Product name is required.';
    if ($product['category_id'] <= 0)                              $errors[] = 'Please choose a category.';
    if ($product['unit'] === '')                                   $errors[] = 'Unit is required (e.g. pc, pack, sachet).';
    if (!is_numeric($product['cost_price'])    || $product['cost_price'] < 0)    $errors[] = 'Cost must be 0 or more.';
    if (!is_numeric($product['selling_price']) || $product['selling_price'] < 0) $errors[] = 'Selling price must be 0 or more.';
    if (filter_var($product['stock'], FILTER_VALIDATE_INT) === false || $product['stock'] < 0)                 $errors[] = 'Stock must be a whole number, 0 or more.';
    if (filter_var($product['reorder_level'], FILTER_VALIDATE_INT) === false || $product['reorder_level'] < 0) $errors[] = 'Reorder level must be a whole number, 0 or more.';

    // No duplicate names
    if (!$errors) {
        $stmt = $conn->prepare('SELECT id FROM products WHERE name = ? AND id <> ?');
        $stmt->bind_param('si', $product['name'], $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) $errors[] = 'A product with this name already exists.';
    }

    // Picture (optional): save the new upload, if any
    $newImage = null;
    if (!$errors) {
        [$newImage, $imgError] = save_product_image($_FILES['image'] ?? []);
        if ($imgError) $errors[] = $imgError;
    }

    if (!$errors) {
        $catId = $product['category_id'];
        $cost  = (float)$product['cost_price'];
        $price = (float)$product['selling_price'];
        $stock = (int)$product['stock'];
        $reord = (int)$product['reorder_level'];
        $userId = $_SESSION['user_id'];

        // Decide which picture to keep: new upload > remove > keep the old one
        $oldImage = $product['image'];
        $image = $oldImage;
        if ($newImage) {
            $image = $newImage;
        } elseif (isset($_POST['remove_image'])) {
            $image = null;
        }

        if ($isEdit) {
            $stmt = $conn->prepare('UPDATE products SET category_id=?, name=?, unit=?, cost_price=?, selling_price=?, stock=?, reorder_level=?, image=? WHERE id=?');
            $stmt->bind_param('issddiisi', $catId, $product['name'], $product['unit'], $cost, $price, $stock, $reord, $image, $id);
            $stmt->execute();

            // Delete the old picture file if it was replaced or removed
            if ($oldImage && $oldImage !== $image) delete_product_image($oldImage);

            // Record a stock adjustment if the stock number was changed
            $diff = $stock - $oldStock;
            if ($diff !== 0) {
                $remarks = "Manual edit ($oldStock → $stock)";
                $stmt = $conn->prepare("INSERT INTO stock_logs (product_id, user_id, type, quantity, remarks) VALUES (?, ?, 'adjust', ?, ?)");
                $stmt->bind_param('iiis', $id, $userId, $diff, $remarks);
                $stmt->execute();
            }
            $msg = 'Product updated.';
        } else {
            $stmt = $conn->prepare('INSERT INTO products (category_id, name, unit, cost_price, selling_price, stock, reorder_level, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('issddiis', $catId, $product['name'], $product['unit'], $cost, $price, $stock, $reord, $image);
            $stmt->execute();
            $newId = $conn->insert_id;

            // Record the starting stock
            if ($stock > 0) {
                $remarks = 'Initial stock';
                $stmt = $conn->prepare("INSERT INTO stock_logs (product_id, user_id, type, quantity, unit_price, remarks) VALUES (?, ?, 'in', ?, ?, ?)");
                $stmt->bind_param('iiids', $newId, $userId, $stock, $cost, $remarks);
                $stmt->execute();
            }
            $msg = 'Product added.';
        }

        $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
        header('Location: products.php');
        exit;
    }
}

$categories = $conn->query('SELECT id, name FROM categories ORDER BY name');

$pageTitle = $isEdit ? 'Edit Product' : 'Add Product';
$active    = 'products';
require 'includes/header.php';
?>
<a class="back" href="products.php"><?= icon('back', 16) ?> Back to products</a>
<div class="page-head">
    <div>
        <h2><?= $isEdit ? 'Edit Product' : 'Add Product' ?></h2>
        <p><?= $isEdit ? 'Update the details of ' . e($product['name']) . '.' : 'Add a new item to the store inventory.' ?></p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="flash error">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<form class="form" method="post" enctype="multipart/form-data">
    <input type="hidden" name="id" value="<?= $id ?>">

    <!-- Product picture -->
    <div class="img-field">
        <div id="imgPreview"><?= product_thumb($product, 96) ?></div>
        <div>
            <label for="imageInput" style="margin-top:0">Product picture</label>
            <input type="file" name="image" id="imageInput" accept="image/jpeg,image/png,image/webp,image/gif">
            <div class="hint">JPG, PNG or WEBP, max 3 MB. A square photo looks best.</div>
            <?php if (!empty($product['image'])): ?>
                <label class="check" style="margin-top:8px; font-weight:500"><input type="checkbox" name="remove_image" style="width:auto;height:auto"> Remove current picture</label>
            <?php endif; ?>
        </div>
    </div>

    <label>Product name</label>
    <input type="text" name="name" value="<?= e($product['name']) ?>" required>

    <div class="row">
        <div>
            <label>Category</label>
            <select name="category_id" required>
                <option value="">-- choose --</option>
                <?php while ($c = $categories->fetch_assoc()): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)$product['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div>
            <label>Unit</label>
            <input type="text" name="unit" value="<?= e($product['unit']) ?>" placeholder="pc, pack, sachet, kilo" required>
        </div>
    </div>

    <div class="row">
        <div>
            <label>Cost price (₱)</label>
            <input type="number" step="0.01" min="0" name="cost_price" value="<?= e($product['cost_price']) ?>" required>
        </div>
        <div>
            <label>Selling price (₱)</label>
            <input type="number" step="0.01" min="0" name="selling_price" value="<?= e($product['selling_price']) ?>" required>
        </div>
    </div>

    <div class="row">
        <div>
            <label>Stock on hand</label>
            <input type="number" step="1" min="0" name="stock" value="<?= e($product['stock']) ?>" required>
        </div>
        <div>
            <label>Reorder level</label>
            <input type="number" step="1" min="0" name="reorder_level" value="<?= e($product['reorder_level']) ?>" required>
            <div class="hint">You'll be warned when stock drops to this number.</div>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Add product' ?></button>
        <a href="products.php" class="btn ghost">Cancel</a>
    </div>
</form>
<script>
// Show the chosen picture right away, before saving
document.getElementById('imageInput').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const img = document.createElement('img');
    img.className = 'thumb';
    img.style.width = img.style.height = '96px';
    img.src = URL.createObjectURL(file);
    const box = document.getElementById('imgPreview');
    box.innerHTML = '';
    box.appendChild(img);
});
</script>
<?php require 'includes/footer.php'; ?>
