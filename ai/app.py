"""
Flask Isolation Forest + OpenRouter chat service for AI-Assisted LIS.
Run: python app.py
Default: http://127.0.0.1:5001
"""
from __future__ import annotations

import json
import os
import re
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import requests
from flask import Flask, jsonify, request

ROOT = Path(__file__).resolve().parent
MODEL_PATH = ROOT / "models" / "isolation_forest_cbc.joblib"
META_PATH = ROOT / "models" / "isolation_forest_cbc.meta.json"


def _read_php_env(key: str) -> str:
    """Read a string value from ../config/env.php when OS env is unset (local XAMPP)."""
    path = ROOT.parent / "config" / "env.php"
    if not path.is_file():
        return ""
    try:
        text = path.read_text(encoding="utf-8")
    except OSError:
        return ""
    m = re.search(rf"['\"]{re.escape(key)}['\"]\s*=>\s*['\"]([^'\"]*)['\"]", text)
    return (m.group(1) if m else "").strip()


def _env(key: str, default: str = "") -> str:
    v = os.environ.get(key, "").strip()
    if v:
        return v
    return _read_php_env(key) or default


OPENROUTER_API_KEY = _env("OPENROUTER_API_KEY")
OPENROUTER_MODEL = _env("OPENROUTER_MODEL", "openai/gpt-4o-mini")
OPENROUTER_BASE = _env("OPENROUTER_BASE_URL", "https://openrouter.ai/api/v1").rstrip("/")
OPENROUTER_SITE = os.environ.get("OPENROUTER_HTTP_REFERER", "https://ailab-lis.local")
OPENROUTER_TITLE = os.environ.get("OPENROUTER_APP_TITLE", "AI-Assisted LIS")

BACKUP_AI_API_KEY = _env("BACKUP_AI_API_KEY")
BACKUP_AI_BASE = _env("BACKUP_AI_BASE_URL", "https://router.bynara.id/v1").rstrip("/")
BACKUP_AI_MODEL = _env("BACKUP_AI_MODEL", "auto/bynara")

LIS_SYSTEM_PROMPT = """You are the AI assistant for an AI-Assisted Laboratory Information System (AI-LIS)
used by Laboratory Managers and Medical Technologists at Lagman Qualicare Multispecialty and Diagnostic Center.

Help with:
- LIS workflow (patients → requests → specimens → encode → AI review → approve → release)
- Interpreting Isolation Forest soft warnings (advisory only — never auto-approve)
- Reference ranges, CBC panels, specimen SLA delays, roles (manager / med_tech / staff)
- Operational troubleshooting inside this LIS

Rules:
- Do not invent patient results or claim to replace clinical judgment.
- If unsure, say so and recommend verifying in the LIS screens or with a supervisor.
- Keep answers concise and practical for busy lab staff.
- You are not a substitute for a licensed clinician's diagnosis.
"""

app = Flask(__name__)

model = None
meta = {
    "model_version": "untrained",
    "features": ["WBC", "RBC", "HGB", "HCT", "PLT", "sex", "age"],
    "panel": "CBC",
}


def ensure_model() -> None:
    """Train on first boot if the joblib artifact is missing (e.g. fresh deploy)."""
    if MODEL_PATH.exists():
        return
    try:
        from train_model import train_cbc

        train_cbc()
    except Exception as exc:  # pragma: no cover - startup safeguard
        app.logger.warning("Could not auto-train model: %s", exc)


def load_model() -> None:
    global model, meta
    ensure_model()
    if META_PATH.exists():
        meta = json.loads(META_PATH.read_text(encoding="utf-8"))
    if MODEL_PATH.exists():
        model = joblib.load(MODEL_PATH)
    else:
        model = None


def llm_providers() -> list[dict[str, str]]:
    providers: list[dict[str, str]] = []
    if OPENROUTER_API_KEY:
        providers.append({
            "name": "openrouter",
            "api_key": OPENROUTER_API_KEY,
            "base_url": OPENROUTER_BASE,
            "model": OPENROUTER_MODEL,
        })
    if BACKUP_AI_API_KEY:
        providers.append({
            "name": "nararouter",
            "api_key": BACKUP_AI_API_KEY,
            "base_url": BACKUP_AI_BASE,
            "model": BACKUP_AI_MODEL,
        })
    return providers


def openrouter_ready() -> bool:
    return bool(llm_providers())


