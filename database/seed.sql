
-- This is test data, not a real clinic.

USE healthconnect_db;

-- Add one verified demo clinic.
INSERT INTO clinics (
    name,
    license_number,
    county,
    address,
    contact_phone,
    operating_hours,
    verification_status
)
VALUES (
    'HealthConnect Demo Clinic',
    'DEMO-HC-001',
    'Nairobi',
    'Demo Location, Nairobi',
    NULL,
    'Monday - Friday, 8:00 AM - 5:00 PM',
    'verified'
)
ON DUPLICATE KEY UPDATE
    clinic_id = LAST_INSERT_ID(clinic_id);

-- Get the demo clinic ID.
SET @demo_clinic_id = LAST_INSERT_ID();

-- Add three test appointment slots for tomorrow.
INSERT IGNORE INTO slots (
    clinic_id,
    slot_date,
    start_time,
    end_time,
    status,
    created_by
)
VALUES
(
    @demo_clinic_id,
    DATE_ADD(CURDATE(), INTERVAL 1 DAY),
    '09:00:00',
    '09:30:00',
    'available',
    NULL
),
(
    @demo_clinic_id,
    DATE_ADD(CURDATE(), INTERVAL 1 DAY),
    '09:30:00',
    '10:00:00',
    'available',
    NULL
),
(
    @demo_clinic_id,
    DATE_ADD(CURDATE(), INTERVAL 1 DAY),
    '10:00:00',
    '10:30:00',
    'available',
    NULL
);