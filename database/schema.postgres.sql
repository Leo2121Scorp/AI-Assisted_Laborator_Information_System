-- Postgres schema for Render (and other hosted Postgres)
-- Local XAMPP continues to use database/schema.sql (MySQL).

CREATE TABLE IF NOT EXISTS users (
  id SERIAL PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  role VARCHAR(20) NOT NULL CHECK (role IN ('manager', 'med_tech', 'staff')),
  is_active SMALLINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS patients (
  id SERIAL PRIMARY KEY,
  patient_code VARCHAR(30) NOT NULL UNIQUE,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  middle_name VARCHAR(80) NULL,
  sex VARCHAR(1) NOT NULL CHECK (sex IN ('M', 'F')),
  birth_date DATE NOT NULL,
  contact_number VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS lab_tests (
  id SERIAL PRIMARY KEY,
  test_code VARCHAR(30) NOT NULL UNIQUE,
  test_name VARCHAR(120) NOT NULL,
  panel_code VARCHAR(30) NOT NULL DEFAULT 'GENERAL',
  unit VARCHAR(40) NULL,
  is_numeric SMALLINT NOT NULL DEFAULT 1,
  is_active SMALLINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS reference_ranges (
  id SERIAL PRIMARY KEY,
  lab_test_id INT NOT NULL REFERENCES lab_tests(id) ON DELETE CASCADE,
  sex VARCHAR(1) NOT NULL DEFAULT 'A' CHECK (sex IN ('M', 'F', 'A')),
  age_min INT NOT NULL DEFAULT 0,
  age_max INT NOT NULL DEFAULT 150,
  min_value DECIMAL(12,4) NULL,
  max_value DECIMAL(12,4) NULL,
  critical_low DECIMAL(12,4) NULL,
  critical_high DECIMAL(12,4) NULL,
  notes VARCHAR(255) NULL
);
CREATE INDEX IF NOT EXISTS idx_ref_lookup ON reference_ranges (lab_test_id, sex, age_min, age_max);

CREATE TABLE IF NOT EXISTS lab_requests (
  id SERIAL PRIMARY KEY,
  request_code VARCHAR(30) NOT NULL UNIQUE,
  patient_id INT NOT NULL REFERENCES patients(id),
  requesting_physician VARCHAR(120) NULL,
  clinical_notes TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_progress', 'completed', 'cancelled')),
  created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS request_tests (
  id SERIAL PRIMARY KEY,
  lab_request_id INT NOT NULL REFERENCES lab_requests(id) ON DELETE CASCADE,
  lab_test_id INT NOT NULL REFERENCES lab_tests(id),
  UNIQUE (lab_request_id, lab_test_id)
);

CREATE TABLE IF NOT EXISTS specimens (
  id SERIAL PRIMARY KEY,
  specimen_code VARCHAR(30) NOT NULL UNIQUE,
  lab_request_id INT NOT NULL REFERENCES lab_requests(id) ON DELETE CASCADE,
  specimen_type VARCHAR(80) NOT NULL DEFAULT 'Blood',
  status VARCHAR(20) NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','collected','processing','completed','delayed','missing')),
  collected_at TIMESTAMP NULL,
  status_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
  notes VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS lab_results (
  id SERIAL PRIMARY KEY,
  result_code VARCHAR(30) NOT NULL UNIQUE,
  lab_request_id INT NOT NULL REFERENCES lab_requests(id),
  specimen_id INT NOT NULL REFERENCES specimens(id),
  panel_code VARCHAR(30) NOT NULL DEFAULT 'GENERAL',
  status VARCHAR(20) NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','encoded','validated','approved','reported','released')),
  ai_flagged SMALLINT NOT NULL DEFAULT 0,
  rule_warnings TEXT NULL,
  encoded_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
  encoded_at TIMESTAMP NULL,
  approved_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
  approved_at TIMESTAMP NULL,
  reported_at TIMESTAMP NULL,
  released_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
  released_at TIMESTAMP NULL,
  rejection_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS result_values (
  id SERIAL PRIMARY KEY,
  lab_result_id INT NOT NULL REFERENCES lab_results(id) ON DELETE CASCADE,
  lab_test_id INT NOT NULL REFERENCES lab_tests(id),
  numeric_value DECIMAL(12,4) NULL,
  text_value VARCHAR(120) NULL,
  is_out_of_range SMALLINT NOT NULL DEFAULT 0,
  is_critical SMALLINT NOT NULL DEFAULT 0,
  UNIQUE (lab_result_id, lab_test_id)
);

CREATE TABLE IF NOT EXISTS ai_flags (
  id SERIAL PRIMARY KEY,
  lab_result_id INT NOT NULL REFERENCES lab_results(id) ON DELETE CASCADE,
  is_anomaly SMALLINT NOT NULL DEFAULT 0,
  score DECIMAL(12,6) NULL,
  warning_message VARCHAR(500) NULL,
  model_version VARCHAR(60) NULL,
  raw_response JSONB NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_ai_result ON ai_flags (lab_result_id);

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGSERIAL PRIMARY KEY,
  user_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id INT NULL,
  details TEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs (created_at);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_logs (entity_type, entity_id);

CREATE TABLE IF NOT EXISTS backups (
  id SERIAL PRIMARY KEY,
  file_path VARCHAR(255) NOT NULL,
  file_size BIGINT NULL,
  status VARCHAR(20) NOT NULL CHECK (status IN ('success','failed')),
  message VARCHAR(255) NULL,
  created_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(80) PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL,
  updated_at TIMESTAMP NULL
);
