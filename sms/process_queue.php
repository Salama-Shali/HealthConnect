<?php
// Run this every 5 minutes (Task Scheduler on Windows / cron on Linux):
//   php C:\xampp\htdocs\HealthConnect\sms\process_queue.php
// It queues 24-hour reminders, then sends everything that is due (including retries).

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sms.php';

$queued = queueDueReminders($conn);
$result = processDueSms($conn);

echo date('Y-m-d H:i:s') . " | reminders queued: $queued | sent: {$result['sent']} | failed: {$result['failed']}\n";
