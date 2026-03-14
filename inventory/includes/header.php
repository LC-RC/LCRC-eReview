<?php
if (!isset($conn)) include __DIR__ . '/../config.php';
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Office Supplies Inventory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --inv-primary: #0f766e;
            --inv-primary-dark: #0d9488;
            --inv-sidebar: #134e4a;
            --inv-bg: #f0fdfa;
            --inv-card: #ffffff;
            --inv-text: #1e293b;
            --inv-muted: #64748b;
        }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--inv-bg); color: var(--inv-text); }
        #sidebar {
            position: fixed; top: 0; left: 0; width: 260px; height: 100vh;
            background: linear-gradient(180deg, var(--inv-sidebar) 0%, #0f766e 100%);
            z-index: 1000; overflow-y: auto;
        }
        .sidebar-brand {
            padding: 24px; border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-brand h1 { color: #fff; font-size: 1.1rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px; }
        .sidebar-brand h1 i { font-size: 1.5rem; opacity: 0.95; }
        .sidebar-nav { padding: 16px 0; }
        .sidebar-nav .nav-link {
            color: rgba(255,255,255,0.85); padding: 12px 24px; display: flex; align-items: center; gap: 12px;
            text-decoration: none; border-left: 3px solid transparent; transition: all 0.2s;
        }
        .sidebar-nav .nav-link:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .sidebar-nav .nav-link.active { background: rgba(255,255,255,0.15); color: #fff; border-left-color: #fff; font-weight: 600; }
        .sidebar-nav .nav-link i { font-size: 1.2rem; width: 24px; text-align: center; }
        #main { margin-left: 260px; padding: 24px; min-height: 100vh; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: var(--inv-sidebar); margin-bottom: 24px; }
        .card-inv { border: none; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); background: var(--inv-card); }
        .card-inv .card-header { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 16px 20px; font-weight: 600; color: var(--inv-text); border-radius: 12px 12px 0 0; }
        .btn-teal { background: var(--inv-primary); color: #fff; border: none; }
        .btn-teal:hover { background: var(--inv-primary-dark); color: #fff; }
        .stat-card { border-radius: 12px; padding: 20px; color: #fff; }
        .stat-card.primary { background: linear-gradient(135deg, #0f766e, #0d9488); }
        .stat-card.warning { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .stat-card.info { background: linear-gradient(135deg, #0369a1, #0ea5e9); }
        .stat-card.success { background: linear-gradient(135deg, #15803d, #22c55e); }
        .stat-card h3 { font-size: 1.75rem; font-weight: 700; margin: 0 0 4px 0; }
        .stat-card p { margin: 0; opacity: 0.9; font-size: 0.9rem; }
        .badge-low { background: #fef3c7; color: #b45309; }
        .table-inv { margin-bottom: 0; }
        .table-inv thead th { font-weight: 600; color: var(--inv-muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; }
        @media (max-width: 992px) { #sidebar { transform: translateX(-100%); } #main { margin-left: 0; } }
    </style>
</head>
<body>
<aside id="sidebar">
    <div class="sidebar-brand">
        <h1><i class="bi bi-box-seam"></i> Office Supplies</h1>
    </div>
    <nav class="sidebar-nav">
        <a class="nav-link <?php echo $current_page === 'index' || $current_page === 'dashboard' ? 'active' : ''; ?>" href="index.php"><i class="bi bi-grid-1x2"></i> Dashboard</a>
        <a class="nav-link <?php echo $current_page === 'products' ? 'active' : ''; ?>" href="products.php"><i class="bi bi-boxes"></i> Products</a>
        <a class="nav-link <?php echo $current_page === 'categories' ? 'active' : ''; ?>" href="categories.php"><i class="bi bi-tags"></i> Categories</a>
        <a class="nav-link <?php echo $current_page === 'stock' ? 'active' : ''; ?>" href="stock.php"><i class="bi bi-arrow-left-right"></i> Stock In / Out</a>
        <a class="nav-link <?php echo $current_page === 'movements' ? 'active' : ''; ?>" href="movements.php"><i class="bi bi-clock-history"></i> Movement Log</a>
    </nav>
</aside>
<main id="main">
