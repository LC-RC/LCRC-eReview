<?php
/**
 * @deprecated Use admin_support_analytics?tab=lookup
 */
require_once 'auth.php';
requireRole('admin');
header('Location: admin_support_analytics?tab=lookup', true, 302);
exit;
