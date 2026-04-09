# KaziBora AI Platform

KaziBora AI is a comprehensive platform for processing job candidates, extracting structured profile data from resumes, and matching candidates to job descriptions. The platform consists of several distributed microservices orchestrated via Docker Compose.

## Architecture & Microservices

The backend infrastructure is split into the following services:

1. **PHP CV Processor (`php-backend`)**
   - **Environment**: PHP 8.2 + Apache
   - **Role**: Handles document uploads (resumes/CVs), calls the NLP microservice to extract insights, and stores the processed candidate profiles into the database.
   - **Port**: `8080`

2. **NLP Data Extraction Service (`nlp-service`)**
   - **Environment**: Python FastAPI
   - **Role**: Receives document text or files from the PHP service and performs advanced NLP-based extraction of candidate data (name, skills, education, experience).
   - **Port**: `8000`

3. **AI Matching Service (`matcher-api` & `matcher-worker`)**
   - **Environment**: Python FastAPI & Celery
   - **Role**: Provides endpoints to trigger batch scoring and candidate ranking. Calculates semantic similarity (using `sentence-transformers`) and structured signals (skills, experience, education) to match candidates against job descriptions. Provides explainable scoring cards for recruiters.
   - **API Port**: `8001`
   - **Worker**: Handles asynchronous background processing of the matching tasks.

4. **Redis (`redis`)**
   - **Role**: Message broker and result backend for the Celery matching workers.
   - **Port**: `6379`

5. **MariaDB (`db`)**
   - **Role**: Relational database storing user data, institutional data, and parsed candidate attributes.
   - **Port**: `3306`

## Prerequisites

- **Docker** & **Docker Compose**

## Setup & Running Locally

The entire stack is containerized for easy deployment. To start all services:

1. **Navigate to the project root directory**:
   ```bash
   cd "C:\Users\Chris\Desktop\kazibora AI"
   ```

2. **Start the containers**:
   ```bash
   docker-compose up --build -d
   ```
   This command will:
   - Initialize the MariaDB database and run initial scripts (e.g., `database/02_institutions.sql`).
   - Build and start the `nlp-service`.
   - Build and start the `php-backend` (which installs composer dependencies on startup).
   - Start the `redis` cache.
   - Build and start the `matcher-api` and `matcher-worker`.

3. **Verify the services**:
   - PHP CV Processor: `http://localhost:8080`
   - NLP Service Docs: `http://localhost:8000/docs`
   - AI Matcher API Docs: `http://localhost:8001/docs`
   - Database Connection: `localhost:3306` (User: `root`, Pass: `root`, DB: `kazibora`)

## Usage / Workflow

1. **Upload CV**: Send candidate CVs to the PHP Processor.
2. **Data Extraction**: The PHP Processor forwards data to the NLP service.
3. **Storage**: Extracted candidate profiles are persisted into MariaDB.
4. **Matching**: Submit job requirements to the Matcher API (`POST /match`) along with a batch of candidates.
5. **Score Retrieval**: The Matcher API returns a `task_id`. Use `GET /task/{task_id}` to retrieve the final ranked list of candidates with explainable scorecards.
