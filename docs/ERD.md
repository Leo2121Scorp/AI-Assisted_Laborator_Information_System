# MySQL Entity-Relationship Design

## Entity overview

```
users ─┬─ audit_logs
       ├─ patients (created_by)
       ├─ lab_requests (created_by)
       ├─ specimens (updated_by)
       ├─ lab_results (encoded_by / approved_by / released_by)
       └─ backups (created_by)

patients ──< lab_requests ──< request_tests >── lab_tests
                │
                └── specimens ── lab_results ──< result_values >── lab_tests
                                      │
                                      └── ai_flags

lab_tests ──< reference_ranges
```

## Tables

| Table | Purpose |
|-------|---------|
| `users` | Auth, roles (`manager`, `med_tech`, `staff`) |
| `patients` | Patient demographics |
| `lab_tests` | Catalog of tests/analytes |
| `reference_ranges` | Rule-based min/max by test, sex, age |
| `lab_requests` | Laboratory request header |
| `request_tests` | Tests ordered on a request |
| `specimens` | Specimen tracking + status |
| `lab_results` | Result header + workflow status |
| `result_values` | Per-analyte encoded values |
| `ai_flags` | Isolation Forest scores/warnings |
| `audit_logs` | Immutable activity trail |
| `backups` | Backup run history |
| `system_settings` | SLA hours, AI endpoint URL |

See `database/schema.sql` for full DDL.
