<?php
/**
 * One-time setup: create config files from samples if they don't exist.
 * Run after cloning the repo (e.g. php setup_config.php or open in browser once).
 * Safe: does not overwrite existing config files.
 */
$configDir = __DIR__ . '/config';
$pairs = [
    'google_oauth_config.sample.php' => 'google_oauth_config.php',
    'mail_config.sample.php'         => 'mail_config.php',
];

$created = [];
$skipped = [];
$errors = [];

foreach ($pairs as $sample => $target) {
    $src = $configDir . '/' . $sample;
    $dst = $configDir . '/' . $target;
    if (!file_exists($src)) {
        $errors[] = "Sample missing: $sample";
        continue;
    }
    if (file_exists($dst)) {
        $skipped[] = $target . ' (already exists)';
        continue;
    }
    if (@copy($src, $dst)) {
        $created[] = $target;
    } else {
        $errors[] = "Could not create $target (check permissions)";
    }
}

$isCli = (php_sapi_name() === 'cli');
if ($isCli) {
    if (!empty($created)) {
        echo "Created: " . implode(', ', $created) . "\n";
    }
    if (!empty($skipped)) {
        echo "Skipped: " . implode(', ', $skipped) . "\n";
    }
    if (!empty($errors)) {
        echo "Errors: " . implode('; ', $errors) . "\n";
    }
    if (empty($created) && empty($errors)) {
        echo "Config files already exist. Edit config/google_oauth_config.php and config/mail_config.php with your credentials.\n";
    } elseif (!empty($created)) {
        echo "Next: Edit config/google_oauth_config.php (Google Client ID/Secret) and config/mail_config.php (SMTP) with your credentials.\n";
    }
} else {
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Config setup</title></head><body style="font-family:sans-serif;max-width:560px;margin:2rem auto;padding:1rem;">';
    echo '<h1>Config setup</h1>';
    if (!empty($created)) {
        echo '<p style="color:green;">Created: ' . implode(', ', $created) . '</p>';
    }
    if (!empty($skipped)) {
        echo '<p style="color:#666;">Skipped: ' . implode(', ', $skipped) . '</p>';
    }
    if (!empty($errors)) {
        echo '<p style="color:red;">' . implode('<br>', $errors) . '</p>';
    }
    if (!empty($created)) {
        echo '<p><strong>Next:</strong> Edit <code>config/google_oauth_config.php</code> (Google OAuth Client ID &amp; Secret) and <code>config/mail_config.php</code> (Gmail SMTP) with your credentials. See <code>config/README_GOOGLE_OAUTH.md</code> and <code>config/README_MAIL.md</code>.</p>';
    } elseif (empty($errors)) {
        echo '<p>Config files already exist. Add your credentials to enable Google Sign-In and email (forgot password, registration verification).</p>';
    }
    echo '<p><a href="login.php">Go to login</a></p></body></html>';
}
