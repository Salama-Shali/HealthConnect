
-- =====================================================
-- HealthConnect Database Schema
-- Database: healthconnect_db
-- =====================================================

USE healthconnect_db;

-- 1. USERS
-- Stores patients, clinic staff and administrators.

CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('patient', 'clinic_staff', 'admin') NOT NULL DEFAULT 'patient',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2. CLINICS
-- Stores clinic details and verification information.

CREATE TABLE clinics (
    clinic_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    license_number VARCHAR(100) NULL UNIQUE,
    county VARCHAR(100) NOT NULL,
    address VARCHAR(255) NOT NULL,
    contact_phone VARCHAR(20),
    operating_hours VARCHAR(255),
    verification_status ENUM(
        'pending', 'verified', 'rejected', 'suspended'
    ) NOT NULL DEFAULT 'pending',
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_clinic_verifier
        FOREIGN KEY (verified_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 3. CLINIC STAFF
-- Connects staff user accounts to their clinics.

CREATE TABLE clinic_staff (
    staff_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    clinic_id INT UNSIGNED NOT NULL,
    position VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_staff_clinic (user_id, clinic_id),

    CONSTRAINT fk_staff_user
        FOREIGN KEY (user_id)
        REFERENCES users(user_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_staff_clinic
        FOREIGN KEY (clinic_id)
        REFERENCES clinics(clinic_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 4. SLOTS
-- Stores appointment slots created by clinic staff.

CREATE TABLE slots (
    slot_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clinic_id INT UNSIGNED NOT NULL,
    slot_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('available', 'unavailable') NOT NULL DEFAULT 'available',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_slots_id_clinic (slot_id, clinic_id),
    UNIQUE KEY uq_clinic_slot_start (clinic_id, slot_date, start_time),
    KEY idx_slot_date (clinic_id, slot_date),

    CONSTRAINT fk_slot_clinic
        FOREIGN KEY (clinic_id)
        REFERENCES clinics(clinic_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_slot_creator
        FOREIGN KEY (created_by)
        REFERENCES users(user_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 5. APPOINTMENTS
-- Stores ordinary appointments and staff-registered walk-ins.
-- Walk-ins do not require a pre-created appointment slot.
-- Emergency classification is excluded from the initial version.

CREATE TABLE appointments (
    appointment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id INT UNSIGNED NOT NULL,
    clinic_id INT UNSIGNED NOT NULL,
    slot_id INT UNSIGNED NULL,
    appointment_date DATE NOT NULL,
    visit_category ENUM('ordinary', 'walk_in') NOT NULL DEFAULT 'ordinary',
    queue_number INT UNSIGNED NULL,
    status ENUM('booked', 'cancelled', 'attended', 'missed')
        NOT NULL DEFAULT 'booked',
    booked_by INT UNSIGNED NOT NULL,
    booked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    -- Only active bookings occupy their selected slot.
    active_slot_id INT UNSIGNED
        GENERATED ALWAYS AS (
            CASE
                WHEN status = 'booked' THEN slot_id
                ELSE NULL
            END
        ) STORED,

    UNIQUE KEY uq_active_appointment_slot (active_slot_id),
    UNIQUE KEY uq_clinic_daily_queue (
        clinic_id, appointment_date, queue_number
    ),

    KEY idx_patient_history (patient_id, appointment_date),
    KEY idx_clinic_schedule (clinic_id, appointment_date, status),

    CONSTRAINT fk_appointment_patient
        FOREIGN KEY (patient_id)
        REFERENCES users(user_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_appointment_clinic
        FOREIGN KEY (clinic_id)
        REFERENCES clinics(clinic_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_appointment_slot_clinic
        FOREIGN KEY (slot_id, clinic_id)
        REFERENCES slots(slot_id, clinic_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_appointment_booker
        FOREIGN KEY (booked_by)
        REFERENCES users(user_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 6. SMS LOGS
-- Stores SMS notification attempts and their results.
-- Each retry is recorded as a separate attempt.

CREATE TABLE sms_logs (
    sms_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT UNSIGNED NOT NULL,
    recipient_phone VARCHAR(20) NOT NULL,
    message_type ENUM(
        'booking_confirmation',
        'appointment_reminder',
        'cancellation_confirmation'
    ) NOT NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempt_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    scheduled_for DATETIME NOT NULL,
    sent_at DATETIME NULL,
    provider_message_id VARCHAR(150) NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    KEY idx_sms_schedule (status, scheduled_for),
    KEY idx_sms_appointment (appointment_id, message_type),

    UNIQUE KEY uq_sms_attempt (
    appointment_id,
    message_type,
    attempt_number
),

CONSTRAINT chk_sms_attempt_number
    CHECK (attempt_number BETWEEN 1 AND 4),

    CONSTRAINT fk_sms_appointment
        FOREIGN KEY (appointment_id)
        REFERENCES appointments(appointment_id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;