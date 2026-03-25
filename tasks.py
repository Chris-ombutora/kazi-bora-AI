import os
from celery import Celery
from .engine import match_candidate_to_job
from .models import Candidate, JobDescription

# Configure Celery to use Redis as the broker and backend
REDIS_URL = os.getenv('REDIS_URL', 'redis://localhost:6379/0')

celery_app = Celery(
    'ai_matching_tasks',
    broker=REDIS_URL,
    backend=REDIS_URL
)

celery_app.conf.update(
    task_serializer='json',
    accept_content=['json'],  # Ignore other content
    result_serializer='json',
    timezone='UTC',
    enable_utc=True,
    task_track_started=True,
    task_time_limit=300, # 5 min limit for matching batch operations
    worker_concurrency=4 # optimize parallel workers per core based on server capacity
)

@celery_app.task(bind=True)
def run_batch_matching(self, job_dict: dict, candidates_list: list) -> dict:
    """
    Asynchronously matches a batch of candidates to a job description.
    Updates the progress of the Celery task for the frontend to track.
    """
    job = JobDescription(**job_dict)
    total_candidates = len(candidates_list)
    results = []

    for i, curr_cand in enumerate(candidates_list):
        candidate = Candidate(**curr_cand)
        score_card = match_candidate_to_job(candidate, job)
        results.append(score_card.model_dump())
        
        # update states for the user retrieving this job status
        self.update_state(state='PROGRESS',
                          meta={'current': i + 1, 'total': total_candidates,
                                'status': f'Processed candidate {candidate.name}'})

    # Sort results by overall ranking (highest first)
    results.sort(key=lambda x: x['overall_score'], reverse=True)
    
    return {
        'status': 'Task completed!',
        'total': total_candidates,
        'results': results
    }
