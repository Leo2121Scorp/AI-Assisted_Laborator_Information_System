-- Seed data for AI-Assisted LIS
USE ailab_lis;

INSERT INTO system_settings (setting_key, setting_value) VALUES
('specimen_sla_hours', '24'),
('ai_endpoint', 'http://127.0.0.1:5001/predict'),
('ai_health_endpoint', 'http://127.0.0.1:5001/health'),
('lab_name', 'Lagman Qualicare Multispecialty and Diagnostic Center'),
('backup_path', 'backups');

-- Demo users are created by install.php with password_hash('password123').

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
('CHOL', 'Total Cholesterol', 'CHEMISTRY', 'mg/dL', 1);

-- CBC ranges (adult, both sexes as A where similar; sex-specific for HGB/HCT/RBC)
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high) VALUES
((SELECT id FROM lab_tests WHERE test_code='WBC'), 'A', 18, 150, 4.0, 11.0, 2.0, 30.0),
((SELECT id FROM lab_tests WHERE test_code='RBC'), 'M', 18, 150, 4.5, 5.5, 3.0, 7.0),
((SELECT id FROM lab_tests WHERE test_code='RBC'), 'F', 18, 150, 4.0, 5.0, 2.5, 6.5),
((SELECT id FROM lab_tests WHERE test_code='HGB'), 'M', 18, 150, 13.0, 17.0, 7.0, 20.0),
((SELECT id FROM lab_tests WHERE test_code='HGB'), 'F', 18, 150, 12.0, 15.0, 7.0, 18.0),
((SELECT id FROM lab_tests WHERE test_code='HCT'), 'M', 18, 150, 40.0, 50.0, 20.0, 60.0),
((SELECT id FROM lab_tests WHERE test_code='HCT'), 'F', 18, 150, 36.0, 46.0, 18.0, 55.0),
((SELECT id FROM lab_tests WHERE test_code='PLT'), 'A', 18, 150, 150.0, 400.0, 50.0, 1000.0),
((SELECT id FROM lab_tests WHERE test_code='GLU'), 'A', 18, 150, 70.0, 100.0, 40.0, 400.0),
((SELECT id FROM lab_tests WHERE test_code='CREA'), 'M', 18, 150, 0.7, 1.3, 0.3, 5.0),
((SELECT id FROM lab_tests WHERE test_code='CREA'), 'F', 18, 150, 0.6, 1.1, 0.3, 5.0),
((SELECT id FROM lab_tests WHERE test_code='BUN'), 'A', 18, 150, 7.0, 20.0, 2.0, 80.0),
((SELECT id FROM lab_tests WHERE test_code='UA'), 'M', 18, 150, 3.5, 7.2, 1.0, 12.0),
((SELECT id FROM lab_tests WHERE test_code='UA'), 'F', 18, 150, 2.6, 6.0, 1.0, 12.0),
((SELECT id FROM lab_tests WHERE test_code='CHOL'), 'A', 18, 150, 0.0, 200.0, NULL, 300.0);

-- Pediatric CBC (simplified)
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high) VALUES
((SELECT id FROM lab_tests WHERE test_code='WBC'), 'A', 0, 17, 5.0, 14.0, 2.0, 30.0),
((SELECT id FROM lab_tests WHERE test_code='HGB'), 'A', 0, 17, 11.0, 16.0, 7.0, 18.0),
((SELECT id FROM lab_tests WHERE test_code='PLT'), 'A', 0, 17, 150.0, 450.0, 50.0, 1000.0);
