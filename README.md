# AI-Assisted Laboratory Information System

Web-based LIS for **Lagman Qualicare Multispecialty and Diagnostic Center** with rule-based validation and Python Isolation Forest anomaly detection.

## Stack

- PHP + MySQL (XAMPP)
- JavaScript / HTML / CSS
- Python Flask + scikit-learn (Isolation Forest)

## Quick start (XAMPP)

1. Place this folder under `htdocs` (already expected).
2. Start **Apache** and **MySQL** in XAMPP.
3. Edit [`config/database.php`](config/database.php) if MySQL credentials differ.
4. Open `http://localhost/AI-Assisted_Laborator_Information_System/install.php`
5. Start the AI service:

```bash
cd ai
pip install -r requirements.txt
python train_model.py
python app.py
```

6. Login: `http://localhost/AI-Assisted_Laborator_Information_System/login.php`  
   Users: `manager` / `medtech` / `staff` — password `password123`

## Workflow

1. Authenticate  
2. Register patient  
3. Create laboratory request (+ specimen + pending results)  
4. Collect / update specimen status  
5. Encode results → rule-based validation → Python Isolation Forest  
6. Show AI warning if needed  
7. Medical technologist reviews → Approve  
8. Generate report → Release  
9. Audit log + database backup  

See [`docs/RBAC_AND_STATE_MACHINE.md`](docs/RBAC_AND_STATE_MACHINE.md), [`docs/ERD.md`](docs/ERD.md), [`docs/PYTHON_AI_API.md`](docs/PYTHON_AI_API.md).

## CAP corrections

Document cleanup notes for the thesis CAP: [`docs/CAP_CORRECTIONS.md`](docs/CAP_CORRECTIONS.md).
