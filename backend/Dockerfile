FROM python:3.10-slim

WORKDIR /app

# Install system dependencies
RUN apt-get update && apt-get install -y gcc \
    && rm -rf /var/lib/apt/lists/*

# Install global requirements (context is project root via docker-compose)
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copy the backend code as a package under /app/backend
COPY backend/ /app/backend/

# The command will be overridden in docker-compose.yml for web vs celery worker
CMD ["uvicorn", "backend.main:app", "--host", "0.0.0.0", "--port", "8001"]
