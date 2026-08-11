<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_acl.php';
admin_acl_ensure_schema($conn);
echo admin_acl_table_ready($conn) ? "acl_ok\n" : "acl_fail\n";
echo admin_acl_log_table_ready($conn) ? "log_ok\n" : "log_fail\n";
