<?php
/**
 * @deprecated Use admin_support_analytics.php?tab=kb
 */
require_once 'auth.php';
requireRole('admin');
$edit = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$q = ['tab' => 'kb'];
if ($edit !== null) {
    $q['edit'] = $edit;
}
header('Location: admin_support_analytics.php?' . http_build_query($q), true, 302);
exit;
