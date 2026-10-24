<?php
require 'config/db.php';
require_login();
if (!$CURRENT_USER->canBackup()) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Only the store owner (admin) can make backups.'];
    header('Location: dashboard.php');
    exit;
}

// Tables in an order that respects their links (parents first)
$tables = ['users', 'categories', 'products', 'stock_logs', 'customers', 'credit_transactions'];

// ---------- Download: build a .sql file of the whole database ----------
if (isset($_GET['download'])) {
    $file = 'jennyding-backup-' . date('Y-m-d-His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');

    echo "-- Jenny & Ding Inventory Management System - database backup\n";
    echo "-- Made on " . date('F j, Y g:i A') . " by " . $_SESSION['full_name'] . "\n";
    echo "-- To restore: phpMyAdmin > select the database > Import > choose this file.\n";
    echo "-- WARNING: restoring replaces all current data with the data in this file.\n\n";
    echo "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    // Drop child tables first so a restore never trips on links
    foreach (array_reverse($tables) as $t) {
        echo "DROP TABLE IF EXISTS `$t`;\n";
    }
    echo "\n";

    foreach ($tables as $t) {
        $create = $conn->query("SHOW CREATE TABLE `$t`")->fetch_assoc();
        echo "-- Table: $t\n" . $create['Create Table'] . ";\n\n";

        $result = $conn->query("SELECT * FROM `$t`");
        $batch = [];
        while ($row = $result->fetch_assoc()) {
            $values = [];
            foreach ($row as $v) {
                $values[] = $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
            }
            $batch[] = '(' . implode(', ', $values) . ')';
            if (count($batch) === 100) {
                echo "INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }
        if ($batch) {
            echo "INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n";
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit;
}

// ---------- Page: what will be backed up ----------
$counts = [];
foreach ($tables as $t) {
    $counts[$t] = $conn->query("SELECT COUNT(*) AS c FROM `$t`")->fetch_assoc()['c'];
}
$labels = [
    'users' => 'User accounts', 'categories' => 'Categories', 'products' => 'Products',
    'stock_logs' => 'Stock history records', 'customers' => 'Credit customers', 'credit_transactions' => 'Utang and payment records',
];

$pageTitle = 'Backup';
$active    = 'backup';
require 'includes/header.php';
?>
<div class="page-head">
    <div>
        <h2>Backup</h2>
        <p>Download a full copy of the store’s data. Keep it on a flash drive or in Google Drive.</p>
    </div>
    <a class="btn" href="backup.php?download=1"><?= icon('arrow-in', 16) ?> Download backup now</a>
</div>

<div class="grid-2">
    <section class="panel">
        <div class="panel-head"><div><h3>What is included</h3><p>Everything in the database</p></div></div>
        <div class="table-wrap">
            <table>
                <tr><th>Data</th><th class="num">Records</th></tr>
                <?php foreach ($counts as $t => $n): ?>
                    <tr><td><?= e($labels[$t]) ?></td><td class="num strong"><?= number_format($n) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><div><h3>How to restore</h3><p>Only if the data is lost or damaged</p></div></div>
        <div class="panel-body">
            <ol style="margin:0; padding-left:20px; line-height:1.9">
                <li>Open <b>phpMyAdmin</b> and click the store’s database.</li>
                <li>Click the <b>Import</b> tab.</li>
                <li>Choose the backup <b>.sql</b> file and click <b>Import</b>.</li>
            </ol>
            <div class="flash error" style="margin:16px 0 0">Restoring replaces ALL current data with the data in the backup file.</div>
            <p class="muted" style="margin:16px 0 0">Product pictures are files, not database records. To back them up too, copy the folder <b>uploads/products</b>.</p>
            <p class="muted" style="margin:8px 0 0">Tip: download a backup at least once a week.</p>
        </div>
    </section>
</div>
<?php require 'includes/footer.php'; ?>
