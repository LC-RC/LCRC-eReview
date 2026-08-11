<?php
/**
 * @deprecated Use admin_support_analytics?tab=backlog
 */
require_once 'auth.php';
requireAdminPage();
header('Location: admin_support_analytics?tab=backlog', true, 302);
exit;
