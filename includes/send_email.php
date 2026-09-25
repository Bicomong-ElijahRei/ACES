<?php
/**
 * ACES Email Helper
 * Uses PHPMailer to send emails via SMTP.
 *
 * Usage:
 *   require_once __DIR__ . '/../includes/send_email.php';
 *   sendEmail('student@example.com', 'Subject', '<p>Hello</p>');
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';

function sendEmail(string $to, string $subject, string $body): array
{
    $config = require __DIR__ . '/../config/email.php';

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->SMTPDebug = SMTP::DEBUG_OFF;          // Change to DEBUG_SERVER for debugging
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];
        $mail->Port       = $config['port'];

        if ($config['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        // Sender
        $mail->setFrom($config['from_address'], $config['from_name']);

        // Recipient
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return ['success' => true, 'message' => 'Email sent'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $mail->ErrorInfo];
    }
}