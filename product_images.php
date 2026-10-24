<?php
require 'config/db.php';
require_login();

if (!$CURRENT_USER->canDeleteRecords()) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Only the admin can upload product pictures here.'];
    header('Location: products.php');
    exit;
}

// Makes names easy to compare: "Lucky_Me!-Pancit Canton.JPG" -> "lucky me pancit canton"
function normalize_name($text) {
    $text = strtolower($text);
    $text = str_replace('&', ' and ', $text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

$all = $conn->query('SELECT id, name, image FROM products ORDER BY name')->fetch_all(MYSQLI_ASSOC);

// ----- "Download name list" button: a text file of product names to use as picture file names -----
if (isset($_GET['names'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="product-picture-names.txt"');
    echo "Name each picture file like the product below, e.g. Piattos.jpg\r\n\r\n";
    foreach ($all as $p) echo $p['name'] . "\r\n";
    exit;
}

// ----- Bulk upload: match each picture to a product by its file name -----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $results = [];
    $files = $_FILES['pics'] ?? null;
    $count = $files ? count($files['name']) : 0;

    for ($i = 0; $i < $count; $i++) {
        $original = $files['name'][$i];
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;

        // Find the product whose name is closest to the file name
        $fileKey = normalize_name(pathinfo($original, PATHINFO_FILENAME));
        $best = null;
        $bestScore = 0;
        foreach ($all as $p) {
            $prodKey = normalize_name($p['name']);
            if ($prodKey === $fileKey) { $best = $p; $bestScore = 100; break; }
            similar_text($fileKey, $prodKey, $score);
            if ($score > $bestScore) { $best = $p; $bestScore = $score; }
        }

        if (!$best || $bestScore < 75) {
            $results[] = ['file' => $original, 'ok' => false, 'msg' => 'No matching product. Rename the file to the product name.'];
            continue;
        }

        $one = ['name' => $original, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i], 'size' => $files['size'][$i]];
        [$saved, $err] = save_product_image($one);
        if ($err) {
            $results[] = ['file' => $original, 'ok' => false, 'msg' => $err];
            continue;
        }

        $stmt = $conn->prepare('UPDATE products SET image = ? WHERE id = ?');
        $stmt->bind_param('si', $saved, $best['id']);
        $stmt->execute();
        delete_product_image($best['image']);

        // Keep our list up to date in case two files match the same product
        foreach ($all as &$p) { if ($p['id'] === $best['id']) $p['image'] = $saved; }
        unset($p);

        $results[] = ['file' => $original, 'ok' => true, 'msg' => $best['name']];
    }

    $_SESSION['upload_results'] = $results;
    header('Location: product_images.php');
    exit;
}

$results = $_SESSION['upload_results'] ?? null;
unset($_SESSION['upload_results']);

$show = $_GET['show'] ?? 'missing';
$withPic = array_filter($all, fn($p) => !empty($p['image']));
$list = $show === 'all' ? $all : array_filter($all, fn($p) => empty($p['image']));
$maxFiles = (int)ini_get('max_file_uploads');

$pageTitle = 'Product Pictures';
$active    = 'products';
require 'includes/header.php';
?>
<a class="back" href="products.php"><?= icon('back', 16) ?> Back to products</a>
<div class="page-head">
    <div>
        <h2>Product Pictures</h2>
        <p><?= count($withPic) ?> of <?= count($all) ?> products have a picture.</p>
    </div>
    <a class="btn ghost" href="product_images.php?names=1"><?= icon('arrow-in', 16) ?> Download name list</a>
</div>

<?php if ($results !== null): ?>
    <section class="panel" style="margin-bottom:20px">
        <div class="panel-head">
            <div>
                <h3>Upload results</h3>
                <p><?= count(array_filter($results, fn($r) => $r['ok'])) ?> saved, <?= count(array_filter($results, fn($r) => !$r['ok'])) ?> not saved</p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <tr><th>File</th><th>Result</th></tr>
                <?php if (!$results): ?><tr><td colspan="2" class="empty">No files were received.</td></tr><?php endif; ?>
                <?php foreach ($results as $r): ?>
                    <tr>
                        <td class="strong"><?= e($r['file']) ?></td>
                        <td><span class="badge <?= $r['ok'] ? 'ok' : 'low' ?>"><?= $r['ok'] ? '✓ Saved to' : '✕' ?></span> <?= e($r['msg']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="panel" style="margin-bottom:24px">
    <div class="panel-head">
        <div>
            <h3>Upload many pictures at once</h3>
            <p>Name each file like the product, e.g. <b>Piattos.jpg</b> or <b>Lucky Me Pancit Canton Calamansi.png</b>. The system matches them automatically.</p>
        </div>
    </div>
    <div class="panel-body">
        <form method="post" enctype="multipart/form-data" id="bulkForm">
            <label class="dropzone" id="drop">
                <?= icon('upload', 34) ?>
                <b>Click to choose pictures, or drag them here</b>
                <span class="muted">JPG, PNG or WEBP · max 3 MB each · up to <?= $maxFiles ?> files at a time</span>
                <input type="file" name="pics[]" id="pics" multiple accept="image/jpeg,image/png,image/webp,image/gif">
            </label>
            <p class="muted" id="chosen" style="margin:10px 0 0"></p>
            <div class="form-actions" style="margin-top:14px">
                <button class="btn" type="submit"><?= icon('upload', 16) ?> Upload pictures</button>
            </div>
        </form>
    </div>
</section>

<div class="page-head" style="margin-bottom:14px">
    <h3><?= $show === 'all' ? 'All products' : 'Products without a picture' ?> (<?= count($list) ?>)</h3>
    <div style="display:flex; gap:8px">
        <a class="btn small <?= $show === 'all' ? 'ghost' : '' ?>" href="product_images.php">Missing</a>
        <a class="btn small <?= $show === 'all' ? '' : 'ghost' ?>" href="product_images.php?show=all">All</a>
    </div>
</div>

<?php if (!$list): ?>
    <div class="panel"><p class="empty">🎉 Every product has a picture.</p></div>
<?php else: ?>
    <div class="pic-grid">
        <?php foreach ($list as $p): ?>
            <div class="pic-card">
                <?= product_thumb($p, 110) ?>
                <div class="name"><?= e($p['name']) ?></div>
                <a href="product_form.php?id=<?= $p['id'] ?>"><?= empty($p['image']) ? 'Add picture' : 'Change' ?></a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
// Drag & drop + show how many files were chosen
(function () {
    const drop = document.getElementById('drop');
    const input = document.getElementById('pics');
    const chosen = document.getElementById('chosen');
    const max = <?= $maxFiles ?>;
    function show() {
        const n = input.files.length;
        chosen.textContent = n ? n + ' picture(s) selected' + (n > max ? ' — only the first ' + max + ' will upload. Upload the rest in another batch.' : '') : '';
    }
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('drag'); }));
    drop.addEventListener('drop', e => { input.files = e.dataTransfer.files; show(); });
})();
</script>
<?php require 'includes/footer.php'; ?>
