-- Seed data for Postgres
INSERT INTO system_settings (setting_key, setting_value) VALUES
('specimen_sla_hours', '24'),
('ai_endpoint', 'http://127.0.0.1:5001/predict'),
('ai_health_endpoint', 'http://127.0.0.1:5001/health'),
('lab_name', 'Lagman Qualicare Multispecialty and Diagnostic Center'),
('backup_path', 'backups')
ON CONFLICT (setting_key) DO NOTHING;

-- HEMATOLOGY / CBC (order matches the printed form)
INSERT INTO lab_tests (test_code, test_name, panel_code, unit, is_numeric, sort_order) VALUES
('HGB', 'Hemoglobin', 'CBC', 'g/dL', 1, 10),
('HCT', 'Hematocrit', 'CBC', '%', 1, 20),
('RBC', 'R.B.C Count', 'CBC', 'x10^12/L', 1, 30),
('WBC', 'W.B.C Count', 'CBC', 'x10^9/L', 1, 40),
('SEG', 'Segmenters', 'CBC', 'fraction', 1, 50),
('LYM', 'Lymphocytes', 'CBC', 'fraction', 1, 60),
('MON', 'Monocytes', 'CBC', 'fraction', 1, 70),
('EOS', 'Eosinophils', 'CBC', 'fraction', 1, 80),
('MCV', 'MCV', 'CBC', 'fL', 1, 90),
('MCH', 'MCH', 'CBC', 'pg', 1, 100),
('MCHC', 'MCHC', 'CBC', 'g/L', 1, 110),
('PLT', 'Platelet Count', 'CBC', 'x10^9/L', 1, 120)
ON CONFLICT (panel_code, test_code) DO NOTHING;

-- CHEMISTRY (blood)
INSERT INTO lab_tests (test_code, test_name, panel_code, unit, is_numeric, sort_order) VALUES
('GLU', 'Fasting Blood Sugar', 'CHEMISTRY', 'mg/dL', 1, 10),
('CREA', 'Creatinine', 'CHEMISTRY', 'mg/dL', 1, 20),
('BUN', 'Blood Urea Nitrogen', 'CHEMISTRY', 'mg/dL', 1, 30),
('UA', 'Uric Acid', 'CHEMISTRY', 'mg/dL', 1, 40),
('CHOL', 'Total Cholesterol', 'CHEMISTRY', 'mg/dL', 1, 50)
ON CONFLICT (panel_code, test_code) DO NOTHING;

-- URINALYSIS (printed form; microscopic lines are text such as "4-6 / hpf")
INSERT INTO lab_tests (test_code, test_name, panel_code, unit, is_numeric, sort_order) VALUES
('COLOR', 'Color', 'URINE', NULL, 0, 10),
('APPEARANCE', 'Transparency', 'URINE', NULL, 0, 20),
('PH', 'pH', 'URINE', NULL, 1, 30),
('SG', 'Specific Gravity', 'URINE', NULL, 1, 40),
('GLU', 'Sugar', 'URINE', NULL, 0, 50),
('PRO', 'Albumin', 'URINE', NULL, 0, 60),
('KET', 'Ketones', 'URINE', NULL, 0, 70),
('BLD', 'Blood', 'URINE', NULL, 0, 80),
('BIL', 'Bilirubin', 'URINE', NULL, 0, 90),
('UBG', 'Urobilinogen', 'URINE', 'EU/dL', 0, 100),
('NIT', 'Nitrite', 'URINE', NULL, 0, 110),
('LEU', 'Leukocyte Esterase', 'URINE', NULL, 0, 120),
('WBC', 'Pus Cells', 'URINE', '/hpf', 0, 130),
('RBC', 'Red Cells', 'URINE', '/hpf', 0, 140),
('MTHR', 'Mucus Threads', 'URINE', NULL, 0, 150),
('AUR', 'Amorphous Urates', 'URINE', NULL, 0, 160),
('EC', 'Epithelial Cells', 'URINE', '/hpf', 0, 170),
('BAC', 'Bacteria', 'URINE', NULL, 0, 180),
('CAST', 'Casts', 'URINE', '/LPF', 0, 190),
('CRYS', 'Crystals', 'URINE', NULL, 0, 200),
('YST', 'Yeast', 'URINE', NULL, 0, 210)
ON CONFLICT (panel_code, test_code) DO NOTHING;

-- FECALYSIS
INSERT INTO lab_tests (test_code, test_name, panel_code, unit, is_numeric, sort_order) VALUES
('COLOR', 'Color', 'STOOL', NULL, 0, 10),
('CONS', 'Consistency', 'STOOL', NULL, 0, 20),
('WBC', 'Pus Cells', 'STOOL', '/hpf', 0, 30),
('RBC', 'Red Cells', 'STOOL', '/hpf', 0, 40),
('BAC', 'Bacteria', 'STOOL', NULL, 0, 50),
('PARA', 'Intestinal Parasites', 'STOOL', NULL, 0, 60),
('MUC', 'Mucus', 'STOOL', NULL, 0, 70),
('BLOOD', 'Visible Blood', 'STOOL', NULL, 0, 80),
('OVA', 'Parasite Ova', 'STOOL', NULL, 0, 90),
('CYST', 'Protozoan Cyst', 'STOOL', NULL, 0, 100),
('TROPH', 'Protozoan Trophozoite', 'STOOL', NULL, 0, 110),
('YEAST', 'Yeast', 'STOOL', NULL, 0, 120),
('FAT', 'Fat Globules', 'STOOL', NULL, 0, 130),
('FOB', 'Fecal Occult Blood', 'STOOL', NULL, 0, 140)
ON CONFLICT (panel_code, test_code) DO NOTHING;

INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 18, 150, 4.0, 11.0, 2.0, 30.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='WBC'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.panel_code='CBC' AND t.test_code='WBC' AND rr.sex='A' AND rr.age_min=18);

INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'M', 18, 150, 4.5, 5.5, 3.0, 7.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='RBC';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'F', 18, 150, 4.0, 5.0, 2.5, 6.5 FROM lab_tests WHERE panel_code='CBC' AND test_code='RBC';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'M', 18, 150, 13.0, 17.0, 7.0, 20.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='HGB';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'F', 18, 150, 12.0, 15.0, 7.0, 18.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='HGB';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'M', 18, 150, 40.0, 50.0, 20.0, 60.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='HCT';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'F', 18, 150, 36.0, 46.0, 18.0, 55.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='HCT';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 18, 150, 150.0, 450.0, 50.0, 1000.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='PLT';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 150, 0.40, 0.60, 0.10, 0.90 FROM lab_tests WHERE panel_code='CBC' AND test_code='SEG'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.panel_code='CBC' AND t.test_code='SEG' AND rr.sex='A' AND rr.age_min=0);
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 150, 0.20, 0.40, 0.05, 0.80 FROM lab_tests WHERE panel_code='CBC' AND test_code='LYM'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.panel_code='CBC' AND t.test_code='LYM' AND rr.sex='A' AND rr.age_min=0);
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 150, 0.02, 0.10, NULL, 0.40 FROM lab_tests WHERE panel_code='CBC' AND test_code='MON'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.panel_code='CBC' AND t.test_code='MON' AND rr.sex='A' AND rr.age_min=0);
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 150, 0.02, 0.08, NULL, 0.30 FROM lab_tests WHERE panel_code='CBC' AND test_code='EOS'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.panel_code='CBC' AND t.test_code='EOS' AND rr.sex='A' AND rr.age_min=0);
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 150, 80.0, 97.0, 50.0, 130.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='MCV'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.panel_code='CBC' AND t.test_code='MCV' AND rr.sex='A' AND rr.age_min=0);
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 150, 26.50, 33.50, 15.0, 45.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='MCH'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.panel_code='CBC' AND t.test_code='MCH' AND rr.sex='A' AND rr.age_min=0);
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 150, 320.0, 360.0, 250.0, 400.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='MCHC'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.panel_code='CBC' AND t.test_code='MCHC' AND rr.sex='A' AND rr.age_min=0);
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 18, 150, 70.0, 100.0, 40.0, 400.0 FROM lab_tests WHERE panel_code='CHEMISTRY' AND test_code='GLU';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'M', 18, 150, 0.7, 1.3, 0.3, 5.0 FROM lab_tests WHERE panel_code='CHEMISTRY' AND test_code='CREA';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'F', 18, 150, 0.6, 1.1, 0.3, 5.0 FROM lab_tests WHERE panel_code='CHEMISTRY' AND test_code='CREA';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 18, 150, 7.0, 20.0, 2.0, 80.0 FROM lab_tests WHERE panel_code='CHEMISTRY' AND test_code='BUN';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'M', 18, 150, 3.5, 7.2, 1.0, 12.0 FROM lab_tests WHERE panel_code='CHEMISTRY' AND test_code='UA';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'F', 18, 150, 2.6, 6.0, 1.0, 12.0 FROM lab_tests WHERE panel_code='CHEMISTRY' AND test_code='UA';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 18, 150, 0.0, 200.0, NULL, 300.0 FROM lab_tests WHERE panel_code='CHEMISTRY' AND test_code='CHOL';

INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 150, 1.005, 1.030, 1.000, 1.040 FROM lab_tests WHERE panel_code='URINE' AND test_code='SG'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.panel_code='URINE' AND t.test_code='SG');
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 150, 4.5, 8.0, 4.0, 9.0 FROM lab_tests WHERE panel_code='URINE' AND test_code='PH'
AND NOT EXISTS (SELECT 1 FROM reference_ranges rr JOIN lab_tests t ON t.id = rr.lab_test_id WHERE t.panel_code='URINE' AND t.test_code='PH');

INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 17, 5.0, 14.0, 2.0, 30.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='WBC';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 17, 11.0, 16.0, 7.0, 18.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='HGB';
INSERT INTO reference_ranges (lab_test_id, sex, age_min, age_max, min_value, max_value, critical_low, critical_high)
SELECT id, 'A', 0, 17, 150.0, 450.0, 50.0, 1000.0 FROM lab_tests WHERE panel_code='CBC' AND test_code='PLT';
