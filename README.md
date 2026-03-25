# KaziBora AI Matching Service

This is the backend service for processing and matching job candidates to job descriptions using semantic similarity (via `sentence-transformers`) and structured signals (skills, experience, and education).

## Architecture
- **FastAPI**: Provides endpoints to trigger batch scoring and retrieve statuses.
- **Celery**: Handles the asynchronous batch matching processing.
- **Redis**: Message broker and result backend for Celery.
- **Sentence-Transformers**: Powers the semantic scoring between candidate resumes and job descriptions.

## Prerequisites
1. Python 3.9+
2. Redis Server running locally or remotely (default: `redis://localhost:6379/0`)

## Setup & Running

1. **Install Dependencies**
   ```bash
   pip install -r requirements.txt
   ```

2. **Start Redis Server**
   Ensure Redis is running. For Windows, you can use Memurai, WSL, or Docker:
   ```bash
   docker run -p 6379:6379 -d redis
   ```

3. **Start Celery Worker**
   In a new terminal, navigate to the parent folder of `backend` (e.g. `c:\Users\Chris\Desktop\kazibora AI`) and run:
   ```bash
   celery -A backend.tasks.celery_app worker --loglevel=info --pool=solo
   ```
   *(Note: `--pool=solo` is used on Windows since Celery's default prefork doesn't support Windows well natively).*

4. **Start FastAPI Application**
   In another terminal, from the same parent folder, run:
   ```bash
   uvicorn backend.main:app --reload
   ```

## Endpoints
- `POST /match`: Submit a job description and a list of candidates. Returns a `task_id`.
- `GET /task/{task_id}`: Poll this endpoint to check progress or retrieve the final sorted batch match results (explainable scoring cards).
