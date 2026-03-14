<?php
/**
 * Mail configuration sample.
 * Copy this file to mail_config.php and fill in your SMTP details.
 * For Gmail: use App Password (not your normal password).
 * Create one at: https://myaccount.google.com/apppasswords (2-Step Verification must be ON).
 */
return [
    'smtp_host'     => 'smtp.gmail.com',
    'smtp_port'     => 465,
    'smtp_secure'   => 'ssl',
    'smtp_username' => 'your-gmail@gmail.com',
    'smtp_password' => 'your-16-char-app-password',
    'from_email'    => 'your-gmail@gmail.com',
    'from_name'     => 'LCRC eReview',
];
