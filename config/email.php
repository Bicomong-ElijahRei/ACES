<?php
/**
 * ============================================================
 * ACES System — Email / SMTP Configuration
 * ============================================================
 * Reads SMTP credentials from .env via config/env.php.
 *
 * Returns an array consumed by includes/send_email.php:
 *   $config = require __DIR__ . '/../config/email.php';
 *   $mail->Username = $config['username'];
 *   $mail->Password = $config['password'];
 *
 * To configure Gmail:
 *   1. Enable 2-Factor Authentication
 *   2. Generate an App Password at
 *      https://myaccount.google.com/apppasswords
 *   3. Put it in .env as MAIL_PASSWORD
 * ============================================================
 */

require_once __DIR__ . '/env.php';

return [
    'host'         => aces_env('MAIL_HOST', 'smtp.gmail.com'),
    'username'     => aces_env('MAIL_USERNAME', ''),
    'password'     => aces_env('MAIL_PASSWORD', ''),
    'port'         => (int) aces_env('MAIL_PORT', 587),
    'encryption'   => aces_env('MAIL_ENCRYPTION', 'tls'),
    'from_address' => aces_env('MAIL_FROM_ADDRESS', aces_env('MAIL_USERNAME', '')),
    'from_name'    => aces_env('MAIL_FROM_NAME', 'ACES Unit - KLD'),
];