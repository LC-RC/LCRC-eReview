<?php
$page_title = 'Categories';
include 'config.php';

// Delete
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    $chk = mysqli_query($conn, "SELECT COUNT(*) AS c FROM products WHERE category_id = $id");
    if (mysqli_fetch_assoc($chk)['c'] > 0) {
        $msg = 'Cannot delete: category has products.';
    } else {
        mysqli_query($conn, "DELETE FROM categories WHERE id = $id");
        $msg = 'Category deleted.';
    }
    header('Location: categories.php?msg=' . urlencode($msg));
    exit;
}

// Save (add/edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    $name = mysqli_real_escape_string($conn, trim($_POST['name']));
    $desc = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id) {
        mysqli_query($conn, "UPDATE categories SET name='$name', description='$desc' WHERE id=$id");
        $msg = 'Category updated.';
    } else {
        mysqli_query($conn, "INSERT INTO categories (name, description) VALUES ('$name','$desc')");
        $msg = 'Category added.';
    }
    header('Location: categories.php?msg=' . urlencode($msg));
    exit;
}

include 'includes/header.php';
$edit = null;
if (isset($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $edit = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM categories WHERE id = $edit_id"));
}
?>
<h1 class="page-title"><i class="bi bi-tags me-2"></i>Categories</h1>
<?php if (!empty($_GET['msg'])): ?><div class="alert alert-success"><?php echo htmlspecialchars($_GET['msg']); ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-4">
        <div class="card-inv mb-4">
            <div class="card-header"><?php echo $edit ? 'Edit Category' : 'Add Category'; ?></div>
            <div class="card-body">
                <form method="post">
                    <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo $edit['id']; ?>"><?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required value="<?php echo $edit ? htmlspecialchars($edit['name']) : ''; ?>" placeholder="e.g. Writing Instruments">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Optional"><?php echo $edit ? htmlspecialchars($edit['description']) : ''; ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-teal"><?php echo $edit ? 'Update' : 'Add Category'; ?></button>
                    <?php if ($edit): ?><a href="categories.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card-inv">
            <div class="card-header">All Categories</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-inv align-middle">
                        <thead>
                            <tr><th>Name</th><th>Description</th><th>Products</th><th width="120">Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php
                        $r = mysqli_query($conn, "SELECT c.*, COUNT(p.id) AS product_count FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id ORDER BY c.name");
                        while ($row = mysqli_fetch_assoc($r)):
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                <td class="text-muted"><?php echo htmlspecialchars($row['description'] ?: '-'); ?></td>
                                <td><?php echo (int)$row['product_count']; ?></td>
                                <td>
                                    <a href="categories.php?edit=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                    <?php if ($row['product_count'] == 0): ?><a href="categories.php?delete=1&id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this category?');"><i class="bi bi-trash"></i></a><?php endif; ?>
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
