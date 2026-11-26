# Part C: Admin side and SMS (setup)

## 1. SMS credentials (Africa's Talking sandbox)
1. Copy `config/sms.local.example.php` to `config/sms.local.php`.
2. Paste your sandbox API key. The file is in `.gitignore`, so it is never pushed.
3. In the Africa's Talking sandbox simulator, add the phone number you test with, so you can see the messages.

## 2. Create the first admin
    php database/create_admin.php "Your Name" you@example.com 0712345678 "YourPassword1"
Then log in; admins land on `/admin/dashboard.php`.

## 3. Send reminders and retries automatically
Schedule this every 5 minutes (Windows Task Scheduler or cron):

    php C:\xampp\htdocs\HealthConnect\sms\process_queue.php

It queues the 24-hour reminders and sends everything that is due, including retries.
Without a scheduler, an admin can click **Send due SMS now** on the SMS Log page.

## How SMS works
- Booking (patient) and walk-in registration call `sendBookingConfirmationSms()`. A failed SMS never blocks a booking.
- Each attempt is one row in `sms_logs`. Attempt 1 is the first try; attempts 2 to 4 are retries (max 3), 2 minutes apart.
- An invalid phone number is logged as failed and not retried.

## Files added
- `includes/sms.php`, `config/sms.php`, `config/sms.local.example.php`, `sms/process_queue.php`
- `admin/dashboard.php`, `clinics.php`, `users.php`, `reports.php`, `sms_log.php`
- `database/create_admin.php`

## Existing files touched (one-line hooks)
- `patient/book.php`: loads `includes/sms.php`, sends the confirmation after commit, updates the success text
- `staff/walkin_booking.php`: loads `includes/sms.php`, sends the confirmation after commit
- `includes/layout_header.php`: admin nav gets Dashboard and SMS Log links
