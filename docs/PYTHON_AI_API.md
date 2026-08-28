# PHP ↔ Python Isolation Forest API Contract

## Overview

PHP (LIS) posts encoded numeric result values to a local Python Flask service. The service scores the sample with a pre-trained Isolation Forest model and returns an anomaly decision. AI output is advisory only.

## Endpoint

| Item | Value |
|------|-------|
| Base URL (default) | `http://127.0.0.1:5001` |
| Path | `POST /predict` |
| Content-Type | `application/json` |
| Health | `GET /health` |

## Request body

```json
{
  "result_id": 42,
  "patient_sex": "M",
  "patient_age": 34,
  "test_code": "CBC",
  "features": {
    "WBC": 6.2,
    "RBC": 4.8,
    "HGB": 14.1,
    "HCT": 42.0,
    "PLT": 250
  }
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `result_id` | int | yes | LIS result primary key (for logging) |
| `patient_sex` | string | yes | `M` or `F` |
| `patient_age` | int | yes | Years |
| `test_code` | string | yes | Panel/test family code |
| `features` | object | yes | Analyte code → numeric value |

## Response body (200)

```json
{
  "ok": true,
  "is_anomaly": false,
  "score": 0.12,
  "warning_message": null,
  "model_version": "iforest-cbc-v1"
}
```

Anomaly example:

```json
{
  "ok": true,
  "is_anomaly": true,
  "score": -0.31,
  "warning_message": "Isolation Forest flagged this CBC panel as anomalous. Review encoding and clinical context before approval.",
  "model_version": "iforest-cbc-v1"
}
```

## Error response

```json
{
  "ok": false,
  "error": "missing_features",
  "detail": "Required feature HGB not provided"
}
```

HTTP 400 for bad input; 503 if model not loaded; 500 for unexpected errors. PHP treats AI failure as a soft warning (`AI service unavailable — manual review required`) and still allows MT review.

## Training data assumptions

| Assumption | Value |
|------------|-------|
| Algorithm | `sklearn.ensemble.IsolationForest` |
| Scope | Per test panel model (start: CBC); fallback global numeric vector |
| Features | Numeric analytes only; sex encoded 0/1; age included |
| Training source | Historical released results + synthetic normal ranges seed |
| Minimum rows | Prefer ≥200; bootstrap with seed data if lab history is thin |
| `contamination` | `0.05` (expected anomaly rate) |
| `n_estimators` | 100 |
| `random_state` | 42 |
| Retrain | Quarterly (per sustainability plan) via `ai/train_model.py` |
| Persistence | `ai/models/isolation_forest_cbc.joblib` + feature list JSON |

## PHP client behavior

1. After rule-based validation, call `POST /predict`.
2. Persist row in `ai_flags` (`is_anomaly`, `score`, `warning_message`, `raw_response`).
3. Set `lab_results.ai_flagged` and move status to `validated`.
4. Never auto-approve based on AI.
