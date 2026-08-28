# RBAC Roles and State Machines

AI-Assisted Laboratory Information System — Lagman Qualicare Multispecialty and Diagnostic Center

## Roles

| Role code | Display name | Description |
|-----------|--------------|-------------|
| `manager` | Laboratory Manager | Full operational oversight, user management, backups, reference ranges, release override |
| `med_tech` | Medical Technologist | Specimen processing, result encoding, validation review, approve, generate/release reports |
| `staff` | Administrative Staff | Patient registration, test requests, specimen collection logging, view reports |

### Permission matrix

| Permission | manager | med_tech | staff |
|------------|:-------:|:--------:|:-----:|
| Login / dashboard | Y | Y | Y |
| Manage users | Y | — | — |
| Manage reference ranges | Y | — | — |
| Register / edit patients | Y | Y | Y |
| Create laboratory requests | Y | Y | Y |
| Collect specimen / update early statuses | Y | Y | Y |
| Update processing / completed specimen | Y | Y | — |
| Encode results | Y | Y | — |
| Approve results | Y | Y | — |
| Generate / release reports | Y | Y | — |
| View released reports | Y | Y | Y |
| View audit logs | Y | Y | — |
| Run database backup | Y | — | — |
| View AI warnings | Y | Y | — |

---

## Specimen status machine

Statuses: `pending` → `collected` → `processing` → `completed`

Alert statuses (side states): `delayed`, `missing`

```
pending ──► collected ──► processing ──► completed
                │              │
                ├──► delayed ◄─┤
                └──► missing
delayed / missing ──► collected | processing (when recovered)
```

| From | Allowed to | Who |
|------|------------|-----|
| pending | collected | manager, med_tech, staff |
| collected | processing, delayed, missing | manager, med_tech |
| processing | completed, delayed, missing | manager, med_tech |
| delayed | collected, processing, missing | manager, med_tech |
| missing | collected, processing | manager, med_tech |
| completed | (terminal) | — |

Delay alert rule: specimen still in `pending`/`collected`/`processing` longer than the configured SLA hours (default 24h) is flagged on the dashboard.

---

## Result / report state machine

Statuses:

`pending` → `encoded` → `validated` → `approved` → `reported` → `released`

Optional flag (boolean, not a status): `ai_flagged` may be set at `validated` when Isolation Forest marks an anomaly. MT must still review and approve.

```
pending ──► encoded ──► validated ──► approved ──► reported ──► released
                │            │
                │            └── ai_flagged = true|false (soft warning)
                └── may return to encoded if MT rejects for re-entry
approved ◄── rejected (returns to encoded for correction)
```

| From | Allowed to | Who | Gate |
|------|------------|-----|------|
| pending | encoded | manager, med_tech | Values entered; rule-based check runs |
| encoded | validated | system | Rule-based pass/warn + Python AI called |
| validated | approved | manager, med_tech | Explicit Approve action (required even if AI flagged) |
| validated | encoded | manager, med_tech | Reject / send back for re-encoding |
| approved | reported | manager, med_tech | PDF/HTML report generated |
| reported | released | manager, med_tech | Released to patient/clinic; audit logged |

**Release gate:** Only `approved` results may become `reported`, and only `reported` results may become `released`. Staff cannot approve or release.

---

## Dual validation order

1. **Rule-based** — compare each analyte to `reference_ranges` (age/sex/test aware). Hard-block impossible values (e.g. negative CBC counts); soft-warn out-of-range clinical values.
2. **Isolation Forest (Python)** — soft anomaly flag; never auto-approves or auto-rejects.
3. **Medical Technologist** — reviews warnings, then Approves or Rejects.
