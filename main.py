from fastapi import FastAPI, BackgroundTasks, HTTPException
from typing import List
from uuid import uuid4
import os

from .models import Candidate, JobDescription, ScoringCard
from .tasks import run_batch_matching

app = FastAPI(
    title="KaziBora AI Matching Service",
    description="Backend service for semantic parsing, structured signals matching, and scoring cards generation.",
    version="1.0.0"
)

@app.get("/")
def read_root():
    return {"message": "Welcome to KaziBora AI Matching API!"}

@app.post("/match", summary="Queue batch AI matching generation")
def create_matching_task(job: JobDescription, candidates: List[Candidate]):
    """
    Submit a task to run the AI Matching and Scoring asynchronously based on Job requirements vs Candidate signals.
    """
    try:
        # Convert Pydantic objects to dicts for Celery serialization
        job_dict = job.model_dump()
        candidates_list = [c.model_dump() for c in candidates]
        
        # Trigger Celery Task
        task = run_batch_matching.delay(job_dict, candidates_list)
        
        # Return response to frontend
        return {
            "message": "Matching task queued successfully.",
            "task_id": task.id
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/task/{task_id}", summary="Check status of Celery matching task")
def get_task_status(task_id: str):
    """Retrieves score cards based on task execution completion."""
    from .tasks import celery_app
    
    task_result = celery_app.AsyncResult(task_id)
    response = {
        "task_id": task_id,
        "task_status": task_result.status,
        "task_result": task_result.result,
        "meta": task_result.info
    }
    
    # Customize info formatting
    if task_result.state == 'FAILURE':
        response["task_result"] = str(task_result.info)
        
    return response
