<?php
$page_title = 'Movement Log';
include 'config.php';
include 'includes/header.php';

$page = max(1, (int)($_GET['p'] ?? 1));
$per = 20;
$offset = ($page - 1) * $per;
$where = '1=1';
if (!empty($_GET['product_id'])) $where .= ' AND l.product_id = ' . (int)$_GET['product_id'];
if (!empty($_GET['type'])) $where .= " AND l.type = '" . mysqli_real_escape_string($conn, $_GET['type']) . "'";

$total = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM stock_log l WHERE $where"))['c'];
$r = mysqli_query($conn, "SELECT l.*, p.sku, p.name AS product_name FROM stock_log l JOIN products p ON l.product_id = p.id WHERE $where ORDER BY l.created_at DESC LIMIT $per OFFSET $offset");
$products_dd = mysqli_query($conn, "SELECT id, sku, name FROM products ORDER BY name");
?>
<h1 class="page-title"><i class="bi bi-clock-history me-2"></i>Movement Log</h1>

<div class="card-inv mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4">
                <select name="product_id" class="form-select">
                    <option value="">All products</option>
                    <?php while ($p = mysqli_fetch_assoc($products_dd)): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo (isset($_GET['product_id']) && $_GET['product_id'] == $p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['sku'] . ' - ' . $p['name']); ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="type" class="form-select">
                    <option value="">All types</option>
                    <option value="in" <?php echo (isset($_GET['type']) && $_GET['type'] === 'in') ? 'selected' : ''; ?>>In</option>
                    <option value="out" <?php echo (isset($_GET['type']) && $_GET['type'] === 'out') ? 'selected' : ''; ?>>Out</option>
                    <option value="adjust" <?php echo (isset($_GET['type']) && $_GET['type'] === 'adjust') ? 'selected' : ''; ?>>Adjust</option>
                </select>
            </div>
            <div class="col-md-2"><button type="submit" class="btn btn-teal">Filter</button></div>
        </form>
    </div>
</div>

<div class="card-inv">
    <div class="card-header">History (<?php echo $total; ?> records)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-inv align-middle">
                <thead>
                    <tr><th>Date & time</th><th>Product</th><th>Type</th><th>Qty</th><th>Reference</th><th>Notes</th></tr>
                </thead>
                <tbody>
                <?php
                if (mysqli_num_rows($r) === 0) echo '<tr><td colspan="6" class="text-muted text-center py-4">No records.</td></tr>';
                while ($row = mysqli_fetch_assoc($r)):
                    $typeBadge = $row['type'] === 'in' ? 'success' : ($row['type'] === 'out' ? 'danger' : 'secondary');
                ?>
                    <tr>
                        <td><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
                        <td><code><?php echo htmlspecialchars($row['sku']); ?></code> <?php echo htmlspecialchars($row['product_name']); ?></td>
                        <td><span class="badge bg-<?php echo $typeBadge; ?>"><?php echo strtoupper($row['type']); ?></span></td>
                        <td><?php echo (int)$row['quantity']; ?></td>
                        <td><?php echo htmlspecialchars($row['reference'] ?: '-'); ?></td>
                        <td><?php echo htmlspecialchars($row['notes'] ?: '-'); ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total > $per): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="text-muted">Page <?php echo $page; ?> of <?php echo ceil($total / $per); ?></span>
            <nav>
                <?php if ($page > 1): ?><a href="?<?php echo http_build_query(array_merge($_GET, ['p' => $page - 1])); ?>" class="btn btn-sm btn-outline-secondary">Previous</a><?php endif; ?>
                <?php if ($page < ceil($total / $per)): ?><a href="?<?php echo http_build_query(array_merge($_GET, ['p' => $page + 1])); ?>" class="btn btn-sm btn-outline-secondary">Next</a><?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include 'includes/footer.php'; ?>
