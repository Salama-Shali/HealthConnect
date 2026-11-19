-- test data
USE healthconnect_db;


-- 1. Preserve the previous test booking.
-- The existing appointment will remain in the database,
-- but its old demo clinic will no longer appear in browsing.

UPDATE clinics
SET
    name = 'HealthConnect Test Clinic (Legacy Demo)',
    license_number = NULL,
    verification_status = 'suspended',
    verified_by = NULL,
    verified_at = NULL
WHERE name IN (
    'HealthConnect Demo Clinic',
    'HealthConnect Test Clinic (Legacy Demo)'
)
OR license_number = 'DEMO-HC-001';


-- 2. Add Mbagathi County Referral Hospital.

UPDATE clinics
SET
    license_number = NULL,
    county = 'Nairobi',
    address = 'Mbagathi Road, off Mbagathi Way, Nairobi, Kenya',
    contact_phone = '+254721311808',
    operating_hours = 'Open 24 hours; clinic schedules may vary. Contact hospital.',
    verification_status = 'verified',
    verified_by = NULL,
    verified_at = NULL
WHERE name = 'Mbagathi County Referral Hospital';

INSERT INTO clinics (
    name,
    license_number,
    county,
    address,
    contact_phone,
    operating_hours,
    verification_status
)
SELECT
    'Mbagathi County Referral Hospital',
    NULL,
    'Nairobi',
    'Mbagathi Road, off Mbagathi Way, Nairobi, Kenya',
    '+254721311808',
    'Open 24 hours; clinic schedules may vary. Contact hospital.',
    'verified'
WHERE NOT EXISTS (
    SELECT 1 FROM clinics
    WHERE name = 'Mbagathi County Referral Hospital'
);


-- 3. Add Kenyatta University Teaching, Referral
--    and Research Hospital (KUTRRH).

UPDATE clinics
SET
    license_number = NULL,
    county = 'Nairobi',
    address = 'Northern Bypass Road, Kahawa West, Nairobi, Kenya',
    contact_phone = '+254111138000',
    operating_hours = 'Hospital operates 24/7; appointment clinic schedules vary.',
    verification_status = 'verified',
    verified_by = NULL,
    verified_at = NULL
WHERE name = 'Kenyatta University Teaching, Referral & Research Hospital';

INSERT INTO clinics (
    name,
    license_number,
    county,
    address,
    contact_phone,
    operating_hours,
    verification_status
)
SELECT
    'Kenyatta University Teaching, Referral & Research Hospital',
    NULL,
    'Nairobi',
    'Northern Bypass Road, Kahawa West, Nairobi, Kenya',
    '+254111138000',
    'Hospital operates 24/7; appointment clinic schedules vary.',
    'verified'
WHERE NOT EXISTS (
    SELECT 1 FROM clinics
    WHERE name = 'Kenyatta University Teaching, Referral & Research Hospital'
);


-- 4. Add simulated 30-minute appointment slots
-- throughout the full 24 hours for hospitals.

INSERT IGNORE INTO slots (
    clinic_id,
    slot_date,
    start_time,
    end_time,
    status,
    created_by
)
SELECT
    c.clinic_id,
    DATE_ADD(CURDATE(), INTERVAL 1 DAY),
    SEC_TO_TIME(half_hours.slot_number * 1800),

    CASE
        WHEN half_hours.slot_number = 47
            THEN '23:59:59'
        ELSE SEC_TO_TIME(
            (half_hours.slot_number + 1) * 1800
        )
    END,

    'available',
    NULL

FROM clinics c

CROSS JOIN (
    SELECT
        digits.n + (tens.n * 10) AS slot_number

    FROM (
        SELECT 0 AS n UNION ALL SELECT 1
        UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4 UNION ALL SELECT 5
        UNION ALL SELECT 6 UNION ALL SELECT 7
        UNION ALL SELECT 8 UNION ALL SELECT 9
    ) AS digits

    CROSS JOIN (
        SELECT 0 AS n UNION ALL SELECT 1
        UNION ALL SELECT 2 UNION ALL SELECT 3
        UNION ALL SELECT 4
    ) AS tens
) AS half_hours

WHERE half_hours.slot_number < 48

AND c.name IN (
    'Mbagathi County Referral Hospital',
    'Kenyatta University Teaching, Referral & Research Hospital'
)

AND c.verification_status = 'verified';
