<?php
// HealthConnect SMS module (Part C)
// Africa's Talking sandbox integration, with logging and retries.
//
// Every attempt is stored as its own row in sms_logs:
//   attempt 1 = first try, attempts 2-4 = retries (max 3 retries).
// A failed attempt schedules the next one; sms/process_queue.php sends the due ones.

/** Convert a Kenyan number (07.., 01.., 254.., +254..) to +254XXXXXXXXX. */
function normalizeKenyanPhone(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);

    if (preg_match('/^0([17]\d{8})$/', $digits, $m)) {
        return '+254' . $m[1];
    }
    if (preg_match('/^254([17]\d{8})$/', $digits, $m)) {
        return '+254' . $m[1];
    }
    return null;
}

/** Load the SMS configuration once. */
function smsConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/sms.php';
    }
    return $config;
}

/**
 * Call the Africa's Talking API.
 * Returns ['ok' => bool, 'message_id' => ?string, 'error' => ?string]
 */
function africasTalkingSend(string $to, string $message): array
{
    $cfg = smsConfig();

    if ($cfg['api_key'] === '') {
        return ['ok' => false, 'message_id' => null,
                'error' => 'SMS API key is not configured (config/sms.local.php).'];
    }

    $fields = [
        'username' => $cfg['username'],
        'to'       => $to,
        'message'  => $message,
    ];
    if (!empty($cfg['sender_id'])) {
        $fields['from'] = $cfg['sender_id'];
    }

    $ch = curl_init($cfg['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $cfg['timeout'],
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'apiKey: ' . $cfg['api_key'],
        ],
    ]);

    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $curlError = curl_error($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'message_id' => null, 'error' => 'Network error: ' . $curlError];
    }

    $data = json_decode((string) $body, true);

    if ($http < 200 || $http >= 300 || !is_array($data)) {
        return ['ok' => false, 'message_id' => null,
                'error' => 'API error (HTTP ' . $http . '): ' . substr((string) $body, 0, 300)];
    }

    $recipient = $data['SMSMessageData']['Recipients'][0] ?? null;
    if (!$recipient) {
        $summary = $data['SMSMessageData']['Message'] ?? 'No recipients accepted.';
        return ['ok' => false, 'message_id' => null, 'error' => $summary];
    }

    // Status codes 100 (Processed), 101 (Sent) and 102 (Queued) mean accepted.
    $code = (int) ($recipient['statusCode'] ?? 0);
    if (in_array($code, [100, 101, 102], true)) {
        return ['ok' => true, 'message_id' => $recipient['messageId'] ?? null, 'error' => null];
    }

    return ['ok' => false, 'message_id' => null,
            'error' => 'Rejected: ' . ($recipient['status'] ?? 'unknown') . ' (code ' . $code . ')'];
}

/** Build the SMS text for an appointment. */
function buildSmsMessage(mysqli $conn, int $appointmentId, string $type): ?array
{
    $stmt = $conn->prepare(
        "SELECT a.appointment_id, a.appointment_date, a.visit_category, a.queue_number,
                a.status, u.full_name, u.phone_number,
                c.name AS clinic_name,
                s.start_time
         FROM appointments a
         INNER JOIN users u   ON u.user_id = a.patient_id
         INNER JOIN clinics c ON c.clinic_id = a.clinic_id
         LEFT JOIN slots s    ON s.slot_id = a.slot_id
         WHERE a.appointment_id = ?
         LIMIT 1"
    );
    $stmt->bind_param("i", $appointmentId);
    $stmt->execute();
    $a = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$a) {
        return null;
    }

    $first = explode(' ', trim($a['full_name']))[0];
    $date  = date('D, d M Y', strtotime($a['appointment_date']));
    $time  = $a['start_time'] ? date('g:i A', strtotime($a['start_time'])) : null;
    $when  = $time ? "$date at $time" : $date;

    switch ($type) {
        case 'booking_confirmation':
            if ($a['visit_category'] === 'walk_in' && $a['queue_number']) {
                $text = "HealthConnect: Hi $first, you are registered at {$a['clinic_name']} today. Your queue number is {$a['queue_number']}. Ref #{$a['appointment_id']}.";
                break;
            }
            $text = "HealthConnect: Hi $first, your appointment at {$a['clinic_name']} is confirmed for $when. Ref #{$a['appointment_id']}.";
            break;
        case 'appointment_reminder':
            $text = "HealthConnect reminder: Hi $first, you have an appointment at {$a['clinic_name']} on $when. Ref #{$a['appointment_id']}.";
            break;
        case 'cancellation_confirmation':
            $text = "HealthConnect: Hi $first, your appointment at {$a['clinic_name']} on $date has been cancelled. Ref #{$a['appointment_id']}.";
            break;
        default:
            return null;
    }

    return ['phone' => $a['phone_number'], 'text' => $text, 'status' => $a['status']];
}

/**
 * Queue an SMS (attempt 1). Safe to call twice: the UNIQUE key blocks duplicates.
 * Returns the sms_id of the new row, or null if it already existed.
 */
function queueSms(mysqli $conn, int $appointmentId, string $type, ?string $scheduledFor = null): ?int
{
    $scheduledFor = $scheduledFor ?? date('Y-m-d H:i:s');

    $appt = $conn->prepare("SELECT u.phone_number
                            FROM appointments a
                            INNER JOIN users u ON u.user_id = a.patient_id
                            WHERE a.appointment_id = ? LIMIT 1");
    $appt->bind_param("i", $appointmentId);
    $appt->execute();
    $row = $appt->get_result()->fetch_assoc();
    $appt->close();

    if (!$row) {
        return null;
    }

    $phone = normalizeKenyanPhone($row['phone_number']) ?? $row['phone_number'];

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO sms_logs
         (appointment_id, recipient_phone, message_type, status, attempt_number, scheduled_for)
         VALUES (?, ?, ?, 'pending', 1, ?)"
    );
    $stmt->bind_param("isss", $appointmentId, $phone, $type, $scheduledFor);
    $stmt->execute();
    $created = $stmt->affected_rows === 1;
    $id = $conn->insert_id;
    $stmt->close();

    return $created ? (int) $id : null;
}

