"""
Train Isolation Forest models for CBC (and generic) panels.
Uses synthetic normal-ish laboratory distributions seeded for thesis demo,
plus optional CSV of historical released results.
"""
from __future__ import annotations

import json
from pathlib import Path

import joblib
import numpy as np
from sklearn.ensemble import IsolationForest

ROOT = Path(__file__).resolve().parent
MODEL_DIR = ROOT / "models"
MODEL_DIR.mkdir(exist_ok=True)

CBC_FEATURES = ["WBC", "RBC", "HGB", "HCT", "PLT", "sex", "age"]
MODEL_VERSION = "iforest-cbc-v1"


def synthesize_cbc(n: int = 400, seed: int = 42) -> np.ndarray:
    rng = np.random.default_rng(seed)
    sex = rng.integers(0, 2, size=n)  # 0=F 1=M
    age = rng.integers(18, 75, size=n).astype(float)
    wbc = rng.normal(7.0, 1.5, size=n)
    rbc = np.where(sex == 1, rng.normal(5.0, 0.35, size=n), rng.normal(4.5, 0.35, size=n))
    hgb = np.where(sex == 1, rng.normal(15.0, 1.0, size=n), rng.normal(13.5, 1.0, size=n))
    hct = np.where(sex == 1, rng.normal(45.0, 3.0, size=n), rng.normal(41.0, 3.0, size=n))
    plt = rng.normal(250.0, 50.0, size=n)
    X = np.column_stack([wbc, rbc, hgb, hct, plt, sex.astype(float), age])
    # Clip to physiologically plausible bands
    X[:, 0] = np.clip(X[:, 0], 3.5, 12.0)
    X[:, 1] = np.clip(X[:, 1], 3.8, 5.8)
    X[:, 2] = np.clip(X[:, 2], 10.0, 18.0)
    X[:, 3] = np.clip(X[:, 3], 32.0, 52.0)
    X[:, 4] = np.clip(X[:, 4], 120.0, 420.0)
    return X


def train_cbc() -> None:
    X = synthesize_cbc()
    model = IsolationForest(
        n_estimators=100,
        contamination=0.05,
        random_state=42,
    )
    model.fit(X)
    joblib.dump(model, MODEL_DIR / "isolation_forest_cbc.joblib")
    meta = {
        "model_version": MODEL_VERSION,
        "features": CBC_FEATURES,
        "panel": "CBC",
        "contamination": 0.05,
        "n_estimators": 100,
        "training_rows": int(X.shape[0]),
        "notes": "Synthetic normals for demo; retrain quarterly with released lab history.",
    }
    (MODEL_DIR / "isolation_forest_cbc.meta.json").write_text(json.dumps(meta, indent=2), encoding="utf-8")
    print(f"Saved {MODEL_VERSION} with {X.shape[0]} rows -> {MODEL_DIR}")


if __name__ == "__main__":
    train_cbc()
