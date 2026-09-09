FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt gunicorn

COPY . .

ENV PORT=5001
ENV HOST=0.0.0.0

EXPOSE 5001

CMD ["sh", "-c", "python train_model.py && gunicorn -b 0.0.0.0:${PORT:-5001} --workers 1 --threads 2 app:app"]
