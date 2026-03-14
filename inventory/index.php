<?php
$page_title = 'Dashboard';
include 'config.php';
include 'includes/header.php';

// Stats
$total_products = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM products"))['c'];
$total_categories = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM categories"))['c'];
$low_stock = mysqli_query($conn, "SELECT COUNT(*) AS c FROM products WHERE quantity <= reorder_level AND reorder_level > 0");
$low_count = (int) mysqli_fetch_assoc($low_stock)['c'];
$total_value = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(quantity * cost_price), 0) AS v FROM products"));
$inv_value = number_format($total_value['v'], 2);
?>
<h1 class="page-title"><i class="bi bi-grid-1x2 me-2"></i>Dashboard</h1>

<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="stat-card primary">
            <p>Total Products</p>
            <h3><?php echo $total_products; ?></h3>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card info">
            <p>Categories</p>
            <h3><?php echo $total_categories; ?></h3>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card <?php echo $low_count > 0 ? 'warning' : 'success'; ?>">
            <p>Low Stock Items</p>
            <h3><?php echo $low_count; ?></h3>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card success">
            <p>Inventory Value</p>
            <h3>₱<?php echo $inv_value; ?></h3>
        </div>
    </div>
</div>

<?php if ($low_count > 0): ?>
<div class="card-inv mb-4">
    <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alert</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-inv align-middle">
                <thead>
                    <tr><th>SKU</th><th>Product</th><th>Category</th><th>Current</th><th>Reorder At</th></tr>
                </thead>
                <tbody>
                <?php
                $low = mysqli_query($conn, "SELECT p.id, p.sku, p.name, p.quantity, p.reorder_level, c.name AS cat_name FROM products p JOIN categories c ON p.category_id = c.id WHERE p.quantity <= p.reorder_level AND p.reorder_level > 0 ORDER BY p.quantity ASC LIMIT 10");
                while ($row = mysqli_fetch_assoc($low)):
                ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($row['sku']); ?></code></td>
                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                        <td><?php echo htmlspecialchars($row['cat_name']); ?></td>
                        <td><span class="badge badge-low"><?php echo (int)$row['quantity']; ?></span></td>
                        <td><?php echo (int)$row['reorder_level']; ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card-inv">
    <div class="card-header">Recent Stock Movements</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-inv align-middle">
                <thead>
                    <tr><th>Date</th><th>Product</th><th>Type</th><th>Qty</th><th>Reference / Notes</th></tr>
                </thead>
                <tbody>
                <?php
                $recent = mysqli_query($conn, "SELECT l.id, l.type, l.quantity, l.reference, l.notes, l.created_at, p.name AS product_name FROM stock_log l JOIN products p ON l.product_id = p.id ORDER BY l.created_at DESC LIMIT 15");
                if (mysqli_num_rows($recent) === 0) echo '<tr><td colspan="5" class="text-muted text-center py-4">No movements yet.</td></tr>';
                while ($row = mysqli_fetch_assoc($recent)):
                    $typeBadge = $row['type'] === 'in' ? 'success' : ($row['type'] === 'out' ? 'danger' : 'secondary');
                ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                        <td><span class="badge bg-<?php echo $typeBadge; ?>"><?php echo strtoupper($row['type']); ?></span></td>
                        <td><?php echo (int)$row['quantity']; ?></td>
                        <td><?php echo htmlspecialchars($row['reference'] ?: $row['notes'] ?: '-'); ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
