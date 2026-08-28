"""
Flask Isolation Forest scoring service for AI-Assisted LIS.
Run: python app.py
Default: http://127.0.0.1:5001
"""
from __future__ import annotations

import json
from pathlib import Path

import joblib
import numpy as np
from flask import Flask, jsonify, request

ROOT = Path(__file__).resolve().parent
MODEL_PATH = ROOT / "models" / "isolation_forest_cbc.joblib"
META_PATH = ROOT / "models" / "isolation_forest_cbc.meta.json"

app = Flask(__name__)

model = None
meta = {
    "model_version": "untrained",
    "features": ["WBC", "RBC", "HGB", "HCT", "PLT", "sex", "age"],
    "panel": "CBC",
}


def load_model() -> None:
    global model, meta
    if META_PATH.exists():
        meta = json.loads(META_PATH.read_text(encoding="utf-8"))
    if MODEL_PATH.exists():
        model = joblib.load(MODEL_PATH)
    else:
        model = None


load_model()


@app.get("/health")
def health():
    return jsonify({"ok": model is not None, "model_version": meta.get("model_version")})


@app.post("/predict")
def predict():
    if model is None:
        return jsonify({"ok": False, "error": "model_not_loaded", "detail": "Run train_model.py first"}), 503

    payload = request.get_json(silent=True) or {}
    features = payload.get("features") or {}
    sex = str(payload.get("patient_sex", "M")).upper()
    age = payload.get("patient_age")
    test_code = str(payload.get("test_code", "CBC")).upper()

    if age is None:
        return jsonify({"ok": False, "error": "missing_age", "detail": "patient_age required"}), 400

    # Map panel features; for non-CBC, score using available overlapping CBC-like codes if present
    required = ["WBC", "RBC", "HGB", "HCT", "PLT"]
    missing = [f for f in required if f not in features]
    if missing and test_code == "CBC":
        return jsonify({
            "ok": False,
            "error": "missing_features",
            "detail": f"Required feature {missing[0]} not provided",
        }), 400

    if missing:
        # Soft path for chemistry panels: no CBC model features — return non-anomaly advisory
        return jsonify({
            "ok": True,
            "is_anomaly": False,
            "score": 0.0,
            "warning_message": None,
            "model_version": meta.get("model_version"),
            "note": "Panel outside CBC model scope; rule-based validation applies.",
        })

    try:
        vector = [
            float(features["WBC"]),
            float(features["RBC"]),
            float(features["HGB"]),
            float(features["HCT"]),
            float(features["PLT"]),
            1.0 if sex == "M" else 0.0,
            float(age),
        ]
    except (TypeError, ValueError):
        return jsonify({"ok": False, "error": "invalid_features", "detail": "Features must be numeric"}), 400

    X = np.array([vector], dtype=float)
    pred = int(model.predict(X)[0])  # -1 anomaly, 1 normal
    score = float(model.decision_function(X)[0])
    is_anomaly = pred == -1

    warning = None
    if is_anomaly:
        warning = (
            "Isolation Forest flagged this CBC panel as anomalous. "
            "Review encoding and clinical context before approval."
        )

    return jsonify({
        "ok": True,
        "is_anomaly": is_anomaly,
        "score": score,
        "warning_message": warning,
        "model_version": meta.get("model_version"),
        "result_id": payload.get("result_id"),
    })


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=5001, debug=False)
