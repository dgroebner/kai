-- =====================================================================
-- Projects — Projektzeiterfassung Schema (Dedizierte zweite Datenbank)
-- Alle Tabellen isoliert mit Präfix proj_
-- =====================================================================

CREATE TABLE IF NOT EXISTS proj_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    name VARCHAR(120) NULL,
    status ENUM('invited', 'active', 'disabled') NOT NULL DEFAULT 'invited',
    state_code ENUM('SN', 'HH') NOT NULL DEFAULT 'SN',
    reminder_enabled TINYINT(1) NOT NULL DEFAULT 0,
    reminder_time TIME NOT NULL DEFAULT '16:30:00',
    last_reminder_sent_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proj_auth_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_type ENUM('otp', 'session') NOT NULL DEFAULT 'otp',
    token_hash VARCHAR(64) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_proj_tokens_user_type (user_id, token_type),
    INDEX idx_proj_tokens_hash (token_hash),
    CONSTRAINT fk_proj_tokens_user FOREIGN KEY (user_id) REFERENCES proj_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proj_projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    short_code VARCHAR(20) NOT NULL,
    planisware_code VARCHAR(80) NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_proj_user_shortcode (user_id, short_code),
    CONSTRAINT fk_proj_projects_user FOREIGN KEY (user_id) REFERENCES proj_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proj_project_defaults (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_proj_defaults_lookup (user_id, valid_from, valid_to),
    CONSTRAINT fk_proj_defaults_user FOREIGN KEY (user_id) REFERENCES proj_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_proj_defaults_project FOREIGN KEY (project_id) REFERENCES proj_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proj_time_exceptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    hours DECIMAL(3,1) NOT NULL,
    comment VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_proj_exceptions_user_date (user_id, date),
    CONSTRAINT fk_proj_exceptions_user FOREIGN KEY (user_id) REFERENCES proj_users(id) ON DELETE CASCADE,
    CONSTRAINT fk_proj_exceptions_project FOREIGN KEY (project_id) REFERENCES proj_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proj_absences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    type ENUM('vacation', 'sick', 'special_leave') NOT NULL DEFAULT 'vacation',
    hours DECIMAL(3,1) NOT NULL DEFAULT 8.0,
    comment VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_proj_absence_user_date (user_id, date),
    CONSTRAINT fk_proj_absences_user FOREIGN KEY (user_id) REFERENCES proj_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proj_push_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    endpoint VARCHAR(500) NOT NULL UNIQUE,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_proj_push_user FOREIGN KEY (user_id) REFERENCES proj_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
