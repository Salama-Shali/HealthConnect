<?php
// Africa's Talking SMS configuration (sandbox).
// Real credentials go in config/sms.local.php (ignored by Git), never in this file.

$smsConfig = [
    'username'   => 'sandbox',
    'api_key'    => 'atsk_9e236bee97bd8c58585a536ce0f3eb90539ae26e05e4b5b6ede49879c1617faa61d23972',
    'sender_id'  => '',        // leave empty in the sandbox
    'endpoint'   => 'https://api.sandbox.africastalking.com/version1/messaging',
    'timeout'    => 15,        // seconds per request
    'max_attempts' => 4,       // 1 original attempt + 3 retries (matches sms_logs CHECK constraint)
    'retry_delay_minutes' => 2 // wait before each retry
];

// Override with local values if the file exists.
$localConfig = __DIR__ . '/sms.local.php';
if (is_file($localConfig)) {
    $smsConfig = array_merge($smsConfig, require $localConfig);
}

return $smsConfig;
