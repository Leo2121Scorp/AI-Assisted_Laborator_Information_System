-- Seed data for Postgres
INSERT INTO system_settings (setting_key, setting_value) VALUES
('specimen_sla_hours', '24'),
('ai_endpoint', 'http://127.0.0.1:5001/predict'),
('ai_health_endpoint', 'http://127.0.0.1:5001/health'),
('lab_name', 'Lagman Qualicare Multispecialty and Diagnostic Center'),
('backup_path', 'backups')
ON CONFLICT (setting_key) DO NOTHING;

INSERT INTO lab_tests (test_code, test_name, panel_code, unit, is_numeric) VALUES
('WBC', 'White Blood Cell Count', 'CBC', 'x10^9/L', 1),
('RBC', 'Red Blood Cell Count', 'CBC', 'x10^12/L', 1),
('HGB', 'Hemoglobin', 'CBC', 'g/dL', 1),
('HCT', 'Hematocrit', 'CBC', '%', 1),
('PLT', 'Platelet Count', 'CBC', 'x10^9/L', 1),
('GLU', 'Fasting Blood Sugar', 'CHEMISTRY', 'mg/dL', 1),
('CREA', 'Creatinine', 'CHEMISTRY', 'mg/dL', 1),
('BUN', 'Blood Urea Nitrogen', 'CHEMISTRY', 'mg/dL', 1),
('UA', 'Uric Acid', 'CHEMISTRY', 'mg/dL', 1),
('CHOL', 'Total Cholesterol', 'CHEMISTRY', 'mg/dL', 1)
ON CONFLICT (test_code) DO NOTHING;

INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 18, 150, 4.0, 11.0, 2.0, 30.0 FROM lab_tests WHERE test_code='WBC'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.test_code='WBC' AND rr.sex='A' AND rr.age_min=18);

INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'M', 18, 150, 4.5, 5.5, 3.0, 7.0 FROM lab_tests WHERE test_code='RBC';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'F', 18, 150, 4.0, 5.0, 2.5, 6.5 FROM lab_tests WHERE test_code='RBC';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'M', 18, 150, 13.0, 17.0, 7.0, 20.0 FROM lab_tests WHERE test_code='HGB';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'F', 18, 150, 12.0, 15.0, 7.0, 18.0 FROM lab_tests WHERE test_code='HGB';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'M', 18, 150, 40.0, 50.0, 20.0, 60.0 FROM lab_tests WHERE test_code='HCT';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'F', 18, 150, 36.0, 46.0, 18.0, 55.0 FROM lab_tests WHERE test_code='HCT';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 18, 150, 150.0, 400.0, 50.0, 1000.0 FROM lab_tests WHERE test_code='PLT';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 18, 150, 70.0, 100.0, 40.0, 400.0 FROM lab_tests WHERE test_code='GLU';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'M', 18, 150, 0.7, 1.3, 0.3, 5.0 FROM lab_tests WHERE test_code='CREA';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'F', 18, 150, 0.6, 1.1, 0.3, 5.0 FROM lab_tests WHERE test_code='CREA';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 18, 150, 7.0, 20.0, 2.0, 80.0 FROM lab_tests WHERE test_code='BUN';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'M', 18, 150, 3.5, 7.2, 1.0, 12.0 FROM lab_tests WHERE test_code='UA';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'F', 18, 150, 2.6, 6.0, 1.0, 12.0 FROM lab_tests WHERE test_code='UA';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 18, 150, 0.0, 200.0, NULL, 300.0 FROM lab_tests WHERE test_code='CHOL';

INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 17, 5.0, 14.0, 2.0, 30.0 FROM lab_tests WHERE test_code='WBC';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 17, 11.0, 16.0, 7.0, 18.0 FROM lab_tests WHERE test_code='HGB';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 17, 150.0, 450.0, 50.0, 1000.0 FROM lab_tests WHERE test_code='PLT';