/**
 * Send one pending sms_logs row, record the result, and schedule a retry on failure.
 * Returns true if the SMS was sent.
 */
function processSmsRow(mysqli $conn, int $smsId): bool
{
    $cfg = smsConfig();

    // Claim the row so two workers cannot send it twice.
    $claim = $conn->prepare("UPDATE sms_logs SET status = 'pending' WHERE sms_id = ? AND status = 'pending'");
    $claim->bind_param("i", $smsId);
    $claim->execute();
    $claim->close();

    $stmt = $conn->prepare("SELECT * FROM sms_logs WHERE sms_id = ? AND status = 'pending' LIMIT 1");
    $stmt->bind_param("i", $smsId);
    $stmt->execute();
    $log = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$log) {
        return false;
    }

    $msg   = buildSmsMessage($conn, (int) $log['appointment_id'], $log['message_type']);
    $error = null;
    $messageId = null;
    $ok = false;

    if (!$msg) {
        $error = 'Appointment not found or message type unsupported.';
    } elseif ($log['message_type'] === 'appointment_reminder' && $msg['status'] !== 'booked') {
        // Appointment was cancelled/attended in the meantime: no reminder needed.
        $upd = $conn->prepare("UPDATE sms_logs SET status = 'failed', error_message = 'Skipped: appointment no longer booked.' WHERE sms_id = ?");
        $upd->bind_param("i", $smsId);
        $upd->execute();
        $upd->close();
        return false;
    } else {
        $phone = normalizeKenyanPhone($log['recipient_phone']);
        if (!$phone) {
            $error = 'Invalid Kenyan phone number: ' . $log['recipient_phone'];
        } else {
            $result    = africasTalkingSend($phone, $msg['text']);
            $ok        = $result['ok'];
            $error     = $result['error'];
            $messageId = $result['message_id'];
        }
    }

    if ($ok) {
        $upd = $conn->prepare("UPDATE sms_logs
                               SET status = 'sent', sent_at = NOW(), provider_message_id = ?, error_message = NULL
                               WHERE sms_id = ?");
        $upd->bind_param("si", $messageId, $smsId);
        $upd->execute();
        $upd->close();
        return true;
    }

    // Failed: mark this attempt, then queue the next attempt if any remain.
    $upd = $conn->prepare("UPDATE sms_logs SET status = 'failed', error_message = ? WHERE sms_id = ?");
    $upd->bind_param("si", $error, $smsId);
    $upd->execute();
    $upd->close();

    $attempt = (int) $log['attempt_number'];
    $permanent = !$msg || ($msg && !normalizeKenyanPhone($log['recipient_phone']));

    if (!$permanent && $attempt < (int) $cfg['max_attempts']) {
        $next = $attempt + 1;
        $when = date('Y-m-d H:i:s', time() + ((int) $cfg['retry_delay_minutes']) * 60);
        $ins = $conn->prepare(
            "INSERT IGNORE INTO sms_logs
             (appointment_id, recipient_phone, message_type, status, attempt_number, scheduled_for)
             VALUES (?, ?, ?, 'pending', ?, ?)"
        );
        $ins->bind_param("issis", $log['appointment_id'], $log['recipient_phone'], $log['message_type'], $next, $when);
        $ins->execute();
        $ins->close();
    }

    return false;
}

/**
 * Booking confirmation: queue it and try to send straight away.
 * Never throws, so a failed SMS can never break a booking.
 */
function sendBookingConfirmationSms(mysqli $conn, int $appointmentId): void
{
    try {
        $smsId = queueSms($conn, $appointmentId, 'booking_confirmation');
        if ($smsId) {
            processSmsRow($conn, $smsId);
        }
    } catch (Throwable $e) {
        error_log('SMS confirmation error: ' . $e->getMessage());
    }
}

/** Send every pending SMS whose time has come. Returns counts for display/logging. */
function processDueSms(mysqli $conn, int $limit = 50): array
{
    $sent = 0;
    $failed = 0;

    $result = $conn->query(
        "SELECT sms_id FROM sms_logs
         WHERE status = 'pending' AND scheduled_for <= NOW()
         ORDER BY scheduled_for ASC
         LIMIT " . (int) $limit
    );

    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        processSmsRow($conn, (int) $row['sms_id']) ? $sent++ : $failed++;
    }

    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Queue a reminder for every booked appointment that starts in ~24 hours.
 * Returns the number of reminders queued.
 */
function queueDueReminders(mysqli $conn): int
{
    $result = $conn->query(
        "SELECT a.appointment_id
         FROM appointments a
         LEFT JOIN slots s ON s.slot_id = a.slot_id
         WHERE a.status = 'booked'
           AND s.slot_id IS NOT NULL
           AND TIMESTAMP(s.slot_date, s.start_time) > NOW()
           AND TIMESTAMP(s.slot_date, s.start_time) <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
           AND NOT EXISTS (
               SELECT 1 FROM sms_logs l
               WHERE l.appointment_id = a.appointment_id
                 AND l.message_type = 'appointment_reminder'
           )"
    );

    $count = 0;
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        if (queueSms($conn, (int) $row['appointment_id'], 'appointment_reminder')) {
            $count++;
        }
    }
    return $count;
}
