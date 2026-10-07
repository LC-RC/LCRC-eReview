<?php
/**
 * Schema ensure gate — student exam hot paths must never run DDL.
 * Enable only via deploy migrate CLI or explicit $GLOBALS['EREVIEW_ENSURE_SCHEMA']=true.
 */
function ereview_schema_ensure_enabled(): bool
{
    if (!empty($GLOBALS['EREVIEW_ENSURE_SCHEMA'])) {
        return true;
    }
    $env = getenv('EREVIEW_ENSURE_SCHEMA');
    if ($env === '1' || strtolower((string)$env) === 'true') {
        return true;
    }
    if (defined('EREVIEW_SCHEMA_MIGRATE') && EREVIEW_SCHEMA_MIGRATE) {
        return true;
    }

    return false;
}
