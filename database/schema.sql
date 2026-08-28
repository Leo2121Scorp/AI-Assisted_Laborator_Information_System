-- AI-Assisted Laboratory Information System
-- Lagman Qualicare Multispecialty and Diagnostic Center
-- MySQL schema for XAMPP

CREATE DATABASE IF NOT EXISTS ailab_lis
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ailab_lis;

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------------------
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  role ENUM('manager', 'med_tech', 'staff') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Patients
-- ---------------------------------------------------------------------------
CREATE TABLE patients (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  patient_code VARCHAR(30) NOT NULL UNIQUE,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  middle_name VARCHAR(80) NULL,
  sex ENUM('M', 'F') NOT NULL,
  birth_date DATE NOT NULL,
  contact_number VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_patients_created_by FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Lab test catalog
-- ---------------------------------------------------------------------------
CREATE TABLE lab_tests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  test_code VARCHAR(30) NOT NULL UNIQUE,
  test_name VARCHAR(120) NOT NULL,
  panel_code VARCHAR(30) NOT NULL DEFAULT 'GENERAL',
  unit VARCHAR(40) NULL,
  is_numeric TINYINT(1) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE reference_ranges (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lab_test_id INT UNSIGNED NOT NULL,
  sex ENUM('M', 'F', 'A') NOT NULL DEFAULT 'A',
  age_min INT UNSIGNED NOT NULL DEFAULT 0,
  age_max INT UNSIGNED NOT NULL DEFAULT 150,
  min_value DECIMAL(12,4) NULL,
  max_value DECIMAL(12,4) NULL,
  critical_low DECIMAL(12,4) NULL,
  critical_high DECIMAL(12,4) NULL,
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_ref_lab_test FOREIGN KEY (lab_test_id) REFERENCES lab_tests(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX idx_ref_lookup (lab_test_id, sex, age_min, age_max)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Requests / specimens / results
-- ---------------------------------------------------------------------------
CREATE TABLE lab_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_code VARCHAR(30) NOT NULL UNIQUE,
  patient_id INT UNSIGNED NOT NULL,
  requesting_physician VARCHAR(120) NULL,
  clinical_notes TEXT NULL,
  status ENUM('open', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'open',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_req_patient FOREIGN KEY (patient_id) REFERENCES patients(id),
  CONSTRAINT fk_req_created_by FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE request_tests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lab_request_id INT UNSIGNED NOT NULL,
  lab_test_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_rt_request FOREIGN KEY (lab_request_id) REFERENCES lab_requests(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_rt_test FOREIGN KEY (lab_test_id) REFERENCES lab_tests(id),
  UNIQUE KEY uq_request_test (lab_request_id, lab_test_id)
) ENGINE=InnoDB;

CREATE TABLE specimens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  specimen_code VARCHAR(30) NOT NULL UNIQUE,
  lab_request_id INT UNSIGNED NOT NULL,
  specimen_type VARCHAR(80) NOT NULL DEFAULT 'Blood',
  status ENUM('pending','collected','processing','completed','delayed','missing')
    NOT NULL DEFAULT 'pending',
  collected_at DATETIME NULL,
  status_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  CONSTRAINT fk_spec_request FOREIGN KEY (lab_request_id) REFERENCES lab_requests(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_spec_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE lab_results (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  result_code VARCHAR(30) NOT NULL UNIQUE,
  lab_request_id INT UNSIGNED NOT NULL,
  specimen_id INT UNSIGNED NOT NULL,
  panel_code VARCHAR(30) NOT NULL DEFAULT 'GENERAL',
  status ENUM('pending','encoded','validated','approved','reported','released')
    NOT NULL DEFAULT 'pending',
  ai_flagged TINYINT(1) NOT NULL DEFAULT 0,
  rule_warnings TEXT NULL,
  encoded_by INT UNSIGNED NULL,
  encoded_at DATETIME NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  reported_at DATETIME NULL,
  released_by INT UNSIGNED NULL,
  released_at DATETIME NULL,
  rejection_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_res_request FOREIGN KEY (lab_request_id) REFERENCES lab_requests(id),
  CONSTRAINT fk_res_specimen FOREIGN KEY (specimen_id) REFERENCES specimens(id),
  CONSTRAINT fk_res_encoded_by FOREIGN KEY (encoded_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_res_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_res_released_by FOREIGN KEY (released_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE result_values (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lab_result_id INT UNSIGNED NOT NULL,
  lab_test_id INT UNSIGNED NOT NULL,
  numeric_value DECIMAL(12,4) NULL,
  text_value VARCHAR(120) NULL,
  is_out_of_range TINYINT(1) NOT NULL DEFAULT 0,
  is_critical TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_rv_result FOREIGN KEY (lab_result_id) REFERENCES lab_results(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_rv_test FOREIGN KEY (lab_test_id) REFERENCES lab_tests(id),
  UNIQUE KEY uq_result_test (lab_result_id, lab_test_id)
) ENGINE=InnoDB;

CREATE TABLE ai_flags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lab_result_id INT UNSIGNED NOT NULL,
  is_anomaly TINYINT(1) NOT NULL DEFAULT 0,
  score DECIMAL(12,6) NULL,
  warning_message VARCHAR(500) NULL,
  model_version VARCHAR(60) NULL,
  raw_response JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ai_result FOREIGN KEY (lab_result_id) REFERENCES lab_results(id)
    ON DELETE CASCADE,
  INDEX idx_ai_result (lab_result_id)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id INT UNSIGNED NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB;

CREATE TABLE backups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_path VARCHAR(255) NOT NULL,
  file_size BIGINT UNSIGNED NULL,
  status ENUM('success','failed') NOT NULL,
  message VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_backup_user FOREIGN KEY (created_by) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE system_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
