<?php
$page_title = 'Products';
include 'config.php';

if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    mysqli_query($conn, "DELETE FROM stock_log WHERE product_id = $id");
    mysqli_query($conn, "DELETE FROM products WHERE id = $id");
    header('Location: products.php?msg=' . urlencode('Product deleted.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sku'])) {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $category_id = (int) $_POST['category_id'];
    $sku = mysqli_real_escape_string($conn, trim($_POST['sku']));
    $name = mysqli_real_escape_string($conn, trim($_POST['name']));
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $unit = mysqli_real_escape_string($conn, $_POST['unit'] ?? 'pcs');
    $cost_price = (float) ($_POST['cost_price'] ?? 0);
    $selling_price = (float) ($_POST['selling_price'] ?? 0);
    $quantity = isset($_POST['quantity']) ? (int) $_POST['quantity'] : 0;
    $reorder_level = (int) ($_POST['reorder_level'] ?? 5);
    if ($id) {
        mysqli_query($conn, "UPDATE products SET category_id=$category_id, sku='$sku', name='$name', description='$description', unit='$unit', cost_price=$cost_price, selling_price=$selling_price, reorder_level=$reorder_level WHERE id=$id");
        $msg = 'Product updated.';
    } else {
        mysqli_query($conn, "INSERT INTO products (category_id, sku, name, description, unit, cost_price, selling_price, quantity, reorder_level) VALUES ($category_id,'$sku','$name','$description','$unit',$cost_price,$selling_price,$quantity,$reorder_level)");
        $msg = 'Product added.';
    }
    header('Location: products.php?msg=' . urlencode($msg));
    exit;
}

include 'includes/header.php';
$edit = null;
if (isset($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $edit = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM products WHERE id = $edit_id"));
}
$categories = mysqli_query($conn, "SELECT id, name FROM categories ORDER BY name");
$search = isset($_GET['q']) ? mysqli_real_escape_string($conn, $_GET['q']) : '';
$cat_filter = isset($_GET['cat']) ? (int) $_GET['cat'] : 0;
?>
<h1 class="page-title"><i class="bi bi-boxes me-2"></i>Products</h1>
<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success"><?php echo htmlspecialchars($_GET['msg']); ?></div><?php endif; ?>

<div class="card-inv mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="q" class="form-control" placeholder="Search by name or SKU..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-3">
                <select name="cat" class="form-select">
                    <option value="0">All categories</option>
                    <?php mysqli_data_seek($categories, 0); while ($c = mysqli_fetch_assoc($categories)): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $cat_filter == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2"><button type="submit" class="btn btn-teal">Search</button></div>
        </form>
    </div>
</div>

<div class="row">
    <div class="col-lg-4">
        <div class="card-inv mb-4">
            <div class="card-header"><?php echo $edit ? 'Edit Product' : 'Add Product'; ?></div>
            <div class="card-body">
                <form method="post">
                    <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo $edit['id']; ?>"><?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Category</label>
                        <select name="category_id" class="form-select" required>
                            <?php mysqli_data_seek($categories, 0); while ($c = mysqli_fetch_assoc($categories)): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($edit && $edit['category_id'] == $c['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">SKU</label>
                        <input type="text" name="sku" class="form-control" required value="<?php echo $edit ? htmlspecialchars($edit['sku']) : ''; ?>" placeholder="e.g. PEN-001">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required value="<?php echo $edit ? htmlspecialchars($edit['name']) : ''; ?>" placeholder="e.g. Ballpen Blue">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Unit</label>
                        <select name="unit" class="form-select">
                            <?php
                            $units = ['pcs' => 'pcs', 'box' => 'box', 'pack' => 'pack', 'ream' => 'ream', 'bottle' => 'bottle', 'roll' => 'roll'];
                            foreach ($units as $u => $l):
                                $sel = ($edit && $edit['unit'] === $u) ? 'selected' : '';
                            ?>
                                <option value="<?php echo $u; ?>" <?php echo $sel; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6">
                            <label class="form-label">Cost (₱)</label>
                            <input type="number" name="cost_price" step="0.01" min="0" class="form-control" value="<?php echo $edit ? $edit['cost_price'] : '0'; ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Selling (₱)</label>
                            <input type="number" name="selling_price" step="0.01" min="0" class="form-control" value="<?php echo $edit ? $edit['selling_price'] : '0'; ?>">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Reorder level</label>
                        <input type="number" name="reorder_level" min="0" class="form-control" value="<?php echo $edit ? $edit['reorder_level'] : '5'; ?>">
                    </div>
                    <?php if (!$edit): ?>
                    <div class="mb-2">
                        <label class="form-label">Initial quantity</label>
                        <input type="number" name="quantity" min="0" class="form-control" value="0">
                    </div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?php echo $edit ? htmlspecialchars($edit['description']) : ''; ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-teal"><?php echo $edit ? 'Update' : 'Add Product'; ?></button>
                    <?php if ($edit): ?><a href="products.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card-inv">
            <div class="card-header">All Products</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-inv align-middle">
                        <thead>
                            <tr><th>SKU</th><th>Name</th><th>Category</th><th>Qty</th><th>Unit</th><th>Reorder</th><th width="100">Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php
                        $where = "1=1";
                        if ($search) $where .= " AND (p.name LIKE '%$search%' OR p.sku LIKE '%$search%')";
                        if ($cat_filter) $where .= " AND p.category_id = $cat_filter";
                        $r = mysqli_query($conn, "SELECT p.*, c.name AS cat_name FROM products p JOIN categories c ON p.category_id = c.id WHERE $where ORDER BY p.name");
                        while ($row = mysqli_fetch_assoc($r)):
                            $low = $row['reorder_level'] > 0 && $row['quantity'] <= $row['reorder_level'];
                        ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($row['sku']); ?></code></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['cat_name']); ?></td>
                                <td><?php if ($low): ?><span class="badge badge-low"><?php echo (int)$row['quantity']; ?></span><?php else: ?><?php echo (int)$row['quantity']; ?><?php endif; ?></td>
                                <td><?php echo htmlspecialchars($row['unit']); ?></td>
                                <td><?php echo (int)$row['reorder_level']; ?></td>
                                <td>
                                    <a href="products.php?edit=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                    <a href="products.php?delete=1&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this product?');"><i class="bi bi-trash"></i></a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
