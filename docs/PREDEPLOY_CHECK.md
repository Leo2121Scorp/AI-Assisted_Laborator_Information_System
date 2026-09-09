# Pre-deploy checklist (run locally before pushing)

## XAMPP (required on this PC)
1. Start Apache + MySQL in XAMPP
2. Open http://localhost/AI-Assisted_Laborator_Information_System/install.php
3. Login as manager / password123
4. Click through: patient → request → specimen → result → approve → report
5. Start AI: `cd ai && pip install -r requirements.txt && python train_model.py && python app.py`

## Render (after local OK)
1. ailab-web → Environment → add `DATABASE_URL` = Postgres **Internal Database URL**
2. Confirm `AI_SERVICE_URL` points at ailab-ai host
3. Manual Deploy → latest commit
4. Open https://ailab-web-….onrender.com/login.php
