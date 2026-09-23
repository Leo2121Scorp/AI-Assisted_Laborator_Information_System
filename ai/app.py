"""
ai/app.py — AI helper service for the Laboratory Information System (LIS)

WHAT THIS FILE DOES
  Small Flask web server that the PHP site calls. It does two jobs:
  1) Score CBC blood panels with Isolation Forest (odd vs normal-looking).
  2) Chat with lab staff using Groq (or a backup LLM) for LIS help.

HOW TO RUN (local)
  cd ai
  python app.py
  Default URL: http://127.0.0.1:5001

MAIN ENDPOINTS
  GET  /health   — Is the model loaded? Are chat keys set?
  POST /predict  — Send CBC numbers; get anomaly yes/no + optional AI note.
  POST /chat     — Lab assistant chat (proxied from PHP).

WHERE TO EDIT
  - API keys: OS env vars, or ../config/env.php (local XAMPP).
  - Model files: ai/models/isolation_forest_cbc.joblib (+ .meta.json).
  - Retrain: run train_model.py (or this app auto-trains if the file is missing).

IMPORTANT
  AI warnings are advisory only. Staff still approve and release results.
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
from werkzeug.exceptions import HTTPException

# Folder of this file (ai/). Used to find models and the PHP config.
ROOT = Path(__file__).resolve().parent
MODEL_PATH = ROOT / "models" / "isolation_forest_cbc.joblib"
META_PATH = ROOT / "models" / "isolation_forest_cbc.meta.json"


def _read_php_env(key: str) -> str:
    """
    Read one setting from ../config/env.php when the OS env var is empty.
    Useful on local XAMPP so you only keep keys in one PHP file.
    Returns "" if the file or key is missing.
    """
    path = ROOT.parent / "config" / "env.php"
    if not path.is_file():
        return ""
    try:
        text = path.read_text(encoding="utf-8")
    except OSError:
        return ""
    # Simple regex: looks for 'KEY' => 'value' in the PHP array.
    m = re.search(rf"['\"]{re.escape(key)}['\"]\s*=>\s*['\"]([^'\"]*)['\"]", text)
    return (m.group(1) if m else "").strip()


def _env(key: str, default: str = "") -> str:
    """Prefer OS environment, then env.php, then the default string."""
    v = os.environ.get(key, "").strip()
    if v:
        return v
    return _read_php_env(key) or default


# Groq shut these down for free/developer accounts on 16 Aug 2026.
# Keep accepting the old ids so an existing GROQ_MODEL env var still works.
_RETIRED_GROQ_MODELS = {
    "llama-3.3-70b-versatile": "openai/gpt-oss-120b",
    "llama-3.1-8b-instant": "openai/gpt-oss-20b",
}
_GROQ_FALLBACK_MODEL = "openai/gpt-oss-120b"


def resolve_groq_model(name: str) -> str:
    """Map a blank or retired Groq model id onto one that still answers."""
    cleaned = (name or "").strip()
    if not cleaned:
        return _GROQ_FALLBACK_MODEL
    return _RETIRED_GROQ_MODELS.get(cleaned, cleaned)


# --- Chat / LLM settings (primary = Groq; optional backup router) ---
GROQ_API_KEY = _env("GROQ_API_KEY") or _env("OPENROUTER_API_KEY")
GROQ_MODEL = resolve_groq_model(_env("GROQ_MODEL") or _env("OPENROUTER_MODEL"))
GROQ_BASE = (_env("GROQ_BASE_URL") or "https://api.groq.com/openai/v1").rstrip("/")
GROQ_SITE = os.environ.get("GROQ_HTTP_REFERER", "https://ailab-lis.local")
GROQ_TITLE = os.environ.get("GROQ_APP_TITLE", "AI-Assisted LIS")

BACKUP_AI_API_KEY = _env("BACKUP_AI_API_KEY")
BACKUP_AI_BASE = _env("BACKUP_AI_BASE_URL", "https://router.bynara.id/v1").rstrip("/")
BACKUP_AI_MODEL = _env("BACKUP_AI_MODEL", "auto/bynara")

# Instructions sent to the chat model so answers stay practical for lab staff.
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

# Loaded Isolation Forest model (None until load_model() succeeds).
model = None
# Metadata from the companion .meta.json (feature names, version, etc.).
meta = {
    "model_version": "untrained",
    "features": ["WBC", "RBC", "HGB", "HCT", "PLT", "sex", "age"],
    "panel": "CBC",
}


def ensure_model() -> None:
    """If the model file is missing, train a demo model once (fresh deploy)."""
    if MODEL_PATH.exists():
        return
    try:
        from train_model import train_cbc

        train_cbc()
    except Exception as exc:  # pragma: no cover - startup safeguard
        app.logger.warning("Could not auto-train model: %s", exc)


def load_model() -> None:
    """Read meta JSON and load the joblib model into memory."""
    global model, meta
    ensure_model()
    if META_PATH.exists():
        meta = json.loads(META_PATH.read_text(encoding="utf-8"))
    if MODEL_PATH.exists():
        model = joblib.load(MODEL_PATH)
    else:
        model = None


def llm_providers() -> list[dict[str, str]]:
    """
    Build the list of chat providers that have API keys.
    Order: Groq first, then backup. Used for failover in groq_chat().
    """
    providers: list[dict[str, str]] = []
    if GROQ_API_KEY:
        providers.append({
            "name": "groq",
            "api_key": GROQ_API_KEY,
            "base_url": GROQ_BASE,
            "model": GROQ_MODEL,
        })
    if BACKUP_AI_API_KEY:
        providers.append({
            "name": "nararouter",
            "api_key": BACKUP_AI_API_KEY,
            "base_url": BACKUP_AI_BASE,
            "model": BACKUP_AI_MODEL,
        })
    return providers


def groq_ready() -> bool:
    """True if at least one chat API key is configured."""
    return bool(llm_providers())


def openrouter_ready() -> bool:
    """Alias kept for older callers; same as groq_ready()."""
    return groq_ready()


def _chat_completions(provider: dict[str, str], messages: list[dict[str, str]], *, temperature: float, max_tokens: int) -> dict[str, Any]:
    """
    Call one OpenAI-style /chat/completions endpoint.
    Returns {ok: True, reply: "..."} or {ok: False, error: "...", detail: "..."}.
    """
    name = provider["name"]
    url = f"{provider['base_url'].rstrip('/')}/chat/completions"
    headers = {
        "Authorization": f"Bearer {provider['api_key']}",
        "Content-Type": "application/json",
        "HTTP-Referer": GROQ_SITE,
        "X-Title": GROQ_TITLE,
    }
    payload = {
        "model": provider["model"],
        "messages": messages,
        "temperature": temperature,
        "max_tokens": max_tokens,
    }
    # gpt-oss spends the token budget on reasoning unless this is set low.
    if str(provider["model"]).startswith("openai/gpt-oss"):
        payload["reasoning_effort"] = "low"
        payload["max_completion_tokens"] = max_tokens
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


def _failure_text(result: dict[str, Any]) -> str:
    return f"{result.get('error', '')} {result.get('detail', '')}".lower()


def _account_gate(result: dict[str, Any]) -> bool:
    """True when a gateway wants an account link (NaraRouter Telegram) instead of answering."""
    blob = _failure_text(result)
    return "telegram_required" in blob or "bind your telegram" in blob


def _model_gone(result: dict[str, Any]) -> bool:
    blob = _failure_text(result)
    return any(
        token in blob
        for token in ("does not exist", "decommissioned", "model_not_found", "model_decommissioned")
    )


def _public_chat_failure(result: dict[str, Any]) -> dict[str, Any]:
    """Turn raw gateway text into a short message for the chat bubble."""
    blob = _failure_text(result)
    if _account_gate(result):
        return {
            "ok": False,
            "error": "backup_not_linked",
            "detail": "The assistant could not reply. The backup AI account is not linked, and Groq did not answer.",
        }
    if "invalid api key" in blob or "invalid_api_key" in blob:
        return {
            "ok": False,
            "error": "invalid_api_key",
            "detail": "Groq rejected the API key. Update GROQ_API_KEY, then try again.",
        }
    if _model_gone(result):
        return {
            "ok": False,
            "error": "model_unavailable",
            "detail": "The configured Groq model is no longer available.",
        }
    return result


def groq_chat(messages: list[dict[str, str]], *, temperature: float = 0.4, max_tokens: int = 700) -> dict[str, Any]:
    """
    Try each configured LLM until one succeeds.
    Used by /chat and by optional anomaly explanations.
    A backup that only asks to bind Telegram is skipped so that message
    does not replace Groq's real error.
    """
    providers = llm_providers()
    if not providers:
        return {"ok": False, "error": "llm_not_configured", "detail": "GROQ_API_KEY / BACKUP_AI_API_KEY missing"}

    last: dict[str, Any] = {"ok": False, "error": "all_providers_failed", "detail": "All LLM providers failed"}
    primary: dict[str, Any] | None = None
    for provider in providers:
        last = _chat_completions(provider, messages, temperature=temperature, max_tokens=max_tokens)
        if (
            not last.get("ok")
            and provider["name"] == "groq"
            and _model_gone(last)
            and provider["model"] != _GROQ_FALLBACK_MODEL
        ):
            retry = dict(provider)
            retry["model"] = _GROQ_FALLBACK_MODEL
            last = _chat_completions(retry, messages, temperature=temperature, max_tokens=max_tokens)
        if last.get("ok"):
            return last
        if provider["name"] == "groq":
            primary = last
        if _account_gate(last):
            continue
    return _public_chat_failure(primary or last)


def explain_anomaly(features: dict, sex: str, age: Any, score: float) -> str | None:
    """
    Optional short LLM tip when Isolation Forest flags a CBC.
    Advisory only — never a diagnosis. Returns None if chat is not configured.
    """
    if not groq_ready():
        return None
    prompt = (
        "An Isolation Forest model flagged this CBC panel as anomalous. "
        "Give a short (2-4 sentences) advisory review tip for a Medical Technologist. "
        "Do not diagnose. Mention which values look most unusual if obvious.\n"
        f"sex={sex}, age={age}, score={score:.4f}, features={json.dumps(features)}"
    )
    result = groq_chat(
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


# Load model once when the module is imported (before first request).
load_model()


def _json_safe(value: Any) -> Any:
    """Turn numpy numbers into plain Python types safe for JSON responses."""
    if isinstance(value, (np.floating, float)):
        number = float(value)
        if not np.isfinite(number):
            return None
        return number
    if isinstance(value, (np.integer, int)):
        return int(value)
    if isinstance(value, np.bool_):
        return bool(value)
    return value


# --- HTTP error handlers: always return JSON so PHP can parse failures ---

@app.errorhandler(HTTPException)
def on_http_error(exc: HTTPException):
    return jsonify({"ok": False, "error": "http_error", "detail": exc.description}), exc.code


@app.errorhandler(Exception)
def on_error(exc: Exception):
    app.logger.exception("AI service error")
    return jsonify({"ok": False, "error": "internal", "detail": str(exc)}), 500


@app.get("/")
def root():
    """Tiny root page pointing at /health."""
    return jsonify({"ok": True, "service": "ailab-ai", "health": "/health"}), 200


@app.get("/health")
def health():
    """Status check for deploys and the PHP proxy."""
    if model is None:
        load_model()
    providers = [p["name"] for p in llm_providers()]
    loaded = model is not None
    return jsonify({
        "ok": True,
        "model_loaded": loaded,
        "model_version": meta.get("model_version"),
        "groq": groq_ready(),
        "openrouter": groq_ready(),
        "chat_providers": providers,
        "chat_model": GROQ_MODEL if GROQ_API_KEY else (BACKUP_AI_MODEL if BACKUP_AI_API_KEY else None),
    })


@app.post("/chat")
def chat():
    """
    Lab assistant chat used by Manager / MedTech screens.
    Body JSON: { message, history (optional), role (optional) }.
    """
    payload = request.get_json(silent=True) or {}
    message = str(payload.get("message") or "").strip()
    history = payload.get("history") or []
    role = str(payload.get("role") or "lab_staff")

    if not message:
        return jsonify({"ok": False, "error": "empty_message", "detail": "message is required"}), 400
    if len(message) > 4000:
        return jsonify({"ok": False, "error": "message_too_long", "detail": "Max 4000 characters"}), 400

    # System prompt + last few turns + current user message.
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

    result = groq_chat(messages)
    status = 200 if result.get("ok") else 502
    return jsonify(result), status


@app.post("/predict")
def predict():
    """
    Score a CBC panel with Isolation Forest.

    Expected JSON body (example):
      {
        "features": {"WBC": 7.1, "RBC": 4.8, "HGB": 14.0, "HCT": 42.0, "PLT": 250},
        "patient_sex": "M",
        "patient_age": 35,
        "test_code": "CBC",
        "explain": false
      }

    Returns is_anomaly, score, and optional warning_message / llm_note.
    Non-CBC panels without CBC features get a soft "not scored" response.
    """
    global model
    if model is None:
        load_model()
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

    # CBC model needs these five blood counts. Sex/age are added below.
    required = ["WBC", "RBC", "HGB", "HCT", "PLT"]
    missing = [f for f in required if f not in features]
    if missing and test_code == "CBC":
        return jsonify({
            "ok": False,
            "error": "missing_features",
            "detail": f"Required feature {missing[0]} not provided",
        }), 400

    if missing:
        # Chemistry / other panels: skip the CBC model; PHP still uses rule checks.
        return jsonify({
            "ok": True,
            "is_anomaly": False,
            "score": 0.0,
            "warning_message": None,
            "model_version": meta.get("model_version"),
            "note": "Panel outside CBC model scope; rule-based validation applies.",
        })

    try:
        # Same feature order as training: WBC, RBC, HGB, HCT, PLT, sex(M=1), age.
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
    pred = int(model.predict(X)[0])  # -1 = anomaly, 1 = looks normal
    score = _json_safe(model.decision_function(X)[0])
    is_anomaly = pred == -1

    warning = None
    llm_note = None
    if is_anomaly:
        warning = (
            "Isolation Forest flagged this CBC panel as anomalous. "
            "Review encoding and clinical context before approval."
        )
        # Optional: ask the LLM for a short review tip (explain=true in the request).
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
        "groq": groq_ready(),
    })


# Second load attempt so startup failures are logged but do not crash import.
try:
    load_model()
except Exception as exc:  # pragma: no cover - startup safeguard
    app.logger.warning("Startup model load failed: %s", exc)

if __name__ == "__main__":
    # Render sets PORT; local default is 5001. Bind 0.0.0.0 for cloud hosts.
    port = int(os.environ.get("PORT", "5001"))
    host = os.environ.get("HOST", "0.0.0.0")
    app.run(host=host, port=port, debug=False)
