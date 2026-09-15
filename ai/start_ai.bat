@echo off
cd /d "%~dp0"
echo Installing/updating Python deps (incl. requests for Groq)...
py -3 -m pip install -r requirements.txt -q
echo Training/checking Isolation Forest model...
py -3 train_model.py
echo Starting AI service on http://127.0.0.1:5001 ...
echo Groq key is read from OS env or ..\config\env.php
py -3 app.py
pause
