-- Migracao agenda barbearia - 2026-01-14

CREATE TABLE IF NOT EXISTS barbers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_barbers_company_name (company_id, name),
    INDEX idx_barbers_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS calendar_blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    date DATE NOT NULL,
    time TIME NOT NULL,
    reason VARCHAR(255) NULL,
    created_by_user_id INT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_blocks_company_date_time (company_id, date, time),
    INDEX idx_blocks_company_date (company_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE appointments
    ADD COLUMN barber_id INT NULL AFTER company_id,
    ADD COLUMN services_json TEXT NULL AFTER instagram,
    ADD COLUMN total_price DECIMAL(10,2) NULL AFTER services_json,
    ADD COLUMN total_duration_minutes INT NULL AFTER total_price,
    ADD COLUMN ends_at_time TIME NULL AFTER total_duration_minutes,
    ADD COLUMN confirmed_message_sent_at DATETIME NULL AFTER ends_at_time,
    ADD COLUMN reminder_sent_at DATETIME NULL AFTER confirmed_message_sent_at;

CREATE INDEX idx_appointments_company_date_time_barber
    ON appointments (company_id, date, time, barber_id);

CREATE INDEX idx_appointments_reminder
    ON appointments (reminder_sent_at);
