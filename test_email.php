<?php
require_once 'includes/send_email.php';

$result = sendEmail(
    'erzbicomong@kld.edu.ph',  // <-- CHANGE THIS
    'ACES Test Email',
    '<h2>Hello</h2><p>If you see this, SMTP works.</p>'
);

echo $result['message'];