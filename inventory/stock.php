<?php
$page_title = 'Stock In / Out';
include 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id']) && isset($_POST['type'])) {
    $product_id = (int) $_POST['product_id'];
    $type = $_POST['type'] === 'in' || $_POST['type'] === 'out' || $_POST['type'] === 'adjust' ? $_POST['type'] : 'in';
    $qty = (int) $_POST['quantity'];
    $ref = mysqli_real_escape_string($conn, trim($_POST['reference'] ?? ''));
    $notes = mysqli_real_escape_string($conn, trim($_POST['notes'] ?? ''));
    if ($qty <= 0) {
        header('Location: stock.php?msg=' . urlencode('Quantity must be greater than 0.'));
        exit;
    }
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT quantity FROM products WHERE id = $product_id"));
    if (!$row) {
        header('Location: stock.php?msg=' . urlencode('Product not found.'));
        exit;
    }
    $current = (int) $row['quantity'];
    if ($type === 'out' && $qty > $current) {
        header('Location: stock.php?msg=' . urlencode('Not enough stock. Current: ' . $current));
        exit;
    }
    $new_qty = $type === 'in' ? $current + $qty : ($type === 'out' ? $current - $qty : $qty);
    if ($type === 'adjust') {
        $qty = $new_qty - $current;
        if ($qty == 0) {
            header('Location: stock.php?msg=' . urlencode('No change.'));
            exit;
        }
        $type = $qty > 0 ? 'in' : 'out';
        $qty = abs($qty);
    }
    mysqli_query($conn, "UPDATE products SET quantity = $new_qty WHERE id = $product_id");
    mysqli_query($conn, "INSERT INTO stock_log (product_id, type, quantity, reference, notes) VALUES ($product_id, '$type', $qty, '$ref', '$notes')");
    header('Location: stock.php?msg=' . urlencode('Stock updated successfully.'));
    exit;
}

include 'includes/header.php';
$products = mysqli_query($conn, "SELECT p.id, p.sku, p.name, p.quantity, p.unit, c.name AS cat_name FROM products p JOIN categories c ON p.category_id = c.id ORDER BY p.name");
?>
<h1 class="page-title"><i class="bi bi-arrow-left-right me-2"></i>Stock In / Out</h1>
<?php if (!empty($_GET['msg'])): ?><div class="alert alert-info"><?php echo htmlspecialchars($_GET['msg']); ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-6">
        <div class="card-inv">
            <div class="card-header">Record movement</div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">Select product...</option>
                            <?php while ($p = mysqli_fetch_assoc($products)): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['sku'] . ' - ' . $p['name'] . ' (' . $p['quantity'] . ' ' . $p['unit'] . ')'); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select" id="stockType">
                            <option value="in">Stock In</option>
                            <option value="out">Stock Out</option>
                            <option value="adjust">Adjust to specific quantity</option>
                        </select>
                    </div>
                    <div class="mb-3" id="qtyGroup">
                        <label class="form-label" id="qtyLabel">Quantity to add</label>
                        <input type="number" name="quantity" class="form-control" min="1" value="1" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Reference (e.g. PO#, receipt)</label>
                        <input type="text" name="reference" class="form-control" placeholder="Optional">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional"></textarea>
                    </div>
                    <button type="submit" class="btn btn-teal">Save</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-inv">
            <div class="card-header">Quick guide</div>
            <div class="card-body">
                <p><strong>Stock In</strong> – Dagdag ng supply (e.g. bagong delivery).</p>
                <p><strong>Stock Out</strong> – Bawas (e.g. na-issue sa employee, nagamit).</p>
                <p><strong>Adjust</strong> – Itakda ang bagong quantity (e.g. after physical count).</p>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('stockType').addEventListener('change', function() {
    var lab = document.getElementById('qtyLabel');
    var inp = document.querySelector('input[name="quantity"]');
    if (this.value === 'adjust') {
        lab.textContent = 'New total quantity';
        inp.min = 0;
    } else {
        lab.textContent = this.value === 'in' ? 'Quantity to add' : 'Quantity to take out';
        inp.min = 1;
    }
});
</script>
<?php include 'includes/footer.php'; ?>
