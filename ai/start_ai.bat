@echo off
cd /d "%~dp0"
echo Training/checking Isolation Forest model...
py -3 train_model.py
echo Starting AI service on http://127.0.0.1:5001 ...
py -3 app.py
pause
