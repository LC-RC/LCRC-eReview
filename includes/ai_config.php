<?php
/**
 * Optional OpenAI key for professor exam AI assist.
 * Copy to ai_config.local.php and set OPENAI_API_KEY, or set env var.
 */
if (!defined('OPENAI_API_KEY')) {
    $local = __DIR__ . '/ai_config.local.php';
    if (is_readable($local)) {
        require_once $local;
    }
    if (!defined('OPENAI_API_KEY')) {
        define('OPENAI_API_KEY', (string)(getenv('OPENAI_API_KEY') ?: ''));
    }
}