def _chat_completions(provider: dict[str, str], messages: list[dict[str, str]], *, temperature: float, max_tokens: int) -> dict[str, Any]:
    name = provider["name"]
    url = f"{provider['base_url'].rstrip('/')}/chat/completions"
    headers = {
        "Authorization": f"Bearer {provider['api_key']}",
        "Content-Type": "application/json",
        "HTTP-Referer": OPENROUTER_SITE,
        "X-Title": OPENROUTER_TITLE,
    }
    payload = {
        "model": provider["model"],
        "messages": messages,
        "temperature": temperature,
        "max_tokens": max_tokens,
    }
    try:
        resp = requests.post(url, headers=headers, json=payload, timeout=40)
    except requests.RequestException as exc:
        return {"ok": False, "error": "llm_request_failed", "detail": f"{exc} ({name})"}

    try:
        data = resp.json()
    except ValueError:
        return {
            "ok": False,
            "error": "invalid_json",
            "detail": f"{name} returned HTTP {resp.status_code}",
        }

    if resp.status_code >= 400:
        detail = data.get("error", {}).get("message") if isinstance(data.get("error"), dict) else data.get("error")
        return {
            "ok": False,
            "error": "llm_http_error",
            "detail": f"{detail or f'HTTP {resp.status_code}'} ({name})",
            "raw": data,
        }

    choices = data.get("choices") or []
    if not choices:
        return {"ok": False, "error": "empty_response", "detail": f"No choices from {name}", "raw": data}

    content = (choices[0].get("message") or {}).get("content") or ""
    return {
        "ok": True,
        "reply": content.strip(),
        "model": data.get("model") or provider["model"],
        "provider": name,
        "usage": data.get("usage"),
    }


def openrouter_chat(messages: list[dict[str, str]], *, temperature: float = 0.4, max_tokens: int = 700) -> dict[str, Any]:
    providers = llm_providers()
    if not providers:
        return {"ok": False, "error": "llm_not_configured", "detail": "OPENROUTER_API_KEY / BACKUP_AI_API_KEY missing"}

    last: dict[str, Any] = {"ok": False, "error": "all_providers_failed", "detail": "All LLM providers failed"}
    for provider in providers:
        last = _chat_completions(provider, messages, temperature=temperature, max_tokens=max_tokens)
        if last.get("ok"):
            return last
    return last


def explain_anomaly(features: dict, sex: str, age: Any, score: float) -> str | None:
    """Optional LLM note when Isolation Forest flags a CBC — advisory only."""
    if not openrouter_ready():
        return None
    prompt = (
        "An Isolation Forest model flagged this CBC panel as anomalous. "
        "Give a short (2-4 sentences) advisory review tip for a Medical Technologist. "
        "Do not diagnose. Mention which values look most unusual if obvious.\n"
        f"sex={sex}, age={age}, score={score:.4f}, features={json.dumps(features)}"
    )
    result = openrouter_chat(
        [
            {"role": "system", "content": LIS_SYSTEM_PROMPT},
            {"role": "user", "content": prompt},
        ],
        temperature=0.2,
        max_tokens=220,
    )
    if result.get("ok") and result.get("reply"):
        return str(result["reply"])
    return None


load_model()


@app.get("/health")
def health():
    providers = [p["name"] for p in llm_providers()]
    return jsonify({
        "ok": model is not None,
        "model_version": meta.get("model_version"),
        "openrouter": openrouter_ready(),
        "chat_providers": providers,
        "chat_model": OPENROUTER_MODEL if OPENROUTER_API_KEY else (BACKUP_AI_MODEL if BACKUP_AI_API_KEY else None),
    })


@app.post("/chat")
def chat():
    """OpenRouter-backed assistant for Manager / MedTech (proxied from PHP)."""
    payload = request.get_json(silent=True) or {}
    message = str(payload.get("message") or "").strip()
    history = payload.get("history") or []
    role = str(payload.get("role") or "lab_staff")

    if not message:
        return jsonify({"ok": False, "error": "empty_message", "detail": "message is required"}), 400
    if len(message) > 4000:
        return jsonify({"ok": False, "error": "message_too_long", "detail": "Max 4000 characters"}), 400

    messages: list[dict[str, str]] = [
        {"role": "system", "content": LIS_SYSTEM_PROMPT + f"\nCaller role context: {role}."},
    ]
    if isinstance(history, list):
        for item in history[-12:]:
            if not isinstance(item, dict):
                continue
            r = str(item.get("role") or "")
            c = str(item.get("content") or "").strip()
            if r in ("user", "assistant") and c:
                messages.append({"role": r, "content": c[:4000]})
    messages.append({"role": "user", "content": message})

    result = openrouter_chat(messages)
    status = 200 if result.get("ok") else 502
    return jsonify(result), status


@app.post("/predict")
def predict():
    if model is None:
        return jsonify({"ok": False, "error": "model_not_loaded", "detail": "Run train_model.py first"}), 503

    payload = request.get_json(silent=True) or {}
    features = payload.get("features") or {}
    sex = str(payload.get("patient_sex", "M")).upper()
    age = payload.get("patient_age")
    test_code = str(payload.get("test_code", "CBC")).upper()
    want_explain = bool(payload.get("explain", False))

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
    llm_note = None
    if is_anomaly:
        warning = (
            "Isolation Forest flagged this CBC panel as anomalous. "
            "Review encoding and clinical context before approval."
        )
        if want_explain:
            llm_note = explain_anomaly(features, sex, age, score)
            if llm_note:
                warning = f"{warning} AI note: {llm_note}"

    return jsonify({
        "ok": True,
        "is_anomaly": is_anomaly,
        "score": score,
        "warning_message": warning,
        "llm_note": llm_note,
        "model_version": meta.get("model_version"),
        "result_id": payload.get("result_id"),
        "openrouter": openrouter_ready(),
    })


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "5001"))
    host = os.environ.get("HOST", "0.0.0.0")
    app.run(host=host, port=port, debug=False)
